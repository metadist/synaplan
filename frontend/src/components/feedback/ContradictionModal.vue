<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { Icon } from '@iconify/vue'
import type { Contradiction } from '@/services/api/feedbackApi'

interface Props {
  isOpen: boolean
  contradictions: Contradiction[]
  newStatementSummary: string
  newStatementCorrection: string
  classification?: 'memory' | 'feedback'
  isSubmitting?: boolean
}

const props = withDefaults(defineProps<Props>(), {
  classification: 'feedback',
  isSubmitting: false,
})

const isDeleteOnly = computed(
  () => props.classification === 'memory' && !props.newStatementCorrection.trim()
)

const emit = defineEmits<{
  close: []
  resolve: [data: { action: 'save' | 'cancel'; itemsToDelete: Contradiction[] }]
}>()

const { t } = useI18n()

// Track which items should be REPLACED (deleted) — checked = delete old, unchecked = keep old
const itemsToReplace = ref<Set<string>>(new Set())

// When modal opens or contradictions change, default to replacing all
watch(
  () => [props.isOpen, props.contradictions],
  () => {
    if (props.isOpen) {
      const all = new Set<string>()
      for (const c of props.contradictions) {
        all.add(keyOf(c))
      }
      itemsToReplace.value = all
    }
  },
  { deep: true }
)

function keyOf(c: Contradiction): string {
  return `${c.type}-${c.id}`
}

function toggleItem(c: Contradiction) {
  const key = keyOf(c)
  const next = new Set(itemsToReplace.value)
  if (next.has(key)) {
    next.delete(key)
  } else {
    next.add(key)
  }
  itemsToReplace.value = next
}

function selectAll() {
  const all = new Set<string>()
  for (const c of props.contradictions) {
    all.add(keyOf(c))
  }
  itemsToReplace.value = all
}

function deselectAll() {
  itemsToReplace.value = new Set()
}

const replaceCount = computed(() => itemsToReplace.value.size)
const keepCount = computed(() => props.contradictions.length - replaceCount.value)
const allSelected = computed(() => replaceCount.value === props.contradictions.length)
const noneSelected = computed(() => replaceCount.value === 0)

function typeIcon(type: string): string {
  if (type === 'memory') return 'mdi:brain'
  if (type === 'false_positive') return 'mdi:close-circle-outline'
  return 'mdi:check-circle-outline'
}

function handleSave() {
  const toDelete = props.contradictions.filter((c) => itemsToReplace.value.has(keyOf(c)))
  emit('resolve', { action: 'save', itemsToDelete: toDelete })
}

function handleCancel() {
  emit('resolve', { action: 'cancel', itemsToDelete: [] })
}
</script>

