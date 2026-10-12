<template>
  <MainLayout>
    <div class="min-h-screen bg-chat px-3 py-4 sm:p-4 md:p-8">
      <div class="mx-auto w-full max-w-[100rem]">
        <section class="surface-card p-6 space-y-3" data-testid="partners-join">
          <h1 class="text-2xl font-semibold txt-primary">{{ $t('partners.joinTitle') }}</h1>
          <p v-if="preview" class="txt-primary text-sm">
            {{ $t('partners.joinBody', { name: preview.name, domain: preview.domain }) }}
          </p>
          <p v-else-if="invalid" class="txt-primary text-sm">{{ $t('partners.joinInvalid') }}</p>
          <p v-else class="txt-secondary text-sm">{{ $t('common.loading') }}</p>
          <p v-if="preview" class="txt-primary text-sm break-all">{{ preview.pasteUrl }}</p>
          <button
            v-if="preview"
            type="button"
            class="btn-primary px-4 py-2.5 rounded-xl text-sm font-medium"
            @click="copy"
          >
            {{ $t('partners.copy') }}
          </button>
        </section>
      </div>
    </div>
  </MainLayout>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import MainLayout from '@/components/MainLayout.vue'
import { useNotification } from '@/composables/useNotification'
import { partnersApi, type PartnerInvitePreview } from '@/services/api/partnersApi'

const { t } = useI18n()
const route = useRoute()
const { success } = useNotification()
const preview = ref<PartnerInvitePreview | null>(null)
const invalid = ref(false)

onMounted(async () => {
  const token = String(route.params.token ?? '')
  try {
    preview.value = await partnersApi.preview(token)
  } catch {
    invalid.value = true
  }
})

async function copy(): Promise<void> {
  if (!preview.value) return
  await navigator.clipboard.writeText(preview.value.pasteUrl)
  success(t('partners.copied'))
}
</script>
