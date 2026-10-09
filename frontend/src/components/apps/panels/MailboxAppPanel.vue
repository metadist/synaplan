<template>
  <div data-testid="panel-app-mailbox">
    <div v-if="loading" class="txt-secondary text-sm py-8 text-center">
      {{ $t('common.loading') }}
    </div>
    <MailHandlerList
      v-else-if="!editing"
      :handlers="handlers"
      data-testid="comp-mail-handler-list"
      @create="createHandler"
      @edit="editHandler"
      @delete="deleteHandler"
      @bulk-update-status="bulkUpdateStatus"
      @bulk-delete="bulkDelete"
    />
    <MailHandlerConfiguration
      v-else
      :handler="currentHandler"
      :handler-id="currentHandlerId"
      data-testid="comp-mail-handler-config"
      @save="saveHandler"
      @cancel="closeEditor"
    />
  </div>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import MailHandlerConfiguration from '@/components/mail/MailHandlerConfiguration.vue'
import MailHandlerList from '@/components/mail/MailHandlerList.vue'
import {
  inboundEmailHandlersApi,
  MASKED_MAIL_PASSWORD_PLACEHOLDER,
  type CreateHandlerRequest,
  type Department,
  type EmailFilterConfig,
  type MailConfig,
  type SavedMailHandler,
  type SmtpConfig,
} from '@/services/api/inboundEmailHandlersApi'
import { useNotification } from '@/composables/useNotification'
import { useDialog } from '@/composables/useDialog'
import { getErrorMessage } from '@/utils/errorMessage'

const { t } = useI18n()
const { success, error: showError, warning: showWarning } = useNotification()
const dialog = useDialog()

const handlers = ref<SavedMailHandler[]>([])
const loading = ref(true)
const editing = ref(false)
const currentHandler = ref<SavedMailHandler | undefined>(undefined)
const currentHandlerId = ref('')

async function loadHandlers(): Promise<void> {
  try {
    handlers.value = await inboundEmailHandlersApi.list()
  } catch (error: unknown) {
    showError(getErrorMessage(error) || t('apps.mailbox.loadFailed'))
  } finally {
    loading.value = false
  }
}

onMounted(loadHandlers)

function createHandler(): void {
  editing.value = true
  currentHandler.value = undefined
  currentHandlerId.value = ''
}

function editHandler(handler: SavedMailHandler): void {
  editing.value = true
  currentHandler.value = handler
  currentHandlerId.value = handler.id
}

function closeEditor(): void {
  editing.value = false
  currentHandler.value = undefined
  currentHandlerId.value = ''
}

const isNewSecret = (value: string | undefined): value is string =>
  !!value && value !== MASKED_MAIL_PASSWORD_PLACEHOLDER

async function saveHandler(
  name: string,
  config: MailConfig,
  departments: Department[],
  smtpConfig: SmtpConfig,
  emailFilter: EmailFilterConfig,
  isActive: boolean
): Promise<void> {
  if (!smtpConfig?.smtpServer || !smtpConfig.smtpUsername || !smtpConfig.smtpPassword) {
    showError(t('mail.smtpRequired'))
    return
  }

  const payload: Omit<CreateHandlerRequest, 'password' | 'smtpPassword'> & {
    password?: string
    smtpPassword?: string
    status?: 'active' | 'inactive'
  } = {
    name,
    mailServer: config.mailServer,
    port: config.port,
    protocol: config.protocol,
    security: config.security,
    username: config.username,
    checkInterval: config.checkInterval,
    deleteAfter: config.deleteAfter,
    departments,
    smtpServer: smtpConfig.smtpServer,
    smtpPort: smtpConfig.smtpPort,
    smtpSecurity: smtpConfig.smtpSecurity,
    smtpUsername: smtpConfig.smtpUsername,
    emailFilterMode: emailFilter.mode || 'new',
    emailFilterFromDate: emailFilter.fromDate || null,
  }

  const creating = !currentHandlerId.value
  if (creating) {
    if (!isNewSecret(config.password)) {
      showError(t('mail.imapPasswordRequired'))
      return
    }
    if (!isNewSecret(smtpConfig.smtpPassword)) {
      showError(t('mail.smtpPasswordRequired'))
      return
    }
  }
  // An update only sends a secret the user actually changed.
  if (isNewSecret(config.password)) payload.password = config.password
  if (isNewSecret(smtpConfig.smtpPassword)) payload.smtpPassword = smtpConfig.smtpPassword

  try {
    let savedId = currentHandlerId.value
    if (creating) {
      const created = await inboundEmailHandlersApi.create(payload as CreateHandlerRequest)
      handlers.value.push(created)
      savedId = created.id
      success(t('mail.handlerCreated'))
    } else {
      // Status only applies to updates; the backend ignores it on create.
      payload.status = isActive ? 'active' : 'inactive'
      const updated = await inboundEmailHandlersApi.update(savedId, payload)
      handlers.value = handlers.value.map((row) => (row.id === savedId ? updated : row))
      success(t('mail.handlerUpdated'))
    }

    try {
      const result = await inboundEmailHandlersApi.testConnection(savedId)
      if (result.success) {
        await loadHandlers()
      } else {
        showWarning(`${t('mail.connectionTestWarning')}: ${result.message}`)
      }
    } catch {
      showWarning(t('mail.handlerSavedTestFailed'))
    }

    closeEditor()
  } catch (error: unknown) {
    showError(getErrorMessage(error) || t('apps.mailbox.saveFailed'))
  }
}

async function deleteHandler(handlerId: string): Promise<void> {
  const handler = handlers.value.find((row) => row.id === handlerId)
  const confirmed = await dialog.confirm({
    title: t('mail.deleteHandlerConfirmTitle'),
    message: t('mail.deleteHandlerConfirmMessage', { name: handler?.name ?? '' }),
    danger: true,
    confirmText: t('common.delete'),
    cancelText: t('common.cancel'),
  })
  if (!confirmed) return

  try {
    await inboundEmailHandlersApi.delete(handlerId)
    handlers.value = handlers.value.filter((row) => row.id !== handlerId)
    success(t('mail.handlerDeleted'))
  } catch (error: unknown) {
    showError(getErrorMessage(error) || t('mail.deleteFailed'))
  }
}

async function bulkUpdateStatus(ids: string[], status: 'active' | 'inactive'): Promise<void> {
  try {
    await inboundEmailHandlersApi.bulkUpdateStatus(ids, status)
    handlers.value = handlers.value.map((row) => (ids.includes(row.id) ? { ...row, status } : row))
    success(t('mail.bulkUpdateSuccess', { count: ids.length }))
  } catch (error: unknown) {
    showError(getErrorMessage(error) || t('mail.bulkUpdateFailed'))
  }
}

async function bulkDelete(ids: string[]): Promise<void> {
  const confirmed = await dialog.confirm({
    title: t('mail.bulkDeleteConfirmTitle'),
    message: t('mail.bulkDeleteConfirmMessage', { count: ids.length }),
    danger: true,
    confirmText: t('common.delete'),
    cancelText: t('common.cancel'),
  })
  if (!confirmed) return

  try {
    await inboundEmailHandlersApi.bulkDelete(ids)
    handlers.value = handlers.value.filter((row) => !ids.includes(row.id))
    success(t('mail.bulkDeleteSuccess', { count: ids.length }))
  } catch (error: unknown) {
    showError(getErrorMessage(error) || t('mail.bulkDeleteFailed'))
  }
}
</script>
