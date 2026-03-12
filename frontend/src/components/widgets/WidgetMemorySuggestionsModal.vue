<template>
  <Teleport to="body">
    <Transition
      enter-active-class="transition-all duration-400 ease-out"
      enter-from-class="opacity-0"
      enter-to-class="opacity-100"
      leave-active-class="transition-all duration-300 ease-in"
      leave-from-class="opacity-100"
      leave-to-class="opacity-0"
    >
      <div
        v-if="visible"
        class="fixed inset-0 z-[100] flex items-center justify-center p-4"
        @click.self="close"
      >
        <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" />

        <Transition
          enter-active-class="transition-all duration-400 ease-out delay-75"
          enter-from-class="opacity-0 scale-95 translate-y-4"
          enter-to-class="opacity-100 scale-100 translate-y-0"
          leave-active-class="transition-all duration-200 ease-in"
          leave-from-class="opacity-100 scale-100"
          leave-to-class="opacity-0 scale-95 translate-y-4"
        >
          <div
            v-if="visible"
            class="relative w-full max-w-lg surface-card rounded-2xl shadow-2xl border border-light-border/20 dark:border-dark-border/15 overflow-hidden"
          >
            <div
              class="h-1 w-full bg-gradient-to-r from-[var(--brand)] via-purple-500 to-blue-500"
            />

            <!-- Header -->
            <div class="px-6 pt-5 pb-3">
              <div class="flex items-start gap-3.5">
                <div
                  class="w-10 h-10 rounded-xl bg-gradient-to-br from-[var(--brand)]/20 to-purple-500/10 flex items-center justify-center flex-shrink-0"
                >
                  <Icon icon="heroicons:light-bulb" class="w-5 h-5 txt-brand" />
                </div>
                <div class="flex-1 min-w-0">
                  <h3 class="text-base font-semibold txt-primary">
                    {{ $t('widgets.detail.memorySuggestions.title') }}
                  </h3>
                  <p class="text-xs txt-secondary mt-0.5 leading-relaxed">
                    {{ $t('widgets.detail.memorySuggestions.subtitle') }}
                  </p>
                </div>
                <button
                  class="p-1.5 rounded-lg txt-secondary hover:txt-primary hover:bg-gray-100 dark:hover:bg-white/5 transition-colors flex-shrink-0"
                  @click="close"
                >
                  <Icon icon="heroicons:x-mark" class="w-4.5 h-4.5" />
                </button>
              </div>
            </div>

            <!-- Suggestions list -->
            <div class="px-6 pb-2 max-h-[50vh] overflow-y-auto scroll-thin">
              <TransitionGroup
                enter-active-class="transition-all duration-300 ease-out"
                enter-from-class="opacity-0 translate-x-2"
                enter-to-class="opacity-100 translate-x-0"
                leave-active-class="transition-all duration-200 ease-in"
                leave-from-class="opacity-100"
                leave-to-class="opacity-0 -translate-x-2"
                tag="div"
                class="space-y-2"
              >
                <div
                  v-for="(item, idx) in suggestions"
                  :key="item.id"
                  :class="[
                    'group relative flex items-start gap-3 rounded-xl border p-3.5 transition-all duration-200 cursor-pointer',
                    isAccepted(item.id)
                      ? 'border-[var(--brand)]/40 bg-[var(--brand)]/[0.04]'
                      : isRejected(item.id)
                        ? 'border-red-300/30 dark:border-red-800/30 bg-red-50/50 dark:bg-red-950/10 opacity-60'
                        : 'border-light-border/25 dark:border-dark-border/15 hover:border-[var(--brand)]/30 hover:bg-[var(--brand)]/[0.02]',
                  ]"
                  :style="{ animationDelay: `${idx * 50}ms` }"
                  @click="toggle(item.id)"
                >
                  <div class="pt-0.5 flex-shrink-0">
                    <div
                      :class="[
                        'w-5 h-5 rounded-md border-2 flex items-center justify-center transition-all duration-200',
                        isAccepted(item.id)
                          ? 'border-[var(--brand)] bg-[var(--brand)]'
                          : isRejected(item.id)
                            ? 'border-red-400 bg-red-400'
                            : 'border-gray-300 dark:border-gray-600 group-hover:border-[var(--brand)]/50',
                      ]"
                    >
                      <Icon
                        v-if="isAccepted(item.id)"
                        icon="heroicons:check"
                        class="w-3.5 h-3.5 text-white"
                      />
                      <Icon
                        v-else-if="isRejected(item.id)"
                        icon="heroicons:x-mark"
                        class="w-3.5 h-3.5 text-white"
                      />
                    </div>
                  </div>

                  <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2 mb-0.5">
                      <span
                        :class="[
                          'inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-[10px] font-semibold uppercase tracking-wider',
                          typeStyles[item.responseType]?.bg ?? 'bg-gray-100 dark:bg-white/5',
                          typeStyles[item.responseType]?.color ?? 'txt-secondary',
                        ]"
                      >
                        <Icon
                          :icon="typeStyles[item.responseType]?.icon ?? 'heroicons:document-text'"
                          class="w-3 h-3"
                        />
                        {{ item.responseType }}
                      </span>
                      <span
                        class="text-[10px] txt-secondary/60 uppercase tracking-wider font-medium"
                      >
                        {{ item.category }}
                      </span>
                    </div>
                    <p class="text-sm font-medium txt-primary leading-snug">
                      {{ item.widgetField }}
                    </p>
                    <p class="text-xs txt-secondary mt-0.5 leading-relaxed truncate">
                      {{ item.value }}
                    </p>
                    <a
                      v-if="item.meta?.url"
                      :href="item.meta.url"
                      target="_blank"
                      rel="noopener"
                      class="inline-flex items-center gap-1 text-[11px] text-blue-500 hover:underline mt-1"
                      @click.stop
                    >
                      <Icon icon="heroicons:arrow-top-right-on-square" class="w-3 h-3" />
                      {{ item.meta.url }}
                    </a>
                  </div>

                  <button
                    v-if="!isRejected(item.id)"
                    class="p-1 rounded-lg opacity-0 group-hover:opacity-100 transition-opacity txt-secondary hover:text-red-500 flex-shrink-0"
                    :title="$t('widgets.detail.memorySuggestions.reject')"
                    @click.stop="reject(item.id)"
                  >
                    <Icon icon="heroicons:x-circle" class="w-4 h-4" />
                  </button>
                  <button
                    v-else
                    class="p-1 rounded-lg opacity-0 group-hover:opacity-100 transition-opacity txt-secondary hover:txt-primary flex-shrink-0"
                    :title="$t('widgets.detail.memorySuggestions.undo')"
                    @click.stop="undoReject(item.id)"
                  >
                    <Icon icon="heroicons:arrow-uturn-left" class="w-4 h-4" />
                  </button>
                </div>
              </TransitionGroup>
            </div>

            <!-- Footer -->
            <div
              class="px-6 py-4 border-t border-light-border/15 dark:border-dark-border/10 flex items-center justify-between gap-3"
            >
              <div class="flex items-center gap-2">
                <button
                  class="text-xs font-medium txt-secondary hover:txt-primary transition-colors"
                  @click="selectAll"
                >
                  {{ $t('widgets.detail.memorySuggestions.selectAll') }}
                </button>
                <span class="text-xs txt-secondary/40">·</span>
                <button
                  class="text-xs font-medium txt-secondary hover:txt-primary transition-colors"
                  @click="deselectAll"
                >
                  {{ $t('widgets.detail.memorySuggestions.deselectAll') }}
                </button>
              </div>
              <div class="flex items-center gap-2">
                <button
                  class="px-4 py-2 rounded-xl text-sm font-medium txt-secondary hover:txt-primary transition-colors"
                  @click="close"
                >
                  {{ $t('widgets.detail.memorySuggestions.skip') }}
                </button>
                <button
                  :disabled="acceptedCount === 0"
                  class="px-5 py-2 rounded-xl text-sm font-semibold bg-[var(--brand)] text-white hover:opacity-90 transition-opacity disabled:opacity-30 disabled:cursor-not-allowed"
                  @click="confirm"
                >
                  {{ $t('widgets.detail.memorySuggestions.apply', { count: acceptedCount }) }}
                </button>
              </div>
            </div>
          </div>
        </Transition>
      </div>
    </Transition>
  </Teleport>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import { Icon } from '@iconify/vue'
