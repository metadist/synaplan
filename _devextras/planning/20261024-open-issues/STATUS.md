# Status — open issues from #2617

Plan: [`00_master_plan.md`](./00_master_plan.md). A walk is a dated entry
here, not a chat message.

| Sprint | Issue | State |
| ------ | ----- | ----- |
| Plan | — | written 2026-10-24 |
| 01 | [#2678](https://github.com/metadist/synaplan/issues/2678) | PR [#2703](https://github.com/metadist/synaplan/pull/2703) |
| 02 | [#2679](https://github.com/metadist/synaplan/issues/2679) | PR [#2704](https://github.com/metadist/synaplan/pull/2704) |
| 03 | [#2671](https://github.com/metadist/synaplan/issues/2671) | PR [#2706](https://github.com/metadist/synaplan/pull/2706); J-OI-3 walked 2026-10-24 |
| 04 | [#2667](https://github.com/metadist/synaplan/issues/2667) | PR [#2707](https://github.com/metadist/synaplan/pull/2707); J-OI-4 walked 2026-10-24 |
| 05 | [#2617](https://github.com/metadist/synaplan/issues/2617) | PR [#2709](https://github.com/metadist/synaplan/pull/2709) |
| 06 | [#2680](https://github.com/metadist/synaplan/issues/2680) | PR [#2710](https://github.com/metadist/synaplan/pull/2710); J-OI-5 walked 2026-10-24 |

#2635, #2636 and #2638 stay on row 24. They are not rows here.

## Walks

### 2026-10-24 — J-OI-1

Account `admin@synaplan.com`. AI infrastructure → Model catalog →
OpenAI-compatible endpoints. A temporary endpoint `labimport` pointed at
a local catalog of 40 models. Import models opened. Cancel closed it.
The endpoint was deleted. Nothing was imported.

Desktop, light, 1068×1234: the panel was 1202 px tall, the list scrolled
(2316 px of rows in a 1026 px region), and Import 40 was inside the
window (bottom 1197). 320×640, light and dark: Cancel and Import 40
were both inside the window (bottom 603). The capability column stays
in the table's sideways scroll, which was already there.

### 2026-10-24 — J-OI-3

Account `demo@synaplan.com`. Stack: Synaplan dev, **cloud-fixture**
(`client=owncloud`, `localhost:8099`, ada linked), Nextcloud dev on
`:8081`. Library → Generated → Copy to cloud.

- Menu: **ownCloud · localhost:8099** and **Nextcloud · localhost:8081**.
- Copy to ownCloud: toast **Open in ownCloud**; Sent to **ownCloud ·
  localhost:8099 — Copied** (not Nextcloud, not raw `owncloud`).
- Fixture note: if `curl localhost:8099/control/state` returns a PHP fatal
  about `/app/index.php`, recreate the container:
  `docker compose -f _devextras/testing/cloud-apps/fixture-app/compose.yml up -d --force-recreate`.

Automated: `@ci` `copy-to-cloud.spec.ts` (mocked link); `@noci`
`open-issues-walks.spec.ts` (live links).

### 2026-10-24 — J-OI-4

Account `demo@synaplan.com`. Linked Nextcloud `localhost:8081`, folder
`Invoices` (three `inv-*.txt` for ada). Branch
`fix/20261024-folder-read-honesty`. Prompt asks for a linked-cloud read
of Invoices (no WebDAV).

- **Ideal:** `folder-read-card` **Read … from Nextcloud › Invoices** and a
  table with Nordwind / Ostsee fixture amounts.
- **Also acceptable (#2667):** no read step, but the answer refuses honestly
  (e.g. “wasn't able to read … Invoices”) and does **not** invent Acme/Beta/Gamma
  vendors.
- Local run:
  `cd frontend/tests/e2e && npx playwright test open-issues-walks.spec.ts --project=chromium-noci`.

### 2026-10-24 — J-OI-5

Account `admin@synaplan.com`. AI infrastructure → Model catalog.
Temporary endpoint `joi5` (`http://host.docker.internal:8791/v1`, auth none)
listed two models, both named Echo:

```json
{"object":"list","data":[{"id":"echo-keep","name":"Echo"},{"id":"echo-gone","name":"Echo"}]}
```

`GET /v1/models` has no unavailable or suspended field. Absence from the
list is the signal. Imported both (ids 400 and 401). Then the list kept
only `echo-keep`. Import models again (the preview refresh).

- Both name cells read **Echo · OpenAICompatible · joi5**.
- `echo-gone` shows **No longer offered by OpenAICompatible. It is still
  here until you remove it.** `echo-keep` does not.
- With `MODELHEALTH.AUTO_DISABLE_ENABLED` off (the default), `echo-gone`
  stayed active (`BACTIVE=1`) and only the withdrawn marker was set.
- With the switch on, the same refresh set `BACTIVE=0` on `echo-gone`
  and left `echo-keep` active. The switch was set back to `0`.
- Delete of the withdrawn row (401) succeeded. Both test rows and the
  endpoint were removed afterwards.