<template>
  <Teleport to="body">
    <Transition name="modal">
      <div
        v-if="isOpen"
        class="fixed inset-0 z-[10000] flex items-center justify-center p-2 sm:p-4"
        @click.self="handleCancel"
      >
        <div class="absolute inset-0 bg-black/50" />
        <div
          class="relative w-full max-w-2xl rounded-2xl shadow-xl surface-elevated overflow-hidden flex flex-col max-h-[95vh] sm:max-h-[90vh]"
          role="dialog"
          aria-modal="true"
          :aria-labelledby="'contradiction-modal-title'"
          @click.stop
        >
          <!-- Header -->
          <div class="bg-amber-500/5 border-b border-amber-500/20 shrink-0">
            <div class="flex items-center justify-between p-4 sm:p-5">
              <div class="flex items-center gap-3">
                <div class="w-11 h-11 rounded-xl bg-amber-500/10 flex items-center justify-center">
                  <Icon icon="mdi:swap-horizontal-circle-outline" class="w-6 h-6 text-amber-500" />
                </div>
                <div>
                  <h2
                    id="contradiction-modal-title"
                    class="text-base sm:text-lg font-semibold txt-primary"
                  >
                    {{ t('feedback.contradiction.title') }}
                  </h2>
                  <p class="text-xs txt-secondary mt-0.5">
                    {{ t('feedback.contradiction.subtitle') }}
                  </p>
                </div>
              </div>
              <button
                class="w-8 h-8 rounded-lg hover:bg-black/5 dark:hover:bg-white/5 flex items-center justify-center transition-colors shrink-0"
                @click="handleCancel"
              >
                <Icon icon="mdi:close" class="w-5 h-5 txt-secondary" />
              </button>
            </div>
          </div>

          <!-- Scrollable content -->
          <div class="overflow-y-auto flex-1 scroll-thin">
            <!-- Explanation -->
            <div class="px-4 sm:px-5 pt-4 sm:pt-5">
              <div
                class="flex items-start gap-3 rounded-xl bg-amber-500/5 border border-amber-500/15 p-3"
              >
                <Icon
                  icon="mdi:information-outline"
                  class="w-4.5 h-4.5 text-amber-500 shrink-0 mt-0.5"
                />
                <p class="text-xs txt-secondary leading-relaxed">
                  {{ t('feedback.contradiction.explanation') }}
                </p>
              </div>
            </div>

            <div class="p-4 sm:px-5 sm:py-4 space-y-5">
              <!-- What you want to save -->
              <div>
                <div
                  class="flex items-center gap-2 text-[11px] font-semibold uppercase tracking-wide txt-secondary mb-2"
                >
                  <Icon icon="mdi:arrow-right-circle" class="w-3.5 h-3.5 text-brand" />
                  {{ t('feedback.contradiction.newStatement') }}
                </div>
                <div class="rounded-xl border-2 border-brand/25 bg-brand/5 p-3.5 space-y-2">
                  <div v-if="newStatementSummary" class="flex items-start gap-2">
                    <div
                      class="w-5 h-5 rounded-full bg-red-500/10 flex items-center justify-center shrink-0 mt-0.5"
                    >
                      <Icon icon="mdi:close" class="w-3 h-3 text-red-500" />
                    </div>
                    <p class="text-sm txt-primary leading-relaxed">{{ newStatementSummary }}</p>
                  </div>
                  <div v-if="newStatementCorrection" class="flex items-start gap-2">
                    <div
                      class="w-5 h-5 rounded-full bg-green-500/10 flex items-center justify-center shrink-0 mt-0.5"
                    >
                      <Icon icon="mdi:check" class="w-3 h-3 text-green-500" />
                    </div>
                    <p class="text-sm txt-primary leading-relaxed">{{ newStatementCorrection }}</p>
                  </div>
                  <!-- Delete-only indicator for memory classification -->
                  <div v-if="isDeleteOnly" class="flex items-start gap-2">
                    <div
                      class="w-5 h-5 rounded-full bg-red-500/10 flex items-center justify-center shrink-0 mt-0.5"
                    >
                      <Icon icon="mdi:delete-outline" class="w-3 h-3 text-red-500" />
                    </div>
                    <p class="text-sm text-red-600 dark:text-red-400 font-medium leading-relaxed">
                      {{ t('feedback.contradiction.deleteOnlyHint') }}
                    </p>
                  </div>
                </div>
              </div>

              <!-- Conflicting items with individual controls -->
              <div>
                <div class="flex items-center justify-between mb-2">
                  <div
                    class="flex items-center gap-2 text-[11px] font-semibold uppercase tracking-wide txt-secondary"
                  >
                    <Icon icon="mdi:alert-outline" class="w-3.5 h-3.5 text-amber-500" />
                    {{ t('feedback.contradiction.existingItems') }}
                    <span class="text-[10px] font-normal txt-secondary/70">
                      ({{ contradictions.length }})
                    </span>
                  </div>
                  <!-- Bulk actions -->
                  <div class="flex items-center gap-1.5">
                    <button
                      type="button"
                      class="text-[10px] font-medium px-2 py-1 rounded-md transition-colors"
                      :class="
                        allSelected
                          ? 'bg-red-500/10 text-red-500'
                          : 'txt-secondary hover:txt-primary hover:bg-black/5 dark:hover:bg-white/5'
                      "
                      @click="selectAll"
                    >
                      {{ t('feedback.contradiction.replaceAll') }}
                    </button>
                    <button
                      type="button"
                      class="text-[10px] font-medium px-2 py-1 rounded-md transition-colors"
                      :class="
                        noneSelected
                          ? 'bg-green-500/10 text-green-500'
                          : 'txt-secondary hover:txt-primary hover:bg-black/5 dark:hover:bg-white/5'
                      "
                      @click="deselectAll"
                    >
                      {{ t('feedback.contradiction.keepAll') }}
                    </button>
                  </div>
                </div>

                <div class="space-y-2">
                  <div
                    v-for="(c, idx) in contradictions"
                    :key="`${c.type}-${c.id}-${idx}`"
                    class="rounded-xl surface-chip overflow-hidden transition-all"
                    :class="
                      itemsToReplace.has(keyOf(c))
                        ? 'ring-1 ring-red-500/25'
                        : 'ring-1 ring-green-500/25'
                    "
                  >
                    <!-- Item header with toggle -->
                    <div
                      class="flex items-center gap-3 px-3.5 py-2.5 cursor-pointer transition-colors"
                      :class="itemsToReplace.has(keyOf(c)) ? 'bg-red-500/5' : 'bg-green-500/5'"
                      @click="toggleItem(c)"
                    >
                      <div class="flex items-center gap-2 flex-1 min-w-0">
                        <Icon
                          :icon="typeIcon(c.type)"
                          class="w-4 h-4 shrink-0"
                          :class="{
                            'text-red-500': c.type === 'false_positive',
                            'text-green-500': c.type === 'positive',
                            'text-brand': c.type === 'memory',
                          }"
                        />
                        <span
                          class="text-[10px] font-semibold uppercase tracking-wide px-1.5 py-0.5 rounded-full shrink-0"
                          :class="{
                            'bg-red-500/10 text-red-500': c.type === 'false_positive',
                            'bg-green-500/10 text-green-500': c.type === 'positive',
                            'bg-brand/10 text-brand': c.type === 'memory',
                          }"
                        >
                          {{ t(`feedback.contradiction.type.${c.type}`) }}
                        </span>
                      </div>
                      <!-- Decision badge -->
                      <span
                        class="text-[10px] font-semibold px-2.5 py-1 rounded-full shrink-0 flex items-center gap-1"
                        :class="
                          itemsToReplace.has(keyOf(c))
                            ? 'bg-red-500/10 text-red-600 dark:text-red-400'
                            : 'bg-green-500/10 text-green-600 dark:text-green-400'
                        "
                      >
                        <Icon
                          :icon="itemsToReplace.has(keyOf(c)) ? 'mdi:delete-outline' : 'mdi:check'"
                          class="w-3 h-3"
                        />
                        {{
                          itemsToReplace.has(keyOf(c))
                            ? t('feedback.contradiction.willReplace')
                            : t('feedback.contradiction.willKeep')
                        }}
                      </span>
                    </div>
                    <!-- Item content -->
                    <div
                      class="px-3.5 py-2.5 border-t border-light-border/5 dark:border-dark-border/5"
                    >
                      <p class="text-sm txt-primary leading-relaxed">{{ c.value }}</p>
                      <p
                        v-if="c.reason"
                        class="text-xs txt-secondary mt-1.5 flex items-start gap-1.5"
                      >
                        <Icon
                          icon="mdi:information-outline"
                          class="w-3.5 h-3.5 shrink-0 mt-0.5 text-amber-500"
                        />
                        <span>{{ c.reason }}</span>
                      </p>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- Footer with summary + actions -->
          <div class="border-t border-light-border/10 dark:border-dark-border/10 shrink-0">
            <!-- Decision summary -->
            <div class="px-4 sm:px-5 pt-3.5 pb-2 flex items-center gap-3 text-xs txt-secondary">
              <span v-if="replaceCount > 0" class="flex items-center gap-1 text-red-500">
                <Icon icon="mdi:delete-outline" class="w-3.5 h-3.5" />
                {{ t('feedback.contradiction.summaryReplace', { count: replaceCount }) }}
              </span>
              <span v-if="keepCount > 0" class="flex items-center gap-1 text-green-500">
                <Icon icon="mdi:check" class="w-3.5 h-3.5" />
                {{ t('feedback.contradiction.summaryKeep', { count: keepCount }) }}
              </span>
            </div>

            <div class="px-4 sm:px-5 pb-4 sm:pb-5 space-y-2">
              <!-- Primary: Save with decisions -->
              <button
                type="button"
                class="w-full px-4 py-3 rounded-xl text-sm font-medium btn-primary flex items-center justify-center gap-2"
                :disabled="isSubmitting"
                @click="handleSave"
              >
                <Icon v-if="isSubmitting" icon="mdi:loading" class="w-4 h-4 animate-spin" />
                <Icon v-else icon="mdi:check-circle" class="w-4.5 h-4.5" />
                {{ t('feedback.contradiction.saveDecisions') }}
              </button>

              <!-- Cancel -->
              <button
                type="button"
                class="w-full px-4 py-2 rounded-xl text-xs font-medium txt-secondary hover:txt-primary transition-colors text-center"
                :disabled="isSubmitting"
                @click="handleCancel"
              >
                {{ t('feedback.contradiction.cancel') }}
              </button>
            </div>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.modal-enter-active,
.modal-leave-active {
  transition: opacity 0.2s ease;
}
.modal-enter-from,
.modal-leave-to {
  opacity: 0;
}
</style>
