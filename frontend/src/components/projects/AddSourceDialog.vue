<template>
  <Teleport :to="teleportTarget">
    <div
      v-if="open"
      class="modal-overlay fixed inset-0 bg-black/50 z-[10000] flex items-center justify-center p-2 sm:p-4"
      data-testid="modal-project-add-source"
      @click.self="close"
    >
      <div
        class="modal-panel surface-card rounded-2xl shadow-2xl max-w-lg w-full overflow-y-auto scroll-thin"
        role="dialog"
        aria-modal="true"
        :aria-labelledby="titleId"
        @click.stop
        @keydown.esc="close"
      >
        <div
          class="flex items-center justify-between p-4 sm:p-6 border-b border-light-border/10 dark:border-dark-border/10"
        >
          <h3 :id="titleId" class="text-base sm:text-lg font-semibold txt-primary">
            {{ $t('projects.addSource.title') }}
          </h3>
          <button
            type="button"
            class="icon-ghost inline-flex h-9 w-9 items-center justify-center rounded-lg"
            :aria-label="$t('common.close')"
            data-testid="btn-project-add-source-close"
            @click="close"
          >
            <XMarkIcon class="h-5 w-5" aria-hidden="true" />
          </button>
        </div>

        <form class="p-4 sm:p-6 space-y-4" @submit.prevent="submit">
          <div class="flex gap-2" role="tablist" :aria-label="$t('projects.addSource.title')">
            <button
              v-for="option in kinds"
              :key="option"
              type="button"
              role="tab"
              :aria-selected="kind === option"
              :class="[
                'flex-1 px-3 py-2 rounded-xl text-sm font-medium',
                kind === option ? 'mode-toggle-active' : 'surface-chip txt-secondary',
              ]"
              :data-testid="`tab-project-add-source-${option}`"
              @click="kind = option"
            >
              {{ $t(`projects.addSource.kind.${option}`) }}
            </button>
          </div>

          <div v-if="kind === 'file'" class="space-y-2">
            <label class="block text-sm font-medium txt-primary" :for="fileId">
              {{ $t('projects.addSource.fileLabel') }}
            </label>
            <input
              :id="fileId"
              ref="fileInput"
              type="file"
              multiple
              class="w-full px-3 py-2 rounded-xl surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
              data-testid="input-project-source-file"
              @change="onFiles"
            />
            <p class="text-xs txt-secondary">{{ $t('projects.addSource.fileHint') }}</p>
          </div>

          <div v-else-if="kind === 'link'" class="space-y-2">
            <label class="block text-sm font-medium txt-primary" :for="urlId">
              {{ $t('projects.addSource.linkLabel') }}
            </label>
            <input
              :id="urlId"
              v-model="url"
              type="url"
              inputmode="url"
              autocomplete="off"
              maxlength="2048"
              placeholder="https://"
              class="w-full px-3 py-2 rounded-xl surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
              data-testid="input-project-source-url"
            />
            <p class="text-xs txt-secondary">{{ $t('projects.addSource.linkHint') }}</p>
          </div>

          <div v-else class="space-y-3">
            <div class="space-y-2">
              <label class="block text-sm font-medium txt-primary" :for="textTitleId">
                {{ $t('projects.addSource.textTitleLabel') }}
              </label>
              <input
                :id="textTitleId"
                v-model="textTitle"
                type="text"
                maxlength="200"
                autocomplete="off"
                class="w-full px-3 py-2 rounded-xl surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
                data-testid="input-project-source-text-title"
              />
            </div>
            <div class="space-y-2">
              <label class="block text-sm font-medium txt-primary" :for="textBodyId">
                {{ $t('projects.addSource.textLabel') }}
              </label>
              <textarea
                :id="textBodyId"
                v-model="textBody"
                rows="6"
                class="w-full px-3 py-2 rounded-xl surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
                data-testid="input-project-source-text"
              ></textarea>
            </div>
          </div>

          <p
            v-if="errorMessage"
            class="text-sm text-red-600 dark:text-red-400"
            role="alert"
            data-testid="text-project-add-source-error"
          >
            {{ errorMessage }}
          </p>

          <div class="flex justify-end gap-2 pt-2">
            <button
              type="button"
              class="btn-secondary px-4 py-2.5 text-sm font-medium"
              data-testid="btn-project-add-source-cancel"
              @click="close"
            >
              {{ $t('common.cancel') }}
            </button>
            <button
              type="submit"
              class="btn-primary px-4 py-2.5 text-sm font-medium"
              :disabled="!canSubmit || busy"
              data-testid="btn-project-add-source-submit"
            >
              {{ busy ? $t('projects.addSource.adding') : $t('projects.addSource.submit') }}
            </button>
          </div>
        </form>
      </div>
    </div>
  </Teleport>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { XMarkIcon } from '@heroicons/vue/24/outline'
