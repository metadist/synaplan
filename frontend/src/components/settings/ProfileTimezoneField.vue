<template>
  <fieldset :disabled="!profileLoaded" class="border-0 p-0 m-0 min-w-0 disabled:opacity-60">
    <section class="surface-card rounded-lg p-6" data-testid="section-account-settings">
      <div ref="root" data-testid="field-timezone" @focusout="onFocusOut">
        <label class="block txt-primary font-medium mb-2" for="profile-timezone-search">
          {{ $t('profile.accountSettings.timezone') }}
        </label>
        <p class="txt-secondary text-sm mb-2">
          {{ $t('profile.accountSettings.timezoneHint') }}
        </p>
        <p class="txt-primary text-sm mb-2" data-testid="timezone-current">
          {{
            currentLabel
              ? $t('profile.accountSettings.timezoneCurrent', { label: currentLabel })
              : $t('profile.accountSettings.timezonePlaceholder')
          }}
        </p>
        <input
          id="profile-timezone-search"
          v-model="query"
          type="text"
          autocomplete="off"
          spellcheck="false"
          role="combobox"
          aria-autocomplete="list"
          aria-haspopup="listbox"
          :aria-expanded="listOpen"
          :aria-controls="listOpen ? listId : undefined"
          :aria-activedescendant="activeOptionId"
          class="w-full px-4 py-2.5 rounded-xl surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
          :placeholder="$t('profile.accountSettings.timezoneSearch')"
          :aria-label="$t('profile.accountSettings.timezoneSearch')"
          data-testid="input-timezone-search"
          @focus="openList"
          @input="openList"
          @keydown.enter.prevent="chooseHighlighted"
          @keydown.down.prevent="moveActive(1)"
          @keydown.up.prevent="moveActive(-1)"
          @keydown.escape.prevent="closeList"
        />
        <p
          v-if="query.trim() && matched.length > 0"
          class="txt-secondary text-sm mt-2"
          aria-live="polite"
          data-testid="timezone-match-count"
        >
          {{ $t('profile.accountSettings.timezoneMatchCount', { count: matched.length }) }}
        </p>
        <div
          v-if="listOpen"
          :id="listId"
          class="dropdown-panel mt-2 max-h-72 overflow-y-auto"
          role="listbox"
          :aria-label="$t('profile.accountSettings.timezone')"
          data-testid="list-timezone-options"
        >
          <p
            v-if="searchMiss"
            class="px-3 py-4 text-sm txt-secondary"
            role="status"
            data-testid="timezone-no-match"
          >
            {{ $t('profile.accountSettings.timezoneNoMatch') }}
          </p>
          <template v-for="group in groups" :key="group.offset">
            <p class="px-3 pt-2 text-xs font-medium txt-secondary">{{ group.offset }}</p>
            <button
              v-for="tz in group.zones"
              :id="optionId(tz.value)"
              :key="tz.value"
              type="button"
              role="option"
              class="dropdown-item"
              :class="tz.value === highlighted?.value ? 'dropdown-item--active' : ''"
              :aria-selected="tz.value === formData.timezone"
              :data-zone="tz.value"
              @mouseenter="activeIndex = indexOf(tz.value)"
              @click="choose(tz.value)"
            >
              <span class="min-w-0 flex-1 truncate">{{ tz.label }}</span>
              <CheckIcon
                v-if="tz.value === formData.timezone"
                class="h-4 w-4 flex-shrink-0 text-[var(--brand)]"
                aria-hidden="true"
              />
            </button>
          </template>
        </div>
      </div>
    </section>
  </fieldset>
</template>

<script setup lang="ts">
import { computed, nextTick, ref, useId, watch } from 'vue'
import { CheckIcon } from '@heroicons/vue/24/outline'
import { injectProfileSettings } from '@/composables/useProfileSettings'
import { filterTimezones, groupTimezones, listTimezones } from '@/utils/timezones'

const { formData, profileLoaded } = injectProfileSettings()

const root = ref<HTMLElement | null>(null)
const query = ref('')
const listOpen = ref(false)
/** Enter may choose a row only after a search or arrow move, never on a bare press. */
const choiceArmed = ref(false)
const activeIndex = ref(0)
const listId = useId()

const zones = computed(() => listTimezones(new Date(), formData.value.timezone))
const matched = computed(() => filterTimezones(zones.value, query.value))
const groups = computed(() => groupTimezones(matched.value))
const flat = computed(() => groups.value.flatMap((group) => group.zones))
const highlighted = computed(() => flat.value[activeIndex.value] ?? null)
const searchMiss = computed(() => query.value.trim().length > 0 && matched.value.length === 0)
const currentLabel = computed(
  () => zones.value.find((zone) => zone.value === formData.value.timezone)?.label ?? ''
)
const activeOptionId = computed(() =>
  listOpen.value && highlighted.value ? optionId(highlighted.value.value) : undefined
)

watch(query, (value) => {
  activeIndex.value = 0
  if (value.trim()) {
    choiceArmed.value = true
    listOpen.value = true
  }
})

function optionId(value: string): string {
  return `${listId}-${value.replace(/[^a-zA-Z0-9_-]/g, '-')}`
}

function indexOf(value: string): number {
  return flat.value.findIndex((zone) => zone.value === value)
}

function openList(): void {
  listOpen.value = true
}

function closeList(): void {
  listOpen.value = false
  choiceArmed.value = false
}

function onFocusOut(event: FocusEvent): void {
  const next = event.relatedTarget
  if (next instanceof Node && root.value?.contains(next)) return
  closeList()
}

function moveActive(step: number): void {
  openList()
  choiceArmed.value = true
  if (flat.value.length === 0) return
  const last = flat.value.length - 1
  activeIndex.value = Math.min(last, Math.max(0, activeIndex.value + step))
  void nextTick(() => {
    if (!highlighted.value) return
    document.getElementById(optionId(highlighted.value.value))?.scrollIntoView({ block: 'nearest' })
  })
}

function choose(value: string): void {
  formData.value.timezone = value
  query.value = ''
  closeList()
}

function chooseHighlighted(): void {
  const zone = highlighted.value
  if (!listOpen.value || !choiceArmed.value || !zone) return
  choose(zone.value)
}
</script>
