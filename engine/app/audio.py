import io
import subprocess
import wave

import numpy as np


class AudioError(Exception):
    pass


def normalize_sample(src: str, dst: str, sample_rate: int, max_seconds: int) -> None:
    """Convertit n'importe quel fichier audio (wav, mp3, webm, m4a…) en WAV mono."""
    result = subprocess.run(
        [
            "ffmpeg", "-nostdin", "-y", "-loglevel", "error",
            "-i", src, "-vn", "-ac", "1", "-ar", str(sample_rate),
            "-t", str(max_seconds), "-c:a", "pcm_s16le", dst,
        ],
        capture_output=True,
    )
    if result.returncode != 0:
        raise AudioError("Échantillon audio illisible.")


def wav_seconds(path: str) -> float:
    with wave.open(path, "rb") as f:
        return f.getnframes() / float(f.getframerate())


def to_wav_bytes(samples: np.ndarray, sample_rate: int) -> bytes:
    pcm = (np.clip(samples, -1.0, 1.0) * 32767.0).astype("<i2")
    buf = io.BytesIO()
    with wave.open(buf, "wb") as f:
        f.setnchannels(1)
        f.setsampwidth(2)
        f.setframerate(sample_rate)
        f.writeframes(pcm.tobytes())
    return buf.getvalue()


def wav_to_mp3(wav: bytes) -> bytes:
    result = subprocess.run(
        [
            "ffmpeg", "-nostdin", "-loglevel", "error", "-f", "wav", "-i", "pipe:0",
            "-c:a", "libmp3lame", "-b:a", "128k", "-f", "mp3", "pipe:1",
        ],
        input=wav,
        capture_output=True,
    )
    if result.returncode != 0:
        raise AudioError("Conversion MP3 impossible.")
    return result.stdout


def join(parts: list[np.ndarray], sample_rate: int, gap_seconds: float = 0.25) -> np.ndarray:
    if not parts:
        return np.zeros(0, dtype=np.float32)
    gap = np.zeros(int(sample_rate * gap_seconds), dtype=np.float32)
    out: list[np.ndarray] = []
    for i, part in enumerate(parts):
        if i:
            out.append(gap)
        out.append(part.astype(np.float32))
    return np.concatenate(out)
