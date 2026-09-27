# Dev prompt: OpenAI-compatible image generation

Use this as the brief for issue [#2207](https://github.com/metadist/synaplan/issues/2207)
and for any follow-up that extends the same path. The app fix described in
"Contract" is what `/pic` needs when the image model is an
OpenAI-compatible endpoint.

## Bug

`/pic` and a plain "create an image of …" both fail when the configured
image model belongs to service `OpenAICompatible`:

```
Provider: openaicompatible
Error: image_generation provider 'openaicompatible' not found or unavailable.
Available: google, higgsfield, huggingface, openai, test, thehive, xai
```

The model row is already selected (import guesses `text2pic` for names
like `flux`, `sdxl`, `dall-e`, `gpt-image`). The provider only registered
chat, embeddings, and vision, so the registry rejected the call before
any HTTP request.

## Contract

`OpenAICompatibleProvider` implements `ImageGenerationProviderInterface`
and is tagged `app.ai.image_generation` / `openaicompatible`.

`generateImage()`:

1. Resolve the endpoint the same way chat does (`endpoint` on the model
   JSON, else the only configured endpoint).
2. `POST {base_url}/images/generations` with `model`, `prompt`, `n`,
   optional `size` (`^\d+x\d+$`), and `response_format: b64_json`.
3. Do **not** send DALL-E `quality` or `style`. Local gateways reject them.
4. If the gateway returns HTTP 400 and the body mentions `response_format`,
   retry once without that field.
5. Prefer `b64_json` (return a `data:image/png;base64,…` URL so the chat
   can save the file). Otherwise accept `url`, and turn a host-relative
   path into an absolute URL on the endpoint **origin** (not under `/v1`).
6. Refuse `options['images']`. Say that this endpoint draws a new picture
   from text and does not edit an attachment. Do not silently drop the
   attachment.
7. `createVariations` and `editImage` stay unsupported with the same kind
   of sentence.
8. Put the upstream HTTP status on the `ProviderException` (`status_code`
   context and the exception code) so the media error copy can tell a
   missing model (404) from a bad key (401).

Endpoint capability `text2pic` is allowed on the admin endpoint form and
is part of the default capability set for a newly saved endpoint.
Existing endpoints keep the capabilities already stored; an admin ticks
**text2pic** and re-saves to offer that tag in "add model".

## Local model to use

**LocalAI + `flux.1-schnell`.**

LocalAI is the local server that already speaks
`POST /v1/images/generations` (the same call this fix sends). Ollama's
`/v1` chat API does not serve that images route, so an Ollama base URL
will not draw pictures.

Suggested setup:

1. Run LocalAI (GPU image if you have one; the CPU image works and is slow).
2. Install the gallery model `flux.1-schnell` (fast few-step FLUX.1).
   Heavier alternatives on the same API: `flux.1-dev-ggml`, `stablediffusion`.
3. In Synaplan, add an OpenAI-compatible endpoint whose base URL is
   LocalAI's `/v1` (often `http://host:8080/v1`), auth none on localhost.
4. Tick **text2pic**. Import or add a model with provider id
   `flux.1-schnell`, service `OpenAICompatible`, tag `text2pic`.
5. Set that model as the image default, then send `/pic a hand pouring
   hot coffee into a cup`.

`flux.1-schnell` is the one to recommend: it is the gallery id LocalAI
documents for the Images API, it is the fast FLUX tier, and the tag
guesser already classifies `flux` as `text2pic`.

## Verify

- Unit: `OpenAICompatibleProviderTest` (request body, data URL, relative
  URL, `response_format` retry, HTTP error, attachment refusal) and
  `OpenAiCompatibleEndpointRegistryTest` (`text2pic` kept, `text2vid`
  dropped).
- App: with the endpoint above, `/pic` saves a PNG in the chat. With an
  attached image, the reply says the endpoint does not edit pictures.
- Light and dark are unchanged; this path adds no new user-facing
  sentence in the five locales (the failure copy already exists).

## Out of scope

- `/images/edits` (pic2pic) and video.
- Shipping a LocalAI service inside `docker-compose.yml`.
- Routing Ollama generate-image experiments through this provider.

---

# Feature prompts (vibe-code later)

Placement on the live order
([`20260925_roadmap.md`](./20260925_roadmap.md) §1): #2204, #2205, and
#2206 are row 1c. #2202 is row 1d (early, own PR). In-app transcription
is row 1b. Generate from
[`20260927-early-intake/`](./20260927-early-intake/README.md).

These are the latest feature issues. The same brief is the starting
note for that generation.

## #2206 — Published image, two files, `docker compose up`

**Journey.** A person who has never cloned the repo starts Synaplan from
a Docker GUI or a two-line terminal, opens the URL, and signs in.

**Do.** Keep the root `docker-compose.yml` as the source-build dev stack.
The portable contract already exists: `deploy/compose.yaml` pulls
`ghcr.io/metadist/synaplan:${SYNAPLAN_VERSION}` and
`deploy/selfhost.env.example` is the env file. Make that the documented
first run:

1. README leads with "copy these two files, set `SYNAPLAN_VERSION`,
   `docker compose up -d`". No `make`, no `build:`.
2. Pin an immutable release tag. Rollback is editing that one variable
   and pulling again. Do not tell people to use `latest`.
3. The compose a GUI can paste must not call `make` and must not require
   the git checkout.
4. Say, on the screen or in the two-file header comment, which URL to
   open and which env keys are required. Empty install, one sentence,
   one next action.

**Do not.** Shrink the 1 200-line dev compose into the production file.
Do not publish a second image name if `ghcr.io/metadist/synaplan` already
receives release tags.

## #2205 — Host ports from `.env`

**Journey.** A person whose machine already uses 5173 or 8000 changes
one env value, starts the stack, and opens the new URL. They never edit
the YAML.

**Do.** Production compose already binds
`${SYNAPLAN_HTTP_BIND:-127.0.0.1}:${SYNAPLAN_HTTP_PORT:-8000}:80`.
The dev file hardcodes `5173`, `8000`, `3307`, `11435`, `9999`, `8082`,
`1025`, `8025`, `8080`, `8443`, `6333`.

1. Give every **published host port** a `${NAME:-default}` with the
   current number as the default, so today's `docker compose up` does
   not move.
2. Document those names in `.env.example` (or `deploy/selfhost.env.example`
   for the production file) in one block, about the ports only — not
   twenty unrelated keys.
3. Internal container-to-container URLs stay on the container ports
   (`backend:80`, `db:3306`). Only the host side is configurable.
4. If the frontend URL is shown in the UI or boot page, it must use the
   configured host port, not a hardcoded `:5173`.

**Do not.** Make container ports configurable. Do not require a rebuild
to change a host port.

## #2204 — Quieter "-- Select Model --"

**Journey.** On the model settings page, a person sees which capability
already has a model and which is still the empty choice, in one glance,
in light and dark.

**Do.** The empty row is `config.aiModels.selectModel`
("-- Select Model --") in `AIModelsConfiguration.vue`, rendered as
`txt-secondary italic` inside `.dropdown-item`. That utility is the same
ink as the real model names' secondary line, so the placeholder shouts
as loud as a selection.

1. Scope a quieter style to that one placeholder row (the `btn-model-option`
   that selects `null`). Do not recolor `.dropdown-item` or `.txt-secondary`
   for the whole panel — overlays inherit those utilities.
2. Keep WCAG AA against the dropdown panel in light and dark (4.5:1 for
   the text). Italic alone is not the fix; contrast is.
3. The five locale strings already exist. Do not add a key.
4. Check 320 px: the row still fits, and the selected model name stays
   the louder line.

## #2202 — Telegram, like a chat channel

**Journey.** A person pastes a Telegram bot token, sends a message to
the bot, and finds the thread under Incoming chats. Turning the channel
off stops new replies and says so on the same row.

**Do.** This is a channel plugin, not a new core controller. The archived
plugin plan (`_devextras/planning/2026-archive/20260822-open-plugin-platform`)
already names Telegram as the pilot: manifest, inbound webhook, outbound
send, bot token in plugin config.

1. Inbound: Telegram update → the same message pipeline WhatsApp uses →
   reply via the Bot API `sendMessage`.
2. The thread shows up in Incoming chats with source Telegram, the bot
   name, and who else can see it.
3. Disconnect is one click on that channel row. Copy says replies stop
   and history stays.
4. No public URL yet: say that the webhook needs an internet-reachable
   `APP_URL`, with the one command or field that fixes it. Do not fail
   with a raw Telegram error.
5. Five locales for every new string. Flag off means the channel is
   absent, not a disabled button.

**Do not.** Fork the chat stack. Do not put the bot token in the frontend
bundle. Do not implement OpenClaw features (tools, computer use) inside
the channel — the channel only carries messages.
