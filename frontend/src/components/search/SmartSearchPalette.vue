<template>
  <Teleport :to="teleportTarget">
    <Transition name="smart-search-fade">
      <div
        v-if="store.isOpen"
        class="fixed inset-0 z-[9000] flex items-start justify-center sm:pt-[10vh] sm:px-4"
        data-testid="modal-smart-search"
      >
        <div
          class="absolute inset-0 bg-black/50 dark:bg-black/70 backdrop-blur-sm"
          aria-hidden="true"
          @mousedown="store.close()"
        />
        <div
          role="dialog"
          aria-modal="true"
          :aria-label="$t('search.palette.label')"
          class="relative surface-card w-full h-full sm:h-auto sm:max-h-[72vh] sm:max-w-2xl sm:rounded-xl shadow-2xl flex flex-col overflow-hidden"
          data-testid="panel-smart-search"
        >
          <div
            class="flex items-center gap-3 px-4 border-b border-light-border/30 dark:border-dark-border/20 pt-[env(safe-area-inset-top)] sm:pt-0"
          >
            <MagnifyingGlassIcon class="w-5 h-5 flex-shrink-0 txt-secondary" aria-hidden="true" />
            <input
              ref="inputRef"
              v-model="query"
              type="text"
              role="combobox"
              aria-autocomplete="list"
              aria-expanded="true"
              :aria-controls="LIST_ID"
              :aria-activedescendant="activeOptionId"
              :aria-label="$t('search.palette.label')"
              :placeholder="$t('search.palette.placeholder')"
              autocomplete="off"
              spellcheck="false"
              class="flex-1 min-w-0 py-4 bg-transparent txt-primary text-base focus:outline-none"
              data-testid="input-smart-search"
              @keydown="onKeydown"
            />
            <button
              type="button"
              class="sm:hidden p-2 rounded-lg txt-secondary hover:bg-black/5 dark:hover:bg-white/5"
              :aria-label="$t('common.close')"
              data-testid="btn-smart-search-close"
              @click="store.close()"
            >
              <XMarkIcon class="w-5 h-5" aria-hidden="true" />
            </button>
            <kbd
              class="hidden sm:inline text-[10px] px-1.5 py-0.5 rounded surface-chip txt-secondary"
              >Esc</kbd
            >
          </div>

          <div
            :id="LIST_ID"
            ref="listRef"
            role="listbox"
            :aria-label="$t('search.palette.label')"
            class="flex-1 min-h-0 overflow-y-auto p-2"
            data-testid="list-smart-search"
          >
            <p
              v-if="statusText"
              class="px-3 py-2 text-xs txt-secondary"
              role="status"
              data-testid="text-smart-search-status"
            >
              {{ statusText }}
            </p>
            <p
              v-if="remoteNote"
              class="px-3 py-2 text-xs txt-secondary"
              role="status"
              data-testid="text-smart-search-remote-note"
            >
              {{ remoteNote }}
            </p>
            <div
              v-for="group in groups"
              :key="group.key"
              role="group"
              :aria-label="group.label"
              :data-testid="`group-smart-search-${group.key}`"
            >
              <div class="px-3 pt-3 pb-1 text-xs font-semibold txt-secondary">
                {{ group.label }}
              </div>
              <SearchResultRow
                v-for="item in group.items"
                :key="item.id"
                :result="item"
                :active="flatIndex(item) === activeIndex"
                :option-id="optionId(flatIndex(item))"
                @select="(event) => select(item, event.ctrlKey || event.metaKey)"
                @hover="activeIndex = flatIndex(item)"
              >
                <template v-if="item.setting" #trailing>
                  <SearchSettingControl
                    :control="item.setting"
                    :value="inline.valueOf(item.setting)"
                    :value-label="inline.valueLabel(item.setting, inline.valueOf(item.setting))"
                    :name="inline.nameOf(item.setting.key)"
                    :saving="inline.savingKey.value === item.setting.key"
                    :option-label="inline.optionLabel"
                    @change="(value) => item.setting && inline.apply(item.setting, value)"
                  />
                </template>
              </SearchResultRow>
            </div>
          </div>

          <SearchPaletteFooter :can-switch="activeSetting !== null" />
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { MagnifyingGlassIcon, XMarkIcon } from '@heroicons/vue/24/outline'
import { useSmartSearchStore } from '@/stores/smartSearch'
import { useAuthStore } from '@/stores/auth'
import { useFullscreenTeleportTarget } from '@/composables/useFullscreenTeleportTarget'
import { useSmartSearch } from '@/composables/search/useSmartSearch'
import type { SearchResult } from '@/composables/search/types'
import { useInlineSetting } from '@/composables/search/useInlineSetting'
import { usePaletteStatus } from '@/composables/search/usePaletteStatus'
import { usePaletteFocus } from '@/composables/search/usePaletteFocus'
import SearchResultRow from './SearchResultRow.vue'
import SearchSettingControl from './SearchSettingControl.vue'
import SearchPaletteFooter from './SearchPaletteFooter.vue'

