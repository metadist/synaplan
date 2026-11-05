> **[Main README](../README.md)** · **[Documentation Index](README.md)** · **Track 1: Getting Started & Deployment (6/8)**  
> **← Previous:** [1.5 Runtime & Outbound Options](RUNTIME_OPTIONS.md) · **Next:** [1.7 Updating on Elestio →](UPDATE_ELESTIO.md)

# Update a Self-Hosted Deployment

> **In this guide:** How to safely back up, update, verify, and roll back a self-hosted Synaplan deployment—both for the full `deploy/compose.yaml` contract and for named Docker volumes (`deploy/quickstart/compose.yaml` / Portainer / Dockge).

Synaplan never updates itself automatically. Nothing changes on your server until you run the steps below.

---

## Which Version to Install

Synaplan shows its running version in the sidebar. When a newer release is published, the admin area displays the new version number next to the current one with a link to the release notes. Read the release notes first, then use that version number in step 2.

---

## Standard Production Update (`deploy/compose.yaml`)

Run every command from the repository root:

1. **Create a backup.** Do not continue if this command fails. The app pauses briefly while a consistent MariaDB, Qdrant, and uploads snapshot is taken.

```bash
deploy/scripts/pre-update.sh
```

2. **Set the new version** in `deploy/.env`, without a leading `v`:

```dotenv
# Example format only — use the release version shown in Synaplan.
SYNAPLAN_VERSION=1.4.0
```

3. **Start the new version.** Docker pulls the pinned image and the web container applies database migrations automatically on startup.

```bash
docker compose --env-file deploy/.env -f deploy/compose.yaml up -d
```

4. **Verify the deployment.** This script waits for every service to pass its health check and prints the running version.

```bash
deploy/scripts/post-update.sh
```

### Roll Back

Put the previous version number back in `deploy/.env` and repeat steps 3 and 4. If the new version already applied database migrations, restore the pre-update backup from step 1 as described in [Backup and restore](../deploy/README.md#backup-and-restore).

---

## Quickstart volumes

The quickstart (`deploy/quickstart/compose.yaml`) stores its data in named Docker volumes instead of `deploy/data`. The commands below run in a terminal on the Docker host and do not need `compose.yaml` in the current directory, so they work identically for a CLI install and for a stack created in Portainer, Dockge, or Synology Container Manager.

First, find the Compose project name (`docker compose ls -a` lists it—the folder name for a CLI install, or the stack name in a GUI):

```bash
docker compose ls -a
project=synaplan   # replace with the name from the list above
```

1. **Back up every volume of the project**, including the generated `secrets` volume. Synaplan is unavailable for the few seconds this takes. Because the archives contain the database and every secret, restrict the backup folder to your user:

```bash
docker compose -p "$project" stop
mkdir -p backup && chmod 700 backup
for v in $(docker volume ls -q --filter "label=com.docker.compose.project=$project"); do
  docker run --rm -v "$v:/v:ro" -v "$PWD/backup:/b" alpine tar czf "/b/$v.tgz" -C /v .
done
docker compose -p "$project" start
```

2. **Change the release:** Set `SYNAPLAN_VERSION` to a version from the [releases page](https://github.com/metadist/synaplan/releases) (without a leading `v`).
   - **CLI:** Set `SYNAPLAN_VERSION` in the `.env` file beside `compose.yaml` and run `docker compose up -d`.
   - **Docker GUI:** Set `SYNAPLAN_VERSION` in the stack's environment variables and redeploy the stack.

3. **Roll back (if needed):** Stop the project, restore the archives from step 1 into their volumes, set the previous `SYNAPLAN_VERSION`, and start the stack again:

```bash
docker compose -p "$project" stop
for f in backup/*.tgz; do
  docker run --rm -v "$(basename "$f" .tgz):/v" -v "$PWD/backup:/b:ro" alpine \
    sh -c 'find /v -mindepth 1 -delete && tar xzf "/b/$1" -C /v' sh "$(basename "$f")"
done
```

> **Important:** Step 3 overwrites the current contents of those volumes with the backup from step 1. The volumes keep their names and Docker labels, so future backups will find them again.

---

## Good to Know

A container restart or redeploy on its own never installs a newer version—the version only changes when you change `SYNAPLAN_VERSION`. Always use an explicit released version number; mutable tags like `latest` are rejected before anything starts.

---

> **[Main README](../README.md)** · **[Documentation Index](README.md)** · **Track 1: Getting Started & Deployment (6/8)**  
> **← Previous:** [1.5 Runtime & Outbound Options](RUNTIME_OPTIONS.md) · **Next:** [1.7 Updating on Elestio →](UPDATE_ELESTIO.md)
