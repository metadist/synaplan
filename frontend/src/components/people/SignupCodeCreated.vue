<template>
  <div class="space-y-4" data-testid="section-signup-code-created">
    <p class="text-sm txt-secondary">{{ $t('admin.signupCodes.shownOnce') }}</p>
    <p
      class="font-mono text-lg sm:text-xl tracking-wider txt-primary surface-chip rounded-xl px-4 py-3 text-center break-all select-all"
      data-testid="text-signup-code-plain"
    >
      {{ code }}
    </p>
    <div class="flex flex-wrap justify-end gap-2">
      <button
        ref="copyButton"
        type="button"
        class="btn-secondary px-4 py-2.5 text-sm font-medium inline-flex items-center gap-2"
        data-testid="btn-signup-code-copy"
        @click="copy"
      >
        <ClipboardDocumentIcon class="w-4 h-4" />
        {{ $t('admin.signupCodes.copy') }}
      </button>
      <button
        type="button"
        class="btn-primary px-4 py-2.5 text-sm font-medium"
        data-testid="btn-signup-code-done"
        @click="emit('done')"
      >
        {{ $t('admin.signupCodes.done') }}
      </button>
    </div>
  </div>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { ClipboardDocumentIcon } from '@heroicons/vue/24/outline'
import { useNotification } from '@/composables/useNotification'

const props = defineProps<{ code: string }>()
const emit = defineEmits<{ done: [] }>()

const { t } = useI18n()
const { success, error } = useNotification()
const copyButton = ref<HTMLButtonElement | null>(null)

onMounted(() => copyButton.value?.focus())

async function copy() {
  try {
    await navigator.clipboard.writeText(props.code)
    success(t('admin.signupCodes.copied'))
  } catch {
    error(t('admin.signupCodes.copyFailed'))
  }
}
</script>
