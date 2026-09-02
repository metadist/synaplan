import { computed, ref } from 'vue'
import { useConfigStore } from '@/stores/config'
import { isNativeApp } from '@/services/api/nativeRuntime'
import { detectBrowserOs } from '@/utils/detectBrowserOs'
import {
  announcements,
  type Announcement,
  type AnnouncementAction,
  type AnnouncementContext,
} from '@/data/announcements'

/**
 * Which announcements this browser has already closed. The `v1` suffix
 * describes the stored shape (an array of ids); raising it would show every
 * announcement again, so it only changes if that shape does.
 */
export const ANNOUNCEMENTS_SEEN_KEY = 'synaplan.announcements.seen.v1'

/**
 * Picks the announcement to show, or nothing. Kept free of Vue and browser
 * state so the decision — the part that would quietly annoy every user if it
 * were wrong — can be tested directly.
 */
export function selectAnnouncement(
  catalogue: Announcement[],
  seenIds: readonly string[],
  context: AnnouncementContext,
  now: number
): Announcement | null {
  return (
    catalogue.find(
      (announcement) =>
        !seenIds.includes(announcement.id) &&
        !hasExpired(announcement, now) &&
        announcement.applies(context)
    ) ?? null
  )
}

/** Expiry runs to the end of the named day, so `until` reads inclusively. */
function hasExpired(announcement: Announcement, now: number): boolean {
  const deadline = Date.parse(`${announcement.until}T23:59:59Z`)

  // An unparseable date is a typo in the catalogue. Retiring the announcement
  // is the safer reading: showing it forever is the outcome nobody can undo.
  return Number.isNaN(deadline) || now > deadline
}

function loadSeen(): string[] {
  try {
    const parsed: unknown = JSON.parse(localStorage.getItem(ANNOUNCEMENTS_SEEN_KEY) ?? '[]')

    return Array.isArray(parsed) ? parsed.filter((id): id is string => 'string' === typeof id) : []
  } catch {
    // Unreadable or unavailable storage. Starting empty means an announcement
    // may reappear on the next visit, which is preferable to suppressing every
    // future one because of a single corrupt value.
    return []
  }
}

/**
 * An announcement with everything already resolved for the current visitor, so
 * the modal renders values instead of re-deriving them.
 */
export interface ActiveAnnouncement {
  id: string
  i18nKey: string
  image?: string
  /** Empty when the announcement is an acknowledgement without a next step. */
  actions: AnnouncementAction[]
}

/**
 * Module-level so every caller shares one list: the modal must not reappear
 * elsewhere in the same session just because another component mounted.
 */
const seen = ref<string[]>(loadSeen())

export function useAnnouncements() {
  const config = useConfigStore()

  const current = computed<ActiveAnnouncement | null>(() => {
    const context: AnnouncementContext = {
      iosAppUrl: config.mobile.iosAppUrl,
      androidAppUrl: config.mobile.androidAppUrl,
      isNativeApp: isNativeApp(),
      deviceOs: detectBrowserOs(),
    }

    const announcement = selectAnnouncement(announcements, seen.value, context, Date.now())

    if (!announcement) {
      return null
    }

    return {
      id: announcement.id,
      i18nKey: announcement.i18nKey,
      image: announcement.image,
      actions: announcement.actions?.(context) ?? [],
    }
  })

  /** Closing is final: whichever way the user got rid of it, it stays gone. */
  function dismiss(id: string): void {
    if (seen.value.includes(id)) {
      return
    }

    seen.value = [...seen.value, id]

    try {
      localStorage.setItem(ANNOUNCEMENTS_SEEN_KEY, JSON.stringify(seen.value))
    } catch {
      // Private mode or a full quota: the in-memory list still keeps it closed
      // for this session, which is the part the user just asked for.
    }
  }

  return { current, dismiss }
}
