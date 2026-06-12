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
import { login } from '../helpers/auth'
import { TIMEOUTS } from '../config/config'

/** old path → canonical path (§4.6 URL map) */
const REDIRECTS: Array<[string, string]> = [
  ['/rag', '/files/search'],
  ['/config', '/channels'],
  ['/config/inbound', '/channels'],
  ['/config/ai-models', '/ai/models'],
  ['/config/task-prompts', '/ai/instructions'],
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
  test('@ci every legacy path redirects to its successor', async ({ page, credentials }) => {
    await login(page, credentials)

    for (const [oldPath, newPath] of REDIRECTS) {
      await test.step(`${oldPath} → ${newPath}`, async () => {
        await page.goto(oldPath)
        const expected = new RegExp(`${newPath.replace(/[/]/g, '\\/')}$`)
        await expect(page, `${oldPath} should land on ${newPath}`).toHaveURL(expected, {
          timeout: TIMEOUTS.STANDARD,
        })
      })
    }
  })

  test('@ci redirect preserves the query string', async ({ page, credentials }) => {
    await login(page, credentials)
    await page.goto('/config/task-prompts?topic=mail')
    await expect(page).toHaveURL(/\/ai\/instructions\?topic=mail$/, {
      timeout: TIMEOUTS.STANDARD,
    })
  })
})
