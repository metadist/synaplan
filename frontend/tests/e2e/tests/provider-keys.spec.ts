import { test, expect } from '../test-setup'
import { login, loginViaApi } from '../helpers/auth'
import { CREDENTIALS } from '../config/credentials'
import { TIMEOUTS, getApiUrl } from '../config/config'

/**
 * NV05 / D2 — Models & keys is the one editor for instance provider keys.
 *
 * Journey J-NV-3 (admin): "Where do I put the OpenAI key?" ⇒ System config
 * › AI Services does not offer a password box for it any more; it shows
 * which keys are already set from the environment / a Helm chart and links
 * to AI infrastructure › Models & keys, where every provider in the catalog
 * (chat, media and speech) has a card. A key injected via the environment
 * is already "set" — no UI save is needed.
 *
 * Deterministic and provider-free (@ci): nothing is saved, tested or removed;
 * only read endpoints are hit. Auth: the worker storageState is a non-admin
 * user, so this spec logs in as the seeded admin like `admin-panel.spec.ts`.
 */
test.describe('@ci Provider keys — one editor', () => {
  test.beforeEach(async ({ page }) => {
    await login(page, CREDENTIALS.getAdminCredentials())
  })

  test('System config › AI Services shows the status card instead of key inputs', async ({
    page,
  }) => {
    await page.goto('/admin/config?tab=ai')
    await expect(page.locator('[data-testid="view-admin-config"]')).toBeVisible({
      timeout: TIMEOUTS.STANDARD,
    })

    const openaiChip = page.locator('[data-testid="managed-key-OPENAI_API_KEY"]')
    await expect(openaiChip).toBeVisible({ timeout: TIMEOUTS.STANDARD })
    await expect(openaiChip).toHaveAttribute('data-state', /^(env|db|none)$/)

    await test.step('no password input is offered for any managed key', async () => {
      const section = page.locator('#config-section-cloud')
      await expect(section).toBeVisible()
      // The section keeps its one unmanaged field (the Vertex access token);
      // every *_API_KEY password box is gone.
      for (const key of ['OPENAI_API_KEY', 'ANTHROPIC_API_KEY', 'GROQ_API_KEY']) {
        await expect(section.locator(`input[name="${key}"], #${key}`)).toHaveCount(0)
        await expect(section.locator(`[data-testid="managed-key-${key}"]`)).toBeVisible()
      }
      await expect(section.getByText('AI infrastructure › Models & keys')).toBeVisible()
      await expect(section.getByText(/chart install does not need this page/)).toBeVisible()
    })

    await test.step('a fully managed section renders no input at all', async () => {
      const media = page.locator('#config-section-media')
      await expect(media).toBeVisible()
      await expect(media.locator('input')).toHaveCount(0)
      await expect(media.locator('[data-testid="managed-key-HIGGSFIELD_API_SECRET"]')).toBeVisible()
    })

    await test.step('the card links to the one editor', async () => {
      const link = page.locator('#config-section-cloud [data-testid="managed-keys-link"]')
      await expect(link).toHaveAttribute('href', '/admin/setup')
      await link.click()
      await expect(page).toHaveURL(/\/admin\/setup/, { timeout: TIMEOUTS.STANDARD })
    })
  })

  test('Models & keys shows a card for every provider in the catalog', async ({
    page,
    request,
  }) => {
    const adminCookie = await loginViaApi(request, CREDENTIALS.getAdminCredentials())
    const res = await request.get(`${getApiUrl()}/api/v1/admin/provider-keys`, {
      headers: { Cookie: adminCookie },
    })
    expect(res.ok()).toBeTruthy()
    const body = (await res.json()) as {
      providers: {
        name: string
        configured: boolean
        source: 'db' | 'env' | 'none'
        secretEnvVar: string | null
        testable: boolean
      }[]
    }
    // Media and speech providers are part of the same catalog (D2).
    const names = body.providers.map((p) => p.name)
    for (const expected of ['openai', 'groq', 'thehive', 'higgsfield', 'elevenlabs']) {
      expect(names, `${expected} must be in the provider catalog`).toContain(expected)
    }

    await page.goto('/admin/setup')
    await expect(page.locator('[data-testid="admin-setup-tab-models"]')).toBeVisible({
      timeout: TIMEOUTS.STANDARD,
    })

    for (const provider of body.providers) {
      const card = page.locator(`[data-testid="provider-card-${provider.name}"]`)
      await expect(card, `card for ${provider.name}`).toBeVisible({ timeout: TIMEOUTS.STANDARD })

      // Key + secret providers get the second password input, others never do.
      await expect(
        card.locator(`[data-testid="provider-secret-input-${provider.name}"]`)
      ).toHaveCount(provider.secretEnvVar ? 1 : 0)

      // A key injected via the environment is already set — the card says so
      // and does not ask for a UI save.
      if (provider.configured && provider.source === 'env') {
        await expect(
          card.locator(`[data-testid="provider-key-source-env-${provider.name}"]`)
        ).toBeVisible()
      }

      // Providers without a free check never show a "Test" action.
      if (!provider.testable) {
        await expect(
          card.locator(`[data-testid="provider-key-test-${provider.name}"]`)
        ).toHaveCount(0)
      }
    }
  })
})
