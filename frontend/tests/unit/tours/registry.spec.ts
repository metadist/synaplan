import { describe, expect, it } from 'vitest'
import { readdirSync, readFileSync, statSync } from 'node:fs'
import { join, resolve } from 'node:path'
import { getTour, GUEST_CHATS_TOUR_ID, resolveRouteTourId, tourIds } from '@/tours'

const SRC = resolve(__dirname, '../../../src')
const LOCALES = ['en', 'de', 'es', 'fr', 'tr'] as const

function sourceFiles(dir: string): string[] {
  return readdirSync(dir).flatMap((name) => {
    const path = join(dir, name)
    if (statSync(path).isDirectory()) return name === 'generated' ? [] : sourceFiles(path)
    return /\.(vue|ts)$/.test(name) ? [path] : []
  })
}

const sources = sourceFiles(SRC)
  .map((path) => readFileSync(path, 'utf8'))
  .join('\n')

function hasTarget(target: string): boolean {
  if (sources.includes(`data-tour="${target}"`)) return true
  if (sources.includes(`? '${target}'`)) return true
  const rail = /^rail-(.+)$/.exec(target)
  return (
    !!rail &&
    sources.includes('data-tour="`rail-${section.key}`"') &&
    sources.includes(`key: '${rail[1]}'`)
  )
}

function readTours(locale: string): Record<string, unknown> {
  const file = join(SRC, 'i18n/locales', locale, 'tools.json')
  return (JSON.parse(readFileSync(file, 'utf8')) as { tours: Record<string, unknown> }).tours
}

describe('tour registry', () => {
  it('covers every rail area', () => {
    expect(tourIds()).toEqual(
      expect.arrayContaining([
        'chats',
        'library',
        'assistants',
        'apps',
        'admin',
        GUEST_CHATS_TOUR_ID,
      ])
    )
  })

  it('gives guests the Chats-only tour and keeps the member chats tour', () => {
    expect(resolveRouteTourId('chats', false)).toBe(GUEST_CHATS_TOUR_ID)
    expect(resolveRouteTourId('chats', true)).toBe('chats')
    expect(resolveRouteTourId('admin', false)).toBe('admin')
    expect(getTour(GUEST_CHATS_TOUR_ID)?.steps.some((step) => step.target === 'chat-new')).toBe(
      false
    )
    expect(getTour(GUEST_CHATS_TOUR_ID)?.steps.some((step) => step.stepKey === 'plan')).toBe(true)
  })

  it.each(tourIds())('%s keeps to a short tour whose targets exist', (id) => {
    const tour = getTour(id)!
    expect(tour.steps.length).toBeGreaterThanOrEqual(2)
    expect(tour.steps.length).toBeLessThanOrEqual(6)
    for (const step of tour.steps) {
      if (step.target) expect(hasTarget(step.target), step.target).toBe(true)
    }
  })

  it.each(LOCALES)('every locale names End tour', (locale) => {
    const tours = readTours(locale) as { common?: { end?: string } }
    expect(tours.common?.end, `${locale} tours.common.end`).toBeTruthy()
  })

  it.each(LOCALES)('every step has a title and body in %s', (locale) => {
    const tours = readTours(locale) as Record<
      string,
      Record<string, { title?: string; body?: string }>
    >
    for (const id of tourIds()) {
      for (const step of getTour(id)!.steps) {
        const copy = tours[id]?.[step.stepKey]
        expect(copy?.title, `${locale} ${id}.${step.stepKey}`).toBeTruthy()
        expect(copy?.body, `${locale} ${id}.${step.stepKey}`).toBeTruthy()
      }
    }
  })
})
