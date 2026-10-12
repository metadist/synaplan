import { ref, readonly } from 'vue'
import { driver, type DriveStep } from 'driver.js'
import 'driver.js/dist/driver.css'
import { i18n } from '@/i18n'
import { useAuthStore } from '@/stores/auth'
import { httpClient } from '@/services/api/httpClient'
import { GetApiProfileGetResponseSchema } from '@/generated/api-schemas'
import { getTour } from '@/tours'
import type { TourDefinition } from '@/tours/types'

const GUEST_STORAGE_KEY = 'synaplan.toursSeen'
const TARGET_WAIT_MS = 4000
/** Later steps may sit in content that loads after the first target. */
const LATER_TARGET_WAIT_MS = 1500

const seen = ref<string[]>([])
const activeTour = ref<string | null>(null)
let loadedFor: 'guest' | number | null = null
let loading: Promise<void> | null = null

function readGuestSeen(): string[] {
  try {
    const raw = localStorage.getItem(GUEST_STORAGE_KEY)
    const parsed: unknown = raw ? JSON.parse(raw) : []
    return Array.isArray(parsed) ? parsed.filter((id): id is string => typeof id === 'string') : []
  } catch {
    return []
  }
}

function writeGuestSeen(ids: string[]): void {
  try {
    localStorage.setItem(GUEST_STORAGE_KEY, JSON.stringify(ids))
  } catch {
    // Private mode: the tour simply shows again next time.
  }
}

/** Keeps the first occurrence of each id. Remote ids stay ahead of local ones. */
function mergeIds(...lists: string[][]): string[] {
  const merged: string[] = []
  for (const list of lists) {
    for (const id of list) {
      if (!merged.includes(id)) merged.push(id)
    }
  }
  return merged
}

/**
 * Records ids in this browser immediately, before any account request.
 * A refresh in the same moment must not open the tour again.
 */
function remember(ids: string[]): void {
  const merged = mergeIds(readGuestSeen(), seen.value, ids)
  writeGuestSeen(merged)
  seen.value = merged
}

function ownerKey(): 'guest' | number {
  const auth = useAuthStore()
  return auth.isAuthenticated && auth.user ? auth.user.id : 'guest'
}

async function ensureLoaded(): Promise<void> {
  const owner = ownerKey()
  if (loadedFor === owner) return
  if (loading) return loading
  loading = (async () => {
    let remote: string[] = []
    if (owner !== 'guest') {
      try {
        const response = await httpClient('/api/v1/profile', {
          method: 'GET',
          schema: GetApiProfileGetResponseSchema,
        })
        remote = response.profile?.toursSeen ?? []
      } catch {
        remote = []
      }
    }
    // The browser copy wins over a stale or empty account list. Closing the
    // tour writes localStorage before the save request, so a refresh cannot
    // forget a dismissal the account has not stored yet.
    const merged = mergeIds(remote, readGuestSeen())
    seen.value = merged
    writeGuestSeen(merged)
    loadedFor = owner
    if (owner !== 'guest' && merged.some((id) => !remote.includes(id))) {
      void persist()
    }
  })()
  try {
    await loading
  } finally {
    loading = null
  }
}

async function persist(): Promise<void> {
  writeGuestSeen(seen.value)
  if (ownerKey() === 'guest') return
  try {
    await httpClient('/api/v1/profile', {
      method: 'PUT',
      body: JSON.stringify({ toursSeen: seen.value }),
    })
  } catch {
    // The local copy keeps the tour from repeating on this device.
  }
}

async function markSeen(id: string): Promise<void> {
  remember([id])
  await ensureLoaded()
  if (!seen.value.includes(id)) seen.value = mergeIds(seen.value, [id])
  await persist()
}

async function unmarkSeen(id: string): Promise<void> {
  await ensureLoaded()
  if (!seen.value.includes(id)) return
  seen.value = seen.value.filter((entry) => entry !== id)
  await persist()
}

function escapeHtml(value: string): string {
  return value
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
}

function findTarget(target: string): HTMLElement | null {
  const nodes = document.querySelectorAll<HTMLElement>(`[data-tour="${target}"]`)
  for (const node of nodes) {
    if (node.getClientRects().length > 0) return node
  }
  return null
}

