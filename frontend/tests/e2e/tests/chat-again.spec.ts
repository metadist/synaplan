import { test, expect } from '../test-setup'
import { selectors } from '../helpers/selectors'
import { login } from '../helpers/auth'
import { ChatHelper } from '../helpers/chat'
import { TIMEOUTS } from '../config/config'
import { PROMPTS } from '../config/test-data'

test.describe('@ci @smoke Chat Again', () => {
  test('again via button then dropdown works repeatedly without refresh', async ({
    page,
    credentials,
  }) => {
    // 3 sequential AI responses require extended timeout
    test.setTimeout(90_000)
    const chat = new ChatHelper(page)

    await test.step('Arrange: login and start new chat', async () => {
      await login(page, credentials)
      await chat.startNewChat()
    })

    // -- Turn 1: send message, get first AI response --
    let previousCount = await chat.conversationBubbles().count()

    await test.step('Act: send initial message', async () => {
      await page.locator(selectors.chat.textInput).fill(PROMPTS.CHAT_SMOKE)
      await page.locator(selectors.chat.sendBtn).click()
    })

    const firstAnswer = await chat.waitForAnswer(previousCount)

    await test.step('Assert: first response is a real answer', async () => {
      expect(firstAnswer.trim().length).toBeGreaterThan(5)
    })

    // -- Turn 2: Again button on the first response --
    const firstResponseIndex = previousCount
    previousCount = await chat.conversationBubbles().count()

    await test.step(
      'Act: click Again on first response (bubble ' + firstResponseIndex + ')',
      async () => {
        const bubble = chat.conversationBubbles().nth(firstResponseIndex)
        await bubble.scrollIntoViewIfNeeded()
        const againBtn = bubble.locator(selectors.chat.againBtn)
        await expect(againBtn).toBeVisible({ timeout: TIMEOUTS.STANDARD })
        await expect(againBtn).toBeEnabled({ timeout: TIMEOUTS.SHORT })
        await againBtn.click()
      }
    )

    const secondAnswer = await chat.waitForAnswer(previousCount)
    const secondResponseIndex = previousCount

    await test.step('Assert: second response (from Again button) is a real answer', async () => {
      expect(secondAnswer.trim().length).toBeGreaterThan(5)
      const count = await chat.conversationBubbles().count()
      expect(count).toBe(secondResponseIndex + 1)
    })

    // -- Turn 3: dropdown model selection on the second response (the bug scenario) --
    previousCount = await chat.conversationBubbles().count()

    await test.step(
      'Act: open dropdown on second response (bubble ' +
        secondResponseIndex +
        ') and select a model',
      async () => {
        const bubble = chat.conversationBubbles().nth(secondResponseIndex)
        await bubble.scrollIntoViewIfNeeded()

        const toggle = bubble.locator(selectors.chat.againDropdown)
        await expect(toggle).toBeVisible({ timeout: TIMEOUTS.STANDARD })
        await expect(toggle).toBeEnabled({ timeout: TIMEOUTS.SHORT })
        await toggle.click()

        const dropdown = bubble.locator(selectors.chat.againDropdownPanel)
        await dropdown.waitFor({ state: 'visible', timeout: TIMEOUTS.STANDARD })

        const options = dropdown.locator(selectors.chat.againDropdownItem)
        const optionCount = await options.count()
        expect(optionCount).toBeGreaterThan(0)

        let clicked = false
        for (let i = 0; i < optionCount; i++) {
          const opt = options.nth(i)
          if ((await opt.isVisible()) && (await opt.isEnabled())) {
            await opt.click()
            clicked = true
            break
          }
        }
        expect(clicked, 'At least one dropdown option should be visible and enabled').toBe(true)

        await dropdown.waitFor({ state: 'hidden', timeout: TIMEOUTS.SHORT }).catch(() => {
          // Dropdown may auto-close after selection — not critical
        })
      }
    )

    const thirdAnswer = await chat.waitForAnswer(previousCount)
    const thirdResponseIndex = previousCount

    await test.step('Assert: third response (from dropdown after Again) is a real answer', async () => {
      expect(thirdAnswer.trim().length).toBeGreaterThan(5)
      const count = await chat.conversationBubbles().count()
      expect(count).toBe(thirdResponseIndex + 1)
    })
  })
})
