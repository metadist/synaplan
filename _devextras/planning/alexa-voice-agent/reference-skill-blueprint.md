# Alexa Skill Blueprint — concrete shapes for paths A1 and A2

> **Status:** Reference material for the plan in `README.md`. Nothing here is
> implemented in the repository. The JSON is copy-paste ready for the Alexa
> developer console; the code fragments are illustrative sketches, not production
> code, and would still have to be written to Synaplan's conventions
> (`final readonly` services, thin controllers, full OpenAPI annotations, PSR-12).

---

## 1. What Alexa sends and expects

### 1.1 Request envelope (trimmed to the fields that matter here)

```json
{
  "version": "1.0",
  "session": {
    "new": false,
    "sessionId": "amzn1.echo-api.session.<opaque>",
    "application": { "applicationId": "amzn1.ask.skill.<skill-id>" },
    "attributes": { "chatId": 4711 },
    "user": {
      "userId": "amzn1.ask.account.<stable per skill + Amazon account>",
      "accessToken": "<present only with OAuth account linking>"
    }
  },
  "context": {
    "System": {
      "application": { "applicationId": "amzn1.ask.skill.<skill-id>" },
      "user": { "userId": "amzn1.ask.account.<...>" },
      "device": { "deviceId": "amzn1.ask.device.<...>", "supportedInterfaces": {} },
      "apiEndpoint": "https://api.eu.amazonalexa.com",
      "apiAccessToken": "<used for the Progressive Response API>"
    }
  },
  "request": {
    "type": "IntentRequest",
    "requestId": "amzn1.echo-api.request.<...>",
    "timestamp": "2026-07-31T06:12:33Z",
    "locale": "de-DE",
    "intent": {
      "name": "AskSynaplanIntent",
      "confirmationStatus": "NONE",
      "slots": {
        "query": { "name": "query", "value": "what did the contract say about notice periods", "confirmationStatus": "NONE" }
      }
    }
  }
}
```

Request types to handle: `LaunchRequest`, `IntentRequest`, `SessionEndedRequest`
(no response body is spoken for the last one).

Fields worth naming explicitly, because the design in `README.md` §3.3 and §4.3
depends on them:

| Field | Use |
|-------|-----|
| `session.user.userId` | The pairing key. Stable per (skill, Amazon account); changes if the user disables and re-enables the skill, and differs between the Development and Live stages |
| `session.application.applicationId` | Must be checked against the skill IDs recorded at pairing time — this is the "request came from *your* skill" requirement |
| `session.attributes` | Echoed back by Alexa on every turn of the same session — carries `chatId` with zero server-side session state |
| `request.timestamp` | Replay protection, 150 s tolerance |
| `request.locale` | Maps to the Synaplan answer language |
| `context.System.apiAccessToken` + `request.requestId` | Required to send a progressive response |

### 1.2 Response envelope

```json
{
  "version": "1.0",
  "sessionAttributes": { "chatId": 4711 },
  "response": {
    "outputSpeech": { "type": "SSML", "ssml": "<speak>The notice period is three months to the end of a quarter.</speak>" },
    "reprompt": { "outputSpeech": { "type": "SSML", "ssml": "<speak>What else would you like to know?</speak>" } },
    "shouldEndSession": false,
    "card": { "type": "Simple", "title": "Synaplan", "content": "The notice period is three months to the end of a quarter." }
  }
}
```

- `shouldEndSession: false` + a `reprompt` keeps the conversation open for the next
  question. Alexa closes the session after silence.
- The pairing turn uses the same shape with the code in the `card`.
- With OAuth account linking (paths B/C only), a missing/invalid `accessToken` is
  answered with `"card": { "type": "LinkAccount" }`.
- Speech must be sanitized before it becomes SSML: `TtsTextSanitizer`
  (`backend/src/Service/TtsTextSanitizer.php`) already strips `[Memory:ID]` badges,
  markdown and think tags, and XML-special characters must be escaped.

### 1.3 Progressive response (buys a couple of seconds, does not extend the budget)

```http
POST https://api.eu.amazonalexa.com/v1/directives
Authorization: Bearer <context.System.apiAccessToken>
Content-Type: application/json

{
  "header": { "requestId": "<request.requestId>" },
  "directive": {
    "type": "VoicePlayer.Speak",
    "speech": "<speak>Let me look that up in your documents.</speak>"
  }
}
```

`204 No Content` on success. Max five per user request, and all of them plus the
final response must fit in the ~8 s window.

---

## 2. Interaction model (copy-paste, `en-US`)

Free-form input without a carrier phrase is only possible through **dialog slot
elicitation**, so the model below both offers carrier-phrase samples (for one-shot
"Alexa, ask <name> what my contract says") and marks the slot as elicitation-required
(for the open conversation after `LaunchRequest`).

