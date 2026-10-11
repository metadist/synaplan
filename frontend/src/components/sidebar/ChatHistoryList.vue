<template>
  <div v-if="chats.length > 0" :data-testid="rowsTestId">
    <div
      v-for="section in sections"
      :key="section.key"
      class="chat-history-section"
      data-testid="section-chat-history-group"
    >
      <p
        v-if="section.label"
        :id="`${groupIdPrefix}-${section.key}`"
        class="chat-group-label px-1.5 pb-1 text-[11px] font-semibold uppercase tracking-wide txt-secondary"
        data-testid="text-chat-history-group"
      >
        {{ section.label }}
      </p>
      <div
        class="space-y-0.5"
        role="list"
        :aria-labelledby="section.label ? `${groupIdPrefix}-${section.key}` : undefined"
      >
        <div
          v-for="chat in section.chats"
          :key="chat.id"
          role="listitem"
          class="group/chat relative overflow-hidden rounded-lg"
          :class="
            chat.id === activeChatId
              ? 'bg-[var(--brand)]/[0.12]'
              : 'hover:bg-black/[0.04] dark:hover:bg-white/[0.04]'
          "
          data-testid="row-chat-v2"
          @mouseenter="onRowEnter(chat, $event)"
          @mouseleave="onRowLeave"
          @focusin="onRowEnter(chat, $event)"
          @focusout="onRowFocusOut"
        >
          <button
            type="button"
            class="chat-row-btn flex w-full min-w-0 items-center gap-2 text-left rounded-xl cursor-pointer"
            :aria-describedby="previewChatId === chat.id ? PREVIEW_ID : undefined"
            @click="emit('select', chat.id)"
          >
            <span
              v-if="generating(chat)"
              class="inline-flex flex-shrink-0"
              data-testid="indicator-chat-active-run"
            >
              <span class="w-1.5 h-1.5 rounded-full bg-[var(--brand)] animate-pulse" />
              <span class="sr-only">{{ $t('chat.stillGenerating') }}</span>
            </span>
            <span
              v-else-if="answerReady?.(chat)"
              class="inline-flex flex-shrink-0"
              data-testid="indicator-chat-answer-ready"
            >
              <span
                class="w-1.5 h-1.5 rounded-full bg-[var(--status-success-text)] dark:bg-[var(--status-success)]"
              />
              <span class="sr-only">{{ $t('chat.answerReady') }}</span>
            </span>
            <span
              v-else-if="chat.isNew"
              class="inline-flex flex-shrink-0"
              data-testid="indicator-chat-incoming-new"
            >
              <span class="w-2 h-2 rounded-full bg-[var(--status-error)]" />
              <span class="sr-only">{{ $t('iam.incoming.new') }}</span>
            </span>
            <span
              class="chat-row-clip flex h-5 min-w-0 flex-1 items-center overflow-hidden"
              :class="{ 'is-reserved': !chat.incoming }"
            >
              <span
                :ref="bindTitle(chat.id)"
                class="block h-5 max-w-full truncate text-[15px] leading-5"
                :class="[
                  chat.id === activeChatId
                    ? 'font-medium text-[var(--brand)]'
                    : 'chat-row-title font-normal',
                  marqueeChatId === chat.id && 'chat-title-marquee',
                ]"
              >
                {{ titleOf(chat) }}
              </span>
            </span>
          </button>
          <p
            v-if="chat.tags && chat.tags.length > 0"
            class="flex min-w-0 flex-wrap gap-x-2 gap-y-0.5 px-1.5 pb-1"
            data-testid="list-chat-tags"
          >
            <span
              v-for="(tag, index) in chat.tags"
              :key="`${chat.id}-${index}`"
              class="max-w-full truncate text-[11px] leading-4 txt-secondary"
              data-testid="text-chat-tag"
            >
              {{ tag }}
            </span>
          </p>
          <div
            v-if="!chat.incoming"
            class="chat-row-menu absolute inset-y-0 right-0 flex items-stretch"
          >
            <button
              type="button"
              class="chat-row-menu-btn icon-ghost inline-flex h-full items-center justify-center px-1.5 cursor-pointer"
              data-testid="btn-chat-v2-row-pin"
              :aria-label="chat.pinned ? $t('chat.unpin') : $t('chat.pin')"
              :aria-pressed="chat.pinned === true"
              :aria-busy="chatsStore.pinPendingChatIds.has(chat.id)"
              :disabled="chatsStore.pinPendingChatIds.has(chat.id)"
              @click="emit('pin', chat.id)"
            >
              <Icon
                :icon="chat.pinned ? 'ph:push-pin-fill' : 'ph:push-pin'"
                class="w-4 h-4"
                aria-hidden="true"
              />
            </button>
            <button
              type="button"
              class="chat-row-menu-btn icon-ghost inline-flex h-full items-center justify-center px-1.5 cursor-pointer"
              data-testid="btn-chat-v2-row-menu"
              :aria-label="$t('common.actions')"
              @click="toggleMenu(chat.id, $event)"
            >
              <EllipsisHorizontalIcon class="w-5 h-5" aria-hidden="true" />
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
  <slot v-else name="empty" />

  <Teleport to="#app">
    <div
      v-if="previewChat"
      :id="PREVIEW_ID"
      role="tooltip"
      class="chat-row-preview dropdown-panel fixed z-[120] w-72 pointer-events-none"
      :style="previewStyle"
    >
      <div class="flex items-start gap-3">
        <p class="min-w-0 flex-1 text-[15px] font-medium txt-primary break-words">
          {{ titleOf(previewChat) }}
        </p>
        <p class="flex-shrink-0 text-[13px] txt-secondary tabular-nums pt-0.5">
          {{ timeOf(previewChat) }}
        </p>
      </div>
      <p class="mt-1.5 flex items-center gap-1.5 text-[13px] txt-secondary">
        <Icon :icon="originOf(previewChat).icon" class="w-4 h-4 flex-shrink-0" aria-hidden="true" />
        <span class="min-w-0 break-words">{{ originOf(previewChat).label }}</span>
      </p>
      <p
        v-if="previewChat.incoming && (previewChat.ownerName || previewChat.permission)"
        class="mt-1 text-[13px] txt-secondary break-words"
        data-testid="text-chat-preview-access"
      >
        {{ accessOf(previewChat) }}
      </p>
    </div>

    <Transition
      enter-active-class="transition ease-out duration-100"
      enter-from-class="opacity-0 scale-95"
      enter-to-class="opacity-100 scale-100"
      leave-active-class="transition ease-in duration-75"
      leave-from-class="opacity-100 scale-100"
      leave-to-class="opacity-0 scale-95"
    >
      <div v-if="menuChatId !== null" class="fixed inset-0 z-[150]" @click="menuChatId = null">
        <div class="fixed w-44 dropdown-panel origin-top-right" :style="menuStyle" @click.stop>
          <button
            v-if="shareAllowed"
            type="button"
            class="dropdown-item"
            data-testid="btn-chat-v2-share"
            @click="onShare"
          >
            <Icon icon="mdi:share-variant-outline" class="w-4 h-4" />
            {{ $t('common.share') }}
          </button>
          <button
            type="button"
            class="dropdown-item"
            data-testid="btn-chat-v2-rename"
            @click="onRename"
          >
            <Icon icon="mdi:pencil-outline" class="w-4 h-4" />
            {{ $t('common.rename') }}
          </button>
          <button
            type="button"
            class="dropdown-item"
            data-testid="btn-chat-v2-tags"
            @click="onTags"
          >
            <Icon icon="mdi:tag" class="w-4 h-4" />
            {{ $t('chat.tags') }}
          </button>
          <button
            type="button"
            class="dropdown-item"
            data-testid="btn-chat-v2-archive"
            @click="onArchive"
          >
            <Icon icon="mdi:archive-outline" class="w-4 h-4" />
            {{ menuChat?.archived ? $t('chat.unarchive') : $t('chat.archive') }}
          </button>
          <button
            v-if="exportAllowed"
            type="button"
            class="dropdown-item"
            data-testid="btn-chat-v2-export"
            @click="onExport"
          >
            <Icon icon="mdi:download-outline" class="w-4 h-4" />
            {{ $t('chat.exportMarkdown') }}
          </button>
          <button
            type="button"
            class="dropdown-item dropdown-item--danger"
            data-testid="btn-chat-v2-delete"
            @click="onDelete"
          >
            <Icon icon="mdi:delete-outline" class="w-4 h-4" />
            {{ $t('common.delete') }}
          </button>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<script setup lang="ts">
