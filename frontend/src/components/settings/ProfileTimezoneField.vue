<template>
  <fieldset :disabled="!profileLoaded" class="border-0 p-0 m-0 min-w-0 disabled:opacity-60">
    <section class="surface-card rounded-lg p-6" data-testid="section-account-settings">
      <div data-testid="field-timezone">
        <label class="block txt-primary font-medium mb-2" for="profile-timezone">
          {{ $t('profile.accountSettings.timezone') }}
        </label>
        <input
          id="profile-timezone-search"
          v-model="timezoneQuery"
          type="search"
          autocomplete="off"
          class="mb-2 w-full px-4 py-2.5 rounded-xl surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
          :placeholder="$t('profile.accountSettings.timezoneSearch')"
          :aria-label="$t('profile.accountSettings.timezoneSearch')"
          data-testid="input-timezone-search"
        />
        <p class="txt-secondary text-sm mb-2">
          {{ $t('profile.accountSettings.timezoneHint') }}
        </p>
        <select
          id="profile-timezone"
          v-model="formData.timezone"
          class="w-full px-4 py-2.5 rounded-xl surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
          data-testid="select-timezone"
        >
          <option v-if="formData.timezone === ''" value="">
            {{ $t('profile.accountSettings.timezonePlaceholder') }}
          </option>
          <optgroup v-for="group in timezoneGroups" :key="group.offset" :label="group.offset">
            <option v-for="tz in group.zones" :key="tz.value" :value="tz.value">
              {{ tz.label }}
            </option>
          </optgroup>
        </select>
        <p
          v-if="timezoneSearchMiss"
          class="text-sm txt-secondary mt-1"
          data-testid="timezone-no-match"
        >
          {{ $t('profile.accountSettings.timezoneNoMatch') }}
        </p>
      </div>
    </section>
  </fieldset>
</template>

<script setup lang="ts">
import { injectProfileSettings } from '@/composables/useProfileSettings'

const { formData, timezoneQuery, timezoneGroups, timezoneSearchMiss, profileLoaded } =
  injectProfileSettings()
</script>
