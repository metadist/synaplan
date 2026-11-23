import { describe, expect, it } from 'vitest'
import { deriveAgentDescription, deriveAgentGreeting, derivedTexts } from '@/utils/agentDescribe'

describe('deriveAgentDescription', () => {
  it('takes the first sentence of the instructions', () => {
    expect(
      deriveAgentDescription(
        'Write an offer from the price list and cite every price. Use the current list only.'
      )
    ).toBe('Write an offer from the price list and cite every price.')
  })

  it('drops the "You are" framing instructions usually start with', () => {
    expect(
      deriveAgentDescription('You are an assistant that summarises contracts in plain words.')
    ).toBe('Assistant that summarises contracts in plain words.')
  })

  it('skips headings and markdown noise to reach the first real sentence', () => {
    expect(deriveAgentDescription('# Role\n**Answer** handbook questions, with the passage.')).toBe(
      'Answer handbook questions, with the passage.'
    )
  })

  it('returns empty when there is nothing usable', () => {
    expect(deriveAgentDescription('')).toBe('')
    expect(deriveAgentDescription('# Notes\n- ok')).toBe('')
  })

  it('caps a very long first sentence at a word boundary', () => {
    const long =
      'Collect the most important open source news from the configured feeds every morning and ' +
      'sort the items by topic so the reader can scan the whole landscape in two minutes flat today'
    const derived = deriveAgentDescription(long)
    expect(derived.length).toBeLessThanOrEqual(161)
    expect(derived.endsWith('.')).toBe(true)
    expect(derived).not.toContain('  ')
  })
})

describe('derivedTexts', () => {
  const base = {
    instructions: 'Write an offer from the price list.',
    description: '',
    greeting: '',
    name: 'Offer helper',
    greetingTemplate: "Hi, I'm {name}. How can I help?",
  }

  it('fills both empty fields from the instructions', () => {
    expect(derivedTexts(base)).toEqual({
      description: 'Write an offer from the price list.',
      greeting: "Hi, I'm Offer helper. How can I help?",
    })
  })

  it('never overwrites a text the user typed', () => {
    expect(derivedTexts({ ...base, description: 'My own words.', greeting: 'Hey there.' })).toEqual(
      {}
    )
  })

  it('derives nothing without instructions', () => {
    expect(derivedTexts({ ...base, instructions: '' })).toEqual({})
    expect(derivedTexts({ ...base, instructions: '  \n ' })).toEqual({})
  })
})

describe('deriveAgentGreeting', () => {
  it('fills the agent name into the template', () => {
    expect(deriveAgentGreeting('Offer helper', "Hi, I'm {name}. How can I help?")).toBe(
      "Hi, I'm Offer helper. How can I help?"
    )
  })

  it('stays empty without a name', () => {
    expect(deriveAgentGreeting('  ', "Hi, I'm {name}.")).toBe('')
    expect(deriveAgentGreeting('Offer helper', 'Hello there.')).toBe('')
  })
})