import { computed, nextTick, onUnmounted, ref, useId } from 'vue'
import { useI18n } from 'vue-i18n'
import { EllipsisHorizontalIcon } from '@heroicons/vue/24/outline'
import { Icon } from '@iconify/vue'
import { triggerHapticImpact } from '@/services/api/nativeHaptics'
import { canExportChats, canShareChats } from '@/composables/useChatWelcome'
import { useDialog } from '@/composables/useDialog'
import { useNotification } from '@/composables/useNotification'
import { useChatsStore } from '@/stores/chats'
import { parseTagLines } from '@/utils/chatTags'
import type { HistoryChat } from '@/composables/useChatHistory'

const PREVIEW_ID = 'chat-row-preview'
const PREVIEW_DELAY_MS = 400
const MARQUEE_DELAY_MS = 400
const MARQUEE_END_HOLD_MS = 350
const PREVIEW_WIDTH = 288
const PREVIEW_GAP = 8

const props = defineProps<{
  chats: HistoryChat[]
  activeChatId: number | null
  titleOf: (chat: HistoryChat) => string
  timeOf: (chat: HistoryChat) => string
  generating: (chat: HistoryChat) => boolean
  /** Background answer finished; the dot stays until the row is opened. */
  answerReady?: (chat: HistoryChat) => boolean
  listTestId?: string
  /** Label of the date group a row belongs to. Rows must arrive sorted, so groups stay contiguous. */
  groupOf?: (chat: HistoryChat) => string | null
}>()

