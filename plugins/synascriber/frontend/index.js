// synaScriber plugin page in Synaplan: your meeting notes, and for
// administrators the settings and the connection checks.

const COPY = {
  en: {
    title: 'Meeting notes', intro: 'Written notes of your Jitsi meetings: the ones you started and the ones from meetings you took part in. Every copy is also in Sources › Generated.',
    mine: 'Your meeting notes', empty: 'No meeting notes yet. Start them in a Jitsi meeting with the Meeting notes button.',
    open: 'Open file', state_saved: 'Saved', state_nothing_to_save: 'Nobody spoke', state_failed: 'Stopped with a problem', state_running: 'Running', state_stopping: 'Saving', state_saving: 'Saving', state_starting: 'Starting',
    admin: 'Settings', enabled: 'Show the Meeting notes button in Jitsi', languages: 'Offered languages', default_language: 'Default language', default_folder: 'Default folder in Sources',
    jitsi_url: 'Jitsi address (where the button runs)', stt_url: 'Speech-to-text server', prosody_url: 'Jitsi connection (Prosody)', secret: 'Shared secret with Jitsi', secret_set: 'set', secret_missing: 'missing',
    save: 'Save settings', saved: 'Settings saved.', checks: 'Status', check_jitsi: 'Jitsi answers', check_speech: 'Speech-to-text server answers', transcriber_secret: 'Shared secret with the transcriber',
    ok: 'OK', not_ok: 'Not reachable', error: 'Could not load the meeting notes. Reload the page.',
    captions: 'Live captions in the meeting while notes are on',
    from: 'started by {name}', all_generated: 'Show in Sources › Generated', share: 'Give every signed-in participant a copy in their Sources › Generated (off: only the person who started gets it)',
  },
  de: {
    title: 'Mitschrift', intro: 'Schriftliche Mitschriften Ihrer Jitsi-Besprechungen: die von Ihnen gestarteten und die aus Besprechungen, an denen Sie teilgenommen haben. Jede Kopie liegt auch in Quellen › Erzeugt.',
    mine: 'Ihre Mitschriften', empty: 'Noch keine Mitschriften. Starten Sie eine in einer Jitsi-Besprechung über die Schaltfläche Mitschrift.',
    open: 'Datei öffnen', state_saved: 'Gespeichert', state_nothing_to_save: 'Niemand hat gesprochen', state_failed: 'Mit Problem beendet', state_running: 'Läuft', state_stopping: 'Wird gespeichert', state_saving: 'Wird gespeichert', state_starting: 'Startet',
    admin: 'Einstellungen', enabled: 'Schaltfläche Mitschrift in Jitsi anzeigen', languages: 'Angebotene Sprachen', default_language: 'Standardsprache', default_folder: 'Standardordner in Quellen',
    jitsi_url: 'Adresse von Jitsi (wo die Schaltfläche läuft)', stt_url: 'Spracherkennungs-Server', prosody_url: 'Verbindung zu Jitsi (Prosody)', secret: 'Gemeinsames Geheimnis mit Jitsi', secret_set: 'gesetzt', secret_missing: 'fehlt',
    save: 'Einstellungen speichern', saved: 'Einstellungen gespeichert.', checks: 'Status', check_jitsi: 'Jitsi antwortet', check_speech: 'Spracherkennungs-Server antwortet', transcriber_secret: 'Gemeinsames Geheimnis mit dem Transkriptionsdienst',
    ok: 'OK', not_ok: 'Nicht erreichbar', error: 'Die Mitschriften konnten nicht geladen werden. Laden Sie die Seite neu.',
    captions: 'Live-Untertitel in der Besprechung, solange die Mitschrift läuft',
    from: 'gestartet von {name}', all_generated: 'In Quellen › Erzeugt anzeigen', share: 'Alle angemeldeten Teilnehmenden erhalten eine Kopie in Quellen › Erzeugt (aus: nur die Person, die gestartet hat)',
  },
  es: {
    title: 'Notas de la reunión', intro: 'Notas escritas de tus reuniones de Jitsi: las que iniciaste y las de reuniones en las que participaste. Cada copia también está en Fuentes › Generados.',
    mine: 'Tus notas', empty: 'Aún no hay notas. Inícialas en una reunión de Jitsi con el botón Notas de la reunión.',
    open: 'Abrir archivo', state_saved: 'Guardadas', state_nothing_to_save: 'Nadie habló', state_failed: 'Detenidas con un problema', state_running: 'En curso', state_stopping: 'Guardando', state_saving: 'Guardando', state_starting: 'Iniciando',
    admin: 'Ajustes', enabled: 'Mostrar el botón Notas de la reunión en Jitsi', languages: 'Idiomas ofrecidos', default_language: 'Idioma predeterminado', default_folder: 'Carpeta predeterminada en Fuentes',
    jitsi_url: 'Dirección de Jitsi (donde está el botón)', stt_url: 'Servidor de voz a texto', prosody_url: 'Conexión con Jitsi (Prosody)', secret: 'Secreto compartido con Jitsi', secret_set: 'definido', secret_missing: 'falta',
    save: 'Guardar ajustes', saved: 'Ajustes guardados.', checks: 'Estado', check_jitsi: 'Jitsi responde', check_speech: 'El servidor de voz a texto responde', transcriber_secret: 'Secreto compartido con el servicio de transcripción',
    ok: 'OK', not_ok: 'No accesible', error: 'No se pudieron cargar las notas. Recarga la página.',
    captions: 'Subtítulos en directo en la reunión mientras se toman notas',
    from: 'iniciadas por {name}', all_generated: 'Mostrar en Fuentes › Generados', share: 'Cada participante con sesión iniciada recibe una copia en Fuentes › Generados (desactivado: solo quien las inició)',
  },
  fr: {
    title: 'Notes de réunion', intro: 'Notes écrites de vos réunions Jitsi : celles que vous avez lancées et celles des réunions auxquelles vous avez participé. Chaque copie se trouve aussi dans Sources › Générés.',
    mine: 'Vos notes de réunion', empty: 'Pas encore de notes. Lancez-les dans une réunion Jitsi avec le bouton Notes de réunion.',
    open: 'Ouvrir le fichier', state_saved: 'Enregistrées', state_nothing_to_save: 'Personne n\'a parlé', state_failed: 'Arrêtées avec un problème', state_running: 'En cours', state_stopping: 'Enregistrement', state_saving: 'Enregistrement', state_starting: 'Démarrage',
    admin: 'Réglages', enabled: 'Afficher le bouton Notes de réunion dans Jitsi', languages: 'Langues proposées', default_language: 'Langue par défaut', default_folder: 'Dossier par défaut dans Sources',
    jitsi_url: 'Adresse de Jitsi (où se trouve le bouton)', stt_url: 'Serveur de reconnaissance vocale', prosody_url: 'Connexion à Jitsi (Prosody)', secret: 'Secret partagé avec Jitsi', secret_set: 'défini', secret_missing: 'manquant',
    save: 'Enregistrer les réglages', saved: 'Réglages enregistrés.', checks: 'État', check_jitsi: 'Jitsi répond', check_speech: 'Le serveur de reconnaissance vocale répond', transcriber_secret: 'Secret partagé avec le service de transcription',
    ok: 'OK', not_ok: 'Injoignable', error: 'Impossible de charger les notes. Rechargez la page.',
    captions: 'Sous-titres en direct dans la réunion pendant la prise de notes',
    from: 'lancées par {name}', all_generated: 'Afficher dans Sources › Générés', share: 'Chaque participant connecté reçoit une copie dans Sources › Générés (désactivé : seule la personne qui a lancé les notes)',
  },
  tr: {
    title: 'Toplantı notları', intro: 'Jitsi toplantılarınızın yazılı notları: başlattıklarınız ve katıldığınız toplantılardan gelenler. Her kopya ayrıca Kaynaklar › Üretilen bölümündedir.',
    mine: 'Toplantı notlarınız', empty: 'Henüz not yok. Bir Jitsi toplantısında Toplantı notları düğmesiyle başlatın.',
    open: 'Dosyayı aç', state_saved: 'Kaydedildi', state_nothing_to_save: 'Kimse konuşmadı', state_failed: 'Bir sorunla durdu', state_running: 'Sürüyor', state_stopping: 'Kaydediliyor', state_saving: 'Kaydediliyor', state_starting: 'Başlıyor',
    admin: 'Ayarlar', enabled: 'Jitsi\'de Toplantı notları düğmesini göster', languages: 'Sunulan diller', default_language: 'Varsayılan dil', default_folder: 'Kaynaklar\'da varsayılan klasör',
    jitsi_url: 'Jitsi adresi (düğmenin bulunduğu yer)', stt_url: 'Konuşmadan metne sunucusu', prosody_url: 'Jitsi bağlantısı (Prosody)', secret: 'Jitsi ile ortak gizli anahtar', secret_set: 'ayarlı', secret_missing: 'eksik',
    save: 'Ayarları kaydet', saved: 'Ayarlar kaydedildi.', checks: 'Durum', check_jitsi: 'Jitsi yanıt veriyor', check_speech: 'Konuşmadan metne sunucusu yanıt veriyor', transcriber_secret: 'Transkripsiyon hizmeti ile ortak gizli anahtar',
    ok: 'Tamam', not_ok: 'Erişilemiyor', error: 'Notlar yüklenemedi. Sayfayı yenileyin.',
    captions: 'Notlar açıkken toplantıda canlı altyazı',
    from: '{name} başlattı', all_generated: 'Kaynaklar › Üretilen bölümünde göster', share: 'Oturum açan her katılımcı Kaynaklar › Üretilen bölümünde bir kopya alır (kapalı: yalnızca başlatan kişi)',
  },
}
const LANGUAGES = { de: 'Deutsch', en: 'English', es: 'Español', fr: 'Français', tr: 'Türkçe' }
const FIELD = 'mt-1 w-full px-3 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]'

