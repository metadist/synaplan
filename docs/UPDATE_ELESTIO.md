# Update on Elestio

Synaplan never updates itself. Nothing changes until you run the steps below.

## Which version to install

Synaplan shows its current version in the sidebar. When a newer release exists,
the admin area shows that new version number next to the current one, together
with a link to its release notes. Read the release notes, then use that version
number in step 2.

## Update

1. **Create a backup.** In Elestio, open your service, go to **Backups**, and
   start a manual backup. Wait until it has finished.
2. **Set the new version.** Open your CI/CD pipeline's environment variables and
   change `SYNAPLAN_VERSION` to the version shown in Synaplan, without a leading
   `v` — for example `1.4.0`, not `v1.4.0`.
3. **Trigger a redeploy** of the pipeline.
4. **Done.** Elestio downloads and checks the new image, the database migrations
   run automatically when the app starts, and every service is health-checked.
   The app is back within a few minutes.

> **Step 1 is not optional.** A redeploy caused by an environment change runs
> Elestio's deploy path, which does **not** include the automatic backup that
> Elestio's own update operation creates. Your manual backup is the only
> recovery point.

## Roll back

Set `SYNAPLAN_VERSION` back to the previous version and redeploy. If the new
version already changed the database, also restore the backup from step 1.

## Good to know

A redeploy on its own never installs a newer version — the version only changes
when you change it. Always use a released version number; `latest` is rejected
before anything starts.

Your pipeline may restart by itself when the Synaplan repository receives a new
commit. That is a restart, not an update: it reuses the version and the settings
your pipeline already has.

This includes the commit that raises the version for **new** installations. Every
release, an automation updates the version that the one-click template installs,
so someone deploying Synaplan tomorrow gets the current release without asking.
Your pipeline is not touched by it: Elestio copied the environment variables into
your pipeline when it was created and reads them from there ever after, so
`SYNAPLAN_VERSION` stays exactly what you set. Updating is still the four steps
above, and still yours to start.