const rowsTestId = computed(() => props.listTestId ?? 'list-chat-manager-rows')
const groupIdPrefix = `chat-group-${useId()}`

interface HistorySection {
  key: string
  label: string | null
  chats: HistoryChat[]
}

const sections = computed<HistorySection[]>(() => {
  const groupOf = props.groupOf
  if (!groupOf) return [{ key: 'all', label: null, chats: props.chats }]
  const out: HistorySection[] = []
  for (const chat of props.chats) {
    const label = groupOf(chat)
    const last = out[out.length - 1]
    if (last && last.label === label) {
      last.chats.push(chat)
    } else {
      out.push({ key: String(out.length), label, chats: [chat] })
    }
  }
  return out
})

const emit = defineEmits<{
  select: [chatId: number]
  share: [chatId: number]
  rename: [chatId: number]
  delete: [chatId: number]
  pin: [chatId: number]
  archive: [chatId: number]
  export: [chatId: number]
}>()

const { t } = useI18n()
const chatsStore = useChatsStore()
const shareAllowed = computed(() => canShareChats())
const exportAllowed = computed(() => canExportChats())

const menuChatId = ref<number | null>(null)
const menuStyle = ref<Record<string, string>>({})
const previewChatId = ref<number | null>(null)
const previewStyle = ref<Record<string, string>>({})
const marqueeChatId = ref<number | null>(null)

const titleEls = new Map<number, HTMLElement>()
let previewTimer = 0
let marqueeTimer = 0
let marqueeGen = 0
let marqueeAnim: Animation | null = null

const previewChat = computed(
  () => props.chats.find((chat) => chat.id === previewChatId.value) ?? null
)

const finePointer = (): boolean => window.matchMedia('(hover: hover) and (pointer: fine)').matches

