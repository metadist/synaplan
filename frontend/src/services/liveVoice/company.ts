const COMPANIES: Record<string, string> = {
  openai: 'OpenAI',
  google: 'Google',
  xai: 'xAI',
}

export function voiceCompany(provider: string): string {
  const known = COMPANIES[provider.toLowerCase()]
  if (known) return known
  const trimmed = provider.trim()
  return trimmed === '' ? 'the voice provider' : trimmed
}
