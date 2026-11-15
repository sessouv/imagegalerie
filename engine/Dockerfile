FROM python:3.11-slim

RUN apt-get update \
    && apt-get install -y --no-install-recommends ffmpeg \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /srv

COPY requirements.txt requirements-model.txt ./
# PyTorch en version CPU : image bien plus légère que la version CUDA par défaut.
# Sur un serveur GPU, retirer --extra-index-url.
RUN pip install --no-cache-dir -r requirements.txt \
    && pip install --no-cache-dir --extra-index-url https://download.pytorch.org/whl/cpu -r requirements-model.txt

COPY app ./app

# Les poids du modèle (environ 3 Go) sont téléchargés au premier démarrage
# et conservés dans ce dossier : le monter en volume.
ENV HF_HOME=/models
VOLUME /models

EXPOSE 8100
CMD ["uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8100"]
