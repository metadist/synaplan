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

## Good to know

A redeploy on its own never installs a newer version — the version only changes
when you change it. Always use a released version number; `latest` is rejected
before anything starts.
