/**
 * Operate is grouped by topic.
 *
 * Journey J-OP-1 (admin): "Where do I set up the AI, and where does the rest
 * of the server live?" — Operate lists five entries (Overview, System status,
 * AI infrastructure, People, System configuration). AI infrastructure holds
 * everything the AI needs: providers and keys, model health, document reading
 * with its reading services, knowledge search with embeddings, vector database
 * and reranking, chat behaviour and system prompts. System configuration keeps
 * the platform settings by topic, including web search. Moderation is a tab of
 * People.
 *
 * Deterministic and provider-free (@ci): read-only, nothing is saved or tested
 * against a provider. Auth: logs in as the seeded admin.
 */
import { test, expect, type Page } from '../test-setup'
import { login } from '../helpers/auth'
import { selectors } from '../helpers/selectors'
import { CREDENTIALS } from '../config/credentials'
import { TIMEOUTS } from '../config/config'

const NAV = selectors.nav

async function openOperateFlyout(page: Page) {
  await page.locator(NAV.sidebarV2Admin).click()
  const flyout = page.locator(NAV.navDropdown)
  await expect(flyout).toBeVisible({ timeout: TIMEOUTS.SHORT })
  return flyout
}

test.describe('@ci Operate topics', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, CREDENTIALS.getAdminCredentials())
  })

  test('J-OP-1 everything AI in one place, the rest by topic', async ({ page }) => {
    await test.step('Operate lists one entry per topic', async () => {
      const flyout = await openOperateFlyout(page)
      const links = flyout.locator('[data-testid^="link-sidebar-v2-admin-"]')
      await expect(links).toHaveCount(5)
      expect(
        await links.evaluateAll((els) => els.map((el) => el.getAttribute('data-testid')))
      ).toEqual([
        'link-sidebar-v2-admin-dashboard',
        'link-sidebar-v2-admin-features',
        'link-sidebar-v2-admin-setup',
        'link-sidebar-v2-admin-people',
        'link-sidebar-v2-admin-config',
      ])
      await flyout.locator(NAV.flyoutLinkAdminSetup).click()
      await expect(page).toHaveURL(/\/admin\/setup/, { timeout: TIMEOUTS.STANDARD })
    })

    await test.step('AI infrastructure has one tab per AI topic', async () => {
      for (const tab of ['providers', 'health', 'documents', 'search', 'behavior', 'prompts']) {
        await expect(page.locator(`[data-testid="admin-setup-tab-${tab}"]`)).toBeVisible({
          timeout: TIMEOUTS.STANDARD,
        })
      }
    })

    await test.step('Document reading pairs the chains with the reading services', async () => {
      await page.locator('[data-testid="admin-setup-tab-documents"]').click()
      await expect(page).toHaveURL(/tab=documents/, { timeout: TIMEOUTS.STANDARD })
      await expect(page.locator('[data-testid="extraction-plug-tab"]')).toBeVisible({
        timeout: TIMEOUTS.STANDARD,
      })
      await page.locator('[data-testid="extraction-sidecar-settings"]').click()
      const tika = page.locator('#config-section-tika')
      await expect(tika).toHaveAttribute('data-open', 'true', { timeout: TIMEOUTS.STANDARD })
      await expect(tika.locator('#TIKA_BASE_URL')).toBeVisible()
      await expect(tika.locator('[data-testid="btn-config-test-tika"]')).toBeVisible()
    })

    await test.step('Knowledge search keeps embeddings, vector database and reranking together', async () => {
      await page.locator('[data-testid="admin-setup-tab-search"]').click()
      await expect(page.locator('#config-section-embeddings')).toBeVisible({
        timeout: TIMEOUTS.STANDARD,
      })
      await expect(page.locator('#config-section-qdrant')).toBeVisible()
      await expect(page.locator('#config-section-qdrant_search')).toBeVisible()
      await expect(page.locator('[data-testid="rerank-plug-tab"]')).toBeVisible()
    })

    await test.step('Model health is a tab of AI infrastructure', async () => {
      await page.locator('[data-testid="admin-setup-tab-health"]').click()
      await expect(page.locator('[data-testid="panel-model-health"]')).toBeVisible({
        timeout: TIMEOUTS.STANDARD,
      })
    })

    await test.step('System configuration groups the platform settings by topic', async () => {
      const flyout = await openOperateFlyout(page)
      await flyout.locator(NAV.flyoutLinkAdminConfig).click()
      await expect(page.locator('[data-testid="config-topic-nav"]')).toBeVisible({
        timeout: TIMEOUTS.STANDARD,
      })
      for (const group of ['access', 'features', 'channels', 'appearance']) {
        await expect(page.locator(`[data-testid="config-group-${group}"]`)).toBeVisible()
      }
      for (const retired of ['ai', 'processing', 'routing', 'vectordb']) {
        await expect(page.locator(`[data-testid="btn-config-tab-${retired}"]`)).toHaveCount(0)
      }
    })

    await test.step('Web search lives in System configuration next to the Brave settings', async () => {
      await page.locator('[data-testid="btn-config-tab-web_search"]').click()
      await expect(page).toHaveURL(/tab=web_search/, { timeout: TIMEOUTS.STANDARD })
      await expect(page.locator('[data-testid="web-search-plug-tab"]')).toBeVisible({
        timeout: TIMEOUTS.STANDARD,
      })
      await expect(page.locator('#config-section-brave')).toBeVisible()
    })

    await test.step('Tools & automation holds file work and the tool approval rules', async () => {
      await page.locator('[data-testid="btn-config-tab-tools"]').click()
      await expect(page.locator('#config-section-compute')).toBeVisible({
        timeout: TIMEOUTS.STANDARD,
      })
      await expect(page.locator('#config-section-tools')).toBeVisible()
    })

    await test.step('Moderation is a tab of People', async () => {
      const flyout = await openOperateFlyout(page)
      await flyout.locator(NAV.flyoutLinkAdminPeople).click()
      await page.locator('[data-testid="tab-moderation"]').click()
      await expect(page.locator('[data-testid="admin-moderation-panel"]')).toBeVisible({
        timeout: TIMEOUTS.STANDARD,
      })
    })
  })
})