/** Resolves once the element is in the DOM and visible, or null after the wait. */
function waitForTarget(target: string, timeoutMs = TARGET_WAIT_MS): Promise<HTMLElement | null> {
  const found = findTarget(target)
  if (found) return Promise.resolve(found)
  return new Promise((resolve) => {
    const observer = new MutationObserver(() => {
      const element = findTarget(target)
      if (element) {
        observer.disconnect()
        window.clearTimeout(timer)
        resolve(element)
      }
    })
    const timer = window.setTimeout(() => {
      observer.disconnect()
      resolve(null)
    }, timeoutMs)
    observer.observe(document.body, { childList: true, subtree: true, attributes: true })
  })
}

function buildSteps(tour: TourDefinition): DriveStep[] {
  const t = i18n.global.t
  const steps: DriveStep[] = []
  for (const step of tour.steps) {
    const element = step.target ? findTarget(step.target) : null
    if (step.target && !element) continue
    steps.push({
      ...(element ? { element } : {}),
      popover: {
        title: escapeHtml(t(`tours.${tour.id}.${step.stepKey}.title`)),
        description: escapeHtml(t(`tours.${tour.id}.${step.stepKey}.body`)),
        side: step.side ?? 'bottom',
        align: 'start',
      },
    })
  }
  return steps
}

function startTour(id: string): boolean {
  const tour = getTour(id)
  if (!tour || activeTour.value) return false
  const steps = buildSteps(tour)
  if (steps.length === 0) return false

  const t = i18n.global.t
  activeTour.value = id

  const finish = (): void => {
    activeTour.value = null
    void markSeen(id)
  }

  const instance = driver({
    steps,
    showProgress: steps.length > 1,
    progressText: t('tours.common.progress', { current: '{{current}}', total: '{{total}}' }),
    nextBtnText: t('tours.common.next'),
    prevBtnText: t('tours.common.back'),
    doneBtnText: t('tours.common.done'),
    popoverClass: 'synaplan-tour',
    overlayOpacity: 0.45,
    stagePadding: 6,
    stageRadius: 12,
    allowClose: true,
    smoothScroll: true,
    // The close button does not emit closeClick once this hook is set, so it
    // has to record the dismissal and destroy the tour itself.
    onCloseClick: () => {
      finish()
      instance.destroy()
    },
    // Escape, the overlay and the last step come through here. driver.js
    // skips onDestroyed when the tour closes before its first highlight has
    // settled, so every user close is handled in these two hooks instead.
    onDestroyStarted: () => {
      finish()
      instance.destroy()
    },
  })
  instance.drive()
  return true
}

/**
 * Starts the tour once per account. Skipped under browser automation so a
 * Playwright run never meets an overlay it did not ask for.
 */
function alreadySeen(id: string): boolean {
  return seen.value.includes(id) || readGuestSeen().includes(id)
}

async function maybeAutoStart(id: string): Promise<void> {
  if (typeof navigator !== 'undefined' && navigator.webdriver) return
  const tour = getTour(id)
  if (!tour || activeTour.value) return
  // Closed in this browser: do not wait for the account. A refresh must not
  // open the tour again while that save is still in flight or came back empty.
  if (readGuestSeen().includes(id)) {
    void ensureLoaded()
    return
  }
  await ensureLoaded()
  if (alreadySeen(id)) return
  const [firstTarget, ...laterTargets] = tour.steps.flatMap((step) =>
    step.target ? [step.target] : []
  )
  if (firstTarget && !(await waitForTarget(firstTarget))) return
  if (alreadySeen(id) || activeTour.value) return
  await Promise.all(laterTargets.map((target) => waitForTarget(target, LATER_TARGET_WAIT_MS)))
  if (firstTarget && !findTarget(firstTarget)) return
  if (alreadySeen(id) || activeTour.value) return
  startTour(id)
}

function hasTour(id: string | undefined | null): boolean {
  return !!id && !!getTour(id)
}

/** Forget the cached state, e.g. after logout. */
function resetTourCache(): void {
  loadedFor = null
  seen.value = []
}

export function useTour() {
  return {
    seen: readonly(seen),
    activeTour: readonly(activeTour),
    startTour,
    maybeAutoStart,
    hasTour,
    markSeen,
    unmarkSeen,
    ensureLoaded,
    resetTourCache,
  }
}
