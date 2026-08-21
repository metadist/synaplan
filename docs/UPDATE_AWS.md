# Update on AWS (Marketplace AMI)

Synaplan never updates itself. Nothing changes until you run the steps below.

The instance keeps the application in `/opt/synaplan` and all persistent state —
database, uploads, vectors, secrets, and `deploy/.env` — on a separate EBS data
volume mounted at `/var/lib/synaplan`. An update replaces container images; it
never touches that volume.

## Connect to the instance

The AMI has no password login and no open SSH port by default. Use Session
Manager, which needs no key pair and no inbound rule:

```bash
aws ssm start-session --target i-0123456789abcdef0
```

In the AWS console the same session is behind **EC2 → Instances → Connect →
Session Manager**.

## Which version to install

Synaplan shows its current version in the sidebar. When a newer release exists,
the admin area shows that new version number next to the current one, together
with a link to its release notes. Read the release notes, then use that version
number below.

## Update

1. **Snapshot the data volume.** This is your only recovery point.

```bash
sudo synaplan-snapshot
```

The command quiesces the database, takes an EBS snapshot of the data volume,
and resumes — the app is briefly unavailable. It prints the snapshot ID. Wait
until the snapshot reaches `completed` before continuing:

```bash
aws ec2 describe-snapshots --snapshot-ids snap-0123456789abcdef0 \
  --query 'Snapshots[0].State'
```

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

If the new version already changed the database, also restore the snapshot from
step 1: create a volume from it, attach it in place of the current data volume,
and reboot. Detailed steps: [Backup and restore](../deploy/README.md#backup-and-restore).

## Good to know

A reboot or a stop/start never installs a newer version — the version only
changes when you change it. Always use a released version number; `latest` is
rejected before anything starts.

Replacing the instance from a newer AMI is **not** an update path on its own:
the new instance boots whatever version its `deploy/.env` names, and that file
lives on the data volume you carry over. Update with `synaplan-update` and keep
the instance.

If you enabled the automatic snapshot policy in the CloudFormation template, you
already have daily snapshots. Step 1 is still worth doing: it gives you a
recovery point from immediately before this specific change.
