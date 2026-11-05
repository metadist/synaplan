/**
 * Synamail profiles inside Synaplan.
 *
 * Profiles are created from the Outlook add-in. This page lists them and
 * deletes one after a second click. Copy comes from mount's t(), which
 * Synaplan fills from frontend/locales/.
 */

const INSTALL_URL = 'https://docs.synaplan.com/synamail#install-it'
const REPO_URL = 'https://github.com/metadist/Synamail'
const PAGE_SIZE = 30
const FIELD =
  'sm-search w-full px-3 py-2 rounded-xl surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]'
const BTN = 'inline-flex items-center gap-2 px-4 py-2.5 text-sm font-medium'

const state = {
  el: null,
  ctx: null,
  t: (key) => key,
  profiles: [],
  filter: '',
  page: 1,
  expanded: new Set(),
  confirmEmail: null,
  listEl: null,
  pagerEl: null,
  countEl: null,
  loadError: null,
}

function api(path, options = {}) {
  const { userId, apiBaseUrl } = state.ctx
  return fetch(`${apiBaseUrl}/api/v1/user/${userId}/plugins/synamail${path}`, {
    credentials: 'include',
    headers: { Accept: 'application/json', ...(options.headers ?? {}) },
    ...options,
  })
}

function esc(value) {
  const div = document.createElement('div')
  div.textContent = String(value ?? '')
  return div.innerHTML
}

function fmtDate(iso) {
  if (!iso) return '—'
  const date = new Date(iso)
  return Number.isNaN(date.getTime())
    ? '—'
    : date.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' })
}

function initials(profile) {
  const src = (profile.name || profile.email || '?').trim()
  const parts = src.split(/[\s@._-]+/).filter(Boolean)
  const chars = (parts[0]?.[0] ?? '') + (parts[1]?.[0] ?? '')
  return (chars || src[0] || '?').toUpperCase()
}

function matchesFilter(profile, needle) {
  if (!needle) return true
  return [profile.name, profile.email, profile.org, profile.summary]
    .filter(Boolean)
    .join(' ')
    .toLowerCase()
    .includes(needle)
}

function pageWindow(current, total) {
  if (total <= 7) return Array.from({ length: total }, (_, i) => i + 1)
  const set = new Set([1, 2, total - 1, total, current - 1, current, current + 1])
  const pages = [...set].filter((page) => page >= 1 && page <= total).sort((a, b) => a - b)
  const out = []
  let prev = 0
  for (const page of pages) {
    if (prev && page - prev > 1) out.push('…')
    out.push(page)
    prev = page
  }
  return out
}

async function deleteProfile(email) {
  const res = await api(`/profiles/${encodeURIComponent(email)}`, { method: 'DELETE' })
  if (!res.ok) {
    state.loadError = state.t('loadError')
    renderList()
    return
  }
  state.profiles = state.profiles.filter((profile) => profile.email !== email)
  state.expanded.delete(email)
  state.confirmEmail = null
  renderList()
}

function renderLoops(loops) {
  const { t } = state
  if (!Array.isArray(loops) || loops.length === 0) return ''
  const items = loops
    .map((loop) => {
      const isMine = /^me\s*:/i.test(loop)
      const isTheirs = /^them\s*:/i.test(loop)
      const text = loop.replace(/^(me|them)\s*:\s*/i, '')
      const badge = isMine
        ? `<span class="sm-owner sm-owner--you">${esc(t('owedByYou'))}</span>`
        : isTheirs
          ? `<span class="sm-owner sm-owner--them">${esc(t('owedByThem'))}</span>`
          : ''
      return `<li>${badge}<span>${esc(text)}</span></li>`
    })
    .join('')
  return `
    <div class="sm-section">
      <div class="sm-section-label">${esc(t('openLoops'))}</div>
      <ul class="sm-loops">${items}</ul>
    </div>`
}

