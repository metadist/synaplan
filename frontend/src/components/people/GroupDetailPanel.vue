<template>
  <div class="surface-card rounded-lg p-6" data-testid="section-group-detail">
    <div class="flex items-start justify-between gap-4 mb-6">
      <div>
        <h3 class="text-lg font-semibold txt-primary">{{ group.name }}</h3>
        <p v-if="group.description" class="text-sm txt-secondary mt-1">{{ group.description }}</p>
      </div>
      <span
        class="pill text-xs"
        :title="group.kind === 'directory' ? (group.externalSource ?? '') : undefined"
        >{{
          group.kind === 'directory' ? $t('people.groups.fromLogin') : $t('people.groups.manual')
        }}</span
      >
    </div>

    <div class="mb-8">
      <h4 class="text-sm font-medium txt-primary mb-3">{{ $t('people.groups.members') }}</h4>
      <p
        v-if="group.kind === 'directory'"
        class="text-sm txt-secondary mb-3"
        data-testid="directory-members-hint"
      >
        {{ $t('people.groups.directoryManagedHint') }}
      </p>
      <form
        class="flex flex-wrap gap-2 mb-4"
        data-testid="form-add-member"
        @submit.prevent="addMember"
      >
        <div ref="memberSearchRoot" class="relative flex-1 min-w-[220px]">
          <input
            v-model="memberQuery"
            type="text"
            autocomplete="off"
            :placeholder="$t('people.groups.addMemberPlaceholder')"
            class="w-full px-4 py-2.5 rounded-lg bg-chat border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:ring-2 focus:ring-[var(--brand)] focus:outline-none"
            data-testid="input-add-member"
            @focus="memberSearchOpen = true"
          />
          <div
            v-if="memberSearchOpen && (memberSuggestions.length > 0 || memberSearchEmpty)"
            class="dropdown-panel absolute left-0 right-0 top-full mt-1 z-40 max-h-56 overflow-y-auto"
            data-testid="list-add-member-suggestions"
            @mousedown.prevent
          >
            <p v-if="memberSearchEmpty" class="px-3 py-2 text-sm txt-secondary">
              {{ $t('people.groups.addMemberNotFound') }}
            </p>
            <ul v-else>
              <li v-for="person in memberSuggestions" :key="person.id">
                <button
                  type="button"
                  class="dropdown-item w-full text-left"
                  :data-testid="`btn-add-member-suggestion-${person.id}`"
                  @click="pickMember(person)"
                >
                  <span class="font-medium block truncate">{{ person.displayName }}</span>
                  <span
                    v-if="person.email && person.email !== person.displayName"
                    class="block text-xs txt-secondary truncate"
                    >{{ person.email }}</span
                  >
                </button>
              </li>
            </ul>
          </div>
        </div>
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
          <div class="min-w-0">
            <div class="txt-primary text-sm truncate">
              {{ member.displayName || member.email }}
            </div>
            <div
              v-if="member.displayName && member.displayName !== member.email"
              class="txt-secondary text-xs truncate"
            >
              {{ member.email }}
            </div>
            <div class="txt-secondary text-xs">
              {{
                member.role === 'manager'
                  ? $t('people.groups.roleManager')
                  : $t('people.groups.roleMember')
              }}
              <span class="pill text-xs ml-2">{{
                member.source === 'directory'
                  ? $t('people.groups.fromLogin')
                  : $t('people.groups.manual')
              }}</span>
            </div>
          </div>
          <button
            v-if="member.source !== 'directory'"
            class="icon-ghost icon-ghost--danger p-2 rounded-lg"
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
      <p v-if="sharesLoading" class="text-sm txt-secondary">
        {{ $t('people.groups.sharedLoading') }}
      </p>
      <p
        v-else-if="shares.length === 0"
        class="text-sm txt-secondary"
        data-testid="group-shared-empty"
      >
        {{ $t('people.groups.sharedEmpty') }}
      </p>
      <ul v-else class="space-y-2" data-testid="list-group-shares">
        <li
          v-for="share in shares"
          :key="`${share.kind}-${share.id}`"
          class="flex flex-wrap items-center gap-2 py-2 px-3 rounded-lg bg-chat"
          :data-testid="`row-group-share-${share.kind}-${share.id}`"
        >
          <span class="txt-primary text-sm font-medium truncate">{{ share.name }}</span>
          <span class="pill text-xs">{{ kindLabel(share.kind) }}</span>
          <span class="pill text-xs">{{ permissionLabel(share.permission) }}</span>
          <span v-if="share.ownerName" class="txt-secondary text-xs">
            {{ $t('iam.dialog.ownerLine', { name: share.ownerName }) }}
          </span>
        </li>
      </ul>
    </div>
  </div>
