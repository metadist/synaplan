# SynaPlan Docker Development Environment

This directory contains all the necessary files to run the SynaPlan PHP application in a Docker containerized environment for development purposes.

## 🚀 Quick Start

### Prerequisites

- Docker Desktop installed and running
- Docker Compose ≥ v2.20 (usually included with Docker Desktop)
- At least 4GB of available RAM

### Getting Started

1. **Navigate to the project root:**
   ```bash
   cd /path/to/synaplan
   ```

2. **Start the complete development environment:**
   ```bash
   docker compose up -d
   ```
   
   **🎯 That's it!** The system automatically:
   - Downloads and installs all dependencies (Composer + NPM)
   - Downloads Whisper models (~3GB) for audio transcription
   - Downloads Ollama AI models (llama3.2:3b, mistral:7b, codellama:7b)
   - Starts all services with proper health checks
   - Caches everything for fast subsequent starts
   
   **First-time setup:** The initial download of models may take several minutes. Monitor progress with:
   ```bash
   # Monitor Whisper model downloads
   docker compose logs -f whisper-models
   
   # Monitor Ollama model downloads  
   docker compose logs -f ollama
   ```
   
   **Subsequent starts:** All models are cached and startup is fast.

3. **Access the application:**
   - **Main Application:** http://localhost:8080
   - **phpMyAdmin:** http://localhost:8081
   - **Ollama API:** http://localhost:11434

## 📁 File Structure

```
dev/docker/
├── Dockerfile              # PHP application container definition
├── Dockerfile.ollama       # Ollama AI models container definition
├── docker-compose.yml      # Multi-container orchestration
├── .dockerignore          # Files to exclude from Docker build
└── README.md              # This file
```

## 🐳 Services

The Docker Compose setup includes the following services:

### 1. **app** (PHP Application)
- **Port:** 8080
- **Base Image:** PHP 8.3 with Apache
- **Features:**
  - All PHP extensions required by the application
  - Composer for dependency management
  - Apache with mod_rewrite enabled
  - Hot-reload for development (volume mounted)
  - Automatic dependency installation via one-shot services

### 2. **db** (MariaDB Database)
- **Port:** 3306
- **Base Image:** mariadb:11.8.2
- **Features:**
  - Automatic database initialization with SQL files
  - Persistent data storage
  - Pre-configured user and database
  - Health checks for service dependencies

### 3. **ollama** (Local AI Models)
- **Port:** 11434
- **Base Image:** ollama/ollama:latest
- **Features:**
  - Local AI model hosting
  - Automatic model downloads (llama3.2:3b, mistral:7b, codellama:7b)
  - Persistent model storage
  - Health checks for service dependencies
  - Robust startup with ready-endpoint detection

### 4. **whisper-models** (Audio Transcription Models)
- **One-shot service** (runs once and exits)
- **Base Image:** alpine:3.20
- **Features:**
  - Downloads Whisper models (base, medium, medium.en)
  - Idempotent downloads (only downloads missing files)
  - Persistent model storage
  - Robust download with retries and timeouts

### 5. **composer-install** (PHP Dependencies)
- **One-shot service** (runs once and exits)
- **Base Image:** composer:2
- **Features:**
  - Installs PHP dependencies via Composer
  - Idempotent installation (only installs if vendor/autoload.php missing)
  - Persistent dependency storage

### 6. **npm-install** (Node.js Dependencies)
- **One-shot service** (runs once and exits)
- **Base Image:** node:20-alpine
- **Features:**
  - Installs Node.js dependencies via NPM
  - Idempotent installation (only installs if node_modules missing)
  - Persistent dependency storage

### 7. **phpmyadmin** (Database Management)
- **Port:** 8081
- **Base Image:** phpMyAdmin latest
- **Features:**
  - Web-based MySQL administration
  - Pre-configured connection to the database

## 🔧 Configuration

### Environment Variables

All environment variables are configured directly in `docker-compose.yml`. The following variables are available for customization:

#### Database Configuration
The database is automatically configured with:
- **Database:** synaplan
- **User:** synaplan_user
- **Password:** synaplan_password
- **Root Password:** root_password

#### Application Configuration
- **APP_ENV:** development
- **APP_DEBUG:** true
- **DB_HOST:** db
- **DB_NAME:** synaplan
- **DB_USER:** synaplan_user
- **DB_PASSWORD:** synaplan_password
- **OLLAMA_SERVER:** ollama:11434

#### Composer Configuration
- **COMPOSER_ALLOW_SUPERUSER:** 1
- **COMPOSER_PROCESS_TIMEOUT:** 1600

### Customizing Configuration

To modify any of these settings, edit the `environment` section in `docker-compose.yml`:

```yaml
environment:
  - APP_ENV=development
  - APP_DEBUG=true
  - DB_HOST=db
  # Add your custom variables here
```

## 🔄 Migration from Shell Scripts

**⚠️ IMPORTANT: Legacy shell scripts are deprecated and no longer needed!**

All functionality has been replaced by modern Docker Compose automation. The system now automatically handles all model downloads and dependency installation through Docker services.

