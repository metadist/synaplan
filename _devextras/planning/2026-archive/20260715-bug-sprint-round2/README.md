# Bug sprint round 2 — 2026-07-15

Branch: `fix/20260715-bug-sprint-round2` (from `origin/main`)

## Selection (10)

| # | Title | Size | Sprint action | Outcome |
| --- | --- | --- | --- | --- |
| [#1345](https://github.com/metadist/synaplan/issues/1345) | Persist composer file attachments | M | Fix | `useAttachmentPersist` wired in `ChatInput.vue` (per-chat localStorage; skipped in incognito) |
| [#732](https://github.com/metadist/synaplan/issues/732) | New chat after cancel reuses empty chat | S | Fix | `findOrCreateEmptyChat` skips active chat with local history; cancel bumps `messageCount` |
| [#951](https://github.com/metadist/synaplan/issues/951) | Memories retry button polish | S | Fix | Padding/flex + loading/disabled state on retry |
| [#430](https://github.com/metadist/synaplan/issues/430) | Line break after memory tags | M | Fix | Named-key badge path splices into previous HTML instead of orphan line |
| [#344](https://github.com/metadist/synaplan/issues/344) | Profile unsaved banner from autofill | S | Fix | Password dirty only after `@input` (`passwordTouchedByUser`) |
| [#975](https://github.com/metadist/synaplan/issues/975) | WhatsApp missing model metadata | S | Fix | `storeOutgoingMessage` writes `ai_chat_*` / `ai_sorting_*` |
| [#1311](https://github.com/metadist/synaplan/issues/1311) | Gemini 3.5 Flash duplicate (prod drift) | S | Migration | `Version20260715120000` restores BID 170 when still named/provid 3.5 Flash |
| [#995](https://github.com/metadist/synaplan/issues/995) | Widget subtitle not configurable | — | Verify & close | Already fixed (`widgetSubtitle` in AdvancedWidgetConfig + ChatWidget) |
| [#1079](https://github.com/metadist/synaplan/issues/1079) | Multitask routing toggle vs user override | M | Fix | Admin config returns `effectiveForMe` / `hasPersonalOverride`; UI warning + clear |
| [#623](https://github.com/metadist/synaplan/issues/623) | Cross-tab session isolation | L | Minimal sync | `BroadcastChannel` + `localStorage` principal marker → re-fetch `/auth/me` + reset stores |

## Notes

- Schema: no new tables; #1311 is idempotent DML only (Galera-safe raw SQL).
- #995: close with comment pointing at `widgetSubtitle` (default / hide / custom).
