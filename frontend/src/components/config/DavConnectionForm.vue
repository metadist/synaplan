<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { Icon } from '@iconify/vue'
import { useNotification } from '@/composables/useNotification'
import { connectionsApi } from '@/services/api/connectionsApi'

const emit = defineEmits<{ created: [] }>()

const { t } = useI18n()
const { success, error: showError } = useNotification()

const open = ref(false)
const kind = ref<'nextcloud' | 'webdav'>('nextcloud')
const serverUrl = ref('')
const davUrl = ref('')
const username = ref('')
const appPassword = ref('')
const folder = ref('Synaplan')
const withCalendar = ref(true)
const calendar = ref('personal')
const submitting = ref(false)

const normalizedServer = computed(() => serverUrl.value.trim().replace(/\/+$/, ''))

const filesBaseUrl = computed(() =>
  kind.value === 'nextcloud'
    ? `${normalizedServer.value}/remote.php/dav/files/${encodeURIComponent(username.value.trim())}`
    : davUrl.value.trim().replace(/\/+$/, '')
)

const calendarBaseUrl = computed(
  () =>
    `${normalizedServer.value}/remote.php/dav/calendars/${encodeURIComponent(
      username.value.trim()
    )}/${encodeURIComponent(calendar.value.trim())}`
)

const host = computed(() => {
  try {
    return new URL(filesBaseUrl.value).hostname
  } catch {
    return ''
  }
})

const canSubmit = computed(() => {
  if (!username.value.trim() || !appPassword.value) return false
  const base = kind.value === 'nextcloud' ? normalizedServer.value : filesBaseUrl.value
  return base.startsWith('https://')
})

const reset = () => {
  open.value = false
  serverUrl.value = ''
  davUrl.value = ''
  username.value = ''
  appPassword.value = ''
  folder.value = 'Synaplan'
  withCalendar.value = true
  calendar.value = 'personal'
}

/** Create the connection, then immediately prove it works with a live test. */
const createAndTest = async (payload: {
  name: string
  type: string
  secret: string
  config: Record<string, unknown>
}) => {
  const created = await connectionsApi.create(payload)
  const result = await connectionsApi.test(created.id)
  if (result.succeeded === false) {
    showError(result.error || t('config.connections.testFailed'))
    return
  }
  success(t('config.connections.providers.dav.created', { name: payload.name }))
}

