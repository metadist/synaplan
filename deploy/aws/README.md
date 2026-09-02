# AWS adapter

Everything needed to publish Synaplan as an AWS Marketplace AMI product and to
run it on a single EC2 instance: the Packer build, the first-boot and operator
scripts, the systemd units, the host TLS terminator, the CloudFormation
templates and the SSM document for consistent snapshots.

Like [the Elestio adapter](../elestio/README.md), and unlike the standalone
Umbrel package, this adapter **calls** the portable scripts in
[`deploy/scripts/`](../scripts) instead of reimplementing them. An AWS instance
therefore prepares, starts, backs up, updates and restores itself exactly the way
a self-hosted installation does, and cannot drift from it.
[`deploy/scripts/tests/test-lifecycle.sh`](../scripts/tests/test-lifecycle.sh)
enforces that: no file here may invoke `docker compose` itself, every script that
reaches the stack must resolve the deployment secrets first, and the systemd
units must run the portable scripts.

## Layout

| Path | What it is |
| ---- | ---------- |
| `packer/synaplan.pkr.hcl` | Builds the AMI from Amazon Linux 2023. |
| `scripts/provision.sh` | Packer provisioner: runtime, Caddy, application tree, pre-pulled images, systemd units. |
| `scripts/pull-images.sh` | Bakes one release's container images into the image cache. |
| `scripts/harden.sh` | Last provisioner: the Marketplace scanner's rules, applied and verified. |
| `scripts/firstboot.sh` | Per-instance setup on the first boot of a data volume. |
| `scripts/configure-tls.sh` | Picks the certificate strategy; also published as `synaplan-tls`. |
| `scripts/update.sh` | Published as `synaplan-update`. |
| `scripts/snapshot.sh` | Published as `synaplan-snapshot`. |
| `scripts/snapshot-hook.sh` | The instance side of the SSM snapshot document. |
| `scripts/stop.sh` | `ExecStop` for the service unit. |
| `systemd/` | `synaplan-firstboot.service` and `synaplan.service`. |
| `caddy/` | Caddyfile for a real certificate, and for the self-signed fallback. |
| `cloudformation/` | The two delivery templates, the required architecture diagram, and the seller-account IAM roles. |
| `ssm/` | The snapshot document, for registering it outside CloudFormation. |
| `scripts/tests/test-firstboot.sh` | Runs `firstboot.sh` for real, with AWS stubbed. |
| `.taskcat.yml` | Multi-region launch test of both templates, run by hand before a public listing. |

## What the AMI contains, and what it does not

It contains the operating system, Docker with the Compose v2 plugin, Caddy, the
application tree in `/opt/synaplan`, the container images for exactly one
released version, and the systemd units.

It contains **no configuration and no secrets**. That is not a detail: one AMI is
launched by every customer, so any value baked in would be shared by all of them.
`harden.sh` fails the build if a `.env` or a `secrets.env` survived.

Persistent state lives on a separate EBS volume mounted at `/var/lib/synaplan`.
`/opt/synaplan/deploy/data`, `.lifecycle` and `.env` are symlinks into it, so the
paths `lib.sh` expects are unchanged while the instance stays replaceable.

## First boot

`synaplan-firstboot.service` runs `firstboot.sh` once per data volume, before
`synaplan.service` and `caddy.service`. It is idempotent and never overwrites an
existing configuration: a reboot, a stop/start and a new instance attached to an
old volume all re-run it, and an installation that already exists is left exactly
as its operator left it.

Two properties it must keep, because the Marketplace depends on them:

- **It works with no user data and no stack parameters.** "Launch from Website"
  starts the AMI with nothing but an instance profile, and that has to produce a
  working, reachable installation.
- **The generated administrator password is single-use.** It is published as an
  SSM `SecureString` at `/synaplan/<instance-id>/admin-password`, and
  `BOOTSTRAP_ADMIN_FORCE_PASSWORD_CHANGE=true` makes the application refuse every
  request except the password change until it is replaced. AWS requires exactly
  this for any image that invents a password.

What it writes into `deploy/.env`, beyond the portable defaults:

| Key | Value | Why |
| --- | ----- | --- |
| `SYNAPLAN_PLATFORM` | `aws` | Points the in-app update notice at [docs/UPDATE_AWS.md](../../docs/UPDATE_AWS.md). |
| `SYNAPLAN_PULL_POLICY` | `missing` | The images are already local; a normal start reaches nothing. |
| `SYNAPLAN_HTTP_BIND` | `127.0.0.1` | Caddy is the only way in, so TLS cannot be bypassed. |
| `REGISTRATION_ENABLED` | `false` | An instance with a public IP would otherwise let anyone who finds it create an account. |
| `AUTH_COOKIE_SECURE` | `true` | Everything is served over TLS, self-signed fallback included. |
| `STRIPE_*` | empty | Billing off. The Marketplace product must ship unrestricted; an operator can fill these in for **their own** users afterwards ([docs/BILLING_SELFHOST.md](../../docs/BILLING_SELFHOST.md)). |

