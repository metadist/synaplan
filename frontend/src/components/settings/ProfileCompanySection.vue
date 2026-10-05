<template>
  <section class="surface-card rounded-lg p-6" data-testid="section-company">
    <h2 class="text-xl font-semibold txt-primary mb-2 flex items-center gap-2">
      <Icon icon="mdi:office-building" class="w-5 h-5" />
      {{ $t('profile.companyInfo.title') }}
    </h2>
    <p class="txt-secondary text-sm mb-6">{{ $t('profile.companyInfo.subtitle') }}</p>

    <div class="grid grid-cols-1 gap-6 @min-[36rem]:grid-cols-2">
      <div data-testid="field-company-name">
        <label class="block txt-primary font-medium mb-2">
          {{ $t('profile.companyInfo.companyName') }}
        </label>
        <input
          v-model="formData.companyName"
          type="text"
          class="w-full px-4 py-2.5 rounded-xl bg-chat border border-light-border/30 dark:border-dark-border/20 txt-primary focus:ring-2 focus:ring-[var(--brand)] focus:outline-none"
          :placeholder="$t('profile.companyInfo.companyNamePlaceholder')"
          data-testid="input-company-name"
        />
      </div>

      <div data-testid="field-vat-id">
        <label class="block txt-primary font-medium mb-2">
          {{ $t('profile.companyInfo.vatId') }}
        </label>
        <input
          v-model="formData.vatId"
          type="text"
          class="w-full px-4 py-2.5 rounded-xl bg-chat border border-light-border/30 dark:border-dark-border/20 txt-primary focus:ring-2 focus:ring-[var(--brand)] focus:outline-none"
          :placeholder="$t('profile.companyInfo.vatIdPlaceholder')"
          data-testid="input-vat-id"
        />
      </div>

      <div class="@min-[36rem]:col-span-2" data-testid="field-invoice-email">
        <label class="block txt-primary font-medium mb-2">
          {{ $t('profile.accountSettings.invoiceEmail') }}
        </label>
        <input
          v-model="formData.invoiceEmail"
          type="email"
          class="w-full px-4 py-2.5 rounded-xl bg-chat border border-light-border/30 dark:border-dark-border/20 txt-primary focus:ring-2 focus:ring-[var(--brand)] focus:outline-none"
          :placeholder="$t('profile.accountSettings.invoiceEmailPlaceholder')"
          data-testid="input-invoice-email"
        />
      </div>
    </div>
  </section>
</template>

<script setup lang="ts">
import { Icon } from '@iconify/vue'
import { injectProfileSettings } from '@/composables/useProfileSettings'

const { formData } = injectProfileSettings()
</script>
