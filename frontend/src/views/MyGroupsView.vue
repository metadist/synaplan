<template>
  <MainLayout data-testid="view-my-groups">
    <div class="container mx-auto px-6 py-8 max-w-3xl overflow-x-hidden">
      <PageHeader
        :title="$t('nav.myGroups')"
        :subtitle="$t('people.myGroups.subtitle')"
        icon="mdi:account-group"
      >
        <router-link
          v-if="authStore.isAdmin"
          to="/admin/people"
          class="btn-primary px-4 py-2.5 rounded-lg inline-flex items-center gap-2"
          data-testid="link-my-groups-people"
        >
          {{ $t('people.openPeople') }}
        </router-link>
      </PageHeader>

      <div v-if="loading" class="surface-card rounded-lg p-12 text-center">
        <Icon icon="mdi:loading" class="w-8 h-8 animate-spin mx-auto txt-secondary" />
      </div>
      <div
        v-else-if="groups.length === 0"
        class="surface-card rounded-lg p-12 text-center txt-secondary"
        data-testid="my-groups-empty"
      >
        {{
          authStore.isAdmin ? $t('people.myGroups.emptyAdmin') : $t('people.myGroups.emptyMember')
        }}
      </div>
      <ul v-else class="space-y-3" data-testid="list-my-groups">
        <li
          v-for="group in groups"
          :key="group.id"
          class="surface-card rounded-lg p-5 flex flex-wrap items-center gap-3"
          :data-testid="`card-my-group-${group.id}`"
        >
          <div class="min-w-0 flex-1">
            <p class="txt-primary font-medium truncate">{{ group.name }}</p>
            <p v-if="group.description" class="txt-secondary text-sm mt-0.5">
              {{ group.description }}
            </p>
          </div>
          <span class="pill text-xs">
            {{
              group.kind === 'directory'
                ? $t('people.groups.fromLogin')
                : $t('people.groups.manual')
            }}
          </span>
          <span v-if="group.role" class="pill text-xs">
            {{
              group.role === 'manager'
                ? $t('people.groups.roleManager')
                : $t('people.groups.roleMember')
            }}
          </span>
          <span class="txt-secondary text-sm">
            {{ $t('people.groups.memberCount', { count: group.memberCount ?? 0 }) }}
          </span>
        </li>
      </ul>
    </div>
  </MainLayout>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { Icon } from '@iconify/vue'
import { useI18n } from 'vue-i18n'
import MainLayout from '@/components/MainLayout.vue'
import PageHeader from '@/components/PageHeader.vue'
import { useAuthStore } from '@/stores/auth'
import { iamApi, type IamGroup } from '@/services/api/iamApi'
import { useNotification } from '@/composables/useNotification'

const { t } = useI18n()
const authStore = useAuthStore()
const { error } = useNotification()
const loading = ref(true)
const groups = ref<IamGroup[]>([])

onMounted(async () => {
  try {
    groups.value = await iamApi.listMyGroups()
  } catch {
    error(t('people.groups.loadError'))
  } finally {
    loading.value = false
  }
})
</script>
