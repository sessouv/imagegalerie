"""Pilotes de synthèse. Chaque pilote transforme un segment de texte en signal mono."""

import hashlib
import os
import threading
import time
from collections import OrderedDict
from dataclasses import dataclass

import numpy as np


@dataclass
class SpeechParams:
    language: str = "fr"
    exaggeration: float = 0.5
    cfg_weight: float = 0.5
    temperature: float = 0.8
    seed: int | None = None


class Driver:
    name = "base"
    sample_rate = 24000
    supports_cloning = True

    def load(self) -> None:  # pragma: no cover - interface
        raise NotImplementedError

    @property
    def loaded(self) -> bool:
        return True

    def synthesize(self, text: str, params: SpeechParams, sample_path: str | None) -> np.ndarray:
        raise NotImplementedError  # pragma: no cover - interface


class FakeDriver(Driver):
    """Signal de test : aucune voix réelle, sert au développement et aux tests."""

    name = "fake"

    def load(self) -> None:
        pass

    def synthesize(self, text: str, params: SpeechParams, sample_path: str | None) -> np.ndarray:
        # ENGINE_FAKE_DELAY simule la lenteur d'un vrai modèle (secondes par passage)
        time.sleep(float(os.environ.get("ENGINE_FAKE_DELAY", "0")))
        seconds = max(0.3, min(len(text) * 0.06, 20.0))
        t = np.arange(int(self.sample_rate * seconds)) / self.sample_rate
        # La hauteur dépend de la voix, pour distinguer les rendus à l'oreille
        freq = 330.0 if sample_path else 220.0
        tone = 0.2 * np.sin(2 * np.pi * freq * t)
        fade = np.minimum(1.0, np.minimum(t, t[::-1]) / 0.05)
        return (tone * fade).astype(np.float32)


class ChatterboxDriver(Driver):
    """Chatterbox Multilingual (Resemble AI, licence MIT) : synthèse et clonage zero-shot."""

    name = "chatterbox"
    MAX_VOICES = 8

    def __init__(self, device: str = "auto", threads: int = 0):
        self._device = device
        self._threads = threads
        self._model = None
        self._lock = threading.Lock()
        self._voices: OrderedDict[str, object] = OrderedDict()
        self._default_conds = None

    @property
    def loaded(self) -> bool:
        return self._model is not None

    def load(self) -> None:
        import torch
        from chatterbox.mtl_tts import ChatterboxMultilingualTTS

        if self._threads > 0:
            torch.set_num_threads(self._threads)
        device = self._device
        if device == "auto":
            device = "cuda" if torch.cuda.is_available() else "cpu"
        self._model = ChatterboxMultilingualTTS.from_pretrained(device=device)
        self._default_conds = self._model.conds
        self.sample_rate = self._model.sr

    def synthesize(self, text: str, params: SpeechParams, sample_path: str | None) -> np.ndarray:
        import torch

        with self._lock:
            self._select_voice(sample_path, params.exaggeration)
            if params.seed is not None:
                torch.manual_seed(params.seed)
            wav = self._model.generate(
                text,
                language_id=params.language,
                exaggeration=params.exaggeration,
                cfg_weight=params.cfg_weight,
                temperature=params.temperature,
            )
        return wav.squeeze().detach().cpu().numpy().astype(np.float32)

    def _select_voice(self, sample_path: str | None, exaggeration: float) -> None:
        """Calcule l'empreinte d'une voix une seule fois, et garde les dernières en mémoire.

        Un dialogue alterne entre plusieurs voix : sans cette mémoire, l'empreinte
        serait recalculée à chaque réplique.
        """
        if sample_path is None:
            if self._default_conds is None:
                raise RuntimeError("Aucune voix par défaut fournie par le modèle.")
            self._model.conds = self._default_conds
            return

        with open(sample_path, "rb") as f:
            key = hashlib.sha256(f.read()).hexdigest()
        if key in self._voices:
            self._voices.move_to_end(key)
            self._model.conds = self._voices[key]
            return

        self._model.prepare_conditionals(sample_path, exaggeration=exaggeration)
        self._voices[key] = self._model.conds
        while len(self._voices) > self.MAX_VOICES:
            self._voices.popitem(last=False)


def make_driver(name: str, device: str = "auto", threads: int = 0) -> Driver:
    if name == "fake":
        return FakeDriver()
    if name == "chatterbox":
        return ChatterboxDriver(device=device, threads=threads)
    raise ValueError(f"Pilote inconnu : {name}")
