<template>
  <div class="surface-card rounded-lg p-6" data-testid="section-group-detail">
    <div class="flex items-start justify-between gap-4 mb-6">
      <div>
        <h3 class="text-lg font-semibold txt-primary">{{ group.name }}</h3>
        <p v-if="group.description" class="text-sm txt-secondary mt-1">{{ group.description }}</p>
      </div>
      <span class="pill text-xs">{{
        group.kind === 'directory' ? $t('people.groups.fromLogin') : $t('people.groups.manual')
      }}</span>
    </div>

    <div class="mb-8">
      <h4 class="text-sm font-medium txt-primary mb-3">{{ $t('people.groups.members') }}</h4>
      <form
        v-if="group.kind === 'manual'"
        class="flex flex-wrap gap-2 mb-4"
        data-testid="form-add-member"
        @submit.prevent="addMember"
      >
        <input
          v-model="memberQuery"
          type="text"
          :placeholder="$t('people.groups.addMemberPlaceholder')"
          class="flex-1 min-w-[220px] px-4 py-2.5 rounded-lg bg-chat border border-light-border/30 dark:border-dark-border/20 txt-primary focus:ring-2 focus:ring-[var(--brand)] focus:outline-none"
          data-testid="input-add-member"
        />
        <select
          v-model="memberRole"
          class="px-3 py-2.5 rounded-lg bg-chat border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:ring-2 focus:ring-[var(--brand)] focus:outline-none"
          data-testid="select-member-role"
        >
          <option value="member">{{ $t('people.groups.roleMember') }}</option>
          <option value="manager">{{ $t('people.groups.roleManager') }}</option>
        </select>
        <button
          type="submit"
          class="btn-primary px-4 py-2.5 rounded-lg"
          :disabled="adding || !memberQuery.trim()"
          data-testid="btn-add-member"
        >
          {{ $t('people.groups.addMember') }}
        </button>
      </form>

      <div v-if="membersLoading" class="text-center py-8">
        <Icon icon="mdi:loading" class="w-6 h-6 animate-spin mx-auto txt-secondary" />
      </div>
      <ul v-else class="space-y-2" data-testid="list-group-members">
        <li
          v-for="member in members"
          :key="member.userId"
          class="flex items-center justify-between gap-3 py-2 px-3 rounded-lg bg-chat"
        >
          <div>
            <div class="txt-primary text-sm">{{ member.email }}</div>
            <div class="txt-secondary text-xs">
              {{
                member.role === 'manager'
                  ? $t('people.groups.roleManager')
                  : $t('people.groups.roleMember')
              }}
            </div>
          </div>
          <button
            v-if="group.kind === 'manual'"
            class="text-red-500 hover:text-red-600 p-2 rounded-lg"
            :data-testid="`btn-remove-member-${member.userId}`"
            @click="removeMember(member)"
          >
            {{ $t('people.groups.removeMember') }}
          </button>
        </li>
      </ul>
    </div>

    <div>
      <h4 class="text-sm font-medium txt-primary mb-2">{{ $t('people.groups.sharedWith') }}</h4>
      <p class="text-sm txt-secondary" data-testid="group-shared-empty">
        {{ $t('people.groups.sharedEmpty') }}
      </p>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, watch } from 'vue'
import { Icon } from '@iconify/vue'
import { useI18n } from 'vue-i18n'
import { iamApi, type IamGroup, type IamGroupMember } from '@/services/api/iamApi'
import { adminApi } from '@/services/api/adminApi'
import { useDialog } from '@/composables/useDialog'
import { useNotification } from '@/composables/useNotification'

const props = defineProps<{
  group: IamGroup
}>()

const emit = defineEmits<{
  changed: []
}>()

const { t } = useI18n()
const { confirm } = useDialog()
const { success, error: showError } = useNotification()

const members = ref<IamGroupMember[]>([])
const membersLoading = ref(false)
const memberQuery = ref('')
const memberRole = ref<'member' | 'manager'>('member')
const adding = ref(false)

async function loadMembers() {
  membersLoading.value = true
  try {
    members.value = await iamApi.listMembers(props.group.id)
  } catch (error) {
    showError(error instanceof Error ? error.message : t('people.groups.loadError'))
  } finally {
    membersLoading.value = false
  }
}

async function addMember() {
  const query = memberQuery.value.trim()
  if (!query) return
  adding.value = true
  try {
    const result = await adminApi.getUsers(1, 20, query)
    const match = result.users.find(
      (user) => (user.email ?? '').toLowerCase() === query.toLowerCase()
    )
    if (!match) {
      showError(t('people.groups.addMemberNotFound'))
      return
    }
    await iamApi.setMember(props.group.id, match.id, memberRole.value)
    memberQuery.value = ''
    success(t('people.groups.memberAdded'))
    await loadMembers()
    emit('changed')
  } catch (error) {
    showError(error instanceof Error ? error.message : t('people.groups.saveError'))
  } finally {
    adding.value = false
  }
}

async function removeMember(member: IamGroupMember) {
  const confirmed = await confirm({
    title: t('people.groups.removeMember'),
    message: t('people.groups.removeConfirm', { email: member.email }),
    danger: true,
  })
  if (!confirmed) return
  try {
    await iamApi.removeMember(props.group.id, member.userId)
    success(t('people.groups.memberRemoved'))
    await loadMembers()
    emit('changed')
  } catch (error) {
    showError(error instanceof Error ? error.message : t('people.groups.saveError'))
  }
}

watch(
  () => props.group.id,
  () => {
    loadMembers()
  },
  { immediate: true }
)
</script>
