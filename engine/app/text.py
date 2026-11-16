import re

_SENTENCE_END = re.compile(r"(?<=[.!?…;:])\s+|\n+")
_SOFT_BREAK = re.compile(r"(?<=,)\s+")


def split_text(text: str, limit: int) -> list[str]:
    """Découpe un texte en segments d'au plus `limit` caractères.

    Le modèle perd en stabilité sur les longs passages : on coupe aux fins de
    phrase, puis aux virgules, puis aux espaces en dernier recours.
    """
    text = re.sub(r"[ \t]+", " ", text).strip()
    if not text:
        return []

    chunks: list[str] = []
    current = ""
    for sentence in _pieces(text, limit):
        if current and len(current) + 1 + len(sentence) > limit:
            chunks.append(current)
            current = sentence
        else:
            current = f"{current} {sentence}".strip()
    if current:
        chunks.append(current)
    return chunks


def _pieces(text: str, limit: int):
    for sentence in filter(None, (s.strip() for s in _SENTENCE_END.split(text))):
        if len(sentence) <= limit:
            yield sentence
            continue
        for part in filter(None, (p.strip() for p in _SOFT_BREAK.split(sentence))):
            while len(part) > limit:
                cut = part.rfind(" ", 0, limit)
                cut = cut if cut > 0 else limit
                yield part[:cut].strip()
                part = part[cut:].strip()
            if part:
                yield part
