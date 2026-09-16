# URL watch — Wave 3 companion (lean)

Watch a page, keep **one** saved copy, compare the next fetch, and mail
the difference. Promptable, schedulable as a daily Saved Task.

## Why this is in Wave 3

`url_fetch` already reads a URL. A scheduled agent could not tell whether
the page changed. That is the gap: one snapshot per owner+URL, compare on
the next fetch, `$nX.text` is the diff (or first-save / no-changes).

This does **not** replace AI Plugs S2 (Docling). It is a small companion
on the existing Saved Tasks + `url_fetch` + `email_me` path.

## In scope (v1)

| Piece | What |
| ----- | ---- |
| Storage | `BURLWATCHES`: one row per `(owner, normalized URL)`. Each compare-fetch **overwrites**. No history. |
| Fetch | `url_fetch` with `inputs.compare: true` (or a compare/difference heuristic on the message). Skip pre-fetch reuse in compare mode. |
| Prompt | “get this URL and save the details, compare it to a previously saved version and mail me the differences” → `url_fetch` (compare) + `email_me` on `$n1.text`. Save that chat as a **daily** Saved Task. |
| CRUD UI | On the Saved Tasks page only. Create (watch + first fetch), read (list + saved copy), update (check now / overwrite), delete (stop watching). Deleting a Saved Task does **not** delete the watch. |
| Cap | ~64 KB text, ~200 diff lines. Same `MULTITASK.URL_FETCH_ENABLED` kill switch. |

## Out of scope (v1)

- Version history / “show me last Tuesday”
- Copying the snapshot to Dropbox / Nextcloud / `save_to_folder` (Dropbox already exists as a destination; do not auto-wire)
- A new capability enum or BCONFIG flag
- Agent Builder S4–S6, Docling, SearXNG

## Acceptance

1. Chat the prompt above, save as a daily task, run twice: first mail is
   “first save”, second is a unified diff or “no changes”.
2. Saved Tasks → Watched pages: add, view, check now, delete.
3. One row per URL; a second watch of the same address reuses it.
4. Ordinary “summarize this URL” still returns the page, not a diff.
