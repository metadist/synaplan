// Synafastbill plugin frontend.
//
// Vanilla-JS single-file module mounted by Synaplan's PluginView:
//   module.default.mount(el, { userId, apiBaseUrl, pluginBaseUrl, config })
//
// PluginView already renders the page title + description inside a surface-card,
// so this module renders only the plugin body: a FastBill connection card, a
// "try it" chat panel (posting to the same /chat endpoint the /fastbill chat
// command uses), and a help overview. Styling uses the app's theme tokens so it
// is correct in light, dark, and the V2 design.
//
// IMPORTANT: `pluginBaseUrl` ends in `/assets` (asset route). API endpoints live
// one level up, so we strip `/assets` to build the API base.

const FALLBACK_MESSAGES = {
  title: 'Synafastbill',
  subtitle: 'Talk to your FastBill account from the chat.',
  settings: {
    heading: 'FastBill connection',
    email: 'Account e-mail',
    apiKey: 'API key',
    apiKeyHint: 'Stored per user and masked. Leave blank to keep the current key.',
    currency: 'Default currency',
    vat: 'Default VAT %',
    unit: 'Default unit',
    language: 'Language',
    save: 'Save',
    testConnection: 'Test connection',
    saved: 'Settings saved.',
    testOk: 'Connection OK.',
    testFailed: 'Connection failed. Check the account e-mail and API key.',
    officialSite: 'Official FastBill website',
    apiDocs: 'Where do I find my API key?',
  },
  chat: {
    heading: 'Try it here',
    placeholder: 'e.g. create a new draft invoice for customer XYZ',
    send: 'Send',
    hint: 'You can also type /fastbill … directly in the Synaplan chat.',
  },
  help: {
    heading: 'Commands',
    createInvoice: 'Draft a new invoice (guided)',
    registerPayment: 'Match and register a payment from pasted bank text',
    showOverdue: 'List overdue invoices',
    help: 'Show this overview',
    safety: 'All write actions ask for confirmation first. Invoices are created as drafts.',
  },
}

const FASTBILL_URL = 'https://www.fastbill.com/'
const FASTBILL_API_DOCS_URL = 'https://apidocs.fastbill.com/'

const STYLE_ID = 'synafastbill-styles'

const STYLES = `
.sfb { display: flex; flex-direction: column; gap: 20px; color: var(--txt-primary); }
.sfb-card { background: var(--bg-card); border: 1px solid var(--border-light); border-radius: 14px; padding: 20px 22px; }
.sfb-card__head { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 4px; }
.sfb-card h2 { font-size: 1rem; font-weight: 600; margin: 0; }
.sfb-sub { color: var(--txt-secondary); font-size: 0.85rem; margin: 0 0 16px; }
.sfb-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 14px; }
.sfb-field { display: flex; flex-direction: column; gap: 6px; font-size: 0.78rem; color: var(--txt-secondary); }
.sfb-field input, .sfb-field select {
  background: var(--bg-chat); color: var(--txt-primary);
  border: 1px solid var(--border-light); border-radius: 9px;
  padding: 9px 12px; font-size: 0.9rem; width: 100%; box-sizing: border-box;
}
.sfb-field input::placeholder { color: var(--txt-secondary); opacity: 0.7; }
.sfb-field input:focus, .sfb-field select:focus {
  outline: none; border-color: var(--brand); box-shadow: 0 0 0 3px var(--brand-alpha-light);
}
.sfb-hint { color: var(--txt-secondary); font-size: 0.78rem; margin: 10px 0 0; }
.sfb-actions { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 16px; align-items: center; }
.sfb-btn {
  cursor: pointer; font-size: 0.88rem; font-weight: 500; border-radius: 9px;
  padding: 9px 16px; border: 1px solid var(--border-light);
  background: transparent; color: var(--txt-primary); transition: all 0.15s ease;
}
.sfb-btn:hover { border-color: var(--brand); color: var(--brand); }
.sfb-btn:disabled { opacity: 0.55; cursor: default; }
.sfb-btn--primary { background: var(--brand); border-color: var(--brand); color: #fff; }
.sfb-btn--primary:hover { background: var(--brand-hover); border-color: var(--brand-hover); color: #fff; }
.sfb-status { font-size: 0.82rem; margin-top: 10px; min-height: 1.1em; color: var(--txt-secondary); }
.sfb-status--ok { color: var(--brand); }
.sfb-status--err { color: #e0564d; }
.sfb-badge { font-size: 0.72rem; font-weight: 600; padding: 4px 10px; border-radius: 999px; white-space: nowrap; }
.sfb-badge--on { background: var(--brand-alpha-light); color: var(--brand); }
.sfb-badge--off { background: rgba(224, 86, 77, 0.14); color: #e0564d; }
.sfb-log {
  display: flex; flex-direction: column; gap: 10px;
  max-height: 360px; overflow-y: auto; padding: 4px 2px 2px;
}
.sfb-msg { max-width: 88%; padding: 10px 14px; border-radius: 14px; font-size: 0.9rem; line-height: 1.5; white-space: pre-wrap; word-wrap: break-word; }
.sfb-msg code { background: var(--brand-alpha-light); padding: 1px 5px; border-radius: 5px; font-size: 0.85em; }
.sfb-msg table { border-collapse: collapse; margin: 6px 0; font-size: 0.85em; }
.sfb-msg th, .sfb-msg td { border: 1px solid var(--border-light); padding: 3px 8px; text-align: left; }
.sfb-msg--user { align-self: flex-end; background: var(--brand); color: #fff; border-bottom-right-radius: 4px; }
.sfb-msg--bot { align-self: flex-start; background: var(--bg-chat); border: 1px solid var(--border-light); border-bottom-left-radius: 4px; }
.sfb-msg--confirm { border-color: var(--brand); box-shadow: 0 0 0 2px var(--brand-alpha-light); }
.sfb-msg--error { border-color: #e0564d; }
.sfb-chips { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 12px; }
.sfb-chip { cursor: pointer; font-size: 0.8rem; padding: 6px 12px; border-radius: 999px; border: 1px solid var(--border-light); background: transparent; color: var(--txt-secondary); }
.sfb-chip:hover { color: var(--brand); border-color: var(--brand); }
.sfb-links {
  display: flex; flex-wrap: wrap; gap: 18px; align-items: center;
  margin-top: 16px; padding-top: 14px; border-top: 1px solid var(--border-light);
  font-size: 0.8rem;
}
.sfb-link { color: var(--brand); text-decoration: none; }
.sfb-link:hover { text-decoration: underline; }
.sfb-help-list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 8px; }
.sfb-help-list li { display: flex; gap: 10px; align-items: baseline; font-size: 0.88rem; }
.sfb-help-list code { background: var(--brand-alpha-light); color: var(--brand); padding: 2px 8px; border-radius: 6px; white-space: nowrap; }
`

