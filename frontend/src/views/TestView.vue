<template>
  <MainLayout>
    <div
      class="min-h-screen bg-chat p-4 md:p-8 overflow-y-auto scroll-thin"
      data-testid="page-test"
    >
      <div class="max-w-7xl mx-auto space-y-6">
        <div class="surface-card p-6" data-testid="section-header">
          <h1 class="text-3xl font-bold txt-primary mb-2">Testing Ground</h1>
          <p class="txt-secondary text-sm">Test notifications and other functionalities</p>
        </div>

        <div class="surface-card p-6" data-testid="section-routes">
          <h2 class="text-xl font-semibold txt-primary mb-4">Routes</h2>
          <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-2">
            <router-link
              data-testid="link-route"
              to="/"
              class="px-4 py-2 rounded surface-chip hover-surface txt-primary text-sm"
              >/</router-link
            >
            <router-link
              data-testid="link-route"
              to="/login"
              class="px-4 py-2 rounded surface-chip hover-surface txt-primary text-sm"
              >/login</router-link
            >
            <router-link
              data-testid="link-route"
              to="/register"
              class="px-4 py-2 rounded surface-chip hover-surface txt-primary text-sm"
              >/register</router-link
            >
            <router-link
              data-testid="link-route"
              to="/forgot-password"
              class="px-4 py-2 rounded surface-chip hover-surface txt-primary text-sm"
              >/forgot-password</router-link
            >
            <router-link
              data-testid="link-route"
              to="/verify-email"
              class="px-4 py-2 rounded surface-chip hover-surface txt-primary text-sm"
              >/verify-email</router-link
            >
            <router-link
              data-testid="link-route"
              to="/email-verified"
              class="px-4 py-2 rounded surface-chip hover-surface txt-primary text-sm"
              >/email-verified</router-link
            >
            <router-link
              data-testid="link-route"
              to="/tools/introduction"
              class="px-4 py-2 rounded surface-chip hover-surface txt-primary text-sm"
              >/tools/introduction</router-link
            >
            <router-link
              data-testid="link-route"
              to="/tools/chat-widget"
              class="px-4 py-2 rounded surface-chip hover-surface txt-primary text-sm"
              >/tools/chat-widget</router-link
            >
            <router-link
              data-testid="link-route"
              to="/tools/doc-summary"
              class="px-4 py-2 rounded surface-chip hover-surface txt-primary text-sm"
              >/tools/doc-summary</router-link
            >
            <router-link
              data-testid="link-route"
              to="/tools/mail-handler"
              class="px-4 py-2 rounded surface-chip hover-surface txt-primary text-sm"
              >/tools/mail-handler</router-link
            >
            <router-link
              data-testid="link-route"
              to="/files"
              class="px-4 py-2 rounded surface-chip hover-surface txt-primary text-sm"
              >/files</router-link
            >
            <router-link
              data-testid="link-route"
              to="/config/inbound"
              class="px-4 py-2 rounded surface-chip hover-surface txt-primary text-sm"
              >/config/inbound</router-link
            >
            <router-link
              data-testid="link-route"
              to="/config/ai-models"
              class="px-4 py-2 rounded surface-chip hover-surface txt-primary text-sm"
              >/config/ai-models</router-link
            >
            <router-link
              data-testid="link-route"
              to="/config/task-prompts"
              class="px-4 py-2 rounded surface-chip hover-surface txt-primary text-sm"
              >/config/task-prompts</router-link
            >
            <router-link
              data-testid="link-route"
              to="/config/sorting-prompt"
              class="px-4 py-2 rounded surface-chip hover-surface txt-primary text-sm"
              >/config/sorting-prompt</router-link
            >
            <router-link
              data-testid="link-route"
              to="/config/api-keys"
              class="px-4 py-2 rounded surface-chip hover-surface txt-primary text-sm"
              >/config/api-keys</router-link
            >
            <router-link
              data-testid="link-route"
              to="/statistics"
              class="px-4 py-2 rounded surface-chip hover-surface txt-primary text-sm"
              >/statistics</router-link
            >
            <router-link
              data-testid="link-route"
              to="/profile"
              class="px-4 py-2 rounded surface-chip hover-surface txt-primary text-sm"
              >/profile</router-link
            >
          </div>
        </div>

        <div class="surface-card p-6" data-testid="section-notifications">
          <h2 class="text-xl font-semibold txt-primary mb-4">Notifications</h2>
          <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
            <button
              class="px-6 py-3 rounded bg-green-500 hover:bg-green-600 text-white font-medium transition-colors"
              data-testid="btn-test-success"
              @click="testSuccess"
            >
              Test Success
            </button>
            <button
              class="px-6 py-3 rounded bg-red-500 hover:bg-red-600 text-white font-medium transition-colors"
              data-testid="btn-test-error"
              @click="testError"
            >
              Test Error
            </button>
            <button
              class="px-6 py-3 rounded bg-orange-500 hover:bg-orange-600 text-white font-medium transition-colors"
              data-testid="btn-test-warning"
              @click="testWarning"
            >
              Test Warning
            </button>
            <button
              class="px-6 py-3 rounded bg-blue-500 hover:bg-blue-600 text-white font-medium transition-colors"
              data-testid="btn-test-info"
              @click="testInfo"
            >
              Test Info
            </button>
          </div>

          <div class="mt-6 space-y-3" data-testid="section-advanced-notifications">
            <button
              class="w-full px-6 py-3 rounded border border-[var(--brand)] text-[var(--brand)] hover:bg-[var(--brand)]/10 font-medium transition-colors"
              data-testid="btn-test-long"
              @click="testLongMessage"
            >
              Test Long Message
            </button>
            <button
              class="w-full px-6 py-3 rounded border border-[var(--brand)] text-[var(--brand)] hover:bg-[var(--brand)]/10 font-medium transition-colors"
              data-testid="btn-test-multiple"
              @click="testMultiple"
            >
              Test Multiple Notifications
            </button>
            <button
              class="w-full px-6 py-3 rounded border border-[var(--brand)] text-[var(--brand)] hover:bg-[var(--brand)]/10 font-medium transition-colors"
              data-testid="btn-test-persistent"
              @click="testPersistent"
            >
              Test Persistent Notification (No Auto-Dismiss)
            </button>
          </div>
        </div>

        <div class="surface-card p-6" data-testid="section-design-tokens">
          <h2 class="text-xl font-semibold txt-primary mb-4">Design Tokens</h2>
          <div class="space-y-4">
            <div>
              <h3 class="text-sm font-semibold txt-primary mb-2">Backgrounds</h3>
              <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                <div class="bg-app p-4 rounded border border-light-border dark:border-dark-border">
                  <p class="text-xs font-mono">bg-app</p>
                </div>
                <div
                  class="bg-sidebar p-4 rounded border border-light-border dark:border-dark-border"
                >
                  <p class="text-xs font-mono">bg-sidebar</p>
                </div>
                <div class="bg-chat p-4 rounded border border-light-border dark:border-dark-border">
                  <p class="text-xs font-mono">bg-chat</p>
                </div>
                <div class="surface-card p-4 rounded">
                  <p class="text-xs font-mono">surface-card</p>
                </div>
              </div>
            </div>

            <div>
              <h3 class="text-sm font-semibold txt-primary mb-2">Text Colors</h3>
              <div class="space-y-2">
                <p class="txt-primary">txt-primary - Main text color</p>
                <p class="txt-secondary">txt-secondary - Secondary text color</p>
                <p class="text-[var(--brand)]">Brand color text</p>
              </div>
            </div>

            <div>
              <h3 class="text-sm font-semibold txt-primary mb-2">Buttons</h3>
              <div class="flex flex-wrap gap-3">
                <button class="btn-primary px-6 py-2.5 rounded">Primary Button</button>
                <button
                  class="px-6 py-2.5 rounded border border-[var(--brand)] text-[var(--brand)] hover:bg-[var(--brand)]/10 transition-colors"
                >
                  Secondary Button
                </button>
                <button class="icon-ghost">
                  <CheckIcon class="w-5 h-5" />
                </button>
              </div>
            </div>

            <div>
              <h3 class="text-sm font-semibold txt-primary mb-2">Pills</h3>
              <div class="flex flex-wrap gap-2">
                <span class="pill">Default Pill</span>
                <span class="pill pill--active">Active Pill</span>
                <span class="pill bg-green-500/10 text-green-500">Success Pill</span>
                <span class="pill bg-red-500/10 text-red-500">Error Pill</span>
              </div>
            </div>

            <div>
              <h3 class="text-sm font-semibold txt-primary mb-2">Chat Bubbles</h3>
              <div class="space-y-3">
                <div class="bubble-ai max-w-md">
                  <p class="text-sm">This is an AI message bubble</p>
                </div>
                <div class="bubble-user max-w-md ml-auto">
                  <p class="text-sm">This is a user message bubble</p>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="surface-card p-6" data-testid="section-forms">
          <h2 class="text-xl font-semibold txt-primary mb-4">Forms</h2>
          <div class="space-y-4 max-w-2xl">
            <div>
              <label class="block text-sm font-semibold txt-primary mb-2">Text Input</label>
              <input
                type="text"
                placeholder="Enter text..."
                class="w-full px-4 py-2.5 rounded surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
              />
            </div>

            <div>
              <label class="block text-sm font-semibold txt-primary mb-2">Select</label>
              <select
                class="w-full px-4 py-2.5 rounded surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
              >
                <option>Option 1</option>
                <option>Option 2</option>
                <option>Option 3</option>
              </select>
            </div>

            <div>
              <label class="block text-sm font-semibold txt-primary mb-2">Textarea</label>
              <textarea
                rows="4"
                placeholder="Enter text..."
                class="w-full px-4 py-2.5 rounded surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)] resize-none"
              ></textarea>
            </div>

            <div class="flex items-center gap-2">
              <input
                id="test-checkbox"
                type="checkbox"
                class="w-5 h-5 rounded border-light-border/30 dark:border-dark-border/20 text-[var(--brand)] focus:ring-2 focus:ring-[var(--brand)]"
              />
              <label for="test-checkbox" class="text-sm txt-primary">Checkbox Label</label>
            </div>
          </div>
        </div>

        <div class="surface-card p-6" data-testid="section-icons">
          <h2 class="text-xl font-semibold txt-primary mb-4">Icons</h2>
          <div class="grid grid-cols-4 md:grid-cols-8 gap-4">
            <div class="flex flex-col items-center gap-2 p-3 surface-chip rounded">
              <CheckIcon class="w-6 h-6 txt-primary" />
              <span class="text-xs txt-secondary">Check</span>
            </div>
            <div class="flex flex-col items-center gap-2 p-3 surface-chip rounded">
              <XMarkIcon class="w-6 h-6 txt-primary" />
              <span class="text-xs txt-secondary">XMark</span>
            </div>
            <div class="flex flex-col items-center gap-2 p-3 surface-chip rounded">
              <TrashIcon class="w-6 h-6 txt-primary" />
              <span class="text-xs txt-secondary">Trash</span>
            </div>
            <div class="flex flex-col items-center gap-2 p-3 surface-chip rounded">
              <PencilIcon class="w-6 h-6 txt-primary" />
              <span class="text-xs txt-secondary">Pencil</span>
            </div>
            <div class="flex flex-col items-center gap-2 p-3 surface-chip rounded">
              <PlusIcon class="w-6 h-6 txt-primary" />
              <span class="text-xs txt-secondary">Plus</span>
            </div>
            <div class="flex flex-col items-center gap-2 p-3 surface-chip rounded">
              <Cog6ToothIcon class="w-6 h-6 txt-primary" />
              <span class="text-xs txt-secondary">Cog</span>
            </div>
            <div class="flex flex-col items-center gap-2 p-3 surface-chip rounded">
              <ArrowRightIcon class="w-6 h-6 txt-primary" />
              <span class="text-xs txt-secondary">Arrow</span>
            </div>
            <div class="flex flex-col items-center gap-2 p-3 surface-chip rounded">
              <MagnifyingGlassIcon class="w-6 h-6 txt-primary" />
              <span class="text-xs txt-secondary">Search</span>
            </div>
          </div>
        </div>

        <div class="surface-card p-6" data-testid="section-loading">
          <h2 class="text-xl font-semibold txt-primary mb-4">Loading States</h2>
          <div class="flex flex-wrap gap-4">
            <button class="btn-primary px-6 py-2.5 rounded flex items-center gap-2">
              <svg
                class="animate-spin h-5 w-5"
                xmlns="http://www.w3.org/2000/svg"
                fill="none"
                viewBox="0 0 24 24"
              >
                <circle
                  class="opacity-25"
                  cx="12"
                  cy="12"
                  r="10"
                  stroke="currentColor"
                  stroke-width="4"
                ></circle>
                <path
                  class="opacity-75"
                  fill="currentColor"
                  d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
                ></path>
              </svg>
              Loading...
            </button>
            <div class="surface-chip px-4 py-2 rounded flex items-center gap-2">
              <div class="w-2 h-2 bg-[var(--brand)] rounded-full animate-pulse"></div>
              <span class="text-sm txt-primary">Processing...</span>
            </div>
          </div>
        </div>
      </div>
    </div>
  </MainLayout>
</template>

<script setup lang="ts">
import MainLayout from '@/components/MainLayout.vue'
import { useNotification } from '@/composables/useNotification'
import {
  CheckIcon,
  XMarkIcon,
  TrashIcon,
  PencilIcon,
  PlusIcon,
  Cog6ToothIcon,
  ArrowRightIcon,
  MagnifyingGlassIcon,
} from '@heroicons/vue/24/outline'

const { success, error, warning, info } = useNotification()

const testSuccess = () => {
  success('Operation completed successfully!')
}

const testError = () => {
  error('Something went wrong. Please try again.')
}

const testWarning = () => {
  warning('This action requires confirmation.')
}

const testInfo = () => {
  info('New updates are available.')
}

const testLongMessage = () => {
  success(
    'This is a very long notification message to test how the notification system handles longer text content. It should wrap properly and remain readable across different screen sizes.'
  )
}

const testMultiple = () => {
  success('First notification')
  setTimeout(() => error('Second notification'), 300)
  setTimeout(() => warning('Third notification'), 600)
  setTimeout(() => info('Fourth notification'), 900)
}

const testPersistent = () => {
  error('This notification will not auto-dismiss. Close it manually.', 0)
}
</script>
