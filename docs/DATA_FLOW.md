# Where a message goes

This note is the source for the public trust center at
[www.synaplan.eu/trust](https://www.synaplan.eu/trust). It describes the hosted
platform at `web.synaplan.com`. It is not a promise about an installation
someone else runs.

Checked: 2026-11-06, against the privacy policy on synaplan.com and the
operator runbook for the hosted platform.

## Three paths

| Path | Where the chat is stored | Who else sees the text |
| ---- | ------------------------ | ---------------------- |
| Hosted, local model | Servers in Germany (Hetzner) | Nobody outside that hosting. The answer is produced there. |
| Hosted, cloud model | Same servers in Germany | The one model provider selected for that answer. Other providers do not receive it. |
| Self-hosted | The operator's installation | metadist sees the content only when that operator explicitly grants support access. |

Synaplan does not train models on chat or file content.

A cloud provider's own contract decides whether that provider stores the
API call. This note does not promise zero retention at the provider.

## Which cloud provider

The administrator selects the model. The product catalog records a
jurisdiction on each model row (`US`, `EU`, `DE`, `CN`, or `local`).
OpenAI, Anthropic, Google, xAI and Groq are US providers in that catalog.
Mistral is EU. A local engine such as Ollama stays on the server.

Turning on a model that answers from outside the EU is the customer's
instruction.

## Mail, payment, sign-in, channels

These are separate from the chat store:

- Account mail goes through Amazon SES in Ireland (`eu-west-1`). The mail
  carries the address and the message Synaplan sends. It does not carry the
  chat archive.
- Stripe sees payment data when someone pays for a hosted plan. It does not
  see chats.
- Google sees the sign-in only when someone chooses "Sign in with Google".
- WhatsApp and Telegram see the messages of that channel only when the
  customer turns the channel on.

## What this note does not claim

Disk encryption, a second copy of every search index, a finished pentest,
ISO 27001, BSI C5, signed images, and a public status page are not claims
of this note. The trust center lists them as open.

The AI Act behaviour of the product is in [`AI_ACT.md`](AI_ACT.md). How to
report a software weakness is in [`SECURITY.md`](../SECURITY.md).
