# Update on Azure (Marketplace VM)

Synaplan never updates itself. Nothing changes until you run the steps below.

The VM keeps the application in `/opt/synaplan` and all persistent state —
database, uploads, vectors, secrets, and `deploy/.env` — on a separate managed
data disk mounted at `/var/lib/synaplan`. An update replaces container images; it
never touches that disk.

## Connect to the VM

The image has no password login and no open SSH port by default. Run Command
needs neither a key pair nor an inbound rule:

```bash
az vm run-command invoke \
  --resource-group synaplan --name synaplan \
  --command-id RunShellScript \
  --scripts 'sudo synaplan-update 1.4.0'
```

In the Azure portal the same thing is behind **Virtual machine → Operations →
Run command → RunShellScript**.

If you deployed with an SSH key, `ssh <admin>@<public-ip>` works too — provided
the network security group allows port 22 from your address, which it does only
if you asked for it at deployment time.

## Which version to install

Synaplan shows its current version in the sidebar. When a newer release exists,
the admin area shows that new version number next to the current one, together
with a link to its release notes. Read the release notes, then use that version
number below.

## Update

1. **Back up the data disk.** This is your only recovery point.

```bash
sudo synaplan-snapshot
```

With a Recovery Services vault — the ARM template creates one unless you turned
it off — this starts an Azure Backup job that quiesces the database, snapshots
the disks and resumes; the app is briefly unavailable. Follow the job:

```bash
az backup job list --resource-group synaplan --vault-name synaplan-backup --output table
```

Without a vault, the same command takes a quiesced managed-disk snapshot instead
and prints its name. Either way, wait until it has finished before continuing.

2. **Install the new version**, without a leading `v`:

```bash
sudo synaplan-update 1.4.0
```

That command runs the same lifecycle as a self-hosted install: it backs up the
database, writes `SYNAPLAN_VERSION` into `/var/lib/synaplan/.env`, pulls the
image, starts it — database migrations run automatically on start — and then
waits for every service to become healthy and prints the running version.

If it fails, it stops before starting the new version and tells you what to fix.

## Roll back

```bash
sudo synaplan-update 1.3.0
```

If the new version already changed the database, also restore the backup from
step 1. Azure Backup restores a disk from a recovery point
(`az backup restore restore-disks`); a plain snapshot becomes a disk with
`az disk create --source <snapshot>`. Either way, attach the result at LUN 0 in
place of the current data disk and restart the VM. Detailed steps: [Backup and
restore](../deploy/README.md#backup-and-restore).

## Good to know

A reboot or a deallocate/start never installs a newer version — the version only
changes when you change it. Always use a released version number; `latest` is
rejected before anything starts.

Replacing the VM from a newer image is **not** an update path on its own: the new
VM boots whatever version its `deploy/.env` names, and that file lives on the
data disk you carry over. Update with `synaplan-update` and keep the VM.

Daily backups from the Recovery Services vault do not make step 1 redundant: it
gives you a recovery point from immediately before this specific change.
