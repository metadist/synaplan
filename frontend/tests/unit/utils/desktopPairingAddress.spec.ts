import { describe, expect, it } from 'vitest'

import { desktopPairingAddress } from '@/utils/desktopPairingAddress'

describe('desktopPairingAddress', () => {
  it('maps the Vite dev origin to the published API port', () => {
    expect(desktopPairingAddress('http://localhost:5173')).toBe('http://localhost:8000')
    expect(desktopPairingAddress('http://127.0.0.1:4173')).toBe('http://127.0.0.1:8000')
  })

  it('keeps a production or already-correct API origin', () => {
    expect(desktopPairingAddress('https://web.synaplan.com')).toBe('https://web.synaplan.com')
    expect(desktopPairingAddress('http://localhost:8000')).toBe('http://localhost:8000')
  })

  it('does not rewrite Keycloak on :8080 into a pairing address', () => {
    expect(desktopPairingAddress('http://localhost:8080')).toBe('http://localhost:8080')
  })
})
