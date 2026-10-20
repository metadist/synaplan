import { onUnmounted, ref, watch, type Ref } from 'vue'
import { listFileCopies, type FileCopyRow } from '@/services/cloudFolderPushService'

const POLL_MS = 5000

/** Ledger rows for the files on screen. Nothing is shown when there are none. */
export function useFileCopies(fileIds: Ref<number[]>) {
  const copies = ref<FileCopyRow[]>([])
  let generation = 0
  let poll: ReturnType<typeof setTimeout> | undefined

  function stop(): void {
    if (poll !== undefined) {
      clearTimeout(poll)
      poll = undefined
    }
  }

  async function load(key: string): Promise<void> {
    const ticket = ++generation
    stop()
    if (key === '') {
      copies.value = []
      return
    }
    try {
      const rows = await listFileCopies(fileIds.value)
      if (ticket !== generation) return
      copies.value = rows
      if (rows.some((row) => row.state === 'queued')) {
        poll = setTimeout(() => {
          void load(fileIds.value.join(','))
        }, POLL_MS)
      }
    } catch {
      if (ticket !== generation) return
      copies.value = []
    }
  }

  watch(
    () => fileIds.value.join(','),
    (key) => {
      void load(key)
    },
    { immediate: true }
  )

  onUnmounted(stop)

  function forFile(fileId: number): FileCopyRow[] {
    return copies.value.filter((row) => row.file_id === fileId)
  }

  function reload(): void {
    void load(fileIds.value.join(','))
  }

  return { copies, forFile, reload }
}
