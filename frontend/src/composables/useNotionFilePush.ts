import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useDialog } from '@/composables/useDialog'
import { useNotification } from '@/composables/useNotification'
import { notionAppApi } from '@/services/api/notionAppApi'
import { userFacingError } from '@/utils/userFacingError'

/**
 * "Add to a Notion page" on a library file. Offered only while the person's
 * own Notion is connected; a shared connection cannot write.
 */
export function useNotionFilePush() {
  const { t } = useI18n()
  const { prompt } = useDialog()
  const { push, error } = useNotification()
  const available = ref(false)
  const busyId = ref<number | null>(null)

  const load = async () => {
    try {
      available.value = (await notionAppApi.status()).status === 'connected'
    } catch {
      available.value = false
    }
  }

  const pushFile = async (fileId: number, fileName: string) => {
    const page = await prompt({
      title: t('files.notion.title'),
      message: t('files.notion.message', { file: fileName }),
      placeholder: t('files.notion.placeholder'),
      confirmText: t('files.notion.confirm'),
    })
    if (page === null || page.trim() === '') return
    busyId.value = fileId
    try {
      const result = await notionAppApi.pushFile(fileId, page.trim())
      push({
        type: 'success',
        message: t('files.notion.done', { file: result.file_name, page: result.page_title }),
        action: {
          label: t('files.notion.open'),
          onClick: () => window.open(result.page_url, '_blank', 'noopener'),
        },
      })
    } catch (err) {
      error(userFacingError(err, t('files.notion.failed')))
    } finally {
      busyId.value = null
    }
  }

  return { available, busyId, load, pushFile }
}
