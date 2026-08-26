export const getProviderIcon = (provider: string): string => {
  const providerLower = provider.toLowerCase()
  const providerCompact = providerLower.replace(/[\s_-]/g, '')

  // MUST come before the generic `openai` check: "openaicompatible" contains
  // "openai". These are self-hosted/local engines, not OpenAI. The chat avatar
  // renders a dedicated green-gradient component (see ServiceIcon.vue); this
  // icon is only the fallback for any consumer that uses the raw name.
  if (providerCompact.includes('openaicompatible')) {
    return 'mdi:server-network'
  } else if (providerLower.includes('openai')) {
    return 'simple-icons:openai'
  } else if (providerLower.includes('anthropic')) {
    return 'simple-icons:anthropic'
  } else if (providerLower.includes('google')) {
    return 'logos:google-icon'
  } else if (providerLower.includes('groq')) {
    return 'simple-icons:groq'
  } else if (providerLower.includes('ollama')) {
    return 'simple-icons:ollama'
  } else if (providerLower.includes('whisper')) {
    return 'mdi:microphone'
  } else if (providerLower.includes('cloudflare')) {
    return 'simple-icons:cloudflare'
  } else if (providerLower.includes('stability')) {
    return 'simple-icons:stabilityai'
  } else if (providerLower.includes('elevenlabs')) {
    return 'simple-icons:elevenlabs'
  } else if (providerLower.includes('runway')) {
    return 'mdi:runway'
  } else if (providerLower.includes('meta')) {
    return 'logos:meta-icon'
  } else if (providerLower.includes('microsoft')) {
    return 'logos:microsoft-icon'
  } else if (providerLower.includes('cohere')) {
    return 'simple-icons:cohere'
  } else if (providerLower.includes('mistral')) {
    return 'simple-icons:mistral'
  } else if (providerLower.includes('huggingface') || providerLower.includes('hugging face')) {
    return 'simple-icons:huggingface'
  } else if (providerLower.includes('xai')) {
    // simple-icons has no `xai` slug; `bxl:xai` is the official slash glyph and
    // is drawn with currentColor, so it themes like the other brand icons.
    return 'bxl:xai'
  } else if (
    providerCompact.includes('trustedtokens') ||
    providerLower.includes('trusted tokens')
  ) {
    // TNG TrustedTokens — German sovereign inference. No brand glyph in
    // simple-icons; use a shield that reads as "sovereign / secured".
    return 'mdi:shield-check'
  }

  return 'mdi:robot'
}

/**
 * Self-hosted engines that run on the operator's machine. They have no home
 * country — a German flag on Ollama made every local install look like a
 * Synaplan-DE product. The badge is a pin, not a flag.
 */
export const isLocalSelfHostedProvider = (provider: string): boolean => {
  const p = provider.toLowerCase()
  const compact = p.replace(/[\s_-]/g, '')

  return (
    p.includes('ollama') ||
    p.includes('whisper') ||
    p.includes('piper') ||
    p.includes('triton') ||
    p.includes('synaplan') ||
    compact.includes('openaicompatible')
  )
}

/**
 * Country/region flag shown as a small badge behind a provider's service icon.
 *
 * Uses the circular `circle-flags` Iconify set so every badge shares the same
 * round shape. Local/self-hosted engines get a pin instead. Providers without
 * a clear home country (or any unlisted service) fall back to the UN emblem.
 */
export const getProviderFlag = (provider: string): string => {
  const p = provider.toLowerCase()

  if (isLocalSelfHostedProvider(provider)) {
    // Pin on the existing brand icon (the Ollama llama, the Piper speaker…).
    // Not a country: these models run wherever the operator installed them.
    return 'mdi:map-marker'
  } else if (p.replace(/[\s_-]/g, '').includes('trustedtokens')) {
    // TNG TrustedTokens — German sovereign inference, actually hosted in DE.
    return 'circle-flags:de'
  } else if (p.includes('mistral')) {
    return 'circle-flags:fr'
  } else if (
    p.includes('openai') ||
    p.includes('anthropic') ||
    p.includes('google') ||
    p.includes('groq') ||
    p.includes('xai') ||
    p.includes('thehive') ||
    p.includes('the hive')
  ) {
    return 'circle-flags:us'
  }

  // HuggingFace and any unlisted service → world icon.
  return 'circle-flags:un'
}