const bindTitle = (id: number) => (el: unknown) => {
  if (el instanceof HTMLElement) titleEls.set(id, el)
  else titleEls.delete(id)
}

const originOf = (chat: HistoryChat): { label: string; icon: string } => {
  if (chat.incoming) {
    return {
      label: chat.kindLabel || t('chat.listOrigin.incoming'),
      icon: 'mdi:share-variant-outline',
    }
  }
  switch (chat.source) {
    case 'whatsapp':
      return { label: t('chat.listOrigin.whatsapp'), icon: 'mdi:whatsapp' }
    case 'telegram':
      return { label: t('chat.listOrigin.telegram'), icon: 'mdi:telegram' }
    case 'email':
      return { label: t('chat.listOrigin.email'), icon: 'mdi:email-outline' }
    case 'widget':
      return { label: t('chat.listOrigin.widget'), icon: 'mdi:widgets-outline' }
    case 'api':
      return { label: t('chat.listOrigin.api'), icon: 'mdi:console' }
    default:
      return { label: t('chat.listOrigin.web'), icon: 'mdi:chat-outline' }
  }
}

/** "from Anna · Can view": who owns an incoming chat and what I may do with it. */
const accessOf = (chat: HistoryChat): string =>
  [
    chat.ownerName ? t('iam.incoming.owner', { name: chat.ownerName }) : null,
    chat.permission ? t(`iam.permission.${chat.permission}`) : null,
  ]
    .filter((part): part is string => part !== null)
    .join(' · ')

const hidePreview = () => {
  window.clearTimeout(previewTimer)
  previewTimer = 0
  previewChatId.value = null
}

const stopMarqueeMotion = () => {
  marqueeAnim?.cancel()
  marqueeAnim = null
}

const hideMarquee = () => {
  window.clearTimeout(marqueeTimer)
  marqueeTimer = 0
  marqueeGen += 1
  stopMarqueeMotion()
  marqueeChatId.value = null
}

const DOTS_GAP_PX = 10

/** Keep the scrolled end of the title to the left of the action button. */
const widthUntilDots = (clip: HTMLElement, contentWidth: number): number => {
  const row = clip.closest('[data-testid="row-chat-v2"]')
  const dots =
    row?.querySelector('[data-testid="btn-chat-v2-row-pin"]') ??
    row?.querySelector('[data-testid="btn-chat-v2-row-menu"]')
  if (!(dots instanceof HTMLElement)) return contentWidth
  const clipStyle = getComputedStyle(clip)
  const contentLeft = clip.getBoundingClientRect().left + parseFloat(clipStyle.paddingLeft)
  const untilDots = dots.getBoundingClientRect().left - DOTS_GAP_PX - contentLeft
  if (untilDots < 8) return contentWidth
  return Math.min(contentWidth, untilDots)
}

const startMarquee = (id: number) => {
  if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return
  window.clearTimeout(marqueeTimer)
  const gen = ++marqueeGen
  marqueeTimer = window.setTimeout(() => {
    window.requestAnimationFrame(() => {
      if (gen !== marqueeGen) return
      const el = titleEls.get(id)
      const clip = el?.parentElement
      if (!el || !clip) return
      const clipStyle = getComputedStyle(clip)
      const contentWidth =
        clip.clientWidth - parseFloat(clipStyle.paddingLeft) - parseFloat(clipStyle.paddingRight)
      const visible = widthUntilDots(clip, contentWidth)
      const previous = el.style.maxWidth
      el.style.maxWidth = 'none'
      const full = el.scrollWidth
      el.style.maxWidth = previous
      const overflow = full - visible
      if (overflow < 8) {
        marqueeChatId.value = null
        return
      }
      const travelMs = (overflow / 18) * 1000
      marqueeChatId.value = id
      void nextTick(() => {
        if (gen !== marqueeGen) return
        const node = titleEls.get(id)
        if (!node) return
        stopMarqueeMotion()
        // Alternate replays the hold on the way back, so the visible pause is twice this.
        const total = travelMs + MARQUEE_END_HOLD_MS
        const arrive = travelMs / total
        marqueeAnim = node.animate(
          [
            { transform: 'translateX(0)', offset: 0 },
            { transform: `translateX(-${overflow}px)`, offset: arrive },
            { transform: `translateX(-${overflow}px)`, offset: 1 },
          ],
          {
            duration: total,
            iterations: Infinity,
            direction: 'alternate',
            easing: 'linear',
          }
        )
      })
    })
  }, MARQUEE_DELAY_MS)
}

