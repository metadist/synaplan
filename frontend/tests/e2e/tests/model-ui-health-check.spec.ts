/**
 * AI models — chat UI health check (Playwright browser; CHAT capability only).
 *
 * Drives the real chat UI per configured model — not redundant with the API health check:
 * catches wrong params, UI state, and combos (e.g. Thinking + temperature) that pass over HTTP
 * but break for users.
 *
 * Tags: @noci @local — not run in CI (real provider keys).
 *
 * Prerequisite: same fixture sync as model-api-health-check.spec.ts (read that header).
 *
 * Run (from frontend/) — no dedicated npm script; pass the file explicitly:
 *   npx playwright test --config=tests/e2e/playwright.health-check.config.ts tests/e2e/tests/model-ui-health-check.spec.ts
 *   INCLUDE_LOCAL=1 npx playwright test --config=tests/e2e/playwright.health-check.config.ts tests/e2e/tests/model-ui-health-check.spec.ts
 *   npx playwright test -c tests/e2e/playwright.health-check.config.ts --ui
 *
 * Together with the API spec: npm run test:e2e:model-check
 */
import { test, expect } from '@playwright/test'
import { selectors } from '../helpers/selectors'
import { ChatHelper } from '../helpers/chat'
import { login } from '../helpers/auth'
import {
  loginAndGetCookie,
  fetchModelsByCapability,
  isCloudProvider,
  type ModelInfo,
} from '../helpers/api'
import { getApiUrl } from '../config/config'
import { CREDENTIALS } from '../config/credentials'

const ADMIN = CREDENTIALS.getAdminCredentials()

const INCLUDE_LOCAL = process.env.INCLUDE_LOCAL === '1'
const PROMPT = 'Hello! Reply with one short sentence.'
const THINKING_PROMPT = 'What is 2+2? Reply with just the number.'

const ANSWER_TIMEOUT = 60_000

// ---------------------------------------------------------------------------
// Result types
// ---------------------------------------------------------------------------

interface UiModelResult {
  service: string
  modelName: string
  modelId: number
  mode: 'vanilla' | 'thinking'
  status: 'PASS' | 'FAIL' | 'SKIP'
  durationMs: number
  errorMessage?: string
}

// ---------------------------------------------------------------------------
// Console output
// ---------------------------------------------------------------------------

function printConsoleSummary(results: UiModelResult[]): void {
  const tested = results.filter((r) => r.status !== 'SKIP')
  const scope = INCLUDE_LOCAL ? 'All' : 'Cloud'

  console.log(`\n========== ${scope} AI models — chat UI health check ==========`)

  for (const mode of ['vanilla', 'thinking'] as const) {
    const modeResults = tested.filter((r) => r.mode === mode)
    if (modeResults.length === 0) continue
    const pass = modeResults.filter((r) => r.status === 'PASS').length
    const fail = modeResults.filter((r) => r.status === 'FAIL').length
    const icon = fail > 0 ? 'x' : '✓'
    console.log(`  ${icon}  ${mode.padEnd(12)} ${pass} pass | ${fail} fail`)
  }

  const totalPass = tested.filter((r) => r.status === 'PASS').length
  const totalFail = tested.filter((r) => r.status === 'FAIL').length
  const totalSkip = results.filter((r) => r.status === 'SKIP').length
  console.log('--------------------------------------------')
  console.log(`     TOTAL        ${totalPass} pass | ${totalFail} fail | ${totalSkip} skipped`)

  const failures = tested.filter((r) => r.status === 'FAIL')
  if (failures.length > 0) {
    console.log('\n  Models that need attention:')
    console.log('  --------------------------')
    for (const f of failures) {
      const dur = (f.durationMs / 1000).toFixed(1)
      console.log(`  [${f.mode}]  ${f.service} / ${f.modelName}  (${dur}s)`)
      console.log(`           ${f.errorMessage}`)
    }
  }
  console.log('============================================\n')
}

// ---------------------------------------------------------------------------
// Report attachment
// ---------------------------------------------------------------------------