function injectStyles() {
  if (document.getElementById(STYLE_ID)) return
  const style = document.createElement('style')
  style.id = STYLE_ID
  style.textContent = STYLES
  document.head.appendChild(style)
}

function el(tag, attrs = {}, children = []) {
  const node = document.createElement(tag)
  Object.entries(attrs).forEach(([key, value]) => {
    if (key === 'class') node.className = value
    else if (key === 'html') node.innerHTML = value
    else if (key === 'text') node.textContent = value
    else node.setAttribute(key, value)
  })
  children.forEach((child) => node.appendChild(child))
  return node
}

function escapeHtml(text) {
  const div = document.createElement('div')
  div.textContent = text
  return div.innerHTML
}

// Minimal, safe Markdown rendering for bot replies: escape first, then apply a
// small allow-list (bold, inline code, and GitHub-style tables).
function renderMarkdown(text) {
  const lines = escapeHtml(text).split('\n')
  const out = []
  let table = null

  const flushTable = () => {
    if (!table) return
    const rows = table.filter((r) => !/^\s*\|?[\s:|-]+\|?\s*$/.test(r))
    const cells = rows.map((r) =>
      r
        .replace(/^\||\|$/g, '')
        .split('|')
        .map((c) => c.trim())
    )
    let html = '<table>'
    cells.forEach((row, i) => {
      const tag = i === 0 ? 'th' : 'td'
      html += '<tr>' + row.map((c) => `<${tag}>${c}</${tag}>`).join('') + '</tr>'
    })
    html += '</table>'
    out.push(html)
    table = null
  }

  for (const line of lines) {
    if (line.includes('|') && line.trim().startsWith('|')) {
      table = table || []
      table.push(line)
      continue
    }
    flushTable()
    out.push(line)
  }
  flushTable()

  return out
    .join('\n')
    .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
    .replace(/`([^`]+)`/g, '<code>$1</code>')
}

function mount(root, context = {}) {
  injectStyles()
  const originUrl = context.apiBaseUrl || ''
  const pluginBaseUrl = context.pluginBaseUrl || ''
  const assetBaseUrl = pluginBaseUrl
  // Endpoints live one level up from the asset route (.../plugins/x/assets).
  const apiRoot = pluginBaseUrl.replace(/\/assets\/?$/, '')
  const api = makeApi(originUrl, apiRoot)

  root.innerHTML = ''
  const container = el('div', { class: 'sfb' })
  root.appendChild(container)

  init(container, api, assetBaseUrl)
}

/**
 * API client mirroring the platform convention: cross-origin requests must send
 * the session cookie (`credentials: 'include'`), and a 401 triggers a single
 * token refresh + retry. Returns parsed JSON or throws Error(body.error).
 */
function makeApi(originUrl, apiRoot) {
  let refreshPromise = null

  const refresh = async () => {
    if (refreshPromise) return refreshPromise
    refreshPromise = (async () => {
      try {
        const res = await fetch(`${originUrl}/api/v1/auth/refresh`, {
          method: 'POST',
          credentials: 'include',
        })
        return res.ok
      } catch {
        return false
      } finally {
        refreshPromise = null
      }
    })()
    return refreshPromise
  }

  return async (path, opts = {}) => {
    const doFetch = () =>
      fetch(`${apiRoot}${path}`, {
        credentials: 'include',
        headers: { 'Content-Type': 'application/json', ...(opts.headers || {}) },
        ...opts,
      })

    let res = await doFetch()
    if (res.status === 401 && (await refresh())) {
      res = await doFetch()
    }
    if (!res.ok) {
      const body = await res.json().catch(() => ({}))
      throw new Error(body.error || `HTTP ${res.status}`)
    }
    return res.json()
  }
}

async function init(container, api, assetBaseUrl) {
  let settings = {}
  try {
    settings = await api('/config')
  } catch {
    // Render with empty defaults if config is unreachable.
  }

  const language = settings.default_language || 'en'
  const messages = await loadMessages(assetBaseUrl, language)

  container.appendChild(renderSettings(api, messages, settings))
  container.appendChild(renderTryPanel(api, messages, language))
  container.appendChild(renderHelp(messages))
}

async function loadMessages(assetBaseUrl, language) {
  try {
    const response = await fetch(`${assetBaseUrl}/i18n/${language}.json`, {
      credentials: 'include',
    })
    if (response.ok) return await response.json()
  } catch {
    // Fall back to embedded English strings.
  }
  return FALLBACK_MESSAGES
}

function renderSettings(api, messages, settings) {
  const t = messages.settings
  const card = el('section', { class: 'sfb-card' })

  const connected = Boolean(settings.email) && Boolean(settings.api_key_set)
  const badge = el('span', {
    class: `sfb-badge ${connected ? 'sfb-badge--on' : 'sfb-badge--off'}`,
    text: connected ? 'Connected' : 'Not connected',
  })
  card.appendChild(el('div', { class: 'sfb-card__head' }, [el('h2', { text: t.heading }), badge]))
  card.appendChild(el('p', { class: 'sfb-sub', text: t.apiKeyHint }))

  const email = el('input', { type: 'email', placeholder: t.email, value: settings.email || '' })
  const apiKey = el('input', {
    type: 'password',
    placeholder: settings.api_key_set ? '••••••••' : t.apiKey,
  })
  const currency = el('input', { type: 'text', value: settings.default_currency || 'EUR' })
  const vat = el('input', { type: 'text', value: settings.default_vat_percent || '19' })
  const unit = el('input', { type: 'text', value: settings.default_unit || 'hours' })

  const grid = el('div', { class: 'sfb-grid' }, [
    field(t.email, email),
    field(t.apiKey, apiKey),
    field(t.currency, currency),
    field(t.vat, vat),
    field(t.unit, unit),
  ])
  card.appendChild(grid)

  const status = el('p', { class: 'sfb-status' })
  const save = el('button', { class: 'sfb-btn sfb-btn--primary', text: t.save })
  const test = el('button', { class: 'sfb-btn', text: t.testConnection })

  save.addEventListener('click', async () => {
    setBusy([save, test], true)
    setStatus(status, '', '')
    try {
      await api('/config', {
        method: 'PUT',
        body: JSON.stringify({
          email: email.value,
          api_key: apiKey.value,
          default_currency: currency.value,
          default_vat_percent: vat.value,
          default_unit: unit.value,
        }),
      })
      apiKey.value = ''
      apiKey.placeholder = '••••••••'
      badge.className = 'sfb-badge sfb-badge--on'
      badge.textContent = 'Connected'
      setStatus(status, t.saved, 'ok')
    } catch (err) {
      setStatus(status, `${t.testFailed} (${err.message})`, 'err')
    } finally {
      setBusy([save, test], false)
    }
  })

  test.addEventListener('click', async () => {
    setBusy([save, test], true)
    setStatus(status, '…', '')
    try {
      const data = await api('/test-connection', {
        method: 'POST',
        body: JSON.stringify({ email: email.value, api_key: apiKey.value }),
      })
      badge.className = 'sfb-badge sfb-badge--on'
      badge.textContent = 'Connected'
      const ok = (t.testOk || 'Connection OK.').replace('{count}', String(data.count ?? 0))
      setStatus(status, `${ok} Click Save to use it in the chat.`, 'ok')
    } catch (err) {
      setStatus(status, `${t.testFailed} (${err.message})`, 'err')
    } finally {
      setBusy([save, test], false)
    }
  })

  card.appendChild(el('div', { class: 'sfb-actions' }, [save, test]))
  card.appendChild(status)
  card.appendChild(
    el('div', { class: 'sfb-links' }, [
      externalLink(FASTBILL_URL, t.officialSite || FALLBACK_MESSAGES.settings.officialSite),
      externalLink(FASTBILL_API_DOCS_URL, t.apiDocs || FALLBACK_MESSAGES.settings.apiDocs),
    ])
  )
  return card
}

function externalLink(href, label) {
  return el('a', {
    class: 'sfb-link',
    href,
    target: '_blank',
    rel: 'noopener noreferrer',
    text: `${label} ↗`,
  })
}

function renderTryPanel(api, messages, language) {
  const t = messages.chat
  const card = el('section', { class: 'sfb-card' })
  card.appendChild(el('div', { class: 'sfb-card__head' }, [el('h2', { text: t.heading })]))
  card.appendChild(el('p', { class: 'sfb-sub', text: t.hint }))

  const log = el('div', { class: 'sfb-log' })
  addBubble(log, 'bot', 'info', 'Type `/fastbill help` or use a quick action below.')

  const input = el('input', { type: 'text', placeholder: t.placeholder })
  const send = el('button', { class: 'sfb-btn sfb-btn--primary', text: t.send })

  async function submit(text) {
    const value = (text || input.value).trim()
    if (!value) return
    addBubble(log, 'user', 'info', value)
    input.value = ''
    setBusy([send], true)
    try {
      const data = await api('/chat', {
        method: 'POST',
        body: JSON.stringify({ message: value, lang: language }),
      })
      addBubble(log, 'bot', data.type || 'info', data.message || '(no reply)')
    } catch (err) {
      addBubble(log, 'bot', 'error', `Request failed: ${err.message}`)
    } finally {
      setBusy([send], false)
    }
  }

  send.addEventListener('click', () => submit())
  input.addEventListener('keydown', (event) => {
    if (event.key === 'Enter') submit()
  })

  card.appendChild(log)
  card.appendChild(el('div', { class: 'sfb-actions' }, [input, send]))

  const chips = el('div', { class: 'sfb-chips' })
  ;[
    ['/fastbill help', 'Help'],
    ['/fastbill show overdue', 'Show overdue'],
    ['/fastbill create invoice for ', 'Create invoice'],
  ].forEach(([command, label]) => {
    const chip = el('button', { class: 'sfb-chip', text: label })
    chip.addEventListener('click', () => {
      if (command.endsWith(' ')) {
        input.value = command
        input.focus()
      } else {
        submit(command)
      }
    })
    chips.appendChild(chip)
  })
  card.appendChild(chips)

  input.style.flex = '1'
  return card
}

function renderHelp(messages) {
  const t = messages.help
  const card = el('section', { class: 'sfb-card' })
  card.appendChild(el('div', { class: 'sfb-card__head' }, [el('h2', { text: t.heading })]))

  const list = el('ul', { class: 'sfb-help-list' })
  ;[
    ['/fastbill create invoice for <customer>', t.createInvoice],
    ['/fastbill register payment <bank text>', t.registerPayment],
    ['/fastbill show overdue', t.showOverdue],
    ['/fastbill help', t.help],
  ].forEach(([command, description]) => {
    list.appendChild(
      el('li', {}, [el('code', { text: command }), el('span', { text: description })])
    )
  })
  card.appendChild(list)
  card.appendChild(el('p', { class: 'sfb-hint', text: t.safety }))
  return card
}

function field(labelText, inputNode) {
  return el('label', { class: 'sfb-field' }, [el('span', { text: labelText }), inputNode])
}

function addBubble(log, who, type, text) {
  const classes = ['sfb-msg', `sfb-msg--${who}`]
  if (who === 'bot' && type === 'needs_confirmation') classes.push('sfb-msg--confirm')
  if (who === 'bot' && type === 'error') classes.push('sfb-msg--error')
  const bubble = el('div', { class: classes.join(' ') })
  bubble.innerHTML = who === 'bot' ? renderMarkdown(text) : escapeHtml(text)
  log.appendChild(bubble)
  log.scrollTop = log.scrollHeight
}

function setBusy(buttons, busy) {
  buttons.forEach((button) => {
    button.disabled = busy
  })
}

function setStatus(node, text, kind) {
  node.textContent = text
  node.className = 'sfb-status' + (kind ? ` sfb-status--${kind}` : '')
}

export default { mount }
