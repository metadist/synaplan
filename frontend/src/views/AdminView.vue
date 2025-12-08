<template>
  <MainLayout data-testid="view-admin">
    <div class="container mx-auto px-6 py-8 max-w-7xl">
      <!-- Header -->
      <div class="mb-8">
        <div class="flex items-center gap-3 mb-2">
          <Icon icon="mdi:shield-crown" class="w-8 h-8 text-[var(--brand)]" />
          <h1 class="text-3xl font-bold txt-primary">{{ $t('admin.title') }}</h1>
        </div>
        <p class="txt-secondary">{{ $t('admin.description') }}</p>
      </div>

      <!-- Tabs -->
      <div class="flex gap-2 mb-6 border-b border-light-border/30 dark:border-dark-border/20">
        <button
          v-for="tab in tabs"
          :key="tab.id"
          @click="activeTab = tab.id"
          :class="[
            'px-4 py-3 font-medium transition-colors relative',
            activeTab === tab.id
              ? 'txt-primary border-b-2 border-[var(--brand)]'
              : 'txt-secondary hover:txt-primary'
          ]"
          :data-testid="`tab-${tab.id}`"
        >
          <div class="flex items-center gap-2">
            <Icon :icon="tab.icon" class="w-5 h-5" />
            {{ tab.label }}
          </div>
        </button>
      </div>

      <!-- Tab Content -->
      <div class="space-y-6">
        <!-- Overview Tab -->
        <div v-if="activeTab === 'overview'" data-testid="section-overview">
          <div v-if="overviewLoading" class="text-center py-12">
            <Icon icon="mdi:loading" class="w-8 h-8 animate-spin mx-auto txt-secondary" />
          </div>
          <div v-else-if="overview" class="space-y-6">
            <!-- Stats Grid -->
              <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                <div class="surface-card rounded-lg p-6">
                <div class="flex items-center justify-between mb-2">
                  <span class="text-sm txt-secondary">{{ $t('admin.overview.totalUsers') }}</span>
                  <Icon icon="mdi:account-multiple" class="w-5 h-5 txt-secondary" />
                </div>
                <div class="text-3xl font-bold txt-primary">{{ overview.totalUsers }}</div>
              </div>

                <div
                  v-for="(count, level) in overview.usersByLevel"
                  :key="level"
                  class="surface-card rounded-lg p-6"
                >
                <div class="flex items-center justify-between mb-2">
                    <span class="text-sm txt-secondary">{{ level }}</span>
                    <Icon :icon="getLevelIcon(level)" class="w-5 h-5 txt-secondary" />
                </div>
                  <div class="text-3xl font-bold txt-primary">{{ count }}</div>
              </div>
            </div>

            <!-- Registration Analytics Chart -->
            <RegistrationChart
              v-if="registrationAnalytics"
              :data="registrationAnalytics"
              :initial-period="analyticsPeriod"
              :initial-group-by="analyticsGroupBy"
              @update:period="updateAnalyticsPeriod"
              @update:groupBy="updateAnalyticsGroupBy"
            />

            <!-- Active Subscriptions Overview -->
            <div class="surface-card rounded-lg p-6">
              <h3 class="text-lg font-semibold txt-primary mb-4 flex items-center gap-2">
                <Icon icon="mdi:credit-card-outline" class="w-5 h-5" />
                {{ $t('admin.usage.activeSubscriptions') }}
              </h3>
              <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <template
                  v-for="(count, level) in overview.usersByLevel"
                  :key="level"
                >
                  <div
                    v-if="level !== 'NEW' && level !== 'ADMIN'"
                    class="surface-elevated rounded-lg p-4"
                  >
                    <div class="flex items-center justify-between mb-2">
                      <span class="font-semibold txt-primary">{{ level }}</span>
                      <Icon :icon="getLevelIcon(level)" class="w-5 h-5 txt-secondary" />
                    </div>
                    <div class="text-2xl font-bold txt-primary">{{ count }}</div>
                    <div class="text-xs txt-secondary mt-1">{{ $t('admin.usage.activeSubscriptions') }}</div>
                  </div>
                </template>
              </div>
            </div>

            <!-- Recent Users -->
            <div class="surface-card rounded-lg p-6">
              <h3 class="text-lg font-semibold txt-primary mb-4 flex items-center gap-2">
                <Icon icon="mdi:account-clock" class="w-5 h-5" />
                {{ $t('admin.overview.recentUsers') }}
              </h3>
              <div class="overflow-x-auto">
                <table class="w-full">
                  <thead>
                    <tr class="border-b border-light-border/30 dark:border-dark-border/20">
                      <th class="text-left py-2 px-4 text-sm font-medium txt-secondary">{{ $t('admin.users.email') }}</th>
                      <th class="text-left py-2 px-4 text-sm font-medium txt-secondary">{{ $t('admin.users.level') }}</th>
                      <th class="text-left py-2 px-4 text-sm font-medium txt-secondary">{{ $t('admin.users.created') }}</th>
                      <th class="text-left py-2 px-4 text-sm font-medium txt-secondary">{{ $t('admin.users.status') }}</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr
                      v-for="user in recentUsers"
                      :key="user.id"
                      class="border-b border-light-border/30 dark:border-dark-border/20 hover:bg-black/5 dark:hover:bg-white/5"
                    >
                      <td class="py-3 px-4 txt-primary">{{ user.email }}</td>
                      <td class="py-3 px-4">
                        <span :class="getLevelBadgeClass(user.level)">{{ user.level }}</span>
                      </td>
                      <td class="py-3 px-4 txt-secondary text-sm">{{ formatDate(user.created) }}</td>
                      <td class="py-3 px-4">
                        <Icon
                          v-if="user.emailVerified"
                          icon="mdi:check-circle"
                          class="w-5 h-5 text-green-500"
                          :title="$t('admin.users.verified')"
                        />
                        <Icon
                          v-else
                          icon="mdi:alert-circle"
                          class="w-5 h-5 text-yellow-500"
                          :title="$t('admin.users.unverified')"
                        />
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>

        <!-- Users Tab -->
        <div v-if="activeTab === 'users'" data-testid="section-users">
          <!-- Search & Filters -->
          <div class="surface-card rounded-lg p-4 mb-6">
            <div class="flex gap-4 flex-wrap">
              <div class="flex-1 min-w-[300px]">
                <input
                  v-model="userSearch"
                  type="text"
                  :placeholder="$t('admin.users.searchPlaceholder')"
                  class="w-full px-4 py-2.5 rounded-lg bg-chat border border-light-border/30 dark:border-dark-border/20 txt-primary focus:ring-2 focus:ring-[var(--brand)] focus:outline-none"
                  @input="debouncedSearchUsers"
                  data-testid="input-user-search"
                />
              </div>
              <button
                @click="loadUsers()"
                class="btn-secondary px-6 py-2.5 rounded-lg font-medium"
                data-testid="btn-refresh-users"
              >
                <Icon icon="mdi:refresh" class="w-5 h-5" />
              </button>
            </div>
          </div>

          <!-- Users Table -->
          <div class="surface-card rounded-lg p-6">
            <div v-if="usersLoading" class="text-center py-12">
              <Icon icon="mdi:loading" class="w-8 h-8 animate-spin mx-auto txt-secondary" />
            </div>
            <div v-else-if="users.length === 0" class="text-center py-12 txt-secondary">
              {{ $t('admin.users.noUsers') }}
            </div>
            <div v-else>
              <div class="overflow-x-auto">
                <table class="w-full">
                  <thead>
                    <tr class="border-b border-light-border/30 dark:border-dark-border/20">
                      <th class="text-left py-2 px-4 text-sm font-medium txt-secondary">ID</th>
                      <th class="text-left py-2 px-4 text-sm font-medium txt-secondary">{{ $t('admin.users.email') }}</th>
                      <th class="text-left py-2 px-4 text-sm font-medium txt-secondary">{{ $t('admin.users.level') }}</th>
                      <th class="text-left py-2 px-4 text-sm font-medium txt-secondary">{{ $t('admin.users.type') }}</th>
                      <th class="text-left py-2 px-4 text-sm font-medium txt-secondary">{{ $t('admin.users.provider') }}</th>
                      <th class="text-left py-2 px-4 text-sm font-medium txt-secondary">{{ $t('admin.users.created') }}</th>
                      <th class="text-right py-2 px-4 text-sm font-medium txt-secondary">{{ $t('admin.users.actions') }}</th>
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
                          <span class="txt-primary">{{ user.email }}</span>
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
                          @change="updateUserLevel(user.id, ($event.target as HTMLSelectElement).value)"
                          class="px-3 py-1.5 rounded-lg bg-chat border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:ring-2 focus:ring-[var(--brand)] focus:outline-none"
                          :disabled="user.id === currentUserId"
                          :data-testid="`select-user-level-${user.id}`"
                        >
                          <option value="NEW">NEW</option>
                          <option value="PRO">PRO</option>
                          <option value="TEAM">TEAM</option>
                          <option value="BUSINESS">BUSINESS</option>
                          <option value="ADMIN">ADMIN</option>
                        </select>
                      </td>
                      <td class="py-3 px-4 txt-secondary text-sm">{{ user.type }}</td>
                      <td class="py-3 px-4 txt-secondary text-sm">{{ user.providerId }}</td>
                      <td class="py-3 px-4 txt-secondary text-sm">{{ formatDate(user.created) }}</td>
                      <td class="py-3 px-4 text-right">
                        <button
                          v-if="user.id !== currentUserId"
                          @click="confirmDeleteUser(user)"
                          class="text-red-500 hover:text-red-600 dark:text-red-400 dark:hover:text-red-300 p-2 rounded-lg hover:bg-red-50 dark:hover:bg-red-900/20"
                          :title="$t('admin.users.delete')"
                          :data-testid="`btn-delete-user-${user.id}`"
                        >
                          <Icon icon="mdi:delete" class="w-5 h-5" />
                        </button>
                        <span v-else class="text-sm txt-secondary italic">{{ $t('admin.users.currentUser') }}</span>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>

              <!-- Pagination -->
              <div v-if="totalPages > 1" class="flex justify-center gap-2 mt-6">
                <button
                  @click="currentPage = Math.max(1, currentPage - 1)"
                  :disabled="currentPage === 1"
                  class="btn-secondary px-4 py-2 rounded-lg disabled:opacity-50"
                  data-testid="btn-prev-page"
                >
                  <Icon icon="mdi:chevron-left" class="w-5 h-5" />
                </button>
                <span class="px-4 py-2 txt-primary">{{ currentPage }} / {{ totalPages }}</span>
                <button
                  @click="currentPage = Math.min(totalPages, currentPage + 1)"
                  :disabled="currentPage === totalPages"
                  class="btn-secondary px-4 py-2 rounded-lg disabled:opacity-50"
                  data-testid="btn-next-page"
                >
                  <Icon icon="mdi:chevron-right" class="w-5 h-5" />
                </button>
              </div>
            </div>
          </div>
        </div>

        <!-- Prompts Tab -->
        <div v-if="activeTab === 'prompts'" data-testid="section-prompts">
          <div v-if="promptsLoading" class="text-center py-12">
            <Icon icon="mdi:loading" class="w-8 h-8 animate-spin mx-auto txt-secondary" />
          </div>
          <div v-else class="space-y-4">
            <div
              v-for="prompt in prompts"
              :key="prompt.id"
              class="surface-card rounded-lg p-6"
            >
              <div class="flex items-start justify-between mb-4">
                <div class="flex-1">
                  <div class="flex items-center gap-3 mb-2">
                    <h3 class="text-lg font-semibold txt-primary">{{ prompt.topic }}</h3>
                    <span class="px-2 py-1 text-xs rounded-full bg-blue-100 dark:bg-blue-900/30 text-blue-800 dark:text-blue-200">
                      {{ prompt.language }}
                    </span>
                  </div>
                  <p class="text-sm txt-secondary mb-4">{{ prompt.shortDescription }}</p>
                </div>
                <button
                  @click="togglePromptEdit(prompt.id)"
                  class="btn-secondary px-4 py-2 rounded-lg"
                  :data-testid="`btn-edit-prompt-${prompt.id}`"
                >
                  <Icon :icon="editingPromptId === prompt.id ? 'mdi:close' : 'mdi:pencil'" class="w-4 h-4" />
                </button>
              </div>

              <!-- Edit Form -->
              <div v-if="editingPromptId === prompt.id" class="space-y-4 mt-4 pt-4 border-t border-light-border/30 dark:border-dark-border/20">
                <div>
                  <label class="block text-sm font-medium txt-primary mb-2">{{ $t('admin.prompts.shortDesc') }}</label>
                  <input
                    v-model="editingPrompt.shortDescription"
                    type="text"
                    class="w-full px-4 py-2.5 rounded-lg bg-chat border border-light-border/30 dark:border-dark-border/20 txt-primary focus:ring-2 focus:ring-[var(--brand)] focus:outline-none"
                    :data-testid="`input-prompt-desc-${prompt.id}`"
                  />
                </div>
                <div>
                  <label class="block text-sm font-medium txt-primary mb-2">{{ $t('admin.prompts.prompt') }}</label>
                  <textarea
                    v-model="editingPrompt.prompt"
                    rows="10"
                    class="w-full px-4 py-2.5 rounded-lg bg-chat border border-light-border/30 dark:border-dark-border/20 txt-primary focus:ring-2 focus:ring-[var(--brand)] focus:outline-none font-mono text-sm"
                    :data-testid="`textarea-prompt-${prompt.id}`"
                  ></textarea>
                </div>
                <div>
                  <label class="block text-sm font-medium txt-primary mb-2">{{ $t('admin.prompts.selectionRules') }}</label>
                  <textarea
                    v-model="editingPrompt.selectionRules"
                    rows="4"
                    class="w-full px-4 py-2.5 rounded-lg bg-chat border border-light-border/30 dark:border-dark-border/20 txt-primary focus:ring-2 focus:ring-[var(--brand)] focus:outline-none font-mono text-sm"
                    :placeholder="$t('admin.prompts.selectionRulesPlaceholder')"
                    :data-testid="`textarea-selection-rules-${prompt.id}`"
                  ></textarea>
                </div>
                <div class="flex justify-end gap-3">
                  <button
                    @click="cancelEditPrompt()"
                    class="btn-secondary px-6 py-2.5 rounded-lg"
                    data-testid="btn-cancel-edit-prompt"
                  >
                    {{ $t('common.cancel') }}
                  </button>
                  <button
                    @click="savePrompt(prompt.id)"
                    class="btn-primary px-6 py-2.5 rounded-lg"
                    :disabled="promptSaving"
                    data-testid="btn-save-prompt"
                  >
                    {{ $t('common.save') }}
                  </button>
                </div>
              </div>

              <!-- Read-only View -->
              <div v-else class="space-y-2">
                <div class="bg-chat rounded-lg p-4 font-mono text-sm txt-secondary">
                  {{ prompt.prompt }}
                </div>
                <div v-if="prompt.selectionRules" class="text-xs txt-secondary">
                  <strong>{{ $t('admin.prompts.selectionRules') }}:</strong> {{ prompt.selectionRules }}
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Usage Tab -->
        <div v-if="activeTab === 'usage'" data-testid="section-usage">
          <!-- Period Selector -->
          <div class="surface-card rounded-lg p-4 mb-6">
            <div class="flex gap-2 flex-wrap">
              <button
                v-for="period in ['day', 'week', 'month', 'all']"
                :key="period"
                @click="loadUsageStats(period as any)"
                :class="[
                  'px-4 py-2 rounded-lg font-medium',
                  usageStatsPeriod === period ? 'btn-primary' : 'btn-secondary'
                ]"
                :data-testid="`btn-period-${period}`"
              >
                {{ $t(`admin.usage.period.${period}`) }}
              </button>
            </div>
          </div>

          <div v-if="usageStatsLoading" class="text-center py-12">
            <Icon icon="mdi:loading" class="w-8 h-8 animate-spin mx-auto txt-secondary" />
          </div>
          <div v-else-if="usageStats" class="space-y-6">
            <!-- Usage Chart -->
            <UsageChart :data="usageStats.byAction" />
            <!-- Stats Grid -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
              <div class="surface-card rounded-lg p-6">
                <div class="flex items-center justify-between mb-2">
                  <span class="text-sm txt-secondary">{{ $t('admin.usage.totalRequests') }}</span>
                  <Icon icon="mdi:message" class="w-5 h-5 txt-secondary" />
                </div>
                <div class="text-3xl font-bold txt-primary">{{ (usageStats.total_requests || 0).toLocaleString() }}</div>
              </div>

              <div class="surface-card rounded-lg p-6">
                <div class="flex items-center justify-between mb-2">
                  <span class="text-sm txt-secondary">{{ $t('admin.usage.totalTokens') }}</span>
                  <Icon icon="mdi:alphabetical-variant" class="w-5 h-5 txt-secondary" />
                </div>
                <div class="text-3xl font-bold txt-primary">{{ (usageStats.total_tokens || 0).toLocaleString() }}</div>
              </div>

              <div class="surface-card rounded-lg p-6">
                <div class="flex items-center justify-between mb-2">
                  <span class="text-sm txt-secondary">{{ $t('admin.usage.totalCost') }}</span>
                  <Icon icon="mdi:currency-usd" class="w-5 h-5 txt-secondary" />
                </div>
                <div class="text-3xl font-bold txt-primary">${{ (usageStats.total_cost || 0).toFixed(2) }}</div>
              </div>

              <div class="surface-card rounded-lg p-6">
                <div class="flex items-center justify-between mb-2">
                  <span class="text-sm txt-secondary">{{ $t('admin.usage.avgLatency') }}</span>
                  <Icon icon="mdi:speedometer" class="w-5 h-5 txt-secondary" />
                </div>
                <div class="text-3xl font-bold txt-primary">{{ (usageStats.avg_latency || 0).toFixed(0) }}ms</div>
              </div>
            </div>

            <!-- By Action -->
            <div class="surface-card rounded-lg p-6">
              <h3 class="text-lg font-semibold txt-primary mb-4 flex items-center gap-2">
                <Icon icon="mdi:gesture-tap" class="w-5 h-5" />
                {{ $t('admin.usage.byAction') }}
              </h3>
              <div class="space-y-2">
                <div
                  v-for="(stats, action) in usageStats.byAction"
                  :key="action"
                  class="flex items-center justify-between py-2 px-4 rounded-lg bg-chat"
                >
                  <span class="txt-primary font-medium">{{ action }}</span>
                  <div class="flex gap-6 text-sm txt-secondary">
                    <span>{{ stats.count.toLocaleString() }} {{ $t('admin.usage.requests') }}</span>
                    <span>{{ stats.tokens.toLocaleString() }} {{ $t('admin.usage.tokens') }}</span>
                    <span>${{ stats.cost.toFixed(4) }}</span>
                  </div>
                </div>
              </div>
            </div>

            <!-- By Provider -->
            <div class="surface-card rounded-lg p-6">
              <h3 class="text-lg font-semibold txt-primary mb-4 flex items-center gap-2">
                <Icon icon="mdi:server-network" class="w-5 h-5" />
                {{ $t('admin.usage.byProvider') }}
              </h3>
              <div class="space-y-2">
                <div
                  v-for="(stats, provider) in usageStats.byProvider"
                  :key="provider"
                  class="flex items-center justify-between py-2 px-4 rounded-lg bg-chat"
                >
                  <span class="txt-primary font-medium">{{ provider }}</span>
                  <div class="flex gap-6 text-sm txt-secondary">
                    <span>{{ stats.count.toLocaleString() }} {{ $t('admin.usage.requests') }}</span>
                    <span>{{ stats.tokens.toLocaleString() }} {{ $t('admin.usage.tokens') }}</span>
                    <span>${{ stats.cost.toFixed(4) }}</span>
                  </div>
                </div>
              </div>
            </div>

            <!-- Top Users -->
            <div class="surface-card rounded-lg p-6">
              <h3 class="text-lg font-semibold txt-primary mb-4 flex items-center gap-2">
                <Icon icon="mdi:trophy" class="w-5 h-5" />
                {{ $t('admin.usage.topUsers') }}
              </h3>
              <div class="overflow-x-auto">
                <table class="w-full">
                  <thead>
                    <tr class="border-b border-light-border/30 dark:border-dark-border/20">
                      <th class="text-left py-2 px-4 text-sm font-medium txt-secondary">#</th>
                      <th class="text-left py-2 px-4 text-sm font-medium txt-secondary">{{ $t('admin.users.email') }}</th>
                      <th class="text-left py-2 px-4 text-sm font-medium txt-secondary">{{ $t('admin.users.level') }}</th>
                      <th class="text-right py-2 px-4 text-sm font-medium txt-secondary">{{ $t('admin.usage.requests') }}</th>
                      <th class="text-right py-2 px-4 text-sm font-medium txt-secondary">{{ $t('admin.usage.tokens') }}</th>
                      <th class="text-right py-2 px-4 text-sm font-medium txt-secondary">{{ $t('admin.usage.cost') }}</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr
                      v-for="(user, index) in usageStats.topUsers"
                      :key="user.id"
                      class="border-b border-light-border/30 dark:border-dark-border/20"
                    >
                      <td class="py-3 px-4 txt-secondary text-sm">{{ index + 1 }}</td>
                      <td class="py-3 px-4 txt-primary">{{ user.email }}</td>
                      <td class="py-3 px-4">
                        <span :class="getLevelBadgeClass(user.level)">{{ user.level }}</span>
                      </td>
                      <td class="py-3 px-4 text-right txt-secondary">{{ user.requests.toLocaleString() }}</td>
                      <td class="py-3 px-4 text-right txt-secondary">{{ user.tokens.toLocaleString() }}</td>
                      <td class="py-3 px-4 text-right txt-secondary">${{ user.cost.toFixed(2) }}</td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <Teleport to="body">
      <Transition name="modal">
        <div
          v-if="showDeleteModal"
          class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm"
          @click.self="showDeleteModal = false"
          data-testid="modal-delete-user"
        >
          <div class="surface-elevated w-full max-w-md p-6 m-4" data-testid="modal-delete-user-content">
            <div class="flex items-center justify-center mb-4">
              <Icon icon="mdi:alert-circle-outline" class="w-12 h-12 text-red-500" />
            </div>
            <h3 class="text-xl font-bold text-center txt-primary mb-2">{{ $t('admin.users.deleteConfirmTitle') }}</h3>
            <p class="text-center txt-secondary mb-6">
              {{ $t('admin.users.deleteConfirmDesc', { email: userToDelete?.email }) }}
            </p>

            <div class="flex justify-end gap-3">
              <button
                @click="showDeleteModal = false"
                class="btn-secondary py-2 px-4 rounded-lg"
                data-testid="btn-cancel-delete-user"
              >
                {{ $t('common.cancel') }}
              </button>
              <button
                @click="deleteUser()"
                class="btn-danger py-2 px-4 rounded-lg"
                data-testid="btn-confirm-delete-user"
              >
                {{ $t('common.delete') }}
              </button>
            </div>
          </div>
        </div>
      </Transition>
    </Teleport>
  </MainLayout>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { Icon } from '@iconify/vue'
