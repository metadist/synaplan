<template>
  <span class="inline-flex flex-wrap items-center gap-1">
    <button
      v-if="!plain"
      type="button"
      class="font-mono text-sm txt-primary surface-chip rounded-lg px-2 py-0.5 hover:underline focus:outline-none focus:ring-2 focus:ring-[var(--brand)] disabled:opacity-50 disabled:cursor-not-allowed"
      :title="$t('admin.signupCodes.show')"
      :aria-label="$t('admin.signupCodes.showAria', { prefix: code.prefix })"
      :disabled="loading"
      data-testid="text-signup-code-prefix"
      @click="show"
    >
      {{ code.prefix }}…
    </button>
    <template v-else>
      <span
        class="font-mono text-sm txt-primary surface-chip rounded-lg px-2 py-0.5 select-all"
        data-testid="text-signup-code-full"
        >{{ plain }}</span
      >
      <button
        type="button"
        class="icon-ghost p-1.5 rounded-lg"
        :title="$t('admin.signupCodes.copy')"
        :aria-label="$t('admin.signupCodes.copy')"
        data-testid="btn-signup-code-row-copy"
        @click="copy"
      >
        <ClipboardDocumentIcon class="w-4 h-4" />
      </button>
      <button
        type="button"
        class="icon-ghost p-1.5 rounded-lg"
        :title="$t('admin.signupCodes.hide')"
        :aria-label="$t('admin.signupCodes.hide')"
        data-testid="btn-signup-code-hide"
        @click="plain = ''"
      >
        <EyeSlashIcon class="w-4 h-4" />
      </button>
    </template>
    <span
      v-if="message"
      role="status"
      class="basis-full text-xs txt-secondary"
      data-testid="text-signup-code-show-message"
      >{{ message }}</span
    >
  </span>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { ClipboardDocumentIcon, EyeSlashIcon } from '@heroicons/vue/24/outline'
import { useNotification } from '@/composables/useNotification'
import { ApiError } from '@/services/api/httpClient'
import { signupCodesApi, type SignupCode } from '@/services/api/signupCodesApi'

const props = defineProps<{ code: SignupCode }>()

const { t } = useI18n()
const { success, error } = useNotification()
const plain = ref('')
const message = ref('')
const loading = ref(false)

async function show() {
  message.value = ''
  if (!props.code.codeStored) {
    message.value = t('admin.signupCodes.notStored')
    return
  }
  loading.value = true
  try {
    plain.value = await signupCodesApi.show(props.code.id)
  } catch (failure) {
    message.value =
      failure instanceof ApiError && failure.code === 'SIGNUP_CODE_NOT_STORED'
        ? t('admin.signupCodes.notStored')
        : t('admin.signupCodes.showFailed')
  } finally {
    loading.value = false
  }
}

async function copy() {
  try {
    await navigator.clipboard.writeText(plain.value)
    success(t('admin.signupCodes.copied'))
  } catch {
    error(t('admin.signupCodes.copyFailed'))
  }
}
</script>
