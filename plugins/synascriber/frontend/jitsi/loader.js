/*
 * synaScriber Jitsi loader: the floating "Meeting notes" button, the start
 * dialog and the banner inside openDesk's Jitsi. Included from Jitsi's
 * head.html:
 *
 *   <script defer src="https://<synaplan>/api/v1/plugins/synascriber/assets/jitsi/loader.js"
 *     data-synaplan="https://<synaplan>" data-issuer="https://<keycloak>/realms/<realm>"
 *     data-client-id="synascriber"></script>
 *
 * Sign-in: Keycloak public client with PKCE; silent first (hidden iframe), a
 * popup when that fails. Keycloak returns to the plugin's /jitsi/silent page
 * on Synaplan, which hands the code back to this window.
 */
;(function () {
  'use strict'

  var script = document.currentScript
  if (!script || window.__synascriber) {
    return
  }
  window.__synascriber = true

  var CONFIG = {
    synaplan: (script.dataset.synaplan || '').replace(/\/$/, ''),
    issuer: (script.dataset.issuer || '').replace(/\/$/, ''),
    clientId: script.dataset.clientId || 'synascriber',
  }
  if (!CONFIG.synaplan || !CONFIG.issuer) {
    return
  }
  var SYNAPLAN_ORIGIN
  try {
    SYNAPLAN_ORIGIN = new URL(CONFIG.synaplan).origin
  } catch {
    return
  }
  var API = CONFIG.synaplan + '/api/v1/plugins/synascriber/jitsi'
  var REDIRECT_URI = API + '/silent'
  var POLL_MS = 15000
  var SAVED_NOTICE_MS = 120000

  var STRINGS = { en: {} }

  function loadCatalog(done) {
    var base = CONFIG.synaplan + '/api/v1/plugins/synascriber/assets/locales/'
    function grab(lang) {
      return fetch(base + lang + '.json')
        .then(function (res) {
          return res.ok ? res.json() : null
        })
        .catch(function () {
          return null
        })
    }
    var preferred = 'en'
    try {
      var fromJitsi =
        window.APP &&
        APP.translation &&
        APP.translation.getCurrentLanguage &&
        APP.translation.getCurrentLanguage()
      preferred = String(fromJitsi || document.documentElement.lang || navigator.language || 'en')
        .slice(0, 2)
        .toLowerCase()
    } catch {
      preferred = String(navigator.language || 'en')
        .slice(0, 2)
        .toLowerCase()
    }
    grab('en').then(function (english) {
      var next = preferred === 'en' ? Promise.resolve(null) : grab(preferred)
      return next.then(function (local) {
        STRINGS.en = (english && english.jitsi) || {}
        if (local && local.jitsi) STRINGS[preferred] = local.jitsi
        done()
      })
    })
  }

  var LANGUAGE_NAMES = { de: 'Deutsch', en: 'English', es: 'Español', fr: 'Français', tr: 'Türkçe' }

  function locale() {
    var candidates = []
    try {
      candidates.push(
        window.APP &&
          APP.translation &&
          APP.translation.getCurrentLanguage &&
          APP.translation.getCurrentLanguage()
      )
    } catch {
      /* Jitsi not ready yet */
    }
    candidates.push(document.documentElement.lang, navigator.language)
    for (var i = 0; i < candidates.length; i++) {
      var code = String(candidates[i] || '')
        .slice(0, 2)
        .toLowerCase()
      if (STRINGS[code]) {
        return code
      }
    }
    return 'en'
  }

  function t(key, vars) {
    var text = (STRINGS[locale()] || STRINGS.en)[key] || STRINGS.en[key] || key
    return text.replace(/\{(\w+)\}/g, function (_, name) {
      return vars && vars[name] != null ? String(vars[name]) : ''
    })
  }

  // ---------------------------------------------------------------- sign-in

  var token = null
  var tokenExpires = 0

  function randomString(bytes) {
    var array = new Uint8Array(bytes)
    crypto.getRandomValues(array)
    return Array.prototype.map
      .call(array, function (b) {
        return ('0' + b.toString(16)).slice(-2)
      })
      .join('')
  }

  function base64url(buffer) {
    var binary = String.fromCharCode.apply(null, new Uint8Array(buffer))
    return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '')
  }

  function authUrl(state, challenge, silent) {
    var params = new URLSearchParams({
      client_id: CONFIG.clientId,
      redirect_uri: REDIRECT_URI,
      response_type: 'code',
      scope: 'openid',
      state: state,
      code_challenge: challenge,
      code_challenge_method: 'S256',
    })
    if (silent) {
      params.set('prompt', 'none')
    }
    return CONFIG.issuer + '/protocol/openid-connect/auth?' + params.toString()
  }

  function waitForRedirect(state, timeoutMs) {
    return new Promise(function (resolve, reject) {
      var timer = setTimeout(function () {
        window.removeEventListener('message', onMessage)
        reject(new Error('timeout'))
      }, timeoutMs)
      function onMessage(event) {
        if (
          event.origin !== SYNAPLAN_ORIGIN ||
          !event.data ||
          event.data.type !== 'synascriber-auth'
        ) {
          return
        }
        var params = new URLSearchParams(event.data.search || '')
        if (params.get('state') !== state) {
          return
        }
        clearTimeout(timer)
        window.removeEventListener('message', onMessage)
        if (params.get('error')) {
          reject(new Error(params.get('error')))
        } else {
          resolve(params.get('code'))
        }
      }
      window.addEventListener('message', onMessage)
    })
  }

  function exchange(code, verifier) {
    var body = new URLSearchParams({
      grant_type: 'authorization_code',
      client_id: CONFIG.clientId,
      code: code,
      redirect_uri: REDIRECT_URI,
      code_verifier: verifier,
    })
    return fetch(CONFIG.issuer + '/protocol/openid-connect/token', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body.toString(),
    })
      .then(function (response) {
        if (!response.ok) {
          throw new Error('token_exchange_failed')
        }
        return response.json()
      })
      .then(function (data) {
        token = data.access_token
        tokenExpires = Date.now() + Math.max(30, (data.expires_in || 300) - 30) * 1000
        return token
      })
  }

  function signIn(silent) {
    var state = randomString(16)
    var verifier = randomString(32)
    return crypto.subtle
      .digest('SHA-256', new TextEncoder().encode(verifier))
      .then(function (hash) {
        var url = authUrl(state, base64url(hash), silent)
        var waiting = waitForRedirect(state, silent ? 8000 : 120000)
        var frame = null
        if (silent) {
          frame = document.createElement('iframe')
          frame.style.display = 'none'
          frame.title = 'synascriber-sign-in'
          frame.src = url
          document.body.appendChild(frame)
        } else {
          window.open(url, 'synascriber-sign-in', 'width=480,height=640')
        }
        return waiting.finally(function () {
          if (frame) {
            frame.remove()
          }
        })
      })
      .then(function (code) {
        return exchange(code, verifier)
      })
  }

  function ensureToken(interactive) {
    if (token && Date.now() < tokenExpires) {
      return Promise.resolve(token)
    }
    return signIn(true).catch(function (error) {
      if (!interactive) {
        throw error
      }
      return signIn(false)
    })
  }

  function api(method, path, body, interactive) {
    return ensureToken(interactive)
      .then(function (bearer) {
        return fetch(API + path, {
          method: method,
          headers: {
            Authorization: 'Bearer ' + bearer,
            'Content-Type': 'application/json',
            Accept: 'application/json',
          },
          body: body ? JSON.stringify(body) : undefined,
        })
      })
      .then(function (response) {
        return response
          .json()
          .catch(function () {
            return {}
          })
          .then(function (data) {
            if (response.status === 401) {
              token = null
            }
            return { status: response.status, data: data }
          })
      })
  }

  // ---------------------------------------------------------------- state

  // view.state: hidden | signin | connecting | launching | idle (session states come from view.info.session)
  var view = {
    state: 'hidden',
    info: null,
    notice: null,
    busy: false,
    signedIn: false,
    dialog: false,
  }
  var room = ''
  var pollTimer = null
  var shownOutcomes = {}

  function refresh(interactive) {
    if (!room) {
      return Promise.resolve()
    }
    return api('GET', '/state?room=' + encodeURIComponent(room), null, interactive)
      .then(function (res) {
        if (res.status === 401) {
          view.signedIn = false
          view.state = 'signin'
          render()
          return
        }
        view.signedIn = true
        if (res.status !== 200 || !res.data.enabled) {
          view.state = 'hidden'
          view.info = null
          render()
          return
        }
        view.info = res.data
        if (view.state !== 'launching') {
          view.state = 'idle'
        }
        var recent = res.data.recent
        if (recent && !shownOutcomes[recent.id]) {
          shownOutcomes[recent.id] = true
          showOutcome(recent)
        }
        render()
      })
      .catch(function () {
        view.signedIn = false
        view.state = 'signin'
        render()
      })
  }

  function startNotes(language, folder) {
    view.busy = true
    view.state = 'launching'
    render()
    return api('POST', '/sessions', { room: room, language: language, folder: folder }, true).then(
      function (res) {
        view.busy = false
        view.dialog = false
        view.state = 'idle'
        var session = res.data && res.data.session
        if (res.status === 201 && session) {
          view.info.session = session
        } else if (session && session.state === 'failed') {
          shownOutcomes[session.id] = true
          showOutcome(session)
        } else {
          view.notice = { kind: 'error', text: errorText(res.data) }
          if (session) {
            view.info.session = session
          }
        }
        render()
        return refresh(false)
      },
      function () {
        view.busy = false
        view.state = 'signin'
        render()
      }
    )
  }

  function stopNotes() {
    var session = view.info && view.info.session
    if (!session) {
      return Promise.resolve()
    }
    view.busy = true
    render()
    return api('POST', '/sessions/' + session.id + '/stop', null, true).then(function (res) {
      view.busy = false
      if (res.status !== 200) {
        view.notice = { kind: 'error', text: errorText(res.data) }
      }
      render()
      return refresh(false)
    })
  }

  function errorText(data) {
    var code = data && data.error
    var key = 'err_' + code
    return STRINGS.en[key] ? t(key) : t('err_generic')
  }

  function showOutcome(session) {
    if (session.state === 'saved' && session.fileId) {
      var text = session.received
        ? t('received', { name: session.startedBy, folder: session.folder })
        : t(session.recipients > 1 ? 'saved_all' : 'saved', {
            folder: session.folder,
            count: session.recipients,
          })
      if (session.mine && session.notDelivered > 0) {
        text += ' ' + t('saved_partial', { count: session.notDelivered })
      }
      view.notice = {
        kind: 'saved',
        text: text,
        link: CONFIG.synaplan + '/files?file=' + session.fileId,
      }
    } else if (session.state === 'nothing_to_save') {
      view.notice = { kind: 'info', text: t('nothing') }
    } else {
      var reason = STRINGS.en['err_' + session.error] ? t('err_' + session.error) : t('err_generic')
      view.notice = { kind: 'error', text: t('failed', { reason: reason }) }
    }
    setTimeout(function () {
      view.notice = null
      render()
    }, SAVED_NOTICE_MS)
    render()
  }

  // ---------------------------------------------------------------- UI

  var host = document.createElement('div')
  host.id = 'synascriber'
  var shadow = host.attachShadow({ mode: 'open' })
  var STYLE = [
    ':host{all:initial}',
    '.wrap{position:fixed;top:72px;left:12px;z-index:2147483000;font:14px/1.4 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;color:#fff;max-width:calc(100vw - 24px)}',
    '.pill{display:inline-flex;align-items:center;gap:8px;padding:8px 14px;border-radius:999px;border:1px solid rgba(255,255,255,.25);background:rgba(18,18,18,.88);color:#fff;font:inherit;cursor:pointer;box-shadow:0 2px 8px rgba(0,0,0,.4)}',
    '.pill:hover,.pill:focus-visible{background:#571efa;outline:none}',
    '.pill[aria-disabled=true]{cursor:default;background:rgba(18,18,18,.88)}',
    '.dot{width:10px;height:10px;border-radius:50%;background:#bbb}',
    '.on .dot{background:#e5484d;box-shadow:0 0 0 3px rgba(229,72,77,.35)}',
    '.banner{margin-top:8px;padding:10px 12px;border-radius:10px;background:rgba(18,18,18,.92);border:1px solid rgba(255,255,255,.2);max-width:360px}',
    '.banner.error{border-color:#e5484d}',
    '.banner.saved{border-color:#46a758}',
    '.row{display:flex;gap:8px;margin-top:8px;flex-wrap:wrap}',
    'button.act{padding:8px 14px;border-radius:8px;border:0;font:inherit;cursor:pointer}',
    'button.primary{background:#571efa;color:#fff}',
    'button.secondary{background:#3a3a3a;color:#fff}',
    'button.danger{background:#c62f34;color:#fff}',
    'button:disabled{opacity:.5;cursor:not-allowed}',
    'a{color:#c9b8ff}',
    '.dialog{margin-top:8px;padding:16px;border-radius:12px;background:#1e1e1e;border:1px solid rgba(255,255,255,.25);width:min(360px,calc(100vw - 24px));box-sizing:border-box}',
    '.dialog h2{margin:0 0 12px;font-size:16px}',
    'label{display:block;margin-top:10px;font-size:13px;color:#ddd}',
    'select,input{display:block;width:100%;box-sizing:border-box;margin-top:4px;padding:8px 10px;border-radius:8px;border:1px solid #666;background:#2a2a2a;color:#fff;font:inherit}',
    'select:focus,input:focus{outline:2px solid #8f6bff;outline-offset:1px}',
    '.hint{margin-top:12px;font-size:12px;color:#d0d0d0}',
  ].join('')

  function el(tag, attrs, children) {
    var node = document.createElement(tag)
    Object.keys(attrs || {}).forEach(function (key) {
      if (key === 'onclick') {
        node.addEventListener('click', attrs[key])
      } else if (key === 'text') {
        node.textContent = attrs[key]
      } else {
        node.setAttribute(key, attrs[key])
      }
    })
    ;(children || []).forEach(function (child) {
      if (child) {
        node.appendChild(child)
      }
    })
    return node
  }

  // Sit below Jitsi's (branded) watermark and any bar above the meeting.
  function topOffset() {
    var mark = document.querySelector('.leftwatermark, .watermark')
    var rect = mark && mark.getBoundingClientRect()
    if (rect && rect.height > 0 && rect.left < window.innerWidth / 2) {
      return Math.round(rect.bottom + 8)
    }
    return 72
  }

  function clock(iso) {
    try {
      return new Date(iso).toLocaleTimeString(locale(), { hour: '2-digit', minute: '2-digit' })
    } catch {
      return ''
    }
  }

  function dialog() {
    var info = view.info || {}
    var select = el('select', { id: 'syn-language' })
    ;(info.languages || ['de']).forEach(function (code) {
      var option = el('option', { value: code, text: LANGUAGE_NAMES[code] || code })
      if (code === info.defaultLanguage) {
        option.setAttribute('selected', 'selected')
      }
      select.appendChild(option)
    })
    var folder = el('input', {
      id: 'syn-folder',
      type: 'text',
      maxlength: '64',
      value: info.defaultFolder || 'Meetings',
    })
    var start = el('button', {
      class: 'act primary',
      type: 'button',
      text: t('start'),
      onclick: function () {
        startNotes(select.value, folder.value)
      },
    })
    if (view.busy) {
      start.setAttribute('disabled', 'disabled')
    }
    var box = el(
      'div',
      { class: 'dialog', role: 'dialog', 'aria-modal': 'false', 'aria-labelledby': 'syn-title' },
      [
        el('h2', { id: 'syn-title', text: t('start_title') }),
        el('label', { for: 'syn-language', text: t('language') }),
        select,
        el('label', { for: 'syn-folder', text: t('folder') }),
        folder,
        el('p', {
          class: 'hint',
          text: t(view.info && view.info.shared === false ? 'consent_own' : 'consent'),
        }),
        el('div', { class: 'row' }, [
          start,
          el('button', {
            class: 'act secondary',
            type: 'button',
            text: t('cancel'),
            onclick: function () {
              view.dialog = false
              render()
            },
          }),
        ]),
      ]
    )
    box.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') {
        view.dialog = false
        render()
      }
    })
    setTimeout(function () {
      select.focus()
    }, 0)
    return box
  }

  function render() {
    shadow.innerHTML = ''
    shadow.appendChild(el('style', { text: STYLE }))
    if (view.state === 'hidden') {
      return
    }
    var info = view.info || {}
    var session = info.session
    var wrap = el('div', { class: 'wrap' })
    wrap.style.top = topOffset() + 'px'
    var active =
      session && ['starting', 'running', 'stopping', 'saving'].indexOf(session.state) >= 0

    if (view.state === 'signin') {
      wrap.appendChild(
        el(
          'button',
          {
            class: 'pill',
            type: 'button',
            onclick: function () {
              view.state = 'connecting'
              render()
              refresh(true)
            },
          },
          [el('span', { class: 'dot' }), el('span', { text: t('sign_in') })]
        )
      )
    } else if (view.state === 'connecting') {
      wrap.appendChild(
        el('div', { class: 'pill', role: 'status', 'aria-disabled': 'true' }, [
          el('span', { class: 'dot' }),
          el('span', { text: t('signing_in') }),
        ])
      )
    } else if (view.state === 'launching') {
      wrap.appendChild(
        el(
          'div',
          { class: 'pill on', role: 'status', 'aria-live': 'polite', 'aria-disabled': 'true' },
          [el('span', { class: 'dot' }), el('span', { text: t('starting') })]
        )
      )
    } else if (active) {
      var label =
        session.state === 'running'
          ? t('on') +
            ' · ' +
            t('by', { name: session.startedBy }) +
            ' · ' +
            t('since', { time: clock(session.startedAt) })
          : session.state === 'starting'
            ? t('starting')
            : t('saving')
      wrap.appendChild(
        el(
          'div',
          { class: 'pill on', role: 'status', 'aria-live': 'polite', 'aria-disabled': 'true' },
          [el('span', { class: 'dot' }), el('span', { text: label })]
        )
      )
      if (session.state === 'running') {
        if (session.canStop) {
          var stop = el('button', {
            class: 'act danger',
            type: 'button',
            text: t('stop'),
            onclick: stopNotes,
          })
          if (view.busy) {
            stop.setAttribute('disabled', 'disabled')
          }
          wrap.appendChild(el('div', { class: 'row' }, [stop]))
        } else {
          wrap.appendChild(
            el('div', { class: 'banner', text: t('stop_hint', { name: session.startedBy }) })
          )
        }
      }
    } else {
      wrap.appendChild(
        el(
          'button',
          {
            class: 'pill',
            type: 'button',
            'aria-expanded': String(view.dialog),
            onclick: function () {
              view.dialog = !view.dialog
              render()
            },
          },
          [el('span', { class: 'dot' }), el('span', { text: t('button') })]
        )
      )
      if (view.dialog) {
        wrap.appendChild(dialog())
      }
    }

    if (captions.seen) {
      wrap.appendChild(el('div', { class: 'row' }, [captionToggle()]))
    }

    if (view.notice) {
      var notice = el(
        'div',
        { class: 'banner ' + view.notice.kind, role: 'status', 'aria-live': 'polite' },
        [el('span', { text: view.notice.text })]
      )
      if (view.notice.link) {
        notice.appendChild(
          el('div', { class: 'row' }, [
            el('a', { href: view.notice.link, target: '_blank', rel: 'noopener', text: t('open') }),
          ])
        )
      }
      notice.appendChild(
        el('div', { class: 'row' }, [
          el('button', {
            class: 'act secondary',
            type: 'button',
            text: t('close'),
            onclick: function () {
              view.notice = null
              render()
            },
          }),
        ])
      )
      wrap.appendChild(notice)
    }
    shadow.appendChild(wrap)
  }

  // ---------------------------------------------------------------- captions

  // The transcriber sends its results back into the meeting; Jitsi hands them
  // to every participant as messages from "transcriber". Shown in a separate
  // element so frequent updates never re-render (and steal focus from) the
  // notes UI above. Works for everyone in the meeting, signed in or not.
  var CAPTION_PREF = 'synascriber.captions'
  var CAPTION_HOLD_MS = 7000
  var CAPTION_LINES = 2
  var CAPTION_CHARS = 160
  var CAPTION_CHARS_NARROW = 70
  var captions = {
    on: captionPref(),
    lines: [],
    conference: null,
    seen: false,
    timer: null,
    order: 0,
  }
  var captionHost = document.createElement('div')
  captionHost.id = 'synascriber-captions'
  var captionShadow = captionHost.attachShadow({ mode: 'open' })
  var CAPTION_STYLE = [
    ':host{all:initial}',
    '.cc{position:fixed;left:50%;transform:translateX(-50%);z-index:2147482999;width:min(640px,calc(100vw - 24px));box-sizing:border-box;padding:8px 36px 8px 12px;border-radius:10px;background:rgba(0,0,0,.75);color:#fff;font:15px/1.45 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;pointer-events:none}',
    '.line{margin:2px 0;overflow-wrap:anywhere}',
    '.who{font-weight:600;color:#c9b8ff;margin-right:6px}',
    '.pending{color:#d8d8d8}',
    '.hide{pointer-events:auto;position:absolute;top:6px;right:6px;width:24px;height:24px;border:0;border-radius:6px;background:transparent;color:#fff;font:16px/24px system-ui,sans-serif;cursor:pointer}',
    '.hide:hover,.hide:focus-visible{background:rgba(255,255,255,.18);outline:none}',
    '@media (max-width:480px){.cc{font-size:14px}}',
  ].join('')

  function captionPref() {
    try {
      return localStorage.getItem(CAPTION_PREF) !== 'off'
    } catch {
      return true
    }
  }

  function setCaptions(on) {
    captions.on = on
    try {
      localStorage.setItem(CAPTION_PREF, on ? 'on' : 'off')
    } catch {
      /* private mode: keep it for this page */
    }
    renderCaptions()
    render()
  }

  function speakerName(endpoint, participant) {
    try {
      if (endpoint && window.APP && APP.conference && endpoint === APP.conference.getMyUserId()) {
        return t('you')
      }
    } catch {
      /* not joined */
    }
    if (participant && participant.name) {
      return participant.name
    }
    try {
      var name =
        APP.conference.getParticipantDisplayName &&
        APP.conference.getParticipantDisplayName(endpoint)
      if (name) {
        return name
      }
      var remote = APP.store.getState()['features/base/participants'].remote
      var entry = remote && remote.get && remote.get(endpoint)
      if (entry && entry.name) {
        return entry.name
      }
    } catch {
      /* roster not available */
    }
    return t('someone')
  }

  function onTranscript(from, message) {
    if (
      !message ||
      message.type !== 'transcription-result' ||
      !message.transcript ||
      !message.transcript[0]
    ) {
      return
    }
    var text = String(message.transcript[0].text || '')
      .replace(/\s+/g, ' ')
      .trim()
    var endpoint = String((message.participant && message.participant.id) || '').split('-')[0]
    var id = String(message.message_id || '')
    var line = null
    for (var i = captions.lines.length - 1; i >= 0; i--) {
      if (captions.lines[i].endpoint === endpoint) {
        line = captions.lines[i]
        break
      }
    }
    if (!line || Date.now() - line.at > CAPTION_HOLD_MS) {
      if (!text) {
        return
      }
      line = { endpoint: endpoint, done: '', pending: null, at: 0 }
      captions.lines.push(line)
    }
    line.name = speakerName(endpoint, message.participant)
    if (message.is_interim) {
      line.pending = { id: id, text: text }
    } else {
      if (line.pending && line.pending.id === id) {
        line.pending = null
      }
      line.done = (line.done + ' ' + text).trim()
    }
    line.at = Date.now()
    line.order = ++captions.order
    captions.lines = captions.lines.filter(function (l) {
      return l === line || Date.now() - l.at <= CAPTION_HOLD_MS
    })
    captions.lines.sort(function (a, b) {
      return a.order - b.order
    })
    captions.lines = captions.lines.slice(-CAPTION_LINES)
    if (!captions.seen) {
      captions.seen = true
      render()
    }
    renderCaptions()
  }

  function tail(text) {
    var limit = window.innerWidth < 480 ? CAPTION_CHARS_NARROW : CAPTION_CHARS
    if (text.length <= limit) {
      return text
    }
    var cut = text.slice(-limit)
    var space = cut.indexOf(' ')
    return '…' + (space > 0 && space < 30 ? cut.slice(space + 1) : cut)
  }

  // Above Jitsi's toolbar and the speaker name on the stage, whichever is higher.
  function captionBottom() {
    var bottom = 24
    ;[
      '.toolbox-content-items',
      '.toolbox-content',
      '#largeVideoContainer [class*="badgeContainer"]',
      '.stage-participant-label',
    ].forEach(function (selector) {
      var node = document.querySelector(selector)
      var rect = node && node.getBoundingClientRect()
      if (rect && rect.height > 0 && rect.top < window.innerHeight) {
        bottom = Math.max(bottom, Math.round(window.innerHeight - rect.top + 10))
      }
    })
    return bottom
  }

  function renderCaptions() {
    captionShadow.innerHTML = ''
    captionShadow.appendChild(el('style', { text: CAPTION_STYLE }))
    var now = Date.now()
    var lines = captions.lines.filter(function (l) {
      return now - l.at <= CAPTION_HOLD_MS && (l.done || l.pending)
    })
    if (!captions.on || lines.length === 0) {
      clearInterval(captions.timer)
      captions.timer = null
      return
    }
    var box = el('div', {
      class: 'cc',
      role: 'log',
      'aria-live': 'polite',
      'aria-label': t('captions_region'),
    })
    box.style.bottom = captionBottom() + 'px'
    lines.forEach(function (l) {
      var full = (l.done + (l.pending ? ' ' + l.pending.text : '')).trim()
      var shown = tail(full)
      var doneShown = shown.slice(
        0,
        Math.max(0, shown.length - (l.pending ? l.pending.text.length : 0))
      )
      box.appendChild(
        el('div', { class: 'line' }, [
          el('span', { class: 'who', text: l.name + ':' }),
          el('span', { text: l.pending ? doneShown : shown }),
          l.pending
            ? el('span', {
                class: 'pending',
                'aria-hidden': 'true',
                text: shown.slice(doneShown.length),
              })
            : null,
        ])
      )
    })
    box.appendChild(
      el('button', {
        class: 'hide',
        type: 'button',
        'aria-label': t('captions_hide'),
        title: t('captions_hide'),
        text: '×',
        onclick: function () {
          setCaptions(false)
        },
      })
    )
    captionShadow.appendChild(box)
    if (!captions.timer) {
      captions.timer = setInterval(renderCaptions, 1000)
    }
  }

  function captionToggle() {
    return el(
      'button',
      {
        class: 'pill',
        type: 'button',
        'aria-pressed': String(captions.on),
        onclick: function () {
          setCaptions(!captions.on)
        },
      },
      [el('span', { text: t(captions.on ? 'captions_on' : 'captions_off') })]
    )
  }

  // Jitsi replaces the conference object on reconnects; re-attach when it does.
  function attachCaptions() {
    var conference = null
    try {
      conference =
        (APP.store.getState()['features/base/conference'] || {}).conference || APP.conference._room
    } catch {
      /* not joined */
    }
    if (!conference || conference === captions.conference || typeof conference.on !== 'function') {
      return
    }
    captions.conference = conference
    var events = window.JitsiMeetJS && JitsiMeetJS.events && JitsiMeetJS.events.conference
    conference.on(
      (events && events.NON_PARTICIPANT_MESSAGE_RECEIVED) ||
        'conference.non_participant_message_received',
      onTranscript
    )
  }

  // ---------------------------------------------------------------- boot

  function joinedRoom() {
    try {
      if (window.APP && APP.conference && APP.conference.isJoined && APP.conference.isJoined()) {
        return String(APP.conference.roomName || '').toLowerCase()
      }
    } catch {
      /* not ready */
    }
    return ''
  }

  function boot() {
    var name = joinedRoom()
    if (!name) {
      setTimeout(boot, 1500)
      return
    }
    room = name
    document.body.appendChild(host)
    document.body.appendChild(captionHost)
    attachCaptions()
    view.state = 'connecting'
    render()
    refresh(false)
    pollTimer = setInterval(function () {
      attachCaptions()
      if (view.signedIn) {
        refresh(false)
      }
    }, POLL_MS)
    var attachTimer = setInterval(attachCaptions, 5000)
    window.addEventListener('beforeunload', function () {
      clearInterval(pollTimer)
      clearInterval(attachTimer)
      clearInterval(captions.timer)
    })
  }

  var start = function () {
    loadCatalog(boot)
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start)
  } else {
    start()
  }
})()
