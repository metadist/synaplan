# AI Act transparency

Synaplan is a tool an organisation deploys. This note says what the product does for the transparency duties in the EU AI Act, and what the organisation still does itself. It is not legal advice. Dates and the exact article text stay with counsel (decision D1 in the planning notes).

## Roles

- The **provider** of a general-purpose model is the company that trains and offers that model (OpenAI, Anthropic, a self-hosted model, and so on). Synaplan is not that provider.
- The **deployer** is the organisation that runs Synaplan for its people and for the people who write to its mail, WhatsApp, Telegram, or website widget.
- Synaplan is the software that deployer uses. It shows the notices and marks the files. It does not decide the organisation's policies.

## What Synaplan does

- **Article 50, machine-readable marking.** A generated image, audio, or video is stored once. Synaplan does not re-encode that original, so a mark the model provider already put on the file stays. When the content-credentials sidecar is configured, Synaplan asks it to sign the file. The private key stays on the sidecar. When the sidecar is absent, a PNG or JPEG gets an XMP fallback (`trainedAlgorithmicMedia`) and the file list says "Not signed".
- **Article 50, telling a person.** Mail adds the sentence to every AI reply. WhatsApp and Telegram say it on the first reply of a conversation and again after 24 hours of silence. The website widget shows the sentence, the company name when the owner set one, and the privacy link. The sentence cannot be removed.
- **Article 4, AI literacy.** "Using AI well" is ten short sections. An administrator can ask everyone to read it once. The card offers "Read now" and "Remind me tomorrow". Nobody is blocked. The switch is off until an administrator turns it on.

## What the deployer still does

- Tell people, in their own channels, who is responsible for the assistant.
- Decide which data may be pasted into a chat.
- Keep a person in the loop before a draft is sent, published, or filed.
- Put the signer key and certificate in the sidecar secret when content credentials must be trusted outside this server. The development certificate is created on the sidecar and is not a public trust anchor.

## Settings

| Setting | Where | Default |
| --- | --- | --- |
| AI notice text | Mail handler, WhatsApp, Telegram | A one-sentence default. Empty text is refused. |
| Company name on the widget | Widget configuration, next to the privacy URL | Empty. The AI sentence still shows. |
| `C2PA_SIGNER_URL` | Backend environment | Empty. Generated media are not signed. |
| `AI_LITERACY.REQUIRED` | People, "Ask everyone to read 'Using AI well'" | Off. |

The system status lists the content-credentials module as absent until `C2PA_SIGNER_URL` points at a healthy sidecar. The sentence there is: "Generated media are not signed. Add the signer to mark images, audio and video as AI-generated."

## Do not put an optimiser in front of uploads

Do not run an image optimiser, a resizer, or a format converter in front of `POST /api/v1/files/uploads/`. Those tools rewrite the bytes and drop the content credential. Thumbnails inside Synaplan are separate files. The original stays as it was stored.

## Content-credentials sidecar

The sidecar is the Compose service `c2pa-signer`, profile `c2pa`. It is not started by default.

```bash
docker compose --profile c2pa up -d
```

Then set `C2PA_SIGNER_URL=http://c2pa-signer:8787` on the backend. No host port is published. Only the backend should reach it.

`POST /sign` takes `{bytes, filename, model, parent}` and returns `{bytes}`. `POST /verify` may return `"watermark": null`. TrustMark is not in this release. It is planned before 2027-02-02 and must not be faked.

The development image installs c2patool 0.28.4 for linux amd64 and checks the archive against `097e9efaff765baf7738a40c640012419cad419ce3e1aa36cf68e0576309c971`. The sample keys inside that archive are not copied into the image. The process runs as uid 65532. When no key is mounted, the sidecar issues a short-lived RSA end-entity certificate and deletes the temporary CA key. The leaf key is mode `0600`. C2PA accepts the `emailProtection` extended key usage, so the development certificate uses that. The manifest algorithm defaults to `ps256`. Set `C2PA_ALG=es256` when the mounted certificate is EC. The key is passed to c2patool through `C2PA_PRIVATE_KEY` and `C2PA_SIGN_CERT`, not written into the manifest file. A certificate that is not in a public trust store still produces a marked file. c2patool may report that signature as untrusted.

An arm64 image has no c2patool binary. `/sign` then returns 503 and Synaplan keeps the file and uses the XMP fallback for PNG and JPEG. The Compose service sets `platform: linux/amd64` so an arm host still signs, through emulation. Mount a production key and certificate readable by uid 65532. Do not commit a private key. The published self-host Compose file does not start a signer until an image is published.

## Helm

The chart lives in [synaplan-charts](https://github.com/metadist/synaplan-charts), not in this repository. The values the chart should grow are:

- `c2paSigner.enabled`
- `c2paSigner.image`
- `c2paSigner.keySecretRef`
- `c2paSigner.certSecretRef`

A NetworkPolicy should allow only the backend to call the signer. This repository documents that contract. It does not ship the chart.