function lang() {
  const code = (localStorage.getItem('language') || navigator.language || 'en').slice(0, 2)
  return COPY[code] ? code : 'en'
}
function t(key, params = {}) {
  const text = COPY[lang()][key] || COPY.en[key] || key
  return text.replace(/\{(\w+)\}/g, (_, name) => (params[name] ?? ''))
}
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
  const card = h('section', { class: 'surface-card rounded-lg p-4' }, [h('h2', { class: 'txt-primary text-base font-semibold', text: t('mine') })])
  if (!list.length) {
    card.appendChild(h('p', { class: 'txt-secondary text-sm mt-2', text: t('empty') }))
    return card
  }
  const ul = h('ul', { class: 'mt-2 divide-y divide-light-border/30 dark:divide-dark-border/20' })
  for (const s of list) {
    const when = s.startedAt ? new Date(s.startedAt).toLocaleString(lang()) : ''
    const row = h('li', { class: 'py-2 flex flex-wrap items-center gap-3' }, [
      h('span', { class: 'txt-primary text-sm font-medium', text: s.room }),
      h('span', { class: 'txt-secondary text-sm', text: `${when} · ${LANGUAGES[s.language] || s.language} · ${s.folder}` }),
      h('span', { class: 'txt-secondary text-sm', text: t(`state_${s.state}`) }),
      s.received ? h('span', { class: 'txt-secondary text-sm', text: t('from', { name: s.startedBy }) }) : null,
    ])
    if (s.fileId) row.appendChild(h('a', { class: 'btn-secondary px-4 py-2.5 rounded-lg text-sm font-medium', href: `/files?file=${s.fileId}`, text: t('open') }))
    ul.appendChild(row)
  }
  card.appendChild(ul)
  card.appendChild(h('a', { class: 'btn-secondary inline-flex mt-3 px-4 py-2.5 rounded-lg text-sm font-medium', href: '/files/generated', text: t('all_generated') }))
  return card
}

