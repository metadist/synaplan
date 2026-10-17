# Azure adapter

Everything needed to publish Synaplan as an Azure Marketplace virtual machine
offer and to run it on a single VM: the Packer build, the first-boot and operator
scripts, the systemd units, the Azure Backup hooks and the ARM template that
delivers all of it.

Like [the AWS adapter](../aws/README.md), this adapter **calls** the portable
scripts in [`deploy/scripts/`](../scripts) instead of reimplementing them. An
Azure VM therefore prepares, starts, backs up, updates and restores itself
exactly the way a self-hosted installation does, and cannot drift from it.
[`deploy/scripts/tests/test-lifecycle.sh`](../scripts/tests/test-lifecycle.sh)
enforces that for both adapters: no file here may invoke `docker compose`
itself, every script that reaches the stack must resolve the deployment secrets
first, and the systemd units must run the portable scripts.

## Layout

| Path | What it is |
| ---- | ---------- |
| `packer/synaplan.pkr.hcl` | Builds the image from Ubuntu 24.04 LTS into an Azure Compute Gallery. |
| `scripts/provision.sh` | Packer provisioner: runtime, Caddy, Azure CLI, application tree, pre-pulled images, systemd units. |
| `scripts/harden.sh` | Last provisioner before deprovisioning: the certification rules, applied and verified. |
| `scripts/firstboot.sh` | Per-instance setup on the first boot of a data disk. |
| `scripts/snapshot.sh` | Published as `synaplan-snapshot`. |
| `scripts/admin-password.sh` | Published as `synaplan-admin-password`. |
| `scripts/motd.sh` | Login banner pointing at the command above. |
| `backup/VMSnapshotScriptPluginConfig.json` | Makes Azure Backup quiesce the application before it snapshots. |
| `systemd/` | `synaplan-firstboot.service` and `synaplan.service`. |
| `arm/` | `mainTemplate.json`, `createUiDefinition.json` and the architecture diagram. |
| `scripts/tests/test-firstboot.sh` | Runs `firstboot.sh` for real, with Azure stubbed. |

Everything that is a property of the *host* rather than of Azure lives one level
up in [`deploy/host/`](../host) and is shared with AWS: the Caddyfiles,
`configure-tls.sh`, `update.sh`, `stop.sh`, `pull-images.sh` and
`snapshot-hook.sh` — the last one is invoked by the Azure Backup script framework
here and by the SSM document there, with the same two stage names.

## What the image contains, and what it does not

It contains the operating system, Docker with the Compose v2 plugin, Caddy, the
Azure CLI, the application tree in `/opt/synaplan`, the container images for
exactly one released version, and the systemd units.

It contains **no configuration and no secrets**. That is not a detail: one image
is deployed by every customer, so any value baked in would be shared by all of
them. `harden.sh` fails the build if a `.env` or a `secrets.env` survived.

Persistent state lives on a separate managed disk mounted at `/var/lib/synaplan`.
`/opt/synaplan/deploy/data`, `.lifecycle` and `.env` are symlinks into it, so the
paths `lib.sh` expects are unchanged while the VM stays replaceable.

## First boot

`synaplan-firstboot.service` runs `firstboot.sh` once per data disk, after
`walinuxagent.service` and before `synaplan.service` and `caddy.service`. It is
idempotent and never overwrites an existing configuration: a reboot, a
deallocate/start and a new VM attached to an old disk all re-run it, and an
installation that already exists is left exactly as its operator left it.

Two properties it must keep, because the Marketplace depends on them:

- **It works with no user data and no template parameters.** A customer who
  picks the image out of the Marketplace and clicks Create gets a bare VM, and
  that has to produce a working, reachable installation.
- **The generated administrator password is single-use.**
  `BOOTSTRAP_ADMIN_FORCE_PASSWORD_CHANGE=true` makes the application refuse every
  request except the password change until it is replaced.

What it writes into `deploy/.env`, beyond the portable defaults:

| Key | Value | Why |
| --- | ----- | --- |
| `SYNAPLAN_PLATFORM` | `azure` | Points the in-app update notice at [docs/UPDATE_AZURE.md](../../docs/UPDATE_AZURE.md). |
| `SYNAPLAN_PULL_POLICY` | `missing` | The images are already local; a normal start reaches nothing. |
| `SYNAPLAN_HTTP_BIND` | `127.0.0.1` | Caddy is the only way in, so TLS cannot be bypassed. |
| `REGISTRATION_ENABLED` | `false` | A VM with a public IP would otherwise let anyone who finds it create an account. |
| `AUTH_COOKIE_SECURE` | `true` | Everything is served over TLS, self-signed fallback included. |
| `STRIPE_*` | empty | Billing off. The Marketplace product must ship unrestricted; an operator can fill these in for **their own** users afterwards ([docs/BILLING_SELFHOST.md](../../docs/BILLING_SELFHOST.md)). |