import MainLayout from '@/components/MainLayout.vue'
import RegistrationChart from '@/components/admin/RegistrationChart.vue'
import UsageChart from '@/components/admin/UsageChart.vue'
import { adminApi, type AdminUser, type SystemPrompt, type UsageStats, type SystemOverview, type RegistrationAnalytics } from '@/services/api/adminApi'
import { useAuthStore } from '@/stores/auth'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const authStore = useAuthStore()

type TabId = 'overview' | 'users' | 'prompts' | 'usage'
interface AdminTab {
  id: TabId
  label: string
  icon: string
}

// Tabs
const activeTab = ref<TabId>('overview')
const tabs = computed<AdminTab[]>(() => [
  { id: 'overview', label: t('admin.tabs.overview'), icon: 'mdi:view-dashboard' },
  { id: 'users', label: t('admin.tabs.users'), icon: 'mdi:account-multiple' },
  { id: 'prompts', label: t('admin.tabs.prompts'), icon: 'mdi:text-box-multiple' },
  { id: 'usage', label: t('admin.tabs.usage'), icon: 'mdi:chart-bar' },
])

// Overview
const overview = ref<SystemOverview | null>(null)
const overviewLoading = ref(false)
const recentUsers = computed(() => overview.value?.recentUsers ?? [])

