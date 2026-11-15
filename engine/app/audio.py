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


# Coupe les graves parasites, atténue le bruit de fond, ramène le volume à un
# niveau constant, puis retire le silence du début et de la fin. Le volume est
# égalisé avant la coupe : un enregistrement très faible n'est ainsi pas pris
# pour du silence.
_TRIM = "silenceremove=start_periods=1:start_threshold=-45dB:start_silence=0.15"
CLEAN_FILTER = ",".join([
    "highpass=f=70",
    "afftdn=nf=-25",
    "loudnorm=I=-20:TP=-2:LRA=11",
    _TRIM, "areverse", _TRIM, "areverse",
])


def clean_sample(src: str, dst: str, sample_rate: int, max_seconds: int) -> None:
    """Nettoie un échantillon de voix et le convertit en WAV mono."""
    result = subprocess.run(
        [
            "ffmpeg", "-nostdin", "-y", "-loglevel", "error",
            "-i", src, "-vn", "-ac", "1", "-af", CLEAN_FILTER, "-ar", str(sample_rate),
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


def file_to_mp3(src: str, dst: str) -> None:
    """Encode un fichier audio en MP3, sans le charger en mémoire."""
    result = subprocess.run(
        [
            "ffmpeg", "-nostdin", "-y", "-loglevel", "error", "-i", src,
            "-vn", "-c:a", "libmp3lame", "-b:a", "128k", "-f", "mp3", dst,
        ],
        capture_output=True,
    )
    if result.returncode != 0:
        raise AudioError("Fichier audio illisible.")


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