async function attachReport(
  testInfo: import('@playwright/test').TestInfo,
  results: UiModelResult[],
): Promise<void> {
  const tested = results.filter((r) => r.status !== 'SKIP')
  const scope = INCLUDE_LOCAL ? 'All' : 'Cloud'
  const lines: string[] = [
    `${scope} AI models — chat UI health check — ${new Date().toISOString()}`,
    `URL: ${getApiUrl()}`,
    '',
  ]

  for (const mode of ['vanilla', 'thinking'] as const) {
    const modeResults = tested.filter((r) => r.mode === mode)
    if (modeResults.length === 0) continue
    const pass = modeResults.filter((r) => r.status === 'PASS').length
    const fail = modeResults.filter((r) => r.status === 'FAIL').length
    lines.push(`${mode}: ${pass} OK | ${fail} FAIL`, '----------------------------------------')
    for (const r of modeResults) {
      const dur = (r.durationMs / 1000).toFixed(1).padStart(6)
      if (r.status === 'PASS') {
        lines.push(`  OK    ${(r.service + ' / ' + r.modelName).padEnd(40)} ${dur}s`)
      } else {
        lines.push(`  FAIL  ${(r.service + ' / ' + r.modelName).padEnd(40)} ${dur}s`)
        lines.push(`        ${r.errorMessage}`)
      }
    }
    lines.push('')
  }

  const totalPass = tested.filter((r) => r.status === 'PASS').length
  const totalFail = tested.filter((r) => r.status === 'FAIL').length
  lines.push(`TOTAL: ${totalPass} OK | ${totalFail} FAIL`)

  await testInfo.attach('ui-health-report.json', {
    body: JSON.stringify(results, null, 2),
    contentType: 'application/json',
  })
  await testInfo.attach('ui-health-report.txt', {
    body: lines.join('\n'),
    contentType: 'text/plain',
  })
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

async function selectModelFromDropdown(
  page: import('@playwright/test').Page,
  modelId: number,
): Promise<void> {
  const toggle = page.locator(selectors.chat.modelToggle)
  await toggle.waitFor({ state: 'visible', timeout: 5_000 })
  await toggle.click()

  const panel = page.locator(selectors.chat.modelPanel)
  await panel.waitFor({ state: 'visible', timeout: 5_000 })

  const option = page.locator(selectors.chat.modelOption(modelId))
  await option.scrollIntoViewIfNeeded()
  await option.click()

  await panel.waitFor({ state: 'hidden', timeout: 5_000 })
}

async function enableThinking(page: import('@playwright/test').Page): Promise<void> {
  const thinkingBtn = page.locator('[data-testid="btn-chat-thinking"]')
  await thinkingBtn.waitFor({ state: 'visible', timeout: 5_000 })

  const isActive = await thinkingBtn.evaluate(
    (el) => el.classList.contains('pill--active'),
  )
  if (!isActive) {
    await thinkingBtn.click()
  }
}

// ---------------------------------------------------------------------------
// Test
// ---------------------------------------------------------------------------

test.describe('@noci @local AI models — chat UI health check', () => {
  test('smoke-test every CHAT model through the browser UI', async ({ page, request }, testInfo) => {
    testInfo.setTimeout(900_000)

    const cookie = await loginAndGetCookie(request, ADMIN)
    const allModels = await fetchModelsByCapability(request, cookie)
    const chatModels = allModels['CHAT'] ?? []

    const shouldTest = (m: ModelInfo) => INCLUDE_LOCAL || isCloudProvider(m)
    const testableModels = chatModels.filter(shouldTest)
    const skippedModels = chatModels.filter((m) => !shouldTest(m))

    const results: UiModelResult[] = []

    for (const m of skippedModels) {
      results.push({
        service: m.service,
        modelName: m.name,
        modelId: m.id,
        mode: 'vanilla',
        status: 'SKIP',
        durationMs: 0,
      })
    }

    await login(page, ADMIN)
    const chat = new ChatHelper(page)
    await chat.ensureAdvancedMode()

    for (const model of testableModels) {
      // --- Vanilla test ---
      const vanillaStart = Date.now()
      let vanillaPassed = false
      try {
        await chat.startNewChat()
        await selectModelFromDropdown(page, model.id)
        await page.locator(selectors.chat.textInput).fill(PROMPT)

        const previousCount = await chat.conversationBubbles().count()
        await page.locator(selectors.chat.sendBtn).click()

        const aiText = await chat.waitForAnswer(previousCount, true)
        expect(aiText.length).toBeGreaterThan(0)
        vanillaPassed = true

        results.push({
          service: model.service,
          modelName: model.name,
          modelId: model.id,
          mode: 'vanilla',
          status: 'PASS',
          durationMs: Date.now() - vanillaStart,
        })
      } catch (error) {
        const msg = error instanceof Error ? error.message : String(error)
        results.push({
          service: model.service,
          modelName: model.name,
          modelId: model.id,
          mode: 'vanilla',
          status: 'FAIL',
          durationMs: Date.now() - vanillaStart,
          errorMessage: msg.slice(0, 400),
        })
      }

      // --- Thinking test (only if vanilla passed and model supports reasoning) ---
      const hasReasoning = model.features?.includes('reasoning')
      if (vanillaPassed && hasReasoning) {
        const thinkStart = Date.now()
        try {
          await chat.startNewChat()
          await selectModelFromDropdown(page, model.id)
          await enableThinking(page)
          await page.locator(selectors.chat.textInput).fill(THINKING_PROMPT)

          const previousCount = await chat.conversationBubbles().count()
          await page.locator(selectors.chat.sendBtn).click()

          const aiText = await chat.waitForAnswer(previousCount, true)
          expect(aiText.length).toBeGreaterThan(0)

          results.push({
            service: model.service,
            modelName: model.name,
            modelId: model.id,
            mode: 'thinking',
            status: 'PASS',
            durationMs: Date.now() - thinkStart,
          })
        } catch (error) {
          const msg = error instanceof Error ? error.message : String(error)
          results.push({
            service: model.service,
            modelName: model.name,
            modelId: model.id,
            mode: 'thinking',
            status: 'FAIL',
            durationMs: Date.now() - thinkStart,
            errorMessage: msg.slice(0, 400),
          })
        }
      }
    }

    printConsoleSummary(results)
    await attachReport(testInfo, results)

    const failures = results.filter((r) => r.status === 'FAIL')
    expect(
      failures,
      `${failures.length} model(s) failed — see report for details`,
    ).toHaveLength(0)
  })
})
