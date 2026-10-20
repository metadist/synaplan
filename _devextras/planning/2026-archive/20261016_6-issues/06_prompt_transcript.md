# Sprint 06 — What was sent to the assistant

**Issue:** [#2515](https://github.com/metadist/synaplan/issues/2515)
**Kind:** Feature. The issue has no acceptance criteria. This file is
those criteria. Do not widen them in the pull request.
**Class:** `ota-candidate`.
**Branch:** `dev/20261016-s6-prompt-transcript`
**Cut from:** `dev/20261016-s5-widget-chrome`. Pull request targets that
branch.
**Commit:** `feat(chat): show what was sent for this reply` — a `feat`
subject bumps the minor version. Do not use `feat` if the scope shrinks
to a backend-only spike with no UI; this sprint includes the UI.

## Goal

On a reply the signed-in person just received in their own chat, they
can open what the assistant was given: instructions, their message, and
earlier turns in that request. The widget, a shared link, and the logs
do not get that text. It lives for this visit. It is not stored.

## Decisions

| # | Decision |
| - | -------- |
| 1 | The surface is a dialog opened from the existing message info popover (`btn-message-info` / `popover-message-info` in `ChatMessage.vue`). It is not a new route and not a rail item. |
| 2 | The transcript is the request the provider was actually given, after that provider's own transformation. `ChatMessageAssembler` is the input, not the record. OpenAI drops history when `previous_response_id` is set and restores it on fallback (`OpenAIProvider::executeResponsesCreateStreamed`). Anthropic drops or merges turns. `ChatToolLoop::run()` adds tool results on later requests. Capture at the provider call, once per request in the turn, including a tool round and a fallback that resends history. Roles in the payload are `instructions`, `you`, `assistant`, mapped from the provider roles at that boundary. |
| 3 | The signed-in web chat delivers `providerMessages` on the live response only. Do not put it on the `complete` event that `SseWriter::send()` hands to `ChatRunRecorder`: that recorder serializes the whole event into the replay buffer, which would store the transcript. Strip it before `record()`, or send it on a path the recorder does not see. Replay and resume keep working. A reloaded chat has no transcript. |
| 4 | Widget turns, email, webhooks, MCP, shared-chat reads, and history reloads omit the field. A missing field means the dialog explains that nothing was recorded for this reply. |
| 5 | No migration, no new table, no column on `BMESSAGE`. Reloading the chat drops the transcript. Say so in the dialog. Persistence needs a schema review and is a later track. |
| 6 | Do not log the transcript. The existing "web search decision" style logs stay free of message text added by this sprint. |
| 7 | Redact before the payload is built. Replace a token that looks like a bearer secret or an `sk_` / `sk-` key with `…`. Cap the whole transcript at 32 000 characters. When the cap hits, the dialog says the rest was left out. |
| 8 | Copy is the table below, in all five locales, in the namespace that already owns the info popover (`chatMessage`). Review this table before editing the Vue file. |

### Copy

| Key | en | de | es | fr | tr |
| --- | -- | -- | -- | -- | -- |
| `whatWasSent` | What was sent | Was gesendet wurde | Qué se envió | Ce qui a été envoyé | Gönderilen metin |
| `whatWasSentTitle` | What was sent | Was gesendet wurde | Qué se envió | Ce qui a été envoyé | Gönderilen metin |
| `whatWasSentBody` | This is the text your assistant received for this reply, including its instructions. | Das ist der Text, den Ihr Assistent für diese Antwort bekommen hat, einschließlich der Anweisungen. | Este es el texto que recibió su asistente para esta respuesta, incluidas las instrucciones. | Voici le texte que votre assistant a reçu pour cette réponse, y compris les instructions. | Bu, asistanınızın bu yanıt için aldığı metindir, talimatlar dahil. |
| `whatWasSentInstructions` | Instructions | Anweisungen | Instrucciones | Instructions | Talimatlar |
| `whatWasSentYou` | You | Sie | Usted | Vous | Siz |
| `whatWasSentAssistant` | Assistant | Assistent | Asistente | Assistant | Asistan |
| `whatWasSentSession` | This stays on this screen until you leave the chat. It is not saved. | Das bleibt auf diesem Bildschirm, bis Sie den Chat verlassen. Es wird nicht gespeichert. | Esto permanece en esta pantalla hasta que salga del chat. No se guarda. | Ceci reste à l'écran jusqu'à ce que vous quittiez la discussion. Ce n'est pas enregistré. | Sohbetten ayrılana kadar bu ekranda kalır. Kaydedilmez. |
| `whatWasSentTruncated` | The rest is left out because the text is too long. | Der Rest fehlt, weil der Text zu lang ist. | El resto se omite porque el texto es demasiado largo. | La suite est omise, car le texte est trop long. | Metin çok uzun olduğu için devamı gösterilmez. |
| `whatWasSentEmpty` | Nothing was recorded for this reply. | Für diese Antwort wurde nichts aufgezeichnet. | No se registró nada para esta respuesta. | Rien n'a été enregistré pour cette réponse. | Bu yanıt için bir kayıt yok. |

Placeholders: none. Do not add a `{model}` until the product asks for it.

The dialog is a stack of blocks, not a left/right page. Instructions and
"You" are the text we sent. "Assistant" is a prior reply included in the
request. That matches the issue's wish to see both sides, and it stays
readable at 320 px. A two-column layout that overflows on a phone is a
reject.

Buttons: `btn-secondary px-4 py-2.5 text-sm font-medium` for the opener
inside the popover if it is a button, and `btn-secondary` for Close.
The dialog comes from the existing dialog pattern (`useDialog` is for
confirm/prompt; a rich transcript is a small component, not `prompt()`).
No native `alert`. Fields: this sprint has none.

Primary copy does not say LLM, prompt, system role, payload, or SSE.

## User-flow

Journey **J-PT-1**.

1. The signed-in person sends a message in Chat and waits until the reply
   finishes.
2. They open the info control on that reply and choose "What was sent".
3. The dialog shows the body sentence, then Instructions, You (their
   sentence), and any earlier assistant turn that was included. The
   session sentence is visible.
4. They close the dialog. The reply is unchanged.
5. They reload the chat, open the same reply's info control. The dialog
   says nothing was recorded for this reply.
6. On a widget and on a shared link, the control is absent.

Who owns this: the signed-in person, for their own reply. Who else: nobody.
What it touches: nothing is written. How to stop: close the dialog; leave
the chat and it is gone. Where it came from: this reply.

## The change

Keep it small. Suggested shape, names adjustable to the folder they land in:

| Piece | Responsibility |
| ----- | -------------- |
| A value object, for example `App\Model\ProviderTranscript` | `fromMessages(array $messages): self`, `redact()`, `cap(int $max)`, `toArray(): list<array{role: string, text: string}>`. No I/O. |
| The provider call for the signed-in web chat | Build the transcript from the messages about to be sent, after the provider's reduction, merge, and tool-round additions. One capture per request. |
| Widget / shared / history serializers | Do not add the key. |
| OpenAPI on that complete event or the message schema the frontend already parses | Optional property, generated Zod, no hand-written interface. |
| `ChatMessage.vue` | The opener stays on every assistant reply in the signed-in chat, including after a reload when no transcript was kept. The empty sentence is inside the dialog. The opener is absent in the widget and on a shared link because those views do not mount this action. |

`ChatMessage.vue` is already large. The dialog markup and the mapping
from `providerMessages` to blocks go in a new component under
`frontend/src/components/chat/`. The parent only decides to show the
button. Do not add a feature flag. The control is absent when there is
no signed-in chat message to attach it to (U11 is satisfied by the
widget and the shared link never mounting it).

## Test first

Backend, PHPUnit:

- Messages containing `Bearer abc.def` and `sk-test-secret` become text
  with those secrets replaced, roles mapped, order kept.
- Over 32 000 characters, the array is capped and the truncated flag is set.
- A widget payload has no `providerMessages` key.
- A signed-in live response includes the key, and the recorded replay
  event for that turn does not. Resume still replays the answer.
- OpenAI with `previous_response_id`, and a tool round, record the
  messages that were sent on that request, not the assembler array from
  before the reduction.

Frontend, Vitest:

- With three blocks, the dialog shows Instructions, You, Assistant, and
  the session sentence.
- With an empty list, the empty sentence is shown and no role heading.
- `ChatWidget` does not render `what was sent` (case-insensitive) for an
  assistant message. This can live next to the sprint 04 widget test.

No Playwright unless the complete-event contract is easier to prove
there. Unit tests plus J-PT-1 are the gate. If a stable TestProvider
reply already runs in `@ci` chat specs, one assertion that the button
appears after `message-done` is worth adding to that spec. Do not start
a new spec file for a single button if an existing chat spec is the
natural home.

## Quality gate

```bash
make ci-local
make -C frontend generate-schemas
docker compose exec -T frontend npm run check:types
make test-e2e
node scripts/mobile-impact.mjs --base <parent> --head HEAD
```

`make ci-local` includes lint, phpstan, deptrac, PHPUnit, frontend lint,
and Vitest. `check:types` after schema generation is still required.
Deptrac: the value object lives in `App\Model`, not in `App\Entity`, and
it does not import a service.

Walk J-PT-1 in the browser, light and dark, 320 px, and one non-English
locale (the other four are the parity test). STATUS records the walk.

Then the loop: push, pull request into the sprint 05 branch, Copilot,
comments addressed, CI green.

## Exit

1. J-PT-1 walked (U10).
2. The control is on the reply the person just received, inside the info
   popover they already use (U2). Ten seconds from the reply.
3. Close dismisses the dialog and writes nothing. The session sentence
   says it is not saved (U3).
4. Empty, truncated, and the normal transcript are three sentences (U8).
   Widget and shared link do not show the control (U11).
5. Light, dark, 320 px, five locales (U9). The dialog uses `surface-card`
   / existing dialog chrome, not a new colour.

## Out of scope

A database column or table. A page of live prompts for every user.
Showing another person's transcript to an admin. The widget. Editing the
instructions from this dialog. Logging the transcript "for support".
