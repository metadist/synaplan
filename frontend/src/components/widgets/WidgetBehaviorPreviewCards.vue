<script setup lang="ts">
import { computed } from 'vue'
import { Icon } from '@iconify/vue'
import { useI18n } from 'vue-i18n'
import type { WidgetBehaviorRules } from '@/utils/widgetBehaviorRules'

interface Props {
  rules: WidgetBehaviorRules
}

const props = defineProps<Props>()
const { t } = useI18n()
const activeTags = computed(() => {
  const tags: string[] = []
  if (props.rules.locationLinkRequired) tags.push('Link')
  if (props.rules.locationImageLink) tags.push('Bild')
  if (props.rules.conciseReplies) tags.push('Kurz')
  if (props.rules.ctaRequired) tags.push('CTA')
  return tags
})
const criteriaImpact = computed(() => {
  return [
    {
      key: 'locationLinkRequired',
      label: t('widgets.advancedConfig.behaviorRules.locationLinkRequired'),
      active: props.rules.locationLinkRequired,
      impactOn: t('widgets.advancedConfig.behaviorRules.relationStates.locationLinkOn'),
      impactOff: t('widgets.advancedConfig.behaviorRules.relationStates.locationLinkOff'),
    },
    {
      key: 'locationImageLink',
      label: t('widgets.advancedConfig.behaviorRules.locationImageLink'),
      active: props.rules.locationImageLink,
      impactOn: t('widgets.advancedConfig.behaviorRules.relationStates.locationImageOn'),
      impactOff: t('widgets.advancedConfig.behaviorRules.relationStates.locationImageOff'),
    },
    {
      key: 'conciseReplies',
      label: t('widgets.advancedConfig.behaviorRules.conciseReplies'),
      active: props.rules.conciseReplies,
      impactOn: t('widgets.advancedConfig.behaviorRules.relationStates.conciseOn'),
      impactOff: t('widgets.advancedConfig.behaviorRules.relationStates.conciseOff'),
    },
    {
      key: 'ctaRequired',
      label: t('widgets.advancedConfig.behaviorRules.ctaRequired'),
      active: props.rules.ctaRequired,
      impactOn: t('widgets.advancedConfig.behaviorRules.relationStates.ctaOn'),
      impactOff: t('widgets.advancedConfig.behaviorRules.relationStates.ctaOff'),
    },
  ]
})

const timeAnswerPreview = computed(() => {
  const base = props.rules.conciseReplies
    ? t('widgets.advancedConfig.behaviorRules.previewReplies.time.concise')
    : t('widgets.advancedConfig.behaviorRules.previewReplies.time.default')

  if (!props.rules.ctaRequired) {
    return base
  }

  return `${base} ${t('widgets.advancedConfig.behaviorRules.previewReplies.time.cta')}`
})

const locationAnswerPreview = computed(() => {
  const parts: string[] = []
  parts.push(
    props.rules.conciseReplies
      ? t('widgets.advancedConfig.behaviorRules.previewReplies.location.concise')
      : t('widgets.advancedConfig.behaviorRules.previewReplies.location.default')
  )

  if (props.rules.locationLinkRequired) {
    parts.push(t('widgets.advancedConfig.behaviorRules.previewReplies.location.link'))
  }

  if (props.rules.locationImageLink) {
    parts.push(t('widgets.advancedConfig.behaviorRules.previewReplies.location.image'))
  }

  if (props.rules.ctaRequired) {
    parts.push(t('widgets.advancedConfig.behaviorRules.previewReplies.location.cta'))
  }

  return parts.join('\n')
})

const genericPreview = computed(() => {
  const base = props.rules.conciseReplies
    ? t('widgets.advancedConfig.behaviorRules.previewReplies.generic.concise')
    : t('widgets.advancedConfig.behaviorRules.previewReplies.generic.default')

  if (!props.rules.ctaRequired) {
    return base
  }

  return `${base} ${t('widgets.advancedConfig.behaviorRules.previewReplies.generic.cta')}`
})
</script>

