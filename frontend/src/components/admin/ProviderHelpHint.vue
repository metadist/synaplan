<template>
  <span class="inline-flex items-center relative">
    <button
      type="button"
      class="icon-ghost w-7 h-7 flex items-center justify-center rounded-lg shrink-0"
      :aria-label="$t('providerHelp.openAria')"
      :data-testid="`provider-help-${helpId}`"
      @click="open = true"
    >
      <Icon icon="mdi:help-circle-outline" class="w-5 h-5" />
    </button>

    <Teleport to="body">
      <Transition name="help-fade">
        <div
          v-if="open"
          class="fixed inset-0 z-[10000] flex items-center justify-center p-4"
          data-testid="provider-help-overlay"
          @click.self="open = false"
        >
          <div class="absolute inset-0 bg-black/40 dark:bg-black/60 backdrop-blur-[2px]" />
          <div
            class="relative surface-card w-full max-w-sm rounded-xl shadow-2xl p-5 space-y-4"
            role="dialog"
            aria-modal="true"
            :aria-labelledby="titleId"
            data-testid="provider-help-dialog"
            @click.stop
          >
            <div class="flex items-start gap-3">
              <div
                class="flex-shrink-0 w-10 h-10 rounded-full bg-[var(--brand)]/10 text-[var(--brand)] flex items-center justify-center"
              >
                <Icon
                  :icon="isDownload ? 'mdi:download-outline' : 'mdi:key-variant'"
                  class="w-5 h-5"
                />
              </div>
              <div class="min-w-0 flex-1">
                <h3 :id="titleId" class="text-base font-semibold txt-primary">
                  {{ title }}
                </h3>
                <p class="text-sm txt-secondary mt-1.5 leading-relaxed">
                  {{ message }}
                </p>
              </div>
              <button
                type="button"
                class="icon-ghost w-7 h-7 flex items-center justify-center rounded-lg shrink-0"
                :aria-label="$t('common.close')"
                data-testid="provider-help-close"
                @click="open = false"
              >
                <Icon icon="mdi:close" class="w-4 h-4" />
              </button>
            </div>

            <div class="flex flex-wrap gap-2 justify-end pt-1">
              <button type="button" class="btn-secondary text-sm px-3 py-2" @click="open = false">
                {{ $t('common.close') }}
              </button>
              <a
                :href="url"
                target="_blank"
                rel="noopener noreferrer"
                class="btn-primary text-sm px-3 py-2 inline-flex items-center gap-1.5"
                data-testid="provider-help-link"
                @click="open = false"
              >
                <Icon icon="mdi:open-in-new" class="w-4 h-4" />
                {{ linkLabel }}
              </a>
            </div>
          </div>
        </div>
      </Transition>
    </Teleport>
  </span>
</template>

<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { Icon } from '@iconify/vue'
import { useI18n } from 'vue-i18n'
import type { ProviderHelpId } from '@/utils/providerHelp'

const props = defineProps<{
  helpId: ProviderHelpId
  /** Override URL (e.g. consoleUrl from the API). Falls back to catalog default via parent. */
  url: string
  isDownload?: boolean
}>()

const { t } = useI18n()
const open = ref(false)
const titleId = `provider-help-title-${props.helpId}`

const title = computed(() => t(`providerHelp.${props.helpId}.title`))
const message = computed(() => t(`providerHelp.${props.helpId}.message`))
const linkLabel = computed(() =>
  props.isDownload ? t('providerHelp.downloadLink') : t('providerHelp.createKeyLink')
)

const onKeydown = (e: KeyboardEvent) => {
  if (e.key === 'Escape' && open.value) {
    open.value = false
  }
}

onMounted(() => document.addEventListener('keydown', onKeydown))
onUnmounted(() => document.removeEventListener('keydown', onKeydown))
</script>

<style scoped>
.help-fade-enter-active,
.help-fade-leave-active {
  transition: opacity 0.15s ease;
}
.help-fade-enter-from,
.help-fade-leave-to {
  opacity: 0;
}
</style>
