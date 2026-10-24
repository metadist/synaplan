// synaScriber plugin page in Synaplan: your meeting notes, and for
// administrators the settings and the connection checks.

let translate = (key) => key
let currentLocale = 'en'

function lang() {
  return currentLocale
}

function t(key, params = {}) {
  return translate(key, params)
}
const LANGUAGES = { de: 'Deutsch', en: 'English', es: 'Español', fr: 'Français', tr: 'Türkçe' }
const FIELD =
  'mt-1 w-full px-3 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]'

function h(tag, attrs = {}, children = []) {
  const node = document.createElement(tag)
  for (const [key, value] of Object.entries(attrs)) {
    if (key === 'text') node.textContent = value
    else if (key.startsWith('on')) node.addEventListener(key.slice(2), value)
    else node.setAttribute(key, value)
  }
  for (const child of children) if (child) node.appendChild(child)
  return node
}

async function call(base, method, path, body) {
  const response = await fetch(`${base}/api/v1/plugins/synascriber${path}`, {
    method,
    credentials: 'include',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: body ? JSON.stringify(body) : undefined,
  })
  const data = await response.json().catch(() => ({}))
  return { ok: response.ok, status: response.status, data }
}

function sessionsCard(list) {
  const card = h('section', { class: 'surface-card rounded-lg p-4' }, [
    h('h2', { class: 'txt-primary text-base font-semibold', text: t('mine') }),
  ])
  if (!list.length) {
    card.appendChild(h('p', { class: 'txt-secondary text-sm mt-2', text: t('empty') }))
    return card
  }
  const ul = h('ul', { class: 'mt-2 divide-y divide-light-border/30 dark:divide-dark-border/20' })
  for (const s of list) {
    const when = s.startedAt ? new Date(s.startedAt).toLocaleString(lang()) : ''
    const row = h('li', { class: 'py-2 flex flex-wrap items-center gap-3' }, [
      h('span', { class: 'txt-primary text-sm font-medium', text: s.room }),
      h('span', {
        class: 'txt-secondary text-sm',
        text: `${when} · ${LANGUAGES[s.language] || s.language} · ${s.folder}`,
      }),
      h('span', { class: 'txt-secondary text-sm', text: t(`state_${s.state}`) }),
      s.received
        ? h('span', { class: 'txt-secondary text-sm', text: t('from', { name: s.startedBy }) })
        : null,
    ])
    if (s.fileId)
      row.appendChild(
        h('a', {
          class: 'btn-secondary px-4 py-2.5 rounded-lg text-sm font-medium',
          href: `/files?file=${s.fileId}`,
          text: t('open'),
        })
      )
    ul.appendChild(row)
  }
  card.appendChild(ul)
  card.appendChild(
    h('a', {
      class: 'btn-secondary inline-flex mt-3 px-4 py-2.5 rounded-lg text-sm font-medium',
      href: '/files/generated',
      text: t('all_generated'),
    })
  )
  return card
}

