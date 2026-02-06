# Step 1: Local Development Setup

To develop the Nextcloud app, we need a running Nextcloud instance that can talk to our local Synaplan instance.

## 1. Docker Compose Configuration
We will add a Nextcloud service to a separate `docker-compose.nextcloud.yml` to keep the main stack clean, or append to the main `docker-compose.yml` temporarily.

**Requirements:**
- **Image:** `nextcloud:latest` (or target version, e.g., 28/29).
- **Network:** Must be on the same Docker network as `synaplan-backend` (`synaplan_default` usually).
- **Volumes:**
    - Map a local folder `./nextcloud-apps/synaplan_integration` to `/var/www/html/custom_apps/synaplan_integration`.
    - This allows editing code locally and seeing changes immediately.

## 2. Setup Instructions

1.  **Create App Directory:**
    ```bash
    mkdir -p nextcloud-apps/synaplan_integration
    ```

2.  **Start Nextcloud:**
    ```yaml
    # docker-compose.nextcloud.yml (Example)
    services:
      nextcloud:
        image: nextcloud:29
        ports:
          - "8081:80"
        volumes:
          - ./nextcloud-apps:/var/www/html/custom_apps
        environment:
          - MYSQL_HOST=db
          - ...
        networks:
          - default
    ```

3.  **Install App:**
    - Access Nextcloud at `http://localhost:8081`.
    - Enable "External storage" (optional, for testing RAG).
    - Go to Apps -> Your Apps -> Enable "Synaplan Integration" (once skeleton is created).

4.  **Connectivity Test:**
    - Enter the Synaplan container IP or service name (`http://backend:8000`) in the App Settings.
    - Test connection.

## 3. Scaffolding the App
Use the `occ` tool inside the container to generate the skeleton if starting from scratch, or manually create:
- `appinfo/info.xml`
- `appinfo/routes.php`
- `appinfo/app.php` (if needed for older versions, prefer `boot` in `Application.php`)
