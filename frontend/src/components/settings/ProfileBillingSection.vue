<template>
  <section
    id="billing"
    class="surface-card rounded-lg p-6 scroll-mt-6"
    data-testid="section-billing"
  >
    <h2 class="text-xl font-semibold txt-primary mb-6 flex items-center gap-2">
      <Icon icon="mdi:map-marker" class="w-5 h-5" />
      {{ $t('profile.billingAddress.title') }}
    </h2>

    <div class="grid grid-cols-1 gap-6" data-testid="group-address">
      <div data-testid="field-street">
        <label class="block txt-primary font-medium mb-2">
          {{ $t('profile.billingAddress.street') }}
        </label>
        <input
          v-model="formData.street"
          type="text"
          class="w-full px-4 py-2.5 rounded-lg bg-chat border border-light-border/30 dark:border-dark-border/20 txt-primary focus:ring-2 focus:ring-[var(--brand)] focus:outline-none"
          :placeholder="$t('profile.billingAddress.streetPlaceholder')"
          data-testid="input-street"
        />
      </div>

      <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <div data-testid="field-zip">
          <label class="block txt-primary font-medium mb-2">
            {{ $t('profile.billingAddress.zipCode') }}
          </label>
          <input
            v-model="formData.zipCode"
            type="text"
            class="w-full px-4 py-2.5 rounded-lg bg-chat border border-light-border/30 dark:border-dark-border/20 txt-primary focus:ring-2 focus:ring-[var(--brand)] focus:outline-none"
            :placeholder="$t('profile.billingAddress.zipCodePlaceholder')"
            data-testid="input-zip"
          />
        </div>

        <div class="md:col-span-2" data-testid="field-city">
          <label class="block txt-primary font-medium mb-2">
            {{ $t('profile.billingAddress.city') }}
          </label>
          <input
            v-model="formData.city"
            type="text"
            class="w-full px-4 py-2.5 rounded-lg bg-chat border border-light-border/30 dark:border-dark-border/20 txt-primary focus:ring-2 focus:ring-[var(--brand)] focus:outline-none"
            :placeholder="$t('profile.billingAddress.cityPlaceholder')"
            data-testid="input-city"
          />
        </div>
      </div>

      <div data-testid="field-country">
        <label class="block txt-primary font-medium mb-2">
          {{ $t('profile.billingAddress.country') }}
        </label>
        <select
          v-model="formData.country"
          class="w-full px-4 py-2.5 rounded-lg bg-chat border border-light-border/30 dark:border-dark-border/20 txt-primary focus:ring-2 focus:ring-[var(--brand)] focus:outline-none"
          data-testid="select-country"
        >
          <option v-for="country in countries" :key="country.code" :value="country.code">
            {{ country.name }}
          </option>
        </select>
      </div>
    </div>
  </section>
</template>

<script setup lang="ts">
import { Icon } from '@iconify/vue'
import { countries } from '@/mocks/profile'
import { injectProfileSettings } from '@/composables/useProfileSettings'

const { formData } = injectProfileSettings()
</script>