// Registration Analytics
const registrationAnalytics = ref<RegistrationAnalytics | null>(null)
const analyticsPeriod = ref<'7d' | '30d' | '90d' | '1y' | 'all'>('30d')
const analyticsGroupBy = ref<'day' | 'week' | 'month'>('day')

// Users
const users = ref<AdminUser[]>([])
const usersLoading = ref(false)
const userSearch = ref('')
const currentPage = ref(1)
const itemsPerPage = ref(50)
const totalUsers = ref(0)
const totalPages = computed(() => Math.ceil(totalUsers.value / itemsPerPage.value))
const currentUserId = computed(() => authStore.user?.id)

// Prompts
const prompts = ref<SystemPrompt[]>([])
const promptsLoading = ref(false)
const editingPromptId = ref<number | null>(null)
const editingPrompt = ref<Partial<SystemPrompt>>({})
const promptSaving = ref(false)

// Usage Stats
const usageStats = ref<UsageStats | null>(null)
const usageStatsLoading = ref(false)
const usageStatsPeriod = ref<'day' | 'week' | 'month' | 'all'>('week')

// Delete Modal
const showDeleteModal = ref(false)
const userToDelete = ref<AdminUser | null>(null)

// Load data based on active tab
watch(activeTab, (newTab: string) => {
  if (newTab === 'overview') {
    if (!overview.value) loadOverview()
    if (!registrationAnalytics.value) loadRegistrationAnalytics()
  } else if (newTab === 'users' && users.value.length === 0) {
    loadUsers()
  } else if (newTab === 'prompts' && prompts.value.length === 0) {
    loadPrompts()
  } else if (newTab === 'usage' && !usageStats.value) {
    loadUsageStats()
  }
})

