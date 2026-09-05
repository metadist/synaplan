<template>
  <div data-testid="section-users">
    <div class="surface-card rounded-lg p-4 mb-6">
      <div class="flex gap-4 flex-wrap">
        <div class="flex-1 min-w-[300px]">
          <input
            v-model="userSearch"
            type="text"
            :placeholder="$t('admin.users.searchPlaceholder')"
            class="w-full px-4 py-2.5 rounded-lg bg-chat border border-light-border/30 dark:border-dark-border/20 txt-primary focus:ring-2 focus:ring-[var(--brand)] focus:outline-none"
            data-testid="input-user-search"
            @input="debouncedSearchUsers"
          />
        </div>
        <button
          class="btn-secondary px-6 py-2.5 rounded-lg font-medium"
          data-testid="btn-refresh-users"
          @click="loadUsers()"
        >
          <Icon icon="mdi:refresh" class="w-5 h-5" />
        </button>
      </div>
    </div>

    <div class="surface-card rounded-lg p-6">
      <div v-if="usersLoading" class="text-center py-12">
        <Icon icon="mdi:loading" class="w-8 h-8 animate-spin mx-auto txt-secondary" />
      </div>
      <div
        v-else-if="users.length === 0"
        class="text-center py-12 txt-secondary"
        data-testid="admin-users-empty"
      >
        {{ $t('admin.users.noUsers') }}
      </div>
      <div v-else>
        <div class="overflow-x-auto">
          <table class="w-full">
            <thead>
              <tr class="border-b border-light-border/30 dark:border-dark-border/20">
                <th class="text-left py-2 px-4 text-sm font-medium txt-secondary">ID</th>
                <th class="text-left py-2 px-4 text-sm font-medium txt-secondary">
                  {{ $t('admin.users.email') }}
                </th>
                <th class="text-left py-2 px-4 text-sm font-medium txt-secondary">
                  {{ $t('admin.users.level') }}
                </th>
                <th class="text-left py-2 px-4 text-sm font-medium txt-secondary">
                  {{ $t('admin.users.type') }}
                </th>
                <th class="text-left py-2 px-4 text-sm font-medium txt-secondary">
                  {{ $t('admin.users.provider') }}
                </th>
                <th
                  v-if="showIamColumns"
                  class="text-left py-2 px-4 text-sm font-medium txt-secondary"
                >
                  {{ $t('people.users.groups') }}
                </th>
                <th
                  v-if="showIamColumns"
                  class="text-left py-2 px-4 text-sm font-medium txt-secondary"
                >
                  {{ $t('people.users.identities') }}
                </th>
                <th class="text-left py-2 px-4 text-sm font-medium txt-secondary">
                  {{ $t('admin.users.created') }}
                </th>
                <th class="text-right py-2 px-4 text-sm font-medium txt-secondary">
                  {{ $t('admin.users.actions') }}
                </th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="user in users"
                :key="user.id"
                class="border-b border-light-border/30 dark:border-dark-border/20 hover:bg-black/5 dark:hover:bg-white/5"
              >
                <td class="py-3 px-4 txt-secondary text-sm">#{{ user.id }}</td>
                <td class="py-3 px-4">
                  <div class="flex items-center gap-2">
                    <span class="txt-primary">{{ user.email || 'N/A' }}</span>
                    <Icon
                      v-if="user.emailVerified"
                      icon="mdi:check-decagram"
                      class="w-4 h-4 text-green-500"
                      :title="$t('admin.users.verified')"
                    />
                  </div>
                </td>
                <td class="py-3 px-4">
                  <select
                    :value="user.level"
                    class="px-3 py-1.5 rounded-lg bg-chat border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:ring-2 focus:ring-[var(--brand)] focus:outline-none"
                    :disabled="user.id === currentUserId"
                    :data-testid="`select-user-level-${user.id}`"
                    @change="updateUserLevel(user.id, ($event.target as HTMLSelectElement).value)"
                  >
                    <option value="ANONYMOUS">ANONYMOUS</option>
                    <option value="NEW">NEW</option>
                    <option value="PRO">PRO</option>
                    <option value="TEAM">TEAM</option>
                    <option value="BUSINESS">BUSINESS</option>
                    <option value="ADMIN">ADMIN</option>
                  </select>
                </td>
                <td class="py-3 px-4 txt-secondary text-sm">{{ user.type }}</td>
                <td class="py-3 px-4 txt-secondary text-sm">{{ user.providerId }}</td>
                <td v-if="showIamColumns" class="py-3 px-4">
                  <div class="flex flex-wrap gap-1">
                    <span v-for="group in user.groups ?? []" :key="group.id" class="pill text-xs">
                      {{ group.name }}
                    </span>
                    <span v-if="!(user.groups && user.groups.length)" class="txt-secondary text-sm"
                      >—</span
                    >
                  </div>
                </td>
                <td v-if="showIamColumns" class="py-3 px-4">
                  <div class="flex flex-wrap gap-1">
                    <span v-for="badge in user.identities ?? []" :key="badge" class="pill text-xs">
                      {{ badge }}
                    </span>
                    <span
                      v-if="!(user.identities && user.identities.length)"
                      class="txt-secondary text-sm"
                      >—</span
                    >
                  </div>
                </td>
                <td class="py-3 px-4 txt-secondary text-sm">
                  {{ formatDate(user.created) }}
                </td>
                <td class="py-3 px-4 text-right">
                  <div v-if="user.id !== currentUserId" class="flex items-center justify-end gap-1">
                    <button
                      class="p-2 rounded-lg text-amber-600 hover:text-amber-700 dark:text-amber-400 dark:hover:text-amber-300 hover:bg-amber-50 dark:hover:bg-amber-900/20"
                      :title="$t('admin.impersonate.buttonTitle')"
                      :data-testid="`btn-impersonate-user-${user.id}`"
                      @click="confirmImpersonate(user)"
                    >
                      <Icon icon="mdi:incognito" class="w-5 h-5" />
                    </button>
                    <button
                      class="text-red-500 hover:text-red-600 dark:text-red-400 dark:hover:text-red-300 p-2 rounded-lg hover:bg-red-50 dark:hover:bg-red-900/20"
                      :title="$t('admin.users.delete')"
                      :data-testid="`btn-delete-user-${user.id}`"
                      @click="confirmDeleteUser(user)"
                    >
                      <Icon icon="mdi:delete" class="w-5 h-5" />
                    </button>
                  </div>
                  <span v-else class="text-sm txt-secondary italic">{{
                    $t('admin.users.currentUser')
                  }}</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div v-if="totalPages > 1" class="flex justify-center gap-2 mt-6">
          <button
            :disabled="currentPage === 1"
            class="btn-secondary px-4 py-2 rounded-lg disabled:opacity-50"
            data-testid="btn-prev-page"
            @click="currentPage = Math.max(1, currentPage - 1)"
          >
            <Icon icon="mdi:chevron-left" class="w-5 h-5" />
          </button>
          <span class="px-4 py-2 txt-primary">{{ currentPage }} / {{ totalPages }}</span>
          <button
            :disabled="currentPage === totalPages"
            class="btn-secondary px-4 py-2 rounded-lg disabled:opacity-50"
            data-testid="btn-next-page"
            @click="currentPage = Math.min(totalPages, currentPage + 1)"
          >
            <Icon icon="mdi:chevron-right" class="w-5 h-5" />
          </button>
        </div>
      </div>
    </div>

    <Teleport to="#app">
      <Transition name="modal">
        <div
          v-if="showDeleteModal"
          class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm"
          data-testid="modal-delete-user"
          @click.self="closeDeleteModal()"
        >
          <div
            class="surface-elevated w-full max-w-md p-6 m-4"
            data-testid="modal-delete-user-content"
          >
            <div class="flex items-center justify-center mb-4">
              <Icon icon="mdi:alert-circle-outline" class="w-12 h-12 text-red-500" />
            </div>
            <h3 class="text-xl font-bold text-center txt-primary mb-2">
              {{ $t('admin.users.deleteConfirmTitle') }}
            </h3>
            <p class="text-center txt-secondary mb-4">
              {{ $t('admin.users.deleteConfirmDesc', { email: userToDelete?.email }) }}
            </p>
            <label
              class="flex items-start gap-3 p-4 rounded-lg bg-red-50 dark:bg-red-900/10 border border-red-200 dark:border-red-800/30 mb-6 cursor-pointer hover:bg-red-100 dark:hover:bg-red-900/20 transition-colors"
              data-testid="label-delete-confirm"
            >
              <input
                v-model="deleteConfirmed"
                type="checkbox"
                class="mt-1 w-4 h-4 text-red-600 rounded border-red-300 focus:ring-red-500 dark:border-red-700 dark:bg-red-900/30 cursor-pointer"
                data-testid="checkbox-delete-confirm"
              />
              <span class="text-sm txt-secondary leading-relaxed">
                {{ $t('admin.users.deleteConfirmCheckbox') }}
              </span>
            </label>
            <div class="flex justify-end gap-3">
              <button
                class="btn-secondary py-2 px-4 rounded-lg"
                data-testid="btn-cancel-delete-user"
                @click="closeDeleteModal()"
              >
                {{ $t('common.cancel') }}
              </button>
              <button
                :disabled="!deleteConfirmed"
                class="btn-danger py-2 px-4 rounded-lg disabled:opacity-50 disabled:cursor-not-allowed"
                data-testid="btn-confirm-delete-user"
                @click="deleteUser()"
              >
                {{ $t('common.delete') }}
              </button>
            </div>
          </div>
        </div>
      </Transition>
    </Teleport>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import { Icon } from '@iconify/vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { adminApi, type AdminUser } from '@/services/api/adminApi'
