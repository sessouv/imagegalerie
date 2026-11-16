import io
import os
import wave

import numpy as np

os.environ["ENGINE_DRIVER"] = "fake"
os.environ["ENGINE_TOKEN"] = "secret"

from fastapi.testclient import TestClient  # noqa: E402

from app.main import app  # noqa: E402
from app.text import split_text  # noqa: E402

AUTH = {"Authorization": "Bearer secret"}


def _wav(seconds: float) -> bytes:
    buf = io.BytesIO()
    with wave.open(buf, "wb") as f:
        f.setnchannels(1)
        f.setsampwidth(2)
        f.setframerate(16000)
        f.writeframes((np.zeros(int(16000 * seconds))).astype("<i2").tobytes())
    return buf.getvalue()


def test_split_respects_limit_and_keeps_text():
    text = "Bonjour à tous. " * 40 + "Une phrase, très longue, " * 30
    chunks = split_text(text, 120)
    assert all(len(c) <= 120 for c in chunks)
    assert "".join(chunks).replace(" ", "") == text.replace(" ", "")


def test_split_cuts_a_word_free_run():
    assert [len(c) for c in split_text("a" * 250, 100)] == [100, 100, 50]


def test_health_is_public():
    with TestClient(app) as client:
        body = client.get("/health").json()
    assert body["status"] == "ok" and body["driver"] == "fake"


def test_speech_requires_token():
    with TestClient(app) as client:
        assert client.post("/v1/speech", data={"text": "Bonjour"}).status_code == 401
        assert client.post(
            "/v1/speech", data={"text": "Bonjour"}, headers={"Authorization": "Bearer nope"}
        ).status_code == 401


def test_speech_returns_wav_and_mp3():
    with TestClient(app) as client:
        r = client.post("/v1/speech", data={"text": "Bonjour le monde.", "format": "wav"}, headers=AUTH)
        assert r.status_code == 200 and r.headers["content-type"] == "audio/wav"
        with wave.open(io.BytesIO(r.content)) as f:
            assert f.getnframes() > 0
        assert int(r.headers["x-audio-duration-ms"]) > 0

        r = client.post("/v1/speech", data={"text": "Bonjour le monde."}, headers=AUTH)
        assert r.status_code == 200 and r.headers["content-type"] == "audio/mpeg"
        assert len(r.content) > 100


def test_speech_with_voice_sample():
    with TestClient(app) as client:
        r = client.post(
            "/v1/speech",
            data={"text": "Hello there.", "language": "en", "format": "wav"},
            files={"sample": ("voice.wav", _wav(5), "audio/wav")},
            headers=AUTH,
        )
    assert r.status_code == 200


def test_speech_rejects_bad_input():
    with TestClient(app) as client:
        post = lambda **kw: client.post("/v1/speech", headers=AUTH, **kw).status_code  # noqa: E731
        assert post(data={"text": "   "}) == 422
        assert post(data={"text": "x" * 6000}) == 422
        assert post(data={"text": "Hola", "language": "es"}) == 422
        assert post(data={"text": "Bonjour", "exaggeration": "9"}) == 422
        assert post(data={"text": "Bonjour"}, files={"sample": ("v.wav", _wav(1), "audio/wav")}) == 422
        assert post(data={"text": "Bonjour"}, files={"sample": ("v.wav", b"pas de l'audio", "audio/wav")}) == 422


def test_encode_turns_wav_into_mp3():
    with TestClient(app) as client:
        assert client.post("/v1/encode", files={"audio_file": ("a.wav", _wav(2), "audio/wav")}).status_code == 401
        r = client.post("/v1/encode", files={"audio_file": ("a.wav", _wav(2), "audio/wav")}, headers=AUTH)
        assert r.status_code == 200 and r.headers["content-type"] == "audio/mpeg" and len(r.content) > 100
        r = client.post("/v1/encode", files={"audio_file": ("a.wav", b"rien", "audio/wav")}, headers=AUTH)
        assert r.status_code == 422


def _voice_like(lead: float, body: float, tail: float, gain: float) -> bytes:
    """Silence, puis un signal faible et bruité, puis silence."""
    rate = 16000
    rng = np.random.default_rng(1)
    t = np.arange(int(rate * body)) / rate
    signal = gain * np.sin(2 * np.pi * 180 * t) * (0.6 + 0.4 * np.sin(2 * np.pi * 3 * t))
    signal += 0.002 * rng.standard_normal(len(t))
    samples = np.concatenate([np.zeros(int(rate * lead)), signal, np.zeros(int(rate * tail))])
    buf = io.BytesIO()
    with wave.open(buf, "wb") as f:
        f.setnchannels(1)
        f.setsampwidth(2)
        f.setframerate(rate)
        f.writeframes((samples * 32767).astype("<i2").tobytes())
    return buf.getvalue()


