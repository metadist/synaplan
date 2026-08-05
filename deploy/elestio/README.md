# Elestio adapter

The root `elestio.yml` follows Elestio's documented Docker Compose template
schema. These files only translate lifecycle events to the portable scripts in
`deploy/scripts/`.

## Import and first deployment

1. Create a custom Docker Compose CI/CD pipeline from this repository and branch.
2. Replace `REPLACE_WITH_PUBLISHED_COMPATIBLE_VERSION` with an immutable SemVer
   tag that already exists in GHCR and passes `deploy/scripts/validate-release.sh`.
3. Keep `COMPOSE_PROFILES` empty for the initial Cloud-AI installation.
4. Confirm that HTTPS 443 proxies to host port 8000 without Basic Auth.
5. Open the Synaplan web UI shortcut and sign in with the generated bootstrap
   administrator.
6. Configure an AI provider in Admin > AI Providers and configure SMTP before
   enabling email-dependent features.

Elestio documents `random_password`, `[EMAIL]`, and `[CI_CD_DOMAIN]`
substitution. Treat every generated password as a persistent pipeline secret:
do not regenerate it on redeploy, and include the environment configuration in
your external disaster-recovery records. The administrator credentials are the
critical case: see
[First administrator credentials](#first-administrator-credentials).

The public template documentation does not define a separate schema property
that marks an environment variable as editable. `COMPOSE_PROFILES` is therefore
declared as a normal environment value. Change it to `local-ai` in Elestio's
pipeline environment editor and redeploy. Confirm the exact dashboard editing
and secret-retention behavior with Elestio before catalog submission.

The documentation also does not publish catalog-only metadata fields or asset
requirements. No undocumented fields are included. Icon, screenshot, benchmark,
minimum-size, and marketplace metadata must be added only after Elestio confirms
their current catalog contract.

## First administrator credentials

Elestio replaces `[EMAIL]` with your Elestio account address and
`random_password` with a generated value, then shows both as the login for the
Synaplan shortcut. Store them in a password manager during the first deployment.

> **After a redeploy, the password Elestio displays no longer works.** Elestio
> generates a new value and shows it, while Synaplan keeps the administrator that
> was created on the very first start — the bootstrap never resets an existing
> administrator, deliberately, because otherwise a deployment variable would be a
> permanent password-reset backdoor. Nothing is broken. Sign in with the
> **original** credentials from the first deployment, which is why they belong in
> a password manager. If they are lost, recover as described in
> [Lost Administrator Password](../../docs/ADMIN.md#lost-administrator-password):
> a password reset by email once SMTP is configured, otherwise through the
> database.

If your Elestio account address is not a valid address for Synaplan, the
deployment stops before the stack starts and names the variable; nothing
crash-loops. Very few real addresses are affected — the part before the `@` may
not be longer than 64 characters, and no part of the domain name longer than 63. Set `BOOTSTRAP_ADMIN_EMAIL` to a different valid address in Elestio's
pipeline environment editor and deploy again, or clear both bootstrap variables,
sign up in the app, and make that account the administrator afterwards. The rules
are in
[Create the First Administrator](../../docs/INSTALLATION.md#create-the-first-administrator).

Generated passwords satisfy those rules with room to spare. In 60 samples from
Elestio's documented generator endpoint (`/api/auth/passwordgenerator`) every
value was exactly 21 characters long, and the characters observed across all
samples were exactly `A-Z`, `a-z`, `0-9` and `-`; Elestio's own Terraform modules
state the same rule verbatim ("The password can only contain alphanumeric
characters or hyphens `-`"). Synaplan waives the uppercase/lowercase/digit
requirement from 16 characters upwards, so a 21-character value is accepted even
when it contains no digit at all. **Assumption, not verified:** nothing documents
that the `random_password` placeholder in `elestio.yml` comes from that same
endpoint. It is the same platform with the same stated policy, which makes it a
strong inference — but if Elestio ever generated shorter passwords, a generated
value missing one character class would be refused. Re-check this if a fresh
deployment is ever rejected because of the password.

## Operations

Elestio lifecycle hooks call the portable backup, restore, update, and smoke
logic. The platform backup must include the repository's `deploy/data/`
directory. Run a restore into an isolated pipeline and verify login, uploads,
Qdrant search, worker processing, scheduler cleanup, and WebSocket delivery.

For upgrades, follow [Update on Elestio](../../docs/UPDATE_ELESTIO.md). Never use
`latest`: `validate_release_pin` accepts immutable SemVer tags only.
`preUpdateCommand` maps to `deploy/scripts/pre-update.sh`, which creates a backup
gate before the new image is started — but Elestio runs that hook only for its
own update operation. A redeploy after an environment change takes the deploy
path (`prepare.sh`, `build.sh`, `run.sh`, then `post-update.sh`) and has no
backup gate, so a version change must be preceded by a manual backup.

The checked-in Elestio value intentionally makes deployment fail before any old
or incompatible app image can start. Replace it only after the release pipeline
has published and verified both `linux/amd64` and `linux/arm64` for the chosen
tag. A catalog submission must contain that published tag, not the placeholder
or a planned release number.

The optional local-AI profile requires at least 16 GB RAM and additional storage.
The large local chat model is a separate opt-in through
`ENABLE_LOCAL_GPT_OSS=true`.

Before a time-limited trial ends, delete pipelines, targets, services, and
backups, disable auto-refill, and verify that no billable resource remains.
