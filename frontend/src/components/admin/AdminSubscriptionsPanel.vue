<template>
  <div class="space-y-6" data-testid="admin-subscriptions-panel">
    <div class="surface-card p-6">
      <div class="flex items-center justify-between mb-6">
        <div>
          <h3 class="text-lg font-semibold txt-primary">
            {{ $t('admin.subscriptions.title') }}
          </h3>
          <p class="text-sm txt-secondary mt-1">
            {{ $t('admin.subscriptions.description') }}
          </p>
        </div>
      </div>

      <div v-if="loading" class="flex justify-center py-12" data-testid="loading">
        <div
          class="w-8 h-8 border-4 border-gray-300 dark:border-gray-600 border-t-[var(--brand)] rounded-full animate-spin"
        />
      </div>

      <div
        v-else-if="subscriptions.length === 0"
        class="text-center py-12 txt-secondary"
        data-testid="empty-state"
      >
        {{ $t('admin.subscriptions.noSubscriptions') }}
      </div>

      <div v-else class="overflow-x-auto">
        <table class="w-full text-sm" data-testid="subscriptions-table">
          <thead>
            <tr class="border-b border-light-border/30 dark:border-dark-border/20">
              <th class="text-left py-3 px-4 font-medium txt-secondary">
                {{ $t('admin.subscriptions.level') }}
              </th>
              <th class="text-left py-3 px-4 font-medium txt-secondary">
                {{ $t('admin.subscriptions.name') }}
              </th>
              <th class="text-right py-3 px-4 font-medium txt-secondary">
                {{ $t('admin.subscriptions.priceMonthly') }}
              </th>
              <th class="text-right py-3 px-4 font-medium txt-secondary">
                {{ $t('admin.subscriptions.priceYearly') }}
              </th>
              <th class="text-right py-3 px-4 font-medium txt-secondary">
                {{ $t('admin.subscriptions.budgetMonthly') }}
              </th>
              <th class="text-right py-3 px-4 font-medium txt-secondary">
                {{ $t('admin.subscriptions.budgetYearly') }}
              </th>
              <th class="text-center py-3 px-4 font-medium txt-secondary">
                {{ $t('admin.subscriptions.active') }}
              </th>
              <th class="text-center py-3 px-4 font-medium txt-secondary">
                {{ $t('admin.subscriptions.actions') }}
              </th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="sub in subscriptions"
              :key="sub.id"
              class="border-b border-light-border/10 dark:border-dark-border/10 hover:bg-gray-50 dark:hover:bg-gray-800/50 transition-colors"
            >
              <td class="py-3 px-4">
                <span
                  class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium"
                  :class="getLevelBadgeClass(sub.level)"
                >
                  {{ sub.level }}
                </span>
              </td>
              <td class="py-3 px-4 txt-primary font-medium">
                {{ sub.name }}
              </td>
              <td class="py-3 px-4 text-right txt-secondary">
                {{ formatCurrency(sub.priceMonthly) }}
              </td>
              <td class="py-3 px-4 text-right txt-secondary">
                {{ formatCurrency(sub.priceYearly) }}
              </td>
              <td class="py-3 px-4 text-right">
                <input
                  v-if="editingId === sub.id"
                  v-model.number="editForm.costBudgetMonthly"
                  type="number"
                  min="0"
                  step="0.01"
                  class="w-24 px-2 py-1 text-right text-sm rounded border border-light-border dark:border-dark-border bg-white dark:bg-gray-800 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
                  data-testid="input-budget-monthly"
                />
                <span v-else class="txt-primary">
                  {{ formatCurrency(sub.costBudgetMonthly) }}
                </span>
              </td>
              <td class="py-3 px-4 text-right">
                <input
                  v-if="editingId === sub.id"
                  v-model.number="editForm.costBudgetYearly"
                  type="number"
                  min="0"
                  step="0.01"
                  class="w-24 px-2 py-1 text-right text-sm rounded border border-light-border dark:border-dark-border bg-white dark:bg-gray-800 txt-primary focus:outline-none focus:ring-2 focus:ring-[var(--brand)]"
                  data-testid="input-budget-yearly"
                />
                <span v-else class="txt-primary">
                  {{ formatCurrency(sub.costBudgetYearly) }}
                </span>
              </td>
              <td class="py-3 px-4 text-center">
                <button
                  :class="[
                    'relative inline-flex h-5 w-9 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-[var(--brand)] focus:ring-offset-2',
                    sub.active ? 'bg-green-500' : 'bg-gray-300 dark:bg-gray-600',
                  ]"
                  :data-testid="`toggle-active-${sub.id}`"
                  @click="toggleActive(sub)"
                >
                  <span
                    :class="[
                      'pointer-events-none inline-block h-4 w-4 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out',
                      sub.active ? 'translate-x-4' : 'translate-x-0',
                    ]"
                  />
                </button>
              </td>
              <td class="py-3 px-4 text-center">
                <div v-if="editingId === sub.id" class="flex items-center justify-center gap-2">
                  <button
                    class="px-3 py-1 text-xs font-medium rounded bg-[var(--brand)] text-white hover:opacity-90 transition-opacity disabled:opacity-50"
                    :disabled="saving"
                    data-testid="btn-save"
                    @click="saveEdit(sub.id)"
                  >
                    {{ $t('admin.subscriptions.save') }}
                  </button>
                  <button
                    class="px-3 py-1 text-xs font-medium rounded border border-light-border dark:border-dark-border txt-secondary hover:txt-primary transition-colors"
                    :disabled="saving"
                    data-testid="btn-cancel"
                    @click="cancelEdit()"
                  >
                    {{ $t('admin.subscriptions.cancel') }}
                  </button>
                </div>
                <button
                  v-else
                  class="px-3 py-1 text-xs font-medium rounded border border-light-border dark:border-dark-border txt-secondary hover:txt-primary transition-colors"
                  :data-testid="`btn-edit-${sub.id}`"
                  @click="startEdit(sub)"
                >
                  {{ $t('admin.subscriptions.edit') }}
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <p class="text-xs txt-secondary mt-4">
        {{ $t('admin.subscriptions.budgetHint') }}
      </p>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useNotification } from '@/composables/useNotification'
