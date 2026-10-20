import { onMounted, ref } from 'vue'
import { connectionsApi } from '@/services/api/connectionsApi'
import { platformLinksApi } from '@/services/api/platformLinksApi'
import {
  cloudFolderTargetsFrom,
  platformCopyTargets,
  type CloudFolderTargetItem,
} from '@/services/cloudFolderTargets'

/**
 * Linked clouds that accept a copy, then WebDAV folders.
 * Empty means the control is absent — never a teaser.
 */
export function useCloudFolderTargets() {
  const targets = ref<CloudFolderTargetItem[]>([])

  async function reload(): Promise<void> {
    let webdav: CloudFolderTargetItem[]
    try {
      webdav = cloudFolderTargetsFrom(await connectionsApi.list())
    } catch {
      webdav = []
    }
    let linked: CloudFolderTargetItem[]
    try {
      linked = platformCopyTargets(await platformLinksApi.listMine())
    } catch {
      linked = []
    }
    targets.value = [...linked, ...webdav]
  }

  onMounted(() => {
    void reload()
  })

  return { targets, reload }
}