function renderFacts(facts) {
  const { t } = state
  if (!Array.isArray(facts) || facts.length === 0) return ''
  const items = facts.map((fact) => `<li>${esc(fact)}</li>`).join('')
  return `
    <div class="sm-section">
      <div class="sm-section-label">${esc(t('facts'))}</div>
      <ul class="sm-facts">${items}</ul>
    </div>`
}

function renderActions(email) {
  const { t } = state
  if (state.confirmEmail === email) {
    return `
      <div class="sm-panel-actions" data-testid="synamail-confirm">
        <p class="sm-confirm">${esc(t('confirmDelete'))}</p>
        <button type="button" class="btn-secondary ${BTN}" data-cancel="${esc(email)}">${esc(t('cancel'))}</button>
        <button type="button" class="btn-danger ${BTN}" data-email="${esc(email)}">${esc(t('delete'))}</button>
      </div>`
  }
  return `
    <div class="sm-panel-actions">
      <button type="button" class="btn-danger ${BTN}" data-ask="${esc(email)}">${esc(t('delete'))}</button>
    </div>`
}

function renderPanel(profile) {
  const { t } = state
  const tone = profile.tone ? `<span class="sm-tone">${esc(profile.tone)}</span>` : ''
  const meta = [
    [t('organization'), profile.org || '—'],
    [t('firstSeen'), fmtDate(profile.firstSeen)],
    [t('lastInbound'), fmtDate(profile.lastInbound)],
    [t('lastOutbound'), fmtDate(profile.lastOutbound)],
  ]
    .map(
      ([label, value]) =>
        `<div class="sm-meta-cell"><dt>${esc(label)}</dt><dd>${esc(value)}</dd></div>`
    )
    .join('')

  return `
    <div class="sm-panel">
      ${tone}
      ${profile.summary ? `<p class="sm-summary">${esc(profile.summary)}</p>` : ''}
      ${renderFacts(profile.facts)}
      ${renderLoops(profile.openLoops)}
      <dl class="sm-meta-grid">${meta}</dl>
      ${renderActions(profile.email)}
    </div>`
}

function renderList() {
  const { t, listEl, pagerEl, countEl } = state
  if (state.loadError) {
    listEl.innerHTML = `<p class="sm-error text-sm text-red-600 dark:text-red-400" data-testid="synamail-error">${esc(state.loadError)}</p>`
    pagerEl.innerHTML = ''
    countEl.textContent = ''
    return
  }

  const total = state.profiles.length
  if (total === 0) {
    listEl.innerHTML = `<p class="sm-empty" data-testid="synamail-empty">${esc(t('empty'))}</p>`
    pagerEl.innerHTML = ''
    countEl.textContent = ''
    return
  }

  const filtered = state.profiles.filter((profile) => matchesFilter(profile, state.filter))
  const totalPages = Math.max(1, Math.ceil(filtered.length / PAGE_SIZE))
  if (state.page > totalPages) state.page = totalPages
  const start = (state.page - 1) * PAGE_SIZE
  const pageItems = filtered.slice(start, start + PAGE_SIZE)
  countEl.textContent = `${filtered.length} ${t('profiles')}`

  if (filtered.length === 0) {
    listEl.innerHTML = `<p class="sm-empty">${esc(t('noResults'))}</p>`
    pagerEl.innerHTML = ''
    return
  }

  listEl.innerHTML = pageItems
    .map((profile) => {
      const open = state.expanded.has(profile.email)
      return `
        <div class="sm-item${open ? ' sm-item--open' : ''}">
          <button type="button" class="sm-acc" data-toggle="${esc(profile.email)}" aria-expanded="${open}">
            <span class="sm-avatar" aria-hidden="true">${esc(initials(profile))}</span>
            <span class="sm-acc-main">
              <span class="sm-acc-name">${esc(profile.name || profile.email)}</span>
              <span class="sm-acc-sub">${esc(profile.email)}${profile.org ? ` · ${esc(profile.org)}` : ''}</span>
            </span>
            <span class="sm-acc-meta">
              <span>${esc(String(profile.emailCount ?? 0))} ${esc(t('emails'))}</span>
              <span class="sm-acc-updated">${esc(t('updated'))} ${esc(fmtDate(profile.updatedAt))}</span>
            </span>
            <svg class="sm-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" aria-hidden="true">
              <path d="M6 9l6 6 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
          </button>
          ${open ? renderPanel(profile) : ''}
        </div>`
    })
    .join('')

  renderPager(totalPages)
  bindListEvents()
}

