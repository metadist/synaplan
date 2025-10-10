import { describe, it, expect } from 'vitest'
import { parseCommand, normalizeUrl } from '@/commands/parse'

describe('parseCommand', () => {
  it('should parse simple command', () => {
    const result = parseCommand('/list')
    expect(result).toEqual({
      command: 'list',
      args: [],
      raw: '/list',
    })
  })

  it('should parse command with args', () => {
    const result = parseCommand('/pic cats on mars')
    expect(result).toEqual({
      command: 'pic',
      args: ['cats', 'on', 'mars'],
      raw: '/pic cats on mars',
    })
  })

  it('should parse command with quoted args', () => {
    const result = parseCommand('/lang de "good morning"')
    expect(result).toEqual({
      command: 'lang',
      args: ['de', 'good morning'],
      raw: '/lang de "good morning"',
    })
  })

  it('should parse web command with URL', () => {
    const result = parseCommand('/web example.com')
    expect(result).toEqual({
      command: 'web',
      args: ['example.com'],
      raw: '/web example.com',
    })
  })

  it('should return null for non-command', () => {
    const result = parseCommand('regular message')
    expect(result).toBeNull()
  })

  it('should handle empty input', () => {
    const result = parseCommand('')
    expect(result).toBeNull()
  })

  it('should handle slash only', () => {
    const result = parseCommand('/')
    expect(result).toBeNull()
  })
})

describe('normalizeUrl', () => {
  it('should add https:// to URL without protocol', () => {
    expect(normalizeUrl('example.com')).toBe('https://example.com')
  })

  it('should keep existing https://', () => {
    expect(normalizeUrl('https://example.com')).toBe('https://example.com')
  })

  it('should keep existing http://', () => {
    expect(normalizeUrl('http://example.com')).toBe('http://example.com')
  })
})
