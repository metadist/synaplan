# Synaplan plain Ubuntu setup

Short checklist for running the Symfony backend and Vite/Vue frontend directly on Ubuntu 22.04/24.04 without Docker. All commands are copy‑paste ready.

## 1. Base system packages
```bash
sudo apt update && sudo apt install -y ca-certificates curl gnupg lsb-release software-properties-common unzip git make build-essential pkg-config
```

## 2. PHP 8.3 + Apache (replacement for FrankenPHP)
```bash
sudo add-apt-repository ppa:ondrej/php -y && sudo apt update
sudo apt install -y apache2 libapache2-mod-fcgid php8.3 php8.3-cli php8.3-fpm php8.3-common php8.3-mysql php8.3-xml php8.3-mbstring php8.3-intl php8.3-zip php8.3-gd php8.3-curl php8.3-bcmath php8.3-exif php8.3-pcntl php8.3-opcache php8.3-readline php8.3-soap php8.3-sodium php8.3-ffi php8.3-imagick php8.3-imap php8.3-redis
sudo apt install -y ghostscript imagemagick libmagickwand-dev poppler-utils ffmpeg libavcodec-extra libavformat-dev libavutil-dev libswscale-dev libavfilter-dev libswresample-dev libx264-dev libx265-dev libvpx-dev libmp3lame-dev libopus-dev libsodium-dev libffi-dev libpng-dev libjpeg-dev libfreetype6-dev libzip-dev libxml2-dev libonig-dev cmake gfortran libopenblas-dev liblapack-dev protobuf-compiler
sudo a2enmod proxy proxy_fcgi setenvif rewrite headers expires ssl && sudo a2enconf php8.3-fpm && sudo systemctl reload apache2
```
Apache 2.4 + PHP‑FPM easily replaces FrankenPHP as long as you keep the same extensions/uploads limits. Create `/etc/php/8.3/fpm/conf.d/99-synaplan.ini` with:
```
upload_max_filesize=128M
post_max_size=128M
max_file_uploads=50
memory_limit=512M
```

## 3. Node.js toolchain (frontend + Vite)
```bash
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
sudo apt install -y nodejs
```

## 4. Optional helpers
```bash
sudo apt install -y redis-server supervisor
```
*(Use Redis for Messenger transports if you don’t want to rely on MariaDB queues; use Supervisor/systemd to keep workers alive.)*

## 5. Local Apache Tika (optional)
```bash
sudo apt install -y default-jre-headless
sudo mkdir -p /wwwroot/synaplan/services/tika && cd /wwwroot/synaplan/services/tika
curl -fL -o tika-server.jar https://dlcdn.apache.org/tika/2.9.2/tika-server-standard-2.9.2.jar \
  || curl -fL -o tika-server.jar https://archive.apache.org/dist/tika/2.9.2/tika-server-standard-2.9.2.jar
sha256sum tika-server.jar             # optional integrity check
nohup java -jar tika-server.jar --host 0.0.0.0 --port 9998 >/wwwroot/synaplan/services/tika/tika.log 2>&1 &
sleep 3 && curl -fsS http://127.0.0.1:9998/tika | head -n1   # should print the HTML banner
```
*Stop the background copy any time with `pkill -f tika-server.jar`. A “Please PUT” banner in the curl response confirms the server is ready. Point `TIKA_BASE_URL=http://localhost:9998`.*

**Systemd autostart (optional)**
```bash
sudo tee /etc/systemd/system/tika.service >/dev/null <<'EOF'
[Unit]
Description=Apache Tika Server
After=network.target

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=/wwwroot/synaplan/services/tika
ExecStart=/usr/bin/java -jar /wwwroot/synaplan/services/tika/tika-server.jar --host 0.0.0.0 --port 9998
Restart=on-failure
RestartSec=5

[Install]
WantedBy=multi-user.target
EOF
sudo systemctl daemon-reload
sudo systemctl enable --now tika.service
sudo systemctl status --no-pager tika.service
```

## 6. (Optional) Whisper.cpp binary (for on-box transcription)
```bash
git clone https://github.com/ggerganov/whisper.cpp.git /tmp/whisper && cd /tmp/whisper && git checkout v1.5.4 && make -j"$(nproc)" && sudo cp main /usr/local/bin/whisper && sudo cp quantize /usr/local/bin/whisper-quantize
mkdir -p /wwwroot/synaplan/backend/var/whisper && curl -fL -o /wwwroot/synaplan/backend/var/whisper/ggml-base.bin https://huggingface.co/ggerganov/whisper.cpp/resolve/main/ggml-base.bin && chmod 644 /wwwroot/synaplan/backend/var/whisper/*.bin
```
*(Skip and set `WHISPER_ENABLED=false` if you prefer remote transcription.)*

## 7. Project checkout
```bash
sudo mkdir -p /wwwroot && cd /wwwroot
git clone <repo-url> synaplan && cd /wwwroot/synaplan
cp backend/.env.example backend/.env
cp frontend/.env.example frontend/.env
```

## 8. Ollama service + required models
Install the systemd-managed Ollama daemon (same API the Docker stack exposes) and make sure the two baseline models exist before Symfony starts:
```bash
curl -fsSL https://ollama.com/install.sh | sh
sudo systemctl enable --now ollama
sudo systemctl status --no-pager ollama
sudo ss -ltnp | grep 11434   # should show ollama on 127.0.0.1:11434
```

