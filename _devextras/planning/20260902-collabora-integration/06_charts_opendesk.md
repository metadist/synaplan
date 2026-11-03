# Final step — openDesk in synaplan-charts

Status: in review (`synaplan-charts` `feat/opendesk-office-editor`)
Depends on: Epic 0.1 (the WOPI host). The chart change is safe on an older
image: unset URLs emit nothing, and an image that does not know the variables
ignores them.

openDesk already runs Synaplan beside it from `synaplan-charts`: the
`synaplan-opendesk` release (Nextcloud app or OpenCloud plugin, portal tile),
meeting notes, and Office previews through openDesk's Collabora
(`apps.collabora.enabled` → `OFFICE_CONVERT_URL=http://collabora:9980`).
This step adds the editor without changing that.

## What ships

- `charts/synaplan` values `office.publicUrl` and `office.wopiUrl`.
  Both set → `OFFICE_PUBLIC_URL` and `OFFICE_WOPI_URL` on web, worker and
  scheduler. Either empty → the editor stays off and previews are unchanged.
  Setting only one fails the render.
- Helmfile passes `technical.synaplan.office.publicUrl` and `wopiUrl`.
  The openDesk example leaves them empty, so `apps.collabora.enabled` still
  means previews only.
- The chart does not edit openDesk's Collabora. The operator allows
  `wopiUrl` (typically `http://synaplan:80`) as a WOPI host on that
  Collabora, and sets `publicUrl` to the browser hostname
  (`https://office.<domain>`).

## Acceptance

`helmfile -e opendesk` renders the same Office env as before (convert URL
only). A render with both editor URLs set contains the pair, and a render
with only one fails. Existing preview installs do not gain an editor.