import { adminSubscriptionsApi, type AdminSubscription } from '@/services/api/adminSubscriptionsApi'

const { t } = useI18n()
const { success, error: notifyError } = useNotification()

const subscriptions = ref<AdminSubscription[]>([])
const loading = ref(true)
const saving = ref(false)
const editingId = ref<number | null>(null)
const editForm = ref({ costBudgetMonthly: 0, costBudgetYearly: 0 })

const loadSubscriptions = async () => {
  loading.value = true
  try {
    const response = await adminSubscriptionsApi.list()
    subscriptions.value = response.subscriptions
  } catch {
    notifyError(t('admin.subscriptions.loadError'))
  } finally {
    loading.value = false
  }
}

const startEdit = (sub: AdminSubscription) => {
  editingId.value = sub.id
  editForm.value = {
    costBudgetMonthly: parseFloat(sub.costBudgetMonthly),
    costBudgetYearly: parseFloat(sub.costBudgetYearly),
  }
}

const cancelEdit = () => {
  editingId.value = null
}

const saveEdit = async (id: number) => {
  saving.value = true
  try {
    const response = await adminSubscriptionsApi.update(id, {
      costBudgetMonthly: editForm.value.costBudgetMonthly,
      costBudgetYearly: editForm.value.costBudgetYearly,
    })
    const idx = subscriptions.value.findIndex((s) => s.id === id)
    if (idx !== -1) {
      subscriptions.value[idx] = response.subscription
    }
    editingId.value = null
    success(t('admin.subscriptions.saveSuccess'))
  } catch {
    notifyError(t('admin.subscriptions.saveError'))
  } finally {
    saving.value = false
  }
}

const toggleActive = async (sub: AdminSubscription) => {
  try {
    const response = await adminSubscriptionsApi.update(sub.id, {
      active: !sub.active,
    })
    const idx = subscriptions.value.findIndex((s) => s.id === sub.id)
    if (idx !== -1) {
      subscriptions.value[idx] = response.subscription
    }
    success(t('admin.subscriptions.saveSuccess'))
  } catch {
    notifyError(t('admin.subscriptions.saveError'))
  }
}

const formatCurrency = (value: string) => {
  return `${parseFloat(value).toFixed(2)} €`
}

const getLevelBadgeClass = (level: string) => {
  const classes: Record<string, string> = {
    NEW: 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300',
    PRO: 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400',
    TEAM: 'bg-purple-100 text-purple-800 dark:bg-purple-900/30 dark:text-purple-400',
    BUSINESS: 'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-400',
  }
  return classes[level] ?? 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300'
}

onMounted(loadSubscriptions)
</script>