function renderPager(totalPages) {
  const { t, pagerEl } = state
  if (totalPages <= 1) {
    pagerEl.innerHTML = ''
    return
  }
  const btn = (label, page, opts = {}) => {
    const { disabled = false, active = false, isGap = false } = opts
    if (isGap) return `<span class="sm-page-gap">…</span>`
    const cls = `sm-page-btn${active ? ' sm-page-btn--active' : ''}`
    return `<button type="button" class="${cls}" data-page="${page}" ${disabled ? 'disabled' : ''}>${esc(label)}</button>`
  }
  const numbers = pageWindow(state.page, totalPages)
    .map((page) =>
      page === '…'
        ? btn('', 0, { isGap: true })
        : btn(String(page), page, { active: page === state.page })
    )
    .join('')
  pagerEl.innerHTML = `
    ${btn(t('prev'), state.page - 1, { disabled: state.page <= 1 })}
    <span class="sm-page-nums">${numbers}</span>
    ${btn(t('next'), state.page + 1, { disabled: state.page >= totalPages })}`
  for (const el of pagerEl.querySelectorAll('button[data-page]')) {
    el.addEventListener('click', () => {
      const target = Number(el.getAttribute('data-page'))
      if (!target || target === state.page) return
      state.page = target
      renderList()
      state.listEl.scrollIntoView({ block: 'nearest', behavior: 'smooth' })
    })
  }
}

function bindListEvents() {
  const { listEl } = state
  for (const el of listEl.querySelectorAll('button[data-toggle]')) {
    el.addEventListener('click', () => {
      const email = el.getAttribute('data-toggle')
      if (state.expanded.has(email)) state.expanded.delete(email)
      else state.expanded.add(email)
      renderList()
    })
  }
  for (const el of listEl.querySelectorAll('button[data-ask]')) {
    el.addEventListener('click', (event) => {
      event.stopPropagation()
      state.confirmEmail = el.getAttribute('data-ask')
      renderList()
    })
  }
  for (const el of listEl.querySelectorAll('button[data-cancel]')) {
    el.addEventListener('click', (event) => {
      event.stopPropagation()
      state.confirmEmail = null
      renderList()
    })
  }
  for (const el of listEl.querySelectorAll('button[data-email]')) {
    el.addEventListener('click', (event) => {
      event.stopPropagation()
      deleteProfile(el.getAttribute('data-email'))
    })
  }
}