watch(currentPage, () => {
  loadUsers()
})

// Load functions
async function loadOverview() {
  overviewLoading.value = true
  try {
    overview.value = await adminApi.getOverview()
  } catch (error) {
    console.error('Failed to load overview:', error)
  } finally {
    overviewLoading.value = false
  }
}

async function loadRegistrationAnalytics() {
  try {
    registrationAnalytics.value = await adminApi.getRegistrationAnalytics(analyticsPeriod.value, analyticsGroupBy.value)
  } catch (error) {
    console.error('Failed to load registration analytics:', error)
  }
}

async function updateAnalyticsPeriod(newPeriod: string) {
  analyticsPeriod.value = newPeriod as any
  await loadRegistrationAnalytics()
}

async function updateAnalyticsGroupBy(newGroupBy: string) {
  analyticsGroupBy.value = newGroupBy as any
  await loadRegistrationAnalytics()
}

async function loadUsers() {
  usersLoading.value = true
  try {
    const response = await adminApi.getUsers(currentPage.value, itemsPerPage.value, userSearch.value)
    users.value = response.users
    totalUsers.value = response.total
  } catch (error) {
    console.error('Failed to load users:', error)
  } finally {
    usersLoading.value = false
  }
}

const debouncedSearchUsers = (() => {
  let timeout: ReturnType<typeof setTimeout> | null = null
  return () => {
    if (timeout) clearTimeout(timeout)
    timeout = setTimeout(() => {
      currentPage.value = 1
      loadUsers()
    }, 300)
  }
})()

