> **[Main README](../README.md)** · **[Documentation Index](README.md)** · **Track 1: Getting Started & Deployment (8/8)**  
> **← Previous:** [1.7 Updating on Elestio](UPDATE_ELESTIO.md) · **Next:** [2.1 Day-2 Operations & Governance →](ADMIN.md)

# Update on AWS (Marketplace AMI)

> **In this guide:** How to connect to a Synaplan AWS Marketplace EC2 instance via Session Manager, snapshot the EBS data volume, and upgrade or roll back with `sudo synaplan-update`.

Synaplan never updates itself automatically. Nothing changes on your instance until you run the steps below.

The AMI keeps the application scripts in `/opt/synaplan` and all persistent state—database, uploads, vectors, secrets, and `deploy/.env`—on a dedicated EBS data volume mounted at `/var/lib/synaplan`. An update replaces container images and never overwrites that volume.

---

## 1. Connect to the Instance

The AMI has no password login and no open SSH port by default. Connect with AWS Systems Manager Session Manager, which requires no SSH key pair and no inbound security-group rule:

```bash
aws ssm start-session --target i-0123456789abcdef0
```

In the AWS Console, the same shell is available under **EC2 → Instances → Connect → Session Manager**.

---

## 2. Which Version to Install

Synaplan shows its current version in the sidebar. When a newer release exists, the admin area displays the new version number next to the current one with a link to its release notes. Read the release notes first, then use that version number below.

---

## 3. Update Procedure

1. **Snapshot the EBS data volume.** This is your recovery point.

```bash
sudo synaplan-snapshot
```

The command pauses database writers, triggers an EBS snapshot of the data volume, and resumes the stack (the app is briefly unavailable). It prints the snapshot ID. Wait until the snapshot reaches `completed` before continuing:

```bash
aws ec2 describe-snapshots --snapshot-ids snap-0123456789abcdef0 \
  --query 'Snapshots[0].State'
```

2. **Install the new version** (without a leading `v`):

```bash
sudo synaplan-update 1.4.0
```

`synaplan-update` runs the full production lifecycle: it backs up the database, writes `SYNAPLAN_VERSION` into `/var/lib/synaplan/.env`, pulls the pinned image, starts the stack (database migrations run automatically on boot), waits for every service to pass its health check, and prints the running version. If validation fails, it aborts before starting the new version and tells you what to fix.

---

## 4. Roll Back

```bash
sudo synaplan-update 1.3.0
```

If the new version already applied database migrations, also restore the EBS snapshot from step 1: create a volume from the snapshot, attach it in place of the current data volume, and reboot. Detailed steps: [Backup and restore](../deploy/README.md#backup-and-restore).

---

## Good to Know

- **Reboots never change the version:** A reboot or EC2 stop/start keeps the version pinned in `/var/lib/synaplan/.env`. `latest` is rejected before anything starts.
- **Keep the instance when updating:** Launching a new EC2 instance from a newer AMI is *not* an update path on its own—an attached data volume carries over its existing `/var/lib/synaplan/.env` version pin. Always update in place with `sudo synaplan-update`.
- **Daily snapshots vs. pre-update snapshots:** Even if you enabled the automatic daily snapshot policy in the CloudFormation template, always run `sudo synaplan-snapshot` in step 1 so you have a recovery point from immediately before the upgrade.

---

> **[Main README](../README.md)** · **[Documentation Index](README.md)** · **Track 1: Getting Started & Deployment (8/8)**  
> **← Previous:** [1.7 Updating on Elestio](UPDATE_ELESTIO.md) · **Next:** [2.1 Day-2 Operations & Governance →](ADMIN.md)