function shellStyles() {
  return `
  <style>
    .sm-wrap { color: var(--txt-primary); max-width: 100%; }
    .sm-header { margin: 0 0 20px; }
    .sm-title-row { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
    .sm-title-icon {
      display: inline-flex; align-items: center; justify-content: center;
      width: 40px; height: 40px; border-radius: 10px;
      background: var(--brand-alpha-light); color: var(--brand); flex: none;
    }
    .sm-title { margin: 0; font-size: 1.5rem; font-weight: 600; line-height: 1.2; }
    .sm-tagline, .sm-intro { margin: 10px 0 0; color: var(--txt-secondary); max-width: 65ch; }
    .sm-links { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 14px; }
    .sm-toolbar {
      display: flex; align-items: center; gap: 12px; flex-wrap: wrap;
      margin: 0 0 16px; padding-top: 16px; border-top: 1px solid var(--border-light);
    }
    .sm-search-box { position: relative; flex: 1 1 16rem; min-width: 0; }
    .sm-search-box svg {
      position: absolute; left: 12px; top: 50%; transform: translateY(-50%);
      width: 16px; height: 16px; color: var(--txt-secondary); pointer-events: none;
    }
    .sm-search { padding-left: 2.25rem; box-sizing: border-box; }
    .sm-count { color: var(--txt-secondary); font-size: 0.85rem; }
    .sm-list { display: flex; flex-direction: column; gap: 10px; }
    .sm-item {
      border: 1px solid var(--border-light); border-radius: 12px; overflow: hidden;
      background: var(--bg-chip);
    }
    .sm-acc {
      width: 100%; display: flex; align-items: center; gap: 12px;
      padding: 12px 14px; background: transparent; border: 0; cursor: pointer;
      text-align: left; color: var(--txt-primary); font-family: inherit;
    }
    .sm-acc:hover { background: var(--brand-alpha-light); }
    .sm-avatar {
      display: inline-flex; align-items: center; justify-content: center;
      width: 34px; height: 34px; border-radius: 50%; flex: none;
      background: var(--brand); color: var(--on-brand); font-size: 0.8rem; font-weight: 600;
    }
    .sm-acc-main { display: flex; flex-direction: column; gap: 2px; min-width: 0; flex: 1; }
    .sm-acc-name, .sm-acc-sub { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .sm-acc-name { font-weight: 600; font-size: 0.95rem; }
    .sm-acc-sub, .sm-acc-meta { color: var(--txt-secondary); font-size: 0.8rem; }
    .sm-acc-meta { display: flex; flex-direction: column; align-items: flex-end; gap: 2px; flex: none; }
    .sm-chevron { color: var(--txt-secondary); flex: none; transition: transform 0.2s ease; }
    .sm-item--open .sm-chevron { transform: rotate(180deg); }
    .sm-panel { padding: 4px 16px 16px; border-top: 1px solid var(--border-light); }
    .sm-tone {
      display: inline-block; margin: 12px 0 0; padding: 3px 12px; border-radius: 999px;
      background: var(--brand-alpha-light); color: var(--brand); font-size: 0.78rem; font-weight: 500;
    }
    .sm-summary { margin: 12px 0 0; line-height: 1.55; white-space: pre-wrap; }
    .sm-section { margin-top: 14px; }
    .sm-section-label {
      font-size: 0.72rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em;
      color: var(--txt-secondary); margin-bottom: 6px;
    }
    .sm-facts, .sm-loops { margin: 0; padding-left: 18px; display: flex; flex-direction: column; gap: 4px; }
    .sm-loops { list-style: none; padding-left: 0; }
    .sm-loops li { display: flex; align-items: baseline; gap: 8px; flex-wrap: wrap; }
    .sm-owner {
      flex: none; font-size: 0.68rem; font-weight: 600; padding: 1px 7px; border-radius: 999px;
      text-transform: uppercase; letter-spacing: 0.03em;
    }
    .sm-owner--you { background: var(--status-warning-muted); color: var(--status-warning-text); }
    .sm-owner--them { background: var(--status-success-muted); color: var(--status-success-text); }
    .sm-meta-grid {
      display: grid; grid-template-columns: repeat(auto-fit, minmax(8rem, 1fr));
      gap: 10px 16px; margin: 16px 0 0;
    }
    .sm-meta-cell dt { font-size: 0.72rem; color: var(--txt-secondary); text-transform: uppercase; letter-spacing: 0.03em; }
    .sm-meta-cell dd { margin: 2px 0 0; font-size: 0.85rem; }
    .sm-panel-actions { margin-top: 16px; display: flex; flex-wrap: wrap; justify-content: flex-end; align-items: center; gap: 8px; }
    .sm-confirm { margin: 0; flex: 1 1 12rem; color: var(--txt-secondary); font-size: 0.85rem; }
    .sm-empty { color: var(--txt-secondary); text-align: center; padding: 32px 12px; }
    .sm-pager { display: flex; align-items: center; justify-content: center; gap: 8px; margin-top: 20px; flex-wrap: wrap; }
    .sm-page-nums { display: flex; align-items: center; gap: 4px; flex-wrap: wrap; }
    .sm-page-btn {
      min-width: 34px; height: 34px; padding: 0 10px; border-radius: 8px;
      border: 1px solid var(--border-light); background: transparent; color: var(--txt-primary);
      cursor: pointer; font-size: 0.85rem; font-family: inherit;
    }
    .sm-page-btn--active { background: var(--brand); color: var(--on-brand); border-color: var(--brand); }
    .sm-page-btn:disabled { opacity: 0.45; cursor: default; }
    .sm-page-gap { min-width: 20px; text-align: center; color: var(--txt-secondary); }
    @media (max-width: 560px) {
      .sm-acc-meta { display: none; }
      .sm-title { font-size: 1.25rem; }
    }
  </style>`
}