## Optional settings, without a shell

Everything optional is read from two sources, because the two ways of launching
the AMI can each supply only one: a CloudFormation stack sets **instance tags**,
and an operator changing something afterwards writes an **SSM parameter** at
`/synaplan/<instance-id>/config/<key>` and reboots. The tag wins.

Recognised keys: `domain`, `admin-email`, `ai-provider`, `<provider>-api-key`
(`groq`, `openai`, `anthropic`, `google-gemini`, `mistral`, `xai`),
`registration-enabled`, `mailer-dsn`, `sender-email`, and `stripe-secret-key`,
`stripe-webhook-secret`, `stripe-price-pro`, `stripe-price-team`,
`stripe-price-business`.

API keys belong in the parameter store, never in a tag: tags are readable by
anyone who can describe the instance. The CloudFormation templates therefore
take no API key at all and print the `aws ssm put-parameter` command instead.

## Amazon Bedrock, prepared but not yet used

Synaplan has no Bedrock provider yet; it is planned as its own piece of work.
What the templates already carry is the permission side, behind
`GrantBedrockAccess`, off by default: with it on, the instance role may call
`bedrock:InvokeModel` and `bedrock:InvokeModelWithResponseStream` (which is also
what the Converse API checks) on foundation models and inference profiles, and
list them. Nothing else — it cannot enable a model, change a guardrail or read a
job.

The point of granting it before the provider exists is that the grant lives in
CloudFormation while the application is updated with `synaplan-update`. An
instance launched today with the switch on will be able to use Bedrock the day
the provider ships, without its operator having to update the stack. The launch
also tags the instance `synaplan:bedrock=granted`, so that release can detect
the grant from the instance itself.

Why it matters for the listing: an AI provider key is currently a disclosed
external dependency, and without one Synaplan cannot answer anything. Bedrock
through the instance role removes that dependency entirely — the buyer launches
the AMI and the product works, billed on their own AWS account.

## TLS

Caddy terminates TLS **on the host**, in front of the application container,
which binds to `127.0.0.1:8000` only.

With a domain, Caddy obtains and renews a Let's Encrypt certificate — which needs
the A record to point at the instance and ports 80 and 443 reachable from the
internet. Without one, it serves a self-signed certificate on 443: the browser
warns, correctly, and plain HTTP for an administrator login is not an
alternative. Attach a domain later without recreating anything:

```bash
sudo synaplan-tls app.example.com admin@example.com
sudo systemctl restart synaplan
```

That also rewrites `APP_URL`, `FRONTEND_URL` and `REALTIME_ALLOWED_ORIGINS`, so
absolute links, the widget origin check and OAuth callbacks follow the new
address.

## Operator commands on the instance

| Command | What it does |
| ------- | ------------ |
| `sudo synaplan-snapshot` | Quiesces the application, takes an EBS snapshot of the data volume, resumes. |
| `sudo synaplan-update <version>` | Backup gate, version bump, start and verify — the portable update path. |
| `sudo synaplan-tls <domain> [email]` | Switches to a real certificate for that domain. |
| `sudo synaplan-smoke-test` | The portable smoke test: `/api/health`, all three app roles, Redis, Qdrant, Tika, Centrifugo. |

Get a shell without an SSH key and without an open port 22:

```bash
aws ssm start-session --target <instance-id>
```

## Building the AMI

```bash
packer init  deploy/aws/packer/synaplan.pkr.hcl
packer build -var synaplan_version=1.4.0 deploy/aws/packer/synaplan.pkr.hcl
```

`synaplan_version` must be an immutable SemVer version. A mutable tag is rejected
by the variable validation here, and by `validate-release.sh` at boot: with
`pull_policy: always` in `compose.yaml`, a floating tag would let an unrelated
restart install a different application.

