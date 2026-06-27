# Feature 7 — Share generated files to destinations ("Send to…")

**Release:** 4.0 (Phase A) · later for Phase B/C · **Priority:** P1
**Status:** Phase A approved (build now) · Phase B/C planned
**Related:** [`03_file-management.md`](./03_file-management.md) (file world; `BSOURCE`
incl. `nextcloud`/`opencloud`, the "Incoming inbox" = the *inbound* half of this),
[`01_async-media-jobs.md`](./01_async-media-jobs.md) (generated media must exist as a
findable file first), archived
[`07-GENERIC-INTEGRATION.md`](../archive/integrations/2026-04-nextcloud-integration/07-GENERIC-INTEGRATION.md)
(the original "Context Protocol" + generic-integration standards).

> Goal: when Synaplan generates a file in a chat — Word/DOCX, PPTX, MP3/TTS, ICS,
> images, video, anything we can produce — the user must be able to **send it to a
> destination**, starting with **their Nextcloud space**. Long-term, "destination"
> is a pluggable concept (Nextcloud, OpenCloud, Dropbox, SharePoint, email, OS
> share sheet) and Synaplan exposes a **standard shareable object** that any
> registered receiver can consume.

This doc captures the whole vision so it does not get lost, but **only Phase A is
in scope for 4.0** (decided 2026-06-27). Phases B/C are the forward-looking
architecture.

---

## 0. Decisions (2026-06-27)

| Decision | Choice |
|---|---|
| **Scope now** | **Phase A only** — generalise the Nextcloud app's "Save to Nextcloud" so it works for **every** generated file type, from **both** launch surfaces (top‑nav Research page + floating chat launcher). |
| **Folder layout in Nextcloud** | **Sorted by kind**: `Synaplan/Documents`, `Synaplan/Audio`, `Synaplan/Calendar`, `Synaplan/Images`, `Synaplan/Video` (fallback `Synaplan/`). |
| **vultr-cluster top‑nav icon** | **Skip** for now (see §6 for the finding — it is almost certainly an OpenDesk top‑bar/portal surface issue, not an app‑version regression). |
| **Web Share API** | Use it later as a *convenience* layer (Phase B), **not** as the cloud‑delivery mechanism. See §5. |

---

## 1. Why (grounded in the current code)

A real need: media/files generated in chat (Word, PPT, MP3, ICS, …) should land in
the user's own storage. Today this is **half-built and media-only**.

### What already exists (do NOT rebuild)

| Component | State | Evidence |
|---|---|---|
| **Nextcloud app save-to-Files** | ✅ built, **media-only** | `synaplan-nextcloud/lib/Controller/MediaController.php` `save()` downloads a Synaplan media URL and writes it into the user's `Synaplan/` folder via `IRootFolder`. Restricted to `type ∈ {image, video}` in `generate()`; the save path itself is type-agnostic. |
| **Both NC launch surfaces are native + session-backed** | ✅ | Top‑nav `/research` and the floating launcher both render the **same** `ResearchChat.vue`, which calls the **NC app's own** `/apps/synaplan_integration/api/v1/*` with the **logged‑in Nextcloud session**; PHP forwards to Synaplan with a shared admin API key. **Neither uses the cross‑origin Synaplan `widget.js`.** |
| **Media proxy** | ✅ | `MediaController::proxy()` streams a Synaplan file through NC (avoids cross-origin issues). |
| **Synaplan generated-file delivery** | ✅ | Generated docs/media are served from `/api/v1/files/uploads/{relativePath}` (public route, owner/shared-chat checks inside) and `/api/v1/files/{id}/download`. Message JSON exposes them via `files[]`, legacy `file{path,type}`, and `taskPlan.cards[].url` (`MessageApiFormatter`). |
| **Synaplan public share** | ✅ | `POST /api/v1/files/{id}/share` → `/up/{token}` (token-based public link). |

### The gaps

| # | Gap |
|---|---|
| **G1** | The NC "Save to Nextcloud" action only appears for **images/videos**. Generated **DOCX/PPTX/MP3/ICS** arrive as `files[]` attachments / taskPlan cards with **no save affordance**. |
| **G2** | `MediaController::save()` writes everything flat into `Synaplan/`; no per-kind organisation; no calendar-import nicety for ICS. |
| **G3** | Synaplan has **no generic "destination/share-target" abstraction** — the only outbound concepts are a public `/up/` link and channel-specific sends (WhatsApp, Synamail). Dropbox/SharePoint/OpenCloud have nowhere to plug in. |
| **G4** | No **standard shareable object** (a portable DTO a receiver can consume); each integration reinvents the shape. |

