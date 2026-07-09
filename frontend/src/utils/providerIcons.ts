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
  }

  return 'mdi:robot'
}

/**
 * Country/region flag shown as a small badge behind a provider's service icon.
 *
 * Uses the circular `circle-flags` Iconify set so every badge shares the same
 * round shape. Providers without a clear home country (or any unlisted service)
 * fall back to the UN "world" emblem.
 */
export const getProviderFlag = (provider: string): string => {
  const p = provider.toLowerCase()

  if (p.replace(/[\s_-]/g, '').includes('openaicompatible')) {
    // Operator-defined, self-hosted endpoint — no fixed country. Use the
    // neutral "world" badge (and NOT the US flag the `openai` branch returns).
    return 'circle-flags:un'
  } else if (p.includes('ollama') || p.includes('piper') || p.includes('synaplan')) {
    // Ollama and the self-hosted Synaplan/Piper TTS are German-hosted.
    return 'circle-flags:de'
  } else if (p.includes('mistral')) {
    return 'circle-flags:fr'
  } else if (
    p.includes('openai') ||
    p.includes('anthropic') ||
    p.includes('google') ||
    p.includes('groq') ||
    p.includes('thehive') ||
    p.includes('the hive')
  ) {
    return 'circle-flags:us'
  }

  // HuggingFace and any unlisted service → world icon.
  return 'circle-flags:un'
}
