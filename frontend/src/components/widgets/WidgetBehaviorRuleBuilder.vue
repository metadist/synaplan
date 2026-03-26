<script setup lang="ts">
import { computed } from 'vue'
import { Icon } from '@iconify/vue'
import {
  DEFAULT_WIDGET_BEHAVIOR_RULES,
  type WidgetBehaviorRules,
  normalizeWidgetBehaviorRules,
} from '@/utils/widgetBehaviorRules'

interface Props {
  modelValue: WidgetBehaviorRules
  disabled?: boolean
}

const props = withDefaults(defineProps<Props>(), {
  disabled: false,
})

const emit = defineEmits<{
  'update:modelValue': [value: WidgetBehaviorRules]
}>()

const rules = computed(() => normalizeWidgetBehaviorRules(props.modelValue))
type ToggleRuleKey = Exclude<keyof WidgetBehaviorRules, 'version'>

const setRule = (key: ToggleRuleKey, value: boolean) => {
  emit('update:modelValue', {
    ...rules.value,
    [key]: value,
  })
}

const applyPreset = (preset: 'location' | 'conciseCta' | 'all') => {
  const next = { ...DEFAULT_WIDGET_BEHAVIOR_RULES }
  if (preset === 'location') {
    next.locationLinkRequired = true
    next.locationImageLink = true
  } else if (preset === 'conciseCta') {
    next.conciseReplies = true
    next.ctaRequired = true
  } else {
    next.locationLinkRequired = true
    next.locationImageLink = true
    next.conciseReplies = true
    next.ctaRequired = true
  }

  emit('update:modelValue', next)
}

const resetRules = () => {
  emit('update:modelValue', { ...DEFAULT_WIDGET_BEHAVIOR_RULES })
}

const enabledRules = computed(() => {
  return [
    rules.value.locationLinkRequired,
    rules.value.locationImageLink,
    rules.value.conciseReplies,
    rules.value.ctaRequired,
  ].filter(Boolean).length
})

const activeStateLabel = computed(() => `${enabledRules.value}/4`)
</script>