---

## 2. Phase A — Nextcloud app: "Save to Nextcloud" for every generated file (BUILD NOW)

All work is in **`/wwwroot/synaplan-nextcloud`**. No Synaplan-backend changes
required (it reuses the existing download routes + admin API key).

### 2.1 Backend — generalise the save controller

- In `MediaController` (or a renamed `FilesController` — keep route back-compat),
  **remove the `image|video` restriction** for the *save* path; accept any
  generated file: `{ mediaUrl | filePath, filename, kind? }`.
- **Folder routing by kind** (decided): map `kind`/extension →
  `Synaplan/Documents` (`docx,pptx,xlsx,csv,pdf,txt,md`), `Synaplan/Audio`
  (`mp3,wav,ogg,m4a`), `Synaplan/Calendar` (`ics`), `Synaplan/Images`
  (`png,jpg,jpeg,gif,webp`), `Synaplan/Video` (`mp4,webm`), else `Synaplan/`.
  Create sub-folders on demand; keep the existing dedupe (`name (1).ext`) logic.
- **Verify download auth for non-media**: confirm `SynaplanClient::downloadFile()`
  with the admin `X-API-Key` can fetch document/audio/ICS paths from
  `/api/v1/files/uploads/...` (media already works; documents go through the same
  public route with internal owner checks resolved against the single Synaplan
  user the app uses). If owner checks block it, fall back to
  `/api/v1/files/{id}/download` with the file id.
- Return `{ success, path, folder }` so the UI toast can name where it landed.

### 2.2 Frontend — surface the action for all file kinds

- In `ResearchChat.vue`, detect generated artifacts of **all** types in an
  assistant message across the three carriers:
  - legacy `message.file` (media),
  - `message.files[]` (attachments — DOCX/PPTX/MP3/…),
  - `message.taskPlan.cards[].url` (document/calendar cards).
- Render a **"Save to Nextcloud"** button per artifact (reuse the existing
  `POST /api/v1/media/save` call; extend payload with `kind`). Success toast:
  *"Saved to {folder}"*.
- Keep it consistent in both the full-page and the compact launcher panel (same
  component — one change covers both).

### 2.3 ICS nicety (stretch, optional)

- For `.ics`, additionally offer **"Add to Calendar"** (import into the user's NC
  Calendar app if present) alongside "Save to Files". Baseline = save the `.ics`.

### 2.4 i18n / version / gate

- Add the new strings to the NC app's locales under `l10n/` (match the existing
  set; `de` at minimum).
- Bump `appinfo/info.xml` + `package.json` to **1.3.0**; add a `CHANGELOG.md`
  entry.
- Run the app's gate (`make build`, lint, format) before commit.

### 2.5 Phase A — Definition of done

- Every generated file type (Word/PPT/MP3/ICS/image/video) shows a working
  **Save to Nextcloud** action in both NC chat surfaces.
- Files land in the correct `Synaplan/<Kind>` sub-folder, deduped, with a toast
  naming the path.
- No Synaplan-backend change; admin-key download verified for documents/audio/ICS.
- i18n complete; version bumped; app gate green.

---

## 3. Phase B — Synaplan: generic "Send to…" destination abstraction (LATER)

The reusable foundation so Nextcloud is not a one-off and Dropbox/SharePoint/
OpenCloud/email can follow. Mirrors the existing AI `ProviderRegistry` pattern.

### 3.1 The standard shareable object (G4)

Formalise what every generated file exposes (builds on `MessageApiFormatter`
`files[]` + the `/up/{token}` share):

```jsonc
ShareableFile {
  fileId, filename, originalName, mime, sizeBytes,
  downloadUrl,            // signed/expiring URL or relative uploads path
  sourceChatId, sourceMessageId,
  expiresAt
}
```

This is the user's "standard sharing object … that potential receivers get on
call".

### 3.2 `DestinationProvider` registry (G3)

- Interface: `deliver(ShareableFile $file, array $target): DeliveryResult`.
- Registry of providers (DI-tagged, like `ProviderRegistry`):
  `download`, `public_link` (exists), `nextcloud`, `opencloud`, `dropbox`,
  `sharepoint`, `email` (Synamail).
