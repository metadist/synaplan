export default {
  /**
   * @param {HTMLElement} el
   * @param {{ t?: (key: string) => string, userId?: string, apiBaseUrl?: string, pluginBaseUrl?: string }} context
   */
  mount(el, context) {
    const t = (key) => (typeof context.t === 'function' ? context.t(key) : key)
    const line = document.createElement('p')
    el.replaceChildren()
    const wrap = document.createElement('div')
    wrap.style.padding = '20px'
    wrap.style.fontFamily = 'sans-serif'
    const title = document.createElement('h2')
    title.textContent = t('title')
    title.style.color = 'var(--brand, #007bff)'
    const body = document.createElement('p')
    body.textContent = t('body')
    const contextTitle = document.createElement('strong')
    contextTitle.textContent = t('context')
    const list = document.createElement('ul')
    for (const [label, value] of [
      [t('userId'), context.userId],
      [t('apiBase'), context.apiBaseUrl],
      [t('pluginBase'), context.pluginBaseUrl],
    ]) {
      const item = document.createElement('li')
      item.textContent = `${label}: ${value ?? ''}`
      list.appendChild(item)
    }
    const button = document.createElement('button')
    button.type = 'button'
    button.textContent = t('button')
    button.style.marginTop = '20px'
    button.style.padding = '8px 16px'
    button.addEventListener('click', () => {
      line.textContent = t('clicked')
    })
    wrap.append(title, body, contextTitle, list, button, line)
    el.appendChild(wrap)
  },
}
