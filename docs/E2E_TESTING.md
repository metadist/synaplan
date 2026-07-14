# Playwright E2E Test Guidelines

Goal: simple, readable, stable tests that fail fast.
No over-engineering. No unnecessary abstractions.

---

## File Structure

```
frontend/tests/e2e/
├── config/
│   ├── config.ts          # TIMEOUTS, INTERVALS, URLS, getApiUrl()
│   ├── credentials.ts     # CREDENTIALS helper (env-backed)
│   ├── test-data.ts       # WIDGET_DEFAULTS, WIDGET_TEST_URLS
│   └── integration-data.ts
├── helpers/
│   ├── selectors.ts       # ALL data-testid selectors (central registry)
│   ├── auth.ts            # login(), loginViaApi(), getAuthHeaders(), deleteUser()
│   ├── chat.ts            # ChatHelper class (sendMessage, waitForAnswer, startNewChat, …)
│   ├── widget.ts          # createTestWidget(), openWidgetOnTestPage(), waitForWidgetAnswer()
│   ├── email.ts           # MailHog helpers
│   ├── billing.ts         # Subscription/billing helpers
│   ├── webhook.ts         # Webhook test helpers
│   ├── ollama-stub.ts     # Ollama stub server control
│   └── whatsapp-stub.ts   # WhatsApp stub server control
├── stub-servers/          # Deterministic stub servers (ollama, whatsapp)
├── fixtures/              # Static test fixtures (widget-test.html, …)
├── tests/                 # All *.spec.ts files
├── playwright.config.ts   # Global Playwright config
├── global-setup.ts        # Runs once before all tests (admin login, TestProvider defaults)
└── test-setup.ts          # Worker fixtures: per-worker user + pre-authenticated storageState
```

---

## Running Tests

```bash
make -C frontend test-e2e                          # full suite
npx playwright test --grep "widget"                # single test by name
npx playwright test tests/chat.spec.ts             # single file
npx playwright test --ui                           # interactive UI mode
npx playwright show-report                         # view last report
```

Against the local test stack (`docker compose -f docker-compose.test.yml up -d`):

```bash
BASE_URL=http://localhost:8001 npm run test:e2e -- --grep "@ci"
```

If the dev stack's Ollama already occupies host port 11434, remap the stub
(`OLLAMA_STUB_URL=http://localhost:11435` plus a compose port override) — the
backend reaches it container-to-container either way.

---

## 0. Tags & CI Matrix

**`@ci` is the only authoritative tag.** The CI workflow runs `--grep "@ci"`
(chromium 2 shards, firefox 2 shards, chromium-mobile) — a test without
`@ci` in its title chain does not run in CI, period. Other tags:

| Tag | Meaning |
|-----|---------|
| `@noci` | Excluded via project `grepInvert` even when the surrounding describe is `@ci`. Local/nightly only. |
| `@layout` | Layout guard — runs in chromium desktop + chromium-mobile, excluded from firefox. |
| `@visual` | Snapshot tests — separate CI-only project (baselines from the ubuntu runner). |
| `@oidc`, `@oidc-redirect` | OIDC jobs only (dedicated matrix entries with Keycloak). |
| `@smoke`, `@auth`, `@api`, … | Informational grouping — no CI effect, historically inconsistent. Don't rely on them for filtering. |

When adding a test, decide explicitly: `@ci` (stable, deterministic, runs on
every PR) or `@noci` (needs real providers/keys or is nightly-grade).

---

## 1. Style

* Prioritize readability over brevity — when in doubt, write the more explicit version.
* Only add logic that the test actually needs.
* No unnecessary TypeScript tricks (mapped types, conditional types, template literal types in test code).
* Act like a user: navigate via UI (nav/buttons/links), not programmatic routing.
* DO NOT target hidden inputs unless the user can interact with them the same way.
  Exception: `setInputFiles()` is allowed for file uploads.
* Setup/teardown and external systems (e.g. MailHog) may bypass UI only when there is no UI path.
* When in doubt, choose simpler inline logic over a helper/abstraction.
* If a helper becomes complex, inline the logic into the test instead.

---

## 2. Locator Rules

### DO

* Locate elements fresh every time — never store a locator in a variable and reuse across navigations or state changes:

  ```ts
  await page.locator(selectors.chat.sendBtn).click()
  ```
* Prefer `data-testid` → `getByRole`/`getByLabel` → CSS selector (in that order).
* Define ALL `data-testid` patterns in `helpers/selectors.ts` — including prefix-match patterns (`^=`).
* Use container-scoped locators (scope counts and selections to the same container).
* Capture state first (e.g. `previousCount`) and assert relative change.
* `filter({ hasText })` is OK to narrow a list by unique test data (e.g. finding a card by its generated name).

### DON'T

* No inline `[data-testid="..."]` strings in test files — always use `selectors.*`.
* No CSS class selectors (`[class*="..."]`, `.my-class`) — classes are implementation details.
* No `i18n`/label/`hasText` for critical controls (buttons, inputs that have a testid).
* No `.first()` / `.last()` unless ordering is guaranteed. When only one element exists, omit `.first()`.
* No assumptions about implicit UI ordering, empty states, or default counts.

