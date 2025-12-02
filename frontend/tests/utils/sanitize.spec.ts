import { describe, it, expect } from 'vitest'

// XSS Protection Tests
describe('XSS Protection', () => {
  it('should not allow script execution via textContent', () => {
    const maliciousInput = '<script>alert("XSS")</script>'
    const div = document.createElement('div')
    div.textContent = maliciousInput
    
    // textContent treats everything as text, so no script tags exist
    expect(div.querySelector('script')).toBe(null)
    expect(div.textContent).toBe(maliciousInput)
  })

  it('should not execute scripts in textContent', () => {
    const div = document.createElement('div')
    div.textContent = '<img src=x onerror=alert(1)>'
    
    // No img element should be created
    expect(div.querySelector('img')).toBe(null)
    expect(div.textContent).toContain('<img')
  })

  it('should show danger of innerHTML with user input', () => {
    const div = document.createElement('div')
    div.innerHTML = '<script>alert("XSS")</script>'
    
    // innerHTML creates actual script tags (but won't execute in jsdom)
    // This demonstrates why we avoid innerHTML with user input
    expect(div.querySelector('script')).not.toBe(null)
  })
})

