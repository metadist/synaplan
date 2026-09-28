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
  const apiKey = clean(env.SYNAPLAN_API_KEY)
  if (synaplanUrl === '' || apiKey === '') {
    throw new Error('SYNAPLAN_URL and SYNAPLAN_API_KEY are required')
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

  const matrixHomeserver = clean(env.MATRIX_HOMESERVER).replace(/\/$/, '')
  const matrixToken = clean(env.MATRIX_ACCESS_TOKEN)
  const matrixUserId = clean(env.MATRIX_USER_ID)
  const matrixReady = matrixHomeserver !== '' && matrixToken !== '' && matrixUserId !== ''
  const elementRequested = mode === 'element' || mode === 'opendesk'

  return {
    mode,
    host: clean(env.TRANSCRIBER_HOST || '0.0.0.0') || '0.0.0.0',
    port,
    jitsi: mode === 'jitsi' || mode === 'opendesk',
    element: elementRequested && matrixReady,
    elementRequested,
    matrixReady,
    synaplanUrl,
    apiKey,
    model: clean(env.SYNAPLAN_STT_MODEL),
    language,
    authToken: clean(env.TRANSCRIBER_AUTH_TOKEN),
    commitAfterMs,
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