The Marketplace ingests from **us-east-1**, and the source AMI must be
unencrypted, EBS-backed and HVM. The workflow rejects account-level EBS
encryption before the build, verifies every resulting snapshot, and shares the
AMI with Marketplace ingestion account `679593333241`. Marketplace encrypts its
copy, and the CloudFormation templates independently encrypt every buyer volume.
Other regions are produced by copying the AMI. The container images are
multi-arch, so `-var architecture=arm64` yields a Graviton image for testing —
AWS Marketplace ties one AMI product to one CPU architecture, so only x86_64 is
ever offered to the listing; see
[Why x86_64 only](../../docs/AWS_MARKETPLACE_LISTING.md#why-x86_64-only).

## Testing it without AWS

`firstboot.sh` is the one script a customer cannot recover from by hand, and the
only place it otherwise runs is a published AMI. So it is executed for real,
with the instance metadata service, the AWS CLI and the data volume stubbed:

```bash
bash deploy/aws/scripts/tests/test-firstboot.sh
```

That covers the whole configuration path — the bare "Launch from Website" with
no metadata and no instance role, a CloudFormation launch with tags, the
parameter store and its precedence, idempotence on a reboot, adoption of a
restored volume, and an instance role that may not write the password. It also
pins the generated password against `validate_bootstrap_admin_values`, the
preflight `prepare.sh` runs seconds later: a generated value that fails it would
turn every launch of the published AMI into an instance that refuses to start.
CI runs it on every push, next to the lifecycle contract tests.

What it cannot cover is what needs a kernel: formatting and mounting the data
volume, the systemd ordering, and Caddy's certificate handling. Those need a
virtual machine — Multipass or UTM with Amazon Linux 2023 — where the tree is
copied to `/opt/synaplan`, the units are installed, and the metadata service is
faked with a local listener on `169.254.169.254`. A second boot of that VM with
the disk still attached is the fastest way to confirm the idempotence the
stubbed suite asserts on a directory.

The full end-to-end check is a real launch, and it is automated: see the release
pipeline below.

## The release pipeline

| Runs | Where | What it does |
| ---- | ----- | ------------ |
| Every push | `deployment-templates` job in [`ci.yml`](../../.github/workflows/ci.yml) | `cfn-lint` on both templates, `packer fmt -check` and `packer validate`. No AWS account, no cost. |
| Every release tag | [`aws-ami.yml`](../../.github/workflows/aws-ami.yml) | Waits for the release's container images, builds an unencrypted x86_64 source AMI with Packer (arm64 is available by hand, for testing a Graviton image outside Marketplace — AWS ties a Marketplace AMI product to one CPU architecture), verifies and shares it with the AWS Marketplace ingestion account, then launches it through `synaplan-new-vpc.yaml` and runs `synaplan-smoke-test` on it over Session Manager. Marks it as `SmokeTested`, which is what [`marketplace-versions.yml`](../../.github/workflows/marketplace-versions.yml) offers to the Marketplace listing. Deletes the verification stack and the snapshot it leaves behind, whatever the outcome. |
| Nightly | [`aws-cleanup.yml`](../../.github/workflows/aws-cleanup.yml) | Deregisters expired AMIs and deletes their snapshots, and terminates Packer builders a cancelled run abandoned. Never touches an image a published listing version launches from, the two newest releases, or anything this pipeline did not tag. Dispatch it with **Only report what would be deleted** to see its reasoning first. |
| Before a public listing | `taskcat` with [`.taskcat.yml`](.taskcat.yml) | Launches both templates in two or three regions. By hand, per release. |

`aws-ami.yml` skips itself with a notice while the secret
`AWS_AMI_BUILD_ROLE_ARN` is unset, so a release stays green until the seller
account exists.

That secret is the ARN of a role in the seller account that trusts GitHub's OIDC
provider — **not** an access key, of which this repository holds none. Its trust
policy is narrowed to this repository's `ami-build` environment, which the
workflow's jobs declare — so the workflow can also be dispatched by hand from a
pull request branch to build and verify a deployment fix *before* it is merged,
while forks stay excluded. The role needs the permissions Packer uses to build
an AMI (EC2 instance, key pair, security group, snapshot, image), plus
CloudFormation, IAM, SSM and EC2 for the verification stack. The AWS managed
policy `AWSMarketplaceAmiIngestion` goes on a separate role, the one the
Marketplace assumes to read the finished AMI.

The AMI bakes the version pinned in `packer/synaplan.pkr.hcl`, which
[`scripts/set-release-version.mjs`](../../scripts/set-release-version.mjs) raises
in the same pull request as the Elestio and Umbrel pins, so every catalog names
one release. `tests/set-release-version.test.mjs` fails if one of them drifts.

## Backups

`sudo synaplan-snapshot` is the consistent path and has no time limit. The
CloudFormation templates also create a Data Lifecycle Manager policy for daily
snapshots; those are crash-consistent by default.

`QuiesceForDailySnapshots` wires the SSM document in front of the daily snapshot
so it quiesces too. It is off by default because DLM allows a pre-script only 120
seconds — past that it snapshots mid-write anyway and can leave the application
paused. Turn it on only for an installation whose dump comfortably fits.

## Updating

See [docs/UPDATE_AWS.md](../../docs/UPDATE_AWS.md). Synaplan never updates
itself; nothing changes until `synaplan-update` runs. An update replaces
container images and never touches the data volume.

## Publishing the listing

[docs/AWS_MARKETPLACE_LISTING.md](../../docs/AWS_MARKETPLACE_LISTING.md) holds
the account prerequisites, the two IAM roles, the submission sequence and the
Usage Instructions text as it goes on the listing.

## External dependencies to disclose

AWS Marketplace requires every outbound dependency to be named in the Usage
Instructions:

- The **AI provider** you configure. Without a key, Synaplan cannot answer
  anything. The key is yours and is billed by that provider.
- **Let's Encrypt**, only with a domain configured.
- **ghcr.io**, only while `synaplan-update` runs.
- **raw.githubusercontent.com**, for the update manifest behind the admin UI's
  "a newer version exists" notice — a static file, fetched with no instance
  identifier.