function adminCard(base, status, onSaved) {
  const s = status.settings
  const card = h('section', { class: 'surface-card rounded-lg p-4' }, [h('h2', { class: 'txt-primary text-base font-semibold', text: t('admin') })])

  const checks = h('ul', { class: 'mt-2 text-sm' })
  for (const [key, label] of [['jitsi', 'check_jitsi'], ['speech', 'check_speech']]) {
    checks.appendChild(h('li', { class: status.checks[key] ? 'txt-primary' : 'text-red-600 dark:text-red-400', text: `${status.checks[key] ? '✓' : '✗'} ${t(label)}: ${status.checks[key] ? t('ok') : t('not_ok')}` }))
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
    return h('label', { class: 'inline-flex items-center gap-1 mr-3 txt-primary text-sm', for: `syn-lang-${code}` }, [box, h('span', { text: LANGUAGES[code] })])
  })
  const defLang = h('select', { class: FIELD, id: 'syn-def-lang' }, Object.entries(LANGUAGES).map(([code, name]) => {
    const option = h('option', { value: code, text: name })
    if (code === s.default_language) option.selected = true
    return option
  }))
  const folder = h('input', { class: FIELD, id: 'syn-folder', value: s.default_folder, maxlength: '64' })
  const jitsi = h('input', { class: FIELD, id: 'syn-jitsi', value: s.jitsi_url, placeholder: 'https://meet.example.org' })
  const stt = h('input', { class: FIELD, id: 'syn-stt', value: s.stt_url, placeholder: 'http://stt.example.svc.cluster.local:8080' })
  const prosody = h('input', { class: FIELD, id: 'syn-prosody', value: s.prosody_url, placeholder: 'http://jitsi-prosody.example.svc.cluster.local:5280' })
  const message = h('p', { class: 'text-sm mt-2', role: 'status' })

  const form = h('form', { class: 'mt-4 grid gap-3' }, [
    h('label', { class: 'inline-flex items-center gap-2 txt-primary text-sm', for: 'syn-enabled' }, [enabled, h('span', { text: t('enabled') })]),
    h('label', { class: 'inline-flex items-center gap-2 txt-primary text-sm', for: 'syn-share' }, [share, h('span', { text: t('share') })]),
    h('label', { class: 'inline-flex items-center gap-2 txt-primary text-sm', for: 'syn-captions' }, [captions, h('span', { text: t('captions') })]),
    h('div', {}, [h('div', { class: 'txt-secondary text-sm', text: t('languages') }), h('div', { class: 'mt-1' }, langs)]),
    h('label', { class: 'txt-secondary text-sm', for: 'syn-def-lang', text: t('default_language') }), defLang,
    h('label', { class: 'txt-secondary text-sm', for: 'syn-folder', text: t('default_folder') }), folder,
    h('label', { class: 'txt-secondary text-sm', for: 'syn-jitsi', text: t('jitsi_url') }), jitsi,
    h('label', { class: 'txt-secondary text-sm', for: 'syn-stt', text: t('stt_url') }), stt,
    h('label', { class: 'txt-secondary text-sm', for: 'syn-prosody', text: t('prosody_url') }), prosody,
    h('p', { class: 'txt-secondary text-sm', text: `${t('secret')}: ${s.prosody_secret ? t('secret_set') : t('secret_missing')}` }),
    h('p', { class: s.transcriber_secret ? 'txt-secondary text-sm' : 'text-sm text-red-600 dark:text-red-400', text: `${t('transcriber_secret')}: ${s.transcriber_secret ? t('secret_set') : t('secret_missing')}` }),
    h('div', {}, [h('button', { type: 'submit', class: 'btn-primary px-4 py-2.5 rounded-lg text-sm font-medium', text: t('save') })]),
    message,
  ])
  form.addEventListener('submit', async (event) => {
    event.preventDefault()
    const body = {
      enabled: enabled.checked,
      share_with_participants: share.checked,
      captions: captions.checked,
      languages: langs.map((l) => l.querySelector('input')).filter((b) => b.checked).map((b) => b.value),
      default_language: defLang.value,
      default_folder: folder.value,
      jitsi_url: jitsi.value,
      stt_url: stt.value,
      prosody_url: prosody.value,
    }
    const res = await call(base, 'PUT', '/admin/settings', body)
    message.className = res.ok ? 'txt-primary text-sm mt-2' : 'text-sm mt-2 text-red-600 dark:text-red-400'
    message.textContent = res.ok ? t('saved') : res.data.message || t('error')
    if (res.ok) onSaved(res.data)
  })
  card.appendChild(form)
  return card
}

export default {
  async mount(el, ctx) {
    const base = ctx.apiBaseUrl || ''
    const root = h('div', { class: 'max-w-3xl mx-auto p-4 grid gap-4' }, [
      h('h1', { class: 'txt-primary text-xl font-semibold', text: t('title') }),
      h('p', { class: 'txt-secondary text-sm', text: t('intro') }),
    ])
    el.innerHTML = ''
    el.appendChild(root)

    const mine = await call(base, 'GET', '/me/sessions')
    if (!mine.ok) {
      root.appendChild(h('p', { class: 'text-sm text-red-600 dark:text-red-400', text: t('error') }))
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