- "Receivers registered on call" = a per-request/session registry where a caller
  (e.g. the NC app) declares itself an eligible destination and is offered back to
  the user as a target.

### 3.3 Endpoint

- `POST /api/v1/files/{id}/send` `{ destination, target }` → `DeliveryResult`,
  OpenAPI-annotated; regenerate frontend schemas.
- **v1 callback model for cloud accounts (no OAuth yet):** Synaplan returns the
  `ShareableFile`; the destination app *pulls* it — which is exactly Phase A.
  Direct server→server push (Synaplan → Nextcloud WebDAV with per-user OAuth) is a
  later `nextcloud` provider implementation.

### 3.4 Auth note

- Logged-in main-app user → cookie/`sk_*` key, backend acts on their behalf.
- Widget visitor in an iframe → only a widget session; cannot call authenticated
  endpoints. For widget-side "send", either a parent-frame bridge (new
  `postMessage`/`CustomEvent` contract) or the host app's own session is required.

---

## 4. Phase C — Reconcile with the 4.0 file world (LATER)

- Track outbound delivery on the file (e.g. a `delivered_to` marker) so the round
  trip generate → send to NC → re-ingest from NC as `incoming` (per
  [`03_file-management.md`](./03_file-management.md) §3.3) is coherent.
- Keep the `ShareableFile` DTO + `DestinationProvider` registry compatible with the
  `BSOURCE`/Incoming model.

---

## 5. "Share with…" standard — investigation result

Yes, the standard exists — two complementary W3C specs:

- **Web Share API** (`navigator.share()` / `navigator.canShare()`) — invokes the
  OS native "Share with…" sheet; **can share files**. Constraints that matter:
  HTTPS only, requires a **user gesture**, gated by the `web-share` permission
  policy, **inside a cross-origin iframe the embed needs `allow="web-share"`**,
  **Firefox does not support it**, desktop support is partial, and file-type
  support varies (audio/image/pdf/video/text broadly OK; arbitrary `docx`/`ics`
  often **not** shareable). Always feature-detect with `navigator.canShare({files})`.
- **Web Share *Target* API** — the inverse: lets an **installed PWA register
  itself as a recipient** in the OS share sheet (manifest `share_target`). This is
  how Synaplan could become a "Share to Synaplan" destination from other apps.

**Verdict:** great as a **Phase B convenience** ("Share" button on a generated file
in the main app/widget, especially on mobile), but **not** a substitute for
server-mediated delivery to a *registered cloud account* — that stays the
`DestinationProvider` job. Plan for both; they are not competitors.

---

## 6. vultr-cluster top-nav icon — finding (not actioned now)

The app registers **both** the floating launcher and the top-nav entry in the same
`Application::boot()` (the nav predates the launcher: nav since v1.0.0, launcher
v1.1.0), so they cannot diverge by version. The `vultr-cluster` repo **pins no
version** (installs from a local copy or `releases/latest`; documented update
2026-04-02 vs local 1.2.1).

Most likely cause: on OpenDesk the visible header is the **OpenDesk unified top
bar** (`integration_swp` / portal `centralNavigation`), a *different* surface from
Nextcloud's left app-menu grid where `INavigationManager` entries appear. Verify
before any change:

```bash
kubectl exec -n opendesk <nc-pod> -- php occ app:list | grep synaplan
kubectl exec -n opendesk <nc-pod> -- grep '<version>' \
  /var/www/html/custom_apps/synaplan_integration/appinfo/info.xml
```

---

## 7. Sprints

- **A1 (now)** — NC backend: generalise `save()`, kind→sub-folder routing, verify
  doc/audio/ICS download auth. Unit tests.
- **A2 (now)** — NC frontend: detect all generated artifacts in `ResearchChat.vue`,
  add "Save to Nextcloud" per artifact, toast with path. i18n, version bump,
  changelog, gate.
- **B (later)** — Synaplan `ShareableFile` DTO + `DestinationProvider` registry +
  `POST /files/{id}/send` (callback model) + Web Share button in main app/widget.
- **C (later)** — file-world reconciliation (`delivered_to`, round-trip coherence).

## 8. Open questions

1. ICS "Add to Calendar" in Phase A, or defer to a later NC app release?
2. Phase B endpoint shape: per-file `send`, or a batch `send` for multi-file
   answers?
3. For the widget path (no NC session), commit to a `postMessage` host bridge, or
   keep widget "send" limited to Web Share + public link?