### Where the administrator password ends up

Always in `/var/lib/synaplan/initial-admin-password`, mode 0600, which is what
`sudo synaplan-admin-password` reads and what the login banner points at. That
path works for every deployment, including the bare one.

When the deployment names a Key Vault — the ARM template creates one and sets the
`synaplan:key-vault` tag — the password is additionally written there as the
secret `synaplan-admin-password`, using the VM's managed identity against the
Key Vault REST API. No CLI, no credential on disk, and nothing the operator has
to sign in with. If the vault is unreachable or the identity is not allowed near
it, first boot logs a warning and continues: an installation that works but needs
a shell to reveal its password beats one that refuses to start.

## Optional settings, without a shell

Everything optional is read from two sources, because the two ways of deploying
the image can each supply only one: an ARM template writes a **user data**
document, and an operator deploying the bare image from the Marketplace sets **VM
tags**. User data wins.

User data is a base64-encoded list of `key=value` lines; a tag is
`synaplan:<key>`. Recognised keys: `domain`, `admin-email`, `ai-provider`,
`<provider>-api-key` (`groq`, `openai`, `anthropic`, `google-gemini`, `mistral`,
`xai`), `registration-enabled`, `mailer-dsn`, `sender-email`, `key-vault`,
`key-vault-secret`, `data-disk`, and `stripe-secret-key`,
`stripe-webhook-secret`, `stripe-price-pro`, `stripe-price-team`,
`stripe-price-business`.

An API key belongs in the user data document, never in a tag: tags are readable
by anyone who can read the VM resource, while user data is readable only from
inside the VM.

## TLS

Caddy terminates TLS **on the host**, in front of the application container,
which binds to `127.0.0.1:8000` only.

With a domain, Caddy obtains and renews a Let's Encrypt certificate — which needs
the A record to point at the VM and ports 80 and 443 reachable from the internet.
Without one, it serves a self-signed certificate on 443: the browser warns,
correctly, and plain HTTP for an administrator login is not an alternative.
Attach a domain later without recreating anything:

```bash
sudo synaplan-tls app.example.com admin@example.com
sudo systemctl restart synaplan
```

That also rewrites `APP_URL`, `FRONTEND_URL` and `REALTIME_ALLOWED_ORIGINS`, so
absolute links, the widget origin check and OAuth callbacks follow the new
address.

## Operator commands on the VM

| Command | What it does |
| ------- | ------------ |
| `sudo synaplan-snapshot` | An application-consistent backup: an Azure Backup job when the deployment has a vault, otherwise a quiesced managed-disk snapshot. |
| `sudo synaplan-update <version>` | Backup gate, version bump, start and verify — the portable update path. |
| `sudo synaplan-tls <domain> [email]` | Switches to a real certificate for that domain. |
| `sudo synaplan-admin-password` | Prints the initial administrator password. |
| `sudo synaplan-smoke-test` | The portable smoke test: `/api/health`, all three app roles, Redis, Qdrant, Tika, Centrifugo. |

Get a shell without an SSH key and without an open port 22:

```bash
az vm run-command invoke --resource-group <group> --name <vm> \
  --command-id RunShellScript --scripts 'sudo synaplan-admin-password'
```

## Deploying the ARM template

```bash
az group create --name synaplan --location westeurope
az deployment group create \
  --resource-group synaplan \
  --template-file deploy/azure/arm/mainTemplate.json \
  --parameters sshPublicKey="$(cat ~/.ssh/id_ed25519.pub)" \
               adminEmail=admin@example.com \
               imageResourceId=/subscriptions/.../versions/1.4.0
```

`imageResourceId` points at a Compute Gallery version and is what the release
workflow verifies against. Leave it empty and the template deploys the published
Marketplace image instead, described by `marketplacePublisher`, `marketplaceOffer`,
`marketplaceSku` and `marketplaceVersion`; the `plan` block is then filled in,
and omitted entirely for a gallery image, which must not carry one.

What the template creates and why is in
[`arm/architecture.md`](arm/architecture.md), which is also the diagram Partner
Center asks for. `createUiDefinition.json` is the portal form for the same
parameters; every one of its outputs is checked against the template's
parameters in CI, because a mismatch is only visible when a customer opens the
form.

## Building the image

```bash
az login
packer init  deploy/azure/packer/synaplan.pkr.hcl
packer build -var synaplan_version=1.4.0 deploy/azure/packer/synaplan.pkr.hcl
```

`synaplan_version` must be an immutable SemVer version. A mutable tag is rejected
by the variable validation here, and by `validate-release.sh` at boot: with
`pull_policy: always` in `compose.yaml`, a floating tag would let an unrelated
restart install a different application.