```json
{
  "interactionModel": {
    "languageModel": {
      "invocationName": "my knowledge base",
      "intents": [
        { "name": "AMAZON.StopIntent", "samples": [] },
        { "name": "AMAZON.CancelIntent", "samples": [] },
        { "name": "AMAZON.HelpIntent", "samples": [] },
        { "name": "AMAZON.FallbackIntent", "samples": [] },
        {
          "name": "AskSynaplanIntent",
          "slots": [ { "name": "query", "type": "AMAZON.SearchQuery" } ],
          "samples": [
            "ask {query}",
            "about {query}",
            "look up {query}",
            "find out {query}",
            "tell me {query}",
            "what do my documents say about {query}"
          ]
        }
      ],
      "types": []
    },
    "dialog": {
      "delegationStrategy": "SKILL_RESPONSE",
      "intents": [
        {
          "name": "AskSynaplanIntent",
          "confirmationRequired": false,
          "prompts": {},
          "slots": [
            {
              "name": "query",
              "type": "AMAZON.SearchQuery",
              "confirmationRequired": false,
              "elicitationRequired": true,
              "prompts": { "elicitation": "Elicit.Slot.Query" }
            }
          ]
        }
      ]
    },
    "prompts": [
      {
        "id": "Elicit.Slot.Query",
        "variations": [
          { "type": "PlainText", "value": "What would you like to know?" },
          { "type": "PlainText", "value": "Go ahead, what's your question?" }
        ]
      }
    ]
  }
}
```

Notes:

- The console rejects a sample utterance consisting of only `{query}` — at least one
  carrier phrase is mandatory for `AMAZON.SearchQuery`.
- Two-word invocation names avoid the Alexa+ routing collision described in
  `README.md` §8 (a single person-name invocation was swallowed by Communications).
- Localized copies are needed per locale (`de-DE`, `en-GB`, `es-ES`, …). Turkish is
  not an Alexa locale at all.

---

## 3. Path A1 — Alexa-hosted skill calling the existing generic webhook

Works against **shipped** Synaplan features only: `POST /api/v1/webhooks/generic`
with an `sk_…` API key (`backend/src/Controller/WebhookController.php::generic`).

The user pastes this into the Alexa-hosted code editor (`index.js`), sets two
environment values, and deploys. Signature verification is *not* needed here —
Amazon's own Lambda invocation path handles authenticity. (Global `fetch` requires a
Node 18+ runtime; on older Alexa-hosted runtimes use `https.request` or add a small
HTTP client to `package.json`.)

```js
// Illustrative sketch — Alexa-hosted skill, ASK SDK v2.
const Alexa = require('ask-sdk-core')

const SYNAPLAN_URL = 'https://synaplan.example.com'   // the user's instance
const SYNAPLAN_KEY = 'sk_...'                          // Settings -> API Keys

async function askSynaplan(text) {
  const res = await fetch(`${SYNAPLAN_URL}/api/v1/webhooks/generic`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${SYNAPLAN_KEY}` },
    body: JSON.stringify({ message: text, channel: 'alexa' }),
  })
  if (!res.ok) throw new Error(`Synaplan returned ${res.status}`)
  const data = await res.json()
  return data?.response?.text ?? ''
}

const AskHandler = {
  canHandle: (h) =>
    Alexa.getRequestType(h.requestEnvelope) === 'IntentRequest' &&
    Alexa.getIntentName(h.requestEnvelope) === 'AskSynaplanIntent',
  async handle(h) {
    const query = Alexa.getSlotValue(h.requestEnvelope, 'query')
    const answer = await askSynaplan(query)
    return h.responseBuilder
      .speak(answer || 'I did not find anything on that.')
      .reprompt('What else would you like to know?')
      .getResponse()
  },
}
```

Known limitations of A1, all inherited from the generic webhook and already
documented in `n8n-integration-research.md`:

- **No conversation threading** — every call creates a fresh message, so follow-up
  questions lose context. A2 fixes this via `sessionAttributes`.
- The API key lives in the user's Lambda configuration.
- Latency is Alexa → Lambda → Synaplan → Lambda → Alexa, inside the same 8 s.

---

## 4. Path A2 — Synaplan as the skill endpoint (proposal)

The skill's endpoint is set to `https://<instance>/api/v1/webhooks/alexa`, "My
development endpoint has a certificate from a trusted certificate authority". No
Lambda, no AWS account, no JavaScript.

### 4.1 Verification steps the endpoint must perform, in order

1. Reject non-`POST`, or a body larger than a sane limit.
2. Normalize the `SignatureCertChainUrl` (strip dot segments, duplicate slashes,
   fragment) and require: scheme `https` (case-insensitive), host `s3.amazonaws.com`
   (case-insensitive), path starting with `/echo.api/` (**case-sensitive**), and port
   443 if a port is present.
