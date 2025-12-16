export const getProviderIcon = (provider: string): string => {
  const providerLower = provider.toLowerCase()

  if (providerLower.includes('openai')) {
    return 'simple-icons:openai'
  } else if (providerLower.includes('anthropic')) {
    return 'simple-icons:anthropic'
  } else if (providerLower.includes('google')) {
    return 'logos:google-icon'
  } else if (providerLower.includes('groq')) {
    return 'simple-icons:groq'
  } else if (providerLower.includes('ollama')) {
    return 'simple-icons:ollama'
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
  }

  return 'mdi:robot'
}
