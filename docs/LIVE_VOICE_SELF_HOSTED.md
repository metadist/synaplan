# A live voice model you host yourself

Synaplan can speak through one OpenAI-Realtime-compatible websocket that you run or rent. The browser still talks only to Synaplan. The sidecar opens the upstream socket.

The model stays hidden until the websocket URL is set. Put the same values in `backend/.env` (the backend and the voice container both read it):

```
LIVE_VOICE_COMPATIBLE_WS_URL=wss://your-host/v1/realtime
LIVE_VOICE_COMPATIBLE_API_KEY=
LIVE_VOICE_COMPATIBLE_AUTH=bearer
LIVE_VOICE_COMPATIBLE_MODEL=
```

`LIVE_VOICE_COMPATIBLE_AUTH` is `bearer`, `api-key`, or `none`. An empty key sends no auth header. `LIVE_VOICE_COMPATIBLE_MODEL` replaces the seeded model id `qwen3-omni` in the session setup. Set it to the upstream model id or the Azure deployment name. Leave it empty to send `qwen3-omni`.

The conversation still uses the relay. Synaplan records how long it lasted and does not add a per-minute price on top of what you already pay the host. At most four of these sockets stay open at once (`LIVE_VOICE` / `CONCURRENCY_COMPATIBLE`).

Restart the backend and the voice container after changing the variables. `docker compose --profile voice up -d`.

## vLLM-Omni (Qwen3-Omni)

This is the open-weight speech-to-speech path. [vLLM-Omni](https://github.com/vllm-project/vllm-omni) serves Qwen3-Omni and can expose an OpenAI-Realtime websocket. It needs a large GPU. Point `LIVE_VOICE_COMPATIBLE_WS_URL` at that websocket, set `LIVE_VOICE_COMPATIBLE_MODEL` to the model id the server expects, and use `none` or `bearer` to match the server.

A spoken walk on a GPU box is still open. The adapter is covered by a local fixture that speaks the Realtime dialect.

## Azure Realtime

Azure speaks the same Realtime protocol. The URL carries the resource and the deployment, and the header is `api-key`:

```
LIVE_VOICE_COMPATIBLE_WS_URL=wss://YOUR_RESOURCE.openai.azure.com/openai/realtime?api-version=2024-10-01-preview&deployment=YOUR_DEPLOYMENT
LIVE_VOICE_COMPATIBLE_API_KEY=YOUR_AZURE_KEY
LIVE_VOICE_COMPATIBLE_AUTH=api-key
LIVE_VOICE_COMPATIBLE_MODEL=YOUR_DEPLOYMENT
```

GPT-Live on Azure is not confirmed. This is the Realtime API.

## Qwen Cloud

Qwen's realtime API is the same shape as OpenAI Realtime. Set the websocket URL from that account, `LIVE_VOICE_COMPATIBLE_AUTH=bearer`, and the model id Qwen expects.

## Kyutai Unmute

[Unmute](https://github.com/kyutai-labs/unmute) is a separate speech server (MIT). It can use an OpenAI-compatible text model, including one you run yourself. It is English and French only, and it wants an NVIDIA GPU with at least 16 GB. It is not the default and it is not wired in as a Synaplan provider. Run it beside Synaplan when you want that stack. The built-in self-hosted path above is the Realtime websocket, not Unmute.

## No GPU

Without a realtime model, the chat still reads an answer aloud and listens with the usual microphone. While that answer is playing, speech on a microphone the person has already allowed stops the reading and starts dictation. The page does not ask for the microphone just to do that.
