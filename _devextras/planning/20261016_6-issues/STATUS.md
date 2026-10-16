# Status — six issues, 2026-10-16

A line here is the record. Chat is not.

| When | Step | Result |
| ---- | ---- | ------ |
| 2026-10-16 | Plan | Written on `dev/20261016-6-issues` from `main` `9ca56ccb1`. Pull request [#2520](https://github.com/metadist/synaplan/pull/2520). Copilot requested. No product code. Sprints 01–03 confirm behaviour that is already on `main`. Sprints 04–06 are the code stack. |
| 2026-10-16 | Plan review | Copilot's six comments accepted. Playwright commands go through `npm run test:e2e`. #2460 stays open without a CI-image repeat and a timeline test. The widget sources test mounts as a visitor and asserts the answer is on screen. The transcript is captured at the provider call and kept out of `ChatRunRecorder`. The opener stays on a reloaded reply. |

| 2026-10-16 | Sprint 01 #2459 | Confirmed on `main` `c5bb5e006`. Vitest `TaskPromptsConfiguration.spec.ts` (3 tests) green. Walk J-TP-1: loading pill has no zero, no-match only with a search, empty library is "No topics yet." plus New topic, failed load is the error sentence plus Retry. Light, dark, 320 px. Issue closed. No branch. |
| 2026-10-16 | Sprint 02 #2460 | Unit lock `shows the saved shortcut after the list has settled, with the form closed and no second load` is green on current code. Browser J-SC-1: save closes the form, the row is in name order, one POST and no second GET, delete removes the row. Dev-stack `--repeat-each=20` with 4 workers: 16 passed, 4 timed out on the slash menu after reload. The same repeat with `--workers=1`: 20 passed. Issue stays open until the CI-image repeat. No product fix. |
| 2026-10-16 | Sprint 03 #2461 | Confirmed. One `GET /api/v1/getting-started` per checklist mount. Vitest and the GettingStarted PHPUnit files are green. Walk J-GS-1: the checklist shows the steps that apply, with no per-app status fan-out; hiding it removes it. Issue closed. No branch. |

| 2026-10-16 | Sprint 04 #2519 | Visitors no longer see internal sources. `ChatWidget` does not paint `KnowledgeSources` from history or from the live complete event. `TurnWebSearch` skips web search on a widget turn and still reads pasted links. Unit tests green. PHPStan green. Full PHPUnit green. |

Next: sprint 05.
