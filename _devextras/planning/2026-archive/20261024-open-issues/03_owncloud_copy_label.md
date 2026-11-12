# 03 — ownCloud is called ownCloud

**Issue:** [#2671](https://github.com/metadist/synaplan/issues/2671).
**Commit:** `fix(files): call an ownCloud link ownCloud`
**Class:** `ota-candidate`.
**Branch:** `fix/20261024-owncloud-copy-label`

## Problem

Copy to cloud names one ownCloud link three ways. The menu and the toast
say "Nextcloud" because `platformCopyTargets()` maps client `owncloud`
to kind `nextcloud`. The Sent to line says "owncloud" because
`FileSentTo.vue` looks up `files.push.kind.<client>` and falls back to
the raw client when the key is missing. There is no `owncloud` key.

Found while walking E-9 ([#2669](https://github.com/metadist/synaplan/issues/2669))
with the reference client registered as `owncloud`.

## Goal

An ownCloud link reads "ownCloud" in the menu, the toast, the Sent to
line, and "Open in …", in all five locales. Nextcloud and OpenCloud
stay as they are.

## User-flow

Pointer: Library → Generated → a file's Copy to cloud, and the Sent to
line on that file.
Journey: **J-OI-3**.

1. With an ownCloud link that `can_copy` (the reference client, or a
   stub of `platformCopyTargets` if the client is down — say which in
   STATUS).
2. Open Copy to cloud. The entry is "ownCloud · <host>".
3. Copy a generated file. The toast says ownCloud, and that the file
   is still here. Open in ownCloud uses that name.
4. The Sent to line says "ownCloud · <host> — Copied".
5. A Nextcloud link on the same menu still says Nextcloud.

Exit (UX contract §6):

1. **J-OI-3 walked in the browser** (U10): menu label, toast, Sent to
   line, and Open in ownCloud on the same file row, light and dark.
   Unit tests prove the kind map; they do not replace the walk. If the
   reference client is down, record the blocker in STATUS and leave this
   exit open — do not mark the sprint done on tests alone.
2. The file stays in the library; Sent to is on the same row.
3. The existing `files.push.done` sentence, with `{name}` = ownCloud.
   Five locales for the new kind label only.
4. A link with `can_copy` false stays off the menu (already tested).
   No flag.
5. Light, dark, 320 px: the menu already wraps; check the new label
   does not overflow the row.

## Copy

`files.push.kind.owncloud` = "ownCloud" in `en`. Match the product name
in `de`, `es`, `fr`, `tr` (the word ownCloud, not a translation).

## Change

- `CloudFolderKind` gains `owncloud`. `platformCopyTargets()` returns
  kind `owncloud` for client `owncloud`. It no longer substitutes
  `nextcloud`.
- `files.push.kind.owncloud` in five locales, so `FileSentTo` and
  `FilePushMenu` resolve it. No special case in the Vue files.
- `cloudFolderKindFor` (WebDAV connections) is unchanged in this sprint:
  the issue is the platform link, and guessing "owncloud" from a WebDAV
  URL is a different bug. Record it if the walk hits it. Do not fix it
  here.

## Test

`frontend/tests/unit/services/cloudFolderTargets.spec.ts`: a link
`{ client: 'owncloud', can_copy: true }` has `kind: 'owncloud'`.

`frontend/tests/unit/components/files/FileSentTo` (add the case if the
spec exists, otherwise a small spec): client `owncloud` renders
"ownCloud", not "owncloud" and not "Nextcloud".

The kind assertion is red before the map change.

## Gate

`make ci-local` and `make test-e2e`.
