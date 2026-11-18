<template>
  <div v-if="offered" ref="root" class="relative" data-testid="section-research-download">
    <button
      ref="trigger"
      type="button"
      :class="[
        'pill text-xs disabled:opacity-50 disabled:cursor-not-allowed',
        open && 'pill--active',
      ]"
      :disabled="busy"
      :aria-busy="busy"
      :aria-label="t('research.download.menuLabel')"
      :aria-expanded="open"
      aria-haspopup="menu"
      data-testid="btn-research-download"
      @click.stop="open = !open"
    >
      <ResearchSpinner v-if="busy" class="w-4 h-4" />
      <ArrowDownTrayIcon v-else class="w-4 h-4" aria-hidden="true" />
      <span class="font-medium hidden sm:inline">{{ t('research.download.button') }}</span>
    </button>
    <div
      v-if="open"
      class="dropdown-up right-0 w-56 max-w-[calc(100vw-1rem)]"
      role="menu"
      data-testid="dropdown-research-download"
    >
      <button
        v-for="format in REPLY_EXPORT_FORMATS"
        :key="format"
        type="button"
        class="dropdown-item w-full"
        role="menuitem"
        :data-testid="`btn-research-download-${format}`"
        @click="download(format)"
      >
        <component :is="ICONS[format]" class="w-4 h-4 flex-shrink-0" aria-hidden="true" />
        <span class="font-medium">{{ t(`research.download.${format}`) }}</span>
      </button>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, nextTick, onUnmounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  ArrowDownTrayIcon,
  CodeBracketIcon,
  DocumentTextIcon,
  GlobeAltIcon,
} from '@heroicons/vue/24/outline'
import ResearchSpinner from '@/components/research/ResearchSpinner.vue'
import { canExportChats } from '@/composables/useChatWelcome'
import { useNotification } from '@/composables/useNotification'
import { useResearchTurn } from '@/composables/useResearchTurn'
import {
  REPLY_EXPORT_FORMATS,
  downloadReplyExport,
  type ReplyExportFormat,
} from '@/services/api/replyExportApi'

/**
 * In the footer of a finished research reply: one control that saves the
 * reply as a Word document, a web page or Markdown. Absent for guests,
 * while the research still runs, and when chat export is turned off.
 */
const props = defineProps<{
  messageId: number
  runId: string | null | undefined
  guest?: boolean
}>()

const ICONS = {
  docx: DocumentTextIcon,
  html: GlobeAltIcon,
  md: CodeBracketIcon,
} as const

const { t } = useI18n()
const notification = useNotification()
const { running } = useResearchTurn(() => props.runId)

const open = ref(false)
const busy = ref(false)
const root = ref<HTMLElement | null>(null)
const trigger = ref<HTMLButtonElement | null>(null)

const offered = computed(() => !!props.runId && !props.guest && !running.value && canExportChats())

const close = () => {
  open.value = false
}

const download = async (format: ReplyExportFormat) => {
  close()
  if (busy.value) return
  busy.value = true
  try {
    await downloadReplyExport(props.messageId, format)
  } catch {
    notification.error(t('research.download.failed'))
  } finally {
    busy.value = false
    await nextTick()
    trigger.value?.focus()
  }
}

const onPointerDown = (event: Event) => {
  const target = event.target
  if (target instanceof Node && root.value?.contains(target)) return
  close()
}

const onKeyDown = (event: KeyboardEvent) => {
  if (event.key !== 'Escape' || !open.value) return
  event.stopPropagation()
  close()
  trigger.value?.focus()
}

const detach = () => {
  document.removeEventListener('pointerdown', onPointerDown)
  document.removeEventListener('keydown', onKeyDown, true)
}

watch(open, (isOpen) => {
  if (!isOpen) {
    detach()
    return
  }
  document.addEventListener('pointerdown', onPointerDown)
  document.addEventListener('keydown', onKeyDown, true)
})

onUnmounted(detach)
</script>