### What Was Replaced

#### `download-whisper-models.sh`
**Replaced by:** `whisper-models` service in docker-compose.yml
- **Function:** Downloads Whisper models for audio transcription
- **New approach:** One-shot Alpine container that downloads models idempotently
- **Benefits:** Automatic, robust, with retries and timeouts

#### `pull-models.sh`
**Replaced by:** `Dockerfile.ollama` with integrated ENTRYPOINT
- **Function:** Downloads Ollama AI models
- **New approach:** Direct integration in Dockerfile with automatic model pulling
- **Benefits:** No external scripts, clean Docker-based solution

#### `manage-whisper-models.sh`
**Replaced by:** `whisper-models` service with idempotent downloads
- **Function:** Manages Whisper model lifecycle
- **New approach:** Automatic detection and download of missing models
- **Benefits:** Zero manual intervention required

### Service Dependencies
The system uses proper Docker Compose service dependencies:
- `app` waits for `db` (healthy)
- `app` waits for `ollama` (healthy)
- `app` waits for `whisper-models` (completed)
- `app` waits for `composer-install` (completed)
- `app` waits for `npm-install` (completed)

### Persistent Storage
All downloads are cached in named volumes:
- `whisper_models` - Whisper model files
- `ollama_data` - Ollama AI models
- `vendor` - Composer dependencies
- `node_modules` - NPM dependencies

### Migration Steps

If you were using the old scripts:

1. **Delete the scripts** - They're no longer needed
2. **Use `docker compose up -d`** - Everything is automated now
3. **Monitor with logs** - Use `docker compose logs -f [service]` to watch progress
4. **Reset if needed** - Delete volumes to force re-download

### Why This Change?

The new approach provides:
- **Better reliability** - Robust error handling and retries
- **Simpler workflow** - One command instead of multiple scripts
- **Better caching** - Persistent volumes prevent unnecessary re-downloads
- **Health checks** - Proper service dependency management
- **Modern Docker practices** - Follows current best practices

## 🛠️ Development Commands

### Start Services
```bash
docker compose up -d
```

**Note:** First run downloads models automatically; subsequent starts skip download. Re-download = delete volume.

### Stop Services
```bash
docker compose down
```

### View Logs
```bash
# All services
docker compose logs

# Specific service
docker compose logs app
docker compose logs db
docker compose logs ollama
docker compose logs whisper-models
```

### Rebuild Application
```bash
docker compose build app
docker compose up -d app
```

### Access Container Shell
```bash
# PHP application container
docker compose exec app bash

# Database container
docker compose exec db mysql -u synaplan_user -p synaplan
```

### Force Re-install Dependencies
```bash
# Reinstall Composer dependencies
docker compose down
docker volume rm synaplan_vendor
docker compose up -d

# Reinstall NPM dependencies
docker compose down
docker volume rm synaplan_node_modules
docker compose up -d
```

## 🔍 Troubleshooting

### Common Issues

1. **Port Already in Use:**
   ```bash
   # Check what's using the port
   netstat -ano | findstr :8080
   
   # Change ports in docker-compose.yml if needed
   ```

2. **Database Connection Issues:**
   ```bash
   # Check if database is running
   docker compose ps db
   
   # Check database logs
   docker compose logs db
   ```

3. **Permission Issues:**
   ```bash
   # Fix file permissions
   docker compose exec app chown -R www-data:www-data /var/www/html
   ```

4. **Memory Issues:**
   - Increase Docker Desktop memory allocation
   - Stop unnecessary containers: `docker system prune`

### Reset Everything
```bash
# Stop and remove all containers, networks, and volumes
docker compose down -v

# Remove all images
docker system prune -a

# Start fresh (downloads models automatically)
docker compose up -d
```

### Re-download Models
```bash
# Re-download Whisper models
docker compose down
docker volume rm synaplan_whisper_models
docker compose up -d

# Re-download Ollama models
docker compose down
docker volume rm synaplan_ollama_data
docker compose up -d
```

## 📊 Monitoring

### Check Service Status
```bash
docker compose ps
```

### Monitor Resource Usage
```bash
docker stats
```

### View Application Logs
```bash
# Apache logs
docker compose exec app tail -f /var/log/apache2/access.log
docker compose exec app tail -f /var/log/apache2/error.log
```

## 🔒 Security Notes

- This setup is for **development only**
- Never use default passwords in production
- Keep API keys secure and never commit them to version control
- Consider using Docker secrets for production deployments

## 🚀 Production Considerations

For production deployment:

1. Use environment-specific Dockerfiles
2. Implement proper SSL/TLS certificates
3. Use Docker secrets for sensitive data
4. Set up proper backup strategies
5. Configure monitoring and logging
6. Use a reverse proxy (nginx/traefik)
7. Implement health checks
8. Set up CI/CD pipelines

## 📞 Support

If you encounter issues:

1. Check the troubleshooting section above
2. Review Docker and application logs
3. Ensure all prerequisites are met
4. Verify environment variable configuration 
