<template>
  <!--
    The row selects on click and keeps focus in the search field on
    mousedown; the control must receive both itself, or the switch would
    navigate and the select would never open. Tab from the search field
    reaches it; Esc or Shift+Tab goes back to the field.
  -->
  <span
    class="flex-shrink-0 flex items-center gap-2"
    data-testid="control-smart-search-setting"
    @click.stop
    @mousedown.stop
    @keydown.esc.stop.prevent="emit('leave')"
    @keydown.shift.tab.prevent="emit('leave')"
  >
    <span
      v-if="control.envPinned"
      class="text-xs txt-secondary text-right"
      :title="$t('search.palette.setting.pinnedHint')"
      data-testid="text-smart-search-setting-pinned"
    >
      {{ valueLabel }} · {{ $t('search.palette.setting.pinned') }}
    </span>
    <template v-else-if="control.type === 'toggle'">
      <span class="hidden sm:inline text-xs txt-secondary">{{ valueLabel }}</span>
      <!--
        The track colour sits on an inner element: the V2 design flattens the
        background of every [role="switch"], so on and off would look alike.
      -->
      <button
        type="button"
        role="switch"
        :aria-checked="value === 'true'"
        :aria-label="$t('search.palette.setting.switchLabel', { name })"
        :disabled="saving"
        class="relative inline-flex h-6 w-11 flex-shrink-0 items-center rounded-full bg-transparent focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--brand)] focus-visible:ring-offset-2 focus-visible:ring-offset-[var(--bg-card)] disabled:opacity-50 disabled:cursor-not-allowed"
        data-testid="btn-smart-search-setting-toggle"
        @click="emit('change', value === 'true' ? 'false' : 'true')"
      >
        <span
          :class="[
            'pointer-events-none absolute inset-0 rounded-full transition-colors duration-200',
            value === 'true' ? 'bg-[var(--brand)]' : 'bg-[var(--status-neutral)]',
          ]"
        />
        <span
          :class="[
            'pointer-events-none relative inline-block h-5 w-5 transform rounded-full bg-white shadow transition duration-200',
            value === 'true' ? 'translate-x-5' : 'translate-x-0.5',
          ]"
        />
      </button>
    </template>
    <select
      v-else
      :value="value"
      :aria-label="$t('search.palette.setting.selectLabel', { name })"
      :disabled="saving"
      class="max-w-[9rem] px-2 py-1 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-xs focus:outline-none focus:ring-2 focus:ring-[var(--brand)] disabled:opacity-50 disabled:cursor-not-allowed"
      data-testid="select-smart-search-setting"
      @change="emit('change', ($event.target as HTMLSelectElement).value)"
    >
      <option v-for="option in control.options" :key="option" :value="option">
        {{ optionLabel(control.key, option) }}
      </option>
    </select>
  </span>
</template>

<script setup lang="ts">
import type { SettingControl } from '@/composables/search/types'

defineProps<{
  control: SettingControl
  /** Value in force, including a change made in this session. */
  value: string
  valueLabel: string
  name: string
  saving: boolean
  optionLabel: (key: string, option: string) => string
}>()

const emit = defineEmits<{
  change: [value: string]
  leave: []
}>()
</script>
