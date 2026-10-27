import { nextTick, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { useNotification } from '@/composables/useNotification'
import { useMcpSignInTarget } from '@/composables/useMcpSignInTarget'
import { mcpSignInErrorKey } from '@/components/config/mcpSignInError'
import { mcpServersApi, type McpServer } from '@/services/api/mcpServersApi'

/**
 * Connect / Reconnect / Save and sign in on Apps → MCP Servers: ask the
 * server for its sign-in page, check where it leads, then leave Synaplan;
 * back from it, name the outcome. Every failure is one sentence; after a
 * save it also says the row exists.
 */
/** `reload`: starting a sign-in may already have dropped an outdated one, so a stay re-reads the list. */
export function useMcpSignInStart(reload: () => Promise<void>) {
  const { t } = useI18n()
  const { success, error } = useNotification()
  const route = useRoute()
  const router = useRouter()
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

  /** Back from the server's page: name the outcome, then show the row it belongs to. */
  const finish = async (servers: McpServer[]): Promise<void> => {
    const connected = route.query.connected
    const reason = route.query.oauth_error
    if (typeof connected === 'string' && connected !== '') {
      const server = servers.find((s) => String(s.id) === connected)
      success(t('mcpServers.oauthConnectedToast', { name: server?.name ?? '' }))
      await router.replace({ query: { ...route.query, connected: undefined } })
      await nextTick()
      document
        .querySelector(`[data-testid="mcp-server-${connected}"]`)
        ?.scrollIntoView({ behavior: 'smooth', block: 'center' })
    } else if (typeof reason === 'string' && reason !== '') {
      error(t(mcpSignInErrorKey(reason)))
      await router.replace({ query: { ...route.query, oauth_error: undefined } })
    }
  }

  return { connectingId, start, finish }
}