<template>
  <section
    class="rounded-2xl p-4 sm:p-5 space-y-5 border border-light-border/30 dark:border-dark-border/20 bg-white/70 dark:bg-black/20"
    data-testid="widget-behavior-rule-builder"
  >
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
      <div class="space-y-1">
        <h4 class="text-sm font-semibold txt-primary flex items-center gap-2">
          <span
            class="w-7 h-7 rounded-lg bg-[var(--brand)] text-white flex items-center justify-center shadow-sm"
          >
            <Icon icon="heroicons:squares-2x2" class="w-4 h-4" />
          </span>
          {{ $t('widgets.advancedConfig.behaviorRules.title') }}
        </h4>
        <p class="text-xs txt-secondary">
          {{ $t('widgets.advancedConfig.behaviorRules.description') }}
        </p>
      </div>

      <div class="flex items-center gap-2">
        <span
          class="text-xs px-2.5 py-1 rounded-full border border-[var(--brand)]/30 bg-[var(--brand)]/10 txt-primary"
        >
          {{ activeStateLabel }}
        </span>
        <button
          type="button"
          class="text-xs px-3 py-1.5 rounded-lg border border-light-border/30 dark:border-dark-border/30 txt-secondary hover:txt-primary hover:bg-black/5 dark:hover:bg-white/5 transition-colors disabled:opacity-50"
          :disabled="disabled"
          @click="resetRules"
        >
          {{ $t('widgets.advancedConfig.behaviorRules.reset') }}
        </button>
      </div>
    </div>

    <div class="flex flex-wrap gap-2">
      <button
        type="button"
        class="text-xs px-3 py-1.5 rounded-xl border border-[var(--brand)]/30 text-[var(--brand)] hover:bg-[var(--brand)]/10 hover:translate-y-[-1px] transition-all"
        :disabled="disabled"
        @click="applyPreset('location')"
      >
        {{ $t('widgets.advancedConfig.behaviorRules.presets.location') }}
      </button>
      <button
        type="button"
        class="text-xs px-3 py-1.5 rounded-xl border border-[var(--brand)]/30 text-[var(--brand)] hover:bg-[var(--brand)]/10 hover:translate-y-[-1px] transition-all"
        :disabled="disabled"
        @click="applyPreset('conciseCta')"
      >
        {{ $t('widgets.advancedConfig.behaviorRules.presets.conciseCta') }}
      </button>
      <button
        type="button"
        class="text-xs px-3 py-1.5 rounded-xl border border-[var(--brand)]/30 text-[var(--brand)] hover:bg-[var(--brand)]/10 hover:translate-y-[-1px] transition-all"
        :disabled="disabled"
        @click="applyPreset('all')"
      >
        {{ $t('widgets.advancedConfig.behaviorRules.presets.all') }}
      </button>
    </div>

    <div class="grid grid-cols-1 xl:grid-cols-3 gap-3">
      <section
        class="rounded-2xl border border-light-border/30 dark:border-dark-border/30 p-4 space-y-3"
      >
        <div class="flex items-center gap-2">
          <Icon icon="heroicons:map-pin" class="w-4 h-4 txt-brand" />
          <p class="text-sm font-semibold txt-primary">
            {{ $t('widgets.advancedConfig.behaviorRules.sections.location.title') }}
          </p>
        </div>
        <p class="text-xs txt-secondary">
          {{ $t('widgets.advancedConfig.behaviorRules.sections.location.description') }}
        </p>

        <label
          :class="[
            'block rounded-xl border p-3 cursor-pointer transition-colors',
            rules.locationLinkRequired
              ? 'border-[var(--brand)]/40 bg-[var(--brand)]/10'
              : 'border-light-border/30 dark:border-dark-border/30',
          ]"
        >
          <div class="flex items-start justify-between gap-2">
            <p class="text-sm font-medium txt-primary">
              {{ $t('widgets.advancedConfig.behaviorRules.locationLinkRequired') }}
            </p>
            <input
              :checked="rules.locationLinkRequired"
              :disabled="disabled"
              type="checkbox"
              class="mt-0.5 h-4 w-4 rounded border-light-border/30 text-[var(--brand)] focus:ring-[var(--brand)]"
              @change="setRule('locationLinkRequired', ($event.target as HTMLInputElement).checked)"
            />
          </div>
        </label>

        <label
          :class="[
            'block rounded-xl border p-3 cursor-pointer transition-colors',
            rules.locationImageLink
              ? 'border-[var(--brand)]/40 bg-[var(--brand)]/10'
              : 'border-light-border/30 dark:border-dark-border/30',
          ]"
        >
          <div class="flex items-start justify-between gap-2">
            <p class="text-sm font-medium txt-primary">
              {{ $t('widgets.advancedConfig.behaviorRules.locationImageLink') }}
            </p>
            <input
              :checked="rules.locationImageLink"
              :disabled="disabled"
              type="checkbox"
              class="mt-0.5 h-4 w-4 rounded border-light-border/30 text-[var(--brand)] focus:ring-[var(--brand)]"
              @change="setRule('locationImageLink', ($event.target as HTMLInputElement).checked)"
            />
          </div>
        </label>

        <p
          class="text-xs rounded-lg bg-[var(--brand)]/10 border border-[var(--brand)]/20 p-2 txt-primary"
        >
          {{ $t('widgets.advancedConfig.behaviorRules.sections.location.effect') }}
        </p>
      </section>

      <section
        class="rounded-2xl border border-light-border/30 dark:border-dark-border/30 p-4 space-y-3"
      >
        <div class="flex items-center gap-2">
          <Icon icon="heroicons:chat-bubble-left-ellipsis" class="w-4 h-4 txt-brand" />
          <p class="text-sm font-semibold txt-primary">
            {{ $t('widgets.advancedConfig.behaviorRules.sections.style.title') }}
          </p>
        </div>
        <p class="text-xs txt-secondary">
          {{ $t('widgets.advancedConfig.behaviorRules.sections.style.description') }}
        </p>

        <label
          :class="[
            'block rounded-xl border p-3 cursor-pointer transition-colors',
            rules.conciseReplies
              ? 'border-[var(--brand)]/40 bg-[var(--brand)]/10'
              : 'border-light-border/30 dark:border-dark-border/30',
          ]"
        >
          <div class="flex items-start justify-between gap-2">
            <p class="text-sm font-medium txt-primary">
              {{ $t('widgets.advancedConfig.behaviorRules.conciseReplies') }}
            </p>
            <input
              :checked="rules.conciseReplies"
              :disabled="disabled"
              type="checkbox"
              class="mt-0.5 h-4 w-4 rounded border-light-border/30 text-[var(--brand)] focus:ring-[var(--brand)]"
              @change="setRule('conciseReplies', ($event.target as HTMLInputElement).checked)"
            />
          </div>
        </label>

        <p
          class="text-xs rounded-lg bg-[var(--brand)]/10 border border-[var(--brand)]/20 p-2 txt-primary"
        >
          {{ $t('widgets.advancedConfig.behaviorRules.sections.style.effect') }}
        </p>
      </section>

      <section
        class="rounded-2xl border border-light-border/30 dark:border-dark-border/30 p-4 space-y-3"
      >
        <div class="flex items-center gap-2">
          <Icon icon="heroicons:megaphone" class="w-4 h-4 txt-brand" />
          <p class="text-sm font-semibold txt-primary">
            {{ $t('widgets.advancedConfig.behaviorRules.sections.finish.title') }}
          </p>
        </div>
        <p class="text-xs txt-secondary">
          {{ $t('widgets.advancedConfig.behaviorRules.sections.finish.description') }}
        </p>

        <label
          :class="[
            'block rounded-xl border p-3 cursor-pointer transition-colors',
            rules.ctaRequired
              ? 'border-[var(--brand)]/40 bg-[var(--brand)]/10'
              : 'border-light-border/30 dark:border-dark-border/30',
          ]"
        >
          <div class="flex items-start justify-between gap-2">
            <p class="text-sm font-medium txt-primary">
              {{ $t('widgets.advancedConfig.behaviorRules.ctaRequired') }}
            </p>
            <input
              :checked="rules.ctaRequired"
              :disabled="disabled"
              type="checkbox"
              class="mt-0.5 h-4 w-4 rounded border-light-border/30 text-[var(--brand)] focus:ring-[var(--brand)]"
              @change="setRule('ctaRequired', ($event.target as HTMLInputElement).checked)"
            />
          </div>
        </label>

        <p
          class="text-xs rounded-lg bg-[var(--brand)]/10 border border-[var(--brand)]/20 p-2 txt-primary"
        >
          {{ $t('widgets.advancedConfig.behaviorRules.sections.finish.effect') }}
        </p>
      </section>
    </div>

    <section class="rounded-2xl border border-light-border/30 dark:border-dark-border/30 p-4">
      <p class="text-sm font-semibold txt-primary mb-3">
        {{ $t('widgets.advancedConfig.behaviorRules.relationTitle') }}
      </p>
      <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
        <div class="rounded-lg border border-light-border/30 dark:border-dark-border/30 p-3">
          <p class="text-xs txt-secondary mb-1">
            {{ $t('widgets.advancedConfig.behaviorRules.locationLinkRequired') }}
          </p>
          <p class="text-sm txt-primary">
            {{
              rules.locationLinkRequired
                ? $t('widgets.advancedConfig.behaviorRules.relationStates.locationLinkOn')
                : $t('widgets.advancedConfig.behaviorRules.relationStates.locationLinkOff')
            }}
          </p>
        </div>
        <div class="rounded-lg border border-light-border/30 dark:border-dark-border/30 p-3">
          <p class="text-xs txt-secondary mb-1">
            {{ $t('widgets.advancedConfig.behaviorRules.locationImageLink') }}
          </p>
          <p class="text-sm txt-primary">
            {{
              rules.locationImageLink
                ? $t('widgets.advancedConfig.behaviorRules.relationStates.locationImageOn')
                : $t('widgets.advancedConfig.behaviorRules.relationStates.locationImageOff')
            }}
          </p>
        </div>
        <div class="rounded-lg border border-light-border/30 dark:border-dark-border/30 p-3">
          <p class="text-xs txt-secondary mb-1">
            {{ $t('widgets.advancedConfig.behaviorRules.conciseReplies') }}
          </p>
          <p class="text-sm txt-primary">
            {{
              rules.conciseReplies
                ? $t('widgets.advancedConfig.behaviorRules.relationStates.conciseOn')
                : $t('widgets.advancedConfig.behaviorRules.relationStates.conciseOff')
            }}
          </p>
        </div>
        <div class="rounded-lg border border-light-border/30 dark:border-dark-border/30 p-3">
          <p class="text-xs txt-secondary mb-1">
            {{ $t('widgets.advancedConfig.behaviorRules.ctaRequired') }}
          </p>
          <p class="text-sm txt-primary">
            {{
              rules.ctaRequired
                ? $t('widgets.advancedConfig.behaviorRules.relationStates.ctaOn')
                : $t('widgets.advancedConfig.behaviorRules.relationStates.ctaOff')
            }}
          </p>
        </div>
      </div>
    </section>
  </section>
</template>