def test_clean_sample_trims_silence_and_raises_volume():
    with TestClient(app) as client:
        source = _voice_like(lead=2, body=6, tail=3, gain=0.03)
        r = client.post("/v1/samples/clean", files={"sample": ("v.wav", source, "audio/wav")}, headers=AUTH)
        assert r.status_code == 200 and r.headers["content-type"] == "audio/wav"
        with wave.open(io.BytesIO(r.content)) as f:
            assert f.getframerate() == 24000 and f.getnchannels() == 1
            seconds = f.getnframes() / f.getframerate()
            pcm = np.frombuffer(f.readframes(f.getnframes()), dtype="<i2") / 32767
        # 11 s en entrée dont 5 s de silence au début et à la fin
        assert 5.5 < seconds < 6.8
        assert abs(int(r.headers["x-audio-duration-ms"]) / 1000 - seconds) < 0.01
        # Le volume moyen est nettement relevé (0,03 d'amplitude au départ)
        assert np.sqrt(np.mean(pcm**2)) > 0.05


def test_clean_sample_keeps_a_very_quiet_recording():
    with TestClient(app) as client:
        source = _voice_like(lead=2, body=6, tail=2, gain=0.004)
        r = client.post("/v1/samples/clean", files={"sample": ("v.wav", source, "audio/wav")}, headers=AUTH)
        assert r.status_code == 200
        assert 5.5 < int(r.headers["x-audio-duration-ms"]) / 1000 < 7.5


def test_clean_sample_rejects_bad_input():
    with TestClient(app) as client:
        post = lambda body: client.post(  # noqa: E731
            "/v1/samples/clean", files={"sample": ("v.wav", body, "audio/wav")}, headers=AUTH
        ).status_code
        assert client.post("/v1/samples/clean", files={"sample": ("v.wav", _wav(5), "audio/wav")}).status_code == 401
        assert post(b"pas de l'audio") == 422
        assert post(_voice_like(lead=4, body=1.5, tail=4, gain=0.3)) == 422


def _rms(wav_bytes: bytes) -> float:
    with wave.open(io.BytesIO(wav_bytes)) as f:
        pcm = np.frombuffer(f.readframes(f.getnframes()), dtype="<i2") / 32767
    return float(np.sqrt(np.mean(pcm**2)))


def test_encode_levels_quiet_and_loud_files_to_the_same_volume():
    with TestClient(app) as client:
        def final(gain):
            source = _voice_like(lead=0, body=6, tail=0, gain=gain)
            r = client.post(
                "/v1/encode", files={"audio_file": ("a.wav", source, "audio/wav")},
                data={"format": "wav", "normalize": "true"}, headers=AUTH,
            )
            assert r.status_code == 200 and r.headers["content-type"] == "audio/wav"
            with wave.open(io.BytesIO(r.content)) as f:
                assert f.getframerate() == 16000 and abs(f.getnframes() / 16000 - 6) < 0.1
            return _rms(r.content)

        quiet, loud = final(0.02), final(0.5)
        # Un écart de 1 à 25 en entrée, moins de 10 % en sortie
        assert abs(quiet - loud) / loud < 0.1

        untouched = client.post(
            "/v1/encode", files={"audio_file": ("a.wav", _voice_like(0, 6, 0, 0.02), "audio/wav")},
            data={"format": "wav"}, headers=AUTH,
        )
        assert _rms(untouched.content) < quiet / 3


def test_encode_handles_silence_and_unknown_format():
    with TestClient(app) as client:
        r = client.post(
            "/v1/encode", files={"audio_file": ("a.wav", _wav(2), "audio/wav")},
            data={"format": "mp3", "normalize": "true"}, headers=AUTH,
        )
        assert r.status_code == 200 and len(r.content) > 100
        r = client.post(
            "/v1/encode", files={"audio_file": ("a.wav", _wav(2), "audio/wav")}, data={"format": "ogg"}, headers=AUTH
        )
        assert r.status_code == 422


def test_transcription_runs_in_background_and_reports_result():
    import time

    with TestClient(app) as client:
        files = {"audio_file": ("a.wav", _wav(12), "audio/wav")}
        assert client.post("/v1/transcriptions", files=files).status_code == 401
        r = client.post("/v1/transcriptions", files=files, data={"language": "fr"}, headers=AUTH)
        assert r.status_code == 202 and r.json()["status"] in ("pending", "processing", "completed")
        job_id = r.json()["id"]

        for _ in range(50):
            body = client.get(f"/v1/transcriptions/{job_id}", headers=AUTH).json()
            if body["status"] == "completed":
                break
            time.sleep(0.05)
        assert body["status"] == "completed" and body["progress"] == 100
        assert body["language"] == "fr" and abs(body["duration_ms"] - 12000) < 50
        assert [s["start"] for s in body["segments"]] == [0.0, 5.0, 10.0]
        assert body["text"].startswith("Phrase de test numéro 1. Phrase de test numéro 2.")


def test_transcription_rejects_bad_input():
    with TestClient(app) as client:
        post = lambda body, **data: client.post(  # noqa: E731
            "/v1/transcriptions", files={"audio_file": ("a.bin", body, "application/octet-stream")}, data=data, headers=AUTH
        ).status_code
        assert post(b"pas de l'audio") == 422
        assert post(_wav(5), language="xx") == 422
        assert client.get("/v1/transcriptions/inconnu", headers=AUTH).status_code == 404
