# E2 — Telegram channel

**Sprint:** E2 of [`00_master_plan.md`](./00_master_plan.md).
**Starts:** row 1d of [`../20260925_roadmap.md`](../20260925_roadmap.md).
After E1 coding has started. Do not wait for the UX walk list or the
Integrations catalog.
**Issue:** [#2202](https://github.com/metadist/synaplan/issues/2202).
**Brief:** [`../20260927_openai_compatible_image_generation.md`](../20260927_openai_compatible_image_generation.md)
section “#2202”.
**Shape to copy:** WhatsApp (`WhatsappModule`, `WhatsAppService`,
Incoming chats) and the archived pilot
[`../2026-archive/20260822-open-plugin-platform/README.md`](../2026-archive/20260822-open-plugin-platform/README.md).

## User-flow

Journeys **J-TG-1** and **J-TG-2** in [`00_master_plan.md`](./00_master_plan.md) §1.

A person pastes a Telegram bot token, sends a message to the bot, and
finds the thread under Incoming chats. Turning the channel off is one
click on that row. The sentence says new replies stop and history stays.

## Goal

This is a channel plugin, not a new core chat controller and not
OpenClaw.

**Do**

1. FeatureModule, default off. Flag off ⇒ no menu row, no webhook, no teaser.
2. Inbound Telegram update → the same message pipeline WhatsApp uses →
   reply with Bot API `sendMessage`.
3. The thread shows in Incoming chats with source Telegram, the bot
   name, and who else can see it.
4. Disconnect on the same row. Consequence is one sentence.
5. If `APP_URL` is not reachable from the internet, say the webhook
   needs a public URL and name the field that fixes it. No raw Telegram
   error in the UI.
6. Five locales for every new string. The bot token never ships in the
   frontend bundle.

**Do not**

- Fork the chat stack.
- Add tools, computer use, or a skill runner in this sprint.
- Require the open plugin marketplace. A focused `plugins/telegram/`
  (or the same FeatureModule pattern WhatsApp uses, if that is the
  smaller patch) is enough. Pick the smaller one in the PR and record
  it in STATUS.

## Exit criteria

1. J-TG-1 and J-TG-2 walked in the browser, including a real or stubbed webhook (U10).
2. The thread is visible under Incoming chats within ten seconds of the inbound message (U2).
3. Disconnect copy states that replies stop and history stays, in all five locales (U3).
4. Empty (no token), error (no public URL), and flag-off states are done. Flag off removes the channel (U5, U8, U11).
5. Light, dark, and 320 px on the channel row (U9).

## Class

Channel screen: `ota-candidate`. Webhook and module: `backend-only`.
New paths go on the matching allow-list in
`.github/mobile-impact-policy.json` in the same PR.