import type { MemorySuggestion } from '@/services/api/widgetsApi'

const props = defineProps<{
  suggestions: MemorySuggestion[]
  visible: boolean
}>()

const emit = defineEmits<{
  close: []
  apply: [accepted: MemorySuggestion[]]
}>()

const accepted = ref(new Set<number>())
const rejected = ref(new Set<number>())

const typeStyles: Record<string, { icon: string; bg: string; color: string }> = {
  link: { icon: 'heroicons:globe-alt', bg: 'bg-blue-500/10', color: 'text-blue-500' },
  text: { icon: 'heroicons:document-text', bg: 'bg-emerald-500/10', color: 'text-emerald-500' },
  list: { icon: 'heroicons:list-bullet', bg: 'bg-amber-500/10', color: 'text-amber-500' },
  api: { icon: 'heroicons:server-stack', bg: 'bg-violet-500/10', color: 'text-violet-500' },
  custom: { icon: 'heroicons:sparkles', bg: 'bg-pink-500/10', color: 'text-pink-500' },
}

const isAccepted = (id: number) => accepted.value.has(id) && !rejected.value.has(id)
const isRejected = (id: number) => rejected.value.has(id)

const acceptedCount = computed(
  () => [...accepted.value].filter((id) => !rejected.value.has(id)).length
)

function toggle(id: number) {
  if (rejected.value.has(id)) {
    rejected.value.delete(id)
    accepted.value.add(id)
  } else if (accepted.value.has(id)) {
    accepted.value.delete(id)
  } else {
    accepted.value.add(id)
  }
}

function reject(id: number) {
  accepted.value.delete(id)
  rejected.value.add(id)
}

function undoReject(id: number) {
  rejected.value.delete(id)
}

function selectAll() {
  rejected.value.clear()
  for (const s of props.suggestions) {
    accepted.value.add(s.id)
  }
}

function deselectAll() {
  accepted.value.clear()
}

function close() {
  emit('close')
}

function confirm() {
  const selected = props.suggestions.filter(
    (s) => accepted.value.has(s.id) && !rejected.value.has(s.id)
  )
  emit('apply', selected)
}

function initSelection(suggestions: MemorySuggestion[]) {
  accepted.value.clear()
  rejected.value.clear()
  for (const s of suggestions) {
    accepted.value.add(s.id)
  }
}

defineExpose({ initSelection })
</script>
