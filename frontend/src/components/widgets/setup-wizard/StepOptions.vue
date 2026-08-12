<template>
  <div class="space-y-5">
    <!-- Welcome message -->
    <div>
      <label class="block text-sm font-medium txt-primary mb-2">
        {{ $t('widgets.createWizard.options.welcomeLabel') }}
      </label>
      <textarea
        v-model="autoMessage"
        rows="2"
        :placeholder="$t('widgets.createWizard.options.welcomePlaceholder')"
        class="w-full px-4 py-3 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)] resize-y"
        data-testid="input-welcome-message"
      ></textarea>
    </div>

    <!-- Auto open -->
    <label
      class="flex items-start gap-3 p-3 rounded-lg surface-chip cursor-pointer hover:opacity-90 transition-opacity"
    >
      <input
        v-model="autoOpen"
        type="checkbox"
        class="mt-0.5 w-4 h-4 rounded border-gray-300 text-[var(--brand)] focus:ring-[var(--brand)]"
        data-testid="input-auto-open"
      />
      <span class="min-w-0">
        <span class="block text-sm font-medium txt-primary">
          {{ $t('widgets.createWizard.options.autoOpenLabel') }}
        </span>
        <span class="block text-xs txt-secondary mt-0.5">
          {{ $t('widgets.createWizard.options.autoOpenHint') }}
        </span>
      </span>
    </label>

    <!-- Visitor file uploads -->
    <label
      class="flex items-start gap-3 p-3 rounded-lg surface-chip cursor-pointer hover:opacity-90 transition-opacity"
    >
      <input
        v-model="allowFileUpload"
        type="checkbox"
        class="mt-0.5 w-4 h-4 rounded border-gray-300 text-[var(--brand)] focus:ring-[var(--brand)]"
        data-testid="input-allow-visitor-uploads"
      />
      <span class="min-w-0">
        <span class="block text-sm font-medium txt-primary">
          {{ $t('widgets.createWizard.options.fileUploadLabel') }}
        </span>
        <span class="block text-xs txt-secondary mt-0.5">
          {{ $t('widgets.createWizard.options.fileUploadHint') }}
        </span>
      </span>
    </label>

    <!-- Change later hint -->
    <p class="text-xs txt-secondary flex items-start gap-1.5">
      <Icon icon="heroicons:information-circle" class="w-4 h-4 flex-shrink-0 mt-0.5" />
      <span>{{ $t('widgets.createWizard.options.changeLaterHint') }}</span>
    </p>
  </div>
</template>

<script setup lang="ts">
import { Icon } from '@iconify/vue'

const autoMessage = defineModel<string>('autoMessage', { required: true })
const autoOpen = defineModel<boolean>('autoOpen', { required: true })
const allowFileUpload = defineModel<boolean>('allowFileUpload', { required: true })
</script>