import { useAuthStore } from '@/stores/auth'
import { useDateFormat } from '@/composables/useDateFormat'
import { useDialog } from '@/composables/useDialog'
import { useNotification } from '@/composables/useNotification'
import { useEscapeKey } from '@/composables/useEscapeKey'

withDefaults(
  defineProps<{
    showIamColumns?: boolean
  }>(),
  { showIamColumns: false }
)

const { t } = useI18n()
const { formatDateTime } = useDateFormat()
const authStore = useAuthStore()
const { success, error: showError } = useNotification()
const { confirm } = useDialog()
const router = useRouter()

const users = ref<AdminUser[]>([])
const usersLoading = ref(false)
const userSearch = ref('')
const currentPage = ref(1)
const itemsPerPage = ref(50)
const totalUsers = ref(0)
const totalPages = computed(() => Math.ceil(totalUsers.value / itemsPerPage.value))
const currentUserId = computed(() => authStore.user?.id)

const showDeleteModal = ref(false)
const userToDelete = ref<AdminUser | null>(null)
const deleteConfirmed = ref(false)

watch(currentPage, () => {
  loadUsers()
})

async function loadUsers() {
  usersLoading.value = true
  try {
    const response = await adminApi.getUsers(
      currentPage.value,
      itemsPerPage.value,
      userSearch.value
    )
    if (response && response.users) {
      users.value = response.users
      totalUsers.value = response.total
    } else {
      showError('Invalid response from server')
    }
  } catch (error) {
    showError(error instanceof Error ? error.message : 'Failed to load users')
  } finally {
    usersLoading.value = false
  }
}