<template>
  <section class="space-y-4" data-testid="widget-behavior-preview-cards">
    <div
      class="rounded-2xl p-4 border border-light-border/30 dark:border-dark-border/20 bg-white/70 dark:bg-black/20"
    >
      <div class="flex flex-wrap items-center justify-between gap-2">
        <h4 class="text-sm font-semibold txt-primary flex items-center gap-2">
          <span
            class="w-7 h-7 rounded-lg bg-[var(--brand)] text-white flex items-center justify-center shadow-sm"
          >
            <Icon icon="heroicons:eye" class="w-4 h-4" />
          </span>
          {{ $t('widgets.advancedConfig.behaviorRules.previewTitle') }}
        </h4>
        <div class="flex flex-wrap gap-1.5">
          <span
            v-for="tag in activeTags"
            :key="tag"
            class="text-[11px] px-2 py-0.5 rounded-full border border-[var(--brand)]/35 bg-[var(--brand)]/10 txt-primary"
          >
            {{ tag }}
          </span>
        </div>
      </div>
    </div>

    <section class="rounded-2xl border border-light-border/30 dark:border-dark-border/20 p-4">
      <h5 class="text-sm font-semibold txt-primary mb-3">
        {{ $t('widgets.advancedConfig.behaviorRules.relationTitle') }}
      </h5>
      <div class="space-y-2">
        <div
          v-for="item in criteriaImpact"
          :key="item.key"
          class="rounded-xl border border-light-border/30 dark:border-dark-border/20 p-3 grid grid-cols-1 lg:grid-cols-[220px_1fr_1fr] gap-2 items-center"
        >
          <p class="text-sm font-medium txt-primary">{{ item.label }}</p>
          <span
            :class="[
              'text-xs px-2 py-1 rounded-md w-fit',
              item.active
                ? 'bg-green-500/15 text-green-700 dark:text-green-300 border border-green-500/30'
                : 'bg-gray-500/15 txt-secondary border border-light-border/30 dark:border-dark-border/20',
            ]"
          >
            {{ item.active ? 'ON' : 'OFF' }}
          </span>
          <p class="text-sm txt-primary">
            {{ item.active ? item.impactOn : item.impactOff }}
          </p>
        </div>
      </div>
    </section>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-3">
      <article
        class="relative overflow-hidden rounded-2xl p-4 border border-light-border/30 dark:border-dark-border/30 bg-white/60 dark:bg-black/10"
      >
        <div
          class="absolute top-0 left-0 right-0 h-1 bg-gradient-to-r from-[var(--brand)]/80 to-transparent"
        />
        <p class="text-xs uppercase tracking-wide txt-secondary mb-2 flex items-center gap-1.5">
          <Icon icon="heroicons:clock" class="w-3.5 h-3.5" />
          {{ $t('widgets.advancedConfig.behaviorRules.scenarios.timeQuestion') }}
        </p>
        <p class="text-sm whitespace-pre-line txt-primary leading-relaxed">
          {{ timeAnswerPreview }}
        </p>
      </article>

      <article
        class="relative overflow-hidden rounded-2xl p-4 border border-light-border/30 dark:border-dark-border/30 bg-white/60 dark:bg-black/10"
      >
        <div
          class="absolute top-0 left-0 right-0 h-1 bg-gradient-to-r from-[var(--brand)]/80 to-transparent"
        />
        <p class="text-xs uppercase tracking-wide txt-secondary mb-2 flex items-center gap-1.5">
          <Icon icon="heroicons:map-pin" class="w-3.5 h-3.5" />
          {{ $t('widgets.advancedConfig.behaviorRules.scenarios.locationQuestion') }}
        </p>
        <p class="text-sm whitespace-pre-line txt-primary leading-relaxed">
          {{ locationAnswerPreview }}
        </p>
      </article>

      <article
        class="relative overflow-hidden rounded-2xl p-4 border border-light-border/30 dark:border-dark-border/30 bg-white/60 dark:bg-black/10 lg:col-span-2"
      >
        <div
          class="absolute top-0 left-0 right-0 h-1 bg-gradient-to-r from-[var(--brand)]/80 to-transparent"
        />
        <p class="text-xs uppercase tracking-wide txt-secondary mb-2 flex items-center gap-1.5">
          <Icon icon="heroicons:chat-bubble-left-right" class="w-3.5 h-3.5" />
          {{ $t('widgets.advancedConfig.behaviorRules.scenarios.genericQuestion') }}
        </p>
        <p class="text-sm whitespace-pre-line txt-primary leading-relaxed">{{ genericPreview }}</p>
      </article>
    </div>
  </section>
</template>