const LIST_ID = 'smart-search-listbox'

const store = useSmartSearchStore()
const authStore = useAuthStore()
const route = useRoute()
const { teleportTarget } = useFullscreenTeleportTarget()
const isOpen = computed(() => store.isOpen)
const {
  query,
  parsed,
  groups,
  flatResults,
  hasMatches,
  execute,
  remoteStatus,
  semanticAvailable,
  indexing,
} = useSmartSearch(isOpen, () => store.close())

const inline = useInlineSetting()

const inputRef = ref<HTMLInputElement | null>(null)
const listRef = ref<HTMLElement | null>(null)
const activeIndex = ref(0)
const activeSetting = computed(() => {
  const setting = flatResults.value[activeIndex.value]?.setting
  return setting && !setting.envPinned ? setting : null
})

const indexById = computed(
  () => new Map(flatResults.value.map((result, index) => [result.id, index]))
)
const flatIndex = (result: SearchResult) => indexById.value.get(result.id) ?? -1
const optionId = (index: number) => `smart-search-option-${index}`
const activeOptionId = computed(() =>
  flatResults.value.length > 0 ? optionId(activeIndex.value) : undefined
)

const { statusText, remoteNote } = usePaletteStatus({
  parsed,
  hasMatches,
  remoteStatus,
  semanticAvailable,
  indexing,
})

// Late server results must not move the keyboard selection off the item
// the user already arrowed to; a new query starts at the top again.
watch(flatResults, (next, previous) => {
  const activeId = previous?.[activeIndex.value]?.id
  const kept = activeIndex.value > 0 && activeId ? next.findIndex((r) => r.id === activeId) : -1
  activeIndex.value = kept >= 0 ? kept : 0
})

watch(query, () => {
  activeIndex.value = 0
})

watch(activeIndex, async (index) => {
  await nextTick()
  listRef.value?.querySelector(`#${optionId(index)}`)?.scrollIntoView({ block: 'nearest' })
})

usePaletteFocus(isOpen, inputRef, () => {
  query.value = store.initialQuery
  activeIndex.value = 0
})

watch(
  () => route.fullPath,
  () => store.close()
)

const select = (result: SearchResult, newTab = false) => {
  void execute(result, newTab)
}

const move = (delta: number) => {
  const total = flatResults.value.length
  if (total === 0) return
  activeIndex.value = (activeIndex.value + delta + total) % total
}

const onKeydown = (event: KeyboardEvent) => {
  switch (event.key) {
    case 'ArrowDown':
      event.preventDefault()
      move(1)
      break
    case 'ArrowUp':
      event.preventDefault()
      move(-1)
      break
    case 'Home':
      if (flatResults.value.length > 0 && query.value === '') {
        event.preventDefault()
        activeIndex.value = 0
      }
      break
    case 'End':
      if (flatResults.value.length > 0 && query.value === '') {
        event.preventDefault()
        activeIndex.value = flatResults.value.length - 1
      }
      break
    case 'Enter': {
      event.preventDefault()
      if (event.shiftKey) {
        if (activeSetting.value) void inline.cycle(activeSetting.value)
        break
      }
      const result = flatResults.value[activeIndex.value]
      if (result) select(result, event.ctrlKey || event.metaKey)
      break
    }
    case 'Escape':
      event.preventDefault()
      store.close()
      break
    case 'Tab':
      event.preventDefault()
      break
  }
}

const canOpen = () => authStore.isAuthenticated && route.meta.public !== true

const onGlobalKeydown = (event: KeyboardEvent) => {
  if (event.key.toLowerCase() !== 'k' || event.altKey || event.shiftKey) return
  if (!(event.metaKey || event.ctrlKey)) return
  if (!store.isOpen && !canOpen()) return
  event.preventDefault()
  store.toggle()
}

// Capture phase: inputs that stop propagation (composer palettes, editors)
// must not swallow the global shortcut.
onMounted(() => window.addEventListener('keydown', onGlobalKeydown, { capture: true }))
onBeforeUnmount(() => window.removeEventListener('keydown', onGlobalKeydown, { capture: true }))
</script>

<style scoped>
.smart-search-fade-enter-active,
.smart-search-fade-leave-active {
  transition: opacity 0.15s ease;
}

.smart-search-fade-enter-from,
.smart-search-fade-leave-to {
  opacity: 0;
}
</style>
