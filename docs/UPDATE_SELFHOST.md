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

The quickstart (`deploy/quickstart/compose.yaml`) keeps its data in
named Docker volumes instead of `deploy/data`. The commands below run in a
terminal on the Docker host and need no `compose.yaml` there, so they work the
same for a CLI install and for a stack created in Portainer, Dockge or Synology
Container Manager. They only need the project name: `docker compose ls -a`
lists it (the folder name for a CLI install, the stack name in a GUI).

```bash
docker compose ls -a
project=synaplan   # the name from the list above
```

1. **Back up** every volume of the project, the generated secrets included.
   Synaplan is unavailable for the few seconds this takes. The archives hold
   the database and every password, so only your user may read the folder:

```bash
docker compose -p "$project" stop
mkdir -p backup && chmod 700 backup
for v in $(docker volume ls -q --filter "label=com.docker.compose.project=$project"); do
  docker run --rm -v "$v:/v:ro" -v "$PWD/backup:/b" alpine tar czf "/b/$v.tgz" -C /v .
done
docker compose -p "$project" start
```

2. **Change the release**: set `SYNAPLAN_VERSION` to a version from the
   [releases page](https://github.com/metadist/synaplan/releases) (without a
   leading `v`). CLI: put it in the `.env` beside `compose.yaml` and run
   `docker compose up -d`. GUI: set it in the stack's environment variables and
   redeploy the stack.

3. **Roll back**: stop the project, put the backup from step 1 back into its
   volumes, then set the previous `SYNAPLAN_VERSION` and start it as in step 2:

```bash
docker compose -p "$project" stop
for f in backup/*.tgz; do
  docker run --rm -v "$(basename "$f" .tgz):/v" -v "$PWD/backup:/b:ro" alpine \
    sh -c 'find /v -mindepth 1 -delete && tar xzf "/b/$1" -C /v' sh "$(basename "$f")"
done
```

Step 3 replaces everything those volumes hold now with the backup, so only run
it with the backup from step 1 next to you. The volumes keep their names and
labels, so the next backup finds them again.

## Good to know

A redeploy on its own never installs a newer version — the version only changes
when you change it. Always use a released version number; `latest` is rejected
before anything starts.
