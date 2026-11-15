# Timbre

Outil interne de synthèse vocale et de clonage de voix (français et anglais), bâti sur des modèles open source.

- **Synthèse vocale** : un texte, une voix, des réglages d'expressivité, un MP3 à écouter et télécharger.
- **Clonage de voix** : 10 à 20 secondes d'audio (fichier importé ou enregistrement au micro) suffisent pour créer une voix réutilisable.
- **Historique** partagé par l'équipe.

## Architecture

| Dossier | Rôle | Technologies |
| --- | --- | --- |
| `web/` | Interface, comptes, bibliothèque de voix, file d'attente | Laravel 13, Inertia, Vue 3, Tailwind |
| `engine/` | Moteur vocal : reçoit un texte (et un échantillon de voix), renvoie l'audio | Python, FastAPI, [Chatterbox Multilingual](https://github.com/resemble-ai/chatterbox) (licence MIT) |

L'application Laravel place chaque demande dans une file d'attente ; un worker appelle le moteur
(`POST /v1/speech`, protégé par un jeton partagé) et enregistre le MP3. Le moteur ne conserve aucune
donnée : il peut être déplacé sur une autre machine (par exemple un serveur GPU) en changeant
seulement `VOICE_ENGINE_URL`.

Les textes longs sont découpés en phrases par le moteur, puis les segments sont assemblés.
Chatterbox appose sur chaque audio un filigrane inaudible (PerTh) qui permet d'identifier un contenu généré.

## Développement local

Moteur, en mode test (aucun modèle à télécharger, l'audio est un simple signal sonore) :

```bash
cd engine
python3 -m venv .venv && . .venv/bin/activate
pip install -r requirements-dev.txt
ENGINE_DRIVER=fake ENGINE_TOKEN=dev-token uvicorn app.main:app --port 8100
```

Application (ffmpeg doit être installé pour le moteur) :

```bash
cd web
composer install && npm install
cp .env.example .env && php artisan key:generate   # puis VOICE_ENGINE_TOKEN=dev-token
php artisan migrate
php artisan user:create vous@exemple.com            # il n'y a pas d'inscription publique
npm run dev            # terminal 1
php artisan serve      # terminal 2
php artisan queue:work # terminal 3
```

Tests : `cd engine && pytest` et `cd web && php artisan test`.

## Mise en production

Le moteur vocal a besoin d'un vrai serveur : **VPS OVH**, pas l'hébergement mutualisé o2switch
(mémoire et processus longs insuffisants). L'application `web/` peut vivre sur le même VPS.

### Moteur (VPS, Docker)

```bash
cd engine
cp .env.example .env    # renseigner ENGINE_TOKEN
docker compose up -d --build
curl http://127.0.0.1:8100/health   # "loading" pendant le téléchargement du modèle, puis "ok"
```

Au premier démarrage, les poids du modèle (plusieurs Go) sont téléchargés depuis Hugging Face et
conservés dans le volume `models`. Prévoir au moins 8 Go de RAM. Sans GPU, la génération est lente :
compter nettement plus que la durée de l'audio produit. Le port n'est ouvert que sur `127.0.0.1`.

### Application (Plesk)

1. Domaine en PHP 8.3, racine du site sur `web/public`, base MySQL.
2. `composer install --no-dev --optimize-autoloader`, `npm ci && npm run build`.
3. `.env` : `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL`, accès MySQL,
   `VOICE_ENGINE_URL=http://127.0.0.1:8100`, `VOICE_ENGINE_TOKEN` identique à `ENGINE_TOKEN`.
4. `php artisan key:generate`, `php artisan migrate --force`, `php artisan user:create …`.
5. Worker de file d'attente : tâche planifiée Plesk, chaque minute :
   `php artisan queue:work --stop-when-empty --max-time=3300 --timeout=960`
   (ou un service systemd / Supervisor exécutant `php artisan queue:work --timeout=960`).
6. Limites d'envoi PHP (`upload_max_filesize`, `post_max_size`) à 25 Mo ou plus pour les échantillons.

## Usage responsable

L'ajout d'une voix exige de confirmer qu'il s'agit de sa propre voix ou que la personne enregistrée
a donné son accord ; la date de cette confirmation est conservée. Ne clonez jamais la voix d'un tiers
sans son consentement.
