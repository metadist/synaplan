import { afterEach, describe, expect, it, vi } from 'vitest'
import SynaplanWidget from '@/widget'

const BUTTON_SELECTOR = '#synaplan-widget-button'

// `isPreview` skips the remote config fetch, so the launcher renders from the
// values passed to `init()` alone.
async function renderLauncher(config: Record<string, unknown>): Promise<Element> {
  await SynaplanWidget.init({
    widgetId: 'wdg_unit_test',
    apiUrl: 'http://localhost',
    isPreview: true,
    ...config,
  })

  await vi.waitFor(() => {
    expect(document.querySelector(BUTTON_SELECTOR)).not.toBeNull()
  })

  return document.querySelector(BUTTON_SELECTOR)!
}

describe('widget launcher icon', () => {
  afterEach(() => {
    SynaplanWidget.destroy()
  })

  it('renders a built-in icon as inline SVG', async () => {
    const button = await renderLauncher({ buttonIcon: 'headset', iconColor: '#123456' })

    const svg = button.querySelector('svg')
    expect(svg).not.toBeNull()
    expect(svg?.getAttribute('stroke')).toBe('#123456')
  })

  it('renders a custom icon without letting its URL inject attributes', async () => {
    // The backend only prefix-checks the icon URL, so this value is storable.
    const hostileUrl = 'https://example.com/icon.png" onerror="window.widgetXss = true'

    const button = await renderLauncher({ buttonIcon: 'custom', buttonIconUrl: hostileUrl })

    const images = button.querySelectorAll('img')
    expect(images).toHaveLength(1)
    expect(images[0].getAttribute('src')).toBe(hostileUrl)
    expect(images[0].getAttribute('onerror')).toBeNull()
  })
})