async function loadPrompts() {
  promptsLoading.value = true
  try {
    const response = await adminApi.getSystemPrompts()
    prompts.value = response.prompts
  } catch (error) {
    console.error('Failed to load prompts:', error)
  } finally {
    promptsLoading.value = false
  }
}

async function loadUsageStats(period: 'day' | 'week' | 'month' | 'all' = 'week') {
  usageStatsLoading.value = true
  usageStatsPeriod.value = period
  try {
    usageStats.value = await adminApi.getUsageStats(period)
  } catch (error) {
    console.error('Failed to load usage stats:', error)
  } finally {
    usageStatsLoading.value = false
  }
}

// User actions
async function updateUserLevel(userId: number, newLevel: string) {
  try {
    await adminApi.updateUserLevel(userId, newLevel)
    // Update local state
    const user = users.value.find((u: AdminUser) => u.id === userId)
    if (user) {
      user.level = newLevel
    }
  } catch (error) {
    console.error('Failed to update user level:', error)
  }
}

function confirmDeleteUser(user: AdminUser) {
  userToDelete.value = user
  showDeleteModal.value = true
}

async function deleteUser() {
  if (!userToDelete.value) return

  try {
    await adminApi.deleteUser(userToDelete.value.id)
    // Remove from local state
    users.value = users.value.filter((u: AdminUser) => u.id !== userToDelete.value!.id)
    totalUsers.value--
    showDeleteModal.value = false
    userToDelete.value = null
  } catch (error) {
    console.error('Failed to delete user:', error)
  }
}