---

## 3. Waiting & Sync

### Timeout Tiers

Import from `config/config.ts`:

| Constant | Value | Use for |
|----------|-------|---------|
| `TIMEOUTS.SHORT` | 5 s | UI reaction (click, dropdown, toggle) |
| `TIMEOUTS.STANDARD` | 10 s | Page navigation, API response |
| `TIMEOUTS.LONG` | 15 s | First AI token, file upload |
| `TIMEOUTS.VERY_LONG` | 30 s | Full AI stream, heavy processing |
| `TIMEOUTS.EXTREME` | 60 s | Only with explicit justification |

### DO

* Default to `SHORT` / `STANDARD` (fail fast).
* Maximum one long wait per test, only for real async work.
* After every state-changing action (click, submit, navigation), wait for the resulting state to be visible before the next action:

  ```ts
  await page.locator(selectors.widgets.simpleForm.createButton).click()
  await page.waitForSelector(selectors.widgets.successModal.modal, {
    timeout: TIMEOUTS.STANDARD,
  })
  ```
* Wait for a single, well-scoped terminal condition (not multiple sequential waits that add up).

### DON'T

* **No `waitForTimeout`.** Ever — as a synchronization mechanism. Wait for a DOM condition instead. (Sole exception: fixed-cadence *measurement sampling* loops that never gate correctness on the delay, e.g. `stableBoundingBox` in `layout.spec.ts` and the bubble-height sampler in `chat-bubble-monotonic.spec.ts`.)
* **No `networkidle`** on SSE/widget pages (SSE keeps connections open → never resolves or silently times out).
* No text-stabilization polling in tests. (Helpers like `ChatHelper.waitForAnswer` may poll internally — that is the single permitted place.)
* No chaining multiple clicks without verifying intermediate states (e.g. wizard step transitions).
* No stacking sequential waits that together behave like a long timeout.

### Deterministic Terminal State (non-negotiable)

Every async operation must expose exactly one terminal state:

* **success** → `[data-testid="message-done"]`
* **error** → `[data-testid="message-topic-error"]`

Tests must race for exactly one of them. No implicit completion detection.

Pattern (see `ChatHelper.waitForAnswer` in `helpers/chat.ts` for the real implementation):

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

### Fast-Fail on Error

* If success is expected: deterministic ERROR state appears → fail immediately.
* If error is expected: deterministic ERROR state appears → pass immediately.

Only use deterministic UI hooks (`data-testid`), never text scanning.

---

## 4. Assertions

### DO

* Normalize text before comparing (`.trim()`, `.toLowerCase()`) unless exact copy is required.
* Exact text assertions only for legal/pricing/onboarding copy.
* Use `expect.soft(...)` in loops to collect all failures rather than stop at the first.

### DON'T

* No regex unless strictly necessary.

### AI-Specific Assertions

* **Real (non-deterministic) AI providers** — never assert on specific AI-generated words. Valid assertions:
  1. Assistant message exists.
  2. Terminal `done` state reached.
  3. No deterministic error.
  4. Final text is non-empty.

* **Stub responses** (e.g. `ollama-stub`) — the response text is controlled by test infrastructure and identical in every run. Asserting on stub text (e.g. `expect(text).toContain('ollama stub response')`) **is allowed and encouraged**.

* Error detection must use deterministic hooks (`data-testid`), not text content scanning.

---

## 5. Loops & Dropdowns

Use standard `for` loops. Each iteration must:

1. Locate the toggle fresh.
2. Open the dropdown.
3. Select by `nth(i)`.
4. Read the label.
5. Conditionally act.

No implicit ordering assumptions.

```ts
const { options, optionCount } = await chat.openLatestAgainDropdown()

for (let i = 0; i < optionCount; i++) {
  // Re-open dropdown fresh each iteration (DOM may have changed)
  const { options: freshOptions } = await chat.openLatestAgainDropdown()
  const option = freshOptions.nth(i)
  await option.waitFor({ state: 'visible', timeout: TIMEOUTS.STANDARD })

  const label = (await option.innerText()).trim()
  if (label.includes('ollama')) continue  // skip known-unavailable

  const countBefore = await chat.conversationBubbles().count()
  await option.click()
  const answer = await chat.waitForAnswer(countBefore)
  expect.soft(answer.length, `Model ${label} should respond`).toBeGreaterThan(0)
}
```

---

## 6. Helpers & Error Handling

### DO

* Let failures propagate — do not wrap Playwright calls in try/catch unless the catch takes a meaningful recovery action.
* Helpers may contain `expect` only for deterministic contracts (e.g. `ChatHelper.waitForAnswer` asserts no-error).
* Keep at most one long wait per helper (e.g. stream completion).

### DON'T

