# Playwright E2E Test Guidelines

Goal: simple, readable, stable tests that fail fast.
No over-engineering. No unnecessary abstractions.

---

## 1. Style

* Simple code > clever code.
* Only as much logic as needed.
* Readability over brevity.
* No unnecessary TypeScript tricks.
* Act like a user: navigate via UI (nav/buttons/links).
* Do not target hidden inputs unless the user can reach them the same way.
  Exception: `setInputFiles()` is allowed for uploads.
* Setup/teardown and external systems (e.g. MailHog) may be used only if not reachable via UI.
* When in doubt: choose simpler logic over abstraction.
* If a helper becomes complex, inline logic into the test instead.

---

## 2. Locator Rules

* Always locate elements fresh — never store a locator in a variable and reuse it across navigations or state changes:

  ```ts
  await page.locator(...).click();
  ```
* Prefer: `data-testid` → `getByRole`/`getByLabel` → CSS.
* Never use CSS class selectors (`[class*="..."]`, `.my-class`) — classes are implementation details and break on refactor.
* Use container-scoped locators.
* Use the same scope for counting and selecting.
* Avoid i18n/label/`hasText` for critical controls.
* Avoid `.first()` / `.last()` unless ordering is guaranteed.
* Capture state first (e.g. `previousCount`) and assert relative change.
* Do not rely on implicit UI ordering, empty states, or default counts.

---

## 3. Waiting & Sync (Fail Fast)

### Timeout Tiers

Use the constants from `frontend/tests/e2e/config/config.ts`:

| Tier | Value | Use for |
|------|-------|---------|
| `SHORT` | 5 s | UI reaction (click, dropdown, toggle) |
| `STANDARD` | 10 s | Page navigation, API response |
| `LONG` | 15 s | First AI token, file upload |
| `VERY_LONG` | 30 s | Full AI stream, heavy processing |
| `EXTREME` | 60 s | Only with explicit justification |

* Fast-fail by default (`SHORT` / `STANDARD`).
* Maximum one long wait per test, only for real async work.
* **No `waitForTimeout`.** Ever. Wait for a DOM condition instead.
* Do not stack sequential waits that together behave like a long timeout.
* Prefer a single, well-scoped wait for the true terminal condition.
* **No `networkidle`** on SSE/widget pages (keeps connections open → never resolves or silently times out).
* Do not use text-stabilization polling.

### Deterministic Terminal State (Non-negotiable)

Every async operation must expose exactly one terminal state:

* **success** (e.g. `[data-testid="message-done"]`)
* **error** (e.g. `[data-testid="message-topic-error"]`)

Tests must wait for exactly one of them. No implicit completion detection.

### Fast-Fail on Error

If success is expected:

* If deterministic ERROR state appears → fail immediately.

If error is expected:

* If deterministic ERROR state appears → succeed immediately.

Pattern (see `ChatHelper.waitForAnswer` for real implementation):

```ts
const result = await Promise.race([
  bubble.locator(selectors.chat.messageDone)
    .waitFor({ state: 'visible', timeout: TIMEOUTS.VERY_LONG })
    .then(() => 'done' as const),
  bubble.locator(selectors.chat.messageTopicError)
    .waitFor({ state: 'visible', timeout: TIMEOUTS.VERY_LONG })
    .then(() => 'error' as const),
])
if (result === 'error') {
  throw new Error('Assistant message ended in error state')
}
```

Only with deterministic UI hooks (no text scanning).

---

## 4. Assertions

* Normalize text unless exact copy required.
* Exact text only for legal/pricing/onboarding.
* Regex only when necessary.
* Use `expect.soft(...)` in loops when you want to collect all failures rather than stop at the first.

### AI-Specific Rules

* Never assert on specific AI words like `"success"`, `"Mamma Mia"`, or any model-generated content from **real, non-deterministic** AI providers.
* **Exception — Stub responses:** When a test uses a deterministic stub server (e.g. `ollama-stub`), the response text is controlled by the test infrastructure and is identical in every environment. Asserting on stub response text (e.g. `expect(text).toContain('fake ollama stub response')`) **is allowed and encouraged** because it proves the full request/response pipeline works end-to-end.
* Valid pattern for real AI:

  1. Assistant message exists.
  2. Terminal `done` state reached.
  3. No deterministic error.
  4. Final text is non-empty.