Pull the chat + embedding models once so the backend can answer immediately:
```bash
ollama pull gpt-oss:20b
ollama pull bge-m3
ollama list
curl -fsS http://127.0.0.1:11434/api/tags | grep -E 'gpt-oss|bge-m3'
```
*If the backend lives on another host, run `sudo systemctl edit ollama`, set `Environment="OLLAMA_HOST=0.0.0.0"`, restart the service, and point `OLLAMA_BASE_URL=http://<ollama-host>:11434` in `backend/.env`. When Ollama is local keep `AUTO_DOWNLOAD_MODELS=false` so Symfony skips redundant pulls.*

## 9. Backend environment essentials
- `APP_ENV=prod`, `APP_URL=https://api.example.com`
- Database (you said you can provide MariaDB 11 → create DB/user first):
  ```
  DATABASE_WRITE_URL=mysql://synaplan_user:strongpass@db-host:3306/synaplan?serverVersion=11.8&charset=utf8mb4
  DATABASE_READ_URL=mysql://synaplan_user:strongpass@db-host:3306/synaplan?serverVersion=11.8&charset=utf8mb4
  ```
- Set `MESSENGER_TRANSPORT_DSN=doctrine://default` (or `redis://localhost:6379/messages` if you enabled Redis).
- Point to your existing services:
  - `OLLAMA_BASE_URL=http://<ollama-host>:11434`
  - `TIKA_BASE_URL=http://<tika-host>:9998`
  - `MAILER_DSN=smtp://AWS_SMTP_USER:AWS_SMTP_PASS@email-smtp.<region>.amazonaws.com:587`
  - `AI_DEFAULT_PROVIDER=ollama`
  - `FRONTEND_URL=https://app.example.com`
- Disable auto model downloads if you already run Ollama elsewhere: `AUTO_DOWNLOAD_MODELS=false`.
- Generate/update JWT keys: `php bin/console lexik:jwt:generate-keypair`.
- Adjust optional providers (Groq/OpenAI/WhatsApp/Stripe) as needed.

## 10. Backend install & database prep
```bash
cd /wwwroot/synaplan/backend
composer install --no-dev --optimize-autoloader
php bin/console lexik:jwt:generate-keypair --skip-if-exists
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console doctrine:fixtures:load --no-interaction # optional demo data
php bin/console cache:clear --env=prod
php bin/console messenger:setup-transports --no-interaction
```
Ensure writable dirs:
```bash
sudo chown -R www-data:www-data var public/up && sudo chmod -R 775 var public/up
```

Run background workers (keep under Supervisor/systemd):
```bash
php bin/console messenger:consume async_ai_high async_extract async_index -vv
```

## 11. Apache virtual host example
```
<VirtualHost *:80>
    ServerName api.example.com
    DocumentRoot /wwwroot/synaplan/backend/public

    <Directory /wwwroot/synaplan/backend/public>
        AllowOverride All
        Require all granted
        FallbackResource /index.php
    </Directory>

    ProxyPassMatch ^/(.*\.php(/.*)?)$ unix:/run/php/php8.3-fpm.sock|fcgi://localhost/wwwroot/synaplan/backend/public
    ErrorLog ${APACHE_LOG_DIR}/synaplan-error.log
    CustomLog ${APACHE_LOG_DIR}/synaplan-access.log combined
</VirtualHost>
```
Enable the site (`sudo a2ensite synaplan.conf && sudo systemctl reload apache2`). Use HTTPS in production.

## 12. Frontend environment & build
Edit `frontend/.env`:
- `VITE_API_BASE_URL=https://api.example.com`
- Optional toggles: `VITE_RECAPTCHA_ENABLED`, `VITE_SHOW_ERROR_STACK=false`, etc.

Install & serve:
```bash
cd /wwwroot/synaplan/frontend
npm install
npm run dev -- --host 0.0.0.0 --port 5173   # development
npm run build && npm run preview -- --host 0.0.0.0 --port 4173  # production preview
```
For production hosting, serve `frontend/dist` via nginx/Apache and proxy API calls to the backend.

## 13. External services you already have
- **Ollama**: expose `http://<host>:11434`, ensure backend host can reach it, and pre-pull `gpt-oss:20b` + `bge-m3` there.
- **Amazon SES**: use SMTP credentials in `MAILER_DSN`.
- **Apache Tika**: leave the service running at `http://<tika-host>:9998`; backend only needs the URL.
- **MariaDB**: provision an empty schema, grant `CREATE/DROP/ALTER` so migrations succeed.

## 14. Final checklist
1. Confirm `php -m` lists every extension above (especially `imagick`, `intl`, `imap`, `ffi`, `sodium`).
2. Run `php bin/console about` to verify Symfony sees the environment.
3. Hit `https://api.example.com/api/health` (healthcheck endpoint referenced in docker-compose).
4. Start the frontend and visit `https://app.example.com` (or `http://localhost:5173`) to log in with the seeded demo users (`admin@synaplan.com / admin123`, etc.).
5. Monitor logs: `tail -f var/log/prod.log`, `sudo journalctl -u apache2`, `tail -f frontend/vite.log`.

That’s all that is required to reproduce the Docker Compose setup on a plain Ubuntu host.

