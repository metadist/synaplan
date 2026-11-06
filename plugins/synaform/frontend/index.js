const TX_VERSION = 'v4.4.3'
// Combined with TX_VERSION when fetching plugin assets (i18n bundles, etc.)
// to defeat stale browser caches whenever a new build of index.js is loaded.
// The host (PluginView.vue) already cache-busts index.js itself with
// Date.now(), so this constant changes on every plugin reload — meaning i18n
// is guaranteed fresh whenever the plugin code is, even if someone forgets
// to bump TX_VERSION on a release.
const TX_LOAD_TS = Date.now()

export default {
  mount(el, context) {
    const { userId, apiBaseUrl, pluginBaseUrl } = context
    const BASE = `${apiBaseUrl}/api/v1/user/${userId}/plugins/synaform`
    const ASSET_BASE = pluginBaseUrl

    // =========================================================================
    // State
    // =========================================================================

    let t = {}
    let _loadedLang = ''
    let _langPollTimer = null
    let _pendingTemplateFile = null

    const state = {
      loading: true,
      error: null,
      config: {},
      forms: [],
      templates: [],
      datasets: [],

      // Prompts tab: prompt sections from GET /prompts plus per-key
      // editing drafts and in-flight save flags.
      prompts: null,
      promptDrafts: {},
      promptSaving: null,

      // Optional free-text command passed along with Generate ("Mach das
      // auf Italienisch", "Formatiere die Zahlen englisch", …).
      generateInstruction: '',

      // Route
      view: 'collections',
      collectionId: null,
      datasetId: null,
      tab: 'overview',

      // Page-level UI state
      collectionsSearch: '',
      newCollectionOpen: false,
      editingCollection: null,
      showCollectionHelp: false,
      showVariablesHelp: false,
      showTemplatesHelp: false,
      showExportHelp: false,

      // Collection title kebab menu (Edit + Danger Zone). The Danger
      // Zone used to be its own top-level tab, which made it too easy
      // to land on accidentally — it now lives inside this menu and
      // opens as a modal when picked.
      collectionMenuOpen: false,
      dangerOpen: false,

      // Split "Set up Collection" button (Collections list + Overview).
      // Holds the id of the currently-open dropdown so multiple
      // instances on the same page don't fight over a single open flag.
      // The primary click runs the guided-wizard flow; the dropdown
      // exposes the per-entry-point shortcuts and the manual escape
      // hatch.
      setupMenuOpen: null,
      // Which split-button mode the user picked when falling back to
      // the manual editor — drives where we land after save
      // ("manual" -> Overview tab; the wizard paths handle their own
      // landing inside the wizard).
      pendingSetupMode: null,

      // Setup Wizard (5 steps). The wizard is a fully self-contained
      // modal driven entirely by the `wizard` substate; every step is
      // persisted to the backend as the user advances so cancelling
      // mid-flow always leaves a resumable draft Collection behind.
      // mode: "template" | "noTemplate" — chosen on step 0. Determines
      // whether step 2 (template upload) is part of the path.
      wizard: {
        open: false,
        step: 0,
        mode: null,
        // Draft Collection being assembled. collectionId is null until
        // the user finishes step 1 (basics); after that every step
        // persists changes against this id.
        collectionId: null,
        name: '',
        description: '',
        language: '',
        templateId: null,
        templateName: '',
        templatePlaceholderCount: 0,
        templateFile: null,
        templateUploading: false,
        templateError: null,
        suggestions: null,
        suggestionsLoading: false,
        suggestionsError: null,
        suggestionsSelected: {},
        pastedText: '',
        pastedParsing: false,
        pastedError: null,
        pastedFields: null,
        // AI-suggest-from-docx progress: phased status while the
        // multi-stage backend pipeline (extract → classify → propose
        // per window → refine) runs. Mirrors the dataset-extract
        // progress pattern below so the user sees motion + an elapsed
        // counter instead of a stalled-looking spinner.
        aiSuggestStep: 0,
        aiSuggestStartedAt: null,
        aiSuggestElapsedSec: 0,
        aiSuggestTimer: null,
        fields: [],
        saving: false,
        error: null,
      },

      // Dataset editing state
      datasetsSearch: '',
      datasetsSortNewest: true,
      datasetsPage: 0,
      selectedDataset: null,
      newDatasetOpen: false,
      datasetExtracting: false,
      datasetGenerating: false,
      datasetSaving: false,
      datasetParsing: false,
      datasetParseStep: 0,
      datasetParseStartedAt: null,
      datasetParseElapsedSec: 0,
      datasetParseFileCount: 0,
      datasetParseHasImages: false,
      datasetParseTimer: null,
      datasetParseStatusMsgs: [],
      datasetParseStatusIdx: 0,
      datasetVariables: null,
      datasetVariablesLoading: false,
      selectedGenerateTemplate: null,
      reorderingFields: false,
      urlFormOpen: false,
      urlAdding: false,
      urlAddError: null,

      // Source-file upload in flight (progress lives in the Sources card
      // AND in the page-top alert so a large iPhone scan is never silent).
      datasetUploading: false,
      datasetUploadCurrent: 0,
      datasetUploadTotal: 0,
      datasetUploadFilename: '',
      datasetUploadPercent: 0,
      datasetUploadBytesSent: 0,
      datasetUploadBytesTotal: 0,

      // Sticky page-top alert for process outcomes (upload / parse /
      // timeout / generate). Toasts stay for short confirmations.
      pageAlert: null,

      // Tabbed dataset detail view: "sources" | "details" | "generate".
      // The old single-page layout was extremely long; the tabs scope the
      // user to one phase of the flow at a time.
      datasetTab: 'sources',

      // Live preview — shown as a modal overlay so the dataset form can use
      // the full screen width. Customers asked for this because the
      // side-by-side layout was cutting the variables column in half on
      // typical screens.
      previewVisible: false,
      previewTemplateId: null,
      previewSkeleton: null,
      previewLoading: false,
      previewError: null,
      previewDebounceTimer: null,
      previewPdfUrl: null,
      previewPdfLoading: false,
      previewPdfError: null,
      previewPdfUnavailable: false,

      // Variables editing state
      variablesDraft: null,
      variablesDirty: false,
      variablesImportOpen: false,
      variablesImportParsing: false,
      variablesImportFields: null,
      variablesImportError: null,
      variablesImportText: '',
      expandedDesignerIdx: null,

      // Polish-with-AI state. Holds the diff modal contents while
      // the admin reviews AI-suggested description / examples /
      // negative_hint per field. Selection map keyed by field key —
      // pre-ticked for fields that had no description/examples
      // before, since those are pure additions; un-ticked for fields
      // where the AI is overwriting existing content.
      polishLoading: false,
      polishOpen: false,
      polishResults: null,
      polishSelected: {},
      polishMeta: null,
      polishApplying: false,
      polishError: null,

      // Import from Target Template state
      variablesTplImportOpen: false,
      variablesTplImportTemplateId: null,
      variablesTplImportLoading: false,
      variablesTplImportError: null,
      variablesTplImportResult: null,
      variablesTplImportSelection: {},

      // Template management state
      selectedTemplateDetail: null,
      templateMatchOpen: false,
      templateMatchAddKey: null,

      // Export state
      exportStatus: '',
      exportFrom: '',
      exportTo: '',
      exportSearch: '',

      // Danger zone
      dangerConfirmText: '',

      // GDPR / data retention banner state — when truthy, renderDatasetsTab
      // suppresses the overdue banner for the current session even if datasets
      // remain past their expiry date. The user explicitly clicked Cancel.
      retentionBannerDismissed: false,

      // Plugin dashboard payload (Tika + AI status). Lazy-loaded the
      // first time the user opens the overview tab.
      dashboard: null,
      dashboardLoading: false,
      dashboardError: null,
      dashboardLoadedAt: 0,
    }

    // =========================================================================
    // i18n
    // =========================================================================

    function T(key, fallback) {
      const parts = key.split('.')
      let val = t
      for (const p of parts) {
        val = val?.[p]
        if (val === undefined) return fallback || key
      }
      return val
    }

    function Tf(key, vars = {}) {
      let out = T(key)
      for (const [k, v] of Object.entries(vars)) {
        out = out.replace(new RegExp(`\\{${k}\\}`, 'g'), String(v))
      }
      return out
    }

    async function loadTranslations(force) {
      const lang = localStorage.getItem('language') || 'en'
      if (!force && lang === _loadedLang && Object.keys(t).length > 0) return false
      const cb = `?v=${TX_VERSION}&_=${TX_LOAD_TS}`
      try {
        const res = await fetch(`${ASSET_BASE}/i18n/${lang}.json${cb}`)
        if (res.ok) {
          t = await res.json()
          _loadedLang = lang
          return true
        }
      } catch (_) {
        /* fallthrough */
      }
      if (lang !== 'en') {
        try {
          const res = await fetch(`${ASSET_BASE}/i18n/en.json${cb}`)
          if (res.ok) {
            t = await res.json()
            _loadedLang = 'en'
            return true
          }
        } catch (_) {
          /* no translations available */
        }
      }
      return false
    }

    function startLanguageWatcher() {
      if (_langPollTimer) return
      _langPollTimer = setInterval(async () => {
        const current = localStorage.getItem('language') || 'en'
        if (current !== _loadedLang) {
          const changed = await loadTranslations()
          if (changed && !state.loading) render()
        }
      }, 500)
    }

    // =========================================================================
    // API helpers
    // =========================================================================

    let _refreshPromise = null

    async function refreshAccessToken() {
      if (_refreshPromise) return _refreshPromise
      _refreshPromise = (async () => {
        try {
          const res = await fetch(`${apiBaseUrl}/api/v1/auth/refresh`, {
            method: 'POST',
            credentials: 'include',
          })
          return res.ok
        } catch {
          return false
        } finally {
          _refreshPromise = null
        }
      })()
      return _refreshPromise
    }

    // Every request is bounded so a slow/stuck backend or AI provider can
    // NEVER make the UI wait forever. Normal CRUD uses this default;
    // AI-hitting calls pass a larger `timeoutMs`. Pass `timeoutMs: 0` to
    // opt out (nothing currently does).
    const DEFAULT_API_TIMEOUT_MS = 90000
    // Base upload timeout; apiUpload() raises this with file size so a
    // large iPhone scan on a slow link is not aborted at 2 minutes.
    const DEFAULT_UPLOAD_TIMEOUT_MS = 180000
    const MAX_SOURCE_UPLOAD_BYTES = 80 * 1024 * 1024
    const SOURCE_UPLOAD_EXTS = [
      'pdf',
      'doc',
      'docx',
      'jpg',
      'jpeg',
      'png',
      'gif',
      'webp',
      'tiff',
      'tif',
      'bmp',
      'heic',
      'heif',
      'txt',
      'rtf',
      'odt',
      'xls',
      'xlsx',
      'pptx',
      'eml',
      'msg',
    ]

    function fileExtension(name) {
      const n = String(name || '')
      const dot = n.lastIndexOf('.')
      return dot < 0 ? '' : n.slice(dot + 1).toLowerCase()
    }

    function formatBytes(n) {
      const v = Number(n) || 0
      if (v < 1024) return `${v} B`
      if (v < 1048576) return `${Math.round(v / 1024)} KB`
      return `${(v / 1048576).toFixed(1)} MB`
    }

    function uploadTimeoutMsFor(file) {
      const mb = Math.max(1, (file && file.size ? file.size : 0) / 1048576)
      return Math.min(300000, Math.round(60000 + mb * 4000))
    }

    function validateSourceFile(file) {
      if (!file) return T('datasets.upload_no_file')
      if (!file.size) return T('datasets.upload_empty')
      if (file.size > MAX_SOURCE_UPLOAD_BYTES) {
        return Tf('datasets.upload_too_large', {
          size: formatBytes(file.size),
          max: '80 MB',
        })
      }
      const ext = fileExtension(file.name)
      if (!ext || !SOURCE_UPLOAD_EXTS.includes(ext)) {
        return Tf('datasets.upload_bad_type', { ext: ext || '?' })
      }
      return null
    }

    function explainNetworkError(e, kind) {
      if (!e) return T('datasets.network_error')
      if (e.name === 'AbortError' || e.code === 'TIMEOUT') {
        return kind === 'upload' ? T('datasets.upload_timeout') : T('datasets.parse_timeout')
      }
      const msg = String(e.message || '')
      if (/Failed to fetch|NetworkError|Load failed|ERR_NETWORK/i.test(msg)) {
        return T('datasets.network_error')
      }
      return msg || T('datasets.network_error')
    }

    function isRetryableParseFailure(err) {
      if (!err) return false
      if (err.name === 'AbortError' || err.code === 'TIMEOUT') return true
      const status = err.status || err.payload?.status
      if (status === 502 || status === 504 || status === 524) return true
      const msg = String(err.message || '')
      return /524|504|502|timed out|timeout|gateway|network/i.test(msg)
    }

    async function api(path, opts = {}) {
      // Per-call timeout. Without it a slow/stuck AI provider (e.g. a heavy
      // reasoning model) would make the request hang forever and the UI
      // spin with no end. Callers that hit AI pass a larger `timeoutMs`.
      const { timeoutMs = DEFAULT_API_TIMEOUT_MS, ...fetchOpts } = opts
      const doFetch = () => {
        let signal = fetchOpts.signal
        let timer = null
        if (timeoutMs && !signal && typeof AbortController !== 'undefined') {
          const ctrl = new AbortController()
          signal = ctrl.signal
          timer = setTimeout(() => ctrl.abort(), timeoutMs)
        }
        const p = fetch(`${BASE}${path}`, {
          credentials: 'include',
          headers: { 'Content-Type': 'application/json', ...fetchOpts.headers },
          ...fetchOpts,
          signal,
        })
        return timer ? p.finally(() => clearTimeout(timer)) : p
      }
      let res
      try {
        res = await doFetch()
        if (res.status === 401) {
          const refreshed = await refreshAccessToken()
          if (refreshed) res = await doFetch()
        }
      } catch (e) {
        if (e && e.name === 'AbortError') {
          throw new Error(T('datasets.parse_timeout'))
        }
        throw new Error(explainNetworkError(e, 'api'))
      }
      if (!res.ok) {
        const raw = await res.text().catch(() => '')
        let body = {}
        try {
          body = raw ? JSON.parse(raw) : {}
        } catch (_) {
          body = {}
        }
        let msg = body.error || ''
        if (!msg) {
          if (res.status === 413) msg = T('datasets.upload_too_large_server')
          else if (res.status === 524 || res.status === 504 || res.status === 502)
            msg = T('datasets.parse_gateway_timeout')
          else if (/cloudflare|timeout|524/i.test(raw)) msg = T('datasets.parse_gateway_timeout')
          else msg = `HTTP ${res.status}`
        }
        const err = new Error(msg)
        err.payload = body
        err.status = res.status
        throw err
      }
      return res.json()
    }

    async function apiUpload(path, file, extraFields = {}, opts = {}) {
      const timeoutMs = opts.timeoutMs ?? uploadTimeoutMsFor(file)
      const onProgress = typeof opts.onProgress === 'function' ? opts.onProgress : null

      const doXhr = () =>
        new Promise((resolve, reject) => {
          const fd = new FormData()
          fd.append('file', file)
          for (const [k, v] of Object.entries(extraFields)) fd.append(k, v)
          const xhr = new XMLHttpRequest()
          xhr.open('POST', `${BASE}${path}`)
          xhr.withCredentials = true
          xhr.timeout = timeoutMs
          xhr.responseType = 'text'
          if (xhr.upload && onProgress) {
            xhr.upload.onprogress = (ev) => {
              if (!ev.lengthComputable) return
              onProgress({
                loaded: ev.loaded,
                total: ev.total,
                percent: Math.round((ev.loaded / ev.total) * 100),
              })
            }
          }
          xhr.onload = () => {
            let body = {}
            try {
              body = xhr.responseText ? JSON.parse(xhr.responseText) : {}
            } catch (_) {
              body = {}
            }
            if (xhr.status === 401) {
              reject(
                Object.assign(new Error('HTTP 401'), {
                  status: 401,
                  payload: body,
                })
              )
              return
            }
            if (xhr.status < 200 || xhr.status >= 300) {
              let msg = body.error || ''
              if (!msg) {
                if (xhr.status === 413) msg = T('datasets.upload_too_large_server')
                else if (xhr.status === 524 || xhr.status === 504 || xhr.status === 502)
                  msg = T('datasets.parse_gateway_timeout')
                else msg = `HTTP ${xhr.status}`
              }
              reject(
                Object.assign(new Error(msg), {
                  status: xhr.status,
                  payload: body,
                })
              )
              return
            }
            resolve(body)
          }
          xhr.onerror = () => reject(new Error(T('datasets.network_error')))
          xhr.ontimeout = () => {
            const e = new Error(T('datasets.upload_timeout'))
            e.name = 'AbortError'
            e.code = 'TIMEOUT'
            reject(e)
          }
          xhr.send(fd)
        })

      try {
        return await doXhr()
      } catch (e) {
        if (e && e.status === 401) {
          const refreshed = await refreshAccessToken()
          if (refreshed) return await doXhr()
        }
        if (e && e.name === 'AbortError') {
          throw new Error(T('datasets.upload_timeout'))
        }
        throw e instanceof Error ? e : new Error(explainNetworkError(e, 'upload'))
      }
    }

    // =========================================================================
    // Utilities
    // =========================================================================

    function escHtml(s) {
      return String(s ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
    }

    // Small "?" affordance that reveals its help text in a CSS tooltip on
    // hover/focus (customer feedback: help texts should open as a tooltip
    // on mouseover instead of taking up permanent space under the field).
    function tooltipIcon(text) {
      if (!text) return ''
      return `<span class="tx-tooltip" tabindex="0" role="img" aria-label="${escHtml(text)}">${ICONS.question}<span class="tx-tooltip-bubble">${escHtml(text)}</span></span>`
    }

    function formatDate(d) {
      if (!d) return '—'
      try {
        return new Date(d).toLocaleDateString(undefined, {
          year: 'numeric',
          month: 'short',
          day: 'numeric',
        })
      } catch (_) {
        return d
      }
    }

    function statusBadge(status) {
      const colors = {
        draft: 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300',
        extracted: 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400',
        reviewed: 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400',
        generated: 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400',
        incomplete: 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400',
      }
      const label = T(`datasets.status_${status}`, status)
      const cls = colors[status] || colors.draft
      return `<span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium ${cls}">${escHtml(label)}</span>`
    }

    function datasetDisplayName(d) {
      const fv = d.field_values || {}
      const nameFromFields =
        fv.firstname || fv.lastname ? `${fv.firstname || ''} ${fv.lastname || ''}`.trim() : null
      return nameFromFields || d.name || fv.fullname || `#${d.id}`
    }

    function datasetHasDoc(d) {
      return d.files?.cv || (d.files?.additional && d.files.additional.length > 0)
    }

    function pluralize(n, singular, plural) {
      return n === 1 ? singular : plural
    }

    function showToast(msg, type = 'success') {
      const palette = {
        success: { bg: '#f0fdf4', color: '#166534', border: '#bbf7d0' },
        error: { bg: '#fef2f2', color: '#991b1b', border: '#fecaca' },
        warning: { bg: '#fffbeb', color: '#92400e', border: '#fde68a' },
        info: { bg: '#eff6ff', color: '#1e40af', border: '#bfdbfe' },
      }
      const p = palette[type] || palette.success
      // Warning toasts get extra dwell time — they usually carry
      // actionable guidance the user needs to read fully.
      const dwellMs = type === 'warning' ? 9000 : 4500
      const div = document.createElement('div')
      div.style.cssText = `position:fixed;top:16px;right:16px;z-index:9999;background:${p.bg};color:${p.color};border:1px solid ${p.border};padding:10px 18px;border-radius:8px;font-size:14px;box-shadow:0 4px 12px rgba(0,0,0,.15);transition:opacity .3s;max-width:520px;`
      div.textContent = msg
      document.body.appendChild(div)
      setTimeout(() => {
        div.style.opacity = '0'
        setTimeout(() => div.remove(), 300)
      }, dwellMs)
    }

    /**
     * Sticky alert at the top of the plugin page. Used for process outcomes
     * (upload / parse / timeout / generate) so they cannot vanish after 4s.
     * Pass sticky:false to auto-clear on the next navigation-less render
     * of a different alert. Dismiss with the × button.
     */
    function showPageAlert({ type = 'info', title = '', message = '', sticky = true } = {}) {
      state.pageAlert = {
        type,
        title: title || '',
        message: message || '',
        sticky: sticky !== false,
      }
      render()
      try {
        const root = findScrollRoot()
        if (root) root.scrollTo({ top: 0, behavior: 'smooth' })
        window.scrollTo({ top: 0, behavior: 'smooth' })
      } catch (_) {
        /* ignore */
      }
    }

    function clearPageAlert() {
      if (!state.pageAlert) return
      state.pageAlert = null
      render()
    }

    function renderPageAlert() {
      const a = state.pageAlert
      if (!a) return ''
      const palette = {
        success: {
          bg: 'color-mix(in srgb, var(--status-success, #16a34a) 12%, var(--bg-card))',
          border: 'var(--status-success, #16a34a)',
          color: 'var(--status-success, #166534)',
          icon: ICONS.check,
        },
        error: {
          bg: 'color-mix(in srgb, var(--status-error, #dc2626) 12%, var(--bg-card))',
          border: 'var(--status-error, #dc2626)',
          color: 'var(--status-error, #991b1b)',
          icon: ICONS.warning,
        },
        warning: {
          bg: 'color-mix(in srgb, var(--status-warning, #d97706) 14%, var(--bg-card))',
          border: 'var(--status-warning, #d97706)',
          color: 'var(--status-warning, #92400e)',
          icon: ICONS.warning,
        },
        info: {
          bg: 'color-mix(in srgb, var(--brand) 12%, var(--bg-card))',
          border: 'var(--brand)',
          color: 'var(--brand)',
          icon: ICONS.info,
        },
      }
      const p = palette[a.type] || palette.info
      const title = a.title ? `<div class="font-semibold">${escHtml(a.title)}</div>` : ''
      const msg = a.message
        ? `<div class="text-sm" style="opacity:.95">${escHtml(a.message)}</div>`
        : ''
      return `<div class="tx-page-alert" role="alert" aria-live="assertive" style="background:${p.bg};border-color:${p.border};color:${p.color}">
        <span class="flex-none mt-0.5">${p.icon}</span>
        <div class="flex-1 min-w-0">${title}${msg}</div>
        <button type="button" data-action="dismiss-page-alert" class="tx-page-alert-close" title="${T('app.close')}">${ICONS.close}</button>
      </div>`
    }

    /**
     * Modal dialog that lists template-doctor findings — what's wrong, what
     * the engine will do about it, and how to fix the source DOCX. We open
     * it on upload only when there is at least one ERROR-severity finding;
     * warnings/infos surface as a toast that links here.
     */
    function showTemplateLintDialog(template, lint) {
      const findings = (lint && lint.findings) || []
      const summary = (lint && lint.summary) || {}
      const sevColor = {
        error: {
          bg: '#fef2f2',
          border: '#fecaca',
          color: '#991b1b',
          chip: '#dc2626',
        },
        warning: {
          bg: '#fffbeb',
          border: '#fde68a',
          color: '#92400e',
          chip: '#d97706',
        },
        info: {
          bg: '#eff6ff',
          border: '#bfdbfe',
          color: '#1e40af',
          chip: '#2563eb',
        },
      }
      const overlay = document.createElement('div')
      overlay.style.cssText =
        'position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:10000;display:flex;align-items:center;justify-content:center;'
      const card = document.createElement('div')
      card.style.cssText =
        'background:#fff;max-width:680px;width:92%;max-height:88vh;overflow:auto;border-radius:14px;padding:22px 26px;box-shadow:0 20px 50px rgba(0,0,0,.25);font-size:14px;color:#0f172a;'
      const title = template?.name || T('templates.lint_dialog_title')
      const subtitle = T('templates.lint_dialog_subtitle')
        .replace('{errors}', String(summary.errors ?? 0))
        .replace('{warnings}', String(summary.warnings ?? 0))
        .replace('{infos}', String(summary.infos ?? 0))
        .replace('{placeholders}', String(summary.placeholders ?? 0))
      const body = findings
        .map((f) => {
          const p = sevColor[f.severity] || sevColor.info
          const sevLabel = T(`templates.lint_severity_${f.severity}`) || f.severity
          const safeMsg = (f.message || '').replace(
            /[<>&]/g,
            (c) => ({ '<': '&lt;', '>': '&gt;', '&': '&amp;' })[c]
          )
          const safeHint = (f.hint || '').replace(
            /[<>&]/g,
            (c) => ({ '<': '&lt;', '>': '&gt;', '&': '&amp;' })[c]
          )
          return `
            <div style="background:${p.bg};border:1px solid ${p.border};color:${p.color};
                        border-radius:10px;padding:12px 14px;margin:10px 0;">
              <div style="display:flex;gap:8px;align-items:center;margin-bottom:6px;">
                <span style="background:${p.chip};color:#fff;font-size:11px;text-transform:uppercase;
                             letter-spacing:.5px;padding:2px 8px;border-radius:999px;">${sevLabel}</span>
                <code style="font-size:11px;color:#475569;">${f.code}</code>
              </div>
              <div style="font-weight:500;line-height:1.4;">${safeMsg}</div>
              <div style="margin-top:8px;font-size:13px;color:#475569;line-height:1.5;">
                <strong>${T('templates.lint_hint_label')}:</strong> ${safeHint}
              </div>
            </div>`
        })
        .join('')
      card.innerHTML = `
        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:8px;">
          <div>
            <div style="font-size:18px;font-weight:600;margin-bottom:2px;">${T('templates.lint_dialog_title')}</div>
            <div style="font-size:13px;color:#64748b;">${title}</div>
          </div>
          <button id="tx-lint-close" style="background:#0f172a;color:#fff;border:0;border-radius:8px;padding:6px 12px;cursor:pointer;font-size:13px;">${T('app.close') || 'Close'}</button>
        </div>
        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:10px 12px;font-size:13px;color:#334155;margin-bottom:6px;">
          ${subtitle}
        </div>
        ${body}
        <div style="font-size:12px;color:#64748b;margin-top:12px;line-height:1.5;">
          ${T('templates.lint_dialog_footer')}
        </div>`
      overlay.appendChild(card)
      document.body.appendChild(overlay)
      const close = () => overlay.remove()
      overlay.addEventListener('click', (e) => {
        if (e.target === overlay) close()
      })
      card.querySelector('#tx-lint-close')?.addEventListener('click', close)
    }

    function collectionById(id) {
      return state.forms.find((f) => f.id == id) || null
    }

    function datasetsForCollection(formId) {
      return state.datasets.filter((d) => d.form_id == formId)
    }

    function collectionTemplates(collection) {
      const ids = collection?.template_ids || []
      return state.templates.filter((t) => ids.includes(t.id))
    }

    /**
     * The "last worked on" timestamp for a Collection. We take the latest of:
     *   1. the Collection's own updated_at / created_at
     *   2. any of its datasets' updated_at / created_at
     * so a Collection bubbles to the top whenever someone touches one of its
     * datasets too, not only when its own metadata changes. Returns 0 for
     * Collections with no timestamps at all (legacy rows) so they sink to
     * the bottom rather than randomly mixing in.
     */
    function collectionActivityAt(c) {
      if (!c) return 0
      let best = new Date(c.updated_at || c.created_at || 0).getTime() || 0
      const dsets = datasetsForCollection(c.id)
      for (const d of dsets) {
        const t = new Date(d.updated_at || d.created_at || 0).getTime() || 0
        if (t > best) best = t
      }
      return best
    }

    function sortCollectionsByActivity(list) {
      return list.slice().sort((a, b) => collectionActivityAt(b) - collectionActivityAt(a))
    }

    // =========================================================================
    // Live preview (Phase 4b)
    // =========================================================================

    /**
     * Collect the current value map from the rendered dataset form PLUS the
     * baseline from saved field_values and the last AI-extracted variables.
     * Priority: form input > AI-extracted value > saved field_values.
     */
    function buildPreviewValueMap(c, d) {
      const values = {}

      for (const [k, v] of Object.entries(d?.field_values || {})) {
        values[k] = v
      }
      const extracted = (state.datasetVariables && state.datasetVariables.variables) || {}
      for (const [k, ent] of Object.entries(extracted)) {
        if (ent && ent.value !== undefined && ent.value !== null && ent.value !== '') {
          values[k] = ent.value
        }
      }

      const form = el?.querySelector('#tx-entry-data-form')
      if (form) {
        for (const f of c.fields || []) {
          if (f.type === 'table') {
            const cols = f.columns || []
            const rows = []
            let ri = 0
            while (form.querySelector(`[name="${f.key}__${ri}__${cols[0]?.key}"]`)) {
              const row = {}
              for (const col of cols) {
                const cell = form.querySelector(`[name="${f.key}__${ri}__${col.key}"]`)
                const raw = cell?.value ?? ''
                if ((col.type || 'text') === 'list') {
                  row[col.key] = raw
                    .split(/\r?\n/)
                    .map((s) => s.trim())
                    .filter(Boolean)
                } else {
                  row[col.key] = raw
                }
              }
              rows.push(row)
              ri++
            }
            values[f.key] = rows
            continue
          }
          const input = form.querySelector(`[name="${f.key}"]`)
          if (!input) continue
          if (f.type === 'checkbox') values[f.key] = input.checked
          else if (f.type === 'list')
            values[f.key] = input.value
              .split('\n')
              .map((s) => s.trim())
              .filter(Boolean)
          else values[f.key] = input.value
        }
      }

      return values
    }

    /**
     * Resolve a single template placeholder key against the value map.
     * Understands: lists (joined by new lines), checkboxes (as glyphs for
     * checkb.X.yes / checkb.X.no, as "Ja"/"Nein" for plain checkbox keys),
     * and the {group}.{col} two-segment pattern used in row-template cells.
     */
    function resolvePlaceholderValue(key, values, c, rowCtx) {
      if (rowCtx && rowCtx.group) {
        const parts = key.split('.')
        if (parts.length === 2 && parts[0] === rowCtx.group && rowCtx.row) {
          const v = rowCtx.row[parts[1]]
          return v === undefined || v === null ? '' : String(v)
        }
      }

      // checkb.KEY.yes / checkb.KEY.no pair
      const mYes = /^checkb\.(.+)\.yes$/.exec(key)
      const mNo = /^checkb\.(.+)\.no$/.exec(key)
      if (mYes || mNo) {
        const base = mYes ? mYes[1] : mNo[1]
        const raw = values[base]
        const yes =
          raw === true ||
          String(raw).toLowerCase() === 'true' ||
          String(raw).toLowerCase() === 'ja' ||
          String(raw).toLowerCase() === 'yes' ||
          raw === 'on'
        const field = (c.fields || []).find((f) => f.key === base)
        const designer = (field && field.designer) || {}
        const on = designer.checked_glyph || '\u2612'
        const off = designer.unchecked_glyph || '\u2610'
        if (mYes) return yes ? on : off
        return !yes ? on : off
      }

      const field = (c.fields || []).find((f) => f.key === key)
      const raw = values[key]

      if (field && field.type === 'list') {
        if (Array.isArray(raw)) return raw.filter(Boolean).join('\n')
        return raw || ''
      }
      if (field && field.type === 'checkbox') {
        const yes =
          raw === true || String(raw).toLowerCase() === 'true' || String(raw).toLowerCase() === 'ja'
        return yes ? 'Ja' : 'Nein'
      }
      if (raw === undefined || raw === null) return ''
      if (Array.isArray(raw)) return raw.join(', ')
      if (typeof raw === 'object') return JSON.stringify(raw)
      return String(raw)
    }

    /**
     * Rewrite the HTML skeleton in #tx-preview-root to reflect the current
     * value map. Runs entirely on the client — no server call.
     *
     * Repeating rows (data-tx-row-template="X") are cloned once per dataset
     * row, with each clone's [data-tx-key] filled from that row's column
     * values. The original template row is hidden; if there are no rows yet,
     * the original stays visible so the user sees placeholders.
     */
    function applyPreviewValues(rootEl, c, values) {
      if (!rootEl) return

      // Pass 1: row-template expansion
      rootEl.querySelectorAll('tr[data-tx-row-template]').forEach((tmpl) => {
        const grp = tmpl.dataset.txRowTemplate
        const parent = tmpl.parentElement
        if (!parent) return

        // Remove any previous clones (they carry data-tx-row-clone=group).
        parent.querySelectorAll(`tr[data-tx-row-clone="${grp}"]`).forEach((n) => n.remove())

        const rows = values[grp]
        if (!Array.isArray(rows) || rows.length === 0) {
          tmpl.style.display = ''
          return
        }
        tmpl.style.display = 'none'

        // Resolve the table variable's columns, so we can tell which cell is a
        // list-typed column and needs to render as bullets inside the preview.
        const tableField = (c.fields || []).find((f) => f.type === 'table' && f.key === grp)
        const columnTypeByKey = {}
        for (const col of tableField?.columns || []) {
          columnTypeByKey[col.key] = col.type || 'text'
        }

        let insertAfter = tmpl
        rows.forEach((row) => {
          const clone = tmpl.cloneNode(true)
          clone.removeAttribute('data-tx-row-template')
          clone.setAttribute('data-tx-row-clone', grp)
          clone.style.display = ''
          clone.querySelectorAll('.tx-ph[data-tx-key]').forEach((span) => {
            const parts = span.dataset.txKey.split('.')
            const colKey = parts.length === 2 ? parts[1] : null
            const colType = colKey ? columnTypeByKey[colKey] : null

            // List-typed cells render as a proper <ul> so the preview matches
            // the final .docx (one bullet per item).
            if (colType === 'list' && colKey) {
              const raw = row[colKey]
              const items = Array.isArray(raw)
                ? raw
                : typeof raw === 'string'
                  ? raw
                      .split(/\r?\n/)
                      .map((s) => s.trim())
                      .filter(Boolean)
                  : []
              span.classList.remove('tx-empty-val', 'tx-cb-on', 'tx-cb-off')
              if (items.length === 0) {
                span.textContent = '(—)'
                span.classList.add('tx-empty-val')
                return
              }
              span.classList.add('tx-filled')
              span.innerHTML = `<ul class="tx-preview-bullets">${items
                .map((it) => `<li>${escHtml(String(it))}</li>`)
                .join('')}</ul>`
              return
            }

            const val = resolvePlaceholderValue(span.dataset.txKey, values, c, {
              group: grp,
              row,
            })
            fillPlaceholderSpan(span, val)
          })
          parent.insertBefore(clone, insertAfter.nextSibling)
          insertAfter = clone
        })
      })

      // Pass 2: every non-row placeholder not inside a template/clone
      rootEl.querySelectorAll('.tx-ph[data-tx-key]').forEach((span) => {
        if (span.closest('tr[data-tx-row-template]')) return
        if (span.closest('tr[data-tx-row-clone]')) return
        const raw = span.dataset.txRaw || span.dataset.txKey
        const key = span.dataset.txKey
        const field = (c.fields || []).find((f) => f.key === key)

        // Image-typed placeholders get an actual <img> tag so the preview looks
        // like the final document.
        if (field && field.type === 'image') {
          fillImagePlaceholderSpan(span, values[key], field, raw)
          return
        }

        const isCb = /^checkb\./.test(raw)
        const val = resolvePlaceholderValue(key, values, c, null)
        fillPlaceholderSpan(span, val, { rawKey: raw, isCheckbox: isCb })
      })
    }

    function fillImagePlaceholderSpan(span, meta, field, rawKey) {
      const d = state.selectedDataset
      span.classList.remove('tx-filled', 'tx-empty-val', 'tx-cb-on', 'tx-cb-off')
      if (!meta || typeof meta !== 'object' || !meta.mime || !d) {
        span.textContent = '{{' + rawKey + '}}'
        span.classList.add('tx-empty-val')
        span.title = T('preview.missing_value')
        return
      }
      const url = `${BASE}/candidates/${d.id}/image/${encodeURIComponent(field.key)}?v=${encodeURIComponent(meta.uploaded_at || '')}`
      const w = (field.designer && field.designer.width) || 140
      const h = (field.designer && field.designer.height) || 180
      span.innerHTML = `<img src="${escHtml(url)}" alt="${escHtml(field.label || field.key)}" style="width:${w}px;height:${h}px;object-fit:cover;display:inline-block" />`
      span.classList.add('tx-filled')
      span.title = meta.original_name || ''
    }

    function fillPlaceholderSpan(span, val, opts) {
      const rawKey = (opts && opts.rawKey) || span.dataset.txRaw || span.dataset.txKey
      const isCb = opts && opts.isCheckbox

      span.classList.remove('tx-filled', 'tx-empty-val', 'tx-cb-on', 'tx-cb-off')

      if (val === undefined || val === null || val === '') {
        span.textContent = '{{' + rawKey + '}}'
        span.classList.add('tx-empty-val')
        span.title = T('preview.missing_value')
        return
      }

      span.textContent = val
      span.title = ''
      if (isCb) {
        // checked glyph vs unchecked
        if (val === '\u2612' || /^(x|y|yes|true|ja)$/i.test(val)) {
          span.classList.add('tx-cb-on')
        } else {
          span.classList.add('tx-cb-off')
        }
      } else {
        span.classList.add('tx-filled')
      }
    }

    function updatePreviewNow() {
      if (!state.selectedDataset) return
      const c = collectionById(state.collectionId)
      if (!c) return
      // The preview lives in the body-level modal portal now; fall back to
      // el for older code paths just in case.
      const rootEl =
        modalPortal?.querySelector('#tx-preview-root') || el?.querySelector('#tx-preview-root')
      if (!rootEl) return
      const values = buildPreviewValueMap(c, state.selectedDataset)
      applyPreviewValues(rootEl, c, values)
    }

    function schedulePreviewUpdate() {
      if (state.previewDebounceTimer) clearTimeout(state.previewDebounceTimer)
      state.previewDebounceTimer = setTimeout(() => {
        state.previewDebounceTimer = null
        updatePreviewNow()
      }, 150)
    }

    async function loadPreviewSkeleton(templateId, opts) {
      if (!templateId) return
      const force = !!(opts && opts.force)

      // De-dupe: if a fetch for the same template is already in flight, skip.
      if (state.previewLoading && state.previewTemplateId === templateId) return

      // Don't loop on a known failure for this template unless the caller explicitly
      // asked to retry (refresh button, template change).
      if (!force && state.previewError && state.previewTemplateId === templateId) return

      state.previewTemplateId = templateId
      state.previewLoading = true
      state.previewError = null
      state.previewSkeleton = null
      render()
      try {
        await refreshAccessToken()
        const res = await api(`/templates/${encodeURIComponent(templateId)}/preview-html`)
        state.previewSkeleton = {
          html: res.html || '',
          row_groups: res.row_groups || [],
          placeholders: res.placeholders || [],
          schema: res.schema_version || 0,
        }
      } catch (err) {
        state.previewError = err.message || String(err)
      }
      state.previewLoading = false
      render()
      // After render, prime the preview with current values
      setTimeout(updatePreviewNow, 0)
    }

    // =========================================================================
    // Router
    // =========================================================================

    // Includes legacy tab names ("variables", "templates", "danger") so old
    // bookmarks still parse cleanly — normalizeCollectionTab() then folds
    // them into the new "setup" tab (or the Danger Zone modal).
    const VALID_TABS = [
      'overview',
      'datasets',
      'setup',
      'export',
      'variables',
      'templates',
      'danger',
    ]

    function parseHash() {
      const raw = window.location.hash.replace(/^#tx-?/, '')
      if (!raw) return { view: 'collections' }
      const parts = raw.split('/')
      if (parts[0] === 'settings') return { view: 'settings' }
      if (parts[0] === 'prompts') return { view: 'prompts' }
      if (parts[0] === 'c' && parts[1]) {
        const res = { view: 'collection', collectionId: parts[1] }
        if (parts[2] && VALID_TABS.includes(parts[2])) res.tab = parts[2]
        else res.tab = 'overview'
        if (parts[2] === 'datasets' && parts[3]) res.datasetId = parts[3]
        return res
      }
      return { view: 'collections' }
    }

    function applyHash() {
      const r = parseHash()
      state.view = r.view
      state.collectionId = r.collectionId || null
      state.tab = normalizeCollectionTab(r.tab || 'overview')
      state.datasetId = r.datasetId || null
    }

    // The Collection page used to expose six tabs (overview / variables /
    // templates / datasets / export / danger). Variables and Templates are
    // now folded behind a single "Set up" tab, and Danger Zone is a kebab
    // menu modal. Legacy hashes are remapped so bookmarks still land on
    // the right page; old #/danger requests pop the modal automatically.
    function normalizeCollectionTab(tab) {
      if (tab === 'variables' || tab === 'templates' || tab === 'setup') {
        return 'setup'
      }
      if (tab === 'danger') {
        state.dangerOpen = true
        return 'overview'
      }
      if (['overview', 'datasets', 'export'].includes(tab)) {
        return tab
      }
      return 'overview'
    }

    function writeHash() {
      let h = '#tx-'
      if (state.view === 'settings') h += 'settings'
      else if (state.view === 'prompts') h += 'prompts'
      else if (state.view === 'collection' && state.collectionId) {
        h += `c/${state.collectionId}/${state.tab || 'overview'}`
        if (state.tab === 'datasets' && state.datasetId) h += `/${state.datasetId}`
      } else h = '#tx-'
      if (window.location.hash !== h) window.location.hash = h
    }

    async function navigate(updates) {
      if (typeof updates.tab === 'string') {
        updates.tab = normalizeCollectionTab(updates.tab)
      }
      Object.assign(state, updates)
      // Reset transient state per view
      if (updates.view && updates.view !== 'collection') {
        state.collectionId = null
        state.datasetId = null
        state.selectedDataset = null
        state.newDatasetOpen = false
        state.newCollectionOpen = false
        state.collectionMenuOpen = false
        state.dangerOpen = false
        state.setupMenuOpen = null
      }
      if (updates.view === 'collection' || updates.tab) {
        state.collectionMenuOpen = false
        state.selectedDataset = null
        state.datasetId = updates.datasetId || null
        state.datasetVariables = null
        state.datasetExtracting = false
        state.datasetGenerating = false
        state.datasetParsing = false
        state.datasetParseStep = 0
        state.reorderingFields = false
        state.selectedGenerateTemplate = null
        state.generateInstruction = ''
        state.variablesDraft = null
        state.variablesDirty = false
        state.variablesImportOpen = false
        state.variablesImportFields = null
        state.variablesImportError = null
        state.variablesImportText = ''
        state.selectedTemplateDetail = null
        state.templateMatchOpen = false
        state.templateMatchAddKey = null
        state.dangerConfirmText = ''
      }
      writeHash()
      await loadTranslations()
      render()
      await loadData()
      if (state.view === 'collection' && state.tab === 'datasets' && state.datasetId) {
        await selectDataset(state.datasetId)
      }
    }

    window.addEventListener('hashchange', () => {
      const prevView = state.view
      const prevCollection = state.collectionId
      const prevTab = state.tab
      const prevDataset = state.datasetId
      applyHash()
      if (
        prevView !== state.view ||
        prevCollection !== state.collectionId ||
        prevTab !== state.tab ||
        prevDataset !== state.datasetId
      ) {
        navigate({})
      }
    })

    // =========================================================================
    // Icons
    // =========================================================================

    const ICONS = {
      folder:
        '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7a2 2 0 012-2h4l2 2h8a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2V7z"/></svg>',
      folderOpen:
        '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7a2 2 0 012-2h4l2 2h8a2 2 0 012 2v1H3V7zm0 3h18l-1.5 7a2 2 0 01-1.97 1.5H6.47A2 2 0 014.5 17L3 10z"/></svg>',
      variable:
        '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v6m0 4v6M6 8v8M18 8v8M4 12h16"/></svg>',
      file: '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>',
      database:
        '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><ellipse cx="12" cy="5" rx="9" ry="3" stroke-width="2"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5v6a9 3 0 0018 0V5M3 11v6a9 3 0 0018 0v-6"/></svg>',
      export:
        '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M12 4v12m-4-4l4 4 4-4"/></svg>',
      warning:
        '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4.5c-.77-.833-2.694-.833-3.464 0L3.34 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>',
      gear: '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>',
      plus: '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>',
      upload:
        '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>',
      trash:
        '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>',
      download:
        '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>',
      check:
        '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>',
      back: '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>',
      sparkle:
        '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09zM18.259 8.715L18 9.75l-.259-1.035a3.375 3.375 0 00-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 002.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 002.455 2.456L21.75 6l-1.036.259a3.375 3.375 0 00-2.455 2.456z"/></svg>',
      doc: '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z"/></svg>',
      edit: '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10"/></svg>',
      info: '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>',
      chevDown:
        '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>',
      chevRight:
        '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>',
      search:
        '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>',
      sortDown:
        '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4h13M3 8h9m-9 4h6m4 0l4 4m0 0l4-4m-4 4V4"/></svg>',
      sortUp:
        '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4h13M3 8h9m-9 4h9m5-4v12m0-12l4-4m-4 4l-4-4"/></svg>',
      arrowUp:
        '<svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7"/></svg>',
      arrowDown:
        '<svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>',
      grip: '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 6h.01M12 6h.01M8 12h.01M12 12h.01M8 18h.01M12 18h.01"/></svg>',
      question:
        '<svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093M12 17h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>',
      close:
        '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>',
      link: '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/></svg>',
      refresh:
        '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>',
      clock:
        '<svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>',
    }

    // =========================================================================
    // Styles
    // =========================================================================

    const TX_STYLES = `
      .tx { color: var(--txt-primary); }
      .tx-secondary { color: var(--txt-secondary); }
      .tx-card { background: var(--bg-card); border-radius: .75rem; box-shadow: inset 0 0 0 1px var(--border-light), 0 1px 2px rgba(0,0,0,.04); }
      .tx-row { background: var(--bg-card); box-shadow: inset 0 0 0 1px var(--border-light); border-radius: .5rem; }
      .tx-row:hover { box-shadow: inset 0 0 0 1px var(--brand-alpha-light), 0 2px 6px rgba(0,0,0,.06); }
      .tx-input, .tx-select, .tx-textarea { background: var(--bg-app); color: var(--txt-primary); border: 1px solid var(--divider); border-radius: .375rem; padding: .5rem .75rem; font-size: .875rem; outline: none; width: 100%; }
      .tx-input:focus, .tx-select:focus, .tx-textarea:focus { border-color: var(--brand); box-shadow: 0 0 0 2px var(--brand-alpha-light); }
      .tx-input::placeholder, .tx-textarea::placeholder { color: var(--txt-secondary); opacity: .6; }
      .tx-btn { background: var(--brand); color: #fff; border: none; border-radius: .375rem; padding: .5rem 1rem; font-size: .875rem; font-weight: 500; cursor: pointer; display: inline-flex; align-items: center; gap: .375rem; transition: background .15s; }
      .tx-btn:hover { background: var(--brand-hover); }
      .tx-btn:disabled { opacity: .5; cursor: not-allowed; }
      .tx-btn-sm { padding: .375rem .75rem; font-size: .8125rem; }
      .tx-btn-danger { background: var(--status-error); }
      .tx-btn-danger:hover { opacity: .85; }
      .tx-btn-ghost { background: transparent; color: var(--brand); box-shadow: inset 0 0 0 1px var(--divider); }
      .tx-btn-ghost:hover { background: var(--brand-alpha-light); }
      .tx-btn-link { background: transparent; color: var(--brand); padding: 0; border: none; text-decoration: none; }
      .tx-btn-link:hover { text-decoration: underline; }
      .tx-link { color: var(--brand); cursor: pointer; }
      .tx-link:hover { text-decoration: underline; }
      .tx-label { display: block; font-size: .8125rem; font-weight: 500; color: var(--txt-primary); margin-bottom: .25rem; }
      .tx-hint { font-size: .75rem; color: var(--txt-secondary); margin-top: .375rem; line-height: 1.4; }
      .tx-badge { display: inline-flex; align-items: center; padding: .125rem .5rem; border-radius: .25rem; font-size: .75rem; font-weight: 500; }
      .tx-render-badge { display: inline-flex; align-items: center; gap: .25rem; padding: .125rem .5rem; border-radius: .375rem; font-size: .6875rem; font-weight: 500; white-space: nowrap; border: 1px solid transparent; }
      .tx-render-badge > span[aria-hidden="true"] { font-size: .875rem; line-height: 1; }
      .tx-badge-list  { background: color-mix(in srgb, var(--brand) 10%, transparent); color: var(--brand); border-color: color-mix(in srgb, var(--brand) 25%, transparent); }
      .tx-badge-check { background: color-mix(in srgb, var(--status-success, #10b981) 12%, transparent); color: var(--status-success, #10b981); border-color: color-mix(in srgb, var(--status-success, #10b981) 30%, transparent); }
      .tx-badge-table { background: color-mix(in srgb, var(--status-warning, #f59e0b) 12%, transparent); color: var(--status-warning, #f59e0b); border-color: color-mix(in srgb, var(--status-warning, #f59e0b) 30%, transparent); }
      .tx-badge-image { background: color-mix(in srgb, var(--status-info, #3b82f6) 12%, transparent); color: var(--status-info, #3b82f6); border-color: color-mix(in srgb, var(--status-info, #3b82f6) 30%, transparent); }
      .tx-badge-plain { background: var(--bg-chip); color: var(--txt-secondary); border-color: var(--divider); }
      .tx-divider { border-color: var(--divider); }
      .tx-drop { display: block; border: 2px dashed var(--divider); border-radius: .5rem; background: var(--bg-card); text-align: center; padding: 1.25rem; cursor: pointer; transition: border-color .15s, background .15s; }
      .tx-drop:hover { border-color: var(--brand); background: var(--brand-alpha-light); }
      .tx-help-trigger { display: inline-flex; align-items: center; justify-content: center; width: 1.1rem; height: 1.1rem; border-radius: 9999px; color: var(--txt-secondary); cursor: pointer; border: none; background: transparent; padding: 0; }
      .tx-help-trigger:hover { color: var(--brand); background: var(--brand-alpha-light); }
      .tx-tooltip { position: relative; display: inline-flex; align-items: center; justify-content: center; width: 1.1rem; height: 1.1rem; border-radius: 9999px; color: var(--txt-secondary); cursor: help; vertical-align: middle; flex: none; }
      .tx-tooltip:hover, .tx-tooltip:focus-visible { color: var(--brand); }
      .tx-tooltip-bubble { position: absolute; bottom: calc(100% + .4rem); left: 50%; transform: translateX(-50%); background: var(--bg-card); color: var(--txt-primary); border: 1px solid var(--divider); box-shadow: 0 6px 16px rgba(0,0,0,.18); border-radius: .5rem; padding: .5rem .625rem; font-size: .75rem; font-weight: 400; line-height: 1.45; width: max-content; max-width: 300px; white-space: pre-line; z-index: 9999; opacity: 0; visibility: hidden; transition: opacity .12s; pointer-events: none; text-align: left; }
      .tx-tooltip:hover .tx-tooltip-bubble, .tx-tooltip:focus-within .tx-tooltip-bubble { opacity: 1; visibility: visible; }
      .tx-modal-bg { position: fixed; inset: 0; background: rgba(0,0,0,.5); z-index: 9998; display: flex; align-items: center; justify-content: center; padding: 1rem; }
      .tx-modal { background: var(--bg-card); border-radius: .75rem; box-shadow: 0 20px 40px rgba(0,0,0,.2); max-width: 560px; width: 100%; max-height: 90vh; overflow-y: auto; }
      .tx-tab { display: inline-flex; align-items: center; gap: .5rem; padding: .625rem 1rem; font-size: .875rem; font-weight: 500; white-space: nowrap; transition: color .15s, border-color .15s; border-bottom: 2px solid transparent; color: var(--txt-secondary); cursor: pointer; background: transparent; border-left: none; border-right: none; border-top: none; }
      .tx-tab:hover { color: var(--txt-primary); }
      .tx-tab.active { color: var(--brand); border-bottom-color: var(--brand); }
      .tx-tab.danger { color: var(--status-error); opacity: .85; }
      .tx-tab.danger.active { border-bottom-color: var(--status-error); }
      .tx-collection-card { position: relative; padding: 1.25rem; background: var(--bg-card); color: var(--txt-primary); border: none; border-radius: .75rem; box-shadow: inset 0 0 0 1px var(--border-light); transition: box-shadow .15s, transform .15s; cursor: pointer; display: flex; flex-direction: column; gap: .75rem; width: 100%; font: inherit; text-align: left; }
      .tx-collection-card:hover { box-shadow: inset 0 0 0 1px var(--brand-alpha-light), 0 6px 16px rgba(0,0,0,.08); transform: translateY(-1px); }
      .tx-collection-card:focus-visible { outline: 2px solid var(--brand); outline-offset: 2px; }
      .tx-callout { padding: .75rem 1rem; border-radius: .5rem; background: var(--brand-alpha-light); color: var(--txt-primary); font-size: .8125rem; }
      .tx-danger-zone { border: 1px solid color-mix(in srgb, var(--status-error) 40%, transparent); border-radius: .75rem; padding: 1.25rem; background: color-mix(in srgb, var(--status-error) 6%, var(--bg-card)); }
      .tx-page-alert { display:flex; align-items:flex-start; gap:.75rem; margin: .75rem 0 0; padding: .85rem 1rem; border-radius: .6rem; border: 1px solid; box-shadow: 0 1px 2px rgba(0,0,0,.04); }
      .tx-page-alert-close { flex:none; background:transparent; border:none; padding:.15rem; cursor:pointer; color:inherit; opacity:.7; border-radius:.25rem; }
      .tx-page-alert-close:hover { opacity:1; background: rgba(0,0,0,.06); }

      /* Live preview — rendered as a wide modal overlay so the dataset form
         can use the full screen width when the preview is closed. */
      .tx-modal--wide { max-width: min(1200px, 95vw); }
      .tx-modal--preview { padding: 0; max-height: 92vh; display: flex; flex-direction: column; overflow: hidden; }
      .tx-preview-panel { display: flex; flex-direction: column; height: 100%; min-height: 0; }
      .tx-preview-panel .tx-preview-toolbar { display: flex; align-items: center; gap: .5rem; padding: .5rem .75rem; border-bottom: 1px solid var(--divider); background: var(--bg-card); flex: 0 0 auto; }
      .tx-preview-panel .tx-preview-viewport { flex: 1 1 auto; min-height: 0; overflow: auto; padding: 1.25rem; background: #ffffff; color: #1a1a1a; }
      .tx-preview { font-family: "Calibri", "Helvetica Neue", Arial, sans-serif; font-size: 11pt; line-height: 1.35; max-width: 780px; margin: 0 auto; color: #1a1a1a; }
      .tx-preview h1 { font-size: 18pt; font-weight: 700; margin: .6em 0 .3em; color: #1a1a1a; }
      .tx-preview h2 { font-size: 14pt; font-weight: 700; margin: .6em 0 .3em; }
      .tx-preview h3 { font-size: 12pt; font-weight: 700; margin: .6em 0 .3em; }
      .tx-preview p { margin: 0 0 .4em; }
      .tx-preview p.tx-empty { margin: .2em 0; min-height: 1em; }
      .tx-preview p.tx-li { margin-left: 1.25em; position: relative; }
      .tx-preview p.tx-li::before { content: "\\2022"; position: absolute; left: -1em; color: #555; }
      .tx-preview table.tx-tbl { border-collapse: collapse; width: 100%; margin: .3em 0 .6em; }
      .tx-preview table.tx-tbl td { border: 1px solid #e0e0e0; padding: .25em .5em; vertical-align: top; }
      .tx-preview .tx-b { font-weight: 700; }
      .tx-preview .tx-i { font-style: italic; }
      .tx-preview .tx-u { text-decoration: underline; }
      .tx-preview .tx-ph { background: rgba(255, 221, 89, .45); padding: 0 .1em; border-radius: 2px; transition: background .15s; }
      .tx-preview .tx-ph.tx-filled { background: rgba(77, 200, 142, .18); }
      .tx-preview .tx-ph.tx-empty-val { background: rgba(255, 221, 89, .65); color: #6b5300; font-style: italic; }
      .tx-preview .tx-ph.tx-cb-on { background: transparent; color: #1a1a1a; font-weight: 700; }
      .tx-preview .tx-ph.tx-cb-off { background: transparent; color: #777; }
      .tx-preview ul.tx-preview-bullets { list-style: disc; margin: 0; padding-left: 1.1em; }
      .tx-preview ul.tx-preview-bullets li { margin: 0 0 .15em; }
      .tx-preview-missing { padding: 2rem; text-align: center; color: var(--txt-secondary); font-size: .875rem; }

      /* Drag-and-drop reordering (variables, dataset fields, table rows) */
      .tx-drag-handle { cursor: grab; touch-action: none; }
      .tx-drag-handle:active { cursor: grabbing; }
      .tx-dragging { opacity: .45; }
      .tx-drag-over-top { box-shadow: inset 0 0 0 1px var(--border-light), 0 -3px 0 0 var(--brand) !important; }
      .tx-drag-over-bottom { box-shadow: inset 0 0 0 1px var(--border-light), 0 3px 0 0 var(--brand) !important; }

      /* Sticky save bar on long edit forms + floating back-to-top button */
      .tx-sticky-save { position: sticky; bottom: 0; z-index: 20; margin-top: .5rem; padding: .625rem .75rem; background: var(--bg-card); border-radius: .5rem; box-shadow: inset 0 0 0 1px var(--border-light), 0 -4px 12px rgba(0,0,0,.08); display: flex; align-items: center; gap: .5rem; }
      .tx-backtop { position: fixed; right: 1.25rem; bottom: 1.25rem; z-index: 40; width: 2.5rem; height: 2.5rem; border-radius: 9999px; border: none; background: var(--brand); color: #fff; display: flex; align-items: center; justify-content: center; cursor: pointer; box-shadow: 0 4px 12px rgba(0,0,0,.25); opacity: .85; transition: opacity .15s; }
      .tx-backtop:hover { opacity: 1; }
    `

    /**
     * Generic HTML5 drag-and-drop reordering for a vertical list of rows.
     * Complements (does not replace) the up/down arrow buttons — arrows stay
     * for keyboard/accessibility, dragging is the fast path (customer
     * feedback: reordering long field lists with arrows is painful).
     *
     * When `handleSelector` is given, dragging only starts from that handle,
     * so rows containing inputs/textareas keep normal text selection.
     * `onDrop(fromIdx, toIdx)` receives indexes in DOM order.
     */
    function enableDragReorder(container, { rowSelector, handleSelector, onDrop }) {
      if (!container) return
      const rows = Array.from(container.querySelectorAll(rowSelector))
      let fromIdx = null
      const clearMarkers = () =>
        rows.forEach((r) =>
          r.classList.remove('tx-dragging', 'tx-drag-over-top', 'tx-drag-over-bottom')
        )
      rows.forEach((row, idx) => {
        const handle = handleSelector ? row.querySelector(handleSelector) : row
        if (!handle) return
        if (handleSelector) {
          handle.addEventListener('mousedown', () => row.setAttribute('draggable', 'true'))
          // Without a handle the whole row is always draggable.
        } else {
          row.setAttribute('draggable', 'true')
          row.classList.add('tx-drag-handle')
        }
        row.addEventListener('dragstart', (e) => {
          fromIdx = idx
          row.classList.add('tx-dragging')
          e.dataTransfer.effectAllowed = 'move'
          try {
            e.dataTransfer.setData('text/plain', String(idx))
          } catch (_e) {
            /* IE/legacy quirk, harmless */
          }
        })
        row.addEventListener('dragend', () => {
          fromIdx = null
          if (handleSelector) row.removeAttribute('draggable')
          clearMarkers()
        })
        row.addEventListener('dragover', (e) => {
          if (fromIdx === null || idx === fromIdx) return
          e.preventDefault()
          e.dataTransfer.dropEffect = 'move'
          const rect = row.getBoundingClientRect()
          const before = e.clientY < rect.top + rect.height / 2
          row.classList.toggle('tx-drag-over-top', before)
          row.classList.toggle('tx-drag-over-bottom', !before)
        })
        row.addEventListener('dragleave', () => {
          row.classList.remove('tx-drag-over-top', 'tx-drag-over-bottom')
        })
        row.addEventListener('drop', (e) => {
          if (fromIdx === null) return
          e.preventDefault()
          const rect = row.getBoundingClientRect()
          const before = e.clientY < rect.top + rect.height / 2
          let to = before ? idx : idx + 1
          if (to > fromIdx) to -= 1
          const from = fromIdx
          fromIdx = null
          clearMarkers()
          if (to !== from) onDrop(from, to)
        })
      })
    }

    // =========================================================================
    // Render engine
    // =========================================================================

    // Body-level portal for modal overlays so they escape every nested
    // scrolling/stacking context the synaplan main layout creates around the
    // plugin host (header, sidebar, surface-card, etc.). Created lazily on
    // first render and removed when the plugin host is unmounted.
    let modalPortal = null
    let portalCleanupObserver = null

    function ensureModalPortal() {
      if (modalPortal && modalPortal.isConnected) return modalPortal
      const doc = el.ownerDocument || document
      modalPortal = doc.createElement('div')
      modalPortal.className = 'tx tx-modal-portal'
      doc.body.appendChild(modalPortal)

      // When the plugin host is wiped (PluginView clears innerHTML on
      // unmount/route change), tear down the portal so we don't leak it.
      try {
        portalCleanupObserver?.disconnect()
        portalCleanupObserver = new MutationObserver(() => {
          if (!el.isConnected || el.childNodes.length === 0) {
            destroyModalPortal()
          }
        })
        portalCleanupObserver.observe(el, { childList: true })
      } catch (_e) {
        // Defensive: if MutationObserver isn't available we just leak the
        // portal until the page reloads — better than crashing on render.
      }
      return modalPortal
    }

    function destroyModalPortal() {
      try {
        portalCleanupObserver?.disconnect()
      } catch (_e) {
        /* noop */
      }
      portalCleanupObserver = null
      if (modalPortal && modalPortal.parentNode) {
        modalPortal.parentNode.removeChild(modalPortal)
      }
      modalPortal = null
    }

    function renderModalPortal() {
      // The page-level modal is always the dataset preview today. If we add
      // more modals we route them through here.
      const showPreview = state.view === 'datasets' && state.selectedDataset && state.previewVisible

      if (!showPreview) {
        if (modalPortal) modalPortal.innerHTML = ''
        return
      }

      const c = collectionById(state.collectionId)
      const d = state.selectedDataset
      if (!c || !d) {
        if (modalPortal) modalPortal.innerHTML = ''
        return
      }
      const templates = collectionTemplates(c)
      if (templates.length === 0) {
        if (modalPortal) modalPortal.innerHTML = ''
        return
      }

      const portal = ensureModalPortal()
      portal.innerHTML = `
        <div class="tx-modal-bg" data-action="preview-hide">
          <div class="tx-modal tx-modal--wide tx-modal--preview tx-preview-panel" id="tx-preview-panel" onclick="event.stopPropagation()">
            ${renderDatasetPreviewPanel(c, d, templates)}
          </div>
        </div>`
    }

    function render() {
      el.innerHTML = `
        <style>${TX_STYLES}</style>
        <div class="tx max-w-6xl mx-auto px-4 py-6">
          ${renderHeader()}
          ${renderNav()}
          ${renderPageAlert()}
          <div id="tx-content" class="mt-4">${renderView()}</div>
        </div>`
      renderModalPortal()
      bindEvents()
      setupBackToTop()
    }

    // Floating "back to top" button (customer feedback: long variable and
    // dataset pages force endless scrolling). The plugin can be hosted in a
    // window-scrolled OR inner-scrolled layout, so we watch both.
    let backToTopBound = false
    function findScrollRoot() {
      let n = el.parentElement
      while (n) {
        const s = getComputedStyle(n)
        if (/(auto|scroll)/.test(s.overflowY) && n.scrollHeight > n.clientHeight + 50) return n
        n = n.parentElement
      }
      return null
    }
    function setupBackToTop() {
      const doc = el.ownerDocument || document
      let btn = doc.getElementById('tx-backtop')
      if (!btn) {
        btn = doc.createElement('button')
        btn.id = 'tx-backtop'
        btn.type = 'button'
        btn.className = 'tx tx-backtop'
        btn.title = T('app.back_to_top')
        btn.innerHTML = ICONS.arrowUp
        btn.hidden = true
        doc.body.appendChild(btn)
        btn.addEventListener('click', () => {
          const root = findScrollRoot()
          if (root) root.scrollTo({ top: 0, behavior: 'smooth' })
          window.scrollTo({ top: 0, behavior: 'smooth' })
        })
      }
      if (!backToTopBound) {
        backToTopBound = true
        const update = () => {
          const b = doc.getElementById('tx-backtop')
          if (!b) return
          if (!el.isConnected) {
            // Plugin host unmounted — remove the body-level button.
            b.remove()
            return
          }
          const root = findScrollRoot()
          const offset = Math.max(root ? root.scrollTop : 0, window.scrollY)
          b.hidden = offset < 400
        }
        window.addEventListener('scroll', update, { passive: true })
        doc.addEventListener('scroll', update, {
          passive: true,
          capture: true,
        })
      }
    }

    function renderHeader() {
      return `<div class="flex items-start justify-between mb-1">
        <div>
          <h1 class="text-2xl font-bold tracking-tight">${T('app.title')}</h1>
          <p class="text-xs tx-secondary mt-0.5">${T('app.tagline')}</p>
        </div>
        <span class="text-xs text-gray-400 font-mono">${TX_VERSION}</span>
      </div>`
    }

    function renderNav() {
      const items = [
        { id: 'collections', icon: ICONS.folder, label: T('nav.collections') },
        { id: 'prompts', icon: ICONS.sparkle, label: T('nav.prompts') },
        { id: 'settings', icon: ICONS.gear, label: T('nav.settings') },
      ]
      const activeId = state.view === 'collection' ? 'collections' : state.view
      return `<nav class="flex gap-1 overflow-x-auto" style="border-bottom:1px solid var(--divider)">
        ${items
          .map((item) => {
            const active = item.id === activeId
            const style = active
              ? 'border-bottom:2px solid var(--brand);color:var(--brand)'
              : 'border-bottom:2px solid transparent;color:var(--txt-secondary)'
            return `<button data-nav="${item.id}" class="flex items-center gap-1.5 px-3 py-2 text-sm font-medium whitespace-nowrap transition-colors" style="${style}">${item.icon} ${item.label}</button>`
          })
          .join('')}
      </nav>`
    }

    function renderView() {
      if (state.loading) return renderLoading()
      if (state.error) return renderError(state.error)
      switch (state.view) {
        case 'settings':
          return renderSettings()
        case 'prompts':
          return renderPrompts()
        case 'collection':
          return renderCollection()
        case 'collections':
        default:
          return renderCollectionsList()
      }
    }

    function renderLoading() {
      return `<div class="flex items-center justify-center py-12">
        <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-500"></div>
        <span class="ml-3 text-gray-500 dark:text-gray-400">${T('app.loading')}</span>
      </div>`
    }

    function renderError(msg) {
      return `<div class="rounded-lg border border-red-200 bg-red-50 dark:bg-red-900/20 dark:border-red-800 p-4 mt-4">
        <p class="text-red-600 dark:text-red-400">${T('app.error')}: ${escHtml(msg)}</p>
      </div>`
    }

    function helpTrigger(topicKey) {
      return `<button type="button" data-help="${topicKey}" class="tx-help-trigger" title="${T('app.help')}">${ICONS.question}</button>`
    }

    function renderHelpModal() {
      const openTopics = {
        collections: state.showCollectionHelp,
        variables: state.showVariablesHelp,
        templates: state.showTemplatesHelp,
        export: state.showExportHelp,
      }
      const active = Object.keys(openTopics).find((k) => openTopics[k])
      if (!active) return ''
      const titleKey =
        active === 'collections' ? 'collections.help_what_is_title' : `${active}.help_title`
      const bodyKey =
        active === 'collections' ? 'collections.help_what_is_body' : `${active}.help_body`
      const body = T(bodyKey).replace(/\n/g, '<br>')
      return `<div class="tx-modal-bg" data-action="close-help">
        <div class="tx-modal p-6" onclick="event.stopPropagation()">
          <div class="flex items-center justify-between mb-3">
            <h3 class="text-lg font-semibold">${T(titleKey)}</h3>
            <button data-action="close-help" class="tx-help-trigger">${ICONS.close}</button>
          </div>
          <p class="text-sm leading-relaxed tx-secondary">${body}</p>
        </div>
      </div>`
    }

    // =========================================================================
    // Collections list view
    // =========================================================================

    /**
     * A Collection is treated as a "draft" when the user has created the
     * shell but not yet defined any variables or attached any templates
     * and hasn't gathered any datasets either. This heuristic intentionally
     * also covers Collections created through the new split-button "guided
     * wizard" entry point so users can pick up where they left off without
     * any backend schema changes. Once the user adds a single variable,
     * uploads a template, or saves a dataset, the Collection graduates
     * out of the Drafts section automatically.
     */
    function isDraftCollection(c) {
      const vars = c.fields?.length || 0
      const tpls = collectionTemplates(c).length
      const dsets = datasetsForCollection(c.id).length
      return vars === 0 && tpls === 0 && dsets === 0
    }

    /**
     * Returns true when the Collection is ready to do daily work
     * (defined variables AND at least one Word template attached).
     * Used to decide the default landing tab when a card is opened.
     */
    function isCollectionReady(c) {
      const vars = c?.fields?.length || 0
      const tpls = collectionTemplates(c).length
      return vars > 0 && tpls > 0
    }

    /**
     * Default landing tab when the user opens a Collection (from a card
     * click or from any cross-link without an explicit tab). Ready
     * Collections jump straight to Datasets — the user's daily work
     * surface — while incomplete or draft ones stay on Overview where
     * the next-step nudges live.
     */
    function defaultLandingTab(c) {
      return isCollectionReady(c) ? 'datasets' : 'overview'
    }

    /**
     * Most recent dataset that is NOT yet finished — i.e. something the
     * user is likely mid-flow on. Generated documents are excluded
     * because re-opening them rarely has value; users typically want to
     * pick up an incomplete one. Returns null when there is nothing
     * to continue.
     */
    function findContinuableDataset(collectionId) {
      const open = datasetsForCollection(collectionId).filter((d) => {
        const s = d.status || 'draft'
        return s !== 'generated'
      })
      if (open.length === 0) return null
      return open
        .slice()
        .sort(
          (a, b) =>
            new Date(b.updated_at || b.created_at || 0) -
            new Date(a.updated_at || a.created_at || 0)
        )[0]
    }

    /**
     * Split-button for setup-time entry points. The primary click runs the
     * "guided wizard" path; the dropdown exposes the per-entry-point
     * shortcuts and a manual escape hatch. PR2 wires every option to the
     * existing manual New-Collection modal (the wizard ships in PR3); the
     * entry-point mode is forwarded via `data-mode` so the wizard can
     * later choose which step to land on.
     *
     * `id` distinguishes multiple instances on the same page (Collections
     * list, Drafts banner, etc.) so the dropdown state doesn't bleed.
     */
    function renderSetupSplitButton(id, opts = {}) {
      const label = opts.label || T('collections.new')
      const size = opts.size === 'sm' ? 'tx-btn-sm' : ''
      const open = state.setupMenuOpen === id
      const dropdownItems = [
        {
          mode: 'wizard',
          icon: ICONS.sparkle,
          label: T('setup.menu_wizard'),
          hint: T('setup.menu_wizard_hint'),
          primary: true,
        },
        {
          mode: 'template',
          icon: ICONS.file,
          label: T('setup.menu_from_template'),
          hint: T('setup.menu_from_template_hint'),
        },
        {
          mode: 'fromDoc',
          icon: ICONS.sparkle,
          label: T('setup.menu_from_doc'),
          hint: T('setup.menu_from_doc_hint'),
        },
        {
          mode: 'text',
          icon: ICONS.upload,
          label: T('setup.menu_from_text'),
          hint: T('setup.menu_from_text_hint'),
        },
        { divider: true },
        {
          mode: 'manual',
          icon: ICONS.edit,
          label: T('setup.menu_manual'),
          hint: T('setup.menu_manual_hint'),
        },
      ]

      const menu = open
        ? `<div data-action="setup-menu-close" style="position:fixed;inset:0;z-index:30"></div>
           <div role="menu" data-testid="setup-menu-${escHtml(id)}" class="tx-card" style="position:absolute;top:calc(100% + .25rem);right:0;min-width:18rem;z-index:31;padding:.375rem 0;box-shadow:0 8px 24px rgba(0,0,0,.16)">
             ${dropdownItems
               .map((item) => {
                 if (item.divider) {
                   return `<div style="height:1px;background:var(--divider);margin:.25rem 0"></div>`
                 }
                 return `<button data-action="setup-action" data-mode="${item.mode}" data-id="${escHtml(id)}" data-testid="setup-menu-item-${item.mode}" role="menuitem" class="w-full text-left flex items-start gap-2 px-3 py-2 text-sm tx-row" style="border-radius:0;background:transparent;box-shadow:none">
                   <span style="color:var(--brand);flex-shrink:0;margin-top:.125rem">${item.icon}</span>
                   <span class="flex-1">
                     <span class="block font-medium${item.primary ? '' : ''}">${escHtml(item.label)}</span>
                     ${item.hint ? `<span class="block text-xs tx-secondary mt-0.5">${escHtml(item.hint)}</span>` : ''}
                   </span>
                 </button>`
               })
               .join('')}
           </div>`
        : ''

      return `<div style="position:relative;display:inline-flex" data-testid="setup-splitbutton-${escHtml(id)}">
        <button data-action="setup-action" data-mode="wizard" data-id="${escHtml(id)}" data-testid="setup-splitbutton-primary-${escHtml(id)}" class="tx-btn ${size}" style="border-top-right-radius:0;border-bottom-right-radius:0">
          ${ICONS.sparkle} <span>${escHtml(label)}</span>
        </button>
        <button data-action="setup-menu-toggle" data-id="${escHtml(id)}" data-testid="setup-splitbutton-chevron-${escHtml(id)}" aria-haspopup="menu" aria-expanded="${open ? 'true' : 'false'}" aria-label="${T('setup.menu_more_options')}" class="tx-btn ${size}" style="border-top-left-radius:0;border-bottom-left-radius:0;border-left:1px solid color-mix(in srgb, #000 18%, transparent);padding-left:.5rem;padding-right:.5rem">
          ${ICONS.chevDown}
        </button>
        ${menu}
      </div>`
    }

    function renderDraftsSection(drafts) {
      if (drafts.length === 0) return ''
      const cards = drafts
        .map(
          (
            d
          ) => `<div class="tx-row p-4 flex items-center justify-between gap-3" data-testid="draft-card" data-draft-id="${escHtml(d.id)}">
            <div class="min-w-0 flex-1">
              <div class="flex items-center gap-2">
                <span style="color:var(--status-warning,#d97706)">${ICONS.clock}</span>
                <span class="font-semibold truncate">${escHtml(d.name)}</span>
                <span class="tx-badge" style="background:color-mix(in srgb, var(--status-warning,#d97706) 14%, transparent);color:var(--status-warning,#d97706)">${T('collections.draft_badge')}</span>
              </div>
              ${d.description ? `<p class="text-xs tx-secondary mt-1 line-clamp-2">${escHtml(d.description)}</p>` : ''}
              <div class="text-xs tx-secondary mt-1">${T('app.created')}: ${formatDate(d.created_at)}</div>
            </div>
            <div class="flex items-center gap-2 flex-shrink-0">
              <button data-action="draft-resume" data-id="${escHtml(d.id)}" data-testid="draft-resume" class="tx-btn tx-btn-sm">
                ${ICONS.sparkle} ${T('collections.draft_resume')}
              </button>
              <button data-action="draft-discard" data-id="${escHtml(d.id)}" data-name="${escHtml(d.name)}" data-testid="draft-discard" class="tx-btn tx-btn-sm tx-btn-ghost" style="color:var(--status-error)" title="${T('collections.draft_discard_hint')}">
                ${ICONS.trash}
              </button>
            </div>
          </div>`
        )
        .join('')
      return `<section data-testid="drafts-section" class="space-y-2">
        <div class="flex items-center gap-2">
          <h3 class="text-sm font-semibold uppercase tracking-wider tx-secondary">${T('collections.drafts_title')} (${drafts.length})</h3>
          <span class="text-xs tx-secondary">— ${T('collections.drafts_hint')}</span>
        </div>
        <div class="space-y-1.5">${cards}</div>
      </section>`
    }

    function renderCollectionsList() {
      const q = state.collectionsSearch.trim().toLowerCase()
      const allForms = state.forms || []
      // Both sections (drafts + ready) get sorted by most-recent-activity
      // first so the user's current work always sits at the top instead of
      // wherever the API happened to return it. Activity = the latest of
      // the Collection's own updated_at and any of its datasets'
      // updated_at, so editing a dataset bubbles its Collection up too.
      const matchesQuery = (f) => {
        if (!q) return true
        return f.name?.toLowerCase().includes(q) || f.description?.toLowerCase().includes(q)
      }
      const drafts = sortCollectionsByActivity(
        allForms.filter((f) => isDraftCollection(f)).filter(matchesQuery)
      )
      const ready = sortCollectionsByActivity(
        allForms.filter((f) => !isDraftCollection(f)).filter(matchesQuery)
      )

      const cards = ready
        .map((c) => {
          const datasets = datasetsForCollection(c.id)
          const templates = collectionTemplates(c)
          const vars = c.fields?.length || 0
          return `<button type="button" data-open-collection="${c.id}" class="tx-collection-card text-left" aria-label="${T('collections.card_open')}: ${escHtml(c.name)}">
            <div class="flex items-start gap-3">
              <span class="inline-flex items-center justify-center w-9 h-9 rounded-full flex-shrink-0" style="background:var(--brand-alpha-light);color:var(--brand)">${ICONS.folder}</span>
              <div class="min-w-0 flex-1">
                <div class="font-semibold text-base truncate">${escHtml(c.name)}</div>
                ${
                  c.description
                    ? `<p class="text-xs tx-secondary mt-0.5 line-clamp-2">${escHtml(c.description)}</p>`
                    : ''
                }
              </div>
            </div>
            <div class="flex items-center gap-4 text-xs tx-secondary">
              <span class="inline-flex items-center gap-1">${ICONS.variable} <span>${vars} ${pluralize(vars, T('collections.card_variables_one'), T('collections.card_variables'))}</span></span>
              <span class="inline-flex items-center gap-1">${ICONS.file} <span>${templates.length} ${pluralize(templates.length, T('collections.card_templates_one'), T('collections.card_templates'))}</span></span>
              <span class="inline-flex items-center gap-1">${ICONS.database} <span>${datasets.length} ${pluralize(datasets.length, T('collections.card_datasets_one'), T('collections.card_datasets'))}</span></span>
            </div>
            <div class="flex items-center justify-between pt-2" style="border-top:1px solid var(--divider)">
              <span class="text-xs tx-secondary">${formatDate(c.created_at)}</span>
              <span class="text-sm tx-link font-medium">${T('collections.card_open')} →</span>
            </div>
          </button>`
        })
        .join('')

      const emptyHelp = `<div class="tx-card p-6 text-center">
        <div class="inline-flex items-center justify-center w-12 h-12 rounded-full mb-3" style="background:var(--bg-chip);color:var(--txt-secondary)">${ICONS.folder}</div>
        <h3 class="font-semibold text-lg mb-1">${T('collections.empty')}</h3>
        <p class="text-sm tx-secondary" style="max-width:440px;margin-left:auto;margin-right:auto">${T('collections.empty_hint')}</p>
      </div>`

      return `<div class="mt-4 space-y-5">
        <div class="flex items-start justify-between gap-3 flex-wrap">
          <div>
            <div class="flex items-center gap-2 mb-1">
              <h2 class="text-xl font-semibold">${T('collections.title')}</h2>
              ${helpTrigger('collections')}
            </div>
            <p class="text-sm tx-secondary" style="max-width:640px">${T('collections.subtitle')}</p>
          </div>
          <div class="flex items-center gap-2">
            ${
              allForms.length > 0
                ? `<div class="relative">
                    <span class="absolute top-1/2 -translate-y-1/2 text-gray-400 pointer-events-none" style="left:12px">${ICONS.search}</span>
                    <input id="tx-collections-search" type="text" value="${escHtml(state.collectionsSearch)}" placeholder="${T('app.search')}" class="tx-input" style="padding-left:36px;min-width:220px" />
                  </div>`
                : ''
            }
            ${renderSetupSplitButton('new')}
          </div>
        </div>
        ${renderDraftsSection(drafts)}
        ${
          allForms.length === 0
            ? emptyHelp
            : ready.length === 0
              ? ''
              : `<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">${cards}</div>`
        }
        ${state.newCollectionOpen ? renderCollectionEditor() : ''}
        ${state.wizard.open ? renderWizardModal() : ''}
        ${renderHelpModal()}
      </div>`
    }

    // =========================================================================
    // Setup Wizard (5 steps)
    //
    //   Step 0 — Chooser ("I have a template" vs "I don't have one yet")
    //   Step 1 — Basics (name + description + language) — creates the draft
    //   Step 2 — Template upload (skipped on the "noTemplate" path)
    //   Step 3 — Fields (auto-derived from template, or AI-parsed text,
    //                    or jumps to the manual editor)
    //   Step 4 — Review + finish
    //
    // Every step persists progress against a real Collection in the
    // backend, so cancelling at any point leaves a resumable draft.
    // =========================================================================

    const WIZARD_STEPS = ['chooser', 'basics', 'template', 'fields', 'review']

    function visibleWizardSteps() {
      // "noTemplate" path hides the Template step but keeps a placeholder
      // pill so the progress bar doesn't visually shrink mid-flow.
      // "fromDoc" path keeps every step — the Template step is the
      // dedicated AI-suggest landing area for that flow.
      return state.wizard.mode === 'noTemplate'
        ? WIZARD_STEPS.filter((s) => s !== 'template')
        : WIZARD_STEPS
    }

    function wizardOpen(opts = {}) {
      const w = state.wizard
      const mode = opts.mode === 'template' ? 'template' : null
      // Reset everything to baseline so a previous run never leaks into
      // a fresh wizard session. Resume mode (opts.resumeCollectionId)
      // hydrates the basics from the existing form so step 1 is
      // pre-filled.
      const baseline = {
        open: true,
        step: 0,
        mode,
        collectionId: opts.resumeCollectionId || null,
        name: '',
        description: '',
        language: state.config?.default_language || 'en',
        templateId: null,
        templateName: '',
        templatePlaceholderCount: 0,
        templateFile: null,
        templateUploading: false,
        templateError: null,
        suggestions: null,
        suggestionsLoading: false,
        suggestionsError: null,
        suggestionsSelected: {},
        pastedText: '',
        pastedParsing: false,
        pastedError: null,
        pastedFields: null,
        // "fromDoc" mode — AI-suggest from a placeholder-less draft .docx.
        // Tracks per-suggestion metadata (source snippet + whether the
        // backend was able to inject {{placeholder}} automatically).
        fromDocApplied: 0,
        fromDocTotal: 0,
        fromDocModel: null,
        // Progress for the multi-stage AI pipeline that runs behind
        // wizardAiSuggestFromDocx(). See the matching block in
        // state.wizard initial state for purpose.
        aiSuggestStep: 0,
        aiSuggestStartedAt: null,
        aiSuggestElapsedSec: 0,
        aiSuggestTimer: null,
        fields: [],
        saving: false,
        error: null,
      }
      if (opts.resumeCollectionId) {
        const c = collectionById(opts.resumeCollectionId)
        if (c) {
          baseline.name = c.name || ''
          baseline.description = c.description || ''
          baseline.language = c.language || baseline.language
          baseline.fields = c.fields || []
          // Skip the chooser when resuming — they already made the
          // structural decision when they first started.
          baseline.step = 1
          baseline.mode = collectionTemplates(c).length > 0 ? 'template' : 'noTemplate'
        }
      }
      // When opt.mode is "text" we jump straight into the text-paste
      // flow inside step 3, which we model as the noTemplate path.
      if (opts.mode === 'text') {
        baseline.mode = 'noTemplate'
        baseline.step = 1
      } else if (opts.mode === 'fromDoc') {
        baseline.mode = 'fromDoc'
        baseline.step = 1
      } else if (mode === 'template') {
        baseline.step = 1
      }
      Object.assign(state.wizard, baseline)
      state.setupMenuOpen = null
      render()
    }

    function wizardClose() {
      // We deliberately do NOT delete the draft Collection here — the
      // whole point of persistent drafts is that closing mid-flow
      // leaves the user a Continue-setup card on the Collections list.
      state.wizard.open = false
      render()
    }

    function wizardCanAdvance() {
      const w = state.wizard
      switch (w.step) {
        case 0:
          return w.mode === 'template' || w.mode === 'noTemplate' || w.mode === 'fromDoc'
        case 1:
          return (w.name || '').trim().length > 0
        case 2:
          // Template step: either upload one (templateId set) or skip
          // (the Skip button itself calls wizardNext, so the Next button
          // only enables when a template is uploaded).
          return !!w.templateId
        case 3:
          // Fields step is OK as long as the user has SOMETHING to
          // generate against. They can also continue with zero fields
          // and edit later in the manual editor.
          return true
        case 4:
          return true
        default:
          return false
      }
    }

    async function wizardSaveBasics() {
      const w = state.wizard
      const payload = {
        name: w.name.trim(),
        description: (w.description || '').trim(),
        language: w.language || state.config.default_language || 'en',
      }
      w.saving = true
      w.error = null
      render()
      try {
        if (w.collectionId) {
          await api(`/forms/${w.collectionId}`, {
            method: 'PUT',
            body: JSON.stringify(payload),
          })
        } else {
          const res = await api('/forms', {
            method: 'POST',
            body: JSON.stringify({ ...payload, fields: [] }),
          })
          w.collectionId = res.form.id
        }
        await fetchForms()
      } catch (err) {
        w.error = err.message
        throw err
      } finally {
        w.saving = false
      }
    }

    async function wizardUploadTemplate() {
      const w = state.wizard
      const file = w.templateFile
      if (!file || !w.collectionId) return
      const fd = new FormData()
      fd.append('file', file)
      fd.append('name', file.name.replace(/\.docx$/i, ''))
      w.templateUploading = true
      w.templateError = null
      render()
      try {
        const res = await fetch(`${BASE}/templates`, {
          method: 'POST',
          credentials: 'include',
          body: fd,
        })
        if (!res.ok) {
          let msg = `Upload failed (${res.status})`
          try {
            const j = await res.json()
            if (j.message) msg = j.message
          } catch (_) {
            /* ignore */
          }
          throw new Error(msg)
        }
        const body = await res.json()
        w.templateId = body.template.id
        w.templateName = body.template.name
        w.templatePlaceholderCount =
          body.template.placeholder_count ?? body.template.placeholders?.length ?? 0
        // Link the template to this collection so it appears on the
        // Templates tab + survives wizard cancellation.
        const c = collectionById(w.collectionId)
        const existing = Array.isArray(c?.template_ids) ? c.template_ids : []
        if (!existing.includes(body.template.id)) {
          await api(`/forms/${w.collectionId}`, {
            method: 'PUT',
            body: JSON.stringify({
              template_ids: [...existing, body.template.id],
            }),
          })
        }
        await Promise.all([fetchTemplates(), fetchForms()])
        await wizardLoadSuggestions()
      } catch (err) {
        w.templateError = err.message
      } finally {
        w.templateUploading = false
        render()
      }
    }

    // "fromDoc" mode: upload a draft .docx that has NO {{placeholders}}
    // yet. The backend asks the AI to identify what should be variables,
    // injects placeholders into a copy of the document, and returns the
    // new template plus a per-suggestion application status (so the UI
    // can flag the ones that need a manual fix in Word).
    async function wizardAiSuggestFromDocx() {
      const w = state.wizard
      const file = w.templateFile
      if (!file || !w.collectionId) return
      const fd = new FormData()
      fd.append('file', file)
      fd.append('name', file.name.replace(/\.docx$/i, ''))
      if (w.language) fd.append('language', w.language)
      w.templateUploading = true
      w.templateError = null
      w.suggestionsError = null
      // Backend pipeline this drives: extract → classify (1 AI call) →
      // propose-per-window (1+ AI calls) → deterministic refine. Each
      // stage advances aiSuggestStep so the UI can label progress
      // instead of showing a single dumb spinner.
      w.aiSuggestStep = 0
      w.aiSuggestStartedAt = Date.now()
      w.aiSuggestElapsedSec = 0
      if (w.aiSuggestTimer) clearInterval(w.aiSuggestTimer)
      w.aiSuggestTimer = setInterval(() => {
        if (!w.templateUploading || !w.aiSuggestStartedAt) return
        w.aiSuggestElapsedSec = Math.round((Date.now() - w.aiSuggestStartedAt) / 1000)
        // Time-based step nudges so the label keeps moving even
        // though a single fetch can't tell us the real backend
        // stage. Calibrated to the typical 15–30 s pipeline runtime.
        const e = w.aiSuggestElapsedSec
        if (e >= 22) w.aiSuggestStep = Math.max(w.aiSuggestStep, 3)
        else if (e >= 8) w.aiSuggestStep = Math.max(w.aiSuggestStep, 2)
        else if (e >= 2) w.aiSuggestStep = Math.max(w.aiSuggestStep, 1)
        render()
      }, 1000)
      render()
      const aiSuggestCtrl = typeof AbortController !== 'undefined' ? new AbortController() : null
      const aiSuggestTimer = aiSuggestCtrl ? setTimeout(() => aiSuggestCtrl.abort(), 240000) : null
      try {
        const res = await fetch(`${BASE}/templates/ai-suggest-from-docx`, {
          method: 'POST',
          credentials: 'include',
          body: fd,
          signal: aiSuggestCtrl ? aiSuggestCtrl.signal : undefined,
        })
        if (!res.ok) {
          let msg = `Upload failed (${res.status})`
          try {
            const j = await res.json()
            if (j.error) msg = j.error
            else if (j.message) msg = j.message
          } catch (_) {
            /* ignore */
          }
          throw new Error(msg)
        }
        w.aiSuggestStep = 4
        const body = await res.json()
        w.templateId = body.template.id
        w.templateName = body.template.name
        w.templatePlaceholderCount =
          body.template.placeholder_count ?? body.template.placeholders?.length ?? 0
        w.fromDocApplied = body.applied_count || 0
        w.fromDocTotal = body.suggestion_count || (body.suggestions || []).length
        w.fromDocModel = body.model || null
        // Reshape into the same envelope renderWizardStepFields*()
        // expects, so existing save/select code keeps working unchanged.
        w.suggestions = { suggestions: body.suggestions || [] }
        const sel = {}
        ;(body.suggestions || []).forEach((s, i) => {
          sel[i] = true
        })
        w.suggestionsSelected = sel

        const c = collectionById(w.collectionId)
        const existing = Array.isArray(c?.template_ids) ? c.template_ids : []
        if (!existing.includes(body.template.id)) {
          await api(`/forms/${w.collectionId}`, {
            method: 'PUT',
            body: JSON.stringify({
              template_ids: [...existing, body.template.id],
            }),
          })
        }
        await Promise.all([fetchTemplates(), fetchForms()])
      } catch (err) {
        w.templateError =
          err && err.name === 'AbortError' ? T('datasets.parse_timeout') : err.message
      } finally {
        if (aiSuggestTimer) clearTimeout(aiSuggestTimer)
        if (w.aiSuggestTimer) {
          clearInterval(w.aiSuggestTimer)
          w.aiSuggestTimer = null
        }
        w.templateUploading = false
        w.aiSuggestStep = 0
        w.aiSuggestStartedAt = null
        w.aiSuggestElapsedSec = 0
        render()
      }
    }

    async function wizardLoadSuggestions() {
      const w = state.wizard
      if (!w.templateId) return
      w.suggestionsLoading = true
      w.suggestionsError = null
      render()
      try {
        const res = await api(`/templates/${w.templateId}/variable-suggestions`)
        w.suggestions = res
        // Pre-tick every non-duplicate suggestion. Users uncheck what
        // they don't want — much faster than ticking from a blank state.
        const sel = {}
        ;(res.suggestions || []).forEach((s, i) => {
          sel[i] = s._status !== 'duplicate'
        })
        w.suggestionsSelected = sel
      } catch (err) {
        w.suggestionsError = err.message
      } finally {
        w.suggestionsLoading = false
        render()
      }
    }

    async function wizardParsePastedText() {
      const w = state.wizard
      if (!w.pastedText.trim()) return
      w.pastedParsing = true
      w.pastedError = null
      render()
      try {
        const res = await api('/forms/import-parse', {
          method: 'POST',
          timeoutMs: 180000,
          body: JSON.stringify({ text: w.pastedText }),
        })
        w.pastedFields = res.fields || []
      } catch (err) {
        w.pastedError = err.message
      } finally {
        w.pastedParsing = false
        render()
      }
    }

    async function wizardSaveFields() {
      const w = state.wizard
      let chosen = []
      // Both `template` and `fromDoc` modes funnel their picks through the
      // same `suggestions` envelope; the only difference is fromDoc never
      // marks duplicates (each AI suggestion gets a fresh slot) and carries
      // a `source_text`/`applied`/`applied_reason` we strip before saving.
      if ((w.mode === 'template' || w.mode === 'fromDoc') && w.suggestions) {
        chosen = (w.suggestions.suggestions || [])
          .map((s, i) => ({ s, i }))
          .filter(({ s, i }) => w.suggestionsSelected[i] && s._status !== 'duplicate')
          .map(({ s }) => {
            const copy = { ...s }
            delete copy._status
            delete copy.source_text
            delete copy.applied
            delete copy.applied_reason
            delete copy.placeholder
            return copy
          })
      } else if (w.pastedFields) {
        chosen = w.pastedFields.map((f) => ({ ...f }))
      }
      // Merge with any fields the existing Collection already had
      // (resume case: don't blow away existing variables silently).
      const c = collectionById(w.collectionId)
      const existing = c?.fields || []
      const existingKeys = new Set(existing.map((f) => f.key))
      const merged = [...existing, ...chosen.filter((f) => !existingKeys.has(f.key))]
      w.fields = merged
      w.saving = true
      w.error = null
      render()
      try {
        await api(`/forms/${w.collectionId}`, {
          method: 'PUT',
          body: JSON.stringify({ fields: merged }),
        })
        await fetchForms()
      } catch (err) {
        w.error = err.message
        throw err
      } finally {
        w.saving = false
      }
    }

    async function wizardNext() {
      const w = state.wizard
      try {
        if (w.step === 1) await wizardSaveBasics()
        if (w.step === 3) await wizardSaveFields()
        const steps = visibleWizardSteps()
        const currentName = WIZARD_STEPS[w.step]
        let idx = steps.indexOf(currentName)
        if (idx < 0) idx = 0
        const nextName = steps[Math.min(steps.length - 1, idx + 1)]
        w.step = WIZARD_STEPS.indexOf(nextName)
        render()
      } catch (_) {
        /* error already surfaced via state.wizard.error */
      }
    }

    function wizardBack() {
      const w = state.wizard
      const steps = visibleWizardSteps()
      const currentName = WIZARD_STEPS[w.step]
      let idx = steps.indexOf(currentName)
      if (idx <= 0) {
        // From step 1 back to step 0; clear chosen mode so the chooser
        // is a fresh decision.
        w.step = 0
        if (currentName === 'basics') w.mode = null
      } else {
        const prevName = steps[idx - 1]
        w.step = WIZARD_STEPS.indexOf(prevName)
      }
      render()
    }

    async function wizardFinish(landOn) {
      const w = state.wizard
      const cid = w.collectionId
      w.open = false
      await fetchForms()
      if (!cid) {
        render()
        return
      }
      // "dataset" primary CTA always goes to Datasets so users start
      // collecting immediately. "collection" secondary CTA uses the
      // ready-check so an incomplete Collection still surfaces the
      // Overview nudges instead of dropping the user on an empty
      // Datasets tab.
      const c = collectionById(cid)
      const tab = landOn === 'dataset' ? 'datasets' : defaultLandingTab(c)
      navigate({ view: 'collection', collectionId: cid, tab })
    }

    function renderWizardModal() {
      const w = state.wizard
      const steps = visibleWizardSteps()
      const currentName = WIZARD_STEPS[w.step]
      const currentVisibleIdx = Math.max(0, steps.indexOf(currentName))

      const pills = steps
        .map((name, idx) => {
          const isPast = idx < currentVisibleIdx
          const isActive = idx === currentVisibleIdx
          const numCol = isPast
            ? 'background:var(--status-success);color:#fff'
            : isActive
              ? 'background:var(--brand);color:#fff'
              : 'background:var(--bg-chip);color:var(--txt-secondary)'
          const labelCol = isActive
            ? 'color:var(--txt-primary);font-weight:600'
            : 'color:var(--txt-secondary)'
          return `<div class="flex items-center gap-2 flex-1 min-w-0">
            <span class="inline-flex items-center justify-center rounded-full text-xs font-bold flex-shrink-0" style="width:1.5rem;height:1.5rem;${numCol}">${isPast ? '✓' : idx + 1}</span>
            <span class="text-xs truncate" style="${labelCol}">${T(`wizard.step_${name}_label`)}</span>
            ${idx < steps.length - 1 ? `<span class="flex-1" style="height:2px;background:${isPast ? 'var(--status-success)' : 'var(--divider)'}"></span>` : ''}
          </div>`
        })
        .join('')

      let body = ''
      switch (currentName) {
        case 'chooser':
          body = renderWizardStepChooser()
          break
        case 'basics':
          body = renderWizardStepBasics()
          break
        case 'template':
          body = renderWizardStepTemplate()
          break
        case 'fields':
          body = renderWizardStepFields()
          break
        case 'review':
          body = renderWizardStepReview()
          break
      }

      const errorBanner = w.error
        ? `<div class="tx-callout" style="background:color-mix(in srgb, var(--status-error) 12%, var(--bg-card));color:var(--status-error)">${escHtml(w.error)}</div>`
        : ''

      const isFinal = currentName === 'review'
      const canAdvance = wizardCanAdvance()
      const isFirst = currentName === 'chooser'

      return `<div class="tx-modal-bg" data-action="wizard-close-backdrop" data-testid="wizard-modal">
        <div class="tx-modal tx-modal--wide p-0" style="max-width:min(900px, 95vw);display:flex;flex-direction:column;max-height:92vh" onclick="event.stopPropagation()">
          <div class="flex items-center justify-between p-5" style="border-bottom:1px solid var(--divider);flex:0 0 auto">
            <h3 class="text-lg font-semibold flex items-center gap-2">
              <span style="color:var(--brand)">${ICONS.sparkle}</span>
              <span>${T('wizard.title')}</span>
            </h3>
            <button data-action="wizard-close" data-testid="wizard-close" class="tx-help-trigger" aria-label="${T('app.close')}">${ICONS.close}</button>
          </div>
          <div class="px-5 py-3" style="border-bottom:1px solid var(--divider);flex:0 0 auto">
            <div class="flex items-center gap-2">${pills}</div>
          </div>
          <div class="p-5 space-y-4 overflow-y-auto" data-testid="wizard-step-${currentName}" style="flex:1 1 auto;min-height:0">
            ${errorBanner}
            ${body}
          </div>
          <div class="flex items-center justify-between gap-3 p-4" style="border-top:1px solid var(--divider);flex:0 0 auto;background:var(--bg-app)">
            <div>
              ${
                !isFirst
                  ? `<button data-action="wizard-back" data-testid="wizard-back" class="tx-btn tx-btn-sm tx-btn-ghost">${ICONS.back} ${T('app.back')}</button>`
                  : ''
              }
            </div>
            <div class="flex items-center gap-2">
              <button data-action="wizard-close" class="tx-btn tx-btn-sm tx-btn-ghost">${T('app.cancel')}</button>
              ${
                isFinal
                  ? `<button data-action="wizard-finish-collection" data-testid="wizard-finish" class="tx-btn tx-btn-sm tx-btn-ghost">${T('wizard.finish_open_collection')}</button>
                     <button data-action="wizard-finish-dataset" data-testid="wizard-finish-primary" class="tx-btn tx-btn-sm"${w.saving ? ' disabled' : ''}>${ICONS.sparkle} ${T('wizard.finish_add_dataset')}</button>`
                  : `<button data-action="wizard-next" data-testid="wizard-next" class="tx-btn tx-btn-sm"${!canAdvance || w.saving ? ' disabled' : ''}>${w.saving ? T('app.loading') : T('app.continue')} ${ICONS.chevRight}</button>`
              }
            </div>
          </div>
        </div>
      </div>`
    }

    function renderWizardStepChooser() {
      const mode = state.wizard.mode
      const card = (
        m,
        icon,
        title,
        hint
      ) => `<button type="button" data-action="wizard-pick-mode" data-mode="${m}" data-testid="wizard-mode-${m}" class="tx-row p-5 text-left flex items-start gap-3" style="${mode === m ? 'box-shadow:inset 0 0 0 2px var(--brand)' : ''}">
        <span class="inline-flex items-center justify-center w-10 h-10 rounded-full flex-shrink-0" style="background:var(--brand-alpha-light);color:var(--brand)">${icon}</span>
        <div>
          <div class="font-semibold">${escHtml(title)}</div>
          <div class="text-xs tx-secondary mt-1">${escHtml(hint)}</div>
        </div>
      </button>`
      return `<div class="space-y-3">
        <h4 class="text-base font-semibold">${T('wizard.chooser_title')}</h4>
        <p class="text-sm tx-secondary">${T('wizard.chooser_subtitle')}</p>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
          ${card('template', ICONS.file, T('wizard.mode_template_title'), T('wizard.mode_template_hint'))}
          ${card('fromDoc', ICONS.sparkle, T('wizard.mode_from_doc_title'), T('wizard.mode_from_doc_hint'))}
          ${card('noTemplate', ICONS.variable, T('wizard.mode_no_template_title'), T('wizard.mode_no_template_hint'))}
        </div>
      </div>`
    }

    function renderWizardStepBasics() {
      const w = state.wizard
      const lang = w.language || state.config.default_language || 'en'
      return `<form id="tx-wizard-basics" class="space-y-3" data-testid="wizard-basics-form">
        <h4 class="text-base font-semibold">${T('wizard.basics_title')}</h4>
        <p class="text-sm tx-secondary">${T('wizard.basics_subtitle')}</p>
        <div>
          <label class="tx-label">${T('collections.name_label')}</label>
          <input name="name" data-action="wizard-input-name" data-testid="wizard-input-name" value="${escHtml(w.name || '')}" class="tx-input" placeholder="${T('collections.name_placeholder')}" autofocus required />
        </div>
        <div>
          <label class="tx-label">${T('collections.description_label')}</label>
          <textarea name="description" data-action="wizard-input-description" rows="2" class="tx-textarea" placeholder="${T('collections.description_placeholder')}">${escHtml(w.description || '')}</textarea>
        </div>
        <div>
          <label class="tx-label">${T('collections.language_label')}</label>
          <select name="language" data-action="wizard-input-language" class="tx-select">
            <option value="de"${lang === 'de' ? ' selected' : ''}>${T('settings.lang_de')}</option>
            <option value="en"${lang === 'en' ? ' selected' : ''}>${T('settings.lang_en')}</option>
            <option value="es"${lang === 'es' ? ' selected' : ''}>${T('settings.lang_es')}</option>
            <option value="tr"${lang === 'tr' ? ' selected' : ''}>${T('settings.lang_tr')}</option>
          </select>
        </div>
      </form>`
    }

    function renderWizardStepTemplate() {
      const w = state.wizard
      const isFromDoc = w.mode === 'fromDoc'
      const hasTemplate = !!w.templateId
      const uploading = w.templateUploading
      const err = w.templateError
      const title = isFromDoc ? T('wizard.from_doc_title') : T('wizard.template_title')
      const subtitle = isFromDoc ? T('wizard.from_doc_subtitle') : T('wizard.template_subtitle')
      const dropHint = isFromDoc ? T('wizard.from_doc_drop_hint') : T('templates.drop_hint')
      const uploadingLabel = isFromDoc ? T('wizard.from_doc_uploading') : T('templates.uploading')
      const uploadingMarkup =
        isFromDoc && uploading
          ? renderWizardAiSuggestProgress()
          : uploading
            ? `<div class="text-sm tx-secondary"><span class="animate-spin inline-block w-4 h-4 border-2 border-current border-t-transparent rounded-full align-middle"></span> ${escHtml(uploadingLabel)}</div>`
            : ''
      const dropAction = isFromDoc ? 'wizard-from-doc-pick' : 'wizard-template-pick'
      const successLine = isFromDoc
        ? `<div class="text-xs tx-secondary">${Tf('wizard.from_doc_summary', { applied: w.fromDocApplied, total: w.fromDocTotal })}${w.fromDocModel ? ` · ${T('datasets.extract_model')}: <span class="font-mono">${escHtml(w.fromDocModel)}</span>` : ''}</div>`
        : `<div class="text-xs tx-secondary">${w.templatePlaceholderCount} ${T('templates.placeholder_count')}</div>`
      return `<div class="space-y-3" data-testid="wizard-template-step">
        <h4 class="text-base font-semibold">${escHtml(title)}</h4>
        <p class="text-sm tx-secondary">${escHtml(subtitle)}</p>
        ${
          hasTemplate
            ? `<div class="tx-row p-4 flex items-center gap-3">
                <span style="color:var(--status-success)">${ICONS.check}</span>
                <div class="flex-1 min-w-0">
                  <div class="font-medium truncate">${escHtml(w.templateName || '')}</div>
                  ${successLine}
                </div>
                <button data-action="wizard-template-replace" class="tx-btn tx-btn-sm tx-btn-ghost">${T('wizard.template_replace')}</button>
              </div>`
            : `<label class="tx-drop" data-testid="wizard-template-dropzone">
                <div class="inline-flex items-center justify-center w-10 h-10 rounded-full mb-2" style="background:var(--brand-alpha-light);color:var(--brand)">${isFromDoc ? ICONS.sparkle : ICONS.upload}</div>
                <p class="text-sm font-medium">${escHtml(dropHint)}</p>
                ${isFromDoc ? `<p class="text-xs tx-secondary mt-1">${T('wizard.from_doc_drop_subhint')}</p>` : ''}
                <input type="file" data-action="${dropAction}" data-testid="wizard-template-file" accept=".docx" class="hidden" />
              </label>`
        }
        ${uploadingMarkup}
        ${err ? `<div class="text-sm" style="color:var(--status-error)">${escHtml(err)}</div>` : ''}
        ${!hasTemplate && !isFromDoc ? `<button data-action="wizard-skip-template" data-testid="wizard-skip-template" class="tx-btn tx-btn-sm tx-btn-ghost">${T('wizard.template_skip')}</button>` : ''}
      </div>`
    }

    // Phased progress UI for the AI-suggest-from-docx pipeline.
    // Drives both the template-upload step (renderWizardStepTemplate)
    // and the fields step (renderWizardStepFieldsFromDoc) so the user
    // gets the same "still working" feedback regardless of where they
    // were sitting when the long-running call kicked off.
    function renderWizardAiSuggestProgress() {
      const w = state.wizard
      const steps = [
        T('wizard.ai_suggest_step_upload'),
        T('wizard.ai_suggest_step_extract'),
        T('wizard.ai_suggest_step_propose'),
        T('wizard.ai_suggest_step_finalize'),
      ]
      const step = w.aiSuggestStep || 0
      const elapsed = w.aiSuggestElapsedSec || 0
      // Calibrated to typical 1–3 page draft (~20 s pipeline).
      // Bigger docs run longer; we cap the bar at 95 % so it never
      // hits 100 % until the actual fetch resolves.
      const expectedSec = 22
      const timeBasedPct = Math.min(85, Math.round((elapsed / expectedSec) * 85))
      const pct = Math.max(15 + step * 22, timeBasedPct)
      const cappedPct = Math.min(95, pct)
      const statusLine =
        step >= 3
          ? T('wizard.ai_suggest_status_finalizing')
          : step >= 2
            ? T('wizard.ai_suggest_status_proposing')
            : step >= 1
              ? T('wizard.ai_suggest_status_extracting')
              : T('wizard.ai_suggest_status_uploading')
      return `<div class="mt-3 space-y-2" data-testid="wizard-ai-suggest-progress">
        <div class="flex items-center justify-between gap-2 text-sm font-medium" style="color:var(--brand)">
          <div class="flex items-center gap-2">
            <div class="animate-spin rounded-full h-4 w-4 border-2 border-current border-t-transparent"></div>
            ${T('wizard.ai_suggest_running')}
          </div>
          <span class="text-xs font-mono tx-secondary" aria-live="polite">${Tf('wizard.ai_suggest_elapsed', { seconds: elapsed })}</span>
        </div>
        <div class="w-full rounded-full h-2" style="background:var(--divider)">
          <div class="h-2 rounded-full transition-all duration-700" style="width:${cappedPct}%;background:var(--brand)"></div>
        </div>
        <div class="flex justify-between text-xs tx-secondary">
          ${steps.map((s, i) => `<span${i <= step ? ' style="color:var(--brand);font-weight:500"' : ''}>${escHtml(s)}</span>`).join('')}
        </div>
        <div class="text-xs tx-secondary">${escHtml(statusLine)}</div>
        <div class="text-xs tx-secondary" style="opacity:0.85">${escHtml(T('wizard.ai_suggest_hint'))}</div>
        <div class="text-xs" style="color:var(--status-warning,#d97706);opacity:0.9">${escHtml(T('wizard.ai_suggest_dont_close'))}</div>
      </div>`
    }

    function renderWizardStepFields() {
      const w = state.wizard
      if (w.mode === 'fromDoc') return renderWizardStepFieldsFromDoc()
      if (w.mode === 'template') return renderWizardStepFieldsFromTemplate()
      return renderWizardStepFieldsFromText()
    }

    // Fields step for the "fromDoc" path. Same table-of-checkboxes UI as the
    // template path but shows the verbatim snippet the AI lifted from the
    // document and whether the placeholder could be auto-injected (so the
    // user knows when they need to open the .docx and fix a placeholder
    // by hand).
    function renderWizardStepFieldsFromDoc() {
      const w = state.wizard
      if (w.templateUploading) {
        return `<div class="py-2">${renderWizardAiSuggestProgress()}</div>`
      }
      if (w.suggestionsLoading) {
        return `<div class="text-sm tx-secondary py-6 text-center">
          <span class="animate-spin inline-block w-5 h-5 border-2 border-current border-t-transparent rounded-full"></span>
          <p class="mt-2">${T('wizard.fields_detecting')}</p>
        </div>`
      }
      if (w.templateError || w.suggestionsError) {
        return `<div class="text-sm" style="color:var(--status-error)">${escHtml(w.templateError || w.suggestionsError)}</div>`
      }
      if (!w.suggestions || !Array.isArray(w.suggestions.suggestions)) {
        return `<div class="text-sm tx-secondary">${T('wizard.fields_template_missing')}</div>`
      }
      const sugg = w.suggestions.suggestions
      if (sugg.length === 0) {
        return `<div class="text-sm tx-secondary">${T('wizard.from_doc_empty')}</div>`
      }
      const truncate = (s, n) => (s && s.length > n ? s.slice(0, n - 1) + '…' : s || '')
      const rows = sugg
        .map((s, i) => {
          const checked = !!w.suggestionsSelected[i]
          const appliedBadge = s.applied
            ? `<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium" style="background:color-mix(in srgb, var(--status-success) 12%, transparent);color:var(--status-success)">${ICONS.check} ${T('wizard.from_doc_applied')}</span>`
            : `<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium" style="background:color-mix(in srgb, var(--status-warning,#d97706) 14%, transparent);color:var(--status-warning,#d97706)" title="${T('wizard.from_doc_manual_hint')}">${ICONS.info || ICONS.sparkle} ${T('wizard.from_doc_manual')}</span>`
          return `<tr class="tx-divider border-t">
            <td class="py-2 px-3 align-top"><input type="checkbox" data-action="wizard-suggestion-toggle" data-idx="${i}" data-testid="wizard-suggestion-${i}" ${checked ? 'checked' : ''} class="h-4 w-4" style="accent-color:var(--brand)" /></td>
            <td class="py-2 px-3 font-mono text-xs align-top">${escHtml(s.key)}</td>
            <td class="py-2 px-3 text-sm align-top">${escHtml(s.label || '')}</td>
            <td class="py-2 px-3 text-xs align-top">${escHtml(T(`variables.type_${s.type}`, s.type))}</td>
            <td class="py-2 px-3 text-xs align-top tx-secondary" style="max-width:18rem">
              <div class="italic">"${escHtml(truncate(s.source_text, 80))}"</div>
              <div class="mt-1">${appliedBadge}</div>
            </td>
          </tr>`
        })
        .join('')
      const summary = Tf('wizard.from_doc_fields_summary', {
        total: sugg.length,
        selected: Object.values(w.suggestionsSelected).filter(Boolean).length,
        applied: w.fromDocApplied || 0,
      })
      return `<div class="space-y-3" data-testid="wizard-fields-from-doc">
        <h4 class="text-base font-semibold">${T('wizard.from_doc_fields_title')}</h4>
        <p class="text-sm tx-secondary">${T('wizard.from_doc_fields_subtitle')}</p>
        <p class="text-xs tx-secondary">${escHtml(summary)}</p>
        <div class="tx-card p-0 overflow-x-auto">
          <table class="w-full text-left">
            <thead>
              <tr class="tx-divider border-b text-xs tx-secondary uppercase tracking-wider">
                <th class="py-2 px-3">${T('variables.import_col_use')}</th>
                <th class="py-2 px-3">${T('variables.import_col_key')}</th>
                <th class="py-2 px-3">${T('variables.import_col_label')}</th>
                <th class="py-2 px-3">${T('variables.import_col_type')}</th>
                <th class="py-2 px-3">${T('wizard.from_doc_col_source')}</th>
              </tr>
            </thead>
            <tbody>${rows}</tbody>
          </table>
        </div>
      </div>`
    }

    function renderWizardStepFieldsFromTemplate() {
      const w = state.wizard
      if (w.suggestionsLoading) {
        return `<div class="text-sm tx-secondary py-6 text-center">
          <span class="animate-spin inline-block w-5 h-5 border-2 border-current border-t-transparent rounded-full"></span>
          <p class="mt-2">${T('wizard.fields_detecting')}</p>
        </div>`
      }
      if (w.suggestionsError) {
        return `<div class="text-sm" style="color:var(--status-error)">${escHtml(w.suggestionsError)}</div>`
      }
      if (!w.suggestions) {
        return `<div class="text-sm tx-secondary">${T('wizard.fields_template_missing')}</div>`
      }
      const sugg = w.suggestions.suggestions || []
      const rows = sugg
        .map((s, i) => {
          const dup = s._status === 'duplicate'
          const checked = !!w.suggestionsSelected[i] && !dup
          return `<tr class="tx-divider border-t${dup ? ' opacity-60' : ''}">
            <td class="py-2 px-3"><input type="checkbox" data-action="wizard-suggestion-toggle" data-idx="${i}" data-testid="wizard-suggestion-${i}" ${checked ? 'checked' : ''} ${dup ? 'disabled' : ''} class="h-4 w-4" style="accent-color:var(--brand)" /></td>
            <td class="py-2 px-3 font-mono text-xs">${escHtml(s.key)}${dup ? ` <span class="tx-secondary text-xs">(${T('variables.import_duplicate_key')})</span>` : ''}</td>
            <td class="py-2 px-3 text-sm">${escHtml(s.label || '')}</td>
            <td class="py-2 px-3 text-xs">${escHtml(T(`variables.type_${s.type}`, s.type))}</td>
          </tr>`
        })
        .join('')
      const summary = Tf('wizard.fields_template_summary', {
        total: sugg.length,
        selected: Object.values(w.suggestionsSelected).filter(Boolean).length,
      })
      return `<div class="space-y-3" data-testid="wizard-fields-template">
        <h4 class="text-base font-semibold">${T('wizard.fields_title')}</h4>
        <p class="text-sm tx-secondary">${T('wizard.fields_template_subtitle')}</p>
        <p class="text-xs tx-secondary">${escHtml(summary)}</p>
        <div class="tx-card p-0 overflow-x-auto">
          <table class="w-full text-left">
            <thead>
              <tr class="tx-divider border-b text-xs tx-secondary uppercase tracking-wider">
                <th class="py-2 px-3">${T('variables.import_col_use')}</th>
                <th class="py-2 px-3">${T('variables.import_col_key')}</th>
                <th class="py-2 px-3">${T('variables.import_col_label')}</th>
                <th class="py-2 px-3">${T('variables.import_col_type')}</th>
              </tr>
            </thead>
            <tbody>${rows}</tbody>
          </table>
        </div>
      </div>`
    }

    function renderWizardStepFieldsFromText() {
      const w = state.wizard
      const parsed = w.pastedFields
      return `<div class="space-y-3" data-testid="wizard-fields-text">
        <h4 class="text-base font-semibold">${T('wizard.fields_text_title')}</h4>
        <p class="text-sm tx-secondary">${T('wizard.fields_text_subtitle')}</p>
        <textarea data-action="wizard-paste-text" data-testid="wizard-paste-text" rows="6" class="tx-textarea" placeholder="${T('variables.import_hint')}" style="font-family:monospace;font-size:.8rem">${escHtml(w.pastedText || '')}</textarea>
        <div class="flex items-center gap-2">
          <button data-action="wizard-parse-text" data-testid="wizard-parse-text" class="tx-btn tx-btn-sm"${w.pastedParsing ? ' disabled' : ''}>
            ${w.pastedParsing ? `<span class="animate-spin inline-block w-3.5 h-3.5 border-2 border-white border-t-transparent rounded-full"></span>` : ICONS.sparkle}
            ${w.pastedParsing ? T('app.loading') : T('wizard.fields_text_parse')}
          </button>
          ${w.pastedError ? `<span class="text-xs" style="color:var(--status-error)">${escHtml(w.pastedError)}</span>` : ''}
        </div>
        ${
          parsed && parsed.length > 0
            ? `<div class="tx-card p-3">
                <div class="text-xs tx-secondary mb-2">${parsed.length} ${T('wizard.fields_text_parsed')}</div>
                <ul class="text-sm space-y-0.5">
                  ${parsed
                    .map(
                      (f) =>
                        `<li>· <span class="font-mono text-xs">${escHtml(f.key)}</span> — ${escHtml(f.label || '')} <span class="tx-secondary text-xs">(${escHtml(T(`variables.type_${f.type || 'text'}`, f.type || 'text'))})</span></li>`
                    )
                    .join('')}
                </ul>
              </div>`
            : `<p class="text-xs tx-secondary">${T('wizard.fields_text_empty')}</p>`
        }
      </div>`
    }

    function renderWizardStepReview() {
      const w = state.wizard
      const c = collectionById(w.collectionId)
      const vars = c?.fields?.length || 0
      const tpls = c ? collectionTemplates(c).length : 0
      return `<div class="space-y-3" data-testid="wizard-review">
        <h4 class="text-base font-semibold">${T('wizard.review_title')}</h4>
        <p class="text-sm tx-secondary">${T('wizard.review_subtitle')}</p>
        <div class="tx-card p-4 space-y-2">
          <div class="flex items-start justify-between gap-3">
            <div class="text-xs tx-secondary uppercase tracking-wider">${T('collections.name_label')}</div>
            <div class="font-medium text-sm truncate">${escHtml(c?.name || w.name)}</div>
          </div>
          ${
            c?.description || w.description
              ? `<div class="flex items-start justify-between gap-3">
                  <div class="text-xs tx-secondary uppercase tracking-wider">${T('collections.description_label')}</div>
                  <div class="text-sm" style="max-width:60%">${escHtml(c?.description || w.description)}</div>
                </div>`
              : ''
          }
          <div class="flex items-start justify-between gap-3">
            <div class="text-xs tx-secondary uppercase tracking-wider">${T('collection.overview_stats_variables')}</div>
            <div class="font-medium text-sm">${vars}</div>
          </div>
          <div class="flex items-start justify-between gap-3">
            <div class="text-xs tx-secondary uppercase tracking-wider">${T('collection.overview_stats_templates')}</div>
            <div class="font-medium text-sm">${tpls}</div>
          </div>
        </div>
        <p class="text-xs tx-secondary">${T('wizard.review_hint')}</p>
      </div>`
    }

    function renderCollectionEditor() {
      const editing = state.editingCollection
      const isNew = !editing
      return `<div class="tx-modal-bg" data-action="close-collection-editor">
        <div class="tx-modal p-6" onclick="event.stopPropagation()">
          <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-semibold">${isNew ? T('collections.new') : T('app.edit')}</h3>
            <button data-action="close-collection-editor" class="tx-help-trigger">${ICONS.close}</button>
          </div>
          <form id="tx-collection-form" class="space-y-4">
            <div>
              <label class="tx-label">${T('collections.name_label')}</label>
              <input name="name" value="${escHtml(editing?.name || '')}" class="tx-input" placeholder="${T('collections.name_placeholder')}" required autofocus />
            </div>
            <div>
              <label class="tx-label">${T('collections.description_label')}</label>
              <textarea name="description" rows="2" class="tx-textarea" placeholder="${T('collections.description_placeholder')}">${escHtml(editing?.description || '')}</textarea>
            </div>
            <div>
              <label class="tx-label">${T('collections.language_label')}</label>
              <select name="language" class="tx-select">
                <option value="de"${(editing?.language || state.config.default_language || 'de') === 'de' ? ' selected' : ''}>${T('settings.lang_de')}</option>
                <option value="en"${(editing?.language || state.config.default_language) === 'en' ? ' selected' : ''}>${T('settings.lang_en')}</option>
                <option value="es"${editing?.language === 'es' ? ' selected' : ''}>${T('settings.lang_es')}</option>
                <option value="tr"${editing?.language === 'tr' ? ' selected' : ''}>${T('settings.lang_tr')}</option>
              </select>
              <p class="tx-hint">${T('collections.language_hint')}</p>
            </div>
            <div class="flex items-center gap-2 pt-2">
              <button type="submit" class="tx-btn">${isNew ? T('collections.create_and_open') : T('collections.save_and_open')}</button>
              <button type="button" data-action="close-collection-editor" class="tx-btn tx-btn-ghost">${T('app.cancel')}</button>
            </div>
          </form>
        </div>
      </div>`
    }

    // =========================================================================
    // Collection detail view (tabbed)
    // =========================================================================

    function renderCollection() {
      const c = collectionById(state.collectionId)
      if (!c) {
        return `<div class="mt-4 tx-card p-6">
          <button data-nav="collections" class="flex items-center gap-1 text-sm transition-colors mb-3" style="color:var(--txt-secondary)">${ICONS.back} ${T('collection.breadcrumb')}</button>
          <p class="text-sm">${T('collection.not_found')}</p>
        </div>`
      }

      // The Collection cockpit was restructured so daily work (Datasets)
      // is front and centre; setup (Variables + Target Templates) is
      // folded behind a single "Set up" tab; the destructive Danger Zone
      // moved into the kebab menu next to the title so users can't land
      // on it by mistake.
      const tabs = [
        { id: 'overview', label: T('collection.tab_overview') },
        { id: 'datasets', label: T('collection.tab_datasets') },
        { id: 'setup', label: T('collection.tab_setup') },
        { id: 'export', label: T('collection.tab_export') },
      ]

      const menu = state.collectionMenuOpen
        ? `<div data-action="close-collection-menu" style="position:fixed;inset:0;z-index:30"></div>
           <div role="menu" class="tx-card" style="position:absolute;top:calc(100% + .25rem);right:0;min-width:14rem;z-index:31;padding:.25rem 0;box-shadow:0 8px 24px rgba(0,0,0,.12)">
             <button data-action="edit-collection" data-testid="menu-edit-collection" role="menuitem" class="w-full text-left flex items-center gap-2 px-3 py-2 text-sm tx-row" style="border-radius:0;background:transparent;box-shadow:none">
               ${ICONS.edit} ${T('collection.menu_edit')}
             </button>
             <div style="height:1px;background:var(--divider);margin:.25rem 0"></div>
             <button data-action="open-danger" data-testid="menu-open-danger" role="menuitem" class="w-full text-left flex items-center gap-2 px-3 py-2 text-sm tx-row" style="border-radius:0;background:transparent;box-shadow:none;color:var(--status-error)">
               ${ICONS.trash} ${T('collection.menu_danger')}
             </button>
           </div>`
        : ''

      return `<div class="mt-4 space-y-5">
        <div>
          <button data-nav="collections" class="flex items-center gap-1 text-sm transition-colors" style="color:var(--txt-secondary)">${ICONS.back} ${T('collection.breadcrumb')}</button>
          <div class="flex items-start justify-between gap-3 mt-2">
            <div class="min-w-0 flex-1">
              <h2 class="text-2xl font-bold tracking-tight truncate flex items-center gap-2">
                <span style="color:var(--brand)">${ICONS.folderOpen}</span>
                ${escHtml(c.name)}
              </h2>
              ${c.description ? `<p class="text-sm tx-secondary mt-1">${escHtml(c.description)}</p>` : ''}
            </div>
            <div style="position:relative">
              <button data-action="toggle-collection-menu" data-testid="btn-collection-menu" aria-haspopup="menu" aria-expanded="${state.collectionMenuOpen ? 'true' : 'false'}" class="tx-btn tx-btn-sm tx-btn-ghost" title="${T('collection.menu_more')}" aria-label="${T('collection.menu_more')}">
                <span aria-hidden="true" style="font-size:1.1rem;line-height:1;letter-spacing:.1em">\u22EF</span>
              </button>
              ${menu}
            </div>
          </div>
        </div>

        <nav data-testid="collection-tabs" class="flex gap-1 overflow-x-auto" style="border-bottom:1px solid var(--divider)">
          ${tabs
            .map(
              (t) =>
                `<button data-tab="${t.id}" class="tx-tab${state.tab === t.id ? ' active' : ''}">${t.label}</button>`
            )
            .join('')}
        </nav>

        <div>${renderCollectionTab(c)}</div>
        ${state.editingCollection && !state.newCollectionOpen ? renderCollectionEditor() : ''}
        ${state.dangerOpen ? renderDangerModal(c) : ''}
        ${state.wizard.open ? renderWizardModal() : ''}
        ${renderHelpModal()}
      </div>`
    }

    // Danger Zone (formerly a top-level tab) lives in a modal triggered
    // from the kebab menu so accidental clicks during normal work are
    // impossible. Content/behaviour is unchanged from the old tab.
    function renderDangerModal(c) {
      return `<div class="tx-modal-bg" data-action="close-danger-modal">
        <div class="tx-modal p-6" data-testid="modal-danger" onclick="event.stopPropagation()">
          <div class="flex items-center justify-between mb-3">
            <h3 class="text-lg font-semibold flex items-center gap-2" style="color:var(--status-error)">${ICONS.warning} ${T('danger.title')}</h3>
            <button data-action="close-danger-modal" class="tx-help-trigger" aria-label="${T('app.close')}">${ICONS.close}</button>
          </div>
          ${renderDangerTab(c)}
        </div>
      </div>`
    }

    function renderCollectionTab(c) {
      switch (state.tab) {
        case 'setup':
          return renderSetupTab(c)
        case 'datasets':
          return renderDatasetsTab(c)
        case 'export':
          return renderExportTab(c)
        case 'overview':
        default:
          return renderOverviewTab(c)
      }
    }

    // "Set up" stacks the two configuration-time editors (Variables and
    // Target Templates) into one focused setup surface. Both editors are
    // reused as-is, with their own headers; we just wrap them with a
    // short orientation banner so it's clear this is where the user
    // *defines* what a Collection looks like before working with it.
    function renderSetupTab(c) {
      return `<div class="space-y-6" data-testid="setup-tab">
        <div class="tx-callout flex items-start gap-2">
          ${ICONS.info}
          <div>
            <div class="text-sm font-medium">${T('collection.tab_setup')}</div>
            <p class="text-xs tx-secondary mt-0.5">${T('collection.tab_setup_subtitle')}</p>
          </div>
        </div>
        <section data-testid="setup-section-templates">${renderTemplatesTab(c)}</section>
        <div style="height:1px;background:var(--divider)"></div>
        <section data-testid="setup-section-variables">${renderVariablesTab(c)}</section>
      </div>`
    }

    // --- Overview tab ---

    function renderOverviewTab(c) {
      const datasets = datasetsForCollection(c.id)
      const templates = collectionTemplates(c)
      const vars = c.fields?.length || 0
      const hasVars = vars > 0
      const hasTpls = templates.length > 0
      const hasDatasets = datasets.length > 0
      const hasGenerated = datasets.some(
        (d) => d.status === 'generated' || (d.documents && Object.keys(d.documents).length > 0)
      )

      const stats = `<div class="grid grid-cols-3 gap-3">
        ${statCard(T('collection.overview_stats_variables'), vars, ICONS.variable, 'setup')}
        ${statCard(T('collection.overview_stats_templates'), templates.length, ICONS.file, 'setup')}
        ${statCard(T('collection.overview_stats_datasets'), datasets.length, ICONS.database, 'datasets')}
      </div>`

      // 4-step wizard. Each step advertises its "current" status based on
      // the first unfinished prerequisite and exposes a CTA that navigates
      // to the relevant tab.
      const steps = [
        {
          key: 'variables',
          num: 1,
          done: hasVars,
          icon: ICONS.variable,
          title: T('collection.overview_step1_title'),
          text: T('collection.overview_step1'),
          cta: T('collection.goto_variables'),
          tab: 'setup',
        },
        {
          key: 'templates',
          num: 2,
          done: hasTpls,
          icon: ICONS.file,
          title: T('collection.overview_step2_title'),
          text: T('collection.overview_step2'),
          cta: T('collection.goto_templates'),
          tab: 'setup',
        },
        {
          key: 'datasets',
          num: 3,
          done: hasDatasets,
          icon: ICONS.database,
          title: T('collection.overview_step3_title'),
          text: T('collection.overview_step3'),
          cta: T('collection.goto_datasets'),
          tab: 'datasets',
        },
        {
          key: 'generate',
          num: 4,
          done: hasGenerated,
          icon: ICONS.doc,
          title: T('collection.overview_step4_title'),
          text: T('collection.overview_step4'),
          cta: T('collection.goto_datasets'),
          tab: 'datasets',
        },
      ]
      const activeStepIdx = steps.findIndex((s) => !s.done)

      const stepsMarkup = steps
        .map((s, i) => {
          const active = i === activeStepIdx
          const prevDone = i === 0 || steps[i - 1].done
          const disabled = !s.done && !prevDone
          const bg = s.done
            ? 'background:var(--status-success);color:#fff'
            : active
              ? 'background:var(--brand);color:#fff'
              : 'background:var(--bg-chip);color:var(--txt-secondary)'
          const cardStyle = active
            ? 'box-shadow:inset 0 0 0 2px var(--brand), 0 4px 12px rgba(0,0,0,.06);'
            : ''
          return `<button type="button" data-tab="${s.tab}"${disabled ? ' disabled' : ''} class="tx-row text-left p-4 flex items-start gap-3 transition-all" style="${cardStyle}${disabled ? 'opacity:.55;pointer-events:none;' : ''}">
            <span class="inline-flex items-center justify-center w-8 h-8 rounded-full text-sm font-bold flex-shrink-0" style="${bg}">${s.done ? '✓' : s.num}</span>
            <div class="flex-1 min-w-0">
              <div class="flex items-center gap-1.5 text-sm font-semibold">
                <span>${s.icon}</span>
                <span>${s.title}</span>
              </div>
              <div class="text-xs tx-secondary mt-1">${s.text}</div>
              ${!disabled ? `<div class="text-xs mt-2 font-medium" style="color:var(--brand)">${s.cta} →</div>` : ''}
            </div>
          </button>`
        })
        .join('')

      let callout = ''
      if (!hasVars) {
        callout = `<div class="tx-callout flex items-center gap-2">${ICONS.info} <span>${T('collection.summary_no_vars')}</span> <button data-tab="setup" class="tx-link ml-auto text-sm font-medium">${T('collection.goto_variables')} →</button></div>`
      } else if (!hasTpls) {
        callout = `<div class="tx-callout flex items-center gap-2">${ICONS.info} <span>${T('collection.summary_no_templates')}</span> <button data-tab="setup" class="tx-link ml-auto text-sm font-medium">${T('collection.goto_templates')} →</button></div>`
      } else {
        callout = `<div class="tx-callout flex items-center gap-2" style="background:color-mix(in srgb, var(--status-success) 10%, var(--bg-card));color:var(--txt-primary)">${ICONS.check} <span>${T('collection.summary_ready')}</span> <button data-tab="datasets" class="tx-link ml-auto text-sm font-medium">${T('collection.goto_datasets')} →</button></div>`
      }

      const recentDatasets = datasets
        .slice()
        .sort(
          (a, b) =>
            new Date(b.updated_at || b.created_at || 0) -
            new Date(a.updated_at || a.created_at || 0)
        )
        .slice(0, 5)

      const recentRows =
        recentDatasets.length > 0
          ? recentDatasets
              .map(
                (d) =>
                  `<button data-open-dataset="${d.id}" class="tx-row w-full text-left p-3 flex items-center justify-between">
                    <div>
                      <div class="font-medium text-sm">${escHtml(datasetDisplayName(d))}</div>
                      <div class="text-xs tx-secondary mt-0.5">${formatDate(d.updated_at || d.created_at)}</div>
                    </div>
                    ${statusBadge(d.status || 'draft')}
                  </button>`
              )
              .join('')
          : `<p class="text-sm tx-secondary py-2">${T('collection.overview_datasets_empty')}</p>`

      return `<div class="space-y-4">
        ${stats}
        ${callout}
        <div class="tx-card p-5">
          <h3 class="text-sm font-semibold mb-3">${T('collection.overview_steps_title')}</h3>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">${stepsMarkup}</div>
        </div>
        <div class="tx-card p-5">
          <div class="flex items-center justify-between mb-3">
            <h3 class="text-sm font-semibold">${T('collection.overview_datasets_title')}</h3>
            ${hasVars ? `<button data-action="new-dataset" class="tx-btn tx-btn-sm">${ICONS.plus} ${T('collection.overview_add_dataset')}</button>` : ''}
          </div>
          <div class="space-y-1.5">${recentRows}</div>
        </div>
      </div>`
    }

    // ---------------------------------------------------------------------
    // Plugin dashboard — Tika status + AI provider/model overview
    // ---------------------------------------------------------------------

    function renderSystemStatusCard() {
      const d = state.dashboard
      const loading = state.dashboardLoading
      const err = state.dashboardError

      const header = `<div class="flex items-center justify-between mb-3">
        <h3 class="text-sm font-semibold flex items-center gap-2">${ICONS.gear} ${T('dashboard.title')}</h3>
        <button data-action="dashboard-refresh" class="tx-btn tx-btn-sm tx-btn-ghost" ${loading ? 'disabled' : ''} title="${T('dashboard.refresh')}">
          ${loading ? `<span class="animate-spin inline-block w-3 h-3 border-2 border-current border-t-transparent rounded-full"></span>` : ICONS.refresh}
          <span class="text-xs">${T('dashboard.refresh')}</span>
        </button>
      </div>`

      let body = ''
      if (loading && !d) {
        body = `<div class="flex items-center gap-2 text-sm tx-secondary py-4">
          <div class="animate-spin rounded-full h-4 w-4 border-b-2 border-current"></div>
          ${T('dashboard.loading')}
        </div>`
      } else if (err && !d) {
        body = `<p class="text-sm" style="color:var(--status-error)">${ICONS.warning} ${escHtml(err)}</p>`
      } else if (d) {
        body = renderSystemStatusBody(d)
      } else {
        body = `<p class="text-sm tx-secondary">${T('dashboard.empty')}</p>`
      }

      return `<div class="tx-card p-5">${header}${body}</div>`
    }

    function renderSystemStatusBody(d) {
      const tika = d.tika || {}
      const ai = d.ai || {}
      const tikaOk = !!tika.reachable
      const tikaColor = tikaOk ? 'var(--status-success)' : 'var(--status-error)'
      const tikaIcon = tikaOk ? ICONS.check : ICONS.warning
      const tikaText = tikaOk
        ? `${T('dashboard.tika_ok')} · ${escHtml(tika.version || '—')} · ${tika.roundtrip_ms || 0} ms`
        : `${T('dashboard.tika_down')} (HTTP ${tika.http_code || 0}${tika.error ? ` — ${escHtml(tika.error)}` : ''})`

      // Each AI slot is rendered as a button so users can click straight
      // through to the synaplan AI Models settings page with the right
      // capability highlighted. The model name + provider come from the
      // backend, which already cascades user → global default in
      // ModelConfigService::getDefaultModel(); when only the platform
      // default is configured we still render its real name.
      const aiRow = (label, slot, role, capability) => {
        const provider = slot.provider || ''
        const modelName = slot.model_name || (slot.model_id ? 'id ' + slot.model_id : '')
        const hasModel = !!(modelName || provider)
        const valueLine = hasModel
          ? `<span class="tx-badge" style="background:var(--brand-alpha-light);color:var(--brand)">${escHtml(provider || '—')}</span>
             <span class="font-mono text-xs ml-2">${escHtml(modelName || T('dashboard.provider_default'))}</span>`
          : `<span class="tx-badge" style="background:var(--bg-chip);color:var(--txt-secondary)">${T('dashboard.unconfigured')}</span>`

        const cta = hasModel ? T('dashboard.change_model') : T('dashboard.configure_model')

        // Curated recommendation chips. The backend filters to models
        // actually present in this instance's catalogue and excludes
        // the model the user is already on.
        const recs = Array.isArray(slot.recommended) ? slot.recommended : []
        const recsBlock = recs.length
          ? `<div class="mt-2 text-xs">
              <div class="tx-secondary mb-1">${T('dashboard.recommended_label')}</div>
              <div class="flex flex-wrap gap-1.5">
                ${recs
                  .map(
                    (r) =>
                      `<button type="button" data-action="open-ai-settings" data-capability="${escHtml(capability)}" class="tx-badge" title="${escHtml(r.reason || '')}" style="background:var(--bg-card);border:1px solid var(--divider);color:var(--txt-primary);cursor:pointer;font-weight:500">
                        ${escHtml(r.label || r.name || '')}
                      </button>`
                  )
                  .join('')}
              </div>
            </div>`
          : ''

        return `<div class="rounded-md transition-colors" style="border:1px solid transparent">
          <button type="button" data-action="open-ai-settings" data-capability="${escHtml(capability)}" class="w-full text-left flex items-start justify-between gap-3 py-3 px-3 rounded-md transition-colors hover:bg-[var(--brand-alpha-light)]" style="background:transparent;border:none;cursor:pointer">
            <div class="min-w-0 flex-1">
              <div class="text-xs uppercase tracking-wider tx-secondary font-semibold">${escHtml(label)}</div>
              <div class="text-xs tx-secondary mt-0.5">${escHtml(role)}</div>
              <div class="mt-1.5 flex items-center flex-wrap gap-1">${valueLine}</div>
            </div>
            <div class="text-right flex flex-col items-end gap-1 shrink-0">
              <span class="text-xs tx-link inline-flex items-center gap-1">${cta} ${ICONS.link}</span>
            </div>
          </button>
          ${recsBlock ? `<div class="px-3 pb-3">${recsBlock}</div>` : ''}
        </div>`
      }

      return `<div class="space-y-1">
        ${aiRow(T('dashboard.ai_chat_label'), ai.analyze || {}, T('dashboard.ai_chat_role'), 'ANALYZE')}
        ${aiRow(T('dashboard.ai_vision_label'), ai.vision || {}, T('dashboard.ai_vision_role'), 'PIC2TEXT')}
        <div class="flex items-center justify-between gap-3 px-3 py-2 mt-2 rounded-md" style="background:var(--bg-app)">
          <div class="min-w-0 flex-1">
            <div class="text-xs uppercase tracking-wider tx-secondary font-semibold">${T('dashboard.tika_label')}</div>
            <div class="text-xs tx-secondary mt-0.5">${T('dashboard.tika_role')} · <span class="font-mono">${escHtml(tika.url || '—')}</span></div>
          </div>
          <div class="text-right" style="color:${tikaColor}">
            <span class="text-sm font-medium flex items-center justify-end gap-1.5">${tikaIcon} ${tikaText}</span>
          </div>
        </div>
        <div class="text-xs tx-secondary pt-2">${T('dashboard.cost_hint')}</div>
      </div>`
    }

    async function fetchDashboard(force = false) {
      const ageMs = Date.now() - (state.dashboardLoadedAt || 0)
      if (!force && state.dashboard && ageMs < 60_000) return
      state.dashboardLoading = true
      state.dashboardError = null
      render()
      try {
        const res = await api('/dashboard')
        if (res && res.success) {
          state.dashboard = res
          state.dashboardLoadedAt = Date.now()
        } else {
          state.dashboardError = res?.error || 'Dashboard request failed'
        }
      } catch (err) {
        state.dashboardError = err.message
      }
      state.dashboardLoading = false
      render()
    }

    function statCard(label, value, icon, tab) {
      return `<button data-tab="${tab}" class="tx-row p-4 text-left flex items-center gap-3 transition-transform hover:translate-y-[-1px]">
        <span class="inline-flex items-center justify-center w-9 h-9 rounded-full" style="background:var(--brand-alpha-light);color:var(--brand)">${icon}</span>
        <div>
          <div class="text-xl font-bold leading-none">${value}</div>
          <div class="text-xs tx-secondary mt-1">${label}</div>
        </div>
      </button>`
    }

    // --- Variables tab ---

    function renderVariablesTab(c) {
      const fields = state.variablesDraft || c.fields || []

      if (state.variablesTplImportOpen) return renderVariablesTplImport(c)
      if (state.variablesImportOpen) return renderVariablesImport(c)
      if (state.polishOpen) return renderPolishModal(c)

      const attachedTemplates = collectionTemplates(c)
      const hasTemplates = attachedTemplates.length > 0
      const tplTitle = hasTemplates
        ? T('variables.import_from_template_hint')
        : T('variables.import_no_templates')

      const header = `<div class="flex items-start justify-between gap-3 flex-wrap">
        <div>
          <div class="flex items-center gap-2 mb-1">
            <h3 class="text-lg font-semibold">${T('variables.title')}</h3>
            ${helpTrigger('variables')}
          </div>
          <p class="text-sm tx-secondary" style="max-width:640px">${T('variables.subtitle')}</p>
        </div>
        <div class="flex items-center gap-2 flex-wrap">
          <button data-action="variables-import-template" class="tx-btn tx-btn-sm tx-btn-ghost" title="${escHtml(tplTitle)}"${hasTemplates ? '' : ' disabled'}>${ICONS.doc} ${T('variables.import_from_template')}</button>
          <button data-action="variables-import" class="tx-btn tx-btn-sm tx-btn-ghost" title="${T('variables.import_hint')}">${ICONS.upload} ${T('variables.import_from_text')}</button>
          <button data-action="variables-polish" class="tx-btn tx-btn-sm tx-btn-ghost" title="${T('variables.polish_hint')}"${fields.length === 0 || state.polishLoading ? ' disabled' : ''}>${state.polishLoading ? `<span class="animate-spin inline-block w-3 h-3 border-2 border-current border-t-transparent rounded-full"></span>` : ICONS.sparkle} ${state.polishLoading ? T('variables.polish_running') : T('variables.polish_btn')}</button>
          <button data-action="add-variable" class="tx-btn tx-btn-sm">${ICONS.plus} ${T('variables.new_field')}</button>
        </div>
      </div>`

      let body = ''
      if (fields.length === 0) {
        body = `<div class="tx-card p-8 text-center">
          <div class="inline-flex items-center justify-center w-12 h-12 rounded-full mb-3" style="background:var(--bg-chip);color:var(--txt-secondary)">${ICONS.variable}</div>
          <h4 class="font-semibold text-lg mb-1">${T('variables.empty')}</h4>
          <p class="text-sm tx-secondary" style="max-width:440px;margin-left:auto;margin-right:auto">${T('variables.empty_hint')}</p>
        </div>`
      } else {
        body = `<form id="tx-variables-form" class="space-y-2">
          ${fields.map((fd, idx) => renderVariableEditor(fd, idx)).join('')}
          <div class="tx-sticky-save">
            <button type="submit" class="tx-btn tx-btn-sm"${state.variablesDirty ? '' : ' disabled'}>${ICONS.check} ${T('app.save')}</button>
            ${state.variablesDirty ? `<button type="button" data-action="variables-reset" class="tx-btn tx-btn-sm tx-btn-ghost">${T('app.reset')}</button>` : ''}
          </div>
        </form>`
      }

      return `<div class="space-y-4">${header}${body}</div>`
    }

    /**
     * Small visual hint for each variable row so the user can see what
     * shape it will render as in the final Word document:
     *   list     -> one bullet paragraph per item
     *   checkbox -> \u2612 / \u2610 glyph pair (or "Ja"/"Nein" text fallback)
     *   table    -> repeating row clone with per-column types
     *   image    -> embedded <w:drawing>
     *   other    -> plain inline text substitution
     */
    function renderFieldRenderingBadge(fd) {
      const t = fd.type || 'text'
      const map = {
        list: { glyph: '\u2022', cls: 'tx-badge-list' },
        checkbox: { glyph: '\u2612', cls: 'tx-badge-check' },
        table: { glyph: '\u229E', cls: 'tx-badge-table' },
        image: { glyph: '\u25A3', cls: 'tx-badge-image' },
        textarea: { glyph: '\u00B6', cls: 'tx-badge-plain' },
        select: { glyph: '\u25BC', cls: 'tx-badge-plain' },
        date: { glyph: '\u2637', cls: 'tx-badge-plain' },
        number: { glyph: '#', cls: 'tx-badge-plain' },
      }
      if (!map[t]) return ''
      const labelText = T(`variables.render_badge_${t}`, T(`variables.type_${t}`, t))
      return `<span class="tx-render-badge ${map[t].cls}" title="${escHtml(labelText)}">
        <span aria-hidden="true">${map[t].glyph}</span>
        <span>${escHtml(labelText)}</span>
      </span>`
    }

    /**
     * Compact, read-only summary of the AI-extraction context attached
     * to a field (description / examples / negative_hint). Lets the
     * admin see at a glance which fields have rich context vs which
     * are running on label-only. Editing happens via "Polish with AI".
     */
    function renderFieldAiContext(fd) {
      const desc = (fd.description || '').trim()
      const negHint = (fd.negative_hint || '').trim()
      const examples = Array.isArray(fd.examples) ? fd.examples.filter(Boolean) : []
      if (!desc && !negHint && examples.length === 0) {
        return ''
      }
      const descLine = desc
        ? `<div class="text-xs tx-secondary"><span class="font-medium">${T('variables.polish_field_description')}:</span> ${escHtml(desc)}</div>`
        : ''
      const exLine = examples.length
        ? `<div class="text-xs tx-secondary"><span class="font-medium">${T('variables.polish_field_examples')}:</span> ${examples
            .map(
              (e) =>
                `<span class="tx-badge" style="background:var(--bg-chip);color:var(--txt-secondary);font-family:monospace">${escHtml(e)}</span>`
            )
            .join(' ')}</div>`
        : ''
      const negLine = negHint
        ? `<div class="text-xs" style="color:var(--status-warning,#d97706)"><span class="font-medium">${T('variables.polish_field_negative_hint')}:</span> ${escHtml(negHint)}</div>`
        : ''
      return `<div class="space-y-1 px-2 py-1.5 rounded" style="background:var(--bg-app);border-left:2px solid var(--brand-alpha-light)">
        ${descLine}
        ${exLine}
        ${negLine}
      </div>`
    }

    function renderVariableEditor(fd, idx) {
      const fields = state.variablesDraft || []
      const total = fields.length
      const optsStr = Array.isArray(fd.options) ? fd.options.join(', ') : ''
      const types = [
        'text',
        'textarea',
        'select',
        'list',
        'date',
        'number',
        'checkbox',
        'table',
        'image',
      ]
      const showDesigner = ['list', 'table', 'checkbox', 'image'].includes(fd.type)
      const designerOpen = state.expandedDesignerIdx === idx
      const badge = renderFieldRenderingBadge(fd)
      return `<div class="tx-row p-3 space-y-2" data-field-idx="${idx}">
        <div class="flex items-start gap-2">
          <div class="flex flex-col gap-0.5 pt-1 flex-shrink-0">
            <span data-drag-handle class="tx-drag-handle p-0.5" style="color:var(--txt-secondary)" title="${T('variables.drag_to_reorder')}">${ICONS.grip}</span>
            <button type="button" data-action="var-move-up" data-idx="${idx}" class="p-0.5 rounded transition-colors" style="color:var(--txt-secondary)${idx === 0 ? ';opacity:0.3' : ''}" ${idx === 0 ? 'disabled' : ''}>${ICONS.arrowUp}</button>
            <button type="button" data-action="var-move-down" data-idx="${idx}" class="p-0.5 rounded transition-colors" style="color:var(--txt-secondary)${idx === total - 1 ? ';opacity:0.3' : ''}" ${idx === total - 1 ? 'disabled' : ''}>${ICONS.arrowDown}</button>
          </div>
          <div class="flex-1 grid grid-cols-2 sm:grid-cols-4 gap-2">
            <input name="fk_${idx}" value="${escHtml(fd.key || '')}" placeholder="${T('variables.field_key')}" class="tx-input" />
            <input name="fl_${idx}" value="${escHtml(fd.label || '')}" placeholder="${T('variables.field_label')}" class="tx-input" />
            <select name="ft_${idx}" class="tx-select">
              ${types
                .map(
                  (tp) =>
                    `<option value="${tp}"${fd.type === tp ? ' selected' : ''}>${T(`variables.type_${tp}`, tp)}</option>`
                )
                .join('')}
            </select>
            <label class="flex items-center gap-1.5 text-sm" style="color:var(--txt-primary)">
              <input type="checkbox" name="fr_${idx}" ${fd.required ? 'checked' : ''} class="h-4 w-4" style="accent-color:var(--brand)" />
              <span>${T('variables.field_required')}</span>
            </label>
          </div>
          ${badge ? `<div class="flex-shrink-0 pt-1">${badge}</div>` : ''}
          <button type="button" data-action="var-remove" data-idx="${idx}" class="p-1 transition-colors" style="color:var(--txt-secondary)" title="${T('variables.remove_field')}">${ICONS.trash}</button>
        </div>
        ${fd.type === 'select' ? `<input name="fo_${idx}" value="${escHtml(optsStr)}" placeholder="${T('variables.field_options_hint')}" class="tx-input text-xs" />` : ''}
        ${fd.type === 'table' ? renderVariableColumnEditor(idx, fd.columns || []) : ''}
        <div class="flex items-start gap-2">
          <input name="fh_${idx}" value="${escHtml(fd.hint || '')}" placeholder="${T('variables.field_hint')}" class="tx-input text-xs" />
          <span class="mt-1">${tooltipIcon(T('variables.field_hint_info'))}</span>
        </div>
        ${renderFieldAiContext(fd)}
        ${
          showDesigner
            ? `<div style="border-top:1px dashed var(--divider);padding-top:.5rem">
                <button type="button" data-action="var-designer-toggle" data-idx="${idx}" class="flex items-center gap-1 text-xs tx-link">
                  ${designerOpen ? ICONS.chevDown : ICONS.chevRight}
                  ${T('variables.designer_title')}
                </button>
                ${designerOpen ? renderVariableDesigner(idx, fd) : ''}
              </div>`
            : ''
        }
      </div>`
    }

    function renderVariableDesigner(idx, fd) {
      const d = fd.designer || {}
      if (fd.type === 'list') {
        const style = d.list_style || 'ul'
        return `<div class="mt-2 p-3 rounded space-y-3" style="background:var(--bg-app)">
          <p class="text-xs tx-secondary">${T('variables.designer_list_hint')}</p>
          <div>
            <label class="tx-label">${T('variables.designer_list_style')}</label>
            <div class="flex items-center gap-3 text-sm">
              <label class="flex items-center gap-1.5">
                <input type="radio" name="fd_${idx}_style" value="ul" ${style === 'ul' ? 'checked' : ''} />
                <span>${T('variables.designer_style_ul')}</span>
              </label>
              <label class="flex items-center gap-1.5">
                <input type="radio" name="fd_${idx}_style" value="ol" ${style === 'ol' ? 'checked' : ''} />
                <span>${T('variables.designer_style_ol')}</span>
              </label>
            </div>
          </div>
          <label class="flex items-center gap-1.5 text-sm">
            <input type="checkbox" name="fd_${idx}_prevent_orphans" ${d.prevent_orphans ? 'checked' : ''} class="h-4 w-4" style="accent-color:var(--brand)" />
            <span>${T('variables.designer_prevent_orphans')}</span>
          </label>
          <p class="text-xs tx-secondary">${T('variables.designer_prevent_orphans_hint')}</p>
          <div class="pt-2 border-t" style="border-color:var(--divider)">
            <label class="tx-label">${T('variables.designer_list_spacing')}</label>
            <p class="text-xs tx-secondary mb-2" style="margin-top:-.125rem">${T('variables.designer_list_spacing_hint')}</p>
            <label class="flex items-center gap-1.5 text-sm">
              <input type="checkbox" name="fd_${idx}_top_blank_line" ${d.top_blank_line ? 'checked' : ''} class="h-4 w-4" style="accent-color:var(--brand)" />
              <span>${T('variables.designer_list_top_blank')}</span>
            </label>
            <label class="flex items-center gap-1.5 text-sm">
              <input type="checkbox" name="fd_${idx}_bottom_blank_line" ${d.bottom_blank_line ? 'checked' : ''} class="h-4 w-4" style="accent-color:var(--brand)" />
              <span>${T('variables.designer_list_bottom_blank')}</span>
            </label>
          </div>
        </div>`
      }
      if (fd.type === 'table') {
        return `<div class="mt-2 p-3 rounded space-y-2" style="background:var(--bg-app)">
          <p class="text-xs tx-secondary">${T('variables.designer_table_hint')}</p>
          <label class="flex items-center gap-1.5 text-sm">
            <input type="checkbox" name="fd_${idx}_repeat_header" ${d.repeat_header !== false ? 'checked' : ''} class="h-4 w-4" style="accent-color:var(--brand)" />
            <span>${T('variables.designer_repeat_header')}</span>
          </label>
          <label class="flex items-center gap-1.5 text-sm">
            <input type="checkbox" name="fd_${idx}_prevent_row_break" ${d.prevent_row_break !== false ? 'checked' : ''} class="h-4 w-4" style="accent-color:var(--brand)" />
            <span>${T('variables.designer_prevent_row_break')}</span>
          </label>
          <label class="flex items-center gap-1.5 text-sm">
            <input type="checkbox" name="fd_${idx}_keep_with_prev" ${d.keep_with_prev ? 'checked' : ''} class="h-4 w-4" style="accent-color:var(--brand)" />
            <span>${T('variables.designer_keep_with_prev')}</span>
          </label>
        </div>`
      }
      if (fd.type === 'checkbox') {
        const clickable = d.clickable_checkbox !== false
        return `<div class="mt-2 p-3 rounded space-y-3" style="background:var(--bg-app)">
          <p class="text-xs tx-secondary">${T('variables.designer_checkbox_hint')}</p>
          <label class="flex items-center gap-1.5 text-sm">
            <input type="checkbox" name="fd_${idx}_clickable_checkbox" ${clickable ? 'checked' : ''} class="h-4 w-4" style="accent-color:var(--brand)" />
            <span>${T('variables.designer_checkbox_clickable')}</span>
          </label>
          <p class="text-xs tx-secondary" style="margin-top:-.125rem">${T('variables.designer_checkbox_clickable_hint')}</p>
          <div>
            <label class="tx-label">${T('variables.designer_checkbox_glyphs')}</label>
            <p class="text-xs tx-secondary mb-2" style="margin-top:-.125rem">${T('variables.designer_checkbox_glyphs_hint')}</p>
            <div class="grid grid-cols-2 gap-2">
              <div>
                <label class="text-xs tx-secondary">${T('variables.designer_checked_glyph')}</label>
                <input name="fd_${idx}_checked_glyph" value="${escHtml(d.checked_glyph || '☒')}" class="tx-input text-center" maxlength="4" style="max-width:4rem" />
              </div>
              <div>
                <label class="text-xs tx-secondary">${T('variables.designer_unchecked_glyph')}</label>
                <input name="fd_${idx}_unchecked_glyph" value="${escHtml(d.unchecked_glyph || '☐')}" class="tx-input text-center" maxlength="4" style="max-width:4rem" />
              </div>
            </div>
          </div>
          <div>
            <label class="tx-label">${T('variables.designer_checkbox_labels')}</label>
            <p class="text-xs tx-secondary mb-2" style="margin-top:-.125rem">${T('variables.designer_checkbox_labels_hint')}</p>
            <div class="grid grid-cols-2 gap-2">
              <div>
                <label class="text-xs tx-secondary">${T('variables.designer_yes_label')}</label>
                <input name="fd_${idx}_yes_label" value="${escHtml(d.yes_label || '')}" placeholder="Ja" class="tx-input" style="max-width:6rem" />
              </div>
              <div>
                <label class="text-xs tx-secondary">${T('variables.designer_no_label')}</label>
                <input name="fd_${idx}_no_label" value="${escHtml(d.no_label || '')}" placeholder="Nein" class="tx-input" style="max-width:6rem" />
              </div>
            </div>
          </div>
        </div>`
      }
      if (fd.type === 'image') {
        const placement = d.placement === 'float' ? 'float' : 'inline'
        const floatH = ['left', 'center', 'right'].includes(d.float_h) ? d.float_h : 'right'
        const floatV = ['top', 'middle', 'bottom'].includes(d.float_v) ? d.float_v : 'top'
        const wrap = ['square', 'topAndBottom', 'none'].includes(d.wrap) ? d.wrap : 'square'
        return `<div class="mt-2 p-3 rounded space-y-3" style="background:var(--bg-app)">
          <p class="text-xs tx-secondary">${T('variables.designer_image_hint')}</p>
          <div class="grid grid-cols-3 gap-2 items-center">
            <div>
              <label class="tx-label">${T('variables.designer_image_width')}</label>
              <input type="number" name="fd_${idx}_width" value="${escHtml(String(d.width || 140))}" min="16" max="1600" class="tx-input" />
            </div>
            <div>
              <label class="tx-label">${T('variables.designer_image_height')}</label>
              <input type="number" name="fd_${idx}_height" value="${escHtml(String(d.height || 180))}" min="16" max="2000" class="tx-input" />
            </div>
            <label class="flex items-center gap-1.5 text-sm mt-5">
              <input type="checkbox" name="fd_${idx}_preserve_ratio" ${d.preserve_ratio ? 'checked' : ''} class="h-4 w-4" style="accent-color:var(--brand)" />
              <span>${T('variables.designer_image_preserve_ratio')}</span>
            </label>
          </div>
          <div>
            <label class="tx-label">${T('variables.designer_image_placement')}</label>
            <select name="fd_${idx}_placement" class="tx-select">
              <option value="inline"${placement === 'inline' ? ' selected' : ''}>${T('variables.designer_image_placement_inline')}</option>
              <option value="float"${placement === 'float' ? ' selected' : ''}>${T('variables.designer_image_placement_float')}</option>
            </select>
          </div>
          <p class="text-xs tx-secondary" style="margin-top:-.125rem">${T('variables.designer_image_float_hint')}</p>
          <div class="grid grid-cols-3 gap-2">
            <div>
              <label class="text-xs tx-secondary">${T('variables.designer_image_float_h')}</label>
              <select name="fd_${idx}_float_h" class="tx-select text-xs">
                <option value="left"${floatH === 'left' ? ' selected' : ''}>${T('variables.designer_image_pos_left')}</option>
                <option value="center"${floatH === 'center' ? ' selected' : ''}>${T('variables.designer_image_pos_center')}</option>
                <option value="right"${floatH === 'right' ? ' selected' : ''}>${T('variables.designer_image_pos_right')}</option>
              </select>
            </div>
            <div>
              <label class="text-xs tx-secondary">${T('variables.designer_image_float_v')}</label>
              <select name="fd_${idx}_float_v" class="tx-select text-xs">
                <option value="top"${floatV === 'top' ? ' selected' : ''}>${T('variables.designer_image_pos_top')}</option>
                <option value="middle"${floatV === 'middle' ? ' selected' : ''}>${T('variables.designer_image_pos_middle')}</option>
                <option value="bottom"${floatV === 'bottom' ? ' selected' : ''}>${T('variables.designer_image_pos_bottom')}</option>
              </select>
            </div>
            <div>
              <label class="text-xs tx-secondary">${T('variables.designer_image_wrap')}</label>
              <select name="fd_${idx}_wrap" class="tx-select text-xs">
                <option value="square"${wrap === 'square' ? ' selected' : ''}>${T('variables.designer_image_wrap_square')}</option>
                <option value="topAndBottom"${wrap === 'topAndBottom' ? ' selected' : ''}>${T('variables.designer_image_wrap_topbottom')}</option>
                <option value="none"${wrap === 'none' ? ' selected' : ''}>${T('variables.designer_image_wrap_none')}</option>
              </select>
            </div>
          </div>
        </div>`
      }
      return ''
    }

    function renderVariableColumnEditor(fieldIdx, columns) {
      const colTypes = ['text', 'textarea', 'list', 'date', 'number']
      const colRows = columns
        .map((col, ci) => {
          const curType = col.type || 'text'
          const typeOpts = colTypes
            .map(
              (ct) =>
                `<option value="${ct}"${ct === curType ? ' selected' : ''}>${T(`variables.col_type_${ct}`, ct)}</option>`
            )
            .join('')
          // List columns pick a document rendering mode: "flat" = every
          // item becomes a bullet; "structured" = date-range headers and
          // position titles are detected and rendered without bullets
          // (the classic HR-profile layout). Matching the backend, a
          // column keyed `details` defaults to structured, all others to
          // flat — unless `structured` is explicitly set.
          let formatRow = ''
          if (curType === 'list') {
            const structured =
              col.structured !== undefined
                ? !!col.structured
                : (col.key || '').toLowerCase() === 'details'
            // Bullet glyph + indent (feedback #4). Empty glyph = keep the
            // template/default "•"; a custom glyph forces a deterministic
            // character bullet even when the Word template defines no list.
            const curGlyph = (col.bullet_char || '').toString()
            const glyphOpts = ['', '■', '▪', '●', '–', '♥', '▸']
              .map(
                (g) =>
                  `<option value="${escHtml(g)}"${g === curGlyph ? ' selected' : ''}>${g === '' ? T('variables.col_bullet_default') : g}</option>`
              )
              .join('')
            const curIndent =
              col.bullet_indent_cm !== undefined && col.bullet_indent_cm !== null
                ? String(col.bullet_indent_cm)
                : ''
            formatRow = `<div class="flex items-center gap-2 pl-1 flex-wrap" style="margin-top:.125rem">
              <span class="text-xs tx-secondary" style="white-space:nowrap">${T('variables.col_list_format')}</span>
              <select name="fc_${fieldIdx}_cs_${ci}" class="tx-select text-xs" style="width:auto;min-width:14rem">
                <option value="flat"${!structured ? ' selected' : ''}>${T('variables.col_list_format_flat')}</option>
                <option value="structured"${structured ? ' selected' : ''}>${T('variables.col_list_format_structured')}</option>
              </select>
              ${tooltipIcon(T('variables.col_list_format_hint'))}
              <span class="text-xs tx-secondary" style="white-space:nowrap">${T('variables.col_bullet_glyph')}</span>
              <select name="fc_${fieldIdx}_bch_${ci}" class="tx-select text-xs" style="width:auto">${glyphOpts}</select>
              <span class="text-xs tx-secondary" style="white-space:nowrap">${T('variables.col_bullet_indent')}</span>
              <input name="fc_${fieldIdx}_bin_${ci}" type="number" min="0" step="0.1" value="${escHtml(curIndent)}" placeholder="0.5" class="tx-input text-xs" style="width:5rem" />
              ${tooltipIcon(T('variables.col_bullet_hint'))}
            </div>`
          }
          return `<div class="space-y-1" data-col-idx="${ci}">
            <div class="flex items-center gap-2">
              <input name="fc_${fieldIdx}_ck_${ci}" value="${escHtml(col.key || '')}" placeholder="${T('variables.column_key')}" class="tx-input text-xs" style="flex:1" />
              <input name="fc_${fieldIdx}_cl_${ci}" value="${escHtml(col.label || '')}" placeholder="${T('variables.column_label')}" class="tx-input text-xs" style="flex:1" />
              <select name="fc_${fieldIdx}_ct_${ci}" class="tx-select text-xs" style="width:7.5rem">${typeOpts}</select>
              <button type="button" data-action="var-col-remove" data-field-idx="${fieldIdx}" data-col-idx="${ci}" class="p-0.5" style="color:var(--txt-secondary)">${ICONS.trash}</button>
            </div>
            ${formatRow}
          </div>`
        })
        .join('')
      return `<div class="ml-0 p-2 rounded" style="background:var(--bg-app);border:1px dashed var(--divider)">
        <div class="flex items-center justify-between mb-1.5">
          <span class="text-xs font-medium tx-secondary">${T('variables.table_columns')}</span>
          <button type="button" data-action="var-col-add" data-field-idx="${fieldIdx}" class="tx-link text-xs flex items-center gap-1">${ICONS.plus} ${T('variables.add_column')}</button>
        </div>
        <p class="text-xs tx-secondary mb-1">${T('variables.column_type_hint')}</p>
        <div class="space-y-1.5">${colRows}</div>
      </div>`
    }

    /**
     * Diff modal for AI-suggested variable polish. Shows current vs
     * proposed description / examples / negative_hint per field with
     * per-field accept toggles. The admin selects what to apply, and
     * the apply step PUTs the merged fields[] back to /forms/{id}.
     */
    function renderPolishModal(c) {
      const r = state.polishResults || { enhancements: [] }
      const enhancements = Array.isArray(r.enhancements) ? r.enhancements : []
      const sel = state.polishSelected || {}
      const meta = state.polishMeta || {}

      const groundedLine = meta.filename
        ? `<div class="text-xs tx-secondary">${ICONS.doc} ${escHtml(Tf('variables.polish_grounded_in', { filename: meta.filename }))}</div>`
        : `<div class="text-xs tx-secondary">${ICONS.info} ${escHtml(T('variables.polish_no_sample'))}</div>`

      const errorBlock = state.polishError
        ? `<div class="tx-callout" style="background:color-mix(in srgb, var(--status-error) 12%, var(--bg-card));color:var(--status-error)">${escHtml(state.polishError)}</div>`
        : ''

      const renderExamples = (arr) => {
        if (!Array.isArray(arr) || arr.length === 0) {
          return `<span class="text-xs tx-secondary italic">${T('variables.polish_no_examples')}</span>`
        }
        return arr
          .map(
            (e) =>
              `<span class="tx-badge" style="background:var(--bg-chip);color:var(--txt-secondary);font-family:monospace">${escHtml(e)}</span>`
          )
          .join(' ')
      }

      const fmtCell = (label, current, suggested, accent = 'var(--brand)') => {
        const same = (current || '').toString().trim() === (suggested || '').toString().trim()
        const sugLine = same
          ? `<div class="text-xs tx-secondary italic">${T('variables.polish_no_change')}</div>`
          : `<div class="text-xs" style="color:${accent}">${escHtml(suggested || '—')}</div>`
        return `<div class="space-y-1">
          <div class="text-xs font-medium tx-secondary uppercase tracking-wide">${escHtml(label)}</div>
          <div class="text-xs tx-secondary">${escHtml(current || '—')}</div>
          <div style="height:1px;background:var(--divider)"></div>
          ${sugLine}
        </div>`
      }

      const fmtExamplesCell = (current, suggested) => {
        const a = JSON.stringify(Array.isArray(current) ? current : [])
        const b = JSON.stringify(Array.isArray(suggested) ? suggested : [])
        const same = a === b
        return `<div class="space-y-1">
          <div class="text-xs font-medium tx-secondary uppercase tracking-wide">${T('variables.polish_field_examples')}</div>
          <div>${renderExamples(current)}</div>
          <div style="height:1px;background:var(--divider)"></div>
          ${same ? `<div class="text-xs tx-secondary italic">${T('variables.polish_no_change')}</div>` : `<div>${renderExamples(suggested)}</div>`}
        </div>`
      }

      const rows = enhancements.length
        ? enhancements
            .map((en, i) => {
              const checked = !!sel[en.key]
              return `<div class="tx-row p-3 space-y-2" data-polish-key="${escHtml(en.key)}">
                <div class="flex items-center gap-2">
                  <input type="checkbox" data-action="polish-toggle" data-key="${escHtml(en.key)}" ${checked ? 'checked' : ''} class="h-4 w-4" style="accent-color:var(--brand)" />
                  <div class="flex-1 min-w-0">
                    <div class="font-medium text-sm">${escHtml(en.label || en.key)}</div>
                    <div class="text-xs tx-secondary font-mono">${escHtml(en.key)} · ${escHtml(en.type || 'text')}</div>
                  </div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                  ${fmtCell(T('variables.polish_field_description'), en.current?.description || en.current?.hint || '', en.suggested?.description || '')}
                  ${fmtExamplesCell(en.current?.examples || [], en.suggested?.examples || [])}
                  ${fmtCell(T('variables.polish_field_negative_hint'), en.current?.negative_hint || '', en.suggested?.negative_hint || '', 'var(--status-warning,#d97706)')}
                </div>
              </div>`
            })
            .join('')
        : `<div class="tx-card p-6 text-center">
            <div class="inline-flex items-center justify-center w-10 h-10 rounded-full mb-2" style="background:var(--bg-chip);color:var(--txt-secondary)">${ICONS.info}</div>
            <p class="text-sm tx-secondary">${T('variables.polish_empty')}</p>
          </div>`

      const selectedCount = enhancements.filter((e) => sel[e.key]).length

      return `<div class="space-y-3" data-testid="polish-modal">
        <div class="flex items-start justify-between gap-3 flex-wrap">
          <div>
            <div class="flex items-center gap-2">
              <h3 class="text-lg font-semibold">${ICONS.sparkle} ${T('variables.polish_modal_title')}</h3>
            </div>
            <p class="text-sm tx-secondary mt-1" style="max-width:640px">${T('variables.polish_modal_subtitle')}</p>
            ${groundedLine}
            ${meta.model ? `<div class="text-xs tx-secondary">${T('datasets.extract_model')}: <span class="font-mono">${escHtml(meta.model)}</span> · ${meta.elapsed_ms ?? 0} ms</div>` : ''}
          </div>
          <div class="flex items-center gap-2 flex-wrap">
            <button data-action="polish-select-all" class="tx-btn tx-btn-sm tx-btn-ghost"${enhancements.length === 0 ? ' disabled' : ''}>${T('variables.polish_accept_all')}</button>
            <button data-action="polish-select-none" class="tx-btn tx-btn-sm tx-btn-ghost"${enhancements.length === 0 ? ' disabled' : ''}>${T('variables.polish_select_none')}</button>
            <button data-action="polish-cancel" class="tx-btn tx-btn-sm tx-btn-ghost">${T('app.cancel')}</button>
            <button data-action="polish-apply" class="tx-btn tx-btn-sm"${selectedCount === 0 || state.polishApplying ? ' disabled' : ''}>${state.polishApplying ? T('app.loading') : Tf('variables.polish_apply', { n: selectedCount })}</button>
          </div>
        </div>
        ${errorBlock}
        <div class="space-y-2" style="max-height:60vh;overflow-y:auto">${rows}</div>
      </div>`
    }

    function renderVariablesImport(c) {
      const parsed = state.variablesImportFields
      const hasPreview = parsed && parsed.length > 0
      return `<div class="space-y-4">
        <button data-action="variables-import-close" class="flex items-center gap-1 text-sm transition-colors" style="color:var(--txt-secondary)">${ICONS.back} ${T('app.back')}</button>
        <h3 class="text-lg font-semibold">${T('variables.import_from_text')}</h3>
        <div class="tx-card p-5 space-y-3">
          <label class="tx-label">${T('variables.import_hint')}</label>
          <textarea id="tx-import-text" class="tx-textarea" rows="8" placeholder="${T('variables.import_hint')}" style="font-family:monospace;font-size:.8rem">${escHtml(state.variablesImportText || '')}</textarea>
          <div class="flex items-center gap-3">
            <span class="text-xs tx-secondary">…or drop a .docx:</span>
            <input type="file" id="tx-import-file" accept=".docx" class="text-sm" />
          </div>
          <div class="flex items-center gap-2">
            <button data-action="variables-import-parse" class="tx-btn tx-btn-sm"${state.variablesImportParsing ? ' disabled' : ''}>
              ${state.variablesImportParsing ? `<span class="animate-spin inline-block w-4 h-4 border-2 border-white border-t-transparent rounded-full"></span>` : ICONS.sparkle}
              ${state.variablesImportParsing ? T('app.loading') : T('variables.from_template')}
            </button>
            ${state.variablesImportError ? `<span class="text-sm" style="color:var(--status-error)">${escHtml(state.variablesImportError)}</span>` : ''}
          </div>
        </div>
        ${hasPreview ? renderVariablesImportPreview(parsed) : ''}
      </div>`
    }

    function renderVariablesTplImport(c) {
      const templates = collectionTemplates(c)
      if (templates.length === 0) {
        return `<div class="space-y-4">
          <button data-action="variables-tplimport-close" class="flex items-center gap-1 text-sm transition-colors" style="color:var(--txt-secondary)">${ICONS.back} ${T('app.back')}</button>
          <h3 class="text-lg font-semibold">${T('variables.import_dialog_title')}</h3>
          <div class="tx-card p-5">
            <p class="text-sm">${T('variables.import_no_templates')}</p>
          </div>
        </div>`
      }

      const pickerOptions = templates
        .map(
          (t) =>
            `<option value="${escHtml(t.id)}"${state.variablesTplImportTemplateId === t.id ? ' selected' : ''}>${escHtml(t.name || t.id)} \u2014 ${t.placeholder_count ?? (t.placeholders?.length || 0)} ${T('templates.placeholder_count')}</option>`
        )
        .join('')

      const loading = state.variablesTplImportLoading
      const error = state.variablesTplImportError
      const result = state.variablesTplImportResult

      let body = ''
      if (loading) {
        body = `<div class="tx-card p-5 text-center">
          <span class="animate-spin inline-block w-5 h-5 border-2 border-current border-t-transparent rounded-full"></span>
          <p class="text-sm tx-secondary mt-2">${T('app.loading')}</p>
        </div>`
      } else if (error) {
        body = `<div class="tx-card p-5"><p class="text-sm" style="color:var(--status-error)">${escHtml(error)}</p></div>`
      } else if (result) {
        body = renderVariablesTplImportPreview(result)
      }

      return `<div class="space-y-4">
        <button data-action="variables-tplimport-close" class="flex items-center gap-1 text-sm transition-colors" style="color:var(--txt-secondary)">${ICONS.back} ${T('app.back')}</button>
        <h3 class="text-lg font-semibold">${T('variables.import_dialog_title')}</h3>
        <p class="text-sm tx-secondary" style="max-width:640px">${T('variables.import_dialog_subtitle')}</p>
        <div class="tx-card p-5 space-y-3">
          <label class="tx-label">${T('variables.import_pick_template')}</label>
          <div class="flex items-center gap-2 flex-wrap">
            <select id="tx-tplimport-picker" class="tx-select" style="max-width:360px">${pickerOptions}</select>
            <button data-action="variables-tplimport-load" class="tx-btn tx-btn-sm"${loading ? ' disabled' : ''}>${ICONS.sparkle} ${T('templates.detect_placeholders')}</button>
          </div>
          <p class="text-xs tx-secondary">${T('variables.import_pick_template_hint')}</p>
        </div>
        ${body}
      </div>`
    }

    function renderVariablesTplImportPreview(result) {
      const suggestions = result.suggestions || []
      const sel = state.variablesTplImportSelection || {}
      const summary = result.summary || {}

      const summaryText = T('variables.import_summary')
        .replace('{total}', result.placeholder_count ?? 0)
        .replace('{tables}', summary.tables || 0)
        .replace('{checkboxes}', summary.checkboxes || 0)
        .replace('{lists}', summary.lists || 0)
        .replace('{texts}', summary.texts || 0)
        .replace('{duplicate}', summary.duplicate || 0)

      const rows = suggestions
        .map((f, i) => {
          const isDup = f._status === 'duplicate'
          const checked = sel[i] === true
          const extras = describeFieldExtras(f)
          return `<tr class="tx-divider border-t${isDup ? ' opacity-60' : ''}">
            <td class="py-2 px-3"><input type="checkbox" data-action="variables-tplimport-toggle" data-idx="${i}" ${checked ? 'checked' : ''} ${isDup ? '' : ''} class="h-4 w-4" style="accent-color:var(--brand)" /></td>
            <td class="py-2 px-3 font-mono text-xs">${escHtml(f.key)}${isDup ? ` <span class="tx-secondary text-xs">(${T('variables.import_duplicate_key')})</span>` : ''}</td>
            <td class="py-2 px-3 text-sm">${escHtml(f.label || '')}</td>
            <td class="py-2 px-3 text-xs">${escHtml(T(`variables.type_${f.type}`, f.type))}</td>
            <td class="py-2 px-3 text-xs tx-secondary">${escHtml(extras)}</td>
          </tr>`
        })
        .join('')

      const selectedCount = Object.values(sel).filter(Boolean).length
      const applyLabel =
        selectedCount > 0
          ? T('variables.import_apply_count').replace('{count}', selectedCount)
          : T('variables.import_apply_none')

      return `<div class="tx-card p-5 space-y-3">
        <div class="flex items-center justify-between gap-3 flex-wrap">
          <h4 class="text-sm font-medium">${T('variables.import_placeholders_label')}</h4>
          <p class="text-xs tx-secondary">${escHtml(summaryText)}</p>
        </div>
        <div class="overflow-x-auto">
          <table class="w-full text-left">
            <thead>
              <tr class="tx-divider border-b text-xs tx-secondary uppercase tracking-wider">
                <th class="py-2 px-3">${T('variables.import_col_use')}</th>
                <th class="py-2 px-3">${T('variables.import_col_key')}</th>
                <th class="py-2 px-3">${T('variables.import_col_label')}</th>
                <th class="py-2 px-3">${T('variables.import_col_type')}</th>
                <th class="py-2 px-3">${T('variables.import_col_extras')}</th>
              </tr>
            </thead>
            <tbody>${rows}</tbody>
          </table>
        </div>
        <div class="flex items-center gap-2">
          <button data-action="variables-tplimport-apply" class="tx-btn tx-btn-sm"${selectedCount === 0 ? ' disabled' : ''}>${ICONS.check} ${escHtml(applyLabel)}</button>
          <button data-action="variables-tplimport-close" class="tx-btn tx-btn-sm tx-btn-ghost">${T('app.cancel')}</button>
        </div>
      </div>`
    }

    function describeFieldExtras(f) {
      if (f.type === 'table') {
        const cols = (f.columns || []).map((c) => c.key).join(', ')
        return T('variables.import_extra_table_cols')
          .replace('{count}', (f.columns || []).length)
          .replace('{cols}', cols)
      }
      if (f.type === 'checkbox') return T('variables.import_extra_checkbox')
      if (f.type === 'list') return T('variables.import_extra_list')
      return T('variables.import_extra_text')
    }

    function renderVariablesImportPreview(parsed) {
      const rows = parsed
        .map(
          (f, i) =>
            `<tr class="tx-divider border-t">
              <td class="py-2 px-3 font-mono text-xs">${escHtml(f.key)}</td>
              <td class="py-2 px-3 text-sm">${escHtml(f.label || '')}</td>
              <td class="py-2 px-3 text-xs">${escHtml(T(`variables.type_${f.type || 'text'}`, f.type || 'text'))}</td>
              <td class="py-2 px-3 text-xs tx-secondary">${escHtml(f.hint || '')}</td>
              <td class="py-2 px-3">
                <button data-action="variables-import-remove" data-idx="${i}" class="p-1" style="color:var(--txt-secondary)">${ICONS.trash}</button>
              </td>
            </tr>`
        )
        .join('')
      return `<div class="tx-card p-5 space-y-3">
        <h4 class="text-sm font-medium">${parsed.length} fields parsed</h4>
        <div class="overflow-x-auto">
          <table class="w-full text-left">
            <thead>
              <tr class="tx-divider border-b text-xs tx-secondary uppercase tracking-wider">
                <th class="py-2 px-3">${T('variables.field_key')}</th>
                <th class="py-2 px-3">${T('variables.field_label')}</th>
                <th class="py-2 px-3">${T('variables.field_type')}</th>
                <th class="py-2 px-3">${T('variables.field_hint')}</th>
                <th class="py-2 px-3"></th>
              </tr>
            </thead>
            <tbody>${rows}</tbody>
          </table>
        </div>
        <div class="flex items-center gap-2">
          <button data-action="variables-import-apply" class="tx-btn tx-btn-sm">${ICONS.check} ${T('app.continue')}</button>
          <button data-action="variables-import-close" class="tx-btn tx-btn-sm tx-btn-ghost">${T('app.cancel')}</button>
        </div>
      </div>`
    }

    // --- Templates tab ---

    function renderTemplatesTab(c) {
      const templates = collectionTemplates(c)

      const header = `<div class="flex items-start justify-between gap-3 flex-wrap">
        <div>
          <div class="flex items-center gap-2 mb-1">
            <h3 class="text-lg font-semibold">${T('templates.title')}</h3>
            ${helpTrigger('templates')}
          </div>
          <p class="text-sm tx-secondary" style="max-width:640px">${T('templates.subtitle')}</p>
        </div>
      </div>`

      const inheritLabel = c?.language
        ? `${T('templates.language_inherit')} (${c.language.toUpperCase()})`
        : T('templates.language_inherit')
      const langOptions = `
        <option value="">${escHtml(inheritLabel)}</option>
        <option value="de">Deutsch (DE)</option>
        <option value="en">English (EN)</option>
        <option value="es">Espa\u00f1ol (ES)</option>
        <option value="fr">Fran\u00e7ais (FR)</option>
        <option value="it">Italiano (IT)</option>
        <option value="tr">T\u00fcrk\u00e7e (TR)</option>
        <option value="pt">Portugu\u00eas (PT)</option>
        <option value="nl">Nederlands (NL)</option>
        <option value="pl">Polski (PL)</option>
      `

      const drop = `<div id="tx-template-drop" class="tx-drop">
        <div class="inline-flex items-center justify-center w-10 h-10 rounded-full mb-2" style="background:var(--brand-alpha-light);color:var(--brand)">${ICONS.upload}</div>
        <p class="text-sm font-medium">${T('templates.drop_hint')}</p>
        <input type="file" id="tx-template-file" accept=".docx" class="hidden" />
        <div id="tx-template-upload-form" class="mt-3 hidden">
          <div class="flex flex-col gap-2 max-w-md mx-auto">
            <div class="flex items-center gap-2">
              <input type="text" id="tx-template-name" placeholder="${T('templates.name_label')}" class="tx-input" style="flex:1" />
            </div>
            <div class="flex flex-col gap-1">
              <label class="text-xs tx-secondary text-left" for="tx-template-language">${T('templates.language_label')}</label>
              <select id="tx-template-language" class="tx-input">${langOptions}</select>
              <p class="text-xs tx-secondary text-left">${T('templates.language_hint')}</p>
            </div>
            <button id="tx-upload-template-btn" class="tx-btn tx-btn-sm self-end">${ICONS.upload} ${T('templates.upload')}</button>
          </div>
        </div>
      </div>`

      const buildLangSelect = (tpl) => {
        const cur = (tpl.language || '').toLowerCase()
        const opts = [
          ['', inheritLabel],
          ['de', 'DE'],
          ['en', 'EN'],
          ['es', 'ES'],
          ['fr', 'FR'],
          ['it', 'IT'],
          ['tr', 'TR'],
          ['pt', 'PT'],
          ['nl', 'NL'],
          ['pl', 'PL'],
        ]
        return `<select data-template-language-set="${tpl.id}" class="tx-input" style="height:28px;font-size:0.75rem;padding:0 0.4rem" title="${escHtml(T('templates.language_hint'))}">
          ${opts.map(([v, l]) => `<option value="${v}"${v === cur ? ' selected' : ''}>${escHtml(l)}</option>`).join('')}
        </select>`
      }

      const list = templates.length
        ? templates
            .map(
              (tpl) => `<div class="tx-row p-4">
                <div class="flex items-center justify-between gap-3">
                  <div class="min-w-0 flex-1">
                    <div class="font-medium truncate">${escHtml(tpl.name)}</div>
                    <div class="text-xs tx-secondary mt-0.5">${formatDate(tpl.created_at)}${tpl.placeholder_count != null ? ` · ${tpl.placeholder_count} ${T('templates.placeholders')}` : ''}</div>
                  </div>
                  <div class="flex items-center gap-1">
                    ${buildLangSelect(tpl)}
                    <button data-action="template-check-match" data-template-id="${tpl.id}" class="tx-btn tx-btn-sm tx-btn-ghost" title="${T('templates.check_match')}">${ICONS.check} ${T('templates.check_match')}</button>
                    <button data-download-template="${tpl.id}" class="p-1.5 text-gray-400 hover:text-blue-500 rounded transition-colors" title="${T('app.download')}">${ICONS.download}</button>
                    <button data-detach-template="${tpl.id}" class="p-1.5 text-gray-400 hover:text-red-500 rounded transition-colors" title="${T('app.delete')}">${ICONS.trash}</button>
                  </div>
                </div>
              </div>`
            )
            .join('')
        : `<div class="tx-card p-6 text-center">
            <div class="inline-flex items-center justify-center w-12 h-12 rounded-full mb-3" style="background:var(--bg-chip);color:var(--txt-secondary)">${ICONS.file}</div>
            <p class="text-sm tx-secondary">${T('templates.empty_hint')}</p>
          </div>`

      return `<div class="space-y-4">
        ${header}
        ${drop}
        <div class="space-y-2">${list}</div>
        ${state.selectedTemplateDetail ? renderTemplateMatching() : ''}
      </div>`
    }

    function renderTemplateMatching() {
      const tpl = state.selectedTemplateDetail
      const c = collectionById(state.collectionId)
      const phs = tpl.placeholders || []
      const fieldMap = Object.fromEntries((c.fields || []).map((f) => [f.key, f]))

      const structural = phs.filter(
        (p) =>
          p.type === 'block_marker' ||
          (p.key || p.name || '').startsWith('#') ||
          (p.key || p.name || '').startsWith('/')
      )
      const data = phs.filter((p) => !structural.includes(p))
      const seen = new Set()
      const deduped = []
      for (const p of data) {
        const k = (p.key || p.name || '').replace(/^checkb\./, '').split('.')[0]
        if (!seen.has(k)) {
          seen.add(k)
          deduped.push({ ...p, matchKey: k })
        }
      }
      let matched = 0

      /**
       * Human-readable rendering hint for a placeholder in the template:
       * is it a `{{checkb.X.yes/no}}` glyph pair (pretty) or a plain
       * `{{X}}` checkbox (renders as text "Ja"/"Nein")? For list / table
       * types we reuse the rendering-badge glyph so the user gets a
       * glanceable mode summary.
       */
      const modeHintFor = (ph, f) => {
        const rawKey = (ph.key || ph.name || '').toString()
        const isGlyphPair = rawKey.startsWith('checkb.')
        if (f && f.type === 'checkbox') {
          return (
            renderFieldRenderingBadge(f) +
            (isGlyphPair
              ? `<span class="tx-hint" style="margin:0 0 0 .5rem">${T('templates.match_checkbox_glyph')}</span>`
              : `<span class="tx-hint" style="margin:0 0 0 .5rem;color:var(--status-warning,#d97706)">${T('templates.match_checkbox_plain')}</span>`)
          )
        }
        if (f) return renderFieldRenderingBadge(f)
        return ''
      }

      const rows = deduped
        .map((ph) => {
          const f = fieldMap[ph.matchKey]
          const isAdding = state.templateMatchAddKey === ph.matchKey
          if (f) {
            matched++
            const hint = modeHintFor(ph, f)
            return `<tr class="tx-divider border-t">
              <td class="py-2.5 px-3"><span class="font-mono text-sm">${escHtml(ph.key || ph.name)}</span></td>
              <td class="py-2.5 px-3">
                <div class="flex items-center gap-2 flex-wrap">
                  <span class="inline-flex items-center gap-1.5 text-sm" style="color:var(--status-success)">
                    ${ICONS.check}
                    <span class="font-medium">${escHtml(f.label || f.key)}</span>
                  </span>
                  ${hint}
                </div>
              </td>
            </tr>`
          }
          if (isAdding) {
            const typeOpts = ['text', 'textarea', 'select', 'list', 'date', 'number', 'checkbox']
              .map(
                (tp) =>
                  `<option value="${tp}"${tp === (ph.type === 'list' ? 'list' : ph.type === 'checkbox' ? 'checkbox' : 'text') ? ' selected' : ''}>${T(`variables.type_${tp}`, tp)}</option>`
              )
              .join('')
            return `<tr class="tx-divider border-t">
              <td class="py-2.5 px-3"><span class="font-mono text-sm">${escHtml(ph.key || ph.name)}</span></td>
              <td class="py-2.5 px-3">
                <div class="space-y-2" data-add-form-key="${escHtml(ph.matchKey)}">
                  <div class="grid grid-cols-2 gap-2" style="max-width:20rem">
                    <input name="add_label" placeholder="${T('variables.field_label')}" class="tx-input" value="${escHtml(ph.matchKey.replace(/[-_]/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase()))}" />
                    <select name="add_type" class="tx-select">${typeOpts}</select>
                  </div>
                  <input name="add_hint" placeholder="${T('variables.field_hint')}" class="tx-input" />
                  <div class="flex items-center gap-2">
                    <button data-action="template-match-save" data-key="${escHtml(ph.matchKey)}" class="tx-btn tx-btn-sm">${ICONS.check} ${T('app.add')}</button>
                    <button data-action="template-match-cancel" class="tx-btn tx-btn-sm tx-btn-ghost">${T('app.cancel')}</button>
                  </div>
                </div>
              </td>
            </tr>`
          }
          return `<tr class="tx-divider border-t">
            <td class="py-2.5 px-3"><span class="font-mono text-sm">${escHtml(ph.key || ph.name)}</span></td>
            <td class="py-2.5 px-3">
              <span class="inline-flex items-center gap-1.5 text-sm" style="color:var(--status-warning,#d97706)">
                ${ICONS.warning}
                <span>${T('templates.match_unmatched')}</span>
              </span>
              <button data-action="template-match-add" data-key="${escHtml(ph.matchKey)}" class="ml-2 tx-btn tx-btn-sm tx-btn-ghost">${ICONS.plus} ${T('templates.match_add_field')}</button>
            </td>
          </tr>`
        })
        .join('')

      const allMatched = matched === deduped.length
      const summary = allMatched
        ? T('templates.match_all_covered')
        : Tf('templates.match_summary', { matched, total: deduped.length })

      // Health tips — non-blocking, glanceable guidance the user can act on
      // before generating. Three sources of tips:
      //   1. A checkbox-typed field whose template uses the plain `{{X}}`
      //      form instead of the `{{checkb.X.yes/no}}` pair (renders as
      //      text "Ja/Nein", not \u2612/\u2610 glyphs).
      //   2. A declared list-typed field with NO matching placeholder in
      //      the template at all (user added a variable but forgot to use
      //      it in the Word document).
      //   3. An image-typed field missing from the template.
      const tips = []
      const phRawKeys = new Set(phs.map((p) => p.key || p.name || ''))
      const glyphCbKeys = new Set()
      for (const raw of phRawKeys) {
        const m = raw.match(/^checkb\.(.+?)\.(?:yes|no)$/)
        if (m) glyphCbKeys.add(m[1])
      }
      for (const f of c.fields || []) {
        if (!f.key) continue
        const plainUsed = phRawKeys.has(f.key)
        if (f.type === 'checkbox' && plainUsed && !glyphCbKeys.has(f.key)) {
          tips.push({
            level: 'warn',
            html: Tf('templates.tip_checkbox_plain', {
              key: `<code>${escHtml(f.key)}</code>`,
              pair: `<code>{{checkb.${escHtml(f.key)}.yes}} Ja  {{checkb.${escHtml(f.key)}.no}} Nein</code>`,
            }),
          })
        }
        if (f.type === 'list' && !plainUsed) {
          tips.push({
            level: 'info',
            html: Tf('templates.tip_list_unused', {
              key: `<code>{{${escHtml(f.key)}}}</code>`,
            }),
          })
        }
        if (f.type === 'image' && !plainUsed) {
          tips.push({
            level: 'info',
            html: Tf('templates.tip_image_unused', {
              key: `<code>{{${escHtml(f.key)}}}</code>`,
            }),
          })
        }
      }

      const tipsHtml = tips.length
        ? `<div class="mt-3 space-y-1.5">
            ${tips
              .map((t) => {
                const color =
                  t.level === 'warn' ? 'var(--status-warning,#d97706)' : 'var(--txt-secondary)'
                const icon = t.level === 'warn' ? ICONS.warning : ICONS.info || ICONS.question
                return `<div class="flex items-start gap-1.5 text-xs" style="color:${color}">
                ${icon}
                <span>${t.html}</span>
              </div>`
              })
              .join('')}
          </div>`
        : ''

      return `<div class="tx-card p-5 mt-2">
        <div class="flex items-center justify-between mb-3">
          <h4 class="font-medium text-sm">${escHtml(tpl.name)} · ${summary}</h4>
          <button data-action="template-match-close" class="tx-link text-sm">${T('app.close')}</button>
        </div>
        <div class="overflow-x-auto">
          <table class="w-full text-left">
            <thead>
              <tr class="tx-divider border-b text-xs tx-secondary uppercase tracking-wider">
                <th class="py-2 px-3" style="width:45%">Placeholder</th>
                <th class="py-2 px-3">Variable</th>
              </tr>
            </thead>
            <tbody>${rows}</tbody>
          </table>
        </div>
        ${tipsHtml}
        ${
          structural.length > 0
            ? `<div class="mt-3 text-xs tx-secondary">
                <span class="font-medium">${T('templates.match_structural')}:</span>
                ${structural.map((p) => `<code class="ml-1">${escHtml(p.key || p.name)}</code>`).join(', ')}
              </div>`
            : ''
        }
      </div>`
    }

    // --- Datasets tab ---

    const DATASETS_PER_PAGE = 25

    function renderDatasetsTab(c) {
      if (state.selectedDataset) return renderDatasetDetail(c)
      if (state.newDatasetOpen) return renderNewDataset(c)

      const fields = c.fields || []
      if (fields.length === 0) {
        return `<div class="tx-card p-6 text-center">
          <div class="inline-flex items-center justify-center w-12 h-12 rounded-full mb-3" style="background:var(--bg-chip);color:var(--txt-secondary)">${ICONS.variable}</div>
          <h4 class="font-semibold text-lg mb-1">${T('collection.summary_no_vars')}</h4>
          <button data-tab="setup" class="tx-btn tx-btn-sm mt-3">${T('collection.goto_variables')}</button>
        </div>`
      }

      const allDatasets = datasetsForCollection(c.id)
      const q = state.datasetsSearch.trim().toLowerCase()
      let filtered = allDatasets
      if (q) {
        filtered = allDatasets.filter((d) => {
          const hay = [
            datasetDisplayName(d),
            ...Object.values(d.field_values || {}).map(String),
            ...Object.values(d.ai_extracted || {}).map(String),
          ]
            .join(' ')
            .toLowerCase()
          return q.split(/\s+/).every((tok) => hay.includes(tok))
        })
      }
      filtered.sort(
        (a, b) =>
          (state.datasetsSortNewest ? -1 : 1) *
          (new Date(a.created_at || 0) - new Date(b.created_at || 0))
      )

      const totalPages = Math.max(1, Math.ceil(filtered.length / DATASETS_PER_PAGE))
      if (state.datasetsPage >= totalPages) state.datasetsPage = Math.max(0, totalPages - 1)
      const start = state.datasetsPage * DATASETS_PER_PAGE
      const pageItems = filtered.slice(start, start + DATASETS_PER_PAGE)

      // "Continue last" jumps straight back into the most recent
      // dataset that's still draft/extracted/reviewed — by far the
      // most common daily action. Skipped for "generated" since
      // re-opening a finished dataset rarely has value.
      const continuable = findContinuableDataset(c.id)
      const continueBtn = continuable
        ? `<button data-action="continue-last-dataset" data-id="${escHtml(continuable.id)}" data-testid="btn-continue-last-dataset" class="tx-btn tx-btn-sm tx-btn-ghost" title="${escHtml(datasetDisplayName(continuable))}">
            ${ICONS.refresh} ${T('datasets.continue_last')}
          </button>`
        : ''

      const header = `<div class="flex items-start justify-between gap-3 flex-wrap">
        <div>
          <h3 class="text-lg font-semibold mb-1">${T('datasets.title')}</h3>
          <p class="text-sm tx-secondary" style="max-width:640px">${T('datasets.subtitle')}</p>
        </div>
        <div class="flex items-center gap-2">
          ${continueBtn}
          <button data-action="new-dataset" class="tx-btn tx-btn-sm">${ICONS.plus} ${T('datasets.new')}</button>
        </div>
      </div>`

      // Surface a GDPR-style overdue banner so the user can bulk-delete
      // datasets whose retention window (delete_after_months) has elapsed.
      const overdueDatasets = state.retentionBannerDismissed
        ? []
        : allDatasets.filter(datasetIsOverdue)
      const overdueBanner = renderOverdueBanner(overdueDatasets)

      if (allDatasets.length === 0) {
        return `<div class="space-y-4">
          ${header}
          ${overdueBanner}
          <div class="tx-card p-8 text-center">
            <div class="inline-flex items-center justify-center w-12 h-12 rounded-full mb-3" style="background:var(--bg-chip);color:var(--txt-secondary)">${ICONS.database}</div>
            <h4 class="font-semibold text-lg mb-1">${T('datasets.empty')}</h4>
            <p class="text-sm tx-secondary" style="max-width:440px;margin-left:auto;margin-right:auto">${T('datasets.empty_hint')}</p>
          </div>
        </div>`
      }

      const toolbar = `<div class="flex items-center gap-2">
        <div class="relative flex-1">
          <span class="absolute top-1/2 -translate-y-1/2 text-gray-400 pointer-events-none" style="left:12px">${ICONS.search}</span>
          <input id="tx-datasets-search" type="text" value="${escHtml(state.datasetsSearch)}" placeholder="${T('datasets.search_placeholder')}" class="tx-input" style="padding-left:36px" />
        </div>
        <button id="tx-datasets-sort" class="tx-btn tx-btn-sm tx-btn-ghost" title="${state.datasetsSortNewest ? T('datasets.sort_newest') : T('datasets.sort_oldest')}">
          ${state.datasetsSortNewest ? ICONS.sortDown : ICONS.sortUp}
          <span class="text-xs">${state.datasetsSortNewest ? T('datasets.sort_newest') : T('datasets.sort_oldest')}</span>
        </button>
      </div>`

      const rows = pageItems.length
        ? pageItems
            .map(
              (d) =>
                `<div class="tx-row p-4 flex items-center justify-between gap-3">
                  <button type="button" data-open-dataset="${d.id}" class="min-w-0 flex-1 text-left bg-transparent border-0 p-0 cursor-pointer" style="color:inherit;font:inherit">
                    <div class="font-medium truncate">${escHtml(datasetDisplayName(d))}</div>
                    <div class="text-xs tx-secondary mt-0.5">${formatDate(d.created_at)}</div>
                  </button>
                  <div class="flex items-center gap-3">
                    ${statusBadge(d.status || 'draft')}
                    <span class="text-xs ${datasetHasDoc(d) ? 'text-green-600 dark:text-green-400' : 'tx-secondary'}">${datasetHasDoc(d) ? T('datasets.doc_uploaded') : T('datasets.doc_missing')}</span>
                    <button data-delete-dataset="${d.id}" class="p-1.5 text-gray-400 hover:text-red-500 rounded transition-colors" title="${T('app.delete')}">${ICONS.trash}</button>
                  </div>
                </div>`
            )
            .join('')
        : `<p class="text-sm tx-secondary py-6 text-center">${T('datasets.no_results')}</p>`

      return `<div class="space-y-4">
        ${header}
        ${overdueBanner}
        ${toolbar}
        <div class="space-y-2">${rows}</div>
        ${totalPages > 1 ? renderPagination(state.datasetsPage, totalPages, filtered.length) : ''}
      </div>`
    }

    function renderPagination(current, totalPages, totalItems) {
      const from = current * DATASETS_PER_PAGE + 1
      const to = Math.min((current + 1) * DATASETS_PER_PAGE, totalItems)
      const isFirst = current === 0
      const isLast = current >= totalPages - 1
      const pages = []
      if (totalPages <= 7) {
        for (let i = 0; i < totalPages; i++) pages.push(i)
      } else {
        pages.push(0)
        if (current > 2) pages.push(-1)
        const lo = Math.max(1, current - 1)
        const hi = Math.min(totalPages - 2, current + 1)
        for (let i = lo; i <= hi; i++) pages.push(i)
        if (current < totalPages - 3) pages.push(-1)
        pages.push(totalPages - 1)
      }
      const btnBase = 'inline-flex items-center justify-center rounded transition-colors'
      const btnStyle = 'min-width:2rem;height:2rem;font-size:.8125rem;'
      const pageBtns = pages
        .map((p) => {
          if (p === -1) return `<span class="px-1 tx-secondary" style="font-size:.8125rem">…</span>`
          const active = p === current
          const bg = active ? 'background:var(--brand);color:#fff;' : 'color:var(--txt-primary);'
          return `<button data-page="${p}" class="${btnBase}${active ? ' font-semibold' : ''}" style="${btnStyle}${bg}">${p + 1}</button>`
        })
        .join('')
      return `<div class="flex items-center justify-between pt-2" style="border-top:1px solid var(--divider)">
        <span class="text-xs tx-secondary">${from}–${to} / ${totalItems}</span>
        <div class="flex items-center gap-1">
          <button data-page-prev class="${btnBase}" style="${btnStyle}${isFirst ? 'opacity:.35;pointer-events:none;' : ''}color:var(--txt-primary);">${ICONS.back}</button>
          ${pageBtns}
          <button data-page-next class="${btnBase}" style="${btnStyle}${isLast ? 'opacity:.35;pointer-events:none;' : ''}color:var(--txt-primary);transform:scaleX(-1);">${ICONS.back}</button>
        </div>
      </div>`
    }

    function renderNewDataset(c) {
      return `<div class="space-y-4">
        <button data-action="datasets-back" class="flex items-center gap-1 text-sm transition-colors" style="color:var(--txt-secondary)">${ICONS.back} ${T('app.back')}</button>
        <div class="tx-card p-6 max-w-lg">
          <h3 class="text-lg font-semibold mb-4">${T('datasets.new')}</h3>
          <form id="tx-new-dataset-form" class="space-y-4">
            <div>
              <label class="tx-label">${T('app.name')}</label>
              <input name="name" class="tx-input" placeholder="${T('datasets.name_placeholder')}" required autofocus />
            </div>
            <p class="text-xs tx-secondary">${T('datasets.new_hint')}</p>
            <button type="submit" class="tx-btn">${T('datasets.create_and_open')}</button>
          </form>
        </div>
      </div>`
    }

    // ---------------------------------------------------------------------
    // GDPR / data retention helpers
    // ---------------------------------------------------------------------

    const RETENTION_MONTHS_OPTIONS = [0, 3, 6, 9, 12, 18]

    function datasetExpiresAt(d) {
      // Backend already returns a computed `expires_at`. We re-derive it on
      // the client for legacy entries (or in case the timestamp is stale)
      // so the UI stays accurate even before the next API roundtrip.
      const months = parseInt(d?.delete_after_months, 10)
      if (!months || months <= 0) return null
      const base = new Date(d.updated_at || d.created_at || Date.now())
      if (Number.isNaN(base.getTime())) return null
      const out = new Date(base)
      out.setMonth(out.getMonth() + months)
      return out
    }

    function datasetIsOverdue(d) {
      const exp = datasetExpiresAt(d)
      if (!exp) return false
      return exp.getTime() < Date.now()
    }

    function renderRetentionPicker(d) {
      const cur = parseInt(d?.delete_after_months, 10) || 0
      const opts = RETENTION_MONTHS_OPTIONS.map((m) => {
        const label =
          m === 0 ? T('datasets.retention_never') : Tf('datasets.retention_months', { months: m })
        return `<option value="${m}"${m === cur ? ' selected' : ''}>${escHtml(label)}</option>`
      }).join('')
      return `<label class="flex items-center gap-1.5 text-xs tx-secondary" title="${escHtml(T('datasets.retention_hint'))}">
        ${ICONS.clock || ICONS.calendar || ''}
        <span>${T('datasets.retention_label')}:</span>
        <select data-action="set-retention" class="tx-input" style="height:30px;padding:0 0.4rem;font-size:0.75rem">${opts}</select>
      </label>`
    }

    function renderExpiryHint(d) {
      const exp = datasetExpiresAt(d)
      if (!exp) return ''
      const overdue = exp.getTime() < Date.now()
      const formatted = exp.toISOString().slice(0, 10)
      const cls = overdue
        ? 'color:var(--status-error);font-weight:500'
        : 'color:var(--txt-secondary)'
      const text = overdue
        ? Tf('datasets.retention_expired_at', { date: formatted })
        : Tf('datasets.retention_expires_at', { date: formatted })
      return ` · <span style="${cls}">${escHtml(text)}</span>`
    }

    function renderOverdueBanner(overdueDatasets) {
      if (!overdueDatasets.length) return ''
      const count = overdueDatasets.length
      return `<div class="tx-card p-4" style="background:rgba(220,38,38,0.08);border-color:var(--status-error)">
        <div class="flex items-start justify-between gap-3 flex-wrap">
          <div class="flex-1 min-w-0">
            <div class="font-semibold" style="color:var(--status-error)">
              ${ICONS.warning} ${Tf('datasets.retention_overdue_title', { count })}
            </div>
            <p class="text-sm tx-secondary mt-1">${T('datasets.retention_overdue_hint')}</p>
            <ul class="text-xs tx-secondary mt-2 space-y-0.5">
              ${overdueDatasets
                .slice(0, 5)
                .map(
                  (d) =>
                    `<li>${escHtml(datasetDisplayName(d))} — ${escHtml(formatDate(d.updated_at || d.created_at))}</li>`
                )
                .join('')}
              ${overdueDatasets.length > 5 ? `<li>… ${overdueDatasets.length - 5} more</li>` : ''}
            </ul>
          </div>
          <div class="flex items-center gap-2">
            <button data-action="retention-dismiss" class="tx-btn tx-btn-sm tx-btn-ghost">${T('app.cancel')}</button>
            <button data-action="retention-delete-overdue" class="tx-btn tx-btn-sm" style="background:var(--status-error);color:white">${ICONS.trash} ${T('datasets.retention_delete_now')}</button>
          </div>
        </div>
      </div>`
    }

    function renderDatasetDetail(c) {
      const d = state.selectedDataset

      const retention = renderRetentionPicker(d)
      const expiryHint = renderExpiryHint(d)
      // Header keeps the auto-delete dropdown, status badge and the
      // delete button. The "Show preview" trigger was removed — it was
      // confusing more than it helped.
      const header = `<div class="tx-card p-5">
        <div class="flex items-center justify-between gap-3 flex-wrap">
          <div class="min-w-0 flex-1">
            <h3 class="text-lg font-semibold truncate">${escHtml(datasetDisplayName(d))}</h3>
            <div class="text-xs tx-secondary mt-0.5">${T('app.created')}: ${formatDate(d.created_at)}${expiryHint}</div>
          </div>
          <div class="flex items-center gap-2 flex-wrap">
            ${retention}
            ${statusBadge(d.status || 'draft')}
            <button data-delete-dataset="${d.id}" class="tx-btn tx-btn-sm tx-btn-ghost" style="color:var(--status-error)">${ICONS.trash}</button>
          </div>
        </div>
      </div>`

      // Explicit tabs, in user-flow order. We use `data-dataset-tab`
      // (NOT `data-tab`) so clicks don't bubble up to the global
      // collection-level tab handler, which would otherwise hijack the
      // navigation away from the dataset detail page.
      const currentTab = state.datasetTab || 'sources'
      // Numbered labels mirror the wizard pattern so the dataset
      // flow has the same visual rhythm: each phase has an explicit
      // step number the user can orient against.
      const tabs = [
        { id: 'sources', label: `1. ${T('datasets.tab_sources')}` },
        { id: 'details', label: `2. ${T('datasets.tab_details')}` },
        { id: 'generate', label: `3. ${T('datasets.tab_generate')}` },
      ]
      const tabNav = `<nav class="flex gap-1 overflow-x-auto" style="border-bottom:1px solid var(--divider)">
        ${tabs
          .map(
            (t) =>
              `<button type="button" data-dataset-tab="${t.id}" class="tx-tab${currentTab === t.id ? ' active' : ''}">${escHtml(t.label)}</button>`
          )
          .join('')}
      </nav>`

      let tabBody = ''
      switch (currentTab) {
        case 'details':
          tabBody = renderDatasetDataSection(c, d)
          break
        case 'generate':
          tabBody = renderDatasetGenerateSection(c, d)
          break
        case 'sources':
        default:
          tabBody = `<div class="space-y-4">
            ${renderDatasetFilesSection(d)}
            ${renderDatasetExtractionSection(d)}
          </div>`
      }

      return `<div class="space-y-4">
        <button data-action="datasets-back" class="flex items-center gap-1 text-sm transition-colors" style="color:var(--txt-secondary)">${ICONS.back} ${T('app.back')}</button>
        ${header}
        ${tabNav}
        ${tabBody}
      </div>`
    }

    function renderDatasetPreviewPanel(c, d, templates) {
      const currentId =
        state.previewTemplateId && templates.some((t) => t.id === state.previewTemplateId)
          ? state.previewTemplateId
          : templates[0].id

      const options = templates
        .map(
          (t) =>
            `<option value="${escHtml(t.id)}"${t.id === currentId ? ' selected' : ''}>${escHtml(t.name || t.id)}</option>`
        )
        .join('')

      const toolbar = `<div class="tx-preview-toolbar">
        <span class="text-xs uppercase tracking-wider tx-secondary" style="letter-spacing:.04em">${T('preview.title')}</span>
        <select id="tx-preview-template-picker" class="tx-select" style="max-width:220px;padding:.25rem .5rem;font-size:.8125rem">${options}</select>
        <button data-action="preview-refresh" class="tx-btn tx-btn-sm tx-btn-ghost" title="${T('preview.refresh')}">${ICONS.refresh}</button>
        <button data-action="preview-render-pdf" class="tx-btn tx-btn-sm tx-btn-ghost" title="${T('preview.render_pdf_hint')}"${state.previewPdfLoading ? ' disabled' : ''}>
          ${state.previewPdfLoading ? `<span class="animate-spin inline-block w-3.5 h-3.5 border-2 border-current border-t-transparent rounded-full"></span>` : ICONS.sparkle}
          ${T('preview.render_pdf')}
        </button>
        <button data-action="preview-hide" class="tx-btn tx-btn-sm tx-btn-ghost ml-auto" title="${T('preview.hide')}">${ICONS.close}</button>
      </div>`

      let body = ''
      if (state.previewPdfUrl) {
        body = `<div class="tx-preview-viewport" style="padding:0;background:#f6f6f6;display:flex;flex-direction:column">
          <div class="flex items-center gap-2 p-2" style="border-bottom:1px solid var(--divider);background:var(--bg-card);flex:0 0 auto">
            <span class="text-xs tx-secondary">${T('preview.showing_pdf')}</span>
            <button data-action="preview-back-html" class="tx-btn tx-btn-sm tx-btn-ghost ml-auto">${T('preview.back_to_html')}</button>
          </div>
          <iframe src="${escHtml(state.previewPdfUrl)}" style="width:100%;flex:1 1 auto;min-height:60vh;border:0"></iframe>
        </div>`
      } else if (state.previewLoading) {
        body = `<div class="tx-preview-missing">
          <span class="animate-spin inline-block w-5 h-5 border-2 border-current border-t-transparent rounded-full"></span>
          <p class="mt-2">${T('preview.loading')}</p>
        </div>`
      } else if (state.previewError) {
        body = `<div class="tx-preview-missing"><p>${escHtml(state.previewError)}</p></div>`
      } else if (state.previewSkeleton && state.previewSkeleton.html) {
        const banner = state.previewPdfUnavailable
          ? `<div class="tx-callout m-3" style="font-size:.75rem">${T('preview.pdf_unavailable')}</div>`
          : ''
        body = `<div class="tx-preview-viewport">
          ${banner}
          <div id="tx-preview-root" data-template-id="${escHtml(currentId)}">${state.previewSkeleton.html}</div>
        </div>`
      } else {
        body = `<div class="tx-preview-missing"><p>${T('preview.loading')}</p></div>`
      }

      return toolbar + body
    }

    function renderDatasetDataSection(c, d) {
      const formData = d.field_values || {}
      const fields = c.fields || []
      const isReordering = state.reorderingFields
      let inner = ''
      if (fields.length === 0) {
        inner = `<p class="text-sm tx-secondary">${T('app.no_data')}</p>`
      } else if (isReordering) {
        inner = `<div id="tx-entry-data-form" class="grid grid-cols-1 sm:grid-cols-2 gap-2">${fields
          .map(
            (
              f,
              idx
            ) => `<div class="flex items-start gap-1.5 group rounded-lg p-2" style="border:1px dashed var(--divider);background:var(--bg-secondary)" data-field-idx="${idx}">
              <div class="flex flex-col gap-0.5 mt-1 shrink-0">
                <button type="button" data-action="df-up" data-idx="${idx}" class="p-0.5" style="color:var(--txt-secondary)${idx === 0 ? ';opacity:0.3' : ''}" ${idx === 0 ? 'disabled' : ''}>${ICONS.arrowUp}</button>
                <button type="button" data-action="df-down" data-idx="${idx}" class="p-0.5" style="color:var(--txt-secondary)${idx === fields.length - 1 ? ';opacity:0.3' : ''}" ${idx === fields.length - 1 ? 'disabled' : ''}>${ICONS.arrowDown}</button>
              </div>
              <div class="flex-1 min-w-0">
                <span class="text-sm font-medium">${escHtml(f.label || f.key)}</span>
                <span class="text-xs tx-secondary ml-1">(${escHtml(T(`variables.type_${f.type || 'text'}`, f.type || 'text'))})</span>
              </div>
              <span class="shrink-0 mt-1" style="color:var(--txt-secondary)" title="${T('variables.drag_to_reorder')}">${ICONS.grip}</span>
            </div>`
          )
          .join('')}</div>`
      } else {
        inner = `<form id="tx-entry-data-form" class="grid grid-cols-1 sm:grid-cols-2 gap-3">
          ${fields.map((f) => renderFormFieldWithValue(f, formData[f.key])).join('')}
          <div class="tx-sticky-save" style="grid-column:1 / -1"><button type="submit" class="tx-btn tx-btn-sm">${T('app.save')}</button></div>
        </form>`
      }
      return `<div class="tx-card p-5">
        <div class="flex items-center gap-2 mb-1">
          <h4 class="text-sm font-semibold flex items-center gap-2">${ICONS.variable} ${T('datasets.section_data')}</h4>
          ${
            fields.length > 1
              ? `<button data-action="toggle-reorder" class="ml-auto text-xs tx-link flex items-center gap-1">${ICONS.grip} ${isReordering ? T('app.done') : T('variables.reorder')}</button>`
              : ''
          }
        </div>
        <p class="text-xs tx-secondary mb-3">${T('datasets.section_data_hint')}</p>
        ${inner}
      </div>`
    }

    function renderFormFieldWithValue(field, value) {
      const fid = `tx-field-${escHtml(field.key)}`
      const req = field.required ? 'required' : ''
      const reqMark = field.required ? ' <span class="text-red-500">*</span>' : ''
      // Help text shows as a mouseover tooltip on a "?" icon next to the
      // label (customer feedback) instead of a permanently visible line.
      const hint = field.hint ? ` ${tooltipIcon(field.hint)}` : ''
      const label = `<label for="${fid}" class="tx-label">${escHtml(field.label || field.key)}${reqMark}${hint}</label>`
      // Wide fields span the FULL grid width. We use an inline grid-column
      // style instead of Tailwind's `sm:col-span-2`: the host purges utility
      // classes it doesn't use itself, and the plugin's runtime-injected markup
      // isn't scanned — so `sm:col-span-2` silently no-ops and tables get
      // squeezed into a single column. `grid-column:1 / -1` always spans every
      // column at any breakpoint (including the 1-column mobile layout).
      const wideTypes = ['textarea', 'list', 'table', 'image']
      const spanAttr = wideTypes.includes(field.type) ? ' style="grid-column:1 / -1"' : ''

      if (field.type === 'checkbox') {
        return `<div${spanAttr}>
          <label class="flex items-center gap-2 cursor-pointer">
            <input type="checkbox" id="${fid}" name="${escHtml(field.key)}" ${value === true || value === 'true' || value === 'Ja' ? 'checked' : ''} class="rounded h-4 w-4" style="accent-color:var(--brand)" />
            <span class="text-sm font-medium">${escHtml(field.label || field.key)}${reqMark}</span>${hint}
          </label>
        </div>`
      }

      if (field.type === 'image') {
        const meta = value && typeof value === 'object' ? value : null
        const d = state.selectedDataset
        const thumbUrl =
          meta && d
            ? `${BASE}/candidates/${d.id}/image/${encodeURIComponent(field.key)}?v=${encodeURIComponent(meta.uploaded_at || '')}`
            : null
        const hasImage = !!thumbUrl
        return `<div${spanAttr}>
          ${label}
          <div class="flex items-start gap-3 p-3 rounded" style="background:var(--bg-app);border:1px dashed var(--divider)">
            <div style="width:96px;height:120px;flex:none;background:var(--bg-card);border-radius:.375rem;overflow:hidden;display:flex;align-items:center;justify-content:center;color:var(--txt-secondary)">
              ${hasImage ? `<img src="${escHtml(thumbUrl)}" alt="${escHtml(field.label || field.key)}" style="width:100%;height:100%;object-fit:cover" />` : ICONS.doc}
            </div>
            <div class="flex-1 min-w-0 space-y-2">
              <p class="text-sm tx-secondary">${hasImage ? T('datasets.image_uploaded') : T('datasets.image_empty')}${hasImage && meta?.auto_extracted ? ` <span class="tx-badge" style="background:var(--bg-chip);color:var(--txt-secondary)">${T('datasets.image_from_cv')}</span>` : ''}</p>
              ${hasImage && meta?.original_name ? `<p class="text-xs tx-secondary truncate">${escHtml(meta.original_name)}</p>` : ''}
              <div class="flex items-center gap-2">
                <label class="tx-btn tx-btn-sm tx-btn-ghost cursor-pointer">
                  ${ICONS.upload} ${hasImage ? T('datasets.image_replace') : T('datasets.image_upload')}
                  <input type="file" data-action="image-upload" data-key="${escHtml(field.key)}" accept="image/jpeg,image/png,image/gif,image/webp,image/bmp" class="hidden" />
                </label>
                ${hasImage ? `<button type="button" data-action="image-remove" data-key="${escHtml(field.key)}" class="tx-btn tx-btn-sm tx-btn-ghost" style="color:var(--status-error)">${ICONS.trash} ${T('app.delete')}</button>` : ''}
              </div>
            </div>
          </div>
        </div>`
      }

      let input
      switch (field.type) {
        case 'textarea':
          input = `<textarea id="${fid}" name="${escHtml(field.key)}" class="tx-textarea" rows="3" ${req}>${escHtml(String(value ?? ''))}</textarea>`
          break
        case 'select': {
          const opts = (field.options || [])
            .map((o) => {
              const v = typeof o === 'object' ? o.value : o
              const l = typeof o === 'object' ? o.label || o.value : o
              const sel = String(value) === String(v) ? ' selected' : ''
              return `<option value="${escHtml(v)}"${sel}>${escHtml(l)}</option>`
            })
            .join('')
          input = `<select id="${fid}" name="${escHtml(field.key)}" class="tx-select" ${req}><option value="">—</option>${opts}</select>`
          break
        }
        case 'list': {
          const lv = Array.isArray(value) ? value.join('\n') : String(value ?? '')
          input = `<textarea id="${fid}" name="${escHtml(field.key)}" class="tx-textarea" rows="3" placeholder="${T('variables.list_placeholder')}" ${req}>${escHtml(lv)}</textarea>`
          break
        }
        case 'date':
          input = `<input type="date" id="${fid}" name="${escHtml(field.key)}" value="${escHtml(String(value ?? ''))}" class="tx-input" ${req} />`
          break
        case 'number':
          input = `<input type="number" id="${fid}" name="${escHtml(field.key)}" value="${escHtml(String(value ?? ''))}" class="tx-input" ${req} />`
          break
        case 'table': {
          const cols = field.columns || []
          const rows = Array.isArray(value) ? value : []
          // WS-G (feedback #7): opt-in per-row "relevant" checkbox. Enabled
          // for a table with designer flag `selectable`, or by convention for
          // the career `stations` table. Selected rows drive the derived
          // {{relevant_positions}} list rendered on page 1.
          const selectable = field.selectable === true || field.key === 'stations'

          // Weighted column widths — list/textarea cells get the bulk of
          // the row so multi-line content (HR profile tasks, notes) is
          // readable. The compact text/date/number columns shrink.
          //   list/textarea : weight 5 (≈ 60-66% with 1 wide + 2 narrow)
          //   text          : weight 1.4
          //   date / number : weight 1
          // 8% is reserved for the trash-icon column.
          const weightOf = (c) => {
            const t = c.type || 'text'
            if (t === 'list' || t === 'textarea') return 5
            if (t === 'date' || t === 'number') return 1
            return 1.4
          }
          const totalWeight = cols.reduce((s, c) => s + weightOf(c), 0) || 1
          const colWidths = cols.map((c) => `${((92 * weightOf(c)) / totalWeight).toFixed(2)}%`)
          const colgroup = `<colgroup>${selectable ? '<col style="width:3rem">' : ''}${colWidths
            .map((w) => `<col style="width:${w}">`)
            .join('')}<col style="width:4.75rem"></colgroup>`

          const selTh = selectable
            ? `<th class="py-1.5 px-1 text-xs tx-secondary font-medium text-center" title="${escHtml(T('datasets.col_relevant_hint'))}">${T('datasets.col_relevant')}</th>`
            : ''
          const hc = cols
            .map(
              (c) =>
                `<th class="py-1.5 px-2 text-xs tx-secondary font-medium">${escHtml(c.label || c.key)}</th>`
            )
            .join('')
          const dr = rows
            .map((row, ri) => {
              const cells = cols
                .map((c) => {
                  const colType = c.type || 'text'
                  const rawVal = row[c.key]
                  // List-typed columns store arrays; display as one-per-line
                  // inside a textarea. On save the harvest layer splits back.
                  if (colType === 'list') {
                    const asArr = Array.isArray(rawVal)
                      ? rawVal
                      : String(rawVal ?? '')
                          .split(/\r?\n/)
                          .filter((s) => s.trim() !== '')
                    const txt = asArr.join('\n')
                    const rows = Math.max(4, asArr.length)
                    return `<td class="py-1.5 px-1.5" style="vertical-align:top"><textarea name="${escHtml(field.key)}__${ri}__${escHtml(c.key)}" rows="${rows}" class="tx-textarea text-sm" style="width:100%;display:block;box-sizing:border-box;padding:.4rem .55rem;min-height:5.25rem;resize:vertical;line-height:1.4" placeholder="${T('datasets.table_list_placeholder')}">${escHtml(txt)}</textarea></td>`
                  }
                  if (colType === 'textarea') {
                    return `<td class="py-1.5 px-1.5" style="vertical-align:top"><textarea name="${escHtml(field.key)}__${ri}__${escHtml(c.key)}" rows="3" class="tx-textarea text-sm" style="width:100%;display:block;box-sizing:border-box;padding:.4rem .55rem;min-height:4.5rem;resize:vertical;line-height:1.4">${escHtml(String(rawVal ?? ''))}</textarea></td>`
                  }
                  const inputType =
                    colType === 'number' ? 'number' : colType === 'date' ? 'date' : 'text'
                  return `<td class="py-1.5 px-1.5" style="vertical-align:top"><input type="${inputType}" name="${escHtml(field.key)}__${ri}__${escHtml(c.key)}" value="${escHtml(String(rawVal ?? ''))}" class="tx-input text-sm" style="width:100%;display:block;box-sizing:border-box;padding:.4rem .55rem" /></td>`
                })
                .join('')
              // Row actions: move up / move down (customer feedback —
              // extracted table rows, e.g. career stations, must be
              // re-orderable by hand) plus remove.
              const rowActions = `<div class="flex items-center justify-center gap-0.5" style="padding-top:.55rem">
                <span data-drag-handle class="tx-drag-handle p-0.5" style="color:var(--txt-secondary)" title="${T('variables.drag_to_reorder')}">${ICONS.grip}</span>
                <button type="button" data-action="move-table-row" data-dir="-1" data-field-key="${escHtml(field.key)}" data-row-idx="${ri}" class="p-0.5" style="color:var(--txt-secondary);${ri === 0 ? 'opacity:.25;pointer-events:none' : ''}" title="${T('datasets.row_move_up')}">${ICONS.arrowUp}</button>
                <button type="button" data-action="move-table-row" data-dir="1" data-field-key="${escHtml(field.key)}" data-row-idx="${ri}" class="p-0.5" style="color:var(--txt-secondary);${ri === rows.length - 1 ? 'opacity:.25;pointer-events:none' : ''}" title="${T('datasets.row_move_down')}">${ICONS.arrowDown}</button>
                <button type="button" data-action="remove-table-row" data-field-key="${escHtml(field.key)}" data-row-idx="${ri}" class="p-0.5" style="color:var(--txt-secondary)">${ICONS.trash}</button>
              </div>`
              const selTd = selectable
                ? `<td class="py-1.5 px-1 text-center" style="vertical-align:top;padding-top:.6rem"><input type="checkbox" name="${escHtml(field.key)}__${ri}__selected"${row.selected ? ' checked' : ''} title="${escHtml(T('datasets.col_relevant_hint'))}" /></td>`
                : ''
              return `<tr class="tx-divider border-t" data-field-key="${escHtml(field.key)}" data-row-idx="${ri}">${selTd}${cells}<td class="py-1.5 px-1 text-center" style="vertical-align:top">${rowActions}</td></tr>`
            })
            .join('')
          const empty =
            rows.length === 0
              ? `<tr><td colspan="${cols.length + 1 + (selectable ? 1 : 0)}" class="py-3 text-center text-xs tx-secondary">${T('datasets.table_empty')}</td></tr>`
              : ''
          // table-layout:fixed makes the colgroup widths authoritative —
          // without it browsers fall back to content-based sizing and
          // the list column gets squeezed by the inputs to its left.
          input = `<div class="rounded" style="border:1px solid var(--divider);overflow:hidden">
            <table class="w-full text-left" style="table-layout:fixed;width:100%">
              ${colgroup}
              <thead><tr class="tx-divider border-b">${selTh}${hc}<th class="py-1.5 px-2"></th></tr></thead>
              <tbody>${dr}${empty}</tbody>
            </table>
          </div>
          <button type="button" data-action="add-table-row" data-field-key="${escHtml(field.key)}" class="mt-1.5 tx-link text-xs flex items-center gap-1">${ICONS.plus} ${T('datasets.add_row')}</button>
          <input type="hidden" name="${escHtml(field.key)}__table_marker" value="1" />`
          break
        }
        default:
          input = `<input type="text" id="${fid}" name="${escHtml(field.key)}" value="${escHtml(String(value ?? ''))}" class="tx-input" ${req} />`
      }
      return `<div${spanAttr}>${label}${input}</div>`
    }

    function renderDatasetFilesSection(d) {
      const cv = d.files?.cv
      const additional = d.files?.additional || []
      const urls = d.files?.urls || []
      const all = []
      if (cv) all.push({ ...cv, slot: 'cv', slotIndex: 0 })
      for (let i = 0; i < additional.length; i++)
        all.push({ ...additional[i], slot: 'additional', slotIndex: i })

      const urlRows = urls
        .map((u, i) => {
          const ok = u.text_snippet && !u.fetch_error
          const warning = u.fetch_error && !u.text_snippet
          const statusIcon = ok
            ? `<span style="color:var(--status-success)" title="${T('datasets.url_fetched')}">${ICONS.check}</span>`
            : warning
              ? `<span style="color:var(--status-warning,#d97706)" title="${escHtml(u.fetch_error || '')}">${ICONS.warning}</span>`
              : `<span style="color:var(--status-warning,#d97706)" title="${escHtml(u.fetch_error || '')}">${ICONS.warning}</span>`
          const kindBadge = u.kind
            ? `<span class="tx-badge" style="background:var(--bg-chip);color:var(--txt-secondary)">${escHtml(u.kind)}</span>`
            : ''
          // Render `fetch_error` as an inline subtitle (not just a
          // hover tooltip) so users with hosts like LinkedIn that
          // structurally block server fetches actually see the
          // guidance and don't sit retrying.
          const errorRow =
            u.fetch_error && !u.text_snippet
              ? `<div class="text-xs pl-6 pb-1.5" style="color:var(--status-warning,#d97706);line-height:1.35">${escHtml(u.fetch_error)}</div>`
              : ''
          return `<div class="group">
            <div class="flex items-center gap-2 py-1.5">
              ${statusIcon}
              <a href="${escHtml(u.url)}" target="_blank" rel="noopener noreferrer" class="text-xs flex-1 truncate tx-link" title="${escHtml(u.url)}">${escHtml(u.label || u.host || u.url)}</a>
              ${kindBadge}
              ${u.text_snippet ? `<span class="text-xs tx-secondary">${u.text_snippet.length.toLocaleString()} chars</span>` : ''}
              <button data-action="refresh-url" data-url-index="${i}" class="p-1 rounded opacity-0 group-hover:opacity-100" style="color:var(--txt-secondary)" title="${T('datasets.url_refetch')}">${ICONS.refresh || ICONS.sparkle}</button>
              <button data-action="delete-source-file" data-slot="urls" data-slot-index="${i}" class="p-1 rounded opacity-0 group-hover:opacity-100" style="color:var(--txt-secondary)">${ICONS.trash}</button>
            </div>
            ${errorRow}
          </div>`
        })
        .join('')

      // Per-file extraction status (v4.0+). Before the first scan we show a
      // neutral "not scanned yet" chip — a green check here used to imply
      // success even though nothing had been read.
      const strategyLabel = (strat) => {
        const s = String(strat || '')
        if (!s) return ''
        if (s === 'ocr_cache') return T('datasets.file_strategy_cache')
        if (s.includes('vision') || s.includes('rasterize'))
          return T('datasets.file_strategy_vision')
        if (s === 'tika') return T('datasets.file_strategy_tika')
        if (s === 'error' || s === 'empty') return T('datasets.file_strategy_failed')
        return s
      }
      const fileStatusIcon = (f) => {
        const ex = f.extraction
        if (!ex) {
          return `<span style="color:var(--txt-secondary)" title="${T('datasets.file_pending_hint')}">${ICONS.file}</span>`
        }
        if (!ex.ok) {
          return `<span style="color:var(--status-error,#dc2626)" title="${escHtml(ex.error || T('datasets.file_unreadable_hint'))}">${ICONS.warning}</span>`
        }
        const strat = String(ex.strategy || '')
        const isVision = strat.includes('vision') || strat.includes('rasterize')
        const color = isVision ? 'var(--status-warning,#d97706)' : 'var(--status-success)'
        return `<span style="color:${color}" title="${escHtml(strategyLabel(strat) + ' · ' + (ex.chars || 0) + ' chars')}">${ICONS.check}</span>`
      }
      const fileMeta = (f) => {
        const ex = f.extraction
        if (!ex) {
          return `<span class="text-xs tx-secondary">${T('datasets.file_pending')}</span>`
        }
        if (!ex.ok) {
          const reason = ex.error ? ` — ${escHtml(ex.error)}` : ''
          return `<span class="text-xs" style="color:var(--status-error,#dc2626)">${T('datasets.file_unreadable')}${reason}</span>`
        }
        const strat = strategyLabel(ex.strategy)
        const stratBit = strat ? `<span class="tx-secondary"> · ${escHtml(strat)}</span>` : ''
        return `<span class="text-xs tx-secondary">${Tf('datasets.file_chars', { count: (ex.chars || 0).toLocaleString() })}${stratBit}</span>`
      }
      const list =
        all.length || urls.length
          ? all
              .map(
                (f) =>
                  `<div class="flex items-center gap-2 py-1.5 group">
                  ${fileStatusIcon(f)}
                  <button type="button" data-action="download-source-file" data-slot="${escHtml(f.slot)}" data-slot-index="${f.slotIndex}" class="text-xs flex-1 truncate text-left tx-link" style="background:none;border:none;padding:0" title="${T('datasets.download_source_hint')}">${escHtml(f.filename)}</button>
                  ${fileMeta(f)}
                  <span class="text-xs tx-secondary">${f.size ? (f.size / 1024 > 1024 ? (f.size / 1048576).toFixed(1) + ' MB' : Math.round(f.size / 1024) + ' KB') : ''}</span>
                  <button data-action="download-source-file" data-slot="${escHtml(f.slot)}" data-slot-index="${f.slotIndex}" class="p-1 rounded opacity-0 group-hover:opacity-100" style="color:var(--txt-secondary)" title="${T('datasets.download_source_hint')}">${ICONS.download}</button>
                  <button data-action="delete-source-file" data-slot="${escHtml(f.slot)}" data-slot-index="${f.slotIndex}" class="p-1 rounded opacity-0 group-hover:opacity-100" style="color:var(--txt-secondary)">${ICONS.trash}</button>
                </div>`
              )
              .join('') + urlRows
          : `<p class="text-xs tx-secondary py-2">${T('datasets.no_files')}</p>`

      let progress = ''
      if (state.datasetParsing) {
        const steps = [
          T('datasets.analyze_step_upload'),
          T('datasets.analyze_step_ocr'),
          T('datasets.analyze_step_ai'),
          T('datasets.analyze_step_done'),
        ]
        const step = state.datasetParseStep || 0
        const elapsed = state.datasetParseElapsedSec || 0
        const fileCount = state.datasetParseFileCount || 0
        const hasImages = !!state.datasetParseHasImages
        // Group-aware estimate: typical CV form has 5-7 groups × ~3 s
        // per group AI call ≈ 15-25 s. Image-heavy uploads add Vision
        // OCR rounds on top.
        const expectedSec = Math.max(14, (hasImages ? fileCount * 12 : 0) + 18)
        const timeBasedPct = Math.min(85, Math.round((elapsed / expectedSec) * 85))
        const pct = Math.max(15 + step * 25, timeBasedPct)
        const cappedPct = Math.min(95, pct)
        // Live, single-line feedback: walk the actual field labels while the
        // request is in flight (built in the parse-documents handler). Falls
        // back to the generic stage text on older state / empty forms.
        const msgs = state.datasetParseStatusMsgs || []
        const dynamicLine = msgs.length
          ? msgs[Math.min(state.datasetParseStatusIdx || 0, msgs.length - 1)]
          : step >= 2
            ? T('datasets.analyze_status_running_ai')
            : Tf('datasets.analyze_status_reading_files', { count: fileCount })
        const hintLine = hasImages
          ? T('datasets.analyze_status_image_hint')
          : T('datasets.analyze_status_doc_hint')
        progress = `<div class="mt-3 space-y-2">
          <div class="flex items-center justify-between gap-2 text-sm font-medium" style="color:var(--brand)">
            <div class="flex items-center gap-2">
              <div class="animate-spin rounded-full h-4 w-4 border-2 border-current border-t-transparent"></div>
              ${T('datasets.analyzing')}
            </div>
            <span class="text-xs font-mono tx-secondary" aria-live="polite">${Tf('datasets.analyze_status_elapsed', { seconds: elapsed })}</span>
          </div>
          <div class="w-full rounded-full h-2" style="background:var(--divider)">
            <div class="h-2 rounded-full transition-all duration-700" style="width:${cappedPct}%;background:var(--brand)"></div>
          </div>
          <div class="flex items-center gap-2 text-sm font-medium" style="color:var(--brand)" aria-live="polite">
            <span class="inline-block w-1.5 h-1.5 rounded-full animate-pulse" style="background:var(--brand)"></span>
            <span class="truncate">${escHtml(dynamicLine)}</span>
          </div>
          <div class="flex justify-between text-xs tx-secondary">
            ${steps.map((s, i) => `<span${i <= step ? ' style="color:var(--brand);font-weight:500"' : ''}>${s}</span>`).join('')}
          </div>
          <div class="text-xs tx-secondary" style="opacity:0.85">${escHtml(hintLine)}</div>
          <div class="text-xs" style="color:var(--status-warning,#d97706);opacity:0.9">${escHtml(T('datasets.analyze_dont_close'))}</div>
        </div>`
      }

      let uploadProgress = ''
      if (state.datasetUploading) {
        const pct = Math.max(0, Math.min(100, state.datasetUploadPercent || 0))
        const cur = state.datasetUploadCurrent || 1
        const tot = state.datasetUploadTotal || 1
        uploadProgress = `<div class="mt-3 space-y-2">
          <div class="flex items-center justify-between gap-2 text-sm font-medium" style="color:var(--brand)">
            <div class="flex items-center gap-2">
              <div class="animate-spin rounded-full h-4 w-4 border-2 border-current border-t-transparent"></div>
              ${escHtml(T('datasets.upload_in_progress_title'))}
            </div>
            <span class="text-xs font-mono tx-secondary" id="tx-upload-pct-label">${pct}% · ${escHtml(formatBytes(state.datasetUploadBytesSent))} / ${escHtml(formatBytes(state.datasetUploadBytesTotal))}</span>
          </div>
          <div class="w-full rounded-full h-2" style="background:var(--divider)">
            <div id="tx-upload-pct" class="h-2 rounded-full transition-all duration-200" style="width:${pct}%;background:var(--brand)"></div>
          </div>
          <div class="text-sm" style="color:var(--brand)">${escHtml(
            Tf('datasets.upload_in_progress', {
              current: cur,
              total: tot,
              name: state.datasetUploadFilename || '',
              size: formatBytes(state.datasetUploadBytesTotal),
            })
          )}</div>
          <div class="text-xs" style="color:var(--status-warning,#d97706)">${escHtml(T('datasets.analyze_dont_close'))}</div>
        </div>`
      }

      const hasAnySource = all.length > 0 || urls.length > 0
      const urlFormOpen = state.urlFormOpen
      const urlFormMarkup = urlFormOpen
        ? `<div class="mt-2 p-3 rounded tx-card space-y-2" style="background:var(--bg-app)">
            <label class="tx-label">${T('datasets.url_add_label')}</label>
            <input id="tx-url-input" type="url" class="tx-input" placeholder="${T('datasets.url_add_placeholder')}" />
            <input id="tx-url-label" type="text" class="tx-input" placeholder="${T('datasets.url_label_placeholder')}" />
            <div class="flex items-center gap-2">
              <button data-action="save-url" class="tx-btn tx-btn-sm"${state.urlAdding ? ' disabled' : ''}>
                ${state.urlAdding ? `<span class="animate-spin inline-block w-3 h-3 border-2 border-white border-t-transparent rounded-full"></span>` : ICONS.upload}
                ${state.urlAdding ? T('datasets.url_adding') : T('datasets.url_add_btn')}
              </button>
              <button data-action="cancel-url" class="tx-btn tx-btn-sm tx-btn-ghost">${T('app.cancel')}</button>
              ${state.urlAddError ? `<span class="text-xs" style="color:var(--status-error)">${escHtml(state.urlAddError)}</span>` : ''}
            </div>
            <p class="text-xs tx-secondary">${T('datasets.url_add_hint')}</p>
          </div>`
        : ''

      return `<div class="tx-card p-5" id="tx-source-card">
        <div class="flex items-center gap-2 mb-3">
          <h4 class="text-sm font-semibold uppercase tracking-wider tx-secondary flex items-center gap-2">${ICONS.file} ${T('datasets.section_files')}</h4>
          <span class="text-xs tx-secondary">— ${T('datasets.section_files_hint')}</span>
        </div>
        <div class="space-y-1">${list}</div>
        <div class="mt-3 flex items-center gap-2 flex-wrap">
          <label class="tx-btn tx-btn-sm tx-btn-ghost cursor-pointer${state.datasetUploading || state.datasetParsing ? ' opacity-50 pointer-events-none' : ''}">
            ${state.datasetUploading ? `<span class="animate-spin inline-block w-3 h-3 border-2 border-current border-t-transparent rounded-full"></span>` : ICONS.upload}
            ${all.length ? T('datasets.upload_more') : T('datasets.upload_doc')}
            <input type="file" id="tx-doc-upload" accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.gif,.webp,.tiff,.tif,.bmp,.heic,.heif,.txt,.rtf,.odt,.xls,.xlsx,.pptx,.eml,.msg" multiple class="hidden"${state.datasetUploading || state.datasetParsing ? ' disabled' : ''} />
          </label>
          <button data-action="toggle-url-form" class="tx-btn tx-btn-sm tx-btn-ghost"${state.datasetUploading || state.datasetParsing ? ' disabled' : ''}>
            ${ICONS.link || ICONS.sparkle} ${T('datasets.url_add_btn')}
          </button>
          ${
            hasAnySource && !state.datasetParsing && !state.datasetUploading
              ? `<button data-action="parse-documents" class="tx-btn tx-btn-sm">${ICONS.sparkle} ${T('datasets.parse_btn')}</button>
                 <button type="button" class="tx-help-trigger" title="${T('datasets.parse_hint')}">${ICONS.question}</button>`
              : ''
          }
        </div>
        ${urlFormMarkup}
        ${progress}
        ${uploadProgress}
      </div>`
    }

    // Status card for the AI extraction stage. Shown while a run is in
    // flight, after a completed/partial/failed run (`last_parse`), or when
    // the candidate status is already extracted/reviewed/generated.
    function renderDatasetExtractionSection(d) {
      const last = d.last_parse || null
      const isExtracted =
        d.status === 'extracted' || d.status === 'reviewed' || d.status === 'generated'
      if (!isExtracted && !state.datasetParsing && !last) {
        return ''
      }
      const info = d.extraction_info || d.ai_extracted || {}
      const modelName = (last && last.model) || info.model_used || ''
      const modelInfo = modelName
        ? ` · ${T('datasets.extract_model')}: <span class="font-mono text-xs">${escHtml(modelName)}</span>`
        : ''
      const coll = collectionById(d.form_id)
      const collFields = (coll && coll.fields) || []
      const values = d.field_values || {}
      const isEmptyVal = (v) =>
        v === null || v === undefined || v === '' || (Array.isArray(v) && v.length === 0)
      const missingCount = collFields.filter((f) => isEmptyVal(values[f.key])).length
      const hasSources = !!(
        d.files?.cv ||
        (d.files?.additional || []).length ||
        (d.files?.urls || []).length
      )
      const rerunBtn =
        missingCount > 0 && hasSources && !state.datasetParsing
          ? `<button type="button" data-action="parse-missing" class="tx-btn tx-btn-sm tx-btn-ghost" title="${T('datasets.parse_missing_hint')}">
              ${ICONS.sparkle} ${Tf('datasets.parse_missing_btn', { count: missingCount })}
            </button>`
          : ''

      let outcomeBlock = ''
      if (state.datasetParsing) {
        outcomeBlock = `<div class="flex items-center gap-2 text-sm" style="color:var(--brand)">
            <div class="animate-spin rounded-full h-4 w-4 border-b-2 border-current"></div>
            ${T('datasets.extract_running')}
          </div>`
      } else if (last) {
        const outcome = last.outcome || 'complete'
        const color =
          outcome === 'complete'
            ? 'var(--status-success)'
            : outcome === 'partial'
              ? 'var(--status-warning,#d97706)'
              : 'var(--status-error,#dc2626)'
        const title =
          outcome === 'complete'
            ? T('datasets.parse_outcome_complete')
            : outcome === 'partial'
              ? T('datasets.parse_outcome_partial')
              : T('datasets.parse_outcome_failed')
        const sourcesLine = Tf('datasets.parse_outcome_sources', {
          ok: last.sources_ok ?? 0,
          total: last.sources_total ?? 0,
        })
        const fieldsLine = Tf('datasets.parse_outcome_fields', {
          filled: last.fields_filled ?? 0,
          total: last.fields_total ?? 0,
        })
        const unread =
          Array.isArray(last.unreadable_files) && last.unreadable_files.length
            ? `<div class="text-xs mt-1" style="color:var(--status-error,#dc2626)">${Tf(
                'datasets.parse_unreadable_warning',
                {
                  files: last.unreadable_files.map((f) => f.filename || '?').join(', '),
                }
              )}</div>`
            : ''
        const errLine =
          last.error && outcome === 'failed'
            ? `<div class="text-xs mt-1" style="color:var(--status-error,#dc2626)">${escHtml(last.error)}</div>`
            : ''
        const retryHint =
          outcome !== 'complete'
            ? `<div class="text-xs mt-1 tx-secondary">${T('datasets.parse_outcome_retry_hint')}</div>`
            : ''
        outcomeBlock = `<div class="space-y-1">
            <div class="flex items-center justify-between gap-3 flex-wrap">
              <div class="flex items-center gap-2 text-sm" style="color:${color}">
                ${outcome === 'complete' ? ICONS.check : ICONS.warning} ${title}${modelInfo}
              </div>
              <div class="flex items-center gap-2">
                ${rerunBtn}
                ${
                  outcome !== 'failed'
                    ? `<button type="button" data-dataset-tab="details" class="tx-btn tx-btn-sm">
                  ${T('datasets.goto_review')} →
                </button>`
                    : hasSources
                      ? `<button type="button" data-action="parse-documents" class="tx-btn tx-btn-sm">${ICONS.sparkle} ${T('datasets.parse_btn')}</button>`
                      : ''
                }
              </div>
            </div>
            <div class="text-xs tx-secondary">${sourcesLine} · ${fieldsLine}</div>
            ${unread}
            ${errLine}
            ${retryHint}
          </div>`
      } else {
        outcomeBlock = `<div class="flex items-center justify-between gap-3 flex-wrap">
            <div class="flex items-center gap-2 text-sm" style="color:var(--status-success)">
              ${ICONS.check} ${T('datasets.extract_done')}${modelInfo}
            </div>
            <div class="flex items-center gap-2">
              ${rerunBtn}
              <button type="button" data-dataset-tab="details" class="tx-btn tx-btn-sm">
                ${T('datasets.goto_review')} →
              </button>
            </div>
          </div>`
      }
      return `<div class="tx-card p-5">
        <div class="flex items-center gap-2 mb-3">
          <h4 class="text-sm font-semibold uppercase tracking-wider tx-secondary flex items-center gap-2">${ICONS.sparkle} ${T('datasets.section_extraction')}</h4>
          <span class="text-xs tx-secondary">— ${T('datasets.section_extraction_hint')}</span>
        </div>
        ${outcomeBlock}
      </div>`
    }

    function renderDatasetGenerateSection(c, d) {
      const hasVars =
        state.datasetVariables &&
        state.datasetVariables.filter((v) => v.type !== 'table').length > 0
      const tpls = collectionTemplates(c)
      if (!hasVars) {
        return `<div class="tx-card p-5">
          <h4 class="text-sm font-semibold uppercase tracking-wider tx-secondary flex items-center gap-2 mb-3">${ICONS.doc} ${T('datasets.section_generate')}</h4>
          <p class="text-sm tx-secondary">${T('datasets.generate_no_vars')}</p>
        </div>`
      }
      const opts = tpls
        .map(
          (t) =>
            `<option value="${t.id}"${state.selectedGenerateTemplate == t.id ? ' selected' : ''}>${escHtml(t.name)}</option>`
        )
        .join('')
      const docsMap = d.documents || {}
      const docs = Object.values(docsMap)
      const docRows = docs
        .map(
          (doc) =>
            `<div class="flex items-center justify-between py-2 tx-divider border-b last:border-0">
              <div>
                <span class="text-sm font-medium">${escHtml(doc.template_name || doc.name || T('datasets.download_doc'))}</span>
                <span class="text-xs tx-secondary ml-2">${formatDate(doc.created_at || doc.generated_at)}</span>
              </div>
              <div class="flex items-center gap-2">
                <button data-action="download-doc" data-doc-id="${doc.id}" class="flex items-center gap-1 text-sm tx-link">${ICONS.download} ${T('datasets.download_doc')}</button>
                <button data-action="delete-doc" data-doc-id="${doc.id}" class="flex items-center gap-1 text-sm" style="color:var(--status-error)">${ICONS.trash}</button>
              </div>
            </div>`
        )
        .join('')
      return `<div class="tx-card p-5">
        <div class="flex items-center gap-2 mb-3">
          <h4 class="text-sm font-semibold uppercase tracking-wider tx-secondary flex items-center gap-2">${ICONS.doc} ${T('datasets.section_generate')}</h4>
          <span class="text-xs tx-secondary">— ${T('datasets.section_generate_hint')}</span>
        </div>
        ${
          tpls.length
            ? `<div class="space-y-2 mb-4">
                <div class="flex items-center gap-2">
                  <select id="tx-generate-template" class="tx-select flex-1">
                    <option value="">— ${T('datasets.generate_select_tpl')} —</option>
                    ${opts}
                  </select>
                  <button data-action="generate" class="tx-btn tx-btn-sm" ${!state.selectedGenerateTemplate || state.datasetGenerating ? 'disabled' : ''}>
                    ${state.datasetGenerating ? `<div class="animate-spin rounded-full h-3 w-3 border-b-2 border-white"></div> ${T('datasets.generate_running')}` : `${ICONS.doc} ${T('datasets.generate_btn')}`}
                  </button>
                </div>
                <div class="flex items-center gap-2">
                  <input id="tx-generate-instruction" class="tx-input flex-1 text-sm" maxlength="1000" placeholder="${T('datasets.generate_instruction_placeholder')}" value="${escHtml(state.generateInstruction || '')}" ${state.datasetGenerating ? 'disabled' : ''} />
                  ${tooltipIcon(T('datasets.generate_instruction_hint'))}
                </div>
              </div>`
            : `<div class="tx-callout mb-4">${T('templates.empty_hint')} <button data-tab="setup" class="tx-link ml-2">${T('collection.goto_templates')} →</button></div>`
        }
        <div>
          <h5 class="text-xs font-medium tx-secondary uppercase tracking-wider mb-2">${T('datasets.generated_docs')}</h5>
          ${docs.length ? `<div class="rounded border px-3" style="border-color:var(--divider)">${docRows}</div>` : `<p class="text-sm tx-secondary">${T('datasets.no_generated_docs')}</p>`}
        </div>
      </div>`
    }

    // --- Export tab ---

    function renderExportTab(c) {
      const allDatasets = datasetsForCollection(c.id)
      const filtered = filterDatasetsForExport(allDatasets)
      const fields = c.fields || []
      const columns = [
        'id',
        'name',
        'status',
        'created_at',
        'updated_at',
        'source_files',
        'generated_documents',
        ...fields.map((f) => f.key),
      ]

      const header = `<div class="flex items-start justify-between gap-3 flex-wrap">
        <div>
          <div class="flex items-center gap-2 mb-1">
            <h3 class="text-lg font-semibold">${T('export.title')}</h3>
            ${helpTrigger('export')}
          </div>
          <p class="text-sm tx-secondary" style="max-width:640px">${T('export.subtitle')}</p>
        </div>
      </div>`

      if (allDatasets.length === 0) {
        return `<div class="space-y-4">${header}
          <div class="tx-card p-8 text-center">
            <p class="text-sm tx-secondary">${T('export.empty')}</p>
          </div>
        </div>`
      }

      const filters = `<div class="tx-card p-5 space-y-3">
        <h4 class="text-sm font-semibold">${T('export.filters_title')}</h4>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <div>
            <label class="tx-label">${T('export.filter_status')}</label>
            <select id="tx-export-status" class="tx-select">
              <option value="">${T('export.filter_status_any')}</option>
              ${['draft', 'extracted', 'reviewed', 'generated', 'incomplete']
                .map(
                  (s) =>
                    `<option value="${s}"${state.exportStatus === s ? ' selected' : ''}>${T(`datasets.status_${s}`)}</option>`
                )
                .join('')}
            </select>
          </div>
          <div>
            <label class="tx-label">${T('export.filter_search')}</label>
            <input id="tx-export-search" type="text" class="tx-input" value="${escHtml(state.exportSearch)}" placeholder="${T('app.search')}" />
          </div>
          <div>
            <label class="tx-label">${T('export.filter_from')}</label>
            <input id="tx-export-from" type="date" class="tx-input" value="${escHtml(state.exportFrom)}" />
          </div>
          <div>
            <label class="tx-label">${T('export.filter_to')}</label>
            <input id="tx-export-to" type="date" class="tx-input" value="${escHtml(state.exportTo)}" />
          </div>
        </div>
      </div>`

      const preview = `<div class="tx-card p-5 space-y-3">
        <div class="flex items-center justify-between">
          <p class="text-sm font-medium">${filtered.length === 0 ? T('export.preview_none') : Tf('export.preview_count', { count: filtered.length })}</p>
          <button data-action="export-csv" class="tx-btn tx-btn-sm" ${filtered.length === 0 ? 'disabled' : ''}>${ICONS.download} ${T('export.download_csv')}</button>
        </div>
        <div class="text-xs tx-secondary">
          <span class="font-medium">${T('export.preview_columns')}:</span>
          ${columns.map((col) => `<code class="inline-block mr-1 mb-1" style="background:var(--bg-chip);padding:.125rem .375rem;border-radius:.25rem">${escHtml(col)}</code>`).join('')}
        </div>
        <p class="tx-hint">${T('export.encoding_hint')}</p>
      </div>`

      return `<div class="space-y-4">${header}${filters}${preview}</div>`
    }

    function filterDatasetsForExport(list) {
      let out = list.slice()
      if (state.exportStatus) out = out.filter((d) => d.status === state.exportStatus)
      if (state.exportSearch) {
        const q = state.exportSearch.toLowerCase()
        out = out.filter((d) => {
          const hay = [
            datasetDisplayName(d),
            ...Object.values(d.field_values || {}).map(String),
            ...Object.values(d.ai_extracted || {}).map(String),
          ]
            .join(' ')
            .toLowerCase()
          return hay.includes(q)
        })
      }
      if (state.exportFrom) {
        const from = new Date(state.exportFrom).getTime()
        out = out.filter((d) => new Date(d.created_at || 0).getTime() >= from)
      }
      if (state.exportTo) {
        const to = new Date(state.exportTo).getTime() + 86400000 // inclusive end
        out = out.filter((d) => new Date(d.created_at || 0).getTime() < to)
      }
      return out
    }

    // --- Danger zone ---

    function renderDangerTab(c) {
      const datasets = datasetsForCollection(c.id)
      const warning =
        datasets.length === 0
          ? T('danger.warning_no_datasets')
          : Tf('danger.warning_with_datasets', { count: datasets.length })
      const typed = state.dangerConfirmText || ''
      const matches = typed === c.name
      return `<div class="space-y-4">
        <div class="tx-danger-zone space-y-3">
          <div class="flex items-start gap-2">
            <span style="color:var(--status-error)">${ICONS.warning}</span>
            <div>
              <h3 class="font-semibold text-base">${T('danger.title')}</h3>
              <p class="text-sm tx-secondary mt-1">${T('danger.subtitle')}</p>
            </div>
          </div>
          <p class="text-sm font-medium" style="color:var(--status-error)">${warning}</p>
          <div class="pt-2 space-y-2">
            <p class="text-sm font-medium">${T('danger.confirm_title')}</p>
            <p class="text-xs tx-secondary">${T('danger.confirm_body')} <code class="font-semibold" style="background:var(--bg-chip);padding:.125rem .375rem;border-radius:.25rem">${escHtml(c.name)}</code></p>
            <input id="tx-danger-input" class="tx-input" value="${escHtml(typed)}" placeholder="${T('danger.confirm_input_placeholder')}" style="max-width:320px;${typed && !matches ? 'border-color:var(--status-error);' : ''}" />
            <p id="tx-danger-mismatch" class="text-xs" style="color:var(--status-error);${typed && !matches ? '' : 'display:none'}">${T('danger.confirm_mismatch')}</p>
            <div>
              <button data-action="delete-collection" class="tx-btn tx-btn-sm tx-btn-danger" ${matches ? '' : 'disabled'}>
                ${ICONS.trash} ${T('danger.confirm_delete_btn')}
              </button>
            </div>
          </div>
        </div>
      </div>`
    }

    // =========================================================================
    // Settings view
    // =========================================================================

    function renderSettings() {
      const cfg = state.config || {}
      return `<div class="mt-4 space-y-4">
        <div class="tx-card p-6">
          <h3 class="text-lg font-semibold mb-4">${T('settings.title')}</h3>
          <form id="tx-settings-form" class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label class="tx-label">${T('settings.company_name')}</label>
              <input name="company_name" value="${escHtml(cfg.company_name || '')}" class="tx-input" />
              <p class="tx-hint">${T('settings.company_name_hint')}</p>
            </div>
            <div>
              <label class="tx-label">${T('settings.default_language')}</label>
              <select name="default_language" class="tx-select">
                <option value="de"${cfg.default_language === 'de' ? ' selected' : ''}>${T('settings.lang_de')}</option>
                <option value="en"${cfg.default_language === 'en' ? ' selected' : ''}>${T('settings.lang_en')}</option>
                <option value="es"${cfg.default_language === 'es' ? ' selected' : ''}>${T('settings.lang_es')}</option>
                <option value="tr"${cfg.default_language === 'tr' ? ' selected' : ''}>${T('settings.lang_tr')}</option>
              </select>
            </div>
            <div>
              <label class="tx-label">${T('settings.extraction_model')}</label>
              <input name="extraction_model" value="${escHtml(cfg.extraction_model || 'default')}" class="tx-input" />
              <p class="tx-hint">${T('settings.extraction_model_hint')}</p>
            </div>
            <div>
              <label class="tx-label">${T('settings.validation_model')}</label>
              <input name="validation_model" value="${escHtml(cfg.validation_model || 'default')}" class="tx-input" />
              <p class="tx-hint">${T('settings.validation_model_hint')}</p>
            </div>
            <div class="flex items-center gap-3" style="grid-column:1 / -1">
              <button type="submit" class="tx-btn">${T('app.save')}</button>
              <span id="tx-settings-msg" class="text-sm hidden" style="color:var(--status-success)">${ICONS.check} ${T('app.saved')}</span>
            </div>
          </form>
        </div>
        ${renderSystemStatusCard()}
      </div>`
    }

    // --- Prompts tab -------------------------------------------------------
    //
    // Every AI prompt section registered in the backend (extraction rules,
    // processing-context defaults, generation instruction transform) is shown
    // with a plain-language explanation in the UI language, an editable
    // textarea, and per-prompt Save / Reset-to-default actions.

    function renderPrompts() {
      const prompts = state.prompts
      if (!prompts) {
        return renderLoading()
      }
      const cards = prompts
        .map((p) => {
          const draft = state.promptDrafts[p.key] ?? p.text
          const dirty = draft.trim() !== p.text.trim()
          const rows = Math.min(22, Math.max(6, draft.split('\n').length + 1))
          const badge = p.customized
            ? `<span class="tx-badge" style="background:color-mix(in srgb, var(--status-warning, #f59e0b) 14%, transparent);color:var(--status-warning, #f59e0b)">${T('prompts.badge_customized')}</span>`
            : `<span class="tx-badge tx-badge-plain">${T('prompts.badge_default')}</span>`
          const saving = state.promptSaving === p.key
          return `<div class="tx-card p-5 space-y-3">
            <div class="flex items-center justify-between gap-3 flex-wrap">
              <div class="flex items-center gap-2">
                <h4 class="text-sm font-semibold">${T(`prompts.${p.key}_title`, p.key)}</h4>
                ${badge}
              </div>
              <div class="flex items-center gap-2">
                <button data-action="prompt-reset" data-prompt-key="${p.key}" class="tx-btn tx-btn-sm tx-btn-ghost" ${!p.customized && !dirty ? 'disabled' : ''}>${T('prompts.reset_btn')}</button>
                <button data-action="prompt-save" data-prompt-key="${p.key}" class="tx-btn tx-btn-sm" ${!dirty || saving ? 'disabled' : ''}>
                  ${saving ? `<div class="animate-spin rounded-full h-3 w-3 border-b-2 border-white"></div>` : ''} ${T('app.save')}
                </button>
              </div>
            </div>
            <p class="text-xs tx-secondary" style="white-space:pre-line">${T(`prompts.${p.key}_explain`, '')}</p>
            <textarea data-prompt-key="${p.key}" class="tx-textarea" rows="${rows}" spellcheck="false" style="font-family:ui-monospace,Menlo,Consolas,monospace;font-size:.78rem;line-height:1.45">${escHtml(draft)}</textarea>
          </div>`
        })
        .join('')
      return `<div class="mt-4 space-y-4">
        <div class="tx-callout flex items-start gap-2">
          ${ICONS.info}
          <div>
            <div class="text-sm font-medium">${T('prompts.title')}</div>
            <p class="text-xs tx-secondary mt-0.5" style="white-space:pre-line">${T('prompts.subtitle')}</p>
          </div>
        </div>
        ${cards}
      </div>`
    }

    function bindPromptsEvents() {
      el.querySelectorAll('textarea[data-prompt-key]').forEach((ta) =>
        ta.addEventListener('input', () => {
          state.promptDrafts[ta.dataset.promptKey] = ta.value
          // Re-toggle the buttons without a full re-render (which would
          // steal focus from the textarea mid-typing).
          const key = ta.dataset.promptKey
          const p = (state.prompts || []).find((x) => x.key === key)
          if (!p) return
          const dirty = ta.value.trim() !== p.text.trim()
          const saveBtn = el.querySelector(`[data-action="prompt-save"][data-prompt-key="${key}"]`)
          if (saveBtn) saveBtn.disabled = !dirty
          const resetBtn = el.querySelector(
            `[data-action="prompt-reset"][data-prompt-key="${key}"]`
          )
          if (resetBtn) resetBtn.disabled = !p.customized && !dirty
        })
      )

      el.querySelectorAll('[data-action="prompt-save"]').forEach((btn) =>
        btn.addEventListener('click', async () => {
          const key = btn.dataset.promptKey
          state.promptSaving = key
          render()
          try {
            const res = await api('/prompts', {
              method: 'PUT',
              body: JSON.stringify({ [key]: state.promptDrafts[key] ?? '' }),
            })
            state.prompts = res.prompts || []
            state.promptDrafts = {}
            for (const p of state.prompts) state.promptDrafts[p.key] = p.text
            showToast(T('prompts.saved'))
          } catch (err) {
            showToast(err.message, 'error')
          }
          state.promptSaving = null
          render()
        })
      )

      el.querySelectorAll('[data-action="prompt-reset"]').forEach((btn) =>
        btn.addEventListener('click', async () => {
          const key = btn.dataset.promptKey
          const p = (state.prompts || []).find((x) => x.key === key)
          if (!p) return
          // Customized on the server → confirm + reset server-side.
          // Only a local draft → just restore the textarea.
          if (p.customized) {
            if (!confirm(T('prompts.reset_confirm'))) return
            state.promptSaving = key
            render()
            try {
              const res = await api('/prompts', {
                method: 'PUT',
                body: JSON.stringify({ [key]: '' }),
              })
              state.prompts = res.prompts || []
              state.promptDrafts = {}
              for (const pp of state.prompts) state.promptDrafts[pp.key] = pp.text
              showToast(T('prompts.reset_done'))
            } catch (err) {
              showToast(err.message, 'error')
            }
            state.promptSaving = null
          } else {
            state.promptDrafts[key] = p.text
          }
          render()
        })
      )
    }

    // =========================================================================
    // Event binding
    // =========================================================================

    function bindEvents() {
      // Top nav
      el.querySelectorAll('[data-nav]').forEach((btn) =>
        btn.addEventListener('click', (e) => {
          e.preventDefault()
          const v = btn.dataset.nav
          if (v === 'collections') navigate({ view: 'collections' })
          else if (v === 'settings') navigate({ view: 'settings' })
          else if (v === 'prompts') navigate({ view: 'prompts' })
        })
      )

      el.querySelector('[data-action="dismiss-page-alert"]')?.addEventListener('click', () => {
        state.pageAlert = null
        render()
      })

      // Collection tabs
      el.querySelectorAll('[data-tab]').forEach((btn) =>
        btn.addEventListener('click', (e) => {
          e.preventDefault()
          navigate({ tab: btn.dataset.tab })
        })
      )

      // Collections list → open card. Ready Collections (vars + templates)
      // land on the Datasets tab so users see their daily work first; only
      // incomplete or draft Collections land on Overview, where the
      // next-step nudges live.
      el.querySelectorAll('[data-open-collection]').forEach((card) =>
        card.addEventListener('click', () => {
          const id = card.dataset.openCollection
          const c = collectionById(id)
          navigate({
            view: 'collection',
            collectionId: id,
            tab: defaultLandingTab(c),
          })
        })
      )

      // Search on collections list
      const csearch = el.querySelector('#tx-collections-search')
      if (csearch) {
        csearch.addEventListener('input', () => {
          state.collectionsSearch = csearch.value
          render()
          const again = el.querySelector('#tx-collections-search')
          if (again) {
            again.focus()
            again.selectionStart = again.selectionEnd = again.value.length
          }
        })
      }

      // Help modal triggers
      el.querySelectorAll('[data-help]').forEach((btn) =>
        btn.addEventListener('click', () => {
          state.showCollectionHelp = btn.dataset.help === 'collections'
          state.showVariablesHelp = btn.dataset.help === 'variables'
          state.showTemplatesHelp = btn.dataset.help === 'templates'
          state.showExportHelp = btn.dataset.help === 'export'
          render()
        })
      )
      el.querySelectorAll('[data-action="close-help"]').forEach((btn) =>
        btn.addEventListener('click', () => {
          state.showCollectionHelp = false
          state.showVariablesHelp = false
          state.showTemplatesHelp = false
          state.showExportHelp = false
          render()
        })
      )

      // New collection (legacy direct trigger — kept for backwards
      // compatibility with any external code that still fires it; the
      // split button now drives the main entry points)
      el.querySelector('[data-action="new-collection"]')?.addEventListener('click', () => {
        state.newCollectionOpen = true
        state.editingCollection = null
        state.setupMenuOpen = null
        render()
      })

      // Setup split-button: primary click + per-mode dropdown items.
      // wizard/template/text modes open the 5-step Setup Wizard with
      // the appropriate entry point; manual mode falls back to the
      // legacy New Collection modal so power users keep their muscle
      // memory.
      el.querySelectorAll('[data-action="setup-action"]').forEach((btn) =>
        btn.addEventListener('click', (e) => {
          e.stopPropagation()
          const mode = btn.dataset.mode || 'wizard'
          state.setupMenuOpen = null
          if (mode === 'manual') {
            state.pendingSetupMode = 'manual'
            state.newCollectionOpen = true
            state.editingCollection = null
            render()
            return
          }
          // wizard => start with the chooser (let the user decide)
          // template => skip the chooser and head to basics with the
          //   template mode pre-selected (so step 2 is mandatory)
          // text => skip the chooser and head to basics with the
          //   noTemplate mode pre-selected (so step 3 is the paste UI)
          wizardOpen({ mode })
        })
      )
      el.querySelectorAll('[data-action="setup-menu-toggle"]').forEach((btn) =>
        btn.addEventListener('click', (e) => {
          e.stopPropagation()
          const id = btn.dataset.id || 'default'
          state.setupMenuOpen = state.setupMenuOpen === id ? null : id
          render()
        })
      )
      el.querySelectorAll('[data-action="setup-menu-close"]').forEach((btn) =>
        btn.addEventListener('click', () => {
          state.setupMenuOpen = null
          render()
        })
      )

      // Draft "Resume" / "Discard" buttons in the Drafts section.
      // Resume opens the wizard pre-loaded with the existing draft so
      // the user picks up exactly where they left off; the wizard's
      // own Cancel button drops them back here without losing anything.
      el.querySelectorAll('[data-action="draft-resume"]').forEach((btn) =>
        btn.addEventListener('click', () => {
          wizardOpen({ resumeCollectionId: btn.dataset.id })
        })
      )
      el.querySelectorAll('[data-action="draft-discard"]').forEach((btn) =>
        btn.addEventListener('click', async () => {
          const name = btn.dataset.name || T('collections.draft_unnamed')
          const ok = window.confirm(Tf('collections.draft_discard_confirm', { name }))
          if (!ok) return
          try {
            await api(`/forms/${btn.dataset.id}`, { method: 'DELETE' })
            await fetchForms()
            showToast(T('collections.draft_discarded'))
            render()
          } catch (err) {
            showToast(err.message, 'error')
          }
        })
      )
      el.querySelector('[data-action="edit-collection"]')?.addEventListener('click', () => {
        const c = collectionById(state.collectionId)
        if (!c) return
        state.collectionMenuOpen = false
        state.editingCollection = JSON.parse(JSON.stringify(c))
        render()
      })
      el.querySelectorAll('[data-action="close-collection-editor"]').forEach((btn) =>
        btn.addEventListener('click', () => {
          state.newCollectionOpen = false
          state.editingCollection = null
          state.pendingSetupMode = null
          render()
        })
      )

      // Kebab menu next to the Collection title (Edit + Danger Zone)
      el.querySelector('[data-action="toggle-collection-menu"]')?.addEventListener('click', (e) => {
        e.stopPropagation()
        state.collectionMenuOpen = !state.collectionMenuOpen
        render()
      })
      el.querySelectorAll('[data-action="close-collection-menu"]').forEach((btn) =>
        btn.addEventListener('click', () => {
          state.collectionMenuOpen = false
          render()
        })
      )
      el.querySelector('[data-action="open-danger"]')?.addEventListener('click', () => {
        state.collectionMenuOpen = false
        state.dangerOpen = true
        state.dangerConfirmText = ''
        render()
      })
      el.querySelectorAll('[data-action="close-danger-modal"]').forEach((btn) =>
        btn.addEventListener('click', () => {
          state.dangerOpen = false
          state.dangerConfirmText = ''
          render()
        })
      )
      const cForm = el.querySelector('#tx-collection-form')
      if (cForm) {
        cForm.addEventListener('submit', async (e) => {
          e.preventDefault()
          const fd = new FormData(cForm)
          const payload = {
            name: fd.get('name')?.toString().trim(),
            description: fd.get('description')?.toString().trim() || '',
            language: fd.get('language')?.toString() || state.config.default_language || 'de',
          }
          if (!payload.name) return
          try {
            if (state.editingCollection?.id) {
              await api(`/forms/${state.editingCollection.id}`, {
                method: 'PUT',
                body: JSON.stringify(payload),
              })
              state.editingCollection = null
              showToast(T('app.saved'))
              await fetchForms()
              render()
            } else {
              const res = await api('/forms', {
                method: 'POST',
                body: JSON.stringify({ ...payload, fields: [] }),
              })
              state.newCollectionOpen = false
              state.editingCollection = null
              // Honor the split-button entry point: every mode except
              // explicit "manual" jumps straight into the Set up tab so
              // the user keeps momentum on the configuration work. PR3
              // will swap this for the real wizard modal.
              const mode = state.pendingSetupMode || 'manual'
              state.pendingSetupMode = null
              const landTab = mode === 'manual' ? 'overview' : 'setup'
              await fetchForms()
              navigate({
                view: 'collection',
                collectionId: res.form.id,
                tab: landTab,
              })
            }
          } catch (err) {
            showToast(err.message, 'error')
          }
        })
      }

      // Plugin dashboard (system status card on the overview tab)
      bindDashboardEvents()

      // Prompts tab
      bindPromptsEvents()

      // Variables tab
      bindVariablesEvents()

      // Templates tab
      bindTemplatesEvents()

      // Datasets tab
      bindDatasetsEvents()

      // Export tab
      bindExportEvents()

      // Danger zone
      bindDangerEvents()

      // Setup Wizard
      bindWizardEvents()

      // Settings
      const sForm = el.querySelector('#tx-settings-form')
      if (sForm) {
        sForm.addEventListener('submit', async (e) => {
          e.preventDefault()
          const body = Object.fromEntries(new FormData(sForm).entries())
          try {
            const res = await api('/config', {
              method: 'PUT',
              body: JSON.stringify(body),
            })
            if (res.success) {
              state.config = res.config
              const msg = el.querySelector('#tx-settings-msg')
              if (msg) {
                msg.classList.remove('hidden')
                setTimeout(() => msg.classList.add('hidden'), 2000)
              }
            }
          } catch (err) {
            showToast(err.message, 'error')
          }
        })
      }
    }

    // --- Variables events ---

    function bindVariablesEvents() {
      const c = collectionById(state.collectionId)
      if (!c) return

      // Ensure draft exists for editing
      function ensureDraft() {
        if (!state.variablesDraft) {
          state.variablesDraft = JSON.parse(JSON.stringify(c.fields || []))
          state.variablesDirty = false
        }
      }

      function collectForm() {
        const form = el.querySelector('#tx-variables-form')
        if (!form || !state.variablesDraft) return
        const fd = new FormData(form)
        const fields = state.variablesDraft
        for (let idx = 0; idx < fields.length; idx++) {
          if (!fd.has(`fk_${idx}`)) continue
          fields[idx].key = fd.get(`fk_${idx}`)?.toString().trim() || fields[idx].key
          fields[idx].label = fd.get(`fl_${idx}`)?.toString().trim() || fields[idx].label
          fields[idx].type = fd.get(`ft_${idx}`)?.toString() || fields[idx].type
          fields[idx].required = fd.has(`fr_${idx}`)
          fields[idx].hint = fd.get(`fh_${idx}`)?.toString().trim() || ''
          if (fields[idx].type === 'select') {
            const raw = fd.get(`fo_${idx}`)?.toString().trim() || ''
            fields[idx].options = raw
              ? raw
                  .split(',')
                  .map((o) => o.trim())
                  .filter(Boolean)
              : []
          }
          if (fields[idx].type === 'table') {
            const cols = fields[idx].columns || []
            const validColTypes = ['text', 'textarea', 'list', 'date', 'number']
            for (let ci = 0; ci < cols.length; ci++) {
              if (fd.has(`fc_${idx}_ck_${ci}`)) {
                cols[ci].key = fd.get(`fc_${idx}_ck_${ci}`)?.toString().trim() || cols[ci].key
                cols[ci].label = fd.get(`fc_${idx}_cl_${ci}`)?.toString().trim() || cols[ci].label
                const ct = fd.get(`fc_${idx}_ct_${ci}`)?.toString()
                cols[ci].type = validColTypes.includes(ct) ? ct : cols[ci].type || 'text'
                // List rendering mode (flat bullets vs structured HR
                // layout). Only present in the DOM for list columns.
                const cs = fd.get(`fc_${idx}_cs_${ci}`)?.toString()
                if (cols[ci].type === 'list' && (cs === 'flat' || cs === 'structured')) {
                  cols[ci].structured = cs === 'structured'
                } else if (cols[ci].type !== 'list') {
                  delete cols[ci].structured
                }
                // Bullet glyph + indent (feedback #4). Only for list columns;
                // empty glyph clears the override (back to template/default).
                if (cols[ci].type === 'list') {
                  const bch = fd.get(`fc_${idx}_bch_${ci}`)?.toString() ?? ''
                  if (bch.trim() !== '') cols[ci].bullet_char = bch.trim()
                  else delete cols[ci].bullet_char
                  const bin = fd.get(`fc_${idx}_bin_${ci}`)?.toString() ?? ''
                  const binNum = parseFloat(bin)
                  if (bin.trim() !== '' && !isNaN(binNum) && binNum > 0)
                    cols[ci].bullet_indent_cm = binNum
                  else delete cols[ci].bullet_indent_cm
                } else {
                  delete cols[ci].bullet_char
                  delete cols[ci].bullet_indent_cm
                }
              }
            }
          }
          // Designer config (collapsible per-field block).
          //
          // IMPORTANT: only harvest when the designer panel is actually in
          // the DOM for this field. The panel is collapsible and only ONE
          // can be open at a time — `fd.has()` on a checkbox that isn't
          // rendered returns false, so harvesting blindly used to silently
          // flip every collapsed field's boolean designer options
          // (clickable_checkbox, repeat_header, prevent_orphans, …) to
          // false on every save. That's the "design options don't work"
          // bug from the customer session.
          if (!form.querySelector(`[name^="fd_${idx}_"]`)) continue
          const designer = fields[idx].designer || {}
          if (fields[idx].type === 'list') {
            const style = fd.get(`fd_${idx}_style`)?.toString()
            if (style === 'ol' || style === 'ul') designer.list_style = style
            designer.prevent_orphans = fd.has(`fd_${idx}_prevent_orphans`)
            designer.top_blank_line = fd.has(`fd_${idx}_top_blank_line`)
            designer.bottom_blank_line = fd.has(`fd_${idx}_bottom_blank_line`)
          } else if (fields[idx].type === 'table') {
            designer.repeat_header = fd.has(`fd_${idx}_repeat_header`)
            designer.prevent_row_break = fd.has(`fd_${idx}_prevent_row_break`)
            designer.keep_with_prev = fd.has(`fd_${idx}_keep_with_prev`)
          } else if (fields[idx].type === 'checkbox') {
            const ch = fd.get(`fd_${idx}_checked_glyph`)?.toString().trim()
            const un = fd.get(`fd_${idx}_unchecked_glyph`)?.toString().trim()
            const yl = fd.get(`fd_${idx}_yes_label`)?.toString().trim()
            const nl = fd.get(`fd_${idx}_no_label`)?.toString().trim()
            if (ch) designer.checked_glyph = ch
            if (un) designer.unchecked_glyph = un
            if (yl) designer.yes_label = yl
            if (nl) designer.no_label = nl
            designer.clickable_checkbox = fd.has(`fd_${idx}_clickable_checkbox`)
          } else if (fields[idx].type === 'image') {
            const w = parseInt(fd.get(`fd_${idx}_width`) || '0', 10)
            const h = parseInt(fd.get(`fd_${idx}_height`) || '0', 10)
            if (w > 0) designer.width = w
            if (h > 0) designer.height = h
            designer.preserve_ratio = fd.has(`fd_${idx}_preserve_ratio`)
            const placement = fd.get(`fd_${idx}_placement`)?.toString()
            if (placement === 'inline' || placement === 'float') designer.placement = placement
            const fh = fd.get(`fd_${idx}_float_h`)?.toString()
            if (['left', 'center', 'right'].includes(fh)) designer.float_h = fh
            const fv = fd.get(`fd_${idx}_float_v`)?.toString()
            if (['top', 'middle', 'bottom'].includes(fv)) designer.float_v = fv
            const wr = fd.get(`fd_${idx}_wrap`)?.toString()
            if (['square', 'topAndBottom', 'none'].includes(wr)) designer.wrap = wr
          }
          if (Object.keys(designer).length > 0) fields[idx].designer = designer
        }
      }

      el.querySelector('[data-action="add-variable"]')?.addEventListener('click', () => {
        ensureDraft()
        collectForm()
        state.variablesDraft.push({
          key: '',
          label: '',
          type: 'text',
          required: false,
          source: 'form',
        })
        state.variablesDirty = true
        render()
      })

      el.querySelectorAll('[data-action="var-remove"]').forEach((btn) =>
        btn.addEventListener('click', () => {
          ensureDraft()
          collectForm()
          if (!confirm(T('variables.confirm_delete_field'))) return
          const idx = parseInt(btn.dataset.idx)
          state.variablesDraft.splice(idx, 1)
          state.variablesDirty = true
          render()
        })
      )

      el.querySelectorAll('[data-action="var-move-up"], [data-action="var-move-down"]').forEach(
        (btn) =>
          btn.addEventListener('click', () => {
            ensureDraft()
            collectForm()
            const idx = parseInt(btn.dataset.idx)
            const dir = btn.dataset.action === 'var-move-up' ? -1 : 1
            const n = idx + dir
            if (n < 0 || n >= state.variablesDraft.length) return
            ;[state.variablesDraft[idx], state.variablesDraft[n]] = [
              state.variablesDraft[n],
              state.variablesDraft[idx],
            ]
            state.variablesDirty = true
            render()
          })
      )

      enableDragReorder(el.querySelector('#tx-variables-form'), {
        rowSelector: ':scope > .tx-row[data-field-idx]',
        handleSelector: '[data-drag-handle]',
        onDrop: (from, to) => {
          ensureDraft()
          collectForm()
          const moved = state.variablesDraft.splice(from, 1)[0]
          state.variablesDraft.splice(to, 0, moved)
          state.variablesDirty = true
          render()
        },
      })

      el.querySelectorAll('[data-action="var-col-add"]').forEach((btn) =>
        btn.addEventListener('click', () => {
          ensureDraft()
          collectForm()
          const fIdx = parseInt(btn.dataset.fieldIdx)
          const f = state.variablesDraft[fIdx]
          if (!f) return
          if (!f.columns) f.columns = []
          f.columns.push({ key: '', label: '', type: 'text' })
          state.variablesDirty = true
          render()
        })
      )

      el.querySelectorAll('[data-action="var-col-remove"]').forEach((btn) =>
        btn.addEventListener('click', () => {
          ensureDraft()
          collectForm()
          const fIdx = parseInt(btn.dataset.fieldIdx)
          const cIdx = parseInt(btn.dataset.colIdx)
          const f = state.variablesDraft[fIdx]
          if (!f?.columns) return
          f.columns.splice(cIdx, 1)
          state.variablesDirty = true
          render()
        })
      )

      el.querySelectorAll('[data-action="var-designer-toggle"]').forEach((btn) =>
        btn.addEventListener('click', () => {
          ensureDraft()
          collectForm()
          const idx = parseInt(btn.dataset.idx)
          state.expandedDesignerIdx = state.expandedDesignerIdx === idx ? null : idx
          render()
        })
      )

      const vForm = el.querySelector('#tx-variables-form')
      if (vForm) {
        vForm.addEventListener('input', () => {
          if (!state.variablesDirty) {
            state.variablesDirty = true
            // Enable Save button without full re-render (preserves focus).
            const saveBtn = vForm.querySelector('button[type=submit]')
            if (saveBtn) saveBtn.disabled = false
          }
        })
        vForm.addEventListener('change', (ev) => {
          // Type changes swap the markup (e.g. select→table, text→list in a
          // table column), so full render.
          if (
            ev.target.name &&
            (ev.target.name.startsWith('ft_') || /^fc_\d+_ct_\d+$/.test(ev.target.name))
          ) {
            state.variablesDirty = true
            ensureDraft()
            collectForm()
            render()
          } else {
            state.variablesDirty = true
          }
        })
        vForm.addEventListener('submit', async (e) => {
          e.preventDefault()
          ensureDraft()
          collectForm()
          const validFields = state.variablesDraft.filter((f) => (f.key || '').trim())
          try {
            await api(`/forms/${c.id}`, {
              method: 'PUT',
              body: JSON.stringify({ fields: validFields }),
            })
            showToast(T('variables.save_success'))
            state.variablesDraft = null
            state.variablesDirty = false
            await fetchForms()
            render()
          } catch (err) {
            showToast(err.message, 'error')
          }
        })
      }

      el.querySelector('[data-action="variables-reset"]')?.addEventListener('click', () => {
        state.variablesDraft = null
        state.variablesDirty = false
        render()
      })

      el.querySelector('[data-action="variables-import"]')?.addEventListener('click', () => {
        state.variablesImportOpen = true
        state.variablesImportFields = null
        state.variablesImportError = null
        state.variablesImportText = ''
        render()
      })

      // --- Polish with AI ---
      // Calls /forms/{id}/enhance-fields, then opens the diff modal so
      // the admin can review and accept per-field. The merged fields
      // are saved via the existing PUT /forms/{id} on apply.
      el.querySelector('[data-action="variables-polish"]')?.addEventListener('click', async () => {
        const draft = state.variablesDraft || c.fields || []
        if (draft.length === 0) return
        state.polishLoading = true
        state.polishError = null
        render()
        try {
          await refreshAccessToken()
          const res = await api(`/forms/${c.id}/enhance-fields`, {
            timeoutMs: 180000,
            method: 'POST',
            body: JSON.stringify({}),
          })
          if (!res.success) {
            throw new Error(res.error || T('variables.polish_failed_generic'))
          }
          const enhancements = Array.isArray(res.enhancements) ? res.enhancements : []
          state.polishResults = { enhancements }
          state.polishMeta = {
            model: res.model,
            elapsed_ms: res.elapsed_ms,
            filename: res.sample?.filename || null,
            candidate_id: res.sample?.candidate_id || null,
          }
          // Pre-tick rows where the AI is purely *adding* (current
          // had no description and no examples). Leave un-ticked
          // when the AI would overwrite something the admin
          // already authored.
          state.polishSelected = {}
          for (const en of enhancements) {
            const cur = en.current || {}
            const isPureAdd =
              !cur.description &&
              !cur.hint &&
              (!Array.isArray(cur.examples) || cur.examples.length === 0) &&
              !cur.negative_hint
            state.polishSelected[en.key] = isPureAdd
          }
          state.polishOpen = true
        } catch (err) {
          showToast(Tf('variables.polish_failed', { error: err.message }), 'warning')
        }
        state.polishLoading = false
        render()
      })

      el.querySelectorAll('[data-action="polish-toggle"]').forEach((cb) => {
        cb.addEventListener('change', () => {
          const k = cb.dataset.key
          state.polishSelected[k] = cb.checked
          render()
        })
      })
      el.querySelector('[data-action="polish-select-all"]')?.addEventListener('click', () => {
        const ens = state.polishResults?.enhancements || []
        state.polishSelected = Object.fromEntries(ens.map((e) => [e.key, true]))
        render()
      })
      el.querySelector('[data-action="polish-select-none"]')?.addEventListener('click', () => {
        state.polishSelected = {}
        render()
      })
      el.querySelector('[data-action="polish-cancel"]')?.addEventListener('click', () => {
        state.polishOpen = false
        state.polishResults = null
        state.polishSelected = {}
        state.polishMeta = null
        state.polishError = null
        render()
      })
      el.querySelector('[data-action="polish-apply"]')?.addEventListener('click', async () => {
        ensureDraft()
        const ens = state.polishResults?.enhancements || []
        const selected = ens.filter((e) => state.polishSelected[e.key])
        if (selected.length === 0) return
        // Merge accepted suggestions into the draft fields. Each
        // accepted field gets its description / examples /
        // negative_hint replaced with the AI's suggestion (we don't
        // try to merge — admin opted in to overwrite).
        const byKey = new Map(selected.map((e) => [e.key, e]))
        state.variablesDraft = state.variablesDraft.map((f) => {
          const en = byKey.get(f.key)
          if (!en) return f
          const out = { ...f }
          if (en.suggested.description) out.description = en.suggested.description
          if (Array.isArray(en.suggested.examples) && en.suggested.examples.length) {
            out.examples = en.suggested.examples
          }
          if (en.suggested.negative_hint) out.negative_hint = en.suggested.negative_hint
          return out
        })
        state.variablesDirty = true
        state.polishApplying = true
        state.polishError = null
        render()
        try {
          await api(`/forms/${c.id}`, {
            method: 'PUT',
            body: JSON.stringify({ fields: state.variablesDraft }),
          })
          state.variablesDraft = null
          state.variablesDirty = false
          state.polishOpen = false
          state.polishResults = null
          state.polishSelected = {}
          state.polishMeta = null
          await fetchForms()
          showToast(Tf('variables.polish_done', { count: selected.length }))
        } catch (err) {
          state.polishError = err.message
        }
        state.polishApplying = false
        render()
      })
      el.querySelectorAll('[data-action="variables-import-close"]').forEach((btn) =>
        btn.addEventListener('click', () => {
          state.variablesImportOpen = false
          state.variablesImportFields = null
          state.variablesImportError = null
          state.variablesImportText = ''
          render()
        })
      )
      el.querySelector('[data-action="variables-import-parse"]')?.addEventListener(
        'click',
        async () => {
          const textarea = el.querySelector('#tx-import-text')
          const fileInput = el.querySelector('#tx-import-file')
          const file = fileInput?.files?.[0]
          const text = textarea?.value?.trim()
          if (!text && !file) {
            state.variablesImportError = 'Paste text or upload a file first.'
            render()
            return
          }
          state.variablesImportParsing = true
          state.variablesImportError = null
          state.variablesImportText = textarea?.value || ''
          render()
          try {
            await refreshAccessToken()
            let res
            if (file)
              res = await apiUpload(
                '/forms/import-parse',
                file,
                {},
                {
                  timeoutMs: 180000,
                }
              )
            else
              res = await api('/forms/import-parse', {
                method: 'POST',
                timeoutMs: 180000,
                body: JSON.stringify({ text }),
              })
            state.variablesImportFields = res.fields || []
          } catch (err) {
            state.variablesImportError = err.message
          }
          state.variablesImportParsing = false
          render()
        }
      )
      el.querySelectorAll('[data-action="variables-import-remove"]').forEach((btn) =>
        btn.addEventListener('click', () => {
          const idx = parseInt(btn.dataset.idx)
          state.variablesImportFields.splice(idx, 1)
          render()
        })
      )
      el.querySelector('[data-action="variables-import-apply"]')?.addEventListener(
        'click',
        async () => {
          ensureDraft()
          const incoming = state.variablesImportFields || []
          const existingKeys = new Set(state.variablesDraft.map((f) => f.key))
          for (const f of incoming) {
            if (!f.key || existingKeys.has(f.key)) continue
            state.variablesDraft.push(f)
          }
          state.variablesDirty = true
          try {
            await api(`/forms/${c.id}`, {
              method: 'PUT',
              body: JSON.stringify({ fields: state.variablesDraft }),
            })
            state.variablesDraft = null
            state.variablesDirty = false
            state.variablesImportOpen = false
            state.variablesImportFields = null
            showToast(T('variables.save_success'))
            await fetchForms()
            render()
          } catch (err) {
            showToast(err.message, 'error')
          }
        }
      )

      // --- Import from Target Template ---
      el.querySelector('[data-action="variables-import-template"]')?.addEventListener(
        'click',
        () => {
          const tpls = collectionTemplates(c)
          state.variablesTplImportOpen = true
          state.variablesTplImportTemplateId = tpls[0]?.id || null
          state.variablesTplImportResult = null
          state.variablesTplImportError = null
          state.variablesTplImportSelection = {}
          render()
        }
      )

      el.querySelectorAll('[data-action="variables-tplimport-close"]').forEach((btn) =>
        btn.addEventListener('click', () => {
          state.variablesTplImportOpen = false
          state.variablesTplImportTemplateId = null
          state.variablesTplImportResult = null
          state.variablesTplImportError = null
          state.variablesTplImportSelection = {}
          render()
        })
      )

      el.querySelector('[data-action="variables-tplimport-load"]')?.addEventListener(
        'click',
        async () => {
          const picker = el.querySelector('#tx-tplimport-picker')
          const tplId = picker?.value
          if (!tplId) return
          state.variablesTplImportTemplateId = tplId
          state.variablesTplImportLoading = true
          state.variablesTplImportError = null
          state.variablesTplImportResult = null
          state.variablesTplImportSelection = {}
          render()
          try {
            await refreshAccessToken()
            const res = await api(
              `/templates/${encodeURIComponent(tplId)}/variable-suggestions?form_id=${encodeURIComponent(c.id)}`
            )
            state.variablesTplImportResult = res
            const sel = {}
            ;(res.suggestions || []).forEach((f, i) => {
              sel[i] = f._status !== 'duplicate'
            })
            state.variablesTplImportSelection = sel
          } catch (err) {
            state.variablesTplImportError = err.message
          }
          state.variablesTplImportLoading = false
          render()
        }
      )

      el.querySelectorAll('[data-action="variables-tplimport-toggle"]').forEach((cb) =>
        cb.addEventListener('change', () => {
          const idx = parseInt(cb.dataset.idx)
          state.variablesTplImportSelection[idx] = !!cb.checked
          render()
        })
      )

      el.querySelector('[data-action="variables-tplimport-apply"]')?.addEventListener(
        'click',
        async () => {
          const res = state.variablesTplImportResult
          if (!res) return
          const sel = state.variablesTplImportSelection || {}
          const picked = (res.suggestions || []).filter((_, i) => sel[i])
          if (picked.length === 0) return

          ensureDraft()
          const existingKeys = new Set(state.variablesDraft.map((f) => f.key))
          for (const f of picked) {
            if (!f.key) continue
            if (existingKeys.has(f.key)) continue
            const clone = { ...f }
            delete clone._status
            state.variablesDraft.push(clone)
            existingKeys.add(f.key)
          }
          state.variablesDirty = true
          try {
            await api(`/forms/${c.id}`, {
              method: 'PUT',
              body: JSON.stringify({ fields: state.variablesDraft }),
            })
            state.variablesDraft = null
            state.variablesDirty = false
            state.variablesTplImportOpen = false
            state.variablesTplImportTemplateId = null
            state.variablesTplImportResult = null
            state.variablesTplImportSelection = {}
            showToast(T('variables.import_applied'))
            await fetchForms()
            render()
          } catch (err) {
            showToast(err.message, 'error')
          }
        }
      )
    }

    // --- Templates events ---

    function bindTemplatesEvents() {
      const c = collectionById(state.collectionId)
      if (!c) return

      // Drag and drop
      const drop = el.querySelector('#tx-template-drop')
      const fi = el.querySelector('#tx-template-file')
      if (drop && fi) {
        drop.addEventListener('click', (e) => {
          if (e.target.closest('#tx-template-upload-form')) return
          fi.click()
        })
        drop.addEventListener('dragover', (e) => {
          e.preventDefault()
          drop.style.borderColor = 'var(--brand)'
        })
        drop.addEventListener('dragleave', () => (drop.style.borderColor = ''))
        drop.addEventListener('drop', (e) => {
          e.preventDefault()
          drop.style.borderColor = ''
          const f = e.dataTransfer.files[0]
          if (f) handleTemplateFile(f)
        })
        fi.addEventListener('change', () => {
          if (fi.files[0]) handleTemplateFile(fi.files[0])
        })
      }

      const uploadBtn = el.querySelector('#tx-upload-template-btn')
      const nameInput = el.querySelector('#tx-template-name')
      const langSelect = el.querySelector('#tx-template-language')
      if (uploadBtn && nameInput) {
        uploadBtn.addEventListener('click', async () => {
          const file = fi?.files[0] || _pendingTemplateFile
          const name = nameInput.value.trim()
          if (!file || !name) return
          try {
            uploadBtn.disabled = true
            uploadBtn.textContent = T('templates.uploading')
            const language = (langSelect?.value || '').trim()
            const extra = { name }
            if (language) extra.language = language
            const res = await apiUpload('/templates', file, extra)
            _pendingTemplateFile = null
            const newTemplateId = res.template?.id
            if (newTemplateId) {
              const existing = (c.template_ids || []).slice()
              if (!existing.includes(newTemplateId)) {
                existing.push(newTemplateId)
                await api(`/forms/${c.id}`, {
                  method: 'PUT',
                  body: JSON.stringify({ template_ids: existing }),
                })
              }
            }
            showToast(T('app.saved'))
            // Surface template-doctor findings inline so the customer can
            // fix layout risks BEFORE the first generation breaks the
            // output. Errors are always shown; warnings/infos are folded
            // into a single summary line so we never spam.
            const lint = res.template?.lint
            if (lint && Array.isArray(lint.findings) && lint.findings.length) {
              const errs = lint.findings.filter((f) => f.severity === 'error')
              const warns = lint.findings.filter((f) => f.severity === 'warning')
              const infos = lint.findings.filter((f) => f.severity === 'info')
              if (errs.length) {
                showTemplateLintDialog(res.template, lint)
              } else {
                const summary = []
                if (warns.length) summary.push(`${warns.length} ${T('templates.lint_warnings')}`)
                if (infos.length) summary.push(`${infos.length} ${T('templates.lint_infos')}`)
                if (summary.length) {
                  showToast(
                    `${T('templates.lint_uploaded_with_findings')} (${summary.join(', ')})`,
                    'info'
                  )
                }
              }
            }
            await Promise.all([fetchTemplates(), fetchForms()])
            render()
          } catch (err) {
            showToast(err.message, 'error')
            uploadBtn.disabled = false
            uploadBtn.innerHTML = `${ICONS.upload} ${T('templates.upload')}`
          }
        })
      }

      el.querySelectorAll('[data-download-template]').forEach((btn) =>
        btn.addEventListener('click', (e) => {
          e.stopPropagation()
          const a = document.createElement('a')
          a.href = `${BASE}/templates/${btn.dataset.downloadTemplate}/download`
          a.download = ''
          document.body.appendChild(a)
          a.click()
          a.remove()
        })
      )

      el.querySelectorAll('[data-template-language-set]').forEach((sel) =>
        sel.addEventListener('change', async () => {
          const tid = sel.dataset.templateLanguageSet
          const language = sel.value || ''
          const original = sel.dataset.previousValue ?? ''
          sel.disabled = true
          try {
            await api(`/templates/${tid}`, {
              method: 'PATCH',
              body: JSON.stringify({ language }),
            })
            sel.dataset.previousValue = language
            await fetchTemplates()
            showToast(T('templates.language_updated'))
          } catch (err) {
            sel.value = original
            showToast(err.message, 'error')
          } finally {
            sel.disabled = false
          }
        })
      )
      el.querySelectorAll('[data-template-language-set]').forEach((sel) => {
        sel.dataset.previousValue = sel.value || ''
      })

      el.querySelectorAll('[data-detach-template]').forEach((btn) =>
        btn.addEventListener('click', async (e) => {
          e.stopPropagation()
          if (!confirm(T('templates.confirm_delete'))) return
          const tid = btn.dataset.detachTemplate
          try {
            const remaining = (c.template_ids || []).filter((id) => id !== tid)
            await api(`/forms/${c.id}`, {
              method: 'PUT',
              body: JSON.stringify({ template_ids: remaining }),
            })
            await api(`/templates/${tid}`, { method: 'DELETE' })
            showToast(T('app.saved'))
            await Promise.all([fetchTemplates(), fetchForms()])
            render()
          } catch (err) {
            showToast(err.message, 'error')
          }
        })
      )

      el.querySelectorAll('[data-action="template-check-match"]').forEach((btn) =>
        btn.addEventListener('click', async () => {
          const tid = btn.dataset.templateId
          try {
            const [tData, pData] = await Promise.all([
              api(`/templates/${tid}`),
              api(`/templates/${tid}/placeholders`),
            ])
            state.selectedTemplateDetail = {
              ...tData.template,
              placeholders: pData.placeholders || [],
            }
            state.templateMatchAddKey = null
            render()
          } catch (err) {
            showToast(err.message, 'error')
          }
        })
      )

      el.querySelector('[data-action="template-match-close"]')?.addEventListener('click', () => {
        state.selectedTemplateDetail = null
        state.templateMatchAddKey = null
        render()
      })

      el.querySelectorAll('[data-action="template-match-add"]').forEach((btn) =>
        btn.addEventListener('click', () => {
          state.templateMatchAddKey = btn.dataset.key
          render()
        })
      )
      el.querySelector('[data-action="template-match-cancel"]')?.addEventListener('click', () => {
        state.templateMatchAddKey = null
        render()
      })
      el.querySelectorAll('[data-action="template-match-save"]').forEach((btn) =>
        btn.addEventListener('click', async () => {
          const key = btn.dataset.key
          const container = el.querySelector(`[data-add-form-key="${key}"]`)
          if (!container) return
          const label = container.querySelector('[name="add_label"]')?.value?.trim() || key
          const type = container.querySelector('[name="add_type"]')?.value || 'text'
          const hint = container.querySelector('[name="add_hint"]')?.value?.trim() || ''
          const newField = {
            key,
            label,
            type,
            required: false,
            source: 'ai',
            hint,
          }
          const existing = c.fields || []
          const next = [...existing, newField]
          try {
            await api(`/forms/${c.id}`, {
              method: 'PUT',
              body: JSON.stringify({ fields: next }),
            })
            state.templateMatchAddKey = null
            await fetchForms()
            showToast(T('app.saved'))
            render()
          } catch (err) {
            showToast(err.message, 'error')
          }
        })
      )
    }

    function handleTemplateFile(file) {
      _pendingTemplateFile = file
      const form = el.querySelector('#tx-template-upload-form')
      const nameInput = el.querySelector('#tx-template-name')
      if (form) form.classList.remove('hidden')
      if (nameInput && !nameInput.value) nameInput.value = file.name.replace(/\.docx$/i, '')
    }

    // --- Dashboard (system status card) events ---

    function bindDashboardEvents() {
      // Only fetch on the overview tab. Lazy / cached: fetchDashboard()
      // skips the network call when fresh data is already in memory.
      // Lazy-load the dashboard only when the Settings tab is on screen
      // — that's the only place the System Info card is rendered now.
      const wantsDashboard = state.view === 'settings'
      if (wantsDashboard && !state.dashboard && !state.dashboardLoading) {
        fetchDashboard(false)
      }
      el.querySelector('[data-action="dashboard-refresh"]')?.addEventListener('click', () => {
        fetchDashboard(true)
      })

      // "Configure model" / "Change model" buttons inside the System
      // Info card. Each one points at the synaplan AI Models settings
      // page with the right capability highlighted (ANALYZE / PIC2TEXT).
      // We use pushState + a synthetic popstate so the host SPA's
      // Vue Router picks up the change without a full reload.
      el.querySelectorAll('[data-action="open-ai-settings"]').forEach((btn) =>
        btn.addEventListener('click', () => {
          const cap = btn.dataset.capability || 'ALL'
          const target = `/config/ai-models?highlight=${encodeURIComponent(cap)}`
          try {
            window.history.pushState({}, '', target)
            window.dispatchEvent(new PopStateEvent('popstate'))
          } catch (_e) {
            window.location.href = target
          }
        })
      )
    }

    // --- Datasets events ---

    function bindDatasetsEvents() {
      const c = collectionById(state.collectionId)
      if (!c) return

      el.querySelector('[data-action="new-dataset"]')?.addEventListener('click', () => {
        state.newDatasetOpen = true
        state.selectedDataset = null
        // The new-dataset form is rendered by the datasets tab, so if the user
        // is on a different tab (e.g. overview), switch so the form actually
        // appears. navigate() updates the hash and re-renders.
        if (state.tab !== 'datasets') {
          navigate({
            view: 'collection',
            collectionId: state.collectionId,
            tab: 'datasets',
          })
        } else {
          render()
        }
      })
      // "Continue last" — jump straight back into the most recent
      // unfinished dataset (driven by findContinuableDataset). Mirrors
      // selectDataset behaviour so URL and state stay in sync.
      el.querySelector('[data-action="continue-last-dataset"]')?.addEventListener(
        'click',
        async (e) => {
          const id = e.currentTarget.dataset.id
          state.datasetId = id
          writeHash()
          await selectDataset(id)
          render()
        }
      )
      el.querySelector('[data-action="datasets-back"]')?.addEventListener('click', () => {
        state.selectedDataset = null
        state.newDatasetOpen = false
        state.datasetId = null
        state.datasetTab = 'sources'
        writeHash()
        render()
      })

      // Dataset-detail tabs. Bound to a dedicated attribute so clicks
      // don't collide with the global [data-tab] handler that switches
      // collection-level tabs.
      el.querySelectorAll('[data-dataset-tab]').forEach((btn) =>
        btn.addEventListener('click', (e) => {
          e.preventDefault()
          e.stopPropagation()
          const next = btn.dataset.datasetTab
          if (!next || state.datasetTab === next) return
          state.datasetTab = next
          render()
        })
      )

      el.querySelectorAll('[data-open-dataset]').forEach((row) =>
        row.addEventListener('click', () => {
          const id = row.dataset.openDataset
          state.datasetId = id
          navigate({
            view: 'collection',
            collectionId: c.id,
            tab: 'datasets',
            datasetId: id,
          })
        })
      )

      el.querySelectorAll('[data-delete-dataset]').forEach((btn) =>
        btn.addEventListener('click', async (e) => {
          e.stopPropagation()
          if (!confirm(T('datasets.confirm_delete'))) return
          try {
            await api(`/candidates/${btn.dataset.deleteDataset}`, {
              method: 'DELETE',
            })
            state.selectedDataset = null
            state.datasetId = null
            await fetchDatasets()
            showToast(T('app.saved'))
            render()
          } catch (err) {
            showToast(err.message, 'error')
          }
        })
      )

      // GDPR retention dropdown on the dataset detail header.
      el.querySelector('[data-action="set-retention"]')?.addEventListener('change', async (e) => {
        const d = state.selectedDataset
        if (!d) return
        const months = parseInt(e.target.value, 10) || 0
        try {
          const res = await api(`/candidates/${d.id}`, {
            method: 'PUT',
            body: JSON.stringify({
              delete_after_months: months > 0 ? months : null,
            }),
          })
          state.selectedDataset = res.candidate
          await fetchDatasets()
          showToast(T('datasets.retention_updated'))
          render()
        } catch (err) {
          showToast(err.message, 'error')
        }
      })

      // Overdue banner — Cancel just hides the banner for the current
      // session. Delete now batch-DELETEs every dataset whose computed
      // expires_at is in the past, after a confirm() guard.
      el.querySelector('[data-action="retention-dismiss"]')?.addEventListener('click', () => {
        state.retentionBannerDismissed = true
        render()
      })
      el.querySelector('[data-action="retention-delete-overdue"]')?.addEventListener(
        'click',
        async () => {
          const overdue = (state.datasets || []).filter(datasetIsOverdue)
          if (!overdue.length) return
          if (
            !confirm(
              Tf('datasets.retention_delete_confirm', {
                count: overdue.length,
              })
            )
          )
            return
          let okCount = 0
          for (const d of overdue) {
            try {
              await api(`/candidates/${d.id}`, { method: 'DELETE' })
              okCount++
            } catch (err) {
              showToast(err.message, 'error')
            }
          }
          state.retentionBannerDismissed = false
          await fetchDatasets()
          showToast(Tf('datasets.retention_delete_done', { count: okCount }))
          render()
        }
      )

      // Datasets search
      const ds = el.querySelector('#tx-datasets-search')
      if (ds) {
        ds.addEventListener('input', () => {
          state.datasetsSearch = ds.value
          state.datasetsPage = 0
          render()
          const again = el.querySelector('#tx-datasets-search')
          if (again) {
            again.focus()
            again.selectionStart = again.selectionEnd = again.value.length
          }
        })
      }
      el.querySelector('#tx-datasets-sort')?.addEventListener('click', () => {
        state.datasetsSortNewest = !state.datasetsSortNewest
        state.datasetsPage = 0
        render()
      })
      el.querySelectorAll('[data-page]').forEach((btn) =>
        btn.addEventListener('click', () => {
          state.datasetsPage = parseInt(btn.dataset.page)
          render()
        })
      )
      el.querySelector('[data-page-prev]')?.addEventListener('click', () => {
        if (state.datasetsPage > 0) {
          state.datasetsPage--
          render()
        }
      })
      el.querySelector('[data-page-next]')?.addEventListener('click', () => {
        state.datasetsPage++
        render()
      })

      // New dataset
      const ndForm = el.querySelector('#tx-new-dataset-form')
      if (ndForm) {
        ndForm.addEventListener('submit', async (e) => {
          e.preventDefault()
          const name = new FormData(ndForm).get('name')?.toString().trim()
          if (!name) return
          try {
            const res = await api('/candidates', {
              method: 'POST',
              body: JSON.stringify({
                form_id: c.id,
                field_values: {},
                name,
              }),
            })
            state.newDatasetOpen = false
            await fetchDatasets()
            await selectDataset(res.candidate.id)
            navigate({
              view: 'collection',
              collectionId: c.id,
              tab: 'datasets',
              datasetId: res.candidate.id,
            })
          } catch (err) {
            showToast(err.message, 'error')
          }
        })
      }

      // Dataset detail actions
      bindDatasetDetailEvents(c)
    }

    function bindDatasetDetailEvents(c) {
      const d = state.selectedDataset
      if (!d) return

      // Save form data
      const dataForm = el.querySelector('#tx-entry-data-form')
      if (dataForm && !state.reorderingFields) {
        dataForm.addEventListener('submit', async (e) => {
          e.preventDefault()
          // In-flight guards (v4.0). Block a save while auto-fill is
          // running (they both PUT field_values → last-write-wins), and
          // ignore a double-submit while a save is already in flight.
          if (state.datasetParsing) {
            showToast(T('datasets.save_blocked_parsing'), 'warning')
            return
          }
          if (state.datasetSaving) return
          const fields = c.fields || []
          const values = {}
          for (const f of fields) {
            if (f.type === 'table') {
              const cols = f.columns || []
              const rows = []
              let ri = 0
              while (dataForm.querySelector(`[name="${f.key}__${ri}__${cols[0]?.key}"]`)) {
                const row = {}
                for (const col of cols) {
                  const cell = dataForm.querySelector(`[name="${f.key}__${ri}__${col.key}"]`)
                  const raw = cell?.value ?? ''
                  if ((col.type || 'text') === 'list') {
                    row[col.key] = raw
                      .split(/\r?\n/)
                      .map((s) => s.trim())
                      .filter(Boolean)
                  } else {
                    row[col.key] = raw
                  }
                }
                // WS-G: persist the per-row "relevant" checkbox.
                const selEl = dataForm.querySelector(`[name="${f.key}__${ri}__selected"]`)
                if (selEl) row.selected = selEl.checked
                rows.push(row)
                ri++
              }
              values[f.key] = rows
              continue
            }
            const input = dataForm.querySelector(`[name="${f.key}"]`)
            if (!input) continue
            if (f.type === 'checkbox') values[f.key] = input.checked
            else if (f.type === 'list')
              values[f.key] = input.value
                .split('\n')
                .map((s) => s.trim())
                .filter(Boolean)
            else values[f.key] = input.value
          }
          // Save button pending/success animation. The customer had to
          // press Save twice because there was no feedback that a
          // (sometimes slow) server round-trip was in progress; they
          // clicked again, which could race the first request. We drive
          // the button state directly on the DOM node so we don't
          // re-render mid-save (which would discard unsaved edits).
          const saveBtn = dataForm.querySelector('button[type="submit"]')
          const saveBtnHtml = saveBtn ? saveBtn.innerHTML : ''
          state.datasetSaving = true
          if (saveBtn) {
            saveBtn.disabled = true
            saveBtn.innerHTML = `<span class="animate-spin inline-block w-3 h-3 border-2 border-white border-t-transparent rounded-full align-middle"></span> ${T('datasets.save_saving')}`
          }
          try {
            const computed =
              values.firstname || values.lastname
                ? `${values.firstname || ''} ${values.lastname || ''}`.trim()
                : d.name
            await api(`/candidates/${d.id}`, {
              method: 'PUT',
              body: JSON.stringify({ field_values: values, name: computed }),
            })
            if (saveBtn) saveBtn.innerHTML = `${ICONS.check} ${T('datasets.save_saved')}`
            showToast(T('app.saved'))
            const upd = await api(`/candidates/${d.id}`)
            state.selectedDataset = upd.candidate
            state.datasetSaving = false
            render()
          } catch (err) {
            state.datasetSaving = false
            if (saveBtn) {
              saveBtn.disabled = false
              saveBtn.innerHTML = saveBtnHtml
            }
            showToast(err.message, 'error')
          }
        })
      }

      // Pull the CURRENT table cell values out of the DOM into
      // d.field_values before any row mutation (add / remove / move).
      // Row actions trigger a full re-render, which would otherwise
      // silently discard every unsaved cell edit in the table.
      function harvestTableRowsFromDom(fieldKey) {
        const form = el.querySelector('#tx-entry-data-form')
        const f = (c.fields || []).find((x) => x.key === fieldKey)
        if (!form || !f) return
        const cols = f.columns || []
        if (!cols.length) return
        const rows = []
        let ri = 0
        while (form.querySelector(`[name="${fieldKey}__${ri}__${cols[0].key}"]`)) {
          const row = {}
          for (const col of cols) {
            const cell = form.querySelector(`[name="${fieldKey}__${ri}__${col.key}"]`)
            const raw = cell?.value ?? ''
            if ((col.type || 'text') === 'list') {
              row[col.key] = raw
                .split(/\r?\n/)
                .map((s) => s.trim())
                .filter(Boolean)
            } else {
              row[col.key] = raw
            }
          }
          rows.push(row)
          ri++
        }
        if (!d.field_values) d.field_values = {}
        d.field_values[fieldKey] = rows
      }

      // Snapshot the ENTIRE Edit-Details form (scalars, lists,
      // checkboxes AND every table) out of the DOM into d.field_values
      // before any action that triggers a re-render. The previous
      // harvest only rescued the table being mutated, so editing a
      // scalar field and then adding/moving a station row silently
      // discarded the scalar edit — the customer's "Save only kept the
      // Stations changes, I have to press Save twice" bug.
      function harvestAllFormValues() {
        const form = el.querySelector('#tx-entry-data-form')
        if (!form) return
        if (!d.field_values) d.field_values = {}
        for (const f of c.fields || []) {
          if (f.type === 'table') {
            const cols = f.columns || []
            if (!cols.length) continue
            const rows = []
            let ri = 0
            while (form.querySelector(`[name="${f.key}__${ri}__${cols[0].key}"]`)) {
              const row = {}
              for (const col of cols) {
                const cell = form.querySelector(`[name="${f.key}__${ri}__${col.key}"]`)
                const raw = cell?.value ?? ''
                row[col.key] =
                  (col.type || 'text') === 'list'
                    ? raw
                        .split(/\r?\n/)
                        .map((s) => s.trim())
                        .filter(Boolean)
                    : raw
              }
              // WS-G: persist the per-row "relevant" checkbox.
              const selEl = form.querySelector(`[name="${f.key}__${ri}__selected"]`)
              if (selEl) row.selected = selEl.checked
              rows.push(row)
              ri++
            }
            d.field_values[f.key] = rows
            continue
          }
          const input = form.querySelector(`[name="${f.key}"]`)
          if (!input) continue
          if (f.type === 'checkbox') d.field_values[f.key] = input.checked
          else if (f.type === 'list')
            d.field_values[f.key] = input.value
              .split('\n')
              .map((s) => s.trim())
              .filter(Boolean)
          else d.field_values[f.key] = input.value
        }
      }

      el.querySelectorAll('[data-action="add-table-row"]').forEach((btn) =>
        btn.addEventListener('click', () => {
          const key = btn.dataset.fieldKey
          harvestAllFormValues()
          if (!d.field_values) d.field_values = {}
          if (!Array.isArray(d.field_values[key])) d.field_values[key] = []
          d.field_values[key].push({})
          render()
        })
      )
      el.querySelectorAll('[data-action="remove-table-row"]').forEach((btn) =>
        btn.addEventListener('click', () => {
          const key = btn.dataset.fieldKey
          const ri = parseInt(btn.dataset.rowIdx)
          harvestAllFormValues()
          if (!d.field_values?.[key]) return
          d.field_values[key].splice(ri, 1)
          render()
        })
      )
      el.querySelectorAll('[data-action="move-table-row"]').forEach((btn) =>
        btn.addEventListener('click', () => {
          const key = btn.dataset.fieldKey
          const ri = parseInt(btn.dataset.rowIdx)
          const dir = parseInt(btn.dataset.dir)
          harvestAllFormValues()
          const rows = d.field_values?.[key]
          if (!Array.isArray(rows)) return
          const ni = ri + dir
          if (ni < 0 || ni >= rows.length) return
          ;[rows[ri], rows[ni]] = [rows[ni], rows[ri]]
          render()
        })
      )

      el.querySelectorAll('#tx-entry-data-form table tbody').forEach((tbody) => {
        const firstRow = tbody.querySelector('tr[data-field-key]')
        if (!firstRow) return
        const key = firstRow.dataset.fieldKey
        enableDragReorder(tbody, {
          rowSelector: ':scope > tr[data-field-key]',
          handleSelector: '[data-drag-handle]',
          onDrop: (from, to) => {
            harvestAllFormValues()
            const rows = d.field_values?.[key]
            if (!Array.isArray(rows)) return
            const moved = rows.splice(from, 1)[0]
            rows.splice(to, 0, moved)
            render()
          },
        })
      })

      el.querySelector('[data-action="toggle-reorder"]')?.addEventListener('click', async () => {
        if (state.reorderingFields) {
          try {
            await api(`/forms/${c.id}`, {
              method: 'PUT',
              body: JSON.stringify({ fields: c.fields }),
            })
            showToast(T('variables.reorder_saved'))
            await fetchForms()
          } catch (err) {
            showToast(err.message, 'error')
          }
        }
        state.reorderingFields = !state.reorderingFields
        render()
      })

      el.querySelectorAll('[data-action="df-up"], [data-action="df-down"]').forEach((btn) =>
        btn.addEventListener('click', () => {
          if (!c.fields) return
          const idx = parseInt(btn.dataset.idx)
          const dir = btn.dataset.action === 'df-up' ? -1 : 1
          const n = idx + dir
          if (n < 0 || n >= c.fields.length) return
          ;[c.fields[idx], c.fields[n]] = [c.fields[n], c.fields[idx]]
          render()
        })
      )

      if (state.reorderingFields) {
        // Reorder-mode rows carry no inputs, so the whole row is draggable.
        enableDragReorder(el.querySelector('#tx-entry-data-form'), {
          rowSelector: ':scope > [data-field-idx]',
          onDrop: (from, to) => {
            if (!c.fields) return
            const moved = c.fields.splice(from, 1)[0]
            c.fields.splice(to, 0, moved)
            render()
          },
        })
      }

      // File upload — via the button AND by dropping files anywhere on the
      // Source Documents card (beta feedback: a drop target was expected here).
      async function uploadSourceFiles(fileList) {
        const files = Array.from(fileList || [])
        if (!files.length) return
        if (state.datasetUploading || state.datasetParsing) {
          showPageAlert({
            type: 'warning',
            title: T('datasets.upload_busy_title'),
            message: T('datasets.upload_busy'),
          })
          return
        }
        const rejected = []
        const accepted = []
        for (const f of files) {
          const why = validateSourceFile(f)
          if (why) rejected.push({ name: f.name, why })
          else accepted.push(f)
        }
        if (rejected.length) {
          showPageAlert({
            type: 'error',
            title: T('datasets.upload_rejected_title'),
            message: rejected.map((r) => `${r.name}: ${r.why}`).join(' · '),
          })
        }
        if (!accepted.length) return

        const hasCv = !!d.files?.cv
        const okNames = []
        const failNames = []
        state.datasetUploading = true
        state.datasetUploadTotal = accepted.length
        state.datasetUploadCurrent = 0
        state.datasetUploadPercent = 0
        state.datasetUploadBytesSent = 0
        state.datasetUploadBytesTotal = 0
        showPageAlert({
          type: 'info',
          title: T('datasets.upload_in_progress_title'),
          message: Tf('datasets.upload_in_progress', {
            current: 1,
            total: accepted.length,
            name: accepted[0].name,
            size: formatBytes(accepted[0].size),
          }),
        })
        render()
        try {
          for (let i = 0; i < accepted.length; i++) {
            const f = accepted[i]
            state.datasetUploadCurrent = i + 1
            state.datasetUploadFilename = f.name
            state.datasetUploadPercent = 0
            state.datasetUploadBytesSent = 0
            state.datasetUploadBytesTotal = f.size || 0
            showPageAlert({
              type: 'info',
              title: T('datasets.upload_in_progress_title'),
              message: Tf('datasets.upload_in_progress', {
                current: i + 1,
                total: accepted.length,
                name: f.name,
                size: formatBytes(f.size),
              }),
            })
            try {
              const dest =
                i === 0 && !hasCv
                  ? `/candidates/${d.id}/upload-cv`
                  : `/candidates/${d.id}/upload-doc`
              await apiUpload(
                dest,
                f,
                {},
                {
                  timeoutMs: uploadTimeoutMsFor(f),
                  onProgress: (p) => {
                    state.datasetUploadPercent = p.percent
                    state.datasetUploadBytesSent = p.loaded
                    state.datasetUploadBytesTotal = p.total
                    const bar = el.querySelector('#tx-upload-pct')
                    const label = el.querySelector('#tx-upload-pct-label')
                    if (bar) bar.style.width = `${p.percent}%`
                    if (label)
                      label.textContent = `${p.percent}% · ${formatBytes(p.loaded)} / ${formatBytes(p.total)}`
                  },
                }
              )
              okNames.push(f.name)
            } catch (err) {
              failNames.push(`${f.name}: ${err.message || err}`)
            }
          }
          const upd = await api(`/candidates/${d.id}`)
          state.selectedDataset = upd.candidate
          if (failNames.length && !okNames.length) {
            showPageAlert({
              type: 'error',
              title: T('datasets.upload_failed_title'),
              message: failNames.join(' · '),
            })
          } else if (failNames.length) {
            showPageAlert({
              type: 'warning',
              title: T('datasets.upload_partial_title'),
              message: Tf('datasets.upload_partial', {
                ok: okNames.length,
                fail: failNames.length,
                errors: failNames.join(' · '),
              }),
            })
            showToast(T('datasets.doc_uploaded'), 'warning')
          } else {
            showPageAlert({
              type: 'success',
              title: T('datasets.upload_ok_title'),
              message: Tf('datasets.upload_ok', {
                count: okNames.length,
                names: okNames.join(', '),
              }),
            })
            showToast(T('datasets.doc_uploaded'))
          }
        } catch (err) {
          showPageAlert({
            type: 'error',
            title: T('datasets.upload_failed_title'),
            message: err.message || String(err),
          })
        }
        state.datasetUploading = false
        state.datasetUploadCurrent = 0
        state.datasetUploadTotal = 0
        state.datasetUploadFilename = ''
        state.datasetUploadPercent = 0
        render()
      }
      const uploader = el.querySelector('#tx-doc-upload')
      if (uploader) {
        uploader.addEventListener('change', () => uploadSourceFiles(uploader.files))
      }
      const sourceCard = el.querySelector('#tx-source-card')
      if (sourceCard) {
        const clearDropHint = () => {
          sourceCard.style.outline = ''
          sourceCard.style.outlineOffset = ''
        }
        sourceCard.addEventListener('dragover', (e) => {
          e.preventDefault()
          sourceCard.style.outline = '2px dashed var(--brand)'
          sourceCard.style.outlineOffset = '2px'
        })
        sourceCard.addEventListener('dragleave', (e) => {
          // Only clear when the pointer actually leaves the card, not when it
          // moves over a child element.
          if (!sourceCard.contains(e.relatedTarget)) clearDropHint()
        })
        sourceCard.addEventListener('drop', (e) => {
          e.preventDefault()
          clearDropHint()
          const files = e.dataTransfer?.files
          if (files && files.length) uploadSourceFiles(files)
        })
      }

      el.querySelectorAll('[data-action="delete-source-file"]').forEach((btn) =>
        btn.addEventListener('click', async () => {
          if (!confirm(T('datasets.confirm_delete_source_file'))) return
          try {
            const upd = await api(
              `/candidates/${d.id}/files/${btn.dataset.slot}/${btn.dataset.slotIndex}`,
              { method: 'DELETE' }
            )
            state.selectedDataset = upd.candidate
            showToast(T('datasets.source_file_deleted'))
            render()
          } catch (err) {
            showToast(err.message, 'error')
          }
        })
      )

      // URL source actions
      el.querySelector('[data-action="toggle-url-form"]')?.addEventListener('click', () => {
        state.urlFormOpen = !state.urlFormOpen
        state.urlAddError = null
        render()
        if (state.urlFormOpen) {
          setTimeout(() => el.querySelector('#tx-url-input')?.focus(), 50)
        }
      })
      el.querySelector('[data-action="cancel-url"]')?.addEventListener('click', () => {
        state.urlFormOpen = false
        state.urlAddError = null
        render()
      })
      el.querySelector('[data-action="save-url"]')?.addEventListener('click', async () => {
        const urlInput = el.querySelector('#tx-url-input')
        const labelInput = el.querySelector('#tx-url-label')
        const url = urlInput?.value?.trim()
        const label = labelInput?.value?.trim() || ''
        if (!url) {
          state.urlAddError = T('datasets.url_add_required')
          render()
          return
        }
        state.urlAdding = true
        state.urlAddError = null
        render()
        try {
          const res = await api(`/candidates/${d.id}/urls`, {
            method: 'POST',
            body: JSON.stringify({ url, label }),
          })
          state.selectedDataset = res.candidate
          state.urlFormOpen = false
          state.urlAddError = null
          showToast(T('datasets.url_added'))
          const hasSnippet = res.url?.text_snippet
          if (!hasSnippet && res.url?.fetch_error) {
            // Not a hard failure — the URL is still attached as a
            // reference. A toast plus the inline subtitle on the
            // row gives the user the actionable guidance (e.g.
            // "Save the LinkedIn profile as PDF") without yelling
            // at them with a red error toast.
            showToast(
              Tf('datasets.url_fetch_warning', {
                error: res.url.fetch_error,
              }),
              'warning'
            )
          }
        } catch (err) {
          state.urlAddError = err.message
        }
        state.urlAdding = false
        render()
      })
      el.querySelectorAll('[data-action="refresh-url"]').forEach((btn) =>
        btn.addEventListener('click', async () => {
          const urlIndex = parseInt(btn.dataset.urlIndex)
          try {
            await api(`/candidates/${d.id}/urls/${urlIndex}/refresh`, {
              method: 'POST',
            })
            const upd = await api(`/candidates/${d.id}`)
            state.selectedDataset = upd.candidate
            showToast(T('datasets.url_refreshed'))
            render()
          } catch (err) {
            showToast(err.message, 'error')
          }
        })
      )

      // Extract
      el.querySelector('[data-action="extract"]')?.addEventListener('click', async () => {
        state.datasetExtracting = true
        render()
        await refreshAccessToken()
        try {
          await api(`/candidates/${d.id}/extract`, {
            method: 'POST',
            timeoutMs: 240000,
          })
          const upd = await api(`/candidates/${d.id}`)
          state.selectedDataset = upd.candidate
          await loadDatasetVariables(d.id)
        } catch (err) {
          showToast(err.message, 'error')
        }
        state.datasetExtracting = false
        render()
      })

      // Parse documents — single combined action that runs the AI form
      // auto-fill (parse-documents) and the variable-level extraction
      // (extract) in parallel. We surface a live elapsed-time + adaptive
      // progress bar so users know that a slow image OCR or AI roundtrip
      // is still in progress rather than hung.
      // `onlyMissing` runs the incremental variant (?onlyMissing=1): the
      // backend re-extracts ONLY still-empty fields (optionally with the
      // configured second-opinion model) and never overwrites filled ones.
      async function runParseDocuments(onlyMissing = false) {
        // In-flight guard: never let auto-fill race a manual save
        // (last-write-wins would silently drop one of them).
        if (state.datasetSaving) {
          showToast(T('datasets.parse_blocked_saving'), 'warning')
          showPageAlert({
            type: 'warning',
            title: T('datasets.parse_blocked_saving'),
            message: '',
          })
          return
        }
        if (state.datasetUploading) {
          showPageAlert({
            type: 'warning',
            title: T('datasets.upload_busy_title'),
            message: T('datasets.upload_busy'),
          })
          return
        }
        const imageExts = [
          'jpg',
          'jpeg',
          'png',
          'gif',
          'webp',
          'tif',
          'tiff',
          'bmp',
          'heic',
          'heif',
        ]
        const allFiles = []
        if (d.files?.cv?.filename) allFiles.push(d.files.cv.filename)
        for (const f of d.files?.additional || []) {
          if (f?.filename) allFiles.push(f.filename)
        }
        const urlCount = (d.files?.urls || []).length
        const fileCount = allFiles.length + urlCount
        const hasImages = allFiles.some((name) => {
          const dot = name.lastIndexOf('.')
          if (dot < 0) return false
          return imageExts.includes(name.slice(dot + 1).toLowerCase())
        })
        // Build a live, per-variable status line from the collection's
        // own field labels so the user sees concrete progress ("Looking
        // for «Vorname»…", "Sorting table entries: «Stations»…") rather
        // than a single static spinner. The backend POST is opaque (no
        // streaming), so we drive the line on the elapsed-time timer.
        const parseColl = collectionById(d.form_id)
        const parseFields = (parseColl && parseColl.fields) || []
        const statusMsgs = [T('datasets.analyze_status_reading')]
        for (const f of parseFields) {
          const name = (f && (f.label || f.key)) || ''
          if (!name) continue
          statusMsgs.push(
            f && f.type === 'table'
              ? Tf('datasets.analyze_status_table', { name })
              : Tf('datasets.analyze_status_field', { name })
          )
        }
        statusMsgs.push(T('datasets.analyze_status_finalizing'))

        state.datasetParsing = true
        state.datasetParseStep = 0
        state.datasetParseStartedAt = Date.now()
        state.datasetParseElapsedSec = 0
        state.datasetParseFileCount = fileCount
        state.datasetParseHasImages = hasImages
        state.datasetParseStatusMsgs = statusMsgs
        state.datasetParseStatusIdx = 0
        if (state.datasetParseTimer) clearInterval(state.datasetParseTimer)
        state.datasetParseTimer = setInterval(() => {
          if (!state.datasetParsing || !state.datasetParseStartedAt) return
          state.datasetParseElapsedSec = Math.round(
            (Date.now() - state.datasetParseStartedAt) / 1000
          )
          // Walk the live status line through the field list while we wait,
          // but hold on the last field message (one before "finalizing")
          // until the request actually returns — at which point step 3
          // pins it to the closing "finalizing/matching" message.
          const msgs = state.datasetParseStatusMsgs || []
          if (msgs.length > 2 && state.datasetParseStatusIdx < msgs.length - 2) {
            state.datasetParseStatusIdx += 1
          }
          render()
        }, 1000)
        render()
        showPageAlert({
          type: 'info',
          title: T('datasets.parse_in_progress_title'),
          message: T('datasets.analyze_dont_close'),
        })
        await new Promise((r) => setTimeout(r, 250))
        state.datasetParseStep = 1
        render()
        await refreshAccessToken()
        let parseRes = null
        try {
          state.datasetParseStep = 2
          render()
          const MAX_ROUNDS = 4
          for (let round = 1; round <= MAX_ROUNDS; round++) {
            if (round > 1) {
              showPageAlert({
                type: 'info',
                title: T('datasets.parse_continue_title'),
                message: Tf('datasets.parse_continue', { round }),
              })
              await new Promise((r) => setTimeout(r, 2500))
            }
            try {
              parseRes = await api(
                `/candidates/${d.id}/parse-documents${onlyMissing ? '?onlyMissing=1' : ''}`,
                {
                  method: 'POST',
                  // Just over Cloudflare's ~100s proxy timeout so we receive
                  // a 524/JSON deadline instead of hanging for minutes.
                  timeoutMs: 110000,
                }
              )
            } catch (err) {
              if (round < MAX_ROUNDS && isRetryableParseFailure(err)) {
                showPageAlert({
                  type: 'warning',
                  title: T('datasets.parse_continue_title'),
                  message: Tf('datasets.parse_continue_after_timeout', {
                    round: round + 1,
                  }),
                })
                continue
              }
              throw err
            }
            if ((parseRes?.deadline_hit || parseRes?.ocr_incomplete) && round < MAX_ROUNDS) {
              continue
            }
            break
          }
          state.datasetParseStep = 3
          // Pin the live status line to the closing "matching variables"
          // message now that both AI round-trips have returned.
          if ((state.datasetParseStatusMsgs || []).length) {
            state.datasetParseStatusIdx = state.datasetParseStatusMsgs.length - 1
          }
          render()
          let appliedFields = 0
          let outcome = parseRes?.outcome || null
          if (parseRes && parseRes.success && parseRes.suggestions) {
            const sug = parseRes.suggestions
            // Defensive guard: a malformed AI response (top-level
            // array, primitive, etc.) used to be silently spread
            // into field_values, producing numeric-keyed garbage
            // that broke template generation. Treat anything that
            // isn't a plain object as a parse failure.
            const isPlainObject =
              sug &&
              typeof sug === 'object' &&
              !Array.isArray(sug) &&
              Object.prototype.toString.call(sug) === '[object Object]'
            const baseline =
              d.field_values && typeof d.field_values === 'object' && !Array.isArray(d.field_values)
                ? d.field_values
                : {}
            if (!isPlainObject) {
              showPageAlert({
                type: 'warning',
                title: T('datasets.parse_outcome_failed'),
                message: T('datasets.parse_unusable_shape'),
              })
              outcome = 'failed'
            } else {
              const merged = { ...baseline }
              for (const [k, v] of Object.entries(sug)) {
                if (v !== null && v !== undefined && v !== '') {
                  merged[k] = v
                  appliedFields += 1
                }
              }
              await api(`/candidates/${d.id}`, {
                method: 'PUT',
                body: JSON.stringify({ field_values: merged }),
              })
            }
          } else if (parseRes && !parseRes.success) {
            const msg = parseRes.error || T('datasets.parse_failed')
            showPageAlert({
              type: 'warning',
              title: T('datasets.parse_outcome_failed'),
              message: msg,
            })
            outcome = 'failed'
          }
          // Group-extraction telemetry (v3.7.1+). Backend returns a
          // `groups` array describing which logical clusters of
          // fields succeeded, were skipped (already filled), or
          // failed. Console table stays for power users.
          if (parseRes && Array.isArray(parseRes.groups)) {
            try {
              console.table(
                parseRes.groups.map((g) => ({
                  group: g.label || g.key,
                  fields: `${g.fields_returned ?? 0}/${g.fields_in_group ?? 0}`,
                  status: g.skipped
                    ? `skipped (${g.reason || 'filled'})`
                    : g.succeeded
                      ? 'ok'
                      : `failed: ${g.error || 'unknown'}`,
                  elapsed_ms: g.elapsed_ms ?? 0,
                }))
              )
            } catch (_) {
              /* console.table missing */
            }
          }
          const upd = await api(`/candidates/${d.id}`)
          state.selectedDataset = upd.candidate
          await loadDatasetVariables(d.id)

          // Single authoritative toast from the persisted outcome —
          // never append a green "done" after warnings (that hid failures).
          const last = state.selectedDataset?.last_parse || parseRes?.last_parse
          outcome = (last && last.outcome) || outcome || 'complete'
          const filled = last?.fields_filled ?? parseRes?.fields_filled ?? appliedFields
          const total = last?.fields_total ?? parseRes?.fields_total ?? 0
          const sourcesOk = last?.sources_ok ?? parseRes?.files?.filter((f) => f.ok).length ?? 0
          const sourcesTotal = last?.sources_total ?? parseRes?.files?.length ?? fileCount
          const deadline = !!(parseRes?.deadline_hit || last?.deadline_hit)
          if (outcome === 'failed') {
            showPageAlert({
              type: 'error',
              title: T('datasets.parse_outcome_failed'),
              message: last?.error || parseRes?.error || T('datasets.parse_failed'),
            })
          } else if (deadline || outcome === 'partial') {
            const extra =
              Array.isArray(parseRes?.unreadable) && parseRes.unreadable.length
                ? ' ' +
                  Tf('datasets.parse_unreadable_warning', {
                    files: parseRes.unreadable.map((f) => f.filename).join(', '),
                  })
                : ''
            showPageAlert({
              type: 'warning',
              title: deadline
                ? T('datasets.parse_deadline_title')
                : T('datasets.parse_outcome_partial'),
              message:
                (deadline ? T('datasets.parse_deadline') + ' ' : '') +
                Tf('datasets.parse_outcome_partial_toast', {
                  sources_ok: sourcesOk,
                  sources_total: sourcesTotal,
                  filled,
                  total,
                }) +
                extra,
            })
          } else {
            showPageAlert({
              type: 'success',
              title: T('datasets.parse_outcome_complete'),
              message: Tf('datasets.parse_outcome_complete_toast', {
                filled,
                total: total || filled,
                sources: sourcesOk || sourcesTotal,
              }),
            })
            showToast(
              Tf('datasets.parse_outcome_complete_toast', {
                filled,
                total: total || filled,
                sources: sourcesOk || sourcesTotal,
              })
            )
          }
        } catch (err) {
          const payload = err && err.payload
          try {
            const upd = await api(`/candidates/${d.id}`)
            state.selectedDataset = upd.candidate
            await loadDatasetVariables(d.id)
          } catch (_) {
            /* ignore reload failure */
          }
          const last = state.selectedDataset?.last_parse || payload?.last_parse
          const failMsg =
            (last && last.outcome === 'failed' && last.error) ||
            (Array.isArray(payload?.unreadable) && payload.unreadable.length
              ? Tf('datasets.parse_unreadable_warning', {
                  files: payload.unreadable.map((f) => f.filename).join(', '),
                })
              : null) ||
            err.message ||
            T('datasets.parse_failed')
          showPageAlert({
            type: 'error',
            title: T('datasets.parse_outcome_failed'),
            message: failMsg,
          })
        }
        if (state.datasetParseTimer) {
          clearInterval(state.datasetParseTimer)
          state.datasetParseTimer = null
        }
        state.datasetParsing = false
        state.datasetParseStep = 0
        state.datasetParseStartedAt = null
        state.datasetParseElapsedSec = 0
        state.datasetParseFileCount = 0
        state.datasetParseHasImages = false
        state.datasetParseStatusMsgs = []
        state.datasetParseStatusIdx = 0
        render()
      }

      el.querySelector('[data-action="parse-documents"]')?.addEventListener('click', () =>
        runParseDocuments(false)
      )
      el.querySelector('[data-action="parse-missing"]')?.addEventListener('click', () =>
        runParseDocuments(true)
      )

      // Generate
      const genSel = el.querySelector('#tx-generate-template')
      if (genSel) {
        genSel.addEventListener('change', () => {
          state.selectedGenerateTemplate = genSel.value || null
          render()
        })
      }
      // Optional free-text instruction ("Mach das auf Italienisch", …) —
      // mirrored into state so the value survives the re-renders that the
      // template picker and the generating spinner trigger.
      const genInstr = el.querySelector('#tx-generate-instruction')
      if (genInstr) {
        genInstr.addEventListener('input', () => {
          state.generateInstruction = genInstr.value
        })
      }
      el.querySelector('[data-action="generate"]')?.addEventListener('click', async () => {
        if (!state.selectedGenerateTemplate) return
        const instruction = (state.generateInstruction || '').trim()
        state.datasetGenerating = true
        render()
        showPageAlert({
          type: 'info',
          title: T('datasets.generate_running'),
          message: T('datasets.analyze_dont_close'),
        })
        await refreshAccessToken()
        try {
          const gen = await api(`/candidates/${d.id}/generate/${state.selectedGenerateTemplate}`, {
            method: 'POST',
            timeoutMs: 240000,
            ...(instruction ? { body: JSON.stringify({ instruction }) } : {}),
          })
          const upd = await api(`/candidates/${d.id}`)
          state.selectedDataset = upd.candidate
          showToast(T('datasets.generate_done'))
          showPageAlert({
            type: 'success',
            title: T('datasets.generate_done'),
            message: T('datasets.download_doc'),
          })
          const newDocId = gen?.document?.id
          if (newDocId) {
            window.open(`${BASE}/candidates/${d.id}/documents/${newDocId}/download`, '_blank')
          }
        } catch (err) {
          showPageAlert({
            type: 'error',
            title: T('datasets.generate_failed_title'),
            message: err.message || String(err),
          })
        }
        state.datasetGenerating = false
        render()
      })

      el.querySelectorAll('[data-action="download-doc"]').forEach((btn) =>
        btn.addEventListener('click', () => {
          window.open(
            `${BASE}/candidates/${d.id}/documents/${btn.dataset.docId}/download`,
            '_blank'
          )
        })
      )
      // Uploaded source files: PDFs/images are served inline (browser
      // preview tab), other formats download as attachments.
      el.querySelectorAll('[data-action="download-source-file"]').forEach((btn) =>
        btn.addEventListener('click', () => {
          window.open(
            `${BASE}/candidates/${d.id}/files/${btn.dataset.slot}/${btn.dataset.slotIndex}/download`,
            '_blank'
          )
        })
      )
      el.querySelectorAll('[data-action="delete-doc"]').forEach((btn) =>
        btn.addEventListener('click', async () => {
          if (!confirm(T('datasets.confirm_delete_doc'))) return
          try {
            await api(`/candidates/${d.id}/documents/${btn.dataset.docId}`, {
              method: 'DELETE',
            })
            showToast(T('app.saved'))
            await selectDataset(d.id)
          } catch (err) {
            showToast(err.message, 'error')
          }
        })
      )

      // --- Image variable upload / remove ---
      el.querySelectorAll('[data-action="image-upload"]').forEach((inp) =>
        inp.addEventListener('change', async (ev) => {
          const file = ev.target?.files?.[0]
          if (!file) return
          const key = inp.dataset.key
          const why = validateSourceFile(file)
          if (why && file.size > 8 * 1024 * 1024) {
            showPageAlert({
              type: 'error',
              title: T('datasets.upload_failed_title'),
              message: why,
            })
            return
          }
          try {
            await refreshAccessToken()
            showPageAlert({
              type: 'info',
              title: T('datasets.upload_in_progress_title'),
              message: file.name,
            })
            await apiUpload(`/candidates/${d.id}/image/${encodeURIComponent(key)}`, file)
            showToast(T('datasets.image_uploaded'))
            showPageAlert({
              type: 'success',
              title: T('datasets.image_uploaded'),
              message: file.name,
            })
            await selectDataset(d.id)
          } catch (err) {
            showPageAlert({
              type: 'error',
              title: T('datasets.upload_failed_title'),
              message: err.message || String(err),
            })
          }
        })
      )
      el.querySelectorAll('[data-action="image-remove"]').forEach((btn) =>
        btn.addEventListener('click', async () => {
          const key = btn.dataset.key
          if (!confirm(T('datasets.image_confirm_remove'))) return
          try {
            await api(`/candidates/${d.id}/image/${encodeURIComponent(key)}`, {
              method: 'DELETE',
            })
            await selectDataset(d.id)
          } catch (err) {
            showToast(err.message || String(err), 'error')
          }
        })
      )

      // --- Live preview (Phase 4b) ---
      bindPreviewEvents(c, d)
    }

    function bindPreviewEvents(c, d) {
      // "Show preview" toggle lives in the dataset header, inside `el`.
      el.querySelector('[data-action="preview-show"]')?.addEventListener('click', () => {
        state.previewVisible = true
        render()
      })

      // Live input listener on the dataset form (delegated). Bind even when
      // the modal is closed so values stay in sync if/when the user opens it.
      const form = el.querySelector('#tx-entry-data-form')
      if (form) {
        const handler = () => schedulePreviewUpdate()
        form.addEventListener('input', handler)
        form.addEventListener('change', handler)
      }

      // The preview panel now lives in a body-level portal (see
      // renderModalPortal). When the modal is closed the portal is empty
      // and we have nothing else to bind.
      const panel = modalPortal?.querySelector('#tx-preview-panel')
      if (!panel) return

      const templates = collectionTemplates(c)
      if (templates.length === 0) return

      // Pick an initial template if we don't have one cached yet
      const validIds = templates.map((t) => t.id)
      let targetId = state.previewTemplateId
      if (!targetId || !validIds.includes(targetId)) {
        targetId = validIds[0]
      }

      // Kick off a fetch only when we have no skeleton for the target AND no
      // other fetch is in flight. The in-flight guard inside loadPreviewSkeleton
      // also prevents loops, but checking here avoids the redundant render().
      const needsLoad =
        (!state.previewSkeleton || state.previewTemplateId !== targetId) &&
        !state.previewLoading &&
        !state.previewError
      if (needsLoad) {
        loadPreviewSkeleton(targetId)
        // fall through: still bind the toolbar buttons so the user can act
        // while the fetch is pending (refresh, hide, pick a different template).
      }

      // Prime the DOM once on bind so the first render isn't blank (only if
      // the skeleton is actually present).
      if (state.previewSkeleton) {
        setTimeout(updatePreviewNow, 0)
      }

      // Template picker — always bind, even mid-fetch, so the user can switch.
      const picker = panel.querySelector('#tx-preview-template-picker')
      picker?.addEventListener('change', () => {
        const id = picker.value
        if (id && id !== state.previewTemplateId) {
          loadPreviewSkeleton(id, { force: true })
        }
      })

      // Refresh: re-fetch the skeleton (handles updated DOCX upload or prior error).
      panel.querySelector('[data-action="preview-refresh"]')?.addEventListener('click', () => {
        loadPreviewSkeleton(state.previewTemplateId || targetId, {
          force: true,
        })
      })

      // Hide — both the toolbar close button and the modal backdrop use
      // data-action="preview-hide". The modal body stops propagation so
      // clicks inside the panel don't bubble up and close the overlay.
      modalPortal?.querySelectorAll('[data-action="preview-hide"]').forEach((node) =>
        node.addEventListener('click', () => {
          state.previewVisible = false
          render()
        })
      )

      // --- True-preview (PDF) path ---
      panel
        .querySelector('[data-action="preview-render-pdf"]')
        ?.addEventListener('click', () => renderTruePreviewPdf(c, d))

      panel.querySelector('[data-action="preview-back-html"]')?.addEventListener('click', () => {
        if (state.previewPdfUrl) {
          URL.revokeObjectURL(state.previewPdfUrl)
        }
        state.previewPdfUrl = null
        state.previewPdfError = null
        render()
      })
    }

    async function renderTruePreviewPdf(c, d) {
      if (!d || !state.previewTemplateId) return
      const templateId = state.previewTemplateId

      state.previewPdfLoading = true
      state.previewPdfError = null
      state.previewPdfUnavailable = false
      render()

      try {
        // 1. Save the current form state so generation uses fresh values.
        const form = el.querySelector('#tx-entry-data-form')
        if (form) {
          // Synthesise a submit (the existing handler writes field_values).
          const submit = new Event('submit', {
            cancelable: true,
            bubbles: true,
          })
          form.dispatchEvent(submit)
          // Give the save + render a tick to complete
          await new Promise((r) => setTimeout(r, 150))
        }

        // 2. Generate the DOCX (persists as a "generated document" on the candidate).
        await refreshAccessToken()
        const gen = await api(`/candidates/${d.id}/generate/${templateId}`, {
          method: 'POST',
          timeoutMs: 240000,
        })
        const docId = gen?.document?.id
        if (!docId) throw new Error('Generation did not return a document id')

        // 3. Fetch the PDF. Response is a binary stream; turn into a blob URL.
        const pdfCtrl = typeof AbortController !== 'undefined' ? new AbortController() : null
        const pdfTimer = pdfCtrl ? setTimeout(() => pdfCtrl.abort(), 180000) : null
        let pdfResp
        try {
          pdfResp = await fetch(`${BASE}/candidates/${d.id}/documents/${docId}/pdf`, {
            credentials: 'include',
            signal: pdfCtrl ? pdfCtrl.signal : undefined,
          })
        } finally {
          if (pdfTimer) clearTimeout(pdfTimer)
        }
        if (pdfResp.status === 501) {
          const errJson = await pdfResp.json().catch(() => ({}))
          state.previewPdfUnavailable = true
          state.previewPdfError = errJson.error || T('preview.pdf_unavailable')
          throw new Error(state.previewPdfError)
        }
        if (!pdfResp.ok) {
          throw new Error(`PDF conversion failed (${pdfResp.status})`)
        }
        const blob = await pdfResp.blob()
        const url = URL.createObjectURL(blob)
        if (state.previewPdfUrl) URL.revokeObjectURL(state.previewPdfUrl)
        state.previewPdfUrl = url
      } catch (err) {
        if (!state.previewPdfUnavailable) {
          showToast(err.message || String(err), 'error')
        }
      }

      state.previewPdfLoading = false
      render()
    }

    // --- Export events ---

    function bindExportEvents() {
      const c = collectionById(state.collectionId)
      if (!c) return

      el.querySelector('#tx-export-status')?.addEventListener('change', (e) => {
        state.exportStatus = e.target.value
        render()
      })
      el.querySelector('#tx-export-search')?.addEventListener('input', (e) => {
        state.exportSearch = e.target.value
        render()
        const again = el.querySelector('#tx-export-search')
        if (again) {
          again.focus()
          again.selectionStart = again.selectionEnd = again.value.length
        }
      })
      el.querySelector('#tx-export-from')?.addEventListener('change', (e) => {
        state.exportFrom = e.target.value
        render()
      })
      el.querySelector('#tx-export-to')?.addEventListener('change', (e) => {
        state.exportTo = e.target.value
        render()
      })

      el.querySelector('[data-action="export-csv"]')?.addEventListener('click', () =>
        exportToCsv(c)
      )
    }

    async function exportToCsv(c) {
      const all = datasetsForCollection(c.id)
      const filtered = filterDatasetsForExport(all)
      if (filtered.length === 0) return

      const fields = c.fields || []
      const variableKeys = fields.map((f) => f.key)
      const columns = [
        'id',
        'name',
        'status',
        'created_at',
        'updated_at',
        'source_files',
        'generated_documents',
        ...variableKeys,
      ]

      // Load full datasets to resolve their variable values.
      const rows = []
      for (const d of filtered) {
        let full = d
        try {
          const res = await api(`/candidates/${d.id}`)
          full = res.candidate || d
        } catch (_) {
          // Fall back to the cached summary.
        }
        let resolved = {}
        try {
          const res = await api(`/candidates/${d.id}/variables`)
          resolved = res.variables || {}
        } catch (_) {
          resolved = full.field_values || {}
        }
        const fileCount = (full.files?.cv ? 1 : 0) + (full.files?.additional?.length || 0)
        const docCount = full.documents ? Object.keys(full.documents).length : 0
        const row = {
          id: full.id,
          name: datasetDisplayName(full),
          status: full.status || 'draft',
          created_at: full.created_at || '',
          updated_at: full.updated_at || '',
          source_files: fileCount,
          generated_documents: docCount,
        }
        for (const k of variableKeys) {
          const v = resolved[k] ?? full.field_values?.[k] ?? ''
          row[k] = Array.isArray(v)
            ? v
                .map((item) => (typeof item === 'object' ? JSON.stringify(item) : String(item)))
                .join(' | ')
            : typeof v === 'object' && v !== null
              ? JSON.stringify(v)
              : String(v)
        }
        rows.push(row)
      }

      const csv = buildCsv(columns, rows)
      const blob = new Blob(['\ufeff' + csv], {
        type: 'text/csv;charset=utf-8',
      })
      const url = URL.createObjectURL(blob)
      const safeName = c.name.replace(/[^a-z0-9-_]+/gi, '_').toLowerCase()
      const today = new Date().toISOString().slice(0, 10)
      const a = document.createElement('a')
      a.href = url
      a.download = `synaform-${safeName}-${today}.csv`
      document.body.appendChild(a)
      a.click()
      a.remove()
      URL.revokeObjectURL(url)
      showToast(T('export.download_csv'))
    }

    function buildCsv(columns, rows) {
      const esc = (v) => {
        const s = String(v ?? '')
        if (/[",\n\r]/.test(s)) return `"${s.replace(/"/g, '""')}"`
        return s
      }
      const head = columns.map(esc).join(',')
      const body = rows.map((r) => columns.map((c) => esc(r[c])).join(',')).join('\r\n')
      return `${head}\r\n${body}`
    }

    // --- Danger zone events ---

    function bindDangerEvents() {
      const c = collectionById(state.collectionId)
      if (!c) return
      const input = el.querySelector('#tx-danger-input')
      const deleteBtn = el.querySelector('[data-action="delete-collection"]')
      if (input) {
        input.addEventListener('input', () => {
          state.dangerConfirmText = input.value
          const matches = state.dangerConfirmText === c.name
          // Update button + visuals in-place to preserve focus.
          if (deleteBtn) deleteBtn.disabled = !matches
          input.style.borderColor = state.dangerConfirmText && !matches ? 'var(--status-error)' : ''
          const msg = el.querySelector('#tx-danger-mismatch')
          if (msg) msg.style.display = state.dangerConfirmText && !matches ? '' : 'none'
        })
      }
      el.querySelector('[data-action="delete-collection"]')?.addEventListener('click', async () => {
        if (state.dangerConfirmText !== c.name) return
        try {
          // Cascade: delete all datasets first (client-side).
          const kids = datasetsForCollection(c.id)
          for (const d of kids) {
            await api(`/candidates/${d.id}`, { method: 'DELETE' })
          }
          await api(`/forms/${c.id}`, { method: 'DELETE' })
          showToast(T('danger.deleted_toast'))
          state.collectionId = null
          state.dangerConfirmText = ''
          navigate({ view: 'collections' })
        } catch (err) {
          showToast(err.message, 'error')
        }
      })
    }

    // --- Setup Wizard events ---

    function bindWizardEvents() {
      if (!state.wizard.open) return

      el.querySelectorAll('[data-action="wizard-close"]').forEach((btn) =>
        btn.addEventListener('click', () => wizardClose())
      )
      el.querySelector('[data-action="wizard-close-backdrop"]')?.addEventListener('click', (e) => {
        if (e.target === e.currentTarget) wizardClose()
      })
      el.querySelector('[data-action="wizard-back"]')?.addEventListener('click', () => wizardBack())
      el.querySelector('[data-action="wizard-next"]')?.addEventListener('click', () => wizardNext())
      el.querySelectorAll('[data-action="wizard-pick-mode"]').forEach((btn) =>
        btn.addEventListener('click', () => {
          state.wizard.mode = btn.dataset.mode
          render()
        })
      )

      // Basics step inputs — keep state in sync without forcing a full
      // re-render on every keystroke (preserves focus + caret).
      const nameEl = el.querySelector('[data-action="wizard-input-name"]')
      if (nameEl) {
        nameEl.addEventListener('input', () => {
          state.wizard.name = nameEl.value
          const nextBtn = el.querySelector('[data-action="wizard-next"]')
          if (nextBtn) nextBtn.disabled = !wizardCanAdvance()
        })
      }
      const descEl = el.querySelector('[data-action="wizard-input-description"]')
      if (descEl) {
        descEl.addEventListener('input', () => {
          state.wizard.description = descEl.value
        })
      }
      const langEl = el.querySelector('[data-action="wizard-input-language"]')
      if (langEl) {
        langEl.addEventListener('change', () => {
          state.wizard.language = langEl.value
        })
      }

      // Template step
      const tplPicker = el.querySelector('[data-action="wizard-template-pick"]')
      if (tplPicker) {
        tplPicker.addEventListener('change', () => {
          const file = tplPicker.files?.[0]
          if (!file) return
          state.wizard.templateFile = file
          wizardUploadTemplate()
        })
      }
      // "fromDoc" picker — uploads a placeholder-less draft so the AI
      // can propose variables AND inject the {{placeholders}} on the
      // way to creating a brand-new template.
      const draftPicker = el.querySelector('[data-action="wizard-from-doc-pick"]')
      if (draftPicker) {
        draftPicker.addEventListener('change', async () => {
          const file = draftPicker.files?.[0]
          if (!file) return
          state.wizard.templateFile = file
          // The "fromDoc" call assumes the Collection already exists,
          // so save the basics first (idempotent) before sending the
          // file to the AI.
          if (!state.wizard.collectionId) {
            try {
              await wizardSaveBasics()
            } catch (_) {
              return
            }
          }
          wizardAiSuggestFromDocx()
        })
      }
      // Drag & drop onto the wizard dropzone. The dropzone is a <label> whose
      // text already invites a drop ("Drop a .docx here"), but a label alone
      // only forwards a CLICK to the hidden <input>, never a drop — so dropping
      // a file did nothing (beta feedback). We forward the dropped file into the
      // matching picker and dispatch its change event, reusing all existing
      // upload / AI-suggest logic and validation unchanged.
      const wizDrop = el.querySelector('[data-testid="wizard-template-dropzone"]')
      const wizPicker = tplPicker || draftPicker
      if (wizDrop && wizPicker) {
        wizDrop.addEventListener('dragover', (e) => {
          e.preventDefault()
          wizDrop.style.borderColor = 'var(--brand)'
        })
        wizDrop.addEventListener('dragleave', () => (wizDrop.style.borderColor = ''))
        wizDrop.addEventListener('drop', (e) => {
          e.preventDefault()
          wizDrop.style.borderColor = ''
          const file = e.dataTransfer?.files?.[0]
          if (!file) return
          try {
            const dt = new DataTransfer()
            dt.items.add(file)
            wizPicker.files = dt.files
            wizPicker.dispatchEvent(new Event('change', { bubbles: true }))
          } catch (_) {
            // Older browsers may forbid assigning input.files — drive the flow
            // directly as a fallback.
            state.wizard.templateFile = file
            if (draftPicker && !tplPicker) {
              wizardAiSuggestFromDocx()
            } else {
              wizardUploadTemplate()
            }
          }
        })
      }
      el.querySelector('[data-action="wizard-template-replace"]')?.addEventListener('click', () => {
        state.wizard.templateId = null
        state.wizard.templateName = ''
        state.wizard.templatePlaceholderCount = 0
        state.wizard.suggestions = null
        state.wizard.suggestionsSelected = {}
        state.wizard.fromDocApplied = 0
        state.wizard.fromDocTotal = 0
        state.wizard.fromDocModel = null
        render()
      })
      el.querySelector('[data-action="wizard-skip-template"]')?.addEventListener('click', () => {
        // "Skip" inside the template step nudges the user into the
        // noTemplate path mid-flow without forcing them all the way
        // back to the chooser.
        state.wizard.mode = 'noTemplate'
        wizardNext()
      })

      // Fields step (template path) — checkbox toggles
      el.querySelectorAll('[data-action="wizard-suggestion-toggle"]').forEach((cb) =>
        cb.addEventListener('change', () => {
          const idx = parseInt(cb.dataset.idx, 10)
          state.wizard.suggestionsSelected = {
            ...state.wizard.suggestionsSelected,
            [idx]: cb.checked,
          }
          // Re-render so the summary count updates; the checkbox
          // itself stays mounted because it has a stable position.
          render()
        })
      )

      // Fields step (text path) — paste + parse
      const pasteEl = el.querySelector('[data-action="wizard-paste-text"]')
      if (pasteEl) {
        pasteEl.addEventListener('input', () => {
          state.wizard.pastedText = pasteEl.value
        })
      }
      el.querySelector('[data-action="wizard-parse-text"]')?.addEventListener('click', () =>
        wizardParsePastedText()
      )

      // Review step finish actions
      el.querySelector('[data-action="wizard-finish-dataset"]')?.addEventListener('click', () =>
        wizardFinish('dataset')
      )
      el.querySelector('[data-action="wizard-finish-collection"]')?.addEventListener('click', () =>
        wizardFinish('collection')
      )
    }

    // =========================================================================
    // Data loaders
    // =========================================================================

    async function fetchForms() {
      try {
        const d = await api('/forms')
        state.forms = d.forms || []
      } catch (_) {
        state.forms = []
      }
    }
    async function fetchTemplates() {
      try {
        const d = await api('/templates')
        state.templates = d.templates || []
      } catch (_) {
        state.templates = []
      }
    }
    async function fetchDatasets() {
      try {
        const d = await api('/candidates')
        state.datasets = d.candidates || []
      } catch (_) {
        state.datasets = []
      }
    }

    async function loadData() {
      const jobs = []
      if (state.view === 'settings') {
        // config already loaded on init
      } else if (state.view === 'prompts') {
        jobs.push(fetchPrompts())
      } else {
        jobs.push(fetchForms(), fetchTemplates(), fetchDatasets())
      }
      await Promise.all(jobs)
      render()
    }

    async function fetchPrompts() {
      try {
        const res = await api('/prompts')
        state.prompts = res.prompts || []
        // Editing drafts keyed by prompt key; reset on (re)load.
        state.promptDrafts = {}
        for (const p of state.prompts) state.promptDrafts[p.key] = p.text
      } catch (err) {
        state.prompts = []
        showToast(err.message, 'error')
      }
    }

    async function selectDataset(id) {
      try {
        const d = await api(`/candidates/${id}`)
        state.selectedDataset = d.candidate
        state.datasetId = id
        state.datasetVariables = null
        state.selectedGenerateTemplate = null
        // Always land on the upload tab so the user knows where files
        // are managed; the other tabs are one click away.
        state.datasetTab = 'sources'
        await loadDatasetVariables(id)
      } catch (err) {
        showToast(err.message, 'error')
      }
      render()
    }

    async function loadDatasetVariables(id) {
      state.datasetVariablesLoading = true
      try {
        const d = await api(`/candidates/${id}/variables`)
        const varsMap = d.variables || {}
        const sourcesDef = d.sources || {}
        const tableFieldsMeta = d.table_fields || {}
        const list = []
        const tableKeys = new Set(Object.keys(tableFieldsMeta))
        if (!tableKeys.has('stations')) {
          for (const key of Object.keys(varsMap)) {
            if (/^stations\.\w+\.\d+$/.test(key)) {
              tableKeys.add('stations')
              break
            }
          }
        }
        const overrides = state.selectedDataset?.variable_overrides || {}
        for (const [k, v] of Object.entries(varsMap)) {
          if (tableKeys.has(k) && Array.isArray(v)) {
            let source = 'form'
            if (overrides[k] !== undefined && overrides[k] !== null) source = 'override'
            else if (sourcesDef[k]?.primary === 'ai') source = 'ai'
            list.push({
              key: k,
              value: v,
              source,
              type: 'table',
              columns: tableFieldsMeta[k]?.columns || [],
              label: tableFieldsMeta[k]?.label || k,
            })
            continue
          }
          if (/^\w+\.\w+\.\d+$/.test(k)) {
            const prefix = k.split('.')[0]
            if (tableKeys.has(prefix)) continue
          }
          let source = 'form'
          if (overrides[k] !== undefined && overrides[k] !== null) source = 'override'
          else if (sourcesDef[k]?.primary === 'ai') source = 'ai'
          list.push({
            key: k,
            value: v,
            source,
            type: k.startsWith('checkb.') ? 'checkbox' : 'text',
          })
        }
        state.datasetVariables = list
      } catch (_) {
        state.datasetVariables = null
      }
      state.datasetVariablesLoading = false
    }

    // =========================================================================
    // Init
    // =========================================================================

    async function init() {
      await loadTranslations()
      startLanguageWatcher()
      applyHash()
      render()
      try {
        const data = await api('/setup-check')
        if (!data.success) {
          state.error = data.error || T('app.not_available')
          state.loading = false
          render()
          return
        }
        state.config = data.config || {}
        const initJobs = [fetchForms(), fetchTemplates(), fetchDatasets()]
        // Deep link straight into the Prompts tab (#tx-prompts) — the
        // per-navigation loader only runs on navigate(), not on init.
        if (state.view === 'prompts') initJobs.push(fetchPrompts())
        await Promise.all(initJobs)
        state.loading = false
        render()
        if (state.view === 'collection' && state.tab === 'datasets' && state.datasetId) {
          await selectDataset(state.datasetId)
        }
      } catch (err) {
        state.error = err.message
        state.loading = false
        render()
      }
    }

    init()
  },
}
