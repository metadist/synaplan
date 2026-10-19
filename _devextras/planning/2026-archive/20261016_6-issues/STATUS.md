# Status — six issues, 2026-10-16

> **Archived 2026-10-18.** The six issues are implemented on `main`.
> #2459 and #2461 closed with no product diff. #2460's settled-list test
> is [#2525](https://github.com/metadist/synaplan/pull/2525) (`a74f8c2c9`).
> Widget sources: [#2526](https://github.com/metadist/synaplan/pull/2526)
> (`596a72781`). Visitor-chrome tests:
> [#2527](https://github.com/metadist/synaplan/pull/2527) (`1f400c619`).
> "What was sent": [#2529](https://github.com/metadist/synaplan/pull/2529)
> (`dd72649c3`) and [#2531](https://github.com/metadist/synaplan/pull/2531)
> (`dcd3f0611`). Two leftovers stay outside this folder: GitHub
> [#2504](https://github.com/metadist/synaplan/issues/2504) is still open
> because #2527 did not use a closing keyword, and the CI-image 20-repeat
> for #2460 was never run — #2525 closed that issue on merge anyway.

A line here is the record. Chat is not.

| When | Step | Result |
| ---- | ---- | ------ |
| 2026-10-16 | Plan | Written on `dev/20261016-6-issues` from `main` `9ca56ccb1`. Pull request [#2520](https://github.com/metadist/synaplan/pull/2520). Copilot requested. No product code. Sprints 01–03 confirm behaviour that is already on `main`. Sprints 04–06 are the code stack. |
| 2026-10-16 | Plan review | Copilot's six comments accepted. Playwright commands go through `npm run test:e2e`. #2460 stays open without a CI-image repeat and a timeline test. The widget sources test mounts as a visitor and asserts the answer is on screen. The transcript is captured at the provider call and kept out of `ChatRunRecorder`. The opener stays on a reloaded reply. |

| 2026-10-16 | Sprint 01 #2459 | Confirmed on `main` `c5bb5e006`. Vitest `TaskPromptsConfiguration.spec.ts` (3 tests) green. Walk J-TP-1: loading pill has no zero, no-match only with a search, empty library is "No topics yet." plus New topic, failed load is the error sentence plus Retry. Light, dark, 320 px. Issue closed. No branch. |
| 2026-10-16 | Sprint 02 #2460 | Unit lock `shows the saved shortcut after the list has settled, with the form closed and no second load` is green on current code. Browser J-SC-1: save closes the form, the row is in name order, one POST and no second GET, delete removes the row. Dev-stack `--repeat-each=20` with 4 workers: 16 passed, 4 timed out on the slash menu after reload. The same repeat with `--workers=1`: 20 passed. Issue stays open until the CI-image repeat. No product fix. |
| 2026-10-16 | Sprint 03 #2461 | Confirmed. One `GET /api/v1/getting-started` per checklist mount. Vitest and the GettingStarted PHPUnit files are green. Walk J-GS-1: the checklist shows the steps that apply, with no per-app status fan-out; hiding it removes it. Issue closed. No branch. |

| 2026-10-16 | Sprint 04 #2519 | Visitors no longer see internal sources. `ChatWidget` does not paint `KnowledgeSources` from history or from the live complete event. `TurnWebSearch` skips web search on a widget turn and still reads pasted links. Unit tests green. PHPStan green. Full PHPUnit green. |

| 2026-10-16 | Sprint 05 #2504 | Playwright covers close/reopen (`@ci` `@crossbrowser`), theme, fullscreen, the 4-of-5 message limit, and removing a staged file. Chromium run of the new tests is green, including the limit test after the session history has loaded. |

| 2026-10-16 | Sprint 06 #2515 | Signed-in chat can open "What was sent" on a reply (`J-PT-1` walked in Playwright `chat.spec.ts` on live reply and after reload). The transcript is captured at the provider boundary (including OpenAI `previous_response_id` reduction + fallback, Anthropic turn normalization, and `ChatToolLoop` rounds), redacted, capped, and stripped before `ChatRunRecorder`. Widget and guest turns do not receive it. `SizeRatchetTest`, PHPStan, deptrac, locale parity, and unit/E2E tests are green. |

Next: none. The CI-image repeat for #2460 is still open.
