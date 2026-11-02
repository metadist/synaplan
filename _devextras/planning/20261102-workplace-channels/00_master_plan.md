# Workplace channels — the assistant in Microsoft Teams and Slack

Row 39. Umbrella: [`20261102-enterprise-readiness/`](../20261102-enterprise-readiness/00_master_plan.md) (C18).

Staff do not open a new tab for an assistant. They write in Teams (most
German companies) or Slack (tech companies). Copilot lives in Teams, and
Langdock and Onyx ship Slack bots. Synaplan already answers on WhatsApp,
Telegram, mail and the widget, with agents, knowledge, approvals and
Saved Tasks. This track brings the same assistant into Teams and Slack:

- direct messages;
- @mentions in channels;
- approvals as buttons;
- Saved Task results delivered there.

The design reuses the Telegram channel's shape (inbound service,
conversation mapping, delivery, formatter, feature module). It does not
invent a second chat pipeline.

## 1. Findings (checked 2026-11-02 against `main` and the vendor docs)

| # | Prio | Finding | Evidence |
| - | ---- | ------- | -------- |
| F1 | P1 | No Teams or Slack channel. Slack is used only as an incoming webhook to page a person for a widget handoff | `Service/Notification/SlackNotificationService.php`; `Module/Channel/` has `TelegramModule`, `WhatsappModule` |
| F2 | — | Microsoft archived the Bot Framework SDK (support ended 2025-12-31). New work uses the Teams SDK (C#, JS, Python) or the Microsoft 365 Agents SDK. The **Bot Connector protocol** (Activity JSON over HTTPS, JWT from Bot Framework, replies to `serviceUrl`) stays the transport | Microsoft Learn: "Legacy Tools and SDKs", Agents SDK migration guide |
| F3 | — | New Azure bots must be **single-tenant** or use a managed identity; multi-tenant bot creation ended 2025-07-31. Each customer therefore registers the bot in their own Entra tenant, which fits self-hosting | Microsoft Learn: "Create an Azure Bot resource" |
| F4 | — | Slack's agent surface: `chat.startStream` / `appendStream` / `stopStream` for streamed answers (10 min per stream), `agents.sessions.setStatus` (replacing `assistant.threads.setStatus`), thread-scoped sessions, Block Kit for buttons, Events API with a signing secret | docs.slack.dev "Developing an agent", "Agent sessions" |
| F5 | P1 | Telegram's code is the closest template and is already split into small classes (inbound, conversation, delivery, formatter, media, keyboard, typing pulse) | `Service/Telegram/` (30 files) |
| F6 | P2 | Saved Tasks deliver to planner channels from a catalog. Teams and Slack would be natural targets | `Service/Connection/PlannerChannelCatalog.php` |

## 2. What is fine — do not "fix" it

- The chat pipeline, agents, knowledge search, approvals and Saved Tasks. The channels call them as Telegram does.
- Channel identities as an account door (row 29 §3.1). A Teams or Slack user maps to an account through that door, not through a new one.
- AI disclosure through `AiDisclosure::forChannel()` (row 36 AT1).

## 3. Target model

### 3.1 Shared shape

| Piece | Telegram today | Teams | Slack |
| ----- | -------------- | ----- | ----- |
| Module | `TelegramModule` | `TeamsModule` | `SlackModule` |
| Inbound | `TelegramWebhookAcceptor` | `POST /api/v1/channels/teams/messages` (Bot Connector JWT checked against Bot Framework OpenID metadata, audience = app id, tenant pinned) | `POST /api/v1/channels/slack/events` (signing secret, 5 min replay window, `url_verification`) and `/interactions` |
| Identity | pairing code | Entra `aadObjectId` + tenant id → account (§3.2) | Slack user id → mail (`users:read.email`) → account (§3.2) |
| Conversation | `TelegramConversation` | `conversation.id` (1:1, group chat, channel thread) → `BMESSAGES` conversation | channel + `thread_ts` → conversation |
| Answer | formatted message | Markdown subset, streaming in 1:1 chats where Teams offers it, else typing then one message | `chat.startStream` → `appendStream` → `stopStream`; `agents.sessions.setStatus` |
| Buttons | inline keyboard | Adaptive Card `Action.Execute` | Block Kit `actions` |
| Files | media downloader | attachments in 1:1 via file consent card; channel files through Graph only if D4 | `files:read`, shared file URLs with the bot token |
| Proactive | — | stored `conversation.id` + `serviceUrl`, app installed for the user | `chat.postMessage` to the user's DM |

Every outbound call goes through `EgressHttpClient`. Credentials are
stored per install as connection secrets, the same way as the Telegram
bot token, never in env (the module's `ConfiguredBy` names the public
webhook base it needs).

### 3.2 Who is talking — identity mapping

1. **Directory match (default).** Teams sends `aadObjectId` and the tenant. When row 35 EI0 is merged, that is an external identity (`BSOURCE = entra:{tenantId}`) and matches the person who signs in with the company account. Before EI0, the match is by Entra UPN / mail through Graph `User.Read.All` (D3). Slack: the user's mail from `users.info`.
2. **Unknown person.** The bot answers once: "I don't know you yet. Sign in to Synaplan once with your company account, then write again." The answer carries a link. No account is created from a chat message (D2).
3. **Group policies apply** — allowed models, local only, masking (row 41) — because the answer runs as that person.

### 3.3 What the administrator does

Admin → **Channels** → Teams or Slack, one guided page each:

**Teams**

1. "Register the bot in your Microsoft tenant". The page lists the steps with copyable values: messaging endpoint, a single-tenant app registration, the client secret.
2. Paste the app id, tenant id and secret, then **Test**. The test sends a token request and shows "Microsoft accepted the bot's credentials."
3. **Download the Teams app package**. Synaplan builds `manifest.json` with icons and the bot id, zipped for upload in the Teams admin center or for side-loading.
4. Choose the agent that answers (default: the organisation's default assistant) and who may use it (everyone / groups).

**Slack**

1. **Create the Slack app from a manifest**. Synaplan generates the manifest: scopes, event subscriptions, the interactivity URL, and the app marked as agent.
2. Paste the bot token and signing secret, then **Test** (`auth.test`).
3. Agent and audience, as for Teams.

The channel card answers the five questions (U7):

- who installed it and when;
- which agent answers;
- who may use it;
- the last 10 conversations, with counts only and no content;
- **Turn off**, which keeps the install and stops answering, with the sentence "The bot stops answering in Teams. People keep their chat history in Synaplan."

### 3.4 In the conversation

- **Direct message:** a full assistant conversation that continues the same Synaplan conversation per Teams or Slack thread.
- **Channel:** answers only when @mentioned, in a thread, and uses the channel thread as context (last 20 messages, D5).
- **Approvals:** a tool that needs approval posts a card with **Approve** and **Reject**. The click runs through the same approval service with the person's identity (Q7 binding to tool and arguments).
- **Saved Task delivery:** "Send to Teams" and "Send to Slack" become planner channels. A weekly report lands in the person's DM.
- **Disclosure:** the first reply per conversation carries the AI sentence (row 36).
- **"Open in Synaplan"** under each answer opens the conversation in the web app with sources and files.

### 3.5 Implementation choice

The Bot Connector protocol is small enough to speak from PHP:

- validate the inbound JWT (Bot Framework OpenID metadata, keys cached);
- get the outbound token (client credentials against the customer's tenant, scope `https://api.botframework.com/.default`);
- `POST {serviceUrl}/v3/conversations/{id}/activities`.

WC0 proves this, including Teams streaming and single-tenant tokens,
before WC2 starts. If the spike fails on streaming or on a protocol
detail, the fallback is a small Teams SDK (TypeScript) sidecar that talks
to Synaplan over the out-of-process plugin contract of row 18 (D1).

## 4. Decisions

- [ ] **D1 (WC0).** Protocol in PHP (preferred: no new runtime, same tests). The fallback is a Teams SDK sidecar on row 18's contract. Decided by the WC0 result.
- [ ] **D2 (WC2, WC5).** No account is created from a Teams or Slack message; the person signs in once.
- [ ] **D3 (WC2).** Before row 35 EI0: map Teams users by UPN / mail via Graph (`User.Read.All` application permission), or require EI0 first.
- [ ] **D4 (WC3).** Channel files through Graph (`ChannelMessage.Read.All`, `Files.Read.All`) in the first release, or 1:1 attachments only.
- [ ] **D5 (WC2, WC5).** Channel context: the last 20 messages of the thread, never the whole channel.
- [ ] **D6.** Teams first, then Slack. German enterprise demand is Teams.

## 5. Journeys

| Id | Journey | Profile |
| -- | ------- | ------- |
| J-WC-1 | An admin opens Admin → Channels → Teams, follows the three steps, tests, downloads the package and uploads it in the Teams admin center. A colleague writes "Hi" to Synaplan in Teams and gets an answer with the AI sentence and **Open in Synaplan** | Admin, user |
| J-WC-2 | In the #sales channel someone writes "@Synaplan summarise the last offer for Müller GmbH". The answer comes in the thread, from the knowledge base, with sources | User |
| J-WC-3 | The assistant wants to send a mail through a tool that needs approval. Teams shows the card; the person presses **Approve**; the mail goes; the card says "Approved by Ada at 10:14. The mail was sent to müller@example.com." | User |
| J-WC-4 | A Saved Task "Weekly KPI report" is set to "Send to Teams". On Monday at 8:00 the report is in the person's Teams chat | User |
| J-WC-5 | Someone without a Synaplan account writes to the bot and gets the sign-in sentence; after signing in once on the web, the next message is answered | New user |
| J-WC-6 | The admin turns off the Slack channel from its card; the next mention gets no answer, and the card says when and by whom it was turned off | Admin |

## 6. Steps

| Step | Commit subject | Release class | Depends on | Gate |
| ---- | -------------- | ------------- | ---------- | ---- |
| WC0 | spike: Bot Connector from PHP (inbound JWT, single-tenant token, reply, Teams streaming, Adaptive Card action) and Slack agent streaming; results and the D1 recommendation in STATUS; no merge to `main` | no-app-impact | — | STATUS entry |
| WC1 | `refactor(channels): one channel contract for chat channels` — the interfaces Telegram already implements (inbound turn, conversation key, delivery, formatter, buttons) lifted into `Service/Channel/`, Telegram moved onto them with no behaviour change | backend-only | WC0 | `make ci-local` (Telegram tests unchanged; size ratchet: no growth) |
| WC2 | `feat(channels): answer in Microsoft Teams` — `TeamsModule`, inbound endpoint with JWT checks, identity mapping (D2, D3), 1:1 and @mention in threads (D5), formatter, typing and streaming, disclosure, **Open in Synaplan** | backend-only | D1, D2, D3, D5, WC1 | `make ci-local`; recorded Teams activities replayed in PHPUnit |
| WC3 | `feat(admin): connect Microsoft Teams in three steps` — Channels → Teams page, credential test, app package download, agent and audience, channel card with turn off; J-WC-1, J-WC-5 | ota-candidate + backend-only | WC2, D4 | `make ci-local && make test-e2e`; real-tenant walk logged in STATUS |
| WC4 | `feat(channels): approvals and Saved Task delivery in Teams` — Adaptive Card approvals through the approval service, Teams as a planner channel; J-WC-3, J-WC-4 | backend-only + ota-candidate | WC2 | `make ci-local && make test-e2e` |
| WC5 | `feat(channels): answer in Slack` — `SlackModule`, Events API + interactivity with signing secret, identity by mail, DMs and @mentions in threads, `chat.*Stream`, `agents.sessions.setStatus`, disclosure | backend-only | D2, D5, WC1 | `make ci-local` |
| WC6 | `feat(admin): connect Slack from a manifest` + approvals and Saved Task delivery in Slack — manifest generator, test, card; Block Kit approvals; Slack planner channel; J-WC-2, J-WC-6 | ota-candidate + backend-only | WC5 | `make ci-local && make test-e2e`; real-workspace walk logged in STATUS |
| WC7 | `docs(channels): Teams and Slack guides` in `docs/` and `synaplan-docs`, plus `feat(synaplan): channel webhook paths` in `synaplan-charts` (ingress paths `/api/v1/channels/*` documented, NetworkPolicy egress to Microsoft and Slack) | no-app-impact + chart | WC3, WC6 | `make lint-docs`; `make all` |

WC3, WC4 and WC6 carry the five exit bullets of `docs/UX_CONTRACT.md` §6
before their first `.vue`:

- **Journeys walked:** J-WC-1 to J-WC-6, logged in STATUS.
- **Ten-second findability:** Channels sits beside Telegram and WhatsApp in Admin; conversations from Teams appear in the person's chat list with a Teams mark.
- **Consequence copy in five locales:** turn off (§3.3 sentence); audience change ("Only people in Sales can talk to the bot. Others get the sign-in sentence.").
- **Empty, error and flag-off states:** no public webhook base → the module says what is missing; a failed credential test names the reason ("Microsoft says the secret has expired.").
- **Dark, V2 and 320 px.**

## 7. Risks

| Risk | Mitigation |
| ---- | ---------- |
| The PHP protocol path misses Teams features the SDK hides | WC0 first; the D1 fallback sidecar |
| Customers struggle with the Azure registration | Copyable values, a test button, the downloadable package; a screen-recorded guide in `synaplan-docs` |
| Channel context leaks data from a channel to people outside it | Answers stay in the thread where the mention happened; knowledge search runs as the asking person; D5 limits context |
| A webhook flood (spam workspace) | Signature checks first, then rate limit per tenant / team; unknown tenants refused |
| Slack deprecates more agent methods | Slack keeps a compatibility bridge; the Slack client is one class with the method names in one place |

## 8. Non-goals

- Copilot / Microsoft 365 agent store listing (a later step once WC3 is in production).
- Teams meetings (live transcription in calls) — openDesk meeting notes cover meetings for now.
- Google Chat, Mattermost, Rocket.Chat, Matrix — after WC7, on the same contract, if customers ask.

## 9. Change log

| Date | Change |
| ---- | ------ |
| 2026-11-02 | Plan written from Microsoft's Teams SDK and Bot Service documentation (archived Bot Framework SDK, single-tenant bots, proactive messages), Slack's agent APIs (streaming, sessions) and a read of the Telegram channel and planner channel code on `main`. No code changed. |
