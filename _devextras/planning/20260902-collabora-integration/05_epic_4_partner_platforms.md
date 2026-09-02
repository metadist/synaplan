# Epic 4 — Partner platforms: Nextcloud, OpenCloud, ownCloud

Status: planned, follows Epic 1 (provisioning) and Epic 2 (extension)
Repositories: `synaplan-nextcloud`, `synaplan-opencloud`,
`synaplan-owncloud-online` (each with its own gates and release process)

These platforms already run Collabora Online (Nextcloud `richdocuments`,
OpenCloud/ownCloud via WOPI apps). Synaplan is installed there as an app.
The job of this epic is **provisioning**, not new editor code: make the
platform's Collabora use Synaplan as its AI, and ship our extension where
the operator controls the Collabora installation.

## Step 4.1 — AI provider provisioning per platform

Collabora reads provider credentials from the WOPI host's `CheckFileInfo`
`UserPrivateInfo` (per user) or from `coolwsd.xml` (instance). The WOPI host
here is the platform, so the integration point is the platform's WOPI app:

- **Nextcloud**: `richdocuments` builds `CheckFileInfo`; our app hooks the
  event it exposes for `UserPrivateInfo` (it already merges Zotero and
  signature settings there — same pattern the Collabora commit names) and
  injects `AIProviderURL` / `AIProviderAPIKey` / `AIProviderModel` for the
  logged-in Nextcloud user, using the Synaplan key the `synaplan-nextcloud`
  app already holds for that user. If `richdocuments` offers no hook,
  contribute one upstream (small, in their interest) and fall back to
  instructing the admin to set `coolwsd.xml` `ai.*` to Synaplan.
- **OpenCloud**: the WOPI app is Go; check whether it forwards
  `UserPrivateInfo`. Our extension already performs RFC 8693 token exchange
  to obtain a Synaplan-scoped token — mint the gateway API key from that.
  Otherwise instance-level `coolwsd.xml`.
- **ownCloud Online**: same investigation as OpenCloud.
- Admin documentation per platform: the two lines to set, how to verify with
  one prompt, how to revoke.

## Step 4.2 — Extension distribution

- Publish the Epic 2 `dist/` as a versioned release asset and a container
  image layer (`synaplan-collabora-extension:<version>`) that platform
  operators can add to their Collabora deployment (volume mount or derived
  image). Document for Nextcloud AIO, the official `collabora/code` image
  and native packages.
- Keep the extension platform-agnostic: it only needs a Synaplan URL and a
  login; the platform apps may pre-fill the URL via a small
  `postMessage`/query handshake if the framework allows it (verify).

## Step 4.3 — Platform-native AI hooks (optional, per platform)

Where the platform has its own assistant framework, register Synaplan there
too so non-editor surfaces (files app, talk, mail) get Synaplan:

- Nextcloud Task Processing provider (text generation, summarise, translate,
  image generation) in `synaplan-nextcloud` — the Nextcloud Assistant then
  calls Synaplan across all Nextcloud apps, including its smart picker in
  Collabora.
- OpenCloud: extend the existing context actions (translate, summarise,
  knowledge) with document-aware ones once Epic 2's adapters exist.

## Acceptance

A Nextcloud user with the Synaplan app connected opens a document in
Collabora and the AI sidebar works against Synaplan without pasting a key;
an operator who installs the extension directory sees the Synaplan panel in
the same session.