</template>

<script setup lang="ts">
import { onUnmounted, ref, watch } from 'vue'
import { Icon } from '@iconify/vue'
import { useI18n } from 'vue-i18n'
import {
  iamApi,
  type IamGroup,
  type IamGroupMember,
  type IamGroupShare,
} from '@/services/api/iamApi'
import { adminApi, type AdminUserSearchHit } from '@/services/api/adminApi'
import { useDialog } from '@/composables/useDialog'
import { useNotification } from '@/composables/useNotification'

const props = defineProps<{
  group: IamGroup
}>()

const emit = defineEmits<{
  changed: []
}>()

const { t, te } = useI18n()
const { confirm } = useDialog()
const { success, error: showError } = useNotification()

const PERMISSIONS = ['read', 'use', 'edit', 'manage'] as const

const members = ref<IamGroupMember[]>([])
const membersLoading = ref(false)
const shares = ref<IamGroupShare[]>([])
const sharesLoading = ref(false)
const memberQuery = ref('')
const memberRole = ref<'member' | 'manager'>('member')
const adding = ref(false)
const memberSuggestions = ref<AdminUserSearchHit[]>([])
const memberSearchOpen = ref(false)
const memberSearchEmpty = ref(false)
const pickedMember = ref<AdminUserSearchHit | null>(null)
const memberSearchRoot = ref<HTMLElement | null>(null)
let searchTimer: ReturnType<typeof setTimeout> | null = null

function kindLabel(kind: string): string {
  const key = `people.groups.resourceKind.${kind}`
  return te(key) ? t(key) : kind
}

function permissionLabel(permission: string): string {
  if ((PERMISSIONS as readonly string[]).includes(permission)) {
    return t(`iam.permission.${permission}`)
  }
  return permission
}

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

async function loadShares() {
  sharesLoading.value = true
  try {
    shares.value = await iamApi.listGroupShares(props.group.id)
  } catch (error) {
    shares.value = []
    showError(error instanceof Error ? error.message : t('people.groups.loadError'))
  } finally {
    sharesLoading.value = false
  }
}

async function searchMembers(query: string) {
  try {
    const users = await adminApi.searchUsers(query)
    memberSuggestions.value = users
    memberSearchEmpty.value = users.length === 0
  } catch {
    memberSuggestions.value = []
    memberSearchEmpty.value = true
  }
}

function pickMember(person: AdminUserSearchHit) {
  pickedMember.value = person
  memberQuery.value = person.displayName || person.email
  memberSuggestions.value = []
  memberSearchEmpty.value = false
  memberSearchOpen.value = false
}

watch(memberQuery, (value) => {
  if (
    pickedMember.value &&
    value !== (pickedMember.value.displayName || pickedMember.value.email)
  ) {
    pickedMember.value = null
  }
  if (searchTimer) clearTimeout(searchTimer)
  const query = value.trim()
  if (query.length < 2 || pickedMember.value) {
    memberSuggestions.value = []
    memberSearchEmpty.value = false
    return
  }
  searchTimer = setTimeout(() => {
    void searchMembers(query)
  }, 250)
})

function onDocumentClick(event: MouseEvent) {
  if (memberSearchRoot.value && !memberSearchRoot.value.contains(event.target as Node)) {
    memberSearchOpen.value = false
  }
}

if (typeof document !== 'undefined') {
  document.addEventListener('mousedown', onDocumentClick)
}

onUnmounted(() => {
  if (searchTimer) clearTimeout(searchTimer)
  document.removeEventListener('mousedown', onDocumentClick)
})

async function addMember() {
  const query = memberQuery.value.trim().toLowerCase()
  if (!query) return
  const person =
    pickedMember.value ??
    memberSuggestions.value.find(
      (user) => user.email.toLowerCase() === query || user.displayName.toLowerCase() === query
    ) ??
    null
  if (!person) {
    showError(t('people.groups.addMemberNotFound'))
    return
  }
  adding.value = true
  try {
    await iamApi.setMember(props.group.id, person.id, memberRole.value)
    memberQuery.value = ''
    pickedMember.value = null
    memberSuggestions.value = []
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
    void loadMembers()
    void loadShares()
  },
  { immediate: true }
)
</script>
