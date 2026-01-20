<template>
  <Teleport to="body">
    <div
      class="fixed inset-0 z-50 bg-black/50 backdrop-blur-sm flex items-center justify-center p-4"
      data-testid="modal-simple-widget-form"
      @click.self="handleClose"
    >
      <div
        class="surface-card rounded-2xl w-full max-w-lg overflow-hidden shadow-2xl"
        data-testid="section-form-container"
      >
        <!-- Header -->
        <div
          class="px-6 py-4 border-b border-light-border/30 dark:border-dark-border/20 flex items-center justify-between"
        >
          <div>
            <h2 class="text-xl font-semibold txt-primary flex items-center gap-2">
              <Icon icon="heroicons:sparkles" class="w-6 h-6 txt-brand" />
              {{ $t('widgets.simpleSetup.title') }}
            </h2>
            <p class="text-sm txt-secondary mt-1">
              {{ $t('widgets.simpleSetup.subtitle') }}
            </p>
          </div>
          <button
            class="w-10 h-10 rounded-lg hover-surface transition-colors flex items-center justify-center"
            :aria-label="$t('common.close')"
            data-testid="btn-close"
            @click="handleClose"
          >
            <Icon icon="heroicons:x-mark" class="w-6 h-6 txt-secondary" />
          </button>
        </div>

        <!-- Form Content -->
        <form class="p-6 space-y-5" @submit.prevent="handleSubmit">
          <!-- Widget Name -->
          <div>
            <label class="block text-sm font-medium txt-primary mb-2">
              {{ $t('widgets.simpleSetup.nameLabel') }} *
            </label>
            <input
              v-model="formData.name"
              type="text"
              :placeholder="$t('widgets.simpleSetup.namePlaceholder')"
              class="w-full px-4 py-3 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)] transition-all"
              data-testid="input-widget-name"
              required
            />
          </div>

          <!-- Website URL -->
          <div>
            <label class="block text-sm font-medium txt-primary mb-2">
              {{ $t('widgets.simpleSetup.websiteLabel') }} *
            </label>
            <div class="relative">
              <Icon
                icon="heroicons:globe-alt"
                class="absolute left-4 top-1/2 -translate-y-1/2 w-5 h-5 txt-secondary"
              />
              <input
                v-model="formData.websiteUrl"
                type="url"
                :placeholder="$t('widgets.simpleSetup.websitePlaceholder')"
                class="w-full pl-12 pr-4 py-3 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)] transition-all"
                data-testid="input-website-url"
                required
              />
            </div>
            <p class="text-xs txt-secondary mt-1.5 flex items-start gap-1">
              <Icon icon="heroicons:information-circle" class="w-4 h-4 flex-shrink-0 mt-0.5" />
              <span>{{ $t('widgets.simpleSetup.websiteHelp') }}</span>
            </p>
          </div>

          <!-- Info Box -->
          <div class="p-4 rounded-lg bg-[var(--brand-alpha-light)] border border-[var(--brand)]/20">
            <div class="flex items-start gap-3">
              <Icon icon="heroicons:light-bulb" class="w-5 h-5 txt-brand flex-shrink-0 mt-0.5" />
              <div class="text-sm">
                <p class="txt-primary font-medium mb-1">
                  {{ $t('widgets.simpleSetup.infoTitle') }}
                </p>
                <p class="txt-secondary">
                  {{ $t('widgets.simpleSetup.infoDescription') }}
                </p>
              </div>
            </div>
          </div>

          <!-- Error Message -->
          <div
            v-if="errorMessage"
            class="p-4 rounded-lg bg-red-500/10 border border-red-500/20 text-red-600 dark:text-red-400 text-sm flex items-start gap-2"
          >
            <Icon icon="heroicons:exclamation-circle" class="w-5 h-5 flex-shrink-0 mt-0.5" />
            <span>{{ errorMessage }}</span>
          </div>

          <!-- Actions -->
          <div class="flex items-center justify-end gap-3 pt-2">
            <button
              type="button"
              class="px-5 py-2.5 rounded-lg hover-surface transition-colors txt-secondary font-medium"
              data-testid="btn-cancel"
              @click="handleClose"
            >
              {{ $t('common.cancel') }}
            </button>
            <button
              type="submit"
              :disabled="!isValid || creating"
              class="btn-primary px-6 py-2.5 rounded-lg transition-colors font-medium disabled:opacity-50 disabled:cursor-not-allowed flex items-center gap-2"
              data-testid="btn-create"
            >
              <Icon v-if="creating" icon="heroicons:arrow-path" class="w-5 h-5 animate-spin" />
              <Icon v-else icon="heroicons:rocket-launch" class="w-5 h-5" />
              {{ creating ? $t('common.creating') : $t('widgets.simpleSetup.createButton') }}
            </button>
          </div>
        </form>
      </div>
    </div>
  </Teleport>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import { Icon } from '@iconify/vue'
import * as widgetsApi from '@/services/api/widgetsApi'
import { useNotification } from '@/composables/useNotification'
import { useI18n } from 'vue-i18n'

// Disable attribute inheritance since we use Teleport as root
defineOptions({
  inheritAttrs: false,
})

const emit = defineEmits<{
  close: []
  created: [widget: widgetsApi.Widget]
}>()

const { error: showError } = useNotification()
const { t } = useI18n()

const formData = ref({
  name: '',
  websiteUrl: '',
})

const creating = ref(false)
const errorMessage = ref<string | null>(null)

const isValid = computed(() => {
  return formData.value.name.trim().length >= 2 && formData.value.websiteUrl.trim().length > 0
})

const handleClose = () => {
  emit('close')
}

const handleSubmit = async () => {
  if (!isValid.value || creating.value) return

  creating.value = true
  errorMessage.value = null

  try {
    const widget = await widgetsApi.quickCreateWidget({
      name: formData.value.name.trim(),
      websiteUrl: formData.value.websiteUrl.trim(),
    })

    emit('created', widget)
  } catch (err: any) {
    console.error('Failed to create widget:', err)
    errorMessage.value = err.message || t('widgets.simpleSetup.createError')
    showError(err.message || t('widgets.simpleSetup.createError'))
  } finally {
    creating.value = false
  }
}
</script>