const submit = async () => {
  if (!canSubmit.value || submitting.value) return
  submitting.value = true
  try {
    await createAndTest({
      name: t('config.connections.providers.dav.folderName', {
        host: host.value,
        user: username.value.trim(),
      }),
      type: 'webdav',
      secret: appPassword.value,
      config: {
        base_url: filesBaseUrl.value,
        username: username.value.trim(),
        folder: folder.value.trim() || 'Synaplan',
        on_conflict: 'rename',
      },
    })

    if (kind.value === 'nextcloud' && withCalendar.value && calendar.value.trim()) {
      await createAndTest({
        name: t('config.connections.providers.dav.calendarName', {
          host: host.value,
          calendar: calendar.value.trim(),
        }),
        type: 'caldav',
        secret: appPassword.value,
        config: {
          base_url: calendarBaseUrl.value,
          username: username.value.trim(),
        },
      })
    }

    emit('created')
    reset()
  } catch {
    showError(t('config.connections.providers.dav.createFailed'))
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <li class="surface-card p-4 space-y-3" data-testid="provider-dav">
    <div class="flex flex-wrap items-start gap-3">
      <Icon icon="heroicons:folder-arrow-down" class="w-6 h-6 text-[var(--brand)] mt-0.5" />
      <div class="flex-1 min-w-0">
        <p class="font-medium txt-primary">{{ $t('config.connections.providers.dav.name') }}</p>
        <p class="text-sm txt-secondary mt-0.5">
          {{ $t('config.connections.providers.dav.description') }}
        </p>
        <p class="text-xs txt-secondary mt-1 flex items-center gap-1">
          <Icon icon="heroicons:shield-check" class="w-3.5 h-3.5 flex-shrink-0" />
          {{ $t('config.connections.providers.dav.hosting') }}
        </p>
      </div>
      <button
        v-if="!open"
        type="button"
        class="btn-primary inline-flex items-center px-4 py-2 rounded-lg text-sm font-medium whitespace-nowrap"
        data-testid="btn-open-dav-form"
        @click="open = true"
      >
        {{ $t('config.connections.providers.dav.action') }}
      </button>
    </div>

    <form v-if="open" class="space-y-3" data-testid="dav-form" @submit.prevent="submit">
      <div class="flex gap-2">
        <label
          v-for="option in ['nextcloud', 'webdav'] as const"
          :key="option"
          class="flex items-center gap-2 text-sm txt-primary"
        >
          <input v-model="kind" type="radio" :value="option" class="accent-[var(--brand)]" />
          {{ $t(`config.connections.providers.dav.kind.${option}`) }}
        </label>
      </div>

      <label v-if="kind === 'nextcloud'" class="block text-sm">
        <span class="txt-secondary">{{ $t('config.connections.providers.dav.serverUrl') }}</span>
        <input
          v-model="serverUrl"
          type="url"
          required
          placeholder="https://cloud.example.com"
          class="mt-1 w-full px-3 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
          data-testid="dav-server-url"
        />
      </label>
      <label v-else class="block text-sm">
        <span class="txt-secondary">{{ $t('config.connections.providers.dav.davUrl') }}</span>
        <input
          v-model="davUrl"
          type="url"
          required
          placeholder="https://cloud.example.com/remote.php/dav/files/USERNAME"
          class="mt-1 w-full px-3 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
          data-testid="dav-dav-url"
        />
      </label>

      <div class="grid sm:grid-cols-2 gap-3">
        <label class="block text-sm">
          <span class="txt-secondary">{{ $t('config.connections.providers.dav.username') }}</span>
          <input
            v-model="username"
            type="text"
            required
            autocomplete="off"
            class="mt-1 w-full px-3 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
            data-testid="dav-username"
          />
        </label>
        <label class="block text-sm">
          <span class="txt-secondary">{{
            $t('config.connections.providers.dav.appPassword')
          }}</span>
          <input
            v-model="appPassword"
            type="password"
            required
            autocomplete="new-password"
            class="mt-1 w-full px-3 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
            data-testid="dav-app-password"
          />
        </label>
      </div>
      <p class="text-xs txt-secondary">
        {{ $t('config.connections.providers.dav.appPasswordHint') }}
      </p>

      <label class="block text-sm">
        <span class="txt-secondary">{{ $t('config.connections.providers.dav.folder') }}</span>
        <input
          v-model="folder"
          type="text"
          class="mt-1 w-full px-3 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
          data-testid="dav-folder"
        />
      </label>

      <div v-if="kind === 'nextcloud'" class="flex flex-wrap items-center gap-3">
        <label class="flex items-center gap-2 text-sm txt-primary">
          <input
            v-model="withCalendar"
            type="checkbox"
            class="accent-[var(--brand)]"
            data-testid="dav-with-calendar"
          />
          {{ $t('config.connections.providers.dav.withCalendar') }}
        </label>
        <input
          v-if="withCalendar"
          v-model="calendar"
          type="text"
          class="px-3 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
          data-testid="dav-calendar"
        />
      </div>

      <div class="flex gap-2">
        <button
          type="submit"
          class="btn-primary inline-flex items-center px-4 py-2 rounded-lg text-sm font-medium"
          :disabled="!canSubmit || submitting"
          data-testid="btn-dav-submit"
        >
          {{
            submitting
              ? $t('config.connections.providers.dav.connecting')
              : $t('config.connections.providers.dav.connect')
          }}
        </button>
        <button
          type="button"
          class="btn-secondary inline-flex items-center px-4 py-2 rounded-lg text-sm font-medium"
          @click="reset"
        >
          {{ $t('common.cancel') }}
        </button>
      </div>
    </form>
  </li>
</template>
