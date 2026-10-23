const MODES = new Set(['jitsi', 'element', 'opendesk'])
const LANGUAGES = new Set(['auto', 'de', 'en', 'es', 'fr', 'tr'])

function clean(value) {
  return typeof value === 'string' ? value.trim() : ''
}

function flag(value, fallback) {
  const text = clean(value).toLowerCase()
  if (text === '') {
    return fallback
  }
  return text === '1' || text === 'true' || text === 'yes'
}

/**
 * Operator configuration. OpenDesk sets the same names from Helm.
 *
 * @param {NodeJS.ProcessEnv} env
 */
export function loadConfig(env) {
  const mode = clean(env.TRANSCRIBER_MODE || 'opendesk').toLowerCase()
  if (!MODES.has(mode)) {
    throw new Error('TRANSCRIBER_MODE must be jitsi, element, or opendesk')
  }

  const synaplanUrl = clean(env.SYNAPLAN_URL).replace(/\/$/, '')
  if (synaplanUrl === '') {
    throw new Error('SYNAPLAN_URL is required')
  }
  // Meeting notes (the synascriber plugin) only need the shared secret. The
  // API key is for the paths that act as a Synaplan user: Element voice
  // messages and Jitsi captions without the plugin.
  const apiKey = clean(env.SYNAPLAN_API_KEY)
  const transcriberSecret = clean(env.SYNAPLAN_TRANSCRIBER_SECRET)
  if (apiKey === '' && transcriberSecret === '') {
    throw new Error('SYNAPLAN_TRANSCRIBER_SECRET (meeting notes) or SYNAPLAN_API_KEY is required')
  }
  if (mode === 'element' && apiKey === '') {
    throw new Error('SYNAPLAN_API_KEY is required in element mode')
  }

  const language = clean(env.TRANSCRIBER_LANGUAGE || 'auto').toLowerCase()
  if (!LANGUAGES.has(language)) {
    throw new Error('TRANSCRIBER_LANGUAGE must be auto, de, en, es, fr, or tr')
  }

  const port = Number(env.TRANSCRIBER_PORT || 8095)
  if (!Number.isInteger(port) || port < 1 || port > 65535) {
    throw new Error('TRANSCRIBER_PORT must be a TCP port')
  }

  const commitAfterMs = Number(env.COMMIT_AFTER_MS || 8000)
  if (!Number.isFinite(commitAfterMs) || commitAfterMs < 1000 || commitAfterMs > 60000) {
    throw new Error('COMMIT_AFTER_MS must be between 1000 and 60000')
  }

  // synaScriber live captions: how often a speaker's window so far is previewed; 0 = off.
  const previewRaw = clean(env.PREVIEW_EVERY_MS)
  const previewEveryMs = previewRaw === '' ? 1500 : Number(previewRaw)
  if (!Number.isFinite(previewEveryMs) || (previewEveryMs !== 0 && (previewEveryMs < 500 || previewEveryMs > 10000))) {
    throw new Error('PREVIEW_EVERY_MS must be 0 or between 500 and 10000')
  }

  const jitsi = mode === 'jitsi' || mode === 'opendesk'
  const authToken = clean(env.TRANSCRIBER_AUTH_TOKEN)
  if (jitsi && authToken === '') {
    throw new Error('TRANSCRIBER_AUTH_TOKEN is required when Jitsi captions are on')
  }

  const matrixHomeserver = clean(env.MATRIX_HOMESERVER).replace(/\/$/, '')
  const matrixToken = clean(env.MATRIX_ACCESS_TOKEN)
  const matrixUserId = clean(env.MATRIX_USER_ID)
  const matrixReady = matrixHomeserver !== '' && matrixToken !== '' && matrixUserId !== ''
  const elementRequested = mode === 'element' || mode === 'opendesk'

  return {
    mode,
    host: clean(env.TRANSCRIBER_HOST || '0.0.0.0') || '0.0.0.0',
    port,
    jitsi,
    element: elementRequested && matrixReady && apiKey !== '',
    elementRequested,
    matrixReady,
    synaplanUrl,
    apiKey,
    transcriberSecret,
    model: clean(env.SYNAPLAN_STT_MODEL),
    language,
    authToken,
    commitAfterMs,
    previewEveryMs,
    matrixHomeserver,
    matrixToken,
    matrixUserId,
    matrixRoom: clean(env.NOTES_MATRIX_ROOM),
    nextcloudUrl: clean(env.NEXTCLOUD_URL).replace(/\/$/, ''),
    nextcloudUser: clean(env.NEXTCLOUD_USER),
    nextcloudPassword: clean(env.NEXTCLOUD_APP_PASSWORD),
    nextcloudFolder: clean(env.NOTES_NEXTCLOUD_FOLDER || '/Meetings') || '/Meetings',
    saveToSynaplan: flag(env.NOTES_SAVE_TO_SYNAPLAN, true),
  }
}
