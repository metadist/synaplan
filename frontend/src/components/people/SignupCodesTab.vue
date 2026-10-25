<template>
  <section class="space-y-4" data-testid="section-signup-codes">
    <div
      v-if="configStore.auth.registrationMode === 'open'"
      class="surface-card rounded-xl p-4 flex flex-col sm:flex-row sm:items-center gap-3"
      data-testid="text-signup-codes-open-mode"
    >
      <p class="flex-1 text-sm txt-secondary">{{ $t('admin.signupCodes.openModeNote') }}</p>
      <button
        type="button"
        class="btn-secondary px-4 py-2.5 text-sm font-medium self-start sm:self-auto"
        data-testid="btn-signup-codes-open-settings"
        @click="router.push({ name: 'admin-config', query: { tab: 'auth' } })"
      >
        {{ $t('admin.signupCodes.openSettings') }}
      </button>
    </div>

    <p v-if="loading" class="text-sm txt-secondary">{{ $t('common.loading') }}</p>

    <div
      v-else-if="loadFailed"
      class="surface-card rounded-xl p-4 flex flex-col sm:flex-row sm:items-center gap-3"
    >
      <p
        role="alert"
        class="flex-1 text-sm text-red-600 dark:text-red-400"
        data-testid="text-signup-codes-error"
      >
        {{ $t('admin.signupCodes.loadFailed') }}
      </p>
      <button type="button" class="btn-secondary px-4 py-2.5 text-sm font-medium" @click="load">
        {{ $t('common.retry') }}
      </button>
    </div>

    <div
      v-else-if="!codes.length"
      class="surface-card rounded-xl p-6 text-center space-y-3"
      data-testid="empty-signup-codes"
    >
      <p class="text-sm txt-secondary">{{ $t('admin.signupCodes.empty') }}</p>
      <button
        type="button"
        class="btn-primary px-4 py-2.5 text-sm font-medium inline-flex items-center gap-2"
        data-testid="btn-signup-code-create"
        @click="openForm(null)"
      >
        <PlusIcon class="w-4 h-4" />
        {{ $t('admin.signupCodes.create') }}
      </button>
    </div>

    <template v-else>
      <div class="flex justify-end">
        <button
          type="button"
          class="btn-primary px-4 py-2.5 text-sm font-medium inline-flex items-center gap-2"
          data-testid="btn-signup-code-create"
          @click="openForm(null)"
        >
          <PlusIcon class="w-4 h-4" />
          {{ $t('admin.signupCodes.create') }}
        </button>
      </div>
      <ul class="space-y-3" data-testid="list-signup-codes">
        <SignupCodeRow
          v-for="code in codes"
          :key="code.id"
          :code="code"
          @edit="openForm(code)"
          @revoke="revoke(code)"
          @restore="restore(code)"
        />
      </ul>
    </template>

    <SignupCodeFormDialog
      :open="formOpen"
      :code="editing"
      @close="formOpen = false"
      @created="(code) => (codes = [code, ...codes])"
      @saved="replace"
    />
  </section>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { PlusIcon } from '@heroicons/vue/24/outline'
import SignupCodeFormDialog from '@/components/people/SignupCodeFormDialog.vue'
import SignupCodeRow from '@/components/people/SignupCodeRow.vue'
import { useDialog } from '@/composables/useDialog'
import { useNotification } from '@/composables/useNotification'
import { signupCodesApi, type SignupCode } from '@/services/api/signupCodesApi'
import { useConfigStore } from '@/stores/config'

const { t } = useI18n()
const router = useRouter()
const configStore = useConfigStore()
const { confirm } = useDialog()
const { success, error: notifyError } = useNotification()

const codes = ref<SignupCode[]>([])
const loading = ref(true)
const loadFailed = ref(false)
const formOpen = ref(false)
const editing = ref<SignupCode | null>(null)

async function load() {
  loading.value = true
  loadFailed.value = false
  try {
    codes.value = await signupCodesApi.list()
  } catch {
    loadFailed.value = true
  } finally {
    loading.value = false
  }
}

function openForm(code: SignupCode | null) {
  editing.value = code
  formOpen.value = true
}

function replace(updated: SignupCode) {
  codes.value = codes.value.map((code) => (code.id === updated.id ? updated : code))
}

async function revoke(code: SignupCode) {
  const confirmed = await confirm({
    title: t('admin.signupCodes.revokeTitle'),
    message: t('admin.signupCodes.revokeBody', { count: code.uses }, code.uses),
    confirmText: t('admin.signupCodes.actions.revoke'),
    cancelText: t('common.cancel'),
    danger: true,
  })
  if (!confirmed) return
  try {
    replace(await signupCodesApi.revoke(code.id))
    success(t('admin.signupCodes.revoked'))
  } catch {
    notifyError(t('admin.signupCodes.revokeFailed'))
  }
}

async function restore(code: SignupCode) {
  try {
    replace(await signupCodesApi.update(code.id, { active: true }))
    success(t('admin.signupCodes.restored'))
  } catch {
    notifyError(t('admin.signupCodes.restoreFailed'))
  }
}

onMounted(load)
</script>
