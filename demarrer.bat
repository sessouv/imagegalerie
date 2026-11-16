@echo off
rem Demarre Timbre : moteur vocal, site et file d'attente, puis ouvre le navigateur.
rem Fermer les trois fenetres ouvertes arrete tout.
setlocal
cd /d "%~dp0"

rem PHP de Laragon, s'il n'est pas deja accessible
where php >nul 2>nul
if errorlevel 1 (
    for /d %%d in (C:\laragon\bin\php\php-*) do set "PATH=%%d;%PATH%"
)
where php >nul 2>nul
if errorlevel 1 (
    echo PHP est introuvable. Lancez ce fichier depuis le terminal de Laragon.
    pause
    exit /b 1
)

if not exist "engine\.venv\Scripts\python.exe" (
    echo Le moteur n'est pas installe : dossier engine\.venv absent. Voir README.md.
    pause
    exit /b 1
)
if not exist "web\.env" (
    echo Le fichier web\.env est absent. Voir README.md.
    pause
    exit /b 1
)

rem Le moteur utilise le meme jeton que le site
set "ENGINE_TOKEN="
for /f "usebackq tokens=1,* delims==" %%a in ("web\.env") do (
    if "%%a"=="VOICE_ENGINE_TOKEN" set "ENGINE_TOKEN=%%b"
)
if "%ENGINE_TOKEN%"=="" (
    echo VOICE_ENGINE_TOKEN est vide dans web\.env.
    pause
    exit /b 1
)

rem Reprend les generations interrompues au dernier arret
pushd web
php artisan generations:resume
popd

start "Timbre - moteur vocal" /d "%~dp0engine" cmd /k ".venv\Scripts\python.exe -m uvicorn app.main:app --port 8100"
start "Timbre - site" /d "%~dp0web" cmd /k "php artisan serve --port=8000"
start "Timbre - file d'attente" /d "%~dp0web" cmd /k "php artisan queue:work"

echo Ouverture du navigateur dans quelques secondes...
timeout /t 6 /nobreak >nul
start "" "http://127.0.0.1:8000"
endlocal
