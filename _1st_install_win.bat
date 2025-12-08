@echo off
setlocal enabledelayedexpansion

set MIN_DOCKER_MAJOR=24

where docker >nul 2>&1
if errorlevel 1 (
    echo ❌ Docker is required. Install Docker Desktop from https://docs.docker.com/desktop/ and rerun this script.
    exit /b 1
)

for /f "tokens=3" %%i in ('docker --version') do set DOCKER_VERSION_RAW=%%i
set DOCKER_VERSION=%DOCKER_VERSION_RAW:,=%
for /f "tokens=1 delims=." %%i in ("%DOCKER_VERSION%") do set DOCKER_MAJOR=%%i
if "%DOCKER_MAJOR%"=="" (
    echo ❌ Unable to detect Docker version. Update Docker and retry.
    exit /b 1
)
set /a CHECK_MAJOR=%DOCKER_MAJOR% - %MIN_DOCKER_MAJOR%
if %CHECK_MAJOR% LSS 0 (
    echo ❌ Docker %MIN_DOCKER_MAJOR%.x or newer is required (found %DOCKER_VERSION%).
    exit /b 1
)

docker compose version >nul 2>&1
if errorlevel 1 (
    echo ❌ Docker Compose plugin is missing. Update Docker Desktop to get 'docker compose'.
    exit /b 1
)

echo ✅ Docker %DOCKER_VERSION% detected
for /f "delims=" %%i in ('docker compose version') do (
    if not defined COMPOSE_VERSION (
        set COMPOSE_VERSION=%%i
    )
)
if defined COMPOSE_VERSION (
    echo ✅ !COMPOSE_VERSION!
)

echo.
echo ================= AI Provider Setup =================
echo 1^) Local Ollama (gpt-oss:20b + bge-m3) - requires ~24GB GPU RAM
echo 2^) Groq Cloud API (recommended, free + fast)
set /p AI_CHOICE=Select provider [1/2, default=2]:
if "%AI_CHOICE%"=="" set AI_CHOICE=2
set USE_GROQ=0
if not "%AI_CHOICE%"=="1" set USE_GROQ=1

if "%USE_GROQ%"=="1" (
    echo.
    echo Great! Grab a free API key at https://console.groq.com/keys
:ask_groq_key
    set /p GROQ_KEY=Enter your GROQ_API_KEY:
    if "%GROQ_KEY%"=="" (
        echo Key cannot be empty.
        goto :ask_groq_key
    )
    if not exist backend\.env (
        copy backend\.env.example backend\.env >nul
    )
    powershell -NoProfile -Command "$envFile='backend/.env'; $key='%GROQ_KEY%'; $content = Get-Content $envFile; if ($content -match '^GROQ_API_KEY=') { ($content -replace '^GROQ_API_KEY=.*', \"GROQ_API_KEY=$key\") | Set-Content $envFile } else { Add-Content -Path $envFile -Value \"`nGROQ_API_KEY=$key\" }"
    if errorlevel 1 goto :error
    echo ✅ GROQ_API_KEY stored in backend\.env
)

echo.
echo 📦 Pulling containers (this may take a minute)...
docker compose pull
if errorlevel 1 goto :error

echo.
if "%USE_GROQ%"=="1" (
    echo 🚀 Starting stack (Groq cloud mode - still downloading bge-m3 locally)...
    set "AUTO_DOWNLOAD_MODELS=true"
    set "ENABLE_LOCAL_GPT_OSS=false"
) else (
    echo 🚀 Starting stack (Auto-download of gpt-oss:20b and bge-m3 enabled)...
    set "AUTO_DOWNLOAD_MODELS=true"
    set "ENABLE_LOCAL_GPT_OSS=true"
)
docker compose up -d
if errorlevel 1 goto :error

echo.
if "%USE_GROQ%"=="1" (
    echo 📡 Tracking Ollama model download (bge-m3 for RAG). Press Ctrl+C to skip.
) else (
    echo 📡 Tracking Ollama model downloads (gpt-oss:20b, bge-m3). Press Ctrl+C to skip.
)
powershell -NoProfile -Command "docker compose logs -f backend | Where-Object {\$_ -match '\[Background\]'} | ForEach-Object { Write-Host \$_ -NoNewline; Write-Host ''; if (\$_ -match 'Background.*🎉 Model downloads completed!') { exit 0 } }"
if errorlevel 1 (
    echo ⚠️ Could not confirm model download completion. Check 'docker compose logs backend'.
) else (
    echo ✅ Required Ollama models downloaded.
)

set READY=0
echo.
echo ⏳ Waiting for backend console availability...
for /L %%i in (1,1,30) do (
    docker compose exec backend php bin/console about >nul 2>&1
    if not errorlevel 1 (
        set READY=1
        goto :after_wait
    )
    timeout /t 2 >nul
)

:after_wait
if "%READY%"=="1" (
    echo 🧱 Updating database schema...
    docker compose exec backend php bin/console doctrine:schema:update --force --complete
    if errorlevel 1 goto :error
    echo 🌱 Loading fixtures...
    docker compose exec backend php bin/console doctrine:fixtures:load --no-interaction
    if errorlevel 1 goto :error
    if "%USE_GROQ%"=="1" (
        echo ⚙️ Switching defaults to Groq llama-3.3-70b-versatile...
        docker compose exec backend php bin/console dbal:run-sql "UPDATE BCONFIG SET BVALUE='9' WHERE BGROUP='DEFAULTMODEL' AND BSETTING IN ('CHAT','SORT')"
        if errorlevel 1 goto :error
        docker compose exec backend php bin/console dbal:run-sql "UPDATE BCONFIG SET BVALUE='groq' WHERE BOWNERID=0 AND BGROUP='ai' AND BSETTING='default_chat_provider'"
        if errorlevel 1 goto :error
    )
) else (
    echo ⚠️ Backend console did not become ready; run schema update + fixtures manually.
)

echo.
echo 🎉 Setup complete! Logins: admin@synaplan.com / admin123
echo 👉 Next time, you can simply run 'docker compose up -d'
echo 🌐 Frontend URL: http://localhost:5173
exit /b 0

:error
echo ❌ Something went wrong during Docker startup. Review the errors above.
exit /b 1