let searchTimeout: ReturnType<typeof setTimeout> | null = null

function debouncedSearchUsers() {
  if (searchTimeout) {
    clearTimeout(searchTimeout)
  }
  searchTimeout = setTimeout(() => {
    currentPage.value = 1
    loadUsers()
  }, 300)
}

async function updateUserLevel(userId: number, newLevel: string) {
  try {
    await adminApi.updateUserLevel(userId, newLevel)
    const user = users.value.find((u: AdminUser) => u.id === userId)
    if (user) {
      user.level = newLevel as 'NEW' | 'PRO' | 'TEAM' | 'BUSINESS' | 'ADMIN'
    }
    success(t('admin.users.levelUpdated', { level: newLevel }))
  } catch (error) {
    showError(error instanceof Error ? error.message : t('admin.users.levelUpdateFailed'))
  }
}

function confirmDeleteUser(user: AdminUser) {
  userToDelete.value = user
  deleteConfirmed.value = false
  showDeleteModal.value = true
}

function closeDeleteModal() {
  showDeleteModal.value = false
  userToDelete.value = null
  deleteConfirmed.value = false
}

useEscapeKey(closeDeleteModal, showDeleteModal)

async function deleteUser() {
  if (!userToDelete.value) return

  const userEmail = userToDelete.value.email

  try {
    await adminApi.deleteUser(userToDelete.value.id)
    users.value = users.value.filter((u: AdminUser) => u.id !== userToDelete.value!.id)
    totalUsers.value--
    closeDeleteModal()
    success(t('admin.users.userDeleted', { email: userEmail }))
  } catch (error) {
    showError(error instanceof Error ? error.message : t('admin.users.userDeleteFailed'))
  }
}

async function confirmImpersonate(targetUser: AdminUser) {
  const targetEmail = targetUser.email ?? `#${targetUser.id}`

  if (targetUser.id === currentUserId.value) {
    showError(t('admin.impersonate.cannotImpersonateSelf'))
    return
  }

  const confirmed = await confirm({
    title: t('admin.impersonate.confirmTitle', { email: targetEmail }),
    message: t('admin.impersonate.confirmMessage', { email: targetEmail }),
    confirmText: t('admin.impersonate.confirmAction'),
    danger: true,
  })
  if (!confirmed) return

  const result = await authStore.startImpersonation(targetUser.id)
  if (!result.success) {
    showError(result.error ?? t('admin.impersonate.startFailed'))
    return
  }

  success(t('admin.impersonate.started', { email: targetEmail }))
  await router.push('/').catch(() => {})
}

function formatDate(dateStr: string): string {
  if (!dateStr) return '—'
  try {
    const date = new Date(dateStr)
    if (isNaN(date.getTime())) return '—'
    return formatDateTime(date)
  } catch {
    return '—'
  }
}

onMounted(() => {
  loadUsers()
})

onUnmounted(() => {
  if (searchTimeout) {
    clearTimeout(searchTimeout)
    searchTimeout = null
  }
})
</script>
