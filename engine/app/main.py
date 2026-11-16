import hmac
import os
import shutil
import tempfile
import time
from contextlib import asynccontextmanager

from fastapi import Depends, FastAPI, File, Form, Header, HTTPException, UploadFile
from fastapi.concurrency import run_in_threadpool
from fastapi.responses import FileResponse, Response
from starlette.background import BackgroundTask

from . import audio
from .config import LANGUAGES, Settings
from .drivers import SpeechParams, make_driver
from .text import split_text
from .transcription import JobStore, make_transcriber

settings = Settings()
driver = make_driver(settings.driver, settings.device, settings.threads)
transcriptions = JobStore(make_transcriber(settings.driver, settings.whisper_model, settings.device, settings.threads))


@asynccontextmanager
async def lifespan(_: FastAPI):
    if not settings.token:
        raise RuntimeError("ENGINE_TOKEN doit être défini.")
    await run_in_threadpool(driver.load)
    yield


app = FastAPI(title="Moteur vocal", lifespan=lifespan)


def require_token(authorization: str = Header(default="")) -> None:
    expected = f"Bearer {settings.token}"
    if not hmac.compare_digest(authorization.encode(), expected.encode()):
        raise HTTPException(status_code=401, detail="Jeton invalide.")


@app.get("/health")
def health():
    return {
        "status": "ok" if driver.loaded else "loading",
        "driver": driver.name,
        "languages": LANGUAGES,
        "max_chars": settings.max_chars,
    }


@app.post("/v1/speech", dependencies=[Depends(require_token)])
async def speech(
    text: str = Form(...),
    language: str = Form("fr"),
    exaggeration: float = Form(0.5, ge=0.25, le=2.0),
    cfg_weight: float = Form(0.5, ge=0.0, le=1.0),
    temperature: float = Form(0.8, ge=0.05, le=2.0),
    seed: int | None = Form(None),
    format: str = Form("mp3"),
    sample: UploadFile | None = File(None),
):
    text = text.strip()
    if not text:
        raise HTTPException(422, "Le texte est vide.")
    if len(text) > settings.max_chars:
        raise HTTPException(422, f"Texte trop long (maximum {settings.max_chars} caractères).")
    if language not in LANGUAGES:
        raise HTTPException(422, f"Langue non prise en charge : {language}.")
    if format not in ("mp3", "wav"):
        raise HTTPException(422, "Format inconnu (mp3 ou wav).")

    params = SpeechParams(language, exaggeration, cfg_weight, temperature, seed)

    with tempfile.TemporaryDirectory() as tmp:
        sample_path = None
        if sample is not None:
            raw = await sample.read(settings.max_sample_bytes + 1)
            if len(raw) > settings.max_sample_bytes:
                raise HTTPException(413, "Échantillon trop volumineux.")
            src = os.path.join(tmp, "source")
            sample_path = os.path.join(tmp, "sample.wav")
            with open(src, "wb") as f:
                f.write(raw)
            try:
                audio.normalize_sample(src, sample_path, 24000, settings.sample_seconds)
            except audio.AudioError as e:
                raise HTTPException(422, str(e))
            if audio.wav_seconds(sample_path) < 3:
                raise HTTPException(422, "Échantillon trop court (3 secondes minimum).")

        started = time.monotonic()
        samples = await run_in_threadpool(_render, text, params, sample_path)
        elapsed = time.monotonic() - started

    wav = audio.to_wav_bytes(samples, driver.sample_rate)
    duration_ms = int(len(samples) / driver.sample_rate * 1000)
    if format == "mp3":
        body, media = await run_in_threadpool(audio.wav_to_mp3, wav), "audio/mpeg"
    else:
        body, media = wav, "audio/wav"

    return Response(
        content=body,
        media_type=media,
        headers={
            "X-Audio-Duration-Ms": str(duration_ms),
            "X-Render-Seconds": f"{elapsed:.2f}",
        },
    )