const placePreview = (chat: HistoryChat, row: HTMLElement) => {
  if (menuChatId.value === chat.id) return
  const rect = row.getBoundingClientRect()
  let left = rect.right + PREVIEW_GAP
  if (left + PREVIEW_WIDTH > window.innerWidth - PREVIEW_GAP) {
    left = Math.max(PREVIEW_GAP, rect.left - PREVIEW_WIDTH - PREVIEW_GAP)
  }
  let top = rect.top
  const estimatedHeight = 96
  if (top + estimatedHeight > window.innerHeight - PREVIEW_GAP) {
    top = Math.max(PREVIEW_GAP, window.innerHeight - estimatedHeight - PREVIEW_GAP)
  }
  previewStyle.value = { top: `${top}px`, left: `${left}px` }
  previewChatId.value = chat.id
}

const onRowEnter = (chat: HistoryChat, event: MouseEvent | FocusEvent) => {
  if (!finePointer()) return
  const target = event.target
  if (target instanceof HTMLElement && target.closest('.chat-row-menu')) return
  const row = event.currentTarget
  if (!(row instanceof HTMLElement)) return
  hidePreview()
  previewTimer = window.setTimeout(() => placePreview(chat, row), PREVIEW_DELAY_MS)
  startMarquee(chat.id)
}

const onRowLeave = () => {
  hidePreview()
  hideMarquee()
}

const onRowFocusOut = (event: FocusEvent) => {
  const row = event.currentTarget
  const next = event.relatedTarget
  if (row instanceof HTMLElement && next instanceof Node && row.contains(next)) return
  onRowLeave()
}

const onScroll = () => {
  if (previewChatId.value !== null) hidePreview()
}

const onKeydown = (event: KeyboardEvent) => {
  if (event.key === 'Escape' && menuChatId.value !== null) menuChatId.value = null
}

window.addEventListener('scroll', onScroll, true)
document.addEventListener('keydown', onKeydown)

const toggleMenu = (chatId: number, event: MouseEvent) => {
  triggerHapticImpact('light')
  hidePreview()
  if (menuChatId.value === chatId) {
    menuChatId.value = null
    return
  }
  const btn = (event.currentTarget ?? event.target) as HTMLElement | null
  if (!(btn instanceof HTMLElement)) return
  const rect = btn.getBoundingClientRect()
  const menuHeight = 176
  const menuWidth = 176
  const spaceBelow = window.innerHeight - rect.bottom
  const top = spaceBelow < menuHeight ? rect.top - menuHeight : rect.bottom + 4
  const left = Math.max(8, rect.right - menuWidth)
  menuStyle.value = { top: `${top}px`, left: `${left}px` }
  menuChatId.value = chatId
}

const takeMenuId = (): number | null => {
  const id = menuChatId.value
  menuChatId.value = null
  return id
}

const onShare = () => {
  const id = takeMenuId()
  if (id !== null) emit('share', id)
}

const onRename = () => {
  const id = takeMenuId()
  if (id !== null) emit('rename', id)
}

const dialog = useDialog()
const { success, error: notifyError } = useNotification()

