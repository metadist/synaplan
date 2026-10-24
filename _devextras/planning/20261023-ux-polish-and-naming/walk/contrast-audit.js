// Contrast and heading audit used for the 2026-10-23 walk (STATUS.md).
//
// Paste into the browser console on the dev stack (or send it through CDP
// Runtime.evaluate), then call `await window.__uxWalk(['/apps', '/files'])`.
// It reports, per page, every visible text element below WCAG AA (4.5:1, or
// 3:1 for large text) and the size/weight of every heading.
//
// Traps it handles, each of which produced false numbers before:
// - Tailwind v4 emits oklch() colours; they are converted through a 1x1 canvas.
// - The route transition fades the page in; measuring before it settles
//   multiplies every colour by the running opacity. `settle()` waits for all
//   finite animations to finish.
// - CSS opacity composites a whole subtree, background included. Text and its
//   background are therefore both composited through every opacity group on
//   the ancestor chain; fading only the text over an unfaded background
//   overstates the contrast.
//
// Only the element's own ancestor chain is composited; an unrelated sibling
// painted underneath is not seen. Where an ancestor has a background image, or
// no opaque layer exists, the theme's page colour is assumed and the row is
// marked with "~" (approximate).
//
// `window.__uxAuditSelfTest()` runs the known cases (one of them the opacity
// trap) and throws on a regression; `__uxWalk()` runs it first.

