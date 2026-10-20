import { ref, watch, type Ref } from 'vue'
import { listFileCopies, type FileCopyRow } from '@/services/cloudFolderPushService'

/** Ledger rows for the files on screen. Nothing is shown when there are none. */
export function useFileCopies(fileIds: Ref<number[]>) {
  const copies = ref<FileCopyRow[]>([])

  watch(
    () => fileIds.value.join(','),
    async (key) => {
      if (key === '') {
        copies.value = []
        return
      }
      try {
        copies.value = await listFileCopies(fileIds.value)
      } catch {
        copies.value = []
      }
    },
    { immediate: true }
  )

  function forFile(fileId: number): FileCopyRow[] {
    return copies.value.filter((row) => row.file_id === fileId)
  }

  return { copies, forFile }
}
