/**
 * Transitional-redirect watchdog — §4.6 of the navigation IA cleanup
 * (_devextras/planning/20260611-navigation-ia-cleanup.md).
 *
 * Every legacy path must land on its canonical successor (bookmarks, docs,
 * support articles). The redirects stay for at least 2 releases; when they
 * are removed (phase 7) this spec is the tripwire that forces the removal
 * to be a conscious, documented decision.
 */
import { test, expect } from '../test-setup'
import { openApp } from '../helpers/auth'
import { isAgentsEnabled } from '../helpers/features'
import { TIMEOUTS } from '../config/config'

/**
 * /ai/instructions itself is a transitional surface: with AGENTS.ENABLED on
 * (the seeded default) `instructionsRouteGuard` forwards it to the Assistants
 * gallery, so the legacy Instructions bookmark lands one hop further.
 */
const instructionsSuccessor = (agentsEnabled: boolean): string =>
  agentsEnabled ? '/ai/assistants' : '/ai/instructions'

/** old path → canonical path (§4.6 URL map) */
const redirects = (agentsEnabled: boolean): Array<[string, string]> => [
  ['/rag', '/files/search'],
  ['/config', '/channels'],
  ['/config/inbound', '/channels'],
  ['/config/ai-models', '/ai/models'],
  ['/config/task-prompts', instructionsSuccessor(agentsEnabled)],
  ['/config/sorting-prompt', '/ai/routing'],
  ['/config/api-keys', '/channels/api'],
  ['/config/api-documentation', '/channels/api/docs'],
  ['/tools', '/channels'],
  ['/tools/chat-widget', '/channels/widgets'],
  ['/tools/chat-widget/live-support', '/channels/widgets/live-support'],
  ['/tools/chat-widget/42', '/channels/widgets/42'],
  ['/tools/chat-widget/42/chats', '/channels/widgets/42/chats'],
  ['/tools/mail-handler', '/channels/email'],
  ['/tools/doc-summary', '/ai/summarizer'],
]

test.describe('Redirects: legacy URLs land on canonical paths (§4.6)', () => {
  test('@ci every legacy path redirects to its successor', async ({
    page,
    request,
    credentials,
  }) => {
    const agentsEnabled = await isAgentsEnabled(request, credentials)
    await openApp(page)

    for (const [oldPath, newPath] of redirects(agentsEnabled)) {
      await test.step(`${oldPath} → ${newPath}`, async () => {
        // Resolve on document commit, not `load`: the app boots and immediately
        // redirects to the canonical path, which aborts a `load`-gated goto
        // (NS_BINDING_ABORTED on firefox). toHaveURL below is the real assertion.
        await page.goto(oldPath, { waitUntil: 'commit' })
        const expected = new RegExp(`${newPath.replace(/[/]/g, '\\/')}$`)
        await expect(page, `${oldPath} should land on ${newPath}`).toHaveURL(expected, {
          timeout: TIMEOUTS.STANDARD,
        })
      })
    }
  })

  test('@ci redirect preserves the query string', async ({ page }) => {
    await openApp(page)
    // /config/sorting-prompt → /ai/routing has no further flag-dependent hop,
    // so it shows the query string surviving the legacy redirect itself.
    await page.goto('/config/sorting-prompt?topic=mail', { waitUntil: 'commit' })
    await expect(page).toHaveURL(/\/ai\/routing\?topic=mail$/, {
      timeout: TIMEOUTS.STANDARD,
    })
  })
})
