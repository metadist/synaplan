<template>
  <div v-if="showFalsePositive || showReport" class="relative">
    <button
      type="button"
      :class="['pill text-xs', open && 'pill--active']"
      :aria-label="t('chatMessage.moreActions')"
      :aria-expanded="open"
      aria-haspopup="menu"
      data-testid="btn-message-more"
      @click.stop="open = !open"
      @keydown.escape="open = false"
    >
      <EllipsisHorizontalIcon class="w-4 h-4" aria-hidden="true" />
    </button>
    <div
      v-if="open"
      v-click-outside="close"
      class="dropdown-up right-0 w-52 max-w-[calc(100vw-1rem)]"
      role="menu"
      data-testid="dropdown-message-more"
      @keydown.escape="close"
    >
      <button
        v-if="showFalsePositive"
        type="button"
        class="dropdown-item w-full disabled:opacity-50 disabled:cursor-not-allowed"
        role="menuitem"
        :disabled="falsePositiveDisabled"
        :aria-label="t('feedback.falsePositive.button')"
        data-testid="btn-message-false-positive"
        @click="chooseFalsePositive"
      >
        <HandThumbDownIcon class="w-4 h-4 flex-shrink-0" aria-hidden="true" />
        <span class="flex-1 font-medium">{{ t('feedback.falsePositive.button') }}</span>
        <LockClosedIcon
          v-if="guest"
          class="w-3 h-3 flex-shrink-0 text-amber-500"
          aria-hidden="true"
        />
      </button>
      <button
        v-if="showReport"
        type="button"
        class="dropdown-item w-full"
        role="menuitem"
        :aria-label="t('moderation.report.button')"
        data-testid="btn-message-report"
        @click="chooseReport"
      >
        <FlagIcon class="w-4 h-4 flex-shrink-0" aria-hidden="true" />
        <span class="font-medium">{{ t('moderation.report.button') }}</span>
      </button>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { EllipsisHorizontalIcon, FlagIcon, HandThumbDownIcon, LockClosedIcon } from '@heroicons/vue/24/outline'

/**
 * "Not correct" and "Report" live behind one control so the message footer
 * stays one row once Look deeper sits beside Again.
 */
defineProps<{
  showFalsePositive: boolean
  showReport: boolean
  falsePositiveDisabled: boolean
  guest?: boolean
}>()

const emit = defineEmits<{
  'false-positive': []
  report: []
}>()

const { t } = useI18n()
const open = ref(false)

const close = () => {
  open.value = false
}

const chooseFalsePositive = () => {
  close()
  emit('false-positive')
}

const chooseReport = () => {
  close()
  emit('report')
}
</script>
