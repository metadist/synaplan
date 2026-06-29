<template>
  <MainLayout>
    <div
      class="min-h-screen bg-chat px-3 py-4 sm:p-4 md:p-8 overflow-y-auto scroll-thin"
      data-testid="page-vector-storage"
    >
      <div class="max-w-7xl mx-auto space-y-6">
        <FilesTabs active="vectors" />

        <p class="text-sm txt-secondary">{{ $t('vectorStorage.intro') }}</p>

        <!-- Loading -->
        <div v-if="isLoading" class="surface-card p-8 flex items-center justify-center">
          <Icon icon="mdi:loading" class="w-6 h-6 animate-spin txt-secondary" />
          <span class="ml-2 txt-secondary">{{ $t('common.loading') }}</span>
        </div>

        <template v-else>
          <!-- Provider status -->
          <div class="surface-card p-4 sm:p-5" data-testid="section-provider">
            <div class="flex flex-wrap items-center justify-between gap-3">
              <div class="flex items-center gap-3">
                <div
                  class="w-9 h-9 rounded-lg flex items-center justify-center shrink-0 bg-[var(--brand)]/10"
                >
                  <Icon icon="mdi:database-outline" class="w-5 h-5 text-[var(--brand)]" />
                </div>
                <div>
                  <p class="text-sm font-medium txt-primary">
                    {{ $t('vectorStorage.provider') }}: {{ providerLabel }}
                  </p>
                  <p class="text-xs txt-secondary">{{ $t('vectorStorage.providerHint') }}</p>
                </div>
              </div>
              <span
                class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium"
                :class="
                  mine?.available
                    ? 'bg-green-500/10 text-green-600 dark:text-green-400'
                    : 'bg-red-500/10 text-red-500'
                "
              >
                <span
                  class="w-1.5 h-1.5 rounded-full"
                  :class="mine?.available ? 'bg-green-500' : 'bg-red-500'"
                />
                {{ mine?.available ? $t('vectorStorage.online') : $t('vectorStorage.offline') }}
              </span>
            </div>
          </div>

          <!-- My vectors -->
          <section data-testid="section-my-stats">
            <h2 class="text-lg font-semibold txt-primary mb-3">
              {{ $t('vectorStorage.myTitle') }}
            </h2>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
              <div class="surface-elevated rounded-lg p-4">
                <div class="flex items-center justify-between mb-1">
                  <span class="text-sm txt-secondary">{{ $t('vectorStorage.files') }}</span>
                  <Icon icon="mdi:file-document-outline" class="w-5 h-5 txt-secondary" />
                </div>
                <div class="text-2xl font-bold txt-primary">{{ fmt(mine?.totalFiles) }}</div>
              </div>
              <div class="surface-elevated rounded-lg p-4">
                <div class="flex items-center justify-between mb-1">
                  <span class="text-sm txt-secondary">{{ $t('vectorStorage.vectors') }}</span>
                  <Icon icon="mdi:vector-point" class="w-5 h-5 txt-secondary" />
                </div>
                <div class="text-2xl font-bold txt-primary">{{ fmt(mine?.totalChunks) }}</div>
                <div class="text-xs txt-secondary mt-1">{{ $t('vectorStorage.vectorsHint') }}</div>
              </div>
              <div class="surface-elevated rounded-lg p-4">
                <div class="flex items-center justify-between mb-1">
                  <span class="text-sm txt-secondary">{{ $t('vectorStorage.folders') }}</span>
                  <Icon icon="mdi:folder-outline" class="w-5 h-5 txt-secondary" />
                </div>
                <div class="text-2xl font-bold txt-primary">{{ fmt(mine?.totalGroups) }}</div>
              </div>
            </div>

            <!-- Per-folder breakdown -->
            <div
              v-if="mine && mine.groups.length > 0"
              class="surface-card p-4 sm:p-5 mt-4"
              data-testid="section-my-groups"
            >
              <h3 class="text-sm font-medium txt-primary mb-3">
                {{ $t('vectorStorage.byFolder') }}
              </h3>
              <ul class="space-y-2">
                <li
                  v-for="group in mine.groups"
                  :key="group.name"
                  class="flex items-center justify-between text-sm"
                >
                  <span class="flex items-center gap-2 min-w-0 txt-secondary">
                    <Icon icon="mdi:folder-outline" class="w-4 h-4 shrink-0" />
                    <span class="truncate">{{
                      group.name || $t('vectorStorage.defaultFolder')
                    }}</span>
                  </span>
                  <span class="txt-primary font-medium shrink-0">
                    {{ $t('vectorStorage.chunksCount', { count: group.chunks }) }}
                  </span>
                </li>
              </ul>
            </div>

            <p v-else class="text-sm txt-secondary mt-4">{{ $t('vectorStorage.emptyMine') }}</p>
          </section>

          <!-- Admin: global stats -->
          <section v-if="isAdmin" data-testid="section-admin-stats" class="pt-2">
            <div class="flex items-center gap-2 mb-3">
              <Icon icon="mdi:shield-crown" class="w-5 h-5 text-[var(--brand)]" />
              <h2 class="text-lg font-semibold txt-primary">
                {{ $t('vectorStorage.adminTitle') }}
              </h2>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
              <div class="surface-elevated rounded-lg p-4">
                <div class="flex items-center justify-between mb-1">
                  <span class="text-sm txt-secondary">{{
                    $t('vectorStorage.usersWithVectors')
                  }}</span>
                  <Icon icon="mdi:account-group-outline" class="w-5 h-5 txt-secondary" />
                </div>
                <div class="text-2xl font-bold txt-primary">{{ fmt(admin?.totalUsers) }}</div>
              </div>
              <div class="surface-elevated rounded-lg p-4">
                <div class="flex items-center justify-between mb-1">
                  <span class="text-sm txt-secondary">{{ $t('vectorStorage.files') }}</span>
                  <Icon icon="mdi:file-document-multiple-outline" class="w-5 h-5 txt-secondary" />
                </div>
                <div class="text-2xl font-bold txt-primary">{{ fmt(admin?.totalFiles) }}</div>
              </div>
              <div class="surface-elevated rounded-lg p-4">
                <div class="flex items-center justify-between mb-1">
                  <span class="text-sm txt-secondary">{{ $t('vectorStorage.vectors') }}</span>
                  <Icon icon="mdi:vector-point" class="w-5 h-5 txt-secondary" />
                </div>
                <div class="text-2xl font-bold txt-primary">{{ fmt(admin?.totalChunks) }}</div>
              </div>
            </div>

            <!-- Top users -->
            <div class="surface-card p-4 sm:p-5 mt-4" data-testid="section-top-users">
              <h3 class="text-sm font-medium txt-primary mb-3">
                {{ $t('vectorStorage.topUsers') }}
              </h3>

              <div v-if="admin && admin.topUsers.length > 0" class="overflow-x-auto">
                <table class="w-full">
                  <thead>
                    <tr class="border-b border-light-border/30 dark:border-dark-border/8">
                      <th class="text-left py-2 px-2 txt-secondary text-xs font-medium w-8">#</th>
                      <th class="text-left py-2 px-2 txt-secondary text-xs font-medium">
                        {{ $t('vectorStorage.user') }}
                      </th>
                      <th class="text-right py-2 px-2 txt-secondary text-xs font-medium w-24">
                        {{ $t('vectorStorage.files') }}
                      </th>
                      <th class="text-right py-2 px-2 txt-secondary text-xs font-medium w-28">
                        {{ $t('vectorStorage.vectors') }}
                      </th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr
                      v-for="(row, index) in admin.topUsers"
                      :key="row.userId"
                      class="border-b border-light-border/10 dark:border-dark-border/5"
                      data-testid="item-top-user"
                    >
                      <td class="py-2 px-2 txt-secondary text-sm">{{ index + 1 }}</td>
                      <td class="py-2 px-2">
                        <span class="text-sm txt-primary">{{
                          row.email || $t('vectorStorage.userId', { id: row.userId })
                        }}</span>
                        <span
                          v-if="row.level"
                          class="ml-2 text-[10px] uppercase tracking-wide txt-secondary"
                          >{{ row.level }}</span
                        >
                      </td>
                      <td class="py-2 px-2 text-right text-sm txt-secondary">
                        {{ fmt(row.files) }}
                      </td>
                      <td class="py-2 px-2 text-right text-sm font-medium txt-primary">
                        {{ fmt(row.chunks) }}
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>

              <p v-else class="text-sm txt-secondary">{{ $t('vectorStorage.emptyAdmin') }}</p>
            </div>
          </section>

          <!-- Deletion note -->
          <div class="surface-card p-4 sm:p-5" data-testid="section-deletion-note">
            <div class="flex items-start gap-3">
              <Icon
                icon="mdi:information-outline"
                class="w-5 h-5 text-[var(--brand)] shrink-0 mt-0.5"
              />
              <p class="text-sm txt-secondary">{{ $t('vectorStorage.deletionNote') }}</p>
            </div>
          </div>
        </template>
      </div>
    </div>
  </MainLayout>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { Icon } from '@iconify/vue'
import MainLayout from '@/components/MainLayout.vue'
import FilesTabs from '@/components/files/FilesTabs.vue'
import { useAuth } from '@/composables/useAuth'
import {
  vectorStatsApi,
  type AdminVectorStats,
  type MyVectorStats,
} from '@/services/api/vectorStatsApi'

const { isAdmin } = useAuth()

const isLoading = ref(true)
const mine = ref<MyVectorStats | null>(null)
const admin = ref<AdminVectorStats | null>(null)

const providerLabel = computed(() => {
  const provider = mine.value?.provider ?? ''
  if (provider === 'qdrant') return 'Qdrant'
  if (provider === 'mariadb') return 'MariaDB'
  return provider || '—'
})

const fmt = (value?: number): string => (value ?? 0).toLocaleString()

onMounted(async () => {
  try {
    const [mineResult, adminResult] = await Promise.all([
      vectorStatsApi.getMine(),
      isAdmin.value ? vectorStatsApi.getAdmin() : Promise.resolve(null),
    ])
    mine.value = mineResult
    admin.value = adminResult
  } catch (error) {
    console.error('Failed to load vector storage stats:', error)
  } finally {
    isLoading.value = false
  }
})
</script>
