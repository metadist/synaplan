<template>
  <div class="w-full max-w-3xl mx-auto" data-testid="comp-companion-links">
    <div
      class="flex sm:grid sm:grid-cols-3 gap-3 overflow-x-auto pb-1 -mx-1 px-1 snap-x snap-mandatory"
    >
      <a
        v-for="item in items"
        :key="item.id"
        :href="item.url"
        target="_blank"
        rel="noopener noreferrer"
        class="surface-card hover-surface p-4 rounded-xl text-left flex flex-col gap-2 transition-all flex-shrink-0 w-[240px] sm:w-auto snap-start focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--brand)]"
        :data-testid="`link-companion-${item.id}`"
      >
        <span class="flex items-start justify-between gap-2">
          <span
            class="w-8 h-8 rounded-lg bg-[var(--brand)]/15 flex items-center justify-center flex-shrink-0"
          >
            <Icon :icon="item.icon" class="w-5 h-5 text-[var(--brand)]" aria-hidden="true" />
          </span>
          <Icon
            icon="heroicons:arrow-top-right-on-square"
            class="w-4 h-4 txt-secondary flex-shrink-0 mt-0.5"
            aria-hidden="true"
          />
        </span>
        <span class="text-sm font-semibold txt-primary leading-snug">
          {{ $t(item.titleKey) }}
        </span>
        <span class="text-sm txt-secondary leading-snug">
          {{ $t(item.blurbKey) }}
        </span>
        <span class="sr-only">{{ $t('companionLinks.opensInNewTab') }}</span>
      </a>
    </div>
  </div>
</template>

<script setup lang="ts">
import { Icon } from '@iconify/vue'

const items = [
  {
    id: 'desktop',
    icon: 'mdi:laptop',
    titleKey: 'companionLinks.desktop.title',
    blurbKey: 'companionLinks.desktop.blurb',
    url: 'https://github.com/metadist/synaplan-desktop',
  },
  {
    id: 'mobile',
    icon: 'mdi:cellphone',
    titleKey: 'companionLinks.mobile.title',
    blurbKey: 'companionLinks.mobile.blurb',
    url: 'https://github.com/metadist/synaplan-apps',
  },
  {
    id: 'outlook',
    icon: 'mdi:microsoft-outlook',
    titleKey: 'companionLinks.outlook.title',
    blurbKey: 'companionLinks.outlook.blurb',
    url: 'https://github.com/metadist/Synamail',
  },
] as const
</script>