import { useFullscreenTeleportTarget } from '@/composables/useFullscreenTeleportTarget'
import { projectsApi, type AddSourcesResult } from '@/services/api/projectsApi'
import { ApiError } from '@/services/api/httpClient'

type Kind = 'file' | 'link' | 'text'

const props = defineProps<{
  open: boolean
  projectId: string
}>()

const emit = defineEmits<{
  close: []
  added: [result: AddSourcesResult]
}>()

const { t } = useI18n()
const { teleportTarget } = useFullscreenTeleportTarget()

const kinds: Kind[] = ['file', 'link', 'text']
const uid = Math.random().toString(36).slice(2, 8)
const titleId = `project-add-source-title-${uid}`
const fileId = `project-add-source-file-${uid}`
const urlId = `project-add-source-url-${uid}`
const textTitleId = `project-add-source-text-title-${uid}`
const textBodyId = `project-add-source-text-body-${uid}`

const kind = ref<Kind>('file')
const files = ref<File[]>([])
const url = ref('')
const textTitle = ref('')
const textBody = ref('')
const busy = ref(false)
const errorMessage = ref('')
const fileInput = ref<HTMLInputElement | null>(null)

const canSubmit = computed(() => {
  if (kind.value === 'file') return files.value.length > 0
  if (kind.value === 'link') return /^https?:\/\/\S+$/i.test(url.value.trim())
  return textTitle.value.trim() !== '' && textBody.value.trim() !== ''
})

function reset(): void {
  kind.value = 'file'
  files.value = []
  url.value = ''
  textTitle.value = ''
  textBody.value = ''
  errorMessage.value = ''
  busy.value = false
  if (fileInput.value) fileInput.value.value = ''
}

watch(
  () => props.open,
  (isOpen) => {
    if (isOpen) reset()
  }
)

function onFiles(event: Event): void {
  const input = event.target as HTMLInputElement
  files.value = Array.from(input.files ?? [])
}

function close(): void {
  if (!busy.value) emit('close')
}

async function submit(): Promise<void> {
  if (!canSubmit.value || busy.value) return
  busy.value = true
  errorMessage.value = ''
  try {
    let result: AddSourcesResult
    if (kind.value === 'file') {
      result = await projectsApi.addFiles(props.projectId, files.value)
    } else if (kind.value === 'link') {
      result = await projectsApi.addSource(props.projectId, {
        type: 'link',
        url: url.value.trim(),
      })
    } else {
      result = await projectsApi.addSource(props.projectId, {
        type: 'text',
        title: textTitle.value.trim(),
        text: textBody.value,
      })
    }
    emit('added', result)
  } catch (error) {
    errorMessage.value = errorText(error)
  } finally {
    busy.value = false
  }
}

function errorText(error: unknown): string {
  if (error instanceof ApiError) {
    if (error.status === 429) return t('projects.addSource.errorRate')
    if (error.status === 409) return error.message || t('projects.addSource.errorLimit')
    if (error.status === 422) return error.message || t('projects.addSource.errorInvalid')
  }
  return t('projects.addSource.errorGeneric')
}
</script>