// Prompt actions
function togglePromptEdit(promptId: number) {
  if (editingPromptId.value === promptId) {
    cancelEditPrompt()
  } else {
    const prompt = prompts.value.find((p: SystemPrompt) => p.id === promptId)
    if (prompt) {
      editingPromptId.value = promptId
      editingPrompt.value = {
        shortDescription: prompt.shortDescription,
        prompt: prompt.prompt,
        selectionRules: prompt.selectionRules || '',
      }
    }
  }
}

function cancelEditPrompt() {
  editingPromptId.value = null
  editingPrompt.value = {}
}

async function savePrompt(promptId: number) {
  promptSaving.value = true
  try {
    const response = await adminApi.updatePrompt(promptId, editingPrompt.value)
    // Update local state
    const index = prompts.value.findIndex((p: SystemPrompt) => p.id === promptId)
    if (index !== -1) {
      prompts.value[index] = response.prompt
    }
    cancelEditPrompt()
  } catch (error) {
    console.error('Failed to save prompt:', error)
  } finally {
    promptSaving.value = false
  }
}

// Helpers
function getLevelIcon(level: string): string {
  const icons: Record<string, string> = {
    'NEW': 'mdi:star-outline',
    'PRO': 'mdi:star',
    'TEAM': 'mdi:account-group',
    'BUSINESS': 'mdi:office-building',
    'ADMIN': 'mdi:shield-crown',
  }
  return icons[level] || 'mdi:account'
}

function getLevelBadgeClass(level: string): string {
  const classes: Record<string, string> = {
    'NEW': 'badge-level badge-new',
    'PRO': 'badge-level badge-pro',
    'TEAM': 'badge-level badge-team',
    'BUSINESS': 'badge-level badge-business',
    'ADMIN': 'badge-level badge-admin',
  }
  return classes[level] || classes['NEW']
}

function formatDate(dateStr: string): string {
  try {
    const date = new Date(dateStr)
    return date.toLocaleDateString() + ' ' + date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
  } catch {
    return dateStr
  }
}

// Initialize
onMounted(() => {
  loadOverview()
  loadRegistrationAnalytics()
})
</script>

