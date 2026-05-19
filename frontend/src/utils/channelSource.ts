/**
 * Channel-source tokens that the backend stores in the `BPROVIDER` /
 * `provider_index` column of incoming and outgoing messages.
 *
 * The column is overloaded: outgoing assistant messages store the
 * real AI provider (e.g. `OpenAI`, `Anthropic`, `Groq`) but inbound
 * messages — and a handful of legacy outbound paths — store the
 * channel/source the message arrived through (e.g. `WHATSAPP`,
 * `EMAIL`, `WEB`, `widget`). When the UI surfaces this value as
 * "Provider" or "Model" the user sees redundant, semantically wrong
 * labels like `Model: WHATSAPP · Provider: WHATSAPP` — see issue #653.
 *
 * Keep this list in sync with `Service\WhatsAppService::setProviderIndex`,
 * `WebhookController::setProviderIndex` and similar call sites in the
 * backend. Tokens are compared case-insensitively so each value only
 * needs to appear once.
 */
const CHANNEL_SOURCE_TOKENS: ReadonlySet<string> = new Set([
  'whatsapp',
  'email',
  'web',
  'widget',
  'ai_widget',
  'wordpress',
  'human_operator',
  'system',
  'perf',
  'api',
  'unknown',
])

/**
 * Returns `true` when the given value is a channel/source identifier
 * rather than a real AI provider name. Used by the chat UI to filter
 * out values like `WHATSAPP` from the per-message metadata bar so the
 * label is consistent across channels (issue #653).
 */
export const isChannelSource = (value: string | null | undefined): boolean => {
  if (!value) {
    return false
  }
  return CHANNEL_SOURCE_TOKENS.has(value.toLowerCase())
}
