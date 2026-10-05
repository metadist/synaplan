<template>
  <div
    v-if="chats.length > 0"
    class="space-y-0.5"
    role="list"
    :data-testid="rowsTestId"
  >
    <div
      v-for="chat in chats"
      :key="chat.id"
      role="listitem"
      class="group/chat relative overflow-hidden rounded-lg"
      :class="
        chat.id === activeChatId
          ? 'bg-[var(--brand)]/[0.08]'
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
          class="chat-row-clip flex h-5 min-w-0 flex-1 items-center overflow-hidden"
          :class="{ 'is-reserved': !chat.incoming }"
        >
          <span
            :ref="bindTitle(chat.id)"
            class="block h-5 max-w-full truncate text-[15px] leading-5"
            :class="[
              chat.id === activeChatId ? 'font-semibold text-[var(--brand)]' : 'chat-row-title font-medium',
              marqueeChatId === chat.id && 'chat-title-marquee',
            ]"
          >
            {{ titleOf(chat) }}
          </span>
        </span>
      </button>
      <div v-if="!chat.incoming" class="chat-row-menu absolute inset-y-0 right-0 flex items-stretch">
        <button
          type="button"
          class="chat-row-menu-btn icon-ghost inline-flex h-full items-center justify-center px-1.5 cursor-pointer"
          data-testid="btn-chat-v2-row-pin"
          :aria-label="chat.pinned ? $t('chat.unpin') : $t('chat.pin')"
          :aria-pressed="chat.pinned === true"
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
import { computed, nextTick, onUnmounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { EllipsisHorizontalIcon } from '@heroicons/vue/24/outline'
import { Icon } from '@iconify/vue'
import { triggerHapticImpact } from '@/services/api/nativeHaptics'
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
  listTestId?: string
}>()

const rowsTestId = computed(() => props.listTestId ?? 'list-chat-manager-rows')

const emit = defineEmits<{
  select: [chatId: number]
  share: [chatId: number]
  rename: [chatId: number]
  delete: [chatId: number]
  pin: [chatId: number]
}>()

const { t } = useI18n()

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

const finePointer = (): boolean =>
  window.matchMedia('(hover: hover) and (pointer: fine)').matches

const bindTitle = (id: number) => (el: Element | null) => {
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
        clip.clientWidth -
        parseFloat(clipStyle.paddingLeft) -
        parseFloat(clipStyle.paddingRight)
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
          },
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

window.addEventListener('scroll', onScroll, true)

const toggleMenu = (chatId: number, event: MouseEvent) => {
  triggerHapticImpact('light')
  hidePreview()
  if (menuChatId.value === chatId) {
    menuChatId.value = null
    return
  }
  const btn = event.currentTarget as HTMLElement
  const rect = btn.getBoundingClientRect()
  const menuHeight = 140
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

const onDelete = () => {
  const id = takeMenuId()
  if (id !== null) emit('delete', id)
}

onUnmounted(() => {
  hidePreview()
  hideMarquee()
  window.removeEventListener('scroll', onScroll, true)
})
</script>

<style scoped>
.chat-row-btn {
  padding: 0.375rem;
}

.chat-row-title {
  color: #1c212b;
}

.dark .chat-row-title {
  color: #e3e6ee;
}

.chat-row-clip.is-reserved {
  padding-right: 4rem;
}

.chat-row-menu {
  /* Short fade, then a plate between the sidebar and the row hover tint.
     Light: sidebar #d0daea, hover is that color with 4% black (#c8d1e1).
     Dark: sidebar #070b15, hover is that color with 4% white (#11151e). */
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
