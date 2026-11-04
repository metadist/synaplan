> **[Main README](../README.md)** · **[Documentation Index](README.md)** · **Track 1: Getting Started & Deployment (7/8)**  
> **← Previous:** [1.6 Updating a Self-Hosted Instance](UPDATE_SELFHOST.md) · **Next:** [1.8 Updating on AWS Marketplace →](UPDATE_AWS.md)

# Update on Elestio

> **In this guide:** How to take a manual recovery backup, bump `SYNAPLAN_VERSION`, and redeploy a Synaplan custom CI/CD pipeline on Elestio.

Synaplan never updates itself automatically. Nothing changes on your Elestio service until you run the steps below.

---

## Which Version to Install

Synaplan shows its current version in the sidebar. When a newer release exists, the admin area shows that new version number next to the current one, together with a link to its release notes. Read the release notes, then use that version number in step 2.

---

## Update Procedure

1. **Create a manual backup.** In Elestio, open your service, go to **Backups**, and start a manual backup. Wait until it has finished.
2. **Set the new version.** Open your CI/CD pipeline's environment variables and change `SYNAPLAN_VERSION` to the version shown in Synaplan, without a leading `v` (for example `1.4.0`, not `v1.4.0`).
3. **Trigger a redeploy** of the pipeline.
4. **Verify.** Elestio downloads and validates the new image, database migrations run automatically when the app starts, and every service is health-checked. The app is back within a few minutes.

> **Step 1 is mandatory.** A redeploy triggered by an environment variable change runs Elestio's deploy path, which does **not** trigger the automatic backup that Elestio's OS/system update operation creates. Your manual backup in step 1 is your only recovery point.

---

## Roll Back

Set `SYNAPLAN_VERSION` back to the previous version and redeploy. If the new version already migrated the database, also restore the manual backup from step 1.

---

## Good to Know

- **Pinned versions only:** A redeploy on its own never installs a newer version—the version only changes when you change `SYNAPLAN_VERSION`. Always use a released version number; `latest` is rejected before anything starts.
- **Repository commits do not upgrade your instance:** Your pipeline may restart when the Synaplan repository receives a new commit. That is a restart, not an upgrade—it reuses the `SYNAPLAN_VERSION` and settings your pipeline already has.
- **Template updates only affect new installs:** On each release, an automation raises the default version in the repo for *new* installations. Because Elestio copied the environment variables into your pipeline when it was first created, your existing `SYNAPLAN_VERSION` remains untouched until you change it.

---

> **[Main README](../README.md)** · **[Documentation Index](README.md)** · **Track 1: Getting Started & Deployment (7/8)**  
> **← Previous:** [1.6 Updating a Self-Hosted Instance](UPDATE_SELFHOST.md) · **Next:** [1.8 Updating on AWS Marketplace →](UPDATE_AWS.md)