* No global defensive wrappers.
* **No silent `.catch(() => {})`** on critical paths — this hides real failures and makes debugging impossible.
* No hidden waits inside helpers that callers don't know about.

---

## 7. Test Data & Cleanup

### DO

* Generate unique data per test (timestamps, UUIDs, or `test.info().title`).
* **Resources owned by the worker user clean themselves up**: the worker
  fixture deletes its user on teardown, and `UserDeletionService` cascades
  chats, messages, files, widgets, prompts, memories, handlers, etc. Explicit
  cleanup is still required for anything created under OTHER accounts (admin,
  provisioned one-off users) or outside user scope.
* **Webhook senders must be unique per test** (`uniqueWaSender()`,
  `uniqueEmailSender()`): the backend maps each sender to an auto-created
  ANONYMOUS user whose MESSAGES limit is a lifetime total — a fixed sender
  works on a fresh CI database but starts failing with 429 on any long-lived
  local test DB.
* **Cleanup is mandatory** for tests that create persistent resources (handlers, widgets, prompts, etc.). Use `test.afterEach` with API calls to delete any resources matching the test's name prefix — this ensures cleanup even when the test fails mid-way:

  ```ts
  test.afterEach(async ({ request }) => {
    const headers = await getAuthHeaders(request)
    await request.delete(`${getApiUrl()}/api/v1/widgets/${widgetId}`, {
      headers,
    })
  })
  ```
* Validate the final result via UI.
* Define readiness by a stable UI state (not timing).

### DON'T

* No backend API calls for the action under test — API is only for setup/teardown.
* No assumptions about empty history or `count = 0`.

---

## 8. Test Scope & Isolation

* E2E tests only for critical user flows. Prefer component/unit tests for UI details.
* Fewer stable smoke tests > many fragile ones.
* **Tests must be independent** — never rely on execution order or state from a previous test.
* Use `test.describe.configure({ mode: 'serial' })` only when tests share expensive setup (e.g. login + data creation) and document why.

---

## 9. Test Structure

Use `test.step` to separate phases (Arrange → Act → Assert):

```ts
test('user sends message and gets AI response', async ({ page }) => {
  const chat = new ChatHelper(page)

  await test.step('Arrange: login and open chat', async () => {
    await login(page)
    await chat.startNewChat()
  })

  let previousCount: number
  await test.step('Act: send message', async () => {
    previousCount = await chat.sendMessage('Hello')
  })

  await test.step('Assert: AI responds without error', async () => {
    const answer = await chat.waitForAnswer(previousCount!)
    expect(answer.length).toBeGreaterThan(0)
  })
})
```

---

## 10. Playwright Config Awareness

These are already configured in `playwright.config.ts` — do not duplicate or override:

| Setting | Value | Meaning |
|---------|-------|---------|
| `trace` | `'retain-on-failure'` | Traces auto-captured; no manual screenshots needed. |
| `video` | `'retain-on-failure'` | Videos auto-captured. |
| `screenshot` | `'only-on-failure'` | Do not add `page.screenshot()` unless debugging (remove before merge). |
| `retries` | `1` (CI) / `0` (local) | Safety net, not a fix for flaky tests. |
| `timeout` | `60_000` | Do not use `test.setTimeout()` unless genuinely needed (document why). |

---

## 11. Authentication

Tests import `test` from `../test-setup`, which provides worker-scoped auth:

* **Each worker gets its own user** — created via the admin provisioning API
  (instantly verified, no MailHog) and deleted on worker teardown. Available
  as the `credentials` fixture.
* **Every test context starts pre-authenticated** — one API login per worker
  is injected via `storageState` (cookies + the `sh` session hint). Do NOT run
  a UI login as Arrange; call `openApp(page)` instead, which loads `/` and
  waits for the chat surface plus the initial chat-list response.
* **Specs that must start logged out** (login, registration, guest flows) opt
  out with `test.use(LOGGED_OUT)` at file level. This also applies to
  API specs asserting 401 for unauthenticated requests — the `request` fixture
  inherits the worker cookies otherwise.
* `login(page, creds)` (UI login) is reserved for tests whose SUBJECT is the
  login flow, and for admin-account steps (`CREDENTIALS.getAdminCredentials()`).
* Use `loginViaApi()` / `getAuthHeaders()` for API-only setup/teardown; use
  `provisionUser()` when a test needs a disposable extra user (delete it in
  a `finally`/`afterEach`).
* Never hardcode user IDs — look them up via API or use `config/credentials.ts`.
* **OIDC specs are exempt from all of this**: they import the base
  `@playwright/test`, run in dedicated CI jobs against Keycloak, and their
  login logic must not be changed as part of general test refactoring.

---

## 12. Change Discipline

* Do not change application code unless essential for the test. Acceptable: `data-testid` additions, test-only config/fixtures.
* One PR = one change type. Touch only files listed in the PR scope.
* If a helper signature changes → update all call sites → run full affected suite.
* Do not modify unrelated files.
* Before committing:

  ```bash
  make format && make lint && make test
  ```