const onTags = async () => {
  const id = takeMenuId()
  if (id === null) return
  const chat = props.chats.find((row) => row.id === id)
  const edited = await dialog.prompt({
    title: t('chat.tags'),
    message: t('chat.tagsHint'),
    defaultValue: (chat?.tags ?? []).join('\n'),
    confirmText: t('common.save'),
    cancelText: t('common.cancel'),
    multiline: true,
  })
  if (edited === null) return
  try {
    await chatsStore.updateChatTags(id, parseTagLines(edited))
    success(t('chat.tagsSaved'))
  } catch (err: unknown) {
    console.error('Saving chat tags failed', err)
    notifyError(t('chat.tagsSaveFailed'))
  }
}

const onDelete = () => {
  const id = takeMenuId()
  if (id !== null) emit('delete', id)
}

const menuChat = computed(() => props.chats.find((chat) => chat.id === menuChatId.value) ?? null)

const onArchive = () => {
  const id = takeMenuId()
  if (id !== null) emit('archive', id)
}

const onExport = () => {
  const id = takeMenuId()
  if (id !== null) emit('export', id)
}

onUnmounted(() => {
  hidePreview()
  hideMarquee()
  window.removeEventListener('scroll', onScroll, true)
  document.removeEventListener('keydown', onKeydown)
})
</script>

<style scoped>
.chat-row-btn {
  padding: 0.3125rem 0.375rem;
}

/* History sits one step below the menu: smaller, regular weight, and a
   softer ink than the menu's primary text. Both stay above WCAG AA on the
   list column (light #d0daea ≈ 7:1, dark #070b15 ≈ 13:1). */
.chat-row-title {
  color: #3b4353;
}

.dark .chat-row-title {
  color: #cdd1da;
}

.chat-row-clip.is-reserved {
  padding-right: 4rem;
}

/* Date groups break a long history into short runs, which reads calmer
   than one undivided list. */
.chat-history-section + .chat-history-section {
  margin-top: 0.875rem;
}

.chat-row-menu {
  /* Short fade, then a plate between the list column and the row hover tint.
     Light: column #d0daea, hover is that color with 4% black (#c8d1e1).
     Dark: column #070b15, hover is that color with 4% white (#11151e). */
  --chat-row-plate: #ccd6e5;
  padding-left: 0.5rem;
  background: linear-gradient(to right, transparent, var(--chat-row-plate) 0.5rem);
  opacity: 1;
  pointer-events: none;
}

.dark .chat-row-menu {
  --chat-row-plate: #0c101a;
}

.chat-row-menu-btn.icon-ghost {
  background: transparent;
  box-shadow: none;
  border-radius: 0;
  color: var(--txt-secondary);
  opacity: 0.72;
  cursor: pointer;
  pointer-events: auto;
  transition:
    color 0.15s ease,
    opacity 0.15s ease;
}

.chat-row-menu-btn.icon-ghost:hover,
.chat-row-menu-btn.icon-ghost:focus-visible {
  background: transparent;
  box-shadow: none;
  color: var(--txt-primary);
  opacity: 1;
  cursor: pointer;
}

.chat-row-menu-btn.icon-ghost:focus-visible {
  outline: 2px solid var(--brand);
  outline-offset: -2px;
}

.chat-row-menu-btn.icon-ghost:disabled {
  opacity: 0.4;
  cursor: progress;
}

@media (hover: hover) and (pointer: fine) {
  .chat-row-clip.is-reserved {
    padding-right: 0;
  }

  .group\/chat:hover .chat-row-clip.is-reserved,
  .group\/chat:focus-within .chat-row-clip.is-reserved {
    padding-right: 4rem;
  }

  .chat-row-menu {
    opacity: 0;
  }

  .chat-row-menu-btn.icon-ghost {
    pointer-events: none;
  }

  .group\/chat:hover .chat-row-menu,
  .group\/chat:focus-within .chat-row-menu {
    opacity: 1;
  }

  .group\/chat:hover .chat-row-menu-btn.icon-ghost,
  .group\/chat:focus-within .chat-row-menu-btn.icon-ghost {
    pointer-events: auto;
  }
}

.chat-title-marquee {
  max-width: none;
  width: max-content;
  overflow: visible;
  text-overflow: clip;
}
</style>
