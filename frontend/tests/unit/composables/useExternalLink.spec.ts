import { describe, it, expect, beforeEach, vi, afterEach } from 'vitest'
import { useExternalLink } from '@/composables/useExternalLink'

const STORAGE_KEY = 'synaplan-skip-external-link-warning'

describe('useExternalLink', () => {
  let windowOpenSpy: ReturnType<typeof vi.spyOn>

  beforeEach(() => {
    localStorage.clear()
    windowOpenSpy = vi.spyOn(window, 'open').mockImplementation(() => null)
  })

  afterEach(() => {
    windowOpenSpy.mockRestore()
  })

  it('should initialize with closed state', () => {
    const { pendingUrl, warningOpen } = useExternalLink()

    expect(pendingUrl.value).toBe('')
    expect(warningOpen.value).toBe(false)
  })

  it('should show warning for first-time external link', () => {
    const { openExternalLink, pendingUrl, warningOpen } = useExternalLink()

    openExternalLink('https://example.com')

    expect(warningOpen.value).toBe(true)
    expect(pendingUrl.value).toBe('https://example.com')
    expect(windowOpenSpy).not.toHaveBeenCalled()
  })

  it('should open link directly if user opted out of warnings', () => {
    localStorage.setItem(STORAGE_KEY, 'true')

    const { openExternalLink, warningOpen } = useExternalLink()

    openExternalLink('https://example.com')

    expect(warningOpen.value).toBe(false)
    expect(windowOpenSpy).toHaveBeenCalledWith(
      'https://example.com',
      '_blank',
      'noopener,noreferrer'
    )
  })

  it('should close warning and reset state', () => {
    const { openExternalLink, closeWarning, pendingUrl, warningOpen } = useExternalLink()

    openExternalLink('https://example.com')
    expect(warningOpen.value).toBe(true)

    closeWarning()

    expect(warningOpen.value).toBe(false)
    expect(pendingUrl.value).toBe('')
  })

  it('should handle multiple sequential links', () => {
    const { openExternalLink, closeWarning, pendingUrl, warningOpen } = useExternalLink()

    openExternalLink('https://first.com')
    expect(pendingUrl.value).toBe('https://first.com')

    closeWarning()

    openExternalLink('https://second.com')
    expect(pendingUrl.value).toBe('https://second.com')
    expect(warningOpen.value).toBe(true)
  })
})
