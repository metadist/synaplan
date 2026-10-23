// Contrast and heading audit used for the 2026-10-23 walk (STATUS.md).
//
// Paste into the browser console on the dev stack (or send it through CDP
// Runtime.evaluate), then call `await window.__uxWalk(['/apps', '/files'])`.
// It reports, per page, every visible text element below WCAG AA (4.5:1, or
// 3:1 for large text) and the size/weight of every heading.
//
// Two traps it handles, both of which produced false numbers in the first run:
// - Tailwind v4 emits oklch() colours; they are converted through a 1x1 canvas.
// - The route transition fades the page in; measuring before it settles
//   multiplies every colour by the running opacity. `settle()` waits for all
//   finite animations to finish.
//
// Translucent "glass" surfaces are blended down to the first opaque ancestor;
// when none exists the theme's page colour is assumed and the row is marked
// with "~" (approximate).

window.__uxAudit = function () {
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
  const blend = (top, bot) => ({
    r: top.r * top.a + bot.r * (1 - top.a),
    g: top.g * top.a + bot.g * (1 - top.a),
    b: top.b * top.a + bot.b * (1 - top.a),
    a: 1,
  })
  const backgroundOf = (el) => {
    const layers = []
    let approx = false
    for (let n = el; n && n.nodeType === 1; n = n.parentElement) {
      const cs = getComputedStyle(n)
      if (cs.backgroundImage && cs.backgroundImage !== 'none') approx = true
      const c = parse(cs.backgroundColor)
      if (c.a > 0) {
        layers.push(c)
        if (c.a >= 1) break
      }
    }
    const dark = document.documentElement.classList.contains('dark')
    let base = dark ? { r: 15, g: 17, b: 23, a: 1 } : { r: 240, g: 243, b: 250, a: 1 }
    if (layers.length && layers[layers.length - 1].a >= 1) base = layers.pop()
    else approx = true
    for (let i = layers.length - 1; i >= 0; i--) base = blend(layers[i], base)
    return { bg: base, approx }
  }
  const visible = (el) => {
    const r = el.getBoundingClientRect()
    if (r.width < 2 || r.height < 2 || r.bottom < 0 || r.top > innerHeight * 3) return false
    const cs = getComputedStyle(el)
    return cs.visibility !== 'hidden' && cs.display !== 'none' && Number(cs.opacity) > 0.05
  }

  const fails = new Map()
  let checked = 0
  for (const el of document.querySelectorAll('body *:not(script):not(style)')) {
    if (el.closest('svg')) continue
    const ownText = [...el.childNodes].some((n) => n.nodeType === 3 && n.textContent.trim().length > 1)
    if (!ownText || !visible(el)) continue
    if (el.closest('[disabled],[aria-disabled="true"]')) continue
    const cs = getComputedStyle(el)
    let opacity = 1
    for (let n = el; n && n.nodeType === 1; n = n.parentElement) opacity *= Number(getComputedStyle(n).opacity)
    const { bg, approx } = backgroundOf(el)
    const raw = parse(cs.color)
    const fg = blend({ ...raw, a: raw.a * opacity }, bg)
    const r = ratio(fg, bg)
    const size = parseFloat(cs.fontSize)
    const large = size >= 24 || (Number(cs.fontWeight) >= 700 && size >= 18.66)
    const need = large ? 3 : 4.5
    checked++
    if (r >= need) continue
    const key = [fg, bg].map((c) => [c.r, c.g, c.b].map(Math.round).join(',')).join(' on ')
    const entry = fails.get(key) || { ratio: r.toFixed(2), need, approx, n: 0, samples: [], classes: new Set() }
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

window.__uxWalk = async function (paths) {
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
