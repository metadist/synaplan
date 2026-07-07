import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { httpClient, getApiBaseUrl } from '@/services/api/httpClient'

/**
 * Incognito chat session state.
 *
 * An incognito session is a purely ephemeral conversation: the backend
 * processes every turn in-memory (no BMESSAGES/BCHATS rows, no memory
 * extraction) and the frontend keeps the transcript only in the in-memory
 * history store. Deliberately NO localStorage — nothing may survive the
 * session.
 *
 * Files created during the session (uploads, generated media, TTS replies)
 * are marked ephemeral by the backend; their ids are tracked here so
 * `endSession()` can delete them (`DELETE /api/v1/files/{id}`). A `pagehide`
 * keepalive request covers tab-close as best effort; the backend reaper
 * (`app:files:reap-ephemeral`) is the safety net for everything else.
 */
export const useIncognitoStore = defineStore('incognito', () => {
  const active = ref(false)
  const ephemeralFileIds = ref<number[]>([])

  const hasEphemeralFiles = computed(() => ephemeralFileIds.value.length > 0)

  /** Best-effort file cleanup when the tab closes mid-session. */
  const handlePageHide = () => {
    if (!active.value) return
    for (const fileId of ephemeralFileIds.value) {
      // sendBeacon is POST-only; a keepalive DELETE survives page unload too.
      void fetch(`${getApiBaseUrl()}/api/v1/files/${fileId}`, {
        method: 'DELETE',
        credentials: 'include',
        keepalive: true,
      }).catch(() => {
        // Tab is closing — the backend reaper cleans up whatever this misses.
      })
    }
  }

  function startSession(): void {
    if (active.value) return
    active.value = true
    ephemeralFileIds.value = []
    window.addEventListener('pagehide', handlePageHide)
  }

  /** Track a file created during the session so endSession() can delete it. */
  function registerFile(fileId: number): void {
    if (!active.value || fileId <= 0) return
    if (!ephemeralFileIds.value.includes(fileId)) {
      ephemeralFileIds.value.push(fileId)
    }
  }

  /**
   * End the session: discard state and delete every ephemeral file. File
   * deletion is best effort — failures are swallowed because the backend
   * reaper removes leftovers anyway.
   */
  async function endSession(): Promise<void> {
    if (!active.value) return
    active.value = false
    window.removeEventListener('pagehide', handlePageHide)

    const fileIds = [...ephemeralFileIds.value]
    ephemeralFileIds.value = []

    await Promise.allSettled(
      fileIds.map((fileId) => httpClient(`/api/v1/files/${fileId}`, { method: 'DELETE' }))
    )
  }

  return {
    active,
    ephemeralFileIds,
    hasEphemeralFiles,
    startSession,
    registerFile,
    endSession,
  }
})
