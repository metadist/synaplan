<template>
  <div v-if="targets.length > 0" class="relative" data-testid="file-push-menu">
    <button
      ref="triggerRef"
      type="button"
      :class="triggerClass"
      :title="$t('files.push.action')"
      :aria-label="$t('files.push.action')"
      :aria-expanded="open"
      :disabled="busy"
      data-testid="btn-file-push"
      @click.stop="toggle"
    >
      <Icon
        :icon="busy ? 'mdi:loading' : 'mdi:cloud-upload-outline'"
        :class="[iconClass, busy && 'animate-spin']"
      />
    </button>
    <Teleport to="body">
      <div
        v-if="open"
        ref="menuRef"
        class="fixed z-[200] overflow-y-auto scroll-thin surface-card rounded-xl border border-light-border/30 dark:border-dark-border/20 shadow-xl py-1.5"
        :style="menuStyle"
        data-testid="file-push-menu-panel"
        @click.stop
      >
        <div class="px-3 py-1.5 text-[10px] font-semibold uppercase tracking-wider txt-secondary">
          {{ $t('files.push.action') }}
        </div>
        <button
          v-for="target in targets"
          :key="target.id"
          type="button"
          class="w-full flex items-start gap-2 px-3 py-2 text-xs txt-primary hover:bg-[var(--brand)]/10 transition-colors text-left disabled:opacity-50"
          :data-testid="`btn-file-push-target-${target.id}`"
          :disabled="busy"
          @click="onSelect(target)"
        >
          <Icon icon="mdi:cloud-outline" class="w-4 h-4 shrink-0 mt-0.5" />
          <span class="min-w-0">
            <span class="block truncate">{{ $t('files.push.to', { name: target.name }) }}</span>
            <span class="block txt-secondary">{{ $t(`files.push.kind.${target.kind}`) }}</span>
          </span>
        </button>
      </div>
    </Teleport>
  </div>
</template>

<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { Icon } from '@iconify/vue'
import {
  claimOfficeActionsMenu,
  releaseOfficeActionsMenu,
} from '@/components/files/officeActionsMenuExclusive'
import {
  MENU_WIDTH_PX,
  parseCssPx,
  placeOfficeActionsMenu,
} from '@/components/files/officeActionsMenuPlacement'
import { useNotification } from '@/composables/useNotification'
import {
  pushFailureCopy,
  pushGeneratedFile,
  pushSuccessFolder,
  pushWorkspaceFile,
} from '@/services/cloudFolderPushService'
import type { CloudFolderTargetItem } from '@/services/cloudFolderTargets'

const props = withDefaults(
  defineProps<{
    targets: CloudFolderTargetItem[]
    fileName: string
    size?: 'row' | 'tile'
    source: 'workspace' | 'generated'
    path?: string
    fileId?: number
  }>(),
  { size: 'row', path: '', fileId: 0 }
)

const { t, te } = useI18n()
const { success, error: notifyError } = useNotification()

const open = ref(false)
const busy = ref(false)
const triggerRef = ref<HTMLElement | null>(null)
const menuRef = ref<HTMLElement | null>(null)
const menuStyle = ref<Record<string, string>>({})

const triggerClass = computed(() =>
  props.size === 'tile'
    ? 'shrink-0 px-2 py-1 rounded-md border border-light-border/30 dark:border-dark-border/10 txt-secondary hover:txt-primary transition-colors text-[11px] flex items-center justify-center disabled:opacity-50 disabled:cursor-not-allowed'
    : 'icon-ghost inline-flex items-center justify-center w-11 h-11 rounded-lg disabled:opacity-50 disabled:cursor-not-allowed'
)
const iconClass = computed(() => (props.size === 'tile' ? 'w-3.5 h-3.5' : 'w-5 h-5'))

const keyboardInsetPx = (): number =>
  parseCssPx(getComputedStyle(document.documentElement).getPropertyValue('--keyboard-inset-height'))

const updateMenuPosition = () => {
  if (!triggerRef.value) return
  const rect = triggerRef.value.getBoundingClientRect()
  const vv = window.visualViewport
  const placed = placeOfficeActionsMenu({
    trigger: { top: rect.top, bottom: rect.bottom, right: rect.right },
    menuHeight: menuRef.value?.offsetHeight ?? 0,
    innerWidth: window.innerWidth,
    innerHeight: window.innerHeight,
    keyboardInsetPx: keyboardInsetPx(),
    visualViewport: vv ? { offsetTop: vv.offsetTop, height: vv.height } : null,
    preferBelow: true,
  })
  menuStyle.value = {
    left: `${placed.left}px`,
    width: `${MENU_WIDTH_PX}px`,
    maxHeight: `${placed.maxHeight}px`,
    top: placed.top,
    bottom: placed.bottom,
  }
}

const close = () => {
  if (!open.value) return
  open.value = false
  releaseOfficeActionsMenu(close)
}

const toggle = () => {
  if (busy.value) return
  if (open.value) {
    close()
    return
  }
  claimOfficeActionsMenu(close)
  open.value = true
  nextTick(() => {
    updateMenuPosition()
    nextTick(updateMenuPosition)
  })
}

const onSelect = async (target: CloudFolderTargetItem) => {
  close()
  if (busy.value) return
  busy.value = true
  try {
    const result =
      props.source === 'workspace'
        ? await pushWorkspaceFile(props.path ?? '', target.id)
        : await pushGeneratedFile(props.fileId ?? 0, target.id)
    success(
      t('files.push.done', {
        folder: pushSuccessFolder(result.reference, target, props.fileName),
        name: target.name,
      })
    )
  } catch (err) {
    notifyError(pushFailureCopy(err, t, te))
  } finally {
    busy.value = false
  }
}

const onDocClick = (event: MouseEvent) => {
  if (!open.value) return
  const node = event.target as Node
  if (triggerRef.value?.contains(node) || menuRef.value?.contains(node)) return
  close()
}

const onKeydown = (event: KeyboardEvent) => {
  if (event.key === 'Escape' && open.value) close()
}

const onReposition = () => {
  if (open.value) updateMenuPosition()
}

onMounted(() => {
  document.addEventListener('click', onDocClick)
  document.addEventListener('keydown', onKeydown)
  window.addEventListener('scroll', onReposition, true)
  window.addEventListener('resize', onReposition)
  window.addEventListener('synaplan:keyboardinset', onReposition)
  window.visualViewport?.addEventListener('resize', onReposition)
  window.visualViewport?.addEventListener('scroll', onReposition)
})

onUnmounted(() => {
  close()
  document.removeEventListener('click', onDocClick)
  document.removeEventListener('keydown', onKeydown)
  window.removeEventListener('scroll', onReposition, true)
  window.removeEventListener('resize', onReposition)
  window.removeEventListener('synaplan:keyboardinset', onReposition)
  window.visualViewport?.removeEventListener('resize', onReposition)
  window.visualViewport?.removeEventListener('scroll', onReposition)
})
</script>