3. Download the PEM chain (cache by URL; be resilient to the URL changing).
4. Validate the signing certificate: not expired, SAN contains
   `echo-api.amazon.com`, and the chain reaches a trusted root.
5. Base64-decode `Signature-256`, verify it against the **raw** request body with
   the certificate's public key (SHA-256, PKCS#1 v1.5). The legacy SHA-1 `Signature`
   header is deprecated.
6. Parse the body only after step 5 succeeded; check `request.timestamp` within
   **150 seconds**.
7. Check `session.application.applicationId` against the skill IDs stored for this
   deployment's Alexa links.
8. Any failure → **HTTP 400** with no body detail (Amazon's stated requirement).

Practical PHP notes for whoever implements it: read the raw body once
(`$request->getContent()`) before anything can normalize it, use `openssl_verify(…, OPENSSL_ALGO_SHA256)`,
and `openssl_x509_parse()` for the SAN check — no new dependency is required.

### 4.2 Turn handling

```text
LaunchRequest
  link known?   -> greet, open session, shouldEndSession=false
  link unknown? -> create pending link, speak + card the pairing code

IntentRequest / AskSynaplanIntent
  resolve User from session.user.userId
  RateLimitService::checkLimit($user, 'MESSAGES')      // 429 -> spoken quota message
  chatId = session.attributes.chatId ?? null
  build Message: BMESSTYPE='ALEX', BPROVIDX='ALEXA', BDIRECT='IN'
  MessageProcessor::process($message, options: [
      'channel'           => 'ALEXA',
      'skipSorting'       => true,           // save a model round-trip
      'fixed_task_prompt' => <link's prompt topic>,
      'rag_limit'         => <small>,
      'disable_memories'  => false,          // account-linked: memories are the point
  ])
  RateLimitService::recordUsage($user, 'MESSAGES', ['source' => 'ALEXA', ...])
  respond: SSML(TtsTextSanitizer::sanitize($answer)), sessionAttributes.chatId

SessionEndedRequest
  no speech; close the chat turn bookkeeping
```

### 4.3 Data the pairing table has to hold

`alexaUserId` (unique), `skillApplicationId`, `userId`, optional `widgetId` /
`promptTopic` / `modelId`, `locale`, `createdAt`, `lastSeenAt`, plus the pending-code
fields (`codeHash`, `expiresAt`, `attempts`). **Any such table is a schema change and
therefore an "Ask First" item**; the migration must be raw idempotent SQL
(`CREATE TABLE IF NOT EXISTS`) and must not touch `Schema $schema`, per the Galera
rules in `AGENTS.md`.

---

## 5. Verification checklist (for whoever builds this)

**Unit / integration (no Amazon account needed)**

- `AlexaRequestVerifier`: fixtures for a valid request, tampered body, stale
  timestamp (>150 s), expired certificate, certificate without the
  `echo-api.amazon.com` SAN, and each invalid `SignatureCertChainUrl` variant
  (`http://`, `notamazon.com`, `/EcHo.aPi/`, `:563`).
- Controller: unknown `applicationId` → 400; unknown `userId` → pairing response;
  paired user → answer with `sessionAttributes.chatId` preserved across two turns.
- Pairing: code single-use, expiry, attempt limit, no code reuse across users.
- Full gate before committing:
  `make lint && make -C backend phpstan && make test` plus
  `docker compose exec -T frontend npm run check:types` when the settings UI lands.
  If OpenAPI annotations change: `make -C frontend generate-schemas`, then re-run
  `vue-tsc`.

**Manual (needs an Amazon developer account)**

- Alexa developer console → **Test** tab: the simulator exercises the real signed
  request path against a public HTTPS endpoint, including multi-turn dialogs and
  progressive responses.
- Physical device: Echo registered to the same Amazon account, device locale
  matching a skill locale.
- **Measure the end-to-end turn latency** against the 8 s budget with realistic RAG
  volume, and confirm the degraded-answer path in `README.md` §4.4 triggers instead
  of timing out.
- Re-test with **Alexa+ enabled** on the account to confirm the invocation name is
  not shadowed (`README.md` §8, risk 1).
- If Synaplan-voice replies are enabled: verify the MP3 is MPEG version 2, 48 kbps,
  16000/22050/24000 Hz, under 240 s, and reachable over HTTPS **without**
  authentication.

**Local tunnelling during development**

The endpoint must be publicly reachable on 443 with a trusted certificate, so a
tunnel (cloudflared, ngrok) is the practical way to point a skill at a dev stack.
Free ngrok URLs change on restart and have to be re-entered in the console each
time.
