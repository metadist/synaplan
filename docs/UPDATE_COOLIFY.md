> **[Main README](../README.md)** · **[Documentation Index](README.md)** · **Track 1: Getting Started & Deployment (9/9)**  
> **← Previous:** [1.8 Updating on AWS Marketplace](UPDATE_AWS.md) · **Next:** [2.1 Day-2 Operations & Governance →](ADMIN.md)

# Update on Coolify

> **In this guide:** How to back up a Synaplan resource on Coolify, set `SYNAPLAN_VERSION` to the new release, restart it, and go back if you need to.

Synaplan never updates itself automatically. Nothing changes on your Coolify resource until you run the steps below.

The resource was created from [`deploy/coolify/synaplan.yaml`](../deploy/coolify/synaplan.yaml). Its three app roles (web, worker, scheduler) run `ghcr.io/metadist/synaplan:${SYNAPLAN_VERSION}`, so the version only changes when you change that variable.

---

## Which Version to Install

Synaplan shows its current version in the sidebar. When a newer release exists, the admin area shows that new version number next to the current one, together with a link to its release notes. Read the release notes, then use that version number in step 2.

---

## Update Procedure

1. **Back up.** On the resource's **Backups** page, run the database backup and both storage backups (`Backend: synaplan-uploads`, `Qdrant: synaplan-qdrant`) and wait until all three show **Success**. If they are not set up yet, add them first as listed in [Backup](../deploy/coolify/README.md#backup), which also says what each one holds.
2. **Set the new version.** Under **Environment Variables**, change `SYNAPLAN_VERSION` to the version shown in Synaplan, without a leading `v` (for example `5.9.0`, not `v5.9.0`).
3. **Click Restart current version.** Coolify pulls the new image and recreates the containers.
4. **Verify.** The web role applies the database migrations when it starts. Expect about half a minute in which the address does not answer. Then sign in: accounts, files and settings are as they were, and the sidebar shows the new version.

> **Step 1 is your only recovery point.** Coolify does not take a backup on its own before a restart, and database migrations are not reversed.

---

## Roll Back

Restore the backup from step 1 ([Restore](../deploy/coolify/README.md#restore): database first, then the two volumes with the resource stopped), set `SYNAPLAN_VERSION` back to the previous version, and start the resource.

Setting the old version alone is not enough once the new version has migrated the database.

---

## Good to Know

- **Pinned versions only:** A redeploy or restart on its own never installs a newer version. Always use a released version number, not `latest`.
- **Change the domain, not `APP_URL`:** Coolify writes the URL variables from the resource's domain on every start, so an edited `APP_URL` is overwritten. To move Synaplan to another address, change the domain.
- **Keep the secrets with the backups:** a database backup without `SERVICE_PASSWORD_64_APPSECRET` cannot decrypt the stored provider keys. See [Secrets](../deploy/coolify/README.md#secrets).
- **The update notice links here** because the file sets `SYNAPLAN_PLATFORM: coolify`. Leave that variable as it is.

---

> **[Main README](../README.md)** · **[Documentation Index](README.md)** · **Track 1: Getting Started & Deployment (9/9)**  
> **← Previous:** [1.8 Updating on AWS Marketplace](UPDATE_AWS.md) · **Next:** [2.1 Day-2 Operations & Governance →](ADMIN.md)
