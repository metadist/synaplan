<template>
  <div class="w-full max-w-3xl mx-auto" data-testid="comp-companion-links">
    <div
      class="flex sm:grid sm:grid-cols-3 gap-3 overflow-x-auto pb-1 -mx-1 px-1 snap-x snap-mandatory"
    >
      <div
        class="surface-card p-4 rounded-xl text-left flex flex-col gap-2 flex-shrink-0 w-[240px] sm:w-auto snap-start"
        data-testid="card-companion-choice"
      >
        <span
          class="w-8 h-8 rounded-lg bg-[var(--brand)]/15 flex items-center justify-center flex-shrink-0"
        >
          <Icon icon="mdi:tune-variant" class="w-5 h-5 text-[var(--brand)]" aria-hidden="true" />
        </span>
        <span class="text-sm font-semibold txt-primary leading-snug">
          {{ $t('companionLinks.choice.title') }}
        </span>
        <span class="text-sm txt-secondary leading-snug">
          {{ $t('companionLinks.choice.blurb') }}
        </span>
      </div>

      <div
        class="surface-card p-4 rounded-xl text-left flex flex-col gap-2 flex-shrink-0 w-[240px] sm:w-auto snap-start"
        data-testid="card-companion-mobile"
      >
        <span
          class="w-8 h-8 rounded-lg bg-[var(--brand)]/15 flex items-center justify-center flex-shrink-0"
        >
          <Icon icon="mdi:cellphone" class="w-5 h-5 text-[var(--brand)]" aria-hidden="true" />
        </span>
        <span class="text-sm font-semibold txt-primary leading-snug">
          {{ $t('companionLinks.mobile.title') }}
        </span>
        <span class="text-sm txt-secondary leading-snug">
          {{ $t('companionLinks.mobile.blurb') }}
        </span>
        <span class="flex flex-col gap-2 mt-1">
          <a
            :href="APP_STORE_URL"
            target="_blank"
            rel="noopener noreferrer"
            class="btn-secondary inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-lg text-sm font-medium focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--brand)]"
            data-testid="link-companion-app-store"
          >
            <Icon icon="mdi:apple" class="w-4 h-4" aria-hidden="true" />
            {{ $t('companionLinks.mobile.appStore') }}
            <span class="sr-only">{{ $t('companionLinks.opensInNewTab') }}</span>
          </a>
          <a
            :href="PLAY_STORE_URL"
            target="_blank"
            rel="noopener noreferrer"
            class="btn-secondary inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-lg text-sm font-medium focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--brand)]"
            data-testid="link-companion-play-store"
          >
            <Icon icon="mdi:google-play" class="w-4 h-4" aria-hidden="true" />
            {{ $t('companionLinks.mobile.playStore') }}
            <span class="sr-only">{{ $t('companionLinks.opensInNewTab') }}</span>
          </a>
        </span>
      </div>

      <a
        :href="SOURCE_URL"
        target="_blank"
        rel="noopener noreferrer"
        class="surface-card hover-surface p-4 rounded-xl text-left flex flex-col gap-2 transition-all flex-shrink-0 w-[240px] sm:w-auto snap-start focus:outline-none focus-visible:ring-2 focus-visible:ring-[var(--brand)]"
        data-testid="link-companion-source"
      >
        <span class="flex items-start justify-between gap-2">
          <span
            class="w-8 h-8 rounded-lg bg-[var(--brand)]/15 flex items-center justify-center flex-shrink-0"
          >
            <Icon icon="mdi:github" class="w-5 h-5 text-[var(--brand)]" aria-hidden="true" />
          </span>
          <Icon
            icon="heroicons:arrow-top-right-on-square"
            class="w-4 h-4 txt-secondary flex-shrink-0 mt-0.5"
            aria-hidden="true"
          />
        </span>
        <span class="text-sm font-semibold txt-primary leading-snug">
          {{ $t('companionLinks.source.title') }}
        </span>
        <span class="text-sm txt-secondary leading-snug">
          {{ $t('companionLinks.source.blurb') }}
        </span>
        <span class="sr-only">{{ $t('companionLinks.opensInNewTab') }}</span>
      </a>
    </div>
  </div>
</template>

<script setup lang="ts">
import { Icon } from '@iconify/vue'

const APP_STORE_URL = 'https://apps.apple.com/app/id6784278288?ct=app-welcome'
const PLAY_STORE_URL =
  'https://play.google.com/store/apps/details?id=com.synaplan.app&referrer=utm_source%3Dapp-welcome'
const SOURCE_URL = 'https://github.com/metadist/synaplan'
</script>
