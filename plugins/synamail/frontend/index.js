/**
 * Synamail plugin — Synaplan web UI.
 *
 * The profiles themselves are created and updated from the Synamail Outlook
 * add-in; this panel is the transparency/privacy surface inside Synaplan:
 * see every stored contact profile and delete any of them.
 *
 * Mount contract (see synaplan PluginView.vue):
 *   export default { mount(el, { userId, apiBaseUrl, pluginBaseUrl }) }
 */

const FALLBACK_LANG = 'en'

const state = {
  el: null,
  ctx: null,
  t: (key) => key,
}

async function loadI18n(pluginBaseUrl) {
  const lang = (document.documentElement.lang || navigator.language || FALLBACK_LANG)
    .slice(0, 2)
    .toLowerCase()
  const load = async (code) => {
    const res = await fetch(`${pluginBaseUrl}/i18n/${code}.json?v=${Date.now()}`, {
      credentials: 'include',
    })
    if (!res.ok) throw new Error(`i18n ${code} unavailable`)
    return res.json()
  }
  let messages
  try {
    messages = await load(lang)
  } catch {
    messages = await load(FALLBACK_LANG).catch(() => ({}))
  }
  state.t = (key) => messages[key] ?? key
}

function api(path, options = {}) {
  const { userId, apiBaseUrl } = state.ctx
  return fetch(`${apiBaseUrl}/api/v1/user/${userId}/plugins/synamail${path}`, {
    credentials: 'include',
    headers: { Accept: 'application/json', ...(options.headers ?? {}) },
    ...options,
  })
}

function esc(s) {
  const div = document.createElement('div')
  div.textContent = String(s ?? '')
  return div.innerHTML
}

function fmtDate(iso) {
  if (!iso) return '—'
  const d = new Date(iso)
  return Number.isNaN(d.getTime()) ? '—' : d.toLocaleString()
}

async function deleteProfile(email) {
  const { t } = state
  if (!window.confirm(`${t('confirmDelete')} (${email})`)) return
  await api(`/profiles/${encodeURIComponent(email)}`, { method: 'DELETE' })
  await render()
}

async function render() {
  const { el } = state
  const { t } = state
  el.innerHTML = `<p class="txt-secondary">${esc(t('loading'))}</p>`

  let profiles = []
  let error = null
  try {
    const res = await api('/profiles')
    if (!res.ok) throw new Error(`HTTP ${res.status}`)
    const data = await res.json()
    profiles = data.profiles ?? []
  } catch (err) {
    error = err instanceof Error ? err.message : String(err)
  }

  const rows = profiles
    .map(
      (p) => `
      <li style="border:1px solid var(--border, #ddd); border-radius:8px; padding:12px; margin:0 0 10px;">
        <div style="display:flex; justify-content:space-between; gap:12px; align-items:baseline; flex-wrap:wrap;">
          <strong>${esc(p.name || p.email)}</strong>
          <span class="txt-secondary" style="font-size:0.85em;">
            ${esc(t('updated'))}: ${esc(fmtDate(p.updatedAt))} · ${esc(String(p.emailCount ?? 0))} ${esc(t('emails'))}
          </span>
        </div>
        <div class="txt-secondary" style="font-size:0.9em; margin:2px 0 6px;">${esc(p.email)}${p.org ? ` · ${esc(p.org)}` : ''}</div>
        <p style="margin:0 0 8px; white-space:pre-wrap;">${esc(p.summary || '')}</p>
        <button type="button" data-email="${esc(p.email)}"
          style="border:1px solid var(--border, #ccc); background:transparent; border-radius:6px; padding:4px 10px; cursor:pointer; color:#b42318;">
          ${esc(t('delete'))}
        </button>
      </li>`,
    )
    .join('')

  el.innerHTML = `
    <div>
      <h2 style="margin:0 0 4px;">${esc(t('title'))}</h2>
      <p class="txt-secondary" style="margin:0 0 16px;">${esc(t('intro'))}</p>
      ${error ? `<p style="color:#b42318;">${esc(error)}</p>` : ''}
      ${
        !error && profiles.length === 0
          ? `<p class="txt-secondary">${esc(t('empty'))}</p>`
          : `<ul style="list-style:none; margin:0; padding:0;">${rows}</ul>`
      }
    </div>`

  for (const btn of el.querySelectorAll('button[data-email]')) {
    btn.addEventListener('click', () => deleteProfile(btn.getAttribute('data-email')))
  }
}

export default {
  async mount(el, context) {
    state.el = el
    state.ctx = context
    await loadI18n(context.pluginBaseUrl)
    await render()
  },
}