function adminCard(base, status, onSaved) {
  const s = status.settings
  const card = h('section', { class: 'surface-card rounded-lg p-4' }, [
    h('h2', { class: 'txt-primary text-base font-semibold', text: t('admin') }),
  ])

  const checks = h('ul', { class: 'mt-2 text-sm' })
  for (const [key, label] of [
    ['jitsi', 'check_jitsi'],
    ['speech', 'check_speech'],
  ]) {
    checks.appendChild(
      h('li', {
        class: status.checks[key] ? 'txt-primary' : 'text-red-600 dark:text-red-400',
        text: `${status.checks[key] ? '✓' : '✗'} ${t(label)}: ${status.checks[key] ? t('ok') : t('not_ok')}`,
      })
    )
  }
  card.appendChild(h('h3', { class: 'txt-primary text-sm font-semibold mt-3', text: t('checks') }))
  card.appendChild(checks)

  const enabled = h('input', { type: 'checkbox', id: 'syn-enabled' })
  enabled.checked = !!s.enabled
  const share = h('input', { type: 'checkbox', id: 'syn-share' })
  share.checked = !!s.share_with_participants
  const captions = h('input', { type: 'checkbox', id: 'syn-captions' })
  captions.checked = s.captions !== false
  const langs = Object.keys(LANGUAGES).map((code) => {
    const box = h('input', { type: 'checkbox', value: code, id: `syn-lang-${code}` })
    box.checked = s.languages.includes(code)
    return h(
      'label',
      { class: 'inline-flex items-center gap-1 mr-3 txt-primary text-sm', for: `syn-lang-${code}` },
      [box, h('span', { text: LANGUAGES[code] })]
    )
  })
  const defLang = h(
    'select',
    { class: FIELD, id: 'syn-def-lang' },
    Object.entries(LANGUAGES).map(([code, name]) => {
      const option = h('option', { value: code, text: name })
      if (code === s.default_language) option.selected = true
      return option
    })
  )
  const folder = h('input', {
    class: FIELD,
    id: 'syn-folder',
    value: s.default_folder,
    maxlength: '64',
  })
  const jitsi = h('input', {
    class: FIELD,
    id: 'syn-jitsi',
    value: s.jitsi_url,
    placeholder: 'https://meet.example.org',
  })
  const stt = h('input', {
    class: FIELD,
    id: 'syn-stt',
    value: s.stt_url,
    placeholder: 'http://stt.example.svc.cluster.local:8080',
  })
  const prosody = h('input', {
    class: FIELD,
    id: 'syn-prosody',
    value: s.prosody_url,
    placeholder: 'http://jitsi-prosody.example.svc.cluster.local:5280',
  })
  const message = h('p', { class: 'text-sm mt-2', role: 'status' })

  const form = h('form', { class: 'mt-4 grid gap-3' }, [
    h(
      'label',
      { class: 'inline-flex items-center gap-2 txt-primary text-sm', for: 'syn-enabled' },
      [enabled, h('span', { text: t('enabled') })]
    ),
    h('label', { class: 'inline-flex items-center gap-2 txt-primary text-sm', for: 'syn-share' }, [
      share,
      h('span', { text: t('share') }),
    ]),
    h(
      'label',
      { class: 'inline-flex items-center gap-2 txt-primary text-sm', for: 'syn-captions' },
      [captions, h('span', { text: t('captions') })]
    ),
    h('div', {}, [
      h('div', { class: 'txt-secondary text-sm', text: t('languages') }),
      h('div', { class: 'mt-1' }, langs),
    ]),
    h('label', {
      class: 'txt-secondary text-sm',
      for: 'syn-def-lang',
      text: t('default_language'),
    }),
    defLang,
    h('label', { class: 'txt-secondary text-sm', for: 'syn-folder', text: t('default_folder') }),
    folder,
    h('label', { class: 'txt-secondary text-sm', for: 'syn-jitsi', text: t('jitsi_url') }),
    jitsi,
    h('label', { class: 'txt-secondary text-sm', for: 'syn-stt', text: t('stt_url') }),
    stt,
    h('label', { class: 'txt-secondary text-sm', for: 'syn-prosody', text: t('prosody_url') }),
    prosody,
    h('p', {
      class: 'txt-secondary text-sm',
      text: `${t('secret')}: ${s.prosody_secret ? t('secret_set') : t('secret_missing')}`,
    }),
    h('p', {
      class: s.transcriber_secret
        ? 'txt-secondary text-sm'
        : 'text-sm text-red-600 dark:text-red-400',
      text: `${t('transcriber_secret')}: ${s.transcriber_secret ? t('secret_set') : t('secret_missing')}`,
    }),
    h('div', {}, [
      h('button', {
        type: 'submit',
        class: 'btn-primary px-4 py-2.5 rounded-lg text-sm font-medium',
        text: t('save'),
      }),
    ]),
    message,
  ])
  form.addEventListener('submit', async (event) => {
    event.preventDefault()
    const body = {
      enabled: enabled.checked,
      share_with_participants: share.checked,
      captions: captions.checked,
      languages: langs
        .map((l) => l.querySelector('input'))
        .filter((b) => b.checked)
        .map((b) => b.value),
      default_language: defLang.value,
      default_folder: folder.value,
      jitsi_url: jitsi.value,
      stt_url: stt.value,
      prosody_url: prosody.value,
    }
    const res = await call(base, 'PUT', '/admin/settings', body)
    message.className = res.ok
      ? 'txt-primary text-sm mt-2'
      : 'text-sm mt-2 text-red-600 dark:text-red-400'
    message.textContent = res.ok ? t('saved') : res.data.message || t('error')
    if (res.ok) onSaved(res.data)
  })
  card.appendChild(form)
  return card
}

export default {
  async mount(el, ctx) {
    currentLocale = String(ctx.locale || 'en').slice(0, 2)
    translate = (key, params = {}) =>
      typeof ctx.t === 'function' ? ctx.t('page.' + key, params) : key
    const base = ctx.apiBaseUrl || ''
    const root = h('div', { class: 'max-w-3xl mx-auto p-4 grid gap-4' }, [
      h('h1', { class: 'txt-primary text-xl font-semibold', text: t('title') }),
      h('p', { class: 'txt-secondary text-sm', text: t('intro') }),
    ])
    el.innerHTML = ''
    el.appendChild(root)

    const mine = await call(base, 'GET', '/me/sessions')
    if (!mine.ok) {
      root.appendChild(
        h('p', { class: 'text-sm text-red-600 dark:text-red-400', text: t('error') })
      )
      return
    }
    const list = h('div')
    list.appendChild(sessionsCard(mine.data.sessions || []))
    root.appendChild(list)

    if (mine.data.admin) {
      const status = await call(base, 'GET', '/admin/status')
      if (status.ok) {
        const slot = h('div')
        const draw = (data) => {
          slot.innerHTML = ''
          slot.appendChild(adminCard(base, data, draw))
        }
        draw(status.data)
        root.appendChild(slot)
      }
    }
  },
}