* Error detection must use deterministic hooks (`data-testid`), not text scanning.
* No copy/model-dependent assertions for real AI providers.

---

## 5. Loops & Dropdowns

* Use standard `for` loops.
* Each iteration:

  1. Locate toggle fresh.
  2. Open dropdown.
  3. Use `nth(i)`.
  4. Read label.
  5. Conditionally click.
* No implicit ordering assumptions.

---

## 6. Helpers & Error Handling

* Let failures propagate — Playwright's error messages are good.
* No global defensive wrappers.
* **No silent `.catch(() => {})`** on critical paths — this hides real failures and makes debugging impossible.
* Helpers may contain `expect` only if they represent a deterministic contract (e.g. `ChatHelper.waitForAnswer` asserts no-error).
* Only one intended long wait inside a helper (e.g. stream completion).
* No hidden waits.

---

## 7. Test Data & Cleanup

* Unique data per test (use timestamps, UUIDs, or `test.info().title`).
* Cleanup via `test.afterEach` or `try/finally` inside the test.
* Backend API only for setup/teardown — never for the action under test.
* Validate final result via UI.
* Do not assume empty history or `count = 0`.
* Define readiness by stable UI state.

---

## 8. Test Scope & Isolation

* E2E only for critical user flows.
* Prefer component/unit tests for UI details.
* Fewer stable smoke tests > many fragile ones.
* **Tests must be independent** — never rely on execution order or state from a previous test.
* Use `test.describe.configure({ mode: 'serial' })` only when tests share expensive setup (e.g. login + data creation) and document why.

---

## 9. Test Structure

Use `test.step` to separate phases — this improves trace readability and error messages:

```ts
test('user sends message and gets AI response', async ({ page }) => {
  await test.step('Arrange: login and open chat', async () => {
    await login(page)
    const chat = new ChatHelper(page)
    await chat.startNewChat()
  })

  await test.step('Act: send message', async () => {
    const input = page.locator(selectors.chat.textInput)
    await input.fill('Hello')
    await page.locator(selectors.chat.sendBtn).click()
  })

  await test.step('Assert: AI responds without error', async () => {
    const chat = new ChatHelper(page)
    const answer = await chat.waitForAnswer(0)
    expect(answer.length).toBeGreaterThan(0)
  })
})
```

Flow: **Arrange → Act → Assert**.

---

## 10. Playwright Config Awareness

The project already configures these in `playwright.config.ts` — do not duplicate or contradict:

* `trace: 'retain-on-failure'` — traces are auto-captured; no need for manual screenshots unless debugging.
* `video: 'retain-on-failure'` — videos captured automatically.
* `screenshot: 'only-on-failure'` — do not add manual `page.screenshot()` calls in tests unless for a specific debugging purpose (remove before merging).
* `retries: 1` in CI, `0` locally — retries are a safety net, not a fix for flaky tests.
* `timeout: 60_000` global — individual tests should not need `test.setTimeout()` unless they genuinely need more time (document why).

---

## 11. Authentication

* Use the existing `login()` helper from `helpers/auth.ts` for UI login.
* Use `loginViaApi()` / `getAuthHeaders()` for API-only tests or setup.
* For test suites that need auth in every test, consider `storageState` in a setup project to avoid repeated login overhead (Playwright docs: "Authentication").
* Never hardcode user IDs — look them up via API or use the credentials config.

---

## 12. Change Discipline

* Do not change application code except when essential for the test. Use existing logic and entry points. Changes only as test-only config/fixtures or a minimal, safe `data-testid` hook.
* One PR = one change type.
* Touch only listed files.
* If helper signature changes → update all call sites.
* After helper change → run full affected suite.
* Do not modify unrelated files.
* Before committing:

  ```
  make format && make lint && make test
  ```

---

## Core Philosophy

Tests must:

* Fail fast
* Be deterministic
* Use explicit terminal states
* Be independent (no shared mutable state between tests)
* Avoid implicit UI assumptions
* Avoid copy/model dependence
* Prefer clarity over abstraction
