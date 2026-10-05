<template>
  <Teleport to="#app">
    <div
      v-if="showDeleteModal"
      class="fixed inset-0 bg-black/50 dark:bg-black/70 flex items-center justify-center z-50 px-4"
      data-testid="modal-delete-account"
      @click.self="showDeleteModal = false"
    >
      <div class="surface-card max-w-lg w-full p-6 space-y-6 animate-scale-in">
        <div class="text-center">
          <div
            class="w-16 h-16 mx-auto mb-4 rounded-full bg-red-100 dark:bg-red-900/30 flex items-center justify-center"
          >
            <Icon icon="mdi:alert-circle" class="w-10 h-10 text-red-600 dark:text-red-400" />
          </div>
          <h2 class="text-2xl font-bold txt-primary mb-2">
            {{ $t('profile.deleteAccountModal.title') }}
          </h2>
          <p class="text-sm text-red-600 dark:text-red-400 font-medium">
            {{ $t('profile.deleteAccountModal.warning') }}
          </p>
        </div>

        <div class="info-box-red">
          <p class="text-sm info-box-red-title mb-3">
            {{ $t('profile.deleteAccountModal.consequences') }}
          </p>
          <ul class="space-y-2 text-sm info-box-red-text">
            <li class="flex items-start gap-2">
              <Icon icon="mdi:close-circle" class="w-4 h-4 flex-shrink-0 mt-0.5" />
              <span>{{ $t('profile.deleteAccountModal.consequence1') }}</span>
            </li>
            <li class="flex items-start gap-2">
              <Icon icon="mdi:close-circle" class="w-4 h-4 flex-shrink-0 mt-0.5" />
              <span>{{ $t('profile.deleteAccountModal.consequence2') }}</span>
            </li>
            <li class="flex items-start gap-2">
              <Icon icon="mdi:close-circle" class="w-4 h-4 flex-shrink-0 mt-0.5" />
              <span>{{ $t('profile.deleteAccountModal.consequence3') }}</span>
            </li>
            <li class="flex items-start gap-2">
              <Icon icon="mdi:close-circle" class="w-4 h-4 flex-shrink-0 mt-0.5" />
              <span>{{ $t('profile.deleteAccountModal.consequence4') }}</span>
            </li>
          </ul>
        </div>

        <div v-if="!isExternalAuth">
          <label class="block txt-primary font-medium mb-2">
            {{ $t('profile.deleteAccountModal.confirmPassword') }}
          </label>
          <input
            v-model="deleteConfirmPassword"
            type="password"
            class="w-full px-4 py-2.5 rounded-lg surface-chip txt-primary placeholder:txt-secondary focus:outline-none focus:ring-2 focus:ring-red-500 transition-colors border-0"
            :placeholder="$t('profile.deleteAccountModal.confirmPasswordPlaceholder')"
            data-testid="input-delete-password"
            @keyup.enter="handleDeleteAccount"
          />
        </div>

        <div v-else>
          <label class="block txt-primary font-medium mb-2">
            {{ $t('profile.deleteAccountModal.externalAuthConfirm') }}
          </label>
          <input
            v-model="deleteConfirmText"
            type="text"
            class="w-full px-4 py-2.5 rounded-lg surface-chip txt-primary placeholder:txt-secondary focus:outline-none focus:ring-2 focus:ring-red-500 transition-colors border-0"
            :placeholder="$t('profile.deleteAccountModal.externalAuthPlaceholder')"
            data-testid="input-delete-confirm"
            @keyup.enter="handleDeleteAccount"
          />
        </div>

        <div class="flex gap-3 pt-2">
          <button
            type="button"
            class="flex-1 btn-secondary py-2.5 rounded-lg font-medium"
            :disabled="deletingAccount"
            data-testid="btn-cancel-delete"
            @click="showDeleteModal = false"
          >
            {{ $t('profile.deleteAccountModal.cancelButton') }}
          </button>
          <button
            type="button"
            class="flex-1 btn-danger py-2.5 rounded-lg font-medium"
            :disabled="deletingAccount || !canConfirmDelete"
            data-testid="btn-confirm-delete"
            @click="handleDeleteAccount"
          >
            <span v-if="deletingAccount">{{ $t('profile.deleteAccountModal.deleting') }}</span>
            <span v-else>{{ $t('profile.deleteAccountModal.deleteButton') }}</span>
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<script setup lang="ts">
import { Icon } from '@iconify/vue'
import { injectProfileSettings } from '@/composables/useProfileSettings'

const {
  showDeleteModal,
  isExternalAuth,
  deleteConfirmPassword,
  deleteConfirmText,
  deletingAccount,
  canConfirmDelete,
  handleDeleteAccount,
} = injectProfileSettings()
</script>

<style scoped>
@keyframes scaleIn {
  from {
    opacity: 0;
    transform: scale(0.95);
  }
  to {
    opacity: 1;
    transform: scale(1);
  }
}

.animate-scale-in {
  animation: scaleIn 0.2s ease-out;
}
</style>