The result is a version in the Compute Gallery named by `gallery_name`, in the
image definition `synaplan-x64` or `synaplan-arm64`. Both definitions must exist
already and must be Gen2 and `TrustedLaunchSupported`, because the build VM is
created with secure boot and a vTPM and a captured image can only be published
against a definition that declares the same. Build both architectures: the
container images are multi-arch, so `-var architecture=arm64` yields the Ampere
Altra image.

A gallery version is plain `major.minor.patch`. A pre-release version has no
valid gallery version to publish under, so `image_version` drops the suffix — and
the release workflow refuses a pre-release tag outright.

## Testing it without Azure

`firstboot.sh` is the one script a customer cannot recover from by hand, and the
only place it otherwise runs is a published image. So it is executed for real,
with the instance metadata service, Key Vault and the data disk stubbed:

```bash
bash deploy/azure/scripts/tests/test-firstboot.sh
```

That covers the whole configuration path — a bare deployment with no metadata and
no identity, an ARM deployment with user data, tags and their precedence,
idempotence on a reboot, adoption of an existing disk, and a Key Vault the
identity may not write to. It also pins the generated password against
`validate_bootstrap_admin_values`, the preflight `prepare.sh` runs seconds later:
a generated value that fails it would turn every deployment of the published
image into a VM that refuses to start. CI runs it on every push, next to the
lifecycle contract tests.

What it cannot cover is what needs a kernel: formatting and mounting the data
disk, the systemd ordering, and Caddy's certificate handling. The full end-to-end
check is a real deployment, and it is automated — see below.

## The release pipeline

| Runs | Where | What it does |
| ---- | ----- | ------------ |
| Every push | `deployment-templates` job in [`ci.yml`](../../.github/workflows/ci.yml) | `packer fmt -check` and `packer validate`, the ARM templates parsed, and every `createUiDefinition.json` output checked against a template parameter. No Azure account, no cost. |
| Every release tag | [`azure-image.yml`](../../.github/workflows/azure-image.yml) | Waits for the release's container images, builds both images with Packer into the gallery, then deploys the x86_64 one through `arm/mainTemplate.json` and runs `synaplan-smoke-test` on it through Run Command. Deletes the resource group whatever the outcome. |
| Before submitting | [ARM-TTK](https://github.com/Azure/arm-ttk), by hand | The template test toolkit Partner Center runs against a solution template. Not a CI gate: a VM offer does not go through it, and it is Phase 2 that needs it green. |

`azure-image.yml` skips itself with a notice while the secret `AZURE_CLIENT_ID`
is unset, so a release stays green until the publishing tenant exists.

Authentication is GitHub OIDC against an app registration in that tenant —
**not** a client secret, of which this repository holds none. The app needs
Contributor on the gallery's resource group to build, and on a subscription or
resource group to run the verification deployment. Store `AZURE_CLIENT_ID`,
`AZURE_TENANT_ID` and `AZURE_SUBSCRIPTION_ID` as repository secrets.

The image bakes the version pinned in `packer/synaplan.pkr.hcl`, which
[`scripts/set-release-version.mjs`](../../scripts/set-release-version.mjs) raises
in the same pull request as the AWS, Elestio and Umbrel pins, so every catalog
names one release. `tests/set-release-version.test.mjs` fails if one of them
drifts.

## Backups

`sudo synaplan-snapshot` is the consistent path and has no time limit.

The ARM template also creates a Recovery Services vault with a daily policy, on
by default. Those backups are application-consistent too: the image ships
`/etc/azure/VMSnapshotScriptPluginConfig.json`, which makes the Azure Backup
extension run [`deploy/host/snapshot-hook.sh`](../host/snapshot-hook.sh) before
and after the snapshot — the same portable `pre-backup.sh` and `post-backup.sh`
the manual command uses. Unlike AWS's Data Lifecycle Manager, Azure allows the
pre-script 30 minutes, so this stays on rather than being an opt-in for small
installations.

## Updating

See [docs/UPDATE_AZURE.md](../../docs/UPDATE_AZURE.md). Synaplan never updates
itself; nothing changes until `synaplan-update` runs. An update replaces
container images and never touches the data disk.

## Publishing the listing

[docs/AZURE_MARKETPLACE_LISTING.md](../../docs/AZURE_MARKETPLACE_LISTING.md)
holds the account prerequisites, the gallery access Partner Center needs, the
submission sequence and the Usage Instructions text as it goes on the listing.

## External dependencies to disclose

Azure certification requires every outbound dependency to be named:

- The **AI provider** you configure. Without a key, Synaplan cannot answer
  anything. The key is yours and is billed by that provider.
- **Let's Encrypt**, only with a domain configured.
- **ghcr.io**, only while `synaplan-update` runs.
- **raw.githubusercontent.com**, for the update manifest behind the admin UI's
  "a newer version exists" notice — a static file, fetched with no VM
  identifier.
