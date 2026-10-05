<template>
  <section
    id="delete"
    class="surface-card rounded-lg p-6 scroll-mt-6 border-2 border-red-200 dark:border-red-800/50"
    data-testid="section-danger-zone"
  >
    <h2 class="text-xl font-semibold text-red-600 dark:text-red-400 mb-2 flex items-center gap-2">
      <Icon icon="mdi:alert" class="w-5 h-5" />
      {{ $t('profile.dangerZone.title') }}
    </h2>
    <p class="txt-secondary text-sm mb-6">{{ $t('profile.dangerZone.subtitle') }}</p>

    <div class="flex items-start gap-4 info-box-red">
      <Icon icon="mdi:account-remove" class="w-6 h-6 info-box-red-icon flex-shrink-0 mt-0.5" />
      <div class="flex-1">
        <h3 class="info-box-red-title mb-1">
          {{ $t('profile.dangerZone.deleteAccount') }}
        </h3>
        <p class="text-sm info-box-red-text mb-4">
          {{ $t('profile.dangerZone.deleteAccountDesc') }}
        </p>
        <button
          type="button"
          class="btn-danger px-4 py-2 rounded-xl text-sm font-medium"
          data-testid="btn-delete-account"
          @click="showDeleteModal = true"
        >
          {{ $t('profile.dangerZone.deleteButton') }}
        </button>
      </div>
    </div>

    <ProfileDeleteAccountModal />
  </section>
</template>

<script setup lang="ts">
import { Icon } from '@iconify/vue'
import { injectProfileSettings } from '@/composables/useProfileSettings'
import ProfileDeleteAccountModal from '@/components/settings/ProfileDeleteAccountModal.vue'

const { showDeleteModal } = injectProfileSettings()
</script>
