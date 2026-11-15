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
