import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useNotification } from '@/composables/useNotification'
import { useMcpSignInTarget } from '@/composables/useMcpSignInTarget'
import { mcpSignInErrorKey } from '@/components/config/mcpSignInError'
import { mcpServersApi, type McpServer } from '@/services/api/mcpServersApi'

/**
 * Connect / Reconnect / Save and sign in on Apps → MCP Servers: ask the
 * server for its sign-in page, check where it leads, then leave Synaplan.
 * Every failure is one sentence; after a save it also says the row exists.
 */
/** `reload`: starting a sign-in may already have dropped an outdated one, so a stay re-reads the list. */
export function useMcpSignInStart(reload: () => Promise<void>) {
  const { t } = useI18n()
  const { error } = useNotification()
  const { decide } = useMcpSignInTarget()
  const connectingId = ref<number | null>(null)

  /** `justSaved`: the message also says the server was saved (an older sign-in may still work). */
  const start = async (server: McpServer, justSaved = false): Promise<void> => {
    if (server.id === undefined) return
    connectingId.value = server.id
    const fail = (reason: unknown) => {
      const sentence = t(mcpSignInErrorKey(reason))
      error(
        justSaved
          ? t('mcpServers.savedSignInNotStarted', { name: server.name ?? '', reason: sentence })
          : sentence
      )
      connectingId.value = null
    }
    try {
      const url = await mcpServersApi.startOAuth(server.id)
      const decision = await decide(url, server)
      if (decision === 'go') {
        window.location.assign(url)
      } else {
        if (decision === 'refuse') {
          fail('unknown')
        }
        connectingId.value = null
        await reload()
      }
    } catch (err) {
      fail(err)
    }
  }

  return { connectingId, start }
}
