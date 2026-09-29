# E2 — Telegram channel

**Sprint:** E2 of [`00_master_plan.md`](./00_master_plan.md).
**Starts:** row 1d of [`../20260925_roadmap.md`](../20260925_roadmap.md).
After E1 coding has started. Do not wait for the UX walk list or the
Integrations catalog.
**Issue:** [#2202](https://github.com/metadist/synaplan/issues/2202).
**Brief:** [`../20260927_openai_compatible_image_generation.md`](../20260927_openai_compatible_image_generation.md)
section “#2202”.
**Shape to copy:** WhatsApp (`WhatsappModule`, the message pipeline,
the chat history source icon). Not the operator-wide token, and not
Incoming chats.

## User-flow

Journeys **J-TG-1**, **J-TG-2**, and **J-TG-3** in [`00_master_plan.md`](./00_master_plan.md) §1.

A person pastes their own BotFather token on Channels, opens the bot,
and sends `/start` with the pairing code. The thread appears in the
normal chat history with a Telegram icon. Disconnect is one click on
that card. The sentence says new replies stop and history stays.

Incoming chats stays the inbox for chats other people share. WhatsApp
and email never landed there, and Telegram does not either.

## Goal

This is a core `FeatureModule`, not a new chat controller and not
OpenClaw. The channel-plugin seam from the archived plugin plan was
never built, so a `plugins/telegram/` package would be the larger
patch.

**Do**

1. FeatureModule, default off. Flag off ⇒ no card, no webhook, no teaser.
2. Each user connects their own bot. The token is encrypted in the
   credential vault. The first `/start <code>` pairs that bot to the
   owner's Telegram account. Anyone else gets one sentence and nothing
   is stored.
3. Inbound update is acknowledged immediately, deduplicated on
   `update_id`, and handled in the worker through the same message
   pipeline WhatsApp uses. The reply goes out with Bot API `sendMessage`.
4. The thread shows in the chat history with source Telegram. The card
   names the owner, who else can talk to the bot, what replies use, how
   to stop, and which bot it is. "Open chat" on the card opens the thread.
5. Disconnect on the same card. Consequence is one sentence.
6. If `APP_URL` is not a public https address, say the webhook needs a
   public URL and name `APP_URL`. No raw Telegram error in the UI.
7. Five locales for every new string. The bot token never ships in the
   frontend bundle and is never returned by the API.

**Do not**

- Fork the chat stack.
- Put the thread under Incoming chats.
- Add tools, computer use, a skill runner, long polling, voice, or
  web-to-Telegram mirroring in this sprint.
- Require the open plugin marketplace.

## Exit criteria

1. J-TG-1, J-TG-2, and J-TG-3 walked in the browser, including a real or stubbed webhook (U10).
2. The thread is visible in the chat history within ten seconds of the inbound message (U2).
3. Disconnect copy states that replies stop and history stays, in all five locales (U3).
4. Empty (no token), error (no public URL or invalid token), and flag-off states are done. Flag off removes the channel (U5, U8, U11).
5. Light, dark, and 320 px on the channel card (U9).

## Class

Channel screen: `ota-candidate`. Webhook and module: `backend-only`.
New paths go on the matching allow-list in
`.github/mobile-impact-policy.json` in the same PR.
`backend/**` and `frontend/src/**` already cover them.
