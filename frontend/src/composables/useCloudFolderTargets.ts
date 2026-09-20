import { onMounted, ref } from 'vue'
import { connectionsApi } from '@/services/api/connectionsApi'
import { cloudFolderTargetsFrom, type CloudFolderTargetItem } from '@/services/cloudFolderTargets'

/**
 * Nextcloud / OpenCloud folders the Files "Send to cloud" action may use.
 * Empty means the control is absent (U11) — never a teaser.
 */
export function useCloudFolderTargets() {
  const targets = ref<CloudFolderTargetItem[]>([])

  async function reload(): Promise<void> {
    try {
      targets.value = cloudFolderTargetsFrom(await connectionsApi.list())
    } catch {
      targets.value = []
    }
  }

  onMounted(() => {
    void reload()
  })

  return { targets, reload }
}
