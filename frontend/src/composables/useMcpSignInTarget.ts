import { useI18n } from 'vue-i18n'
import { useDialog } from '@/composables/useDialog'

export type McpSignInDecision = 'go' | 'cancel' | 'refuse'

const hostOf = (url: string | null | undefined): string => {
  try {
    return new URL(url ?? '').host
  } catch {
    return ''
  }
}

/**
 * Last check before the browser leaves Synaplan for a server's sign-in page:
 * only `https`, and the person agrees when the sign-in happens on a host
 * other than the server's own.
 */
export function useMcpSignInTarget() {
  const { t } = useI18n()
  const { confirm } = useDialog()

  const decide = async (
    authorizeUrl: string,
    server: { name?: string | null; url?: string | null }
  ): Promise<McpSignInDecision> => {
    let target: URL
    try {
      target = new URL(authorizeUrl)
    } catch {
      return 'refuse'
    }
    if (target.protocol !== 'https:') {
      return 'refuse'
    }

    const serverHost = hostOf(server.url)
    if (target.host === serverHost) {
      return 'go'
    }

    const agreed = await confirm({
      title: t('mcpServers.signInElsewhereTitle', { host: target.host }),
      message: t('mcpServers.signInElsewhereMessage', {
        name: server.name ?? '',
        serverHost,
        authHost: target.host,
      }),
      confirmText: t('mcpServers.signInElsewhereConfirm'),
    })

    return agreed ? 'go' : 'cancel'
  }

  return { decide }
}
