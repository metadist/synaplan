<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { ChevronDownIcon, DocumentTextIcon, XMarkIcon } from '@heroicons/vue/24/outline'
import { useFullscreenTeleportTarget } from '@/composables/useFullscreenTeleportTarget'
import WhatWasSentRound from '@/components/chat/WhatWasSentRound.vue'
import { groupProviderTranscript } from '@/utils/providerTranscriptView'

const props = defineProps<{
  messages: Array<{ role: string; text: string; request?: number }> | null
  truncated: boolean
}>()

const emit = defineEmits<{ close: [] }>()

const { t } = useI18n()
const { teleportTarget } = useFullscreenTeleportTarget()

const panelRef = ref<HTMLElement | null>(null)
const scrollerRef = ref<HTMLElement | null>(null)
const closeRef = ref<HTMLButtonElement | null>(null)

const rounds = computed(() => groupProviderTranscript(props.messages))
const latest = computed(() => rounds.value[rounds.value.length - 1] ?? null)
const earlier = computed(() => rounds.value.slice(0, -1))
const empty = computed(() => latest.value === null)

const parts = computed(() => {
  const round = latest.value
  if (!round) return []
  const items: Array<{ id: string; label: string }> = []
  if (round.question) {
    items.push({ id: 'what-was-sent-question', label: t('chatMessage.whatWasSentQuestion') })
  }
  if (round.history.length > 0) {
    items.push({ id: 'what-was-sent-history', label: t('chatMessage.whatWasSentHistory') })
  }
  if (round.after.length > 0) {
    items.push({ id: 'what-was-sent-after', label: t('chatMessage.whatWasSentAfter') })
  }
  if (round.instructions.length > 0) {
    items.push({
      id: 'what-was-sent-instructions',
      label: t('chatMessage.whatWasSentInstructions'),
    })
  }
  return items
})

const FOCUSABLE =
  'a[href], button:not([disabled]), textarea:not([disabled]), input:not([disabled]), select:not([disabled]), summary, [tabindex]:not([tabindex="-1"])'

let previousOverflow = ''

function focusableInPanel(): HTMLElement[] {
  if (!panelRef.value) return []
  return Array.from(panelRef.value.querySelectorAll<HTMLElement>(FOCUSABLE)).filter(
    (el) => !el.hasAttribute('disabled') && el.tabIndex !== -1
  )
}

function scrollToSection(id: string) {
  const scroller = scrollerRef.value
  const target = scroller?.querySelector<HTMLElement>(`#${id}`)
  if (!scroller || !target) return
  const top =
    target.getBoundingClientRect().top - scroller.getBoundingClientRect().top + scroller.scrollTop
  scroller.scrollTo({ top: Math.max(0, top - 12), behavior: 'smooth' })
}

function onKeydown(event: KeyboardEvent) {
  if (event.key === 'Escape') {
    event.preventDefault()
    emit('close')
    return
  }
  if (event.key !== 'Tab') return

  const nodes = focusableInPanel()
  if (nodes.length === 0) {
    event.preventDefault()
    return
  }
  const first = nodes[0]
  const last = nodes[nodes.length - 1]
  const active = document.activeElement
  if (event.shiftKey && active === first) {
    event.preventDefault()
    last.focus()
  } else if (!event.shiftKey && active === last) {
    event.preventDefault()
    first.focus()
  }
}

onMounted(() => {
  previousOverflow = document.body.style.overflow
  document.body.style.overflow = 'hidden'
  window.addEventListener('keydown', onKeydown)
  void nextTick(() => closeRef.value?.focus())
})

onUnmounted(() => {
  document.body.style.overflow = previousOverflow
  window.removeEventListener('keydown', onKeydown)
})
</script>