@app.post("/v1/samples/clean", dependencies=[Depends(require_token)])
async def clean_sample(sample: UploadFile = File(...)):
    """Nettoie un échantillon de voix : bruit de fond, silences, volume."""
    raw = await sample.read(settings.max_sample_bytes + 1)
    if len(raw) > settings.max_sample_bytes:
        raise HTTPException(413, "Échantillon trop volumineux.")

    with tempfile.TemporaryDirectory() as tmp:
        src, dst = os.path.join(tmp, "source"), os.path.join(tmp, "clean.wav")
        with open(src, "wb") as f:
            f.write(raw)
        try:
            await run_in_threadpool(audio.clean_sample, src, dst, 24000, settings.sample_seconds)
        except audio.AudioError as e:
            raise HTTPException(422, str(e))
        seconds = audio.wav_seconds(dst)
        if seconds < 3:
            raise HTTPException(422, "Il reste moins de 3 secondes de voix une fois les silences retirés.")
        with open(dst, "rb") as f:
            body = f.read()

    return Response(content=body, media_type="audio/wav", headers={"X-Audio-Duration-Ms": str(int(seconds * 1000))})


@app.post("/v1/encode", dependencies=[Depends(require_token)])
async def encode(
    audio_file: UploadFile = File(...),
    format: str = Form("mp3"),
    normalize: bool = Form(False),
):
    """Produit l'audio final à partir de l'assemblage des passages.

    `normalize` ramène le volume à un niveau constant, identique d'un fichier à l'autre.
    """
    if format not in ("mp3", "wav"):
        raise HTTPException(422, "Format inconnu (mp3 ou wav).")

    tmp = tempfile.mkdtemp()
    cleanup = BackgroundTask(shutil.rmtree, tmp, ignore_errors=True)
    src, dst = os.path.join(tmp, "source"), os.path.join(tmp, f"audio.{format}")
    try:
        with open(src, "wb") as f:
            while chunk := await audio_file.read(1024 * 1024):
                f.write(chunk)
        await run_in_threadpool(audio.encode_file, src, dst, format, normalize)
    except audio.AudioError as e:
        shutil.rmtree(tmp, ignore_errors=True)
        raise HTTPException(422, str(e))
    except BaseException:
        shutil.rmtree(tmp, ignore_errors=True)
        raise
    media = "audio/mpeg" if format == "mp3" else "audio/wav"
    return FileResponse(dst, media_type=media, background=cleanup)


@app.post("/v1/transcriptions", dependencies=[Depends(require_token)], status_code=202)
async def create_transcription(audio_file: UploadFile = File(...), language: str | None = Form(None)):
    """Lance une transcription en arrière-plan et renvoie son identifiant."""
    if language is not None and language not in LANGUAGES:
        raise HTTPException(422, f"Langue non prise en charge : {language}.")

    tmp = tempfile.mkdtemp()
    src, wav = os.path.join(tmp, "source"), os.path.join(tmp, "speech.wav")
    try:
        size = 0
        with open(src, "wb") as f:
            while chunk := await audio_file.read(1024 * 1024):
                size += len(chunk)
                if size > settings.max_transcribe_bytes:
                    raise HTTPException(413, "Fichier trop volumineux.")
                f.write(chunk)
        try:
            await run_in_threadpool(audio.to_speech_wav, src, wav)
        except audio.AudioError as e:
            raise HTTPException(422, str(e))
        os.remove(src)
        seconds = audio.wav_seconds(wav)
        if seconds < 0.5:
            raise HTTPException(422, "Le fichier ne contient pas de son.")
    except BaseException:
        shutil.rmtree(tmp, ignore_errors=True)
        raise

    return transcriptions.submit(wav, tmp, seconds, language).view()


@app.get("/v1/transcriptions/{job_id}", dependencies=[Depends(require_token)])
def show_transcription(job_id: str):
    job = transcriptions.get(job_id)
    if job is None:
        raise HTTPException(404, "Transcription inconnue : le moteur a peut-être redémarré.")
    return job.view()


def _render(text: str, params: SpeechParams, sample_path: str | None):
    parts = [
        driver.synthesize(chunk, params, sample_path)
        for chunk in split_text(text, settings.chunk_chars)
    ]
    return audio.join(parts, driver.sample_rate)
