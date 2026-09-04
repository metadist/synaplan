<template>
  <div data-testid="section-groups">
    <div class="flex justify-end mb-4">
      <button
        class="btn-primary px-4 py-2.5 rounded-lg"
        data-testid="btn-create-group"
        @click="createGroup"
      >
        {{ $t('people.groups.create') }}
      </button>
    </div>

    <div v-if="loading" class="surface-card rounded-lg p-12 text-center">
      <Icon icon="mdi:loading" class="w-8 h-8 animate-spin mx-auto txt-secondary" />
    </div>
    <div
      v-else-if="groups.length === 0"
      class="surface-card rounded-lg p-12 text-center txt-secondary"
      data-testid="groups-empty"
    >
      {{ $t('people.groups.empty') }}
    </div>
    <div v-else class="grid grid-cols-1 lg:grid-cols-2 gap-6">
      <div class="surface-card rounded-lg p-6 overflow-x-auto">
        <table class="w-full">
          <thead>
            <tr class="border-b border-light-border/30 dark:border-dark-border/20">
              <th class="text-left py-2 px-3 text-sm font-medium txt-secondary">
                {{ $t('people.groups.name') }}
              </th>
              <th class="text-left py-2 px-3 text-sm font-medium txt-secondary">
                {{ $t('people.groups.kind') }}
              </th>
              <th class="text-left py-2 px-3 text-sm font-medium txt-secondary">
                {{ $t('people.groups.members') }}
              </th>
              <th class="text-right py-2 px-3 text-sm font-medium txt-secondary">
                {{ $t('admin.users.actions') }}
              </th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="group in groups"
              :key="group.id"
              class="border-b border-light-border/30 dark:border-dark-border/20 hover:bg-black/5 dark:hover:bg-white/5 cursor-pointer"
              :class="selectedId === group.id ? 'bg-black/5 dark:bg-white/5' : ''"
              :data-testid="`row-group-${group.id}`"
              @click="selectedId = group.id"
            >
              <td class="py-3 px-3 txt-primary font-medium">{{ group.name }}</td>
              <td class="py-3 px-3">
                <span class="pill text-xs">{{
                  group.kind === 'directory'
                    ? $t('people.groups.fromLogin')
                    : $t('people.groups.manual')
                }}</span>
              </td>
              <td class="py-3 px-3 txt-secondary text-sm">
                {{ $t('people.groups.memberCount', { count: group.memberCount }) }}
              </td>
              <td class="py-3 px-3 text-right">
                <div v-if="group.kind === 'manual'" class="flex justify-end gap-1">
                  <button
                    class="p-2 rounded-lg hover:bg-black/5 dark:hover:bg-white/5"
                    :data-testid="`btn-rename-group-${group.id}`"
                    @click.stop="renameGroup(group)"
                  >
                    {{ $t('people.groups.rename') }}
                  </button>
                  <button
                    class="p-2 rounded-lg text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20"
                    :data-testid="`btn-delete-group-${group.id}`"
                    @click.stop="deleteGroup(group)"
                  >
                    {{ $t('people.groups.delete') }}
                  </button>
                </div>
                <span v-else class="text-xs txt-secondary">{{
                  $t('people.groups.directoryReadOnly')
                }}</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <GroupDetailPanel v-if="selectedGroup" :group="selectedGroup" @changed="loadGroups" />
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { Icon } from '@iconify/vue'
import { useI18n } from 'vue-i18n'
import { iamApi, type IamGroup } from '@/services/api/iamApi'
import { useDialog } from '@/composables/useDialog'
import { useNotification } from '@/composables/useNotification'
import GroupDetailPanel from './GroupDetailPanel.vue'

const { t } = useI18n()
const { prompt, confirm } = useDialog()
const { success, error: showError } = useNotification()

const groups = ref<IamGroup[]>([])
const loading = ref(false)
const selectedId = ref<number | null>(null)
const selectedGroup = computed(
  () => groups.value.find((group) => group.id === selectedId.value) ?? null
)

async function loadGroups() {
  loading.value = true
  try {
    groups.value = await iamApi.listAdminGroups()
    if (selectedId.value && !groups.value.some((group) => group.id === selectedId.value)) {
      selectedId.value = null
    }
    if (selectedId.value === null && groups.value[0]) {
      selectedId.value = groups.value[0].id
    }
  } catch (error) {
    showError(error instanceof Error ? error.message : t('people.groups.loadError'))
  } finally {
    loading.value = false
  }
}

async function createGroup() {
  const name = await prompt({
    title: t('people.groups.createTitle'),
    message: t('people.groups.createPrompt'),
    placeholder: t('people.groups.createPrompt'),
  })
  if (!name?.trim()) return
  try {
    const group = await iamApi.createGroup(name.trim())
    success(t('people.groups.created'))
    await loadGroups()
    selectedId.value = group.id
  } catch (error) {
    showError(error instanceof Error ? error.message : t('people.groups.saveError'))
  }
}

async function renameGroup(group: IamGroup) {
  const name = await prompt({
    title: t('people.groups.renameTitle'),
    message: t('people.groups.createPrompt'),
    defaultValue: group.name,
  })
  if (!name?.trim() || name.trim() === group.name) return
  try {
    await iamApi.updateGroup(group.id, { name: name.trim() })
    success(t('people.groups.renamed'))
    await loadGroups()
  } catch (error) {
    showError(error instanceof Error ? error.message : t('people.groups.saveError'))
  }
}

async function deleteGroup(group: IamGroup) {
  const confirmed = await confirm({
    title: t('people.groups.delete'),
    message: t('people.groups.deleteConfirm', { name: group.name }),
    danger: true,
  })
  if (!confirmed) return
  try {
    await iamApi.deleteGroup(group.id)
    success(t('people.groups.deleted'))
    if (selectedId.value === group.id) {
      selectedId.value = null
    }
    await loadGroups()
  } catch (error) {
    showError(error instanceof Error ? error.message : t('people.groups.saveError'))
  }
}

onMounted(() => {
  loadGroups()
})
</script>
