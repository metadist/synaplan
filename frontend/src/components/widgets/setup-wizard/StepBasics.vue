<template>
  <div class="space-y-5">
    <!-- Widget Name -->
    <div>
      <label class="block text-sm font-medium txt-primary mb-2">
        {{ $t('widgets.createWizard.basics.nameLabel') }} *
      </label>
      <input
        v-model="name"
        type="text"
        :placeholder="$t('widgets.createWizard.basics.namePlaceholder')"
        class="w-full px-4 py-3 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)] transition-all"
        data-testid="input-widget-name"
        required
      />
    </div>

    <!-- Website URL -->
    <div>
      <label class="block text-sm font-medium txt-primary mb-2">
        {{ $t('widgets.createWizard.basics.websiteLabel') }} *
      </label>
      <div class="relative">
        <Icon
          icon="heroicons:globe-alt"
          class="absolute left-4 top-1/2 -translate-y-1/2 w-5 h-5 txt-secondary z-10"
        />
        <input
          v-model="websiteUrl"
          type="text"
          :placeholder="$t('widgets.createWizard.basics.websitePlaceholder')"
          class="w-full pl-12 pr-4 py-3 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)] transition-all"
          data-testid="input-website-url"
          required
        />
      </div>
      <p class="text-xs txt-secondary mt-1.5">
        {{ $t('widgets.createWizard.basics.websiteHelp') }}
      </p>
    </div>
  </div>
</template>

<script setup lang="ts">
import { Icon } from '@iconify/vue'

const name = defineModel<string>('name', { required: true })
const websiteUrl = defineModel<string>('websiteUrl', { required: true })
</script>
