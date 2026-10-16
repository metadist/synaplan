import { clientId as makeClientId } from './clientId.js'

async function readJson(response) {
  const text = await response.text()
  if (!text) {
    return {}
  }
  try {
    return JSON.parse(text)
  } catch {
    return { text }
  }
}

export function createSynaplanClient({ baseUrl, apiKey, fetchImpl = fetch }) {
  const root = baseUrl.replace(/\/$/, '')

  async function request(path, options = {}) {
    const headers = new Headers(options.headers || {})
    headers.set('Authorization', `Bearer ${apiKey}`)
    const response = await fetchImpl(`${root}${path}`, { ...options, headers })
    const body = await readJson(response)
    if (!response.ok) {
      const message = body?.error?.message || body?.message || body?.error || `Synaplan returned ${response.status}`
      const error = new Error(typeof message === 'string' ? message : `Synaplan returned ${response.status}`)
      error.status = response.status
      throw error
    }
    return body
  }

  return {
    async transcribeOgg({ meetingId, speaker, audio, model, language }) {
      const payload = {
        client_id: makeClientId('jitsi', meetingId, speaker),
        encoding: 'ogg',
        sample_rate: 48000,
        channels: 1,
        commit_after_bytes: 8000000,
      }
      if (model) {
        payload.model = model
      }
      if (language && language !== 'auto') {
        payload.language = language
      }
      const created = await request('/v1/audio/transcriptions/sessions', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
      })
      try {
        const result = await request(`/v1/audio/transcriptions/sessions/${encodeURIComponent(created.id)}/audio?commit=true`, {
          method: 'POST',
          headers: { 'Content-Type': 'audio/ogg' },
          body: audio,
        })
        const segments = Array.isArray(result.segments) ? result.segments : []
        const last = segments.length ? segments[segments.length - 1].text : ''
        return {
          text: String(last || '').trim(),
          language: result.language || language || 'auto',
        }
      } finally {
        await request(`/v1/audio/transcriptions/sessions/${encodeURIComponent(created.id)}`, {
          method: 'DELETE',
        }).catch(() => {})
      }
    },

    async transcribeFile({ client, filename, bytes, contentType, model, language }) {
      const form = new FormData()
      form.append('file', new Blob([bytes], { type: contentType || 'application/octet-stream' }), filename || 'note.bin')
      form.append('client_id', makeClientId('matrix', client))
      if (model) {
        form.append('model', model)
      }
      if (language && language !== 'auto') {
        form.append('language', language)
      }
      const body = await request('/v1/audio/transcriptions', { method: 'POST', body: form })
      return {
        text: String(body.text || '').trim(),
        language: body.language || language || 'auto',
      }
    },

    saveNote(note) {
      return request('/api/v1/opendesk/meeting-notes', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(note),
      })
    },
  }
}
