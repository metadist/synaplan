# Epic 2 — Synaplan extension for Collabora Online

Status: planned, starts after Epic 1 is in production
Depends on: Epic 0 (test bed), a Collabora build with the extension
framework (26.04+ with `experimental_features` enabled; watch for the 1.0
manifest)

Epic 1 gives Collabora users Synaplan's brain inside Collabora's own
sidebar. This epic gives them **Synaplan's face**: our chat UI, image
generation, saved tasks, knowledge search and file context, as a first-class
sidebar panel that reads and writes the document through Collabora's
extension API. It is the "standard plugin" requested: one directory an
operator drops into any Collabora installation.

## How the framework works (as of 26.04, manifest 0.1)

- An extension is a directory `browser/dist/extensions/<reverse-dns-id>/`
  containing `manifest.json` (`manifestVersion: "0.1"`, `name`, `entry`,
  `icon`, `supports: ["text","spreadsheet","presentation","drawing"]`) plus
  its HTML/JS/CSS. coolwsd discovers the directories at startup; the editor
  adds an **Extensions** notebookbar tab / menubar submenu with one item per
  extension; clicking opens the entry HTML in a sidebar iframe.
- The iframe loads `../cool.js`, which exposes:
  - `cool.callRemote(fn, ...args)` — ships the function source to the kit
    process and runs it in a JS-UNO context with the **full UNO API** (same
    surface as Basic/Python macros: `com.sun.star.frame.Desktop`, text
    ranges, sheets/cells, draw pages/shapes). The function cannot close over
    iframe variables; everything goes in as arguments; the return value is
    JSON-encoded.
  - `cool.document.on*` hooks — selection changes, modifications, comment
    add/change/remove.
  - `Extension_Resize` / `Extension_Teardown` handshakes handled by the
    helper.
- Gated by `experimental_features` in `coolwsd.xml`; deploy is manual (copy
  the directory, restart coolwsd).

## Step 2.1 — Repository and build

- New public repository `synaplan-collabora` (same layout as the other
  integration repos: `frontend/` Vite build, `docs/`, CI, Conventional
  Commits) producing `dist/com.synaplan.assistant/` with `manifest.json`,
  `index.html`, `assets/`, `icon.svg`. Build output is what an operator
  copies into `browser/dist/extensions/`.
- Auth: the iframe is served **by Collabora**, not by Synaplan, so it is a
  cross-origin client of the Synaplan API like the chat widget. Reuse the
  widget's runtime detection and CORS model; first-run screen asks for the
  Synaplan URL + login (OAuth device/redirect flow already used by
  Synamail's `/addin/connect` bridge) and stores the session in the iframe's
  storage. No hardcoded hosts.
- Dev loop: our `collabora` compose service mounts `dist/` into
  `/opt/cool/browser/dist/extensions/com.synaplan.assistant:ro` and enables
  the experimental flag; Vite builds on change; reload the document.

## Step 2.2 — Document adapters (the core of the epic)

`src/document/` — one adapter per document type, each a set of small,
self-contained functions passed to `cool.callRemote`. They are the only place
UNO is touched; everything else sees plain JSON.

| Adapter | Read | Write |
| ------- | ---- | ----- |
| `WriterAdapter` | selection text, paragraph at cursor, whole text with paragraph indexes and styles, headings outline, comments | insert text at cursor / replace selection (keeps paragraph style), insert heading/paragraph after index, insert image from data URL or URL, add comment on selection |
| `CalcAdapter` | active sheet + selection range (values, formulas, number formats), sheet list, named ranges, cell at cursor | set values/formulas for a range, set number format, insert sheet, add chart from a range, add note |
| `ImpressAdapter` | slide list with titles, current slide shapes (type, text, position), notes | set slide title/body text, add slide from outline, insert image on the current slide, set notes |

Each function is exercised against fixture documents in an integration test
that runs a real CODE container in CI (nightly tier; PR CI stubs
`cool.callRemote`).

## Step 2.3 — Panel UI

- `Synaplan` panel: chat with the **document pinned as context** (adapter
  read → sent as file context via the existing conversation-file mechanism),
  quick actions per document type (Summarise, Translate selection, Rewrite,
  Explain formula, Generate slide from notes, Make an image for this slide),
  a result footer with **Insert at cursor / Replace selection / Copy**.
- Image generation: Synaplan `mediamaker` result → `insertImage` in the
  adapter (data URL path avoids Collabora's `lok_allow` host list).
- Saved tasks: list the user's tasks that accept a document input and run
  them on the current document (uses the office-docs engine on the server
  for the heavy lifting; the adapter only exports the current bytes via
  `.uno:Save` + WOPI, or sends text).
- Design tokens: the panel is Synaplan-branded but must sit well in
  Collabora's light and dark themes (read `prefers-color-scheme`; no
  Tailwind colors).
- i18n: 5 locales, reuse Synaplan keys where possible.

## Step 2.4 — Fallback without the experimental flag

For installations that cannot enable the extension framework, Epic 0's
`DocumentEditView.vue` (Synaplan owns the page) hosts the **same panel
component** next to the editor iframe and talks to the document through the
stable postMessage API:

- write: `Send_UNO_Command` (`.uno:InsertText` with `Text` and optional
  `ParaStyleName`), `Action_InsertGraphic` (Synaplan origin must be in
  `lok_allow`), `.uno:ExecuteSearch` to position the cursor;
- read: postMessage cannot return the selection; use the WOPI file bytes +
  office-docs engine (`convert-to txt`) for whole-document context and ask
  the user to paste a selection. Honest limitation, documented in the UI.
- toolbar: `Insert_Button` "Ask Synaplan" only in classic mode; rely on the
  panel otherwise.

This is also the interim experience until Epic 2.1–2.3 land, so build the
panel component once, host it twice.

## Acceptance

- In our sidecar with the flag on: Extensions tab shows Synaplan; select a
  paragraph → "Rewrite formally" → replaced in place with style kept; in Calc
  select a range → "Explain" → answer references A1-style cells; in Impress
  "Image for this slide" inserts a generated picture on the current slide.
- The same `dist/` directory dropped into a vanilla CODE 26.04 container
  behaves identically after login to any Synaplan instance.
- Nothing in the extension depends on our WOPI host; it works on documents
  opened from Nextcloud/OpenCloud once the operator installs it (Epic 4).