<template>
  <Teleport :to="teleportTarget">
    <Transition name="what-was-sent" appear>
      <div
        class="modal-overlay fixed inset-0 z-[10000] flex items-center justify-center p-4"
        role="presentation"
      >
        <button
          type="button"
          class="absolute inset-0 z-0 border-0 bg-black/50 p-0 backdrop-blur-sm dark:bg-black/70"
          tabindex="-1"
          :aria-label="t('common.close')"
          data-testid="btn-what-was-sent-backdrop"
          @click="emit('close')"
        />
        <div
          ref="panelRef"
          class="what-was-sent-panel modal-panel surface-card relative z-10 flex w-full min-w-0 max-w-3xl flex-col overflow-hidden rounded-xl shadow-2xl"
          role="dialog"
          aria-modal="true"
          aria-labelledby="what-was-sent-title"
          data-testid="dialog-what-was-sent"
        >
          <header
            class="relative z-10 shrink-0 border-b border-light-border/30 bg-inherit px-5 py-4 dark:border-dark-border/20 sm:px-6 sm:py-5"
          >
            <div class="flex items-start justify-between gap-3">
              <div class="flex min-w-0 items-start gap-3">
                <div
                  class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-[var(--brand)]/10 text-[var(--brand)]"
                  aria-hidden="true"
                >
                  <DocumentTextIcon class="h-5 w-5" />
                </div>
                <div class="min-w-0">
                  <h2 id="what-was-sent-title" class="text-lg font-semibold txt-primary">
                    {{ t('chatMessage.whatWasSentTitle') }}
                  </h2>
                  <p class="mt-1 text-sm leading-relaxed txt-secondary">
                    {{ t('chatMessage.whatWasSentBody') }}
                  </p>
                </div>
              </div>
              <button
                ref="closeRef"
                type="button"
                class="icon-ghost shrink-0"
                :aria-label="t('common.close')"
                data-testid="btn-what-was-sent-dismiss"
                @click="emit('close')"
              >
                <XMarkIcon class="h-5 w-5" />
              </button>
            </div>
            <nav
              v-if="parts.length > 1"
              class="mt-4 flex flex-wrap gap-2"
              :aria-label="t('chatMessage.whatWasSentTitle')"
            >
              <button
                v-for="part in parts"
                :key="part.id"
                type="button"
                class="pill text-sm"
                :aria-controls="part.id"
                @click="scrollToSection(part.id)"
              >
                {{ part.label }}
              </button>
            </nav>
          </header>

          <div
            ref="scrollerRef"
            class="min-h-0 flex-1 overflow-y-auto overscroll-contain scroll-thin px-5 py-4 sm:px-6 sm:py-5"
          >
            <p
              v-if="empty"
              class="text-sm leading-relaxed txt-primary"
              data-testid="text-what-was-sent-empty"
            >
              {{ t('chatMessage.whatWasSentEmpty') }}
            </p>
            <div v-else-if="latest" class="space-y-5">
              <WhatWasSentRound :round="latest" id-prefix="what-was-sent" primary />
              <details
                v-if="earlier.length > 0"
                class="what-was-sent-earlier group rounded-xl border border-light-border/30 dark:border-dark-border/20"
                data-testid="details-what-was-sent-earlier"
              >
                <summary
                  class="flex cursor-pointer list-none items-center justify-between gap-3 px-4 py-3 text-sm font-medium txt-primary"
                >
                  <span>{{
                    t(
                      'chatMessage.whatWasSentEarlierRounds',
                      { count: earlier.length },
                      earlier.length
                    )
                  }}</span>
                  <ChevronDownIcon
                    class="h-4 w-4 shrink-0 txt-secondary transition-transform group-open:rotate-180"
                    aria-hidden="true"
                  />
                </summary>
                <div
                  class="space-y-5 border-t border-light-border/30 px-4 py-4 dark:border-dark-border/20"
                >
                  <div
                    v-for="(round, index) in earlier"
                    :key="`earlier-${index}`"
                    class="space-y-3"
                  >
                    <p class="text-xs font-medium txt-secondary">
                      {{ t('chatMessage.whatWasSentRound', { number: index + 1 }) }}
                    </p>
                    <WhatWasSentRound
                      :round="round"
                      :id-prefix="`what-was-sent-earlier-${index}`"
                    />
                  </div>
                </div>
              </details>
            </div>
          </div>

          <footer
            class="relative z-10 shrink-0 space-y-3 border-t border-light-border/30 bg-inherit px-5 py-4 dark:border-dark-border/20 sm:px-6"
          >
            <p
              v-if="truncated"
              class="text-sm leading-relaxed txt-secondary"
              data-testid="text-what-was-sent-truncated"
            >
              {{ t('chatMessage.whatWasSentTruncated') }}
            </p>
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
              <p class="text-sm leading-relaxed txt-secondary">
                {{ t('chatMessage.whatWasSentSession') }}
              </p>
              <button
                type="button"
                class="btn-secondary w-full px-4 py-2.5 text-sm font-medium sm:w-auto"
                data-testid="btn-what-was-sent-close"
                @click="emit('close')"
              >
                {{ t('common.close') }}
              </button>
            </div>
          </footer>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.what-was-sent-enter-active {
  transition: opacity 0.18s ease;
}

.what-was-sent-enter-from {
  opacity: 0;
}

.what-was-sent-enter-active .what-was-sent-panel {
  animation: what-was-sent-in 0.18s ease-out;
}

@keyframes what-was-sent-in {
  from {
    opacity: 0;
    transform: scale(0.98);
  }
  to {
    opacity: 1;
    transform: scale(1);
  }
}

.what-was-sent-earlier summary::-webkit-details-marker {
  display: none;
}
</style>
