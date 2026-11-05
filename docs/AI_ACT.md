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
| `C2PA_SIGNER_TOKEN` | Backend, worker and scheduler environment | Required when the signer is enabled. |
| `AI_LITERACY.REQUIRED` | People, "Ask everyone to read 'Using AI well'" | Off. |

The system status lists the content-credentials module as absent until `C2PA_SIGNER_URL` and `C2PA_SIGNER_TOKEN` are set and the signer answers. The sentence there is: "Generated media are not signed. On a linux amd64 server the mark runs inside Synaplan when it is switched on."

## Do not put an optimiser in front of uploads

Do not run an image optimiser, a resizer, or a format converter in front of `POST /api/v1/files/uploads/`. Those tools rewrite the bytes and drop the content credential. Thumbnails inside Synaplan are separate files. The original stays as it was stored.

## Content credentials inside Synaplan

There is no extra container. The signer starts inside the backend, the worker and the scheduler when both of these are set:

```bash
C2PA_SIGNER_URL=http://127.0.0.1:8787
C2PA_SIGNER_TOKEN=<a long random token>
```

It listens on `127.0.0.1` only. Requests to `/sign` and `/verify` require `Authorization: Bearer <C2PA_SIGNER_TOKEN>`. Leave the URL empty to keep signing off. Do not point the URL at another host from this install: a URL that is not localhost does not start the local process.

`POST /sign` takes `{bytes, filename, model, parent}` and returns `{bytes}`. `POST /verify` may return `"watermark": null`. TrustMark is not in this release.

The app image compiles c2patool 0.28.4 in a Debian bookworm Rust stage from the source archive (`2ed01103cdcb65184dfcaa6f522beac4c715291b51a44e503b572fa9b5255aba`) so the binary links against the bookworm libc (`glibc 2.36`) shipped in the base image. The sample keys inside that archive are not copied into the image. The process runs as uid 65532. When no key is mounted at `/certs`, it issues a short-lived RSA end-entity certificate and deletes the temporary CA key. That development certificate is not a public trust anchor. Do not use it on web.synaplan.com. The leaf key is mode `0600`. C2PA accepts the `emailProtection` extended key usage, so the development certificate uses that. The manifest algorithm defaults to `ps256`. Set `C2PA_ALG=es256` when the mounted certificate is EC. c2patool receives the key through `C2PA_PRIVATE_KEY` and `C2PA_SIGN_CERT` inside the signer process only. Those variables are not set on PHP. A certificate that is not in a public trust store still produces a marked file. c2patool may report that signature as untrusted.

An arm64 image has no c2patool binary. The signer then stays off and PNG and JPEG still get the XMP note. A Mac development machine is arm64, so it does not sign. Production linux amd64 does. Mount a production key and certificate at `/certs/key.pem` and `/certs/cert.pem`, mode `0400`, owner uid 65532. Do not commit a private key. Do not put the key in the environment.

## Helm

The chart lives in [synaplan-charts](https://github.com/metadist/synaplan-charts) ([#106](https://github.com/metadist/synaplan-charts/pull/106)), not in this repository. It is off by default. Turning it on mounts the key into the Synaplan pods and sets `C2PA_SIGNER_URL` to `http://127.0.0.1:8787`. There is no second Deployment, no host port and no Ingress. The signer binary ships in the Synaplan image. It is linux amd64 only. An arm64 pod does not sign and uses the XMP note for PNG and JPEG.

- `c2paSigner.enabled`
- `c2paSigner.keySecretRef` — Secret data key `key.pem`, copied to `/certs/key.pem`
- `c2paSigner.certSecretRef` — Secret data key `cert.pem`, copied to `/certs/cert.pem`

An init container copies the two files into an empty volume, `chown 65532:65532`, `chmod 0400`. The website user cannot read them. The chart creates the bearer token unless `tokenSecretRef` names an existing Secret. When the files are present, the signer does not write a development key.
