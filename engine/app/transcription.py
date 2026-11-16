"""Transcription audio → texte. Les tâches tournent en arrière-plan, une à la fois."""

import os
import shutil
import threading
import time
import uuid
import wave
from concurrent.futures import ThreadPoolExecutor
from dataclasses import dataclass, field


class Transcriber:
    name = "base"

    def transcribe(self, wav_path: str, language: str | None, on_segment) -> dict:
        """Appelle on_segment(start, end, text) au fil de l'eau ; renvoie {language, duration}."""
        raise NotImplementedError  # pragma: no cover - interface


class FakeTranscriber(Transcriber):
    """Texte factice, pour le développement et les tests."""

    name = "fake"

    def transcribe(self, wav_path: str, language: str | None, on_segment) -> dict:
        with wave.open(wav_path, "rb") as f:
            duration = f.getnframes() / float(f.getframerate())
        delay = float(os.environ.get("ENGINE_FAKE_DELAY", "0"))
        start, index = 0.0, 1
        while start < duration:
            time.sleep(delay)
            end = min(start + 5.0, duration)
            on_segment(start, end, f"Phrase de test numéro {index}.")
            start, index = end, index + 1
        return {"language": language or "fr", "duration": duration}


class WhisperTranscriber(Transcriber):
    """Whisper (OpenAI, licence MIT) exécuté par faster-whisper."""

    name = "whisper"

    def __init__(self, model: str, device: str, threads: int):
        self._model_name = model
        self._device = device
        self._threads = threads
        self._model = None

    def _load(self):
        # Chargé à la première transcription : ne coûte rien en mémoire tant qu'on ne s'en sert pas
        if self._model is None:
            try:
                from faster_whisper import WhisperModel
            except ImportError as e:
                raise RuntimeError(
                    "La transcription n'est pas installée dans le moteur : "
                    "lancez « pip install -r requirements-model.txt » dans le dossier engine."
                ) from e

            device = self._device
            if device == "auto":
                try:
                    import torch
                    device = "cuda" if torch.cuda.is_available() else "cpu"
                except ImportError:
                    device = "cpu"
            self._model = WhisperModel(
                self._model_name,
                device=device,
                compute_type="float16" if device == "cuda" else "int8",
                cpu_threads=self._threads,
            )
        return self._model

    def transcribe(self, wav_path: str, language: str | None, on_segment) -> dict:
        # vad_filter : saute les silences, ce qui évite du texte inventé sur les blancs
        segments, info = self._load().transcribe(wav_path, language=language, vad_filter=True)
        for segment in segments:
            on_segment(segment.start, segment.end, segment.text.strip())
        return {"language": info.language, "duration": info.duration}


def make_transcriber(driver: str, model: str, device: str, threads: int) -> Transcriber:
    return FakeTranscriber() if driver == "fake" else WhisperTranscriber(model, device, threads)


@dataclass
class Job:
    id: str
    status: str = "pending"  # pending, processing, completed, failed
    duration: float = 0.0
    language: str | None = None
    segments: list = field(default_factory=list)
    error: str | None = None
    finished_at: float | None = None

    def view(self) -> dict:
        done = self.segments[-1]["end"] if self.segments else 0.0
        progress = 100 if self.status == "completed" else int(min(99, done / self.duration * 100)) if self.duration else 0
        view = {"id": self.id, "status": self.status, "progress": progress, "error": self.error}
        if self.status == "completed":
            view |= {
                "language": self.language,
                "duration_ms": int(self.duration * 1000),
                "text": " ".join(s["text"] for s in self.segments if s["text"]),
                "segments": self.segments,
            }
        return view


class JobStore:
    """Tâches gardées en mémoire : elles sont perdues si le moteur redémarre."""

    KEEP_SECONDS = 3600

    def __init__(self, transcriber: Transcriber):
        self._transcriber = transcriber
        self._jobs: dict[str, Job] = {}
        self._lock = threading.Lock()
        self._pool = ThreadPoolExecutor(max_workers=1)

    def submit(self, wav_path: str, workdir: str, duration: float, language: str | None) -> Job:
        job = Job(id=uuid.uuid4().hex, duration=duration)
        with self._lock:
            now = time.monotonic()
            for key in [k for k, j in self._jobs.items() if j.finished_at and now - j.finished_at > self.KEEP_SECONDS]:
                del self._jobs[key]
            self._jobs[job.id] = job
        self._pool.submit(self._run, job, wav_path, workdir, language)
        return job

    def get(self, job_id: str) -> Job | None:
        return self._jobs.get(job_id)

    def _run(self, job: Job, wav_path: str, workdir: str, language: str | None) -> None:
        job.status = "processing"
        try:
            info = self._transcriber.transcribe(
                wav_path, language,
                lambda start, end, text: job.segments.append({"start": round(start, 2), "end": round(end, 2), "text": text}),
            )
            job.language = info["language"]
            job.duration = info["duration"] or job.duration
            job.status = "completed"
        except Exception as e:  # noqa: BLE001 - l'erreur est rapportée à l'appelant
            job.error = str(e)[:400] or "Erreur inconnue."
            job.status = "failed"
        finally:
            job.finished_at = time.monotonic()
            shutil.rmtree(workdir, ignore_errors=True)