function installLink() {
  const mailIcon = `<svg width="15" height="15" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 6h16a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1Z" stroke="currentColor" stroke-width="1.6"/><path d="m4 7 8 6 8-6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>`
  return `<a class="btn-primary ${BTN}" href="${INSTALL_URL}" target="_blank" rel="noopener noreferrer" data-testid="synamail-install">${mailIcon}<span>${esc(state.t('installOutlook'))}</span></a>`
}

function buildShell() {
  const { t } = state
  const headerIcon = `<svg width="22" height="22" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M4 6h16a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1Z" stroke="currentColor" stroke-width="1.6"/><path d="m4 7 8 6 8-6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>`
  state.el.innerHTML = `
    ${shellStyles()}
    <div class="sm-wrap" data-testid="synamail-page">
      <header class="sm-header">
        <div class="sm-title-row">
          <span class="sm-title-icon">${headerIcon}</span>
          <h2 class="sm-title">${esc(t('title'))}</h2>
        </div>
        <p class="sm-tagline">${esc(t('tagline'))}</p>
        <p class="sm-intro">${esc(t('intro'))}</p>
        <div class="sm-links">
          ${installLink()}
          <a class="btn-secondary ${BTN}" href="${REPO_URL}" target="_blank" rel="noopener noreferrer">${esc(t('repoLink'))}</a>
        </div>
      </header>
      <div class="sm-toolbar">
        <div class="sm-search-box">
          <svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="11" cy="11" r="7" stroke="currentColor" stroke-width="2"/><path d="m20 20-3-3" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
          <input type="search" class="${FIELD}" placeholder="${esc(t('searchPlaceholder'))}" aria-label="${esc(t('searchPlaceholder'))}" />
        </div>
        <span class="sm-count"></span>
      </div>
      <div class="sm-list" data-testid="synamail-list"></div>
      <nav class="sm-pager" aria-label="Pagination"></nav>
    </div>`
  state.listEl = state.el.querySelector('.sm-list')
  state.pagerEl = state.el.querySelector('.sm-pager')
  state.countEl = state.el.querySelector('.sm-count')
  state.el.querySelector('.sm-search').addEventListener('input', (event) => {
    state.filter = event.target.value.trim().toLowerCase()
    state.page = 1
    renderList()
  })
}

async function loadProfiles() {
  state.loadError = null
  state.listEl.innerHTML = `<p class="sm-empty">${esc(state.t('loading'))}</p>`
  try {
    const res = await api('/profiles')
    if (!res.ok) throw new Error('unavailable')
    const data = await res.json()
    state.profiles = Array.isArray(data.profiles) ? data.profiles : []
  } catch {
    state.loadError = state.t('loadError')
  }
  renderList()
}

export default {
  async mount(el, context) {
    state.el = el
    state.ctx = context
    state.t = typeof context.t === 'function' ? context.t : (key) => key
    state.profiles = []
    state.filter = ''
    state.page = 1
    state.expanded = new Set()
    state.confirmEmail = null
    state.loadError = null
    buildShell()
    await loadProfiles()
  },
}
