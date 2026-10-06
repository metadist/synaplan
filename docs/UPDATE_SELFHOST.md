# Update a Self-Hosted Deployment

Synaplan never updates itself. Nothing changes until you run the steps below.

## Which version to install

Synaplan shows its current version in the sidebar. When a newer release exists,
the admin area shows that new version number next to the current one, together
with a link to its release notes. Read the release notes, then use that version
number in step 2.

## Update

Run every command from the repository root.

1. **Create a backup.** Do not continue if this command fails. The app is
   briefly unavailable while the backup is taken.

```bash
deploy/scripts/pre-update.sh
```

2. **Set the new version** in `deploy/.env`, without a leading `v`:

```dotenv
# Example format only — use the version shown in Synaplan.
SYNAPLAN_VERSION=1.4.0
```

3. **Start the new version.** The new image is downloaded, and the database
   migrations run automatically when the app starts.

```bash
docker compose --env-file deploy/.env -f deploy/compose.yaml up -d
```

4. **Verify.** This waits for every service, checks health, and prints the
   running version.

```bash
deploy/scripts/post-update.sh
```

## Roll back

Put the previous version back in `deploy/.env` and repeat steps 3 and 4. If the
new version already changed the database, also restore the backup from step 1 as
described in [Backup and restore](../deploy/README.md#backup-and-restore).

## Quickstart volumes

The two-file quickstart (`deploy/quickstart/compose.yaml`) keeps its data in
named Docker volumes instead of `deploy/data`. Run these commands in the folder
that holds its `compose.yaml`. For a stack created in a Docker GUI, run them on
the Docker host; the project name is the stack name.

1. **Back up** every volume of the project, the generated secrets included:

```bash
project=$(docker compose ps -a --format '{{.Project}}' | head -n1)
docker compose stop
mkdir -p backup
for v in $(docker volume ls -q --filter "label=com.docker.compose.project=$project"); do
  docker run --rm -v "$v:/v:ro" -v "$PWD/backup:/b" alpine tar czf "/b/$v.tgz" -C /v .
done
docker compose start
```

2. **Change the release** with `SYNAPLAN_VERSION=1.4.0` in the `.env` beside
   `compose.yaml` (or in the stack's environment variables), then run
   `docker compose up -d`.

3. **Roll back** by setting the previous version and restoring the backup into
   fresh volumes:

```bash
docker compose down -v
docker compose create
for f in backup/*.tgz; do
  docker run --rm -v "$(basename "$f" .tgz):/v" -v "$PWD/backup:/b:ro" alpine tar xzf "/b/$(basename "$f")" -C /v
done
docker compose up -d
```

`docker compose down -v` deletes the data of this project. Only run step 3 with
the backup from step 1 next to you.

## Good to know

A redeploy on its own never installs a newer version — the version only changes
when you change it. Always use a released version number; `latest` is rejected
before anything starts.