;(() => {
  const ctx = (() => {
    const c = document.createElement('canvas')
    c.width = c.height = 1
    return c.getContext('2d', { willReadFrequently: true })
  })()

  const parse = (c) => {
    const m = c.match(/rgba?\(([^)]+)\)/)
    if (m) {
      const p = m[1].split(/[ ,/]+/).filter(Boolean).map(Number)
      return { r: p[0], g: p[1], b: p[2], a: p.length > 3 ? p[3] : 1 }
    }
    ctx.clearRect(0, 0, 1, 1)
    ctx.fillStyle = '#000'
    ctx.fillStyle = c
    ctx.fillRect(0, 0, 1, 1)
    const d = ctx.getImageData(0, 0, 1, 1).data
    return d[3] === 0 ? { r: 0, g: 0, b: 0, a: 0 } : { r: d[0], g: d[1], b: d[2], a: d[3] / 255 }
  }

  const lum = ({ r, g, b }) => {
    const f = (v) => {
      v /= 255
      return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4)
    }
    return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b)
  }

  const ratio = (a, b) => {
    const l1 = lum(a)
    const l2 = lum(b)
    return (Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05)
  }

  // Premultiplied colours: { r, g, b } already multiplied by a.
  const premultiply = (c) => ({ r: c.r * c.a, g: c.g * c.a, b: c.b * c.a, a: c.a })
  const over = (top, bottom) => ({
    r: top.r + bottom.r * (1 - top.a),
    g: top.g + bottom.g * (1 - top.a),
    b: top.b + bottom.b * (1 - top.a),
    a: top.a + bottom.a * (1 - top.a),
  })
  const fade = (c, o) => ({ r: c.r * o, g: c.g * o, b: c.b * o, a: c.a * o })
  const TRANSPARENT = { r: 0, g: 0, b: 0, a: 0 }

  const pageColour = () =>
    document.documentElement.classList.contains('dark')
      ? { r: 15, g: 17, b: 23, a: 1 }
      : { r: 240, g: 243, b: 250, a: 1 }

  // Renders one pixel of `el` from the root down: each element paints its
  // background, then its children on top, and the result is faded by the
  // element's opacity as one group. With `text` set, the text colour is the
  // topmost layer inside `el`; without it, the pixel next to the glyph.
  const renderPixel = (el, text) => {
    const chain = []
    for (let n = el; n && n.nodeType === 1; n = n.parentElement) chain.unshift(n)
    let approx = false
    let content = text ? premultiply(text) : TRANSPARENT
    for (let i = chain.length - 1; i >= 0; i--) {
      const cs = getComputedStyle(chain[i])
      if (cs.backgroundImage && cs.backgroundImage !== 'none') approx = true
      content = over(content, premultiply(parse(cs.backgroundColor)))
      content = fade(content, Number(cs.opacity))
    }
    if (content.a < 0.999) approx = true
    const out = over(content, premultiply(pageColour()))
    return { colour: { r: out.r, g: out.g, b: out.b, a: 1 }, approx }
  }

  const measure = (el) => {
    const cs = getComputedStyle(el)
    const fg = renderPixel(el, parse(cs.color))
    const bg = renderPixel(el, null)
    const size = parseFloat(cs.fontSize)
    const large = size >= 24 || (Number(cs.fontWeight) >= 700 && size >= 18.66)
    return {
      fg: fg.colour,
      bg: bg.colour,
      ratio: ratio(fg.colour, bg.colour),
      need: large ? 3 : 4.5,
      approx: fg.approx || bg.approx,
    }
  }

  const visible = (el) => {
    const r = el.getBoundingClientRect()
    if (r.width < 1 || r.height < 1 || r.bottom < 0 || r.top > innerHeight * 3) return false
    const cs = getComputedStyle(el)
    return cs.visibility !== 'hidden' && cs.display !== 'none' && Number(cs.opacity) > 0.05
  }

  const hasOwnText = (el) => [...el.childNodes].some((n) => n.nodeType === 3 && n.textContent.trim().length > 0)

  window.__uxAudit = function () {
    const fails = new Map()
    let checked = 0
    for (const el of document.querySelectorAll('body *:not(script):not(style)')) {
      if (el.closest('svg')) continue
      if (!hasOwnText(el) || !visible(el)) continue
      if (el.closest('[disabled],[aria-disabled="true"]')) continue
      // A closed drawer keeps its layout box but is not on screen.
      if (el.closest('[aria-hidden="true"],[inert],[hidden]')) continue
      const m = measure(el)
      checked++
      if (m.ratio >= m.need) continue
      const key = [m.fg, m.bg].map((c) => [c.r, c.g, c.b].map(Math.round).join(',')).join(' on ')
      const entry = fails.get(key) || { ratio: m.ratio.toFixed(2), need: m.need, approx: m.approx, n: 0, samples: [], classes: new Set() }
      entry.n++
      if (entry.samples.length < 3) entry.samples.push(el.textContent.trim().slice(0, 40))
      if (typeof el.className === 'string') {
        entry.classes.add(el.className.split(' ').filter((c) => /txt-|text-|opacity|pill|btn|status/.test(c)).slice(0, 4).join(' '))
      }
      fails.set(key, entry)
    }

    const headings = []
    for (const el of document.querySelectorAll('h1,h2,h3,h4,h5')) {
      if (!visible(el)) continue
      const cs = getComputedStyle(el)
      headings.push(`${el.tagName} ${cs.fontSize}/${cs.fontWeight} "${el.textContent.trim().slice(0, 40)}"`)
    }

    return {
      url: location.pathname + location.search,
      theme: document.documentElement.className,
      checked,
      failing: [...fails.values()].reduce((s, v) => s + v.n, 0),
      fails: [...fails.entries()]
        .sort((a, b) => b[1].n - a[1].n)
        .map(([k, v]) => `${v.ratio}<${v.need}${v.approx ? '~' : ''} x${v.n} [${k}] {${[...v.classes].filter(Boolean).join(' | ')}} ${JSON.stringify(v.samples)}`),
      headings,
    }
  }

  window.__uxAuditSelfTest = function () {
    const host = document.createElement('div')
    host.style.cssText = 'position:fixed;left:0;top:0;z-index:2147483647;background:#fff;padding:4px;font:16px sans-serif'
    host.innerHTML = [
      // White text on a black panel at 0.5 opacity over white renders at ~3.98:1.
      // Fading only the text would compare grey with black and claim ~5.3:1.
      '<div data-case="opacity-group" style="background:#000;color:#fff;opacity:.5">Faded panel</div>',
      // A lone counter is text too.
      '<span data-case="single-char" style="color:#777">0</span>',
      // Plain black on white, the 21:1 anchor.
      '<span data-case="anchor" style="color:#000">Anchor</span>',
    ].join('')
    document.body.appendChild(host)
    try {
      const pick = (c) => host.querySelector(`[data-case="${c}"]`)
      const group = measure(pick('opacity-group')).ratio
      const anchor = measure(pick('anchor')).ratio
      const single = pick('single-char')
      const problems = []
      if (Math.abs(group - 3.98) > 0.1) problems.push(`opacity-group: expected ~3.98, got ${group.toFixed(2)}`)
      if (Math.abs(anchor - 21) > 0.1) problems.push(`anchor: expected 21, got ${anchor.toFixed(2)}`)
      if (!hasOwnText(single) || !visible(single)) problems.push('single-char: a one-character text node is not audited')
      if (problems.length) throw new Error(`contrast-audit self-test failed: ${problems.join('; ')}`)
      return { ok: true, opacityGroup: Number(group.toFixed(2)), anchor: Number(anchor.toFixed(2)) }
    } finally {
      host.remove()
    }
  }

  window.__uxWalk = async function (paths) {
    window.__uxAuditSelfTest()
    const router = document.querySelector('#app').__vue_app__.config.globalProperties.$router
    const settle = async () => {
      await new Promise((r) => setTimeout(r, 1200))
      for (let i = 0; i < 30; i++) {
        const running = document.getAnimations().filter((a) => {
          const timing = a.effect && a.effect.getTiming ? a.effect.getTiming() : null
          return a.playState === 'running' && !(timing && timing.iterations === Infinity)
        })
        if (!running.length) return
        await new Promise((r) => setTimeout(r, 150))
      }
    }
    const out = []
    for (const p of paths) {
      await router.push(p)
      await settle()
      out.push(window.__uxAudit())
    }
    return out
  }
})()
