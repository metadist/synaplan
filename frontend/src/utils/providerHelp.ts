/**
 * Help metadata for AI provider credentials and local Ollama.
 * URLs mirror ProviderKeyCatalog consoleUrl values on the backend.
 */

export type ProviderHelpId =
  | 'groq'
  | 'openai'
  | 'anthropic'
  | 'google'
  | 'mistral'
  | 'trustedtokens'
  | 'huggingface'
  | 'xai'
  | 'ollama'

export interface ProviderHelpMeta {
  id: ProviderHelpId
  /** External page where the user creates a key or downloads the tool */
  url: string
  /** true = download/setup flow (Ollama); false = create an API key */
  isDownload?: boolean
}

const BY_PROVIDER: Record<string, ProviderHelpMeta> = {
  groq: { id: 'groq', url: 'https://console.groq.com/keys' },
  openai: { id: 'openai', url: 'https://platform.openai.com/api-keys' },
  anthropic: { id: 'anthropic', url: 'https://console.anthropic.com/settings/keys' },
  google: { id: 'google', url: 'https://aistudio.google.com/apikey' },
  mistral: { id: 'mistral', url: 'https://console.mistral.ai/api-keys' },
  trustedtokens: { id: 'trustedtokens', url: 'https://trustedtokens.eu/' },
  huggingface: { id: 'huggingface', url: 'https://huggingface.co/settings/tokens' },
  xai: { id: 'xai', url: 'https://console.x.ai/' },
  ollama: { id: 'ollama', url: 'https://ollama.com/download', isDownload: true },
}

/** System Config field keys → help entry */
const BY_ENV_VAR: Record<string, ProviderHelpMeta> = {
  GROQ_API_KEY: BY_PROVIDER.groq,
  OPENAI_API_KEY: BY_PROVIDER.openai,
  ANTHROPIC_API_KEY: BY_PROVIDER.anthropic,
  GOOGLE_GEMINI_API_KEY: BY_PROVIDER.google,
  MISTRAL_API_KEY: BY_PROVIDER.mistral,
  TRUSTEDTOKENS_API_KEY: BY_PROVIDER.trustedtokens,
  HUGGINGFACE_API_KEY: BY_PROVIDER.huggingface,
  XAI_API_KEY: BY_PROVIDER.xai,
  OLLAMA_BASE_URL: BY_PROVIDER.ollama,
}

export function providerHelpByName(provider: string): ProviderHelpMeta | null {
  return BY_PROVIDER[provider.toLowerCase()] ?? null
}

export function providerHelpByEnvVar(envVar: string): ProviderHelpMeta | null {
  return BY_ENV_VAR[envVar] ?? null
}
