# Synaplan Demo — client surfaces (step F4)

**Goal.** A fresh install can chat with one click on the Synaplan Demo
(module `freeapi`, off by default), sees at all times that it runs on a
shared, limited Demo, and moves to its own AI provider without losing
anything. Flag off ⇒ nothing of this exists (U11).

The backend half shipped with #2563 (gateway seams) and #2564 (client
module: activate, leave, auto-leave, fake server). This step adds the HTTP
surface and the UI. Readiness ("Still setting up" pill, `/setup/status`,
service rows) needs the service list of step F2 and follows after it.

## User-flow

Journeys (walked in the browser before each UI PR merges, U10):

- **J-DEMO-1 — First chat on a fresh install.** An admin logs in to an
  install without any AI provider. Instead of "No AI provider configured"
  she sees the welcome screen with three tiles (Synaplan Demo preselected,
  Ollama, own key), reads the consent sentence, clicks **Start with
  Synaplan Demo**. The chat answers. The banner says she is on the shared
  Demo and how many messages are left today; she hides it, it becomes a
  pill; the pill opens the card (limits, end date, what is sent, who
  started it). She adds a Groq key under AI setup: one toast says the Demo
  was left and her Groq models apply, with **Undo**. Undo brings the Demo
  back. **Leave the Demo** on the card restores her previous settings and
  the welcome screen returns.
- **J-DEMO-2 — Limits.** A turn refused by the Demo (daily allowance used,
  busy network, fully used for today, Demo ended, paused, stopped, version
  too old, not included) ends with one sentence that says no answer was
  generated and names the recovery. The message is kept and can be retried.
  Nothing stays on "thinking".
- **J-DEMO-3 — Non-admin colleague.** A user on the same install sees the
  banner and the card (read-only: no Start, no Leave) and the same refusal
  sentences. Without the Demo and without a provider he keeps today's
  "Ask your administrator" screen.

Ten-second findability (U2): the Demo state is visible from the chat
itself — banner or pill above the thread, the composer's model label
("Synaplan Demo · …"), and the card from the pill. Admins also find it on
`/admin/features` (module row) and in AI setup (the `synaplan-demo`
endpoint).

## Pull requests

| PR | Scope | Class |
| -- | ----- | ----- |
| F4a | `GET /api/v1/freeapi/status`, `POST /api/v1/admin/freeapi/activate` (consent required), `POST /api/v1/admin/freeapi/leave`; who started it; refusal sentences per `freeapi_*` code on the chat turn (five locales); Getting-started `provider` item stays open while the Demo is active | backend-only (OpenAPI consumed by F4b) |
| F4b | Welcome screen replacing the no-provider screen for admins, also the provider step of `/setup`; consent sentence; start errors in plain words | ota-candidate |
| F4c | Banner → pill → card in the chat; Demo row in Quick model setup; auto-leave toast with Undo; Getting-started copy | ota-candidate |

## Copy

Consent (every start surface): "Your messages, and the files you ask
about, are sent to web.synaplan.com to be answered. No copy is kept there.
Usage counts and your install id are stored for 30 days."

Refusal sentences say what did not happen and name a recovery; they carry
no fixed numbers because the limits are tuned on the server.

## Exit criteria (UX contract §6)

1. J-DEMO-1, J-DEMO-2 and J-DEMO-3 walked in the browser against the fake
   server (`docker compose --profile freeapi-fake up -d`).
2. The Demo state is found in ten seconds from the chat (banner or pill,
   composer label) and from `/admin/features`.
3. Consequence copy for start, leave, auto-leave with undo, and every
   refusal code in all five locales.
4. Empty, error and flag-off states: welcome screen, refusal sentences,
   start failures; flag off ⇒ no tile, banner, pill, card or row, and the
   routes answer 404 `feature_not_configured`.
5. Dark, V2 and 320 px checked for the welcome screen, banner, pill and
   card.
