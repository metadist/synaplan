<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { Icon } from '@iconify/vue'
import type { ReportReason } from '@/services/api/moderationApi'

interface Props {
  isOpen: boolean
  isSubmitting?: boolean
}

const props = withDefaults(defineProps<Props>(), {
  isSubmitting: false,
})

const emit = defineEmits<{
  close: []
  submit: [data: { reason: ReportReason; details: string }]
}>()

const { t } = useI18n()

// Ordered to match the backend enum; CSAE is surfaced explicitly because Apple
// (Guideline 1.2) requires a first-class path for the most severe category.
const REASONS: ReportReason[] = [
  'harassment',
  'hate_speech',
  'violence',
  'sexual_content',
  'csae',
  'spam',
  'illegal',
  'other',
]

const DETAILS_MAX = 1000

const selectedReason = ref<ReportReason | null>(null)
const details = ref('')

const canSubmit = computed(() => selectedReason.value !== null && !props.isSubmitting)

watch(
  () => props.isOpen,
  (open) => {
    if (open) {
      selectedReason.value = null
      details.value = ''
    }
  }
)

const handleSubmit = () => {
  if (!canSubmit.value || selectedReason.value === null) {
    return
  }
  emit('submit', { reason: selectedReason.value, details: details.value.trim() })
}
</script>

<template>
  <Teleport to="#app">
    <Transition name="fade">
      <div
        v-if="isOpen"
        class="modal-overlay fixed inset-0 bg-black/50 z-[10000] flex items-center justify-center p-2 sm:p-4"
        @click.self="emit('close')"
      >
        <div
          class="modal-panel surface-card rounded-2xl shadow-2xl max-w-lg w-full overflow-y-auto scroll-thin"
          @click.stop
        >
          <!-- Header -->
          <div
            class="flex items-center justify-between p-4 sm:p-6 border-b border-light-border/10 dark:border-dark-border/10"
          >
            <div class="flex items-center gap-3">
              <div
                class="w-10 h-10 rounded-xl bg-red-500/10 flex items-center justify-center shrink-0"
              >
                <Icon icon="mdi:flag-outline" class="w-5 h-5 text-red-500" />
              </div>
              <div>
                <h3 class="text-base sm:text-lg font-semibold txt-primary">
                  {{ t('moderation.report.title') }}
                </h3>
                <p class="text-xs txt-secondary">
                  {{ t('moderation.report.subtitle') }}
                </p>
              </div>
            </div>
            <button
              class="w-8 h-8 rounded-lg hover:bg-black/5 dark:hover:bg-white/5 flex items-center justify-center transition-colors shrink-0"
              :aria-label="t('common.cancel')"
              @click="emit('close')"
            >
              <Icon icon="mdi:close" class="w-5 h-5 txt-secondary" />
            </button>
          </div>

          <!-- Body -->
          <div class="p-4 sm:p-6 space-y-4">
            <p class="text-sm txt-secondary">
              {{ t('moderation.report.reasonLabel') }}
            </p>

            <div class="space-y-1.5">
              <label
                v-for="reason in REASONS"
                :key="reason"
                class="flex items-start gap-2.5 p-2.5 rounded-lg cursor-pointer transition-all"
                :class="
                  selectedReason === reason
                    ? 'bg-red-500/5 ring-1 ring-red-500/25'
                    : 'hover:bg-black/[0.02] dark:hover:bg-white/[0.02]'
                "
              >
                <input
                  type="radio"
                  name="report-reason"
                  :checked="selectedReason === reason"
                  class="mt-0.5 text-red-500 focus:ring-red-500/30"
                  @change="selectedReason = reason"
                />
                <div class="flex-1 min-w-0">
                  <p class="text-sm font-medium txt-primary">
                    {{ t(`moderation.reasons.${reason}.label`) }}
                  </p>
                  <p class="text-[11px] txt-secondary leading-relaxed">
                    {{ t(`moderation.reasons.${reason}.hint`) }}
                  </p>
                </div>
              </label>
            </div>

            <!-- Optional details -->
            <div>
              <label class="text-xs font-medium txt-secondary mb-1 block">
                {{ t('moderation.report.detailsLabel') }}
              </label>
              <textarea
                v-model="details"
                rows="3"
                :maxlength="DETAILS_MAX"
                class="w-full px-3 py-2 rounded-lg surface-chip txt-primary placeholder:txt-secondary focus:outline-none focus:ring-2 focus:ring-red-500/30 resize-none text-sm"
                :placeholder="t('moderation.report.detailsPlaceholder')"
              />
            </div>

            <!-- Info box -->
            <div
              class="rounded-xl p-3.5 flex items-start gap-3 bg-red-500/5 border border-red-500/10"
            >
              <Icon icon="mdi:shield-alert-outline" class="w-5 h-5 shrink-0 mt-0.5 text-red-500" />
              <p class="text-xs txt-secondary leading-relaxed">
                {{ t('moderation.report.explanation') }}
              </p>
            </div>
          </div>

          <!-- Footer -->
          <div
            class="flex justify-end gap-2 p-4 sm:p-6 border-t border-light-border/10 dark:border-dark-border/10"
          >
            <button
              type="button"
              class="px-4 py-2 rounded-xl text-sm font-medium surface-chip txt-secondary hover:txt-primary transition-colors"
              @click="emit('close')"
            >
              {{ t('common.cancel') }}
            </button>
            <button
              type="button"
              class="px-4 py-2 rounded-xl text-sm font-medium flex items-center gap-2 bg-red-500 text-white hover:bg-red-600 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
              :disabled="!canSubmit"
              @click="handleSubmit"
            >
              <Icon
                :icon="isSubmitting ? 'mdi:loading' : 'mdi:flag'"
                :class="['w-4 h-4', isSubmitting ? 'animate-spin' : '']"
              />
              {{ t('moderation.report.submit') }}
            </button>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.2s ease;
}

.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}
</style>
