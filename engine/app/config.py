import os
from dataclasses import dataclass, field


def _int(name: str, default: int) -> int:
    return int(os.environ.get(name, default))


@dataclass(frozen=True)
class Settings:
    # "chatterbox" (vrai modèle) ou "fake" (signal de test, aucun modèle requis)
    driver: str = field(default_factory=lambda: os.environ.get("ENGINE_DRIVER", "chatterbox"))
    # Jeton partagé avec l'application Laravel. Vide = service refusé au démarrage.
    token: str = field(default_factory=lambda: os.environ.get("ENGINE_TOKEN", ""))
    device: str = field(default_factory=lambda: os.environ.get("ENGINE_DEVICE", "auto"))
    max_chars: int = field(default_factory=lambda: _int("ENGINE_MAX_CHARS", 5000))
    chunk_chars: int = field(default_factory=lambda: _int("ENGINE_CHUNK_CHARS", 280))
    max_sample_bytes: int = field(default_factory=lambda: _int("ENGINE_MAX_SAMPLE_MB", 20) * 1024 * 1024)
    # Durée maximale conservée de l'échantillon de référence, en secondes
    sample_seconds: int = field(default_factory=lambda: _int("ENGINE_SAMPLE_SECONDS", 30))
    # Transcription : taille du modèle Whisper (tiny, base, small, medium, large-v3)
    whisper_model: str = field(default_factory=lambda: os.environ.get("ENGINE_WHISPER_MODEL", "small"))
    max_transcribe_bytes: int = field(default_factory=lambda: _int("ENGINE_MAX_TRANSCRIBE_MB", 500) * 1024 * 1024)
    # Threads PyTorch (0 = valeur par défaut de PyTorch)
    threads: int = field(default_factory=lambda: _int("ENGINE_THREADS", 0))


LANGUAGES = {
    "fr": "Français",
    "en": "English",
}
