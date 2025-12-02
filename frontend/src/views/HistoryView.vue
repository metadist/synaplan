<template>
  <MainLayout>
    <template #header>
      <div class="flex items-center gap-3">
        <ArrowUturnLeftIcon class="w-6 h-6 text-primary" />
        <h1 class="text-xl font-semibold txt-primary">
          {{ $t('history.title') }}
        </h1>
      </div>
    </template>

    <div class="max-w-7xl mx-auto p-6" data-testid="page-history">
      <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-8" data-testid="section-stats">
        <div class="surface-card p-6" data-testid="item-stat-card">
          <p class="text-sm txt-secondary mb-2">
            {{ $t('history.totalPrompts') }}
          </p>
          <p class="text-3xl font-bold" style="color: var(--brand)">79</p>
        </div>

        <div class="surface-card p-6" data-testid="item-stat-card">
          <p class="text-sm txt-secondary mb-2">
            {{ $t('history.withAttachments') }}
          </p>
          <p class="text-3xl font-bold" style="color: var(--brand)">17</p>
        </div>

        <div class="surface-card p-6" data-testid="item-stat-card">
          <p class="text-sm txt-secondary mb-2">
            {{ $t('history.conversations') }}
          </p>
          <p class="text-3xl font-bold" style="color: var(--brand)">26</p>
        </div>

        <div class="surface-card p-6" data-testid="item-stat-card">
          <p class="text-sm txt-secondary mb-2">
            {{ $t('history.dateRange') }}
          </p>
          <p class="text-sm font-medium txt-primary">
            Invalid Date - Invalid Date
          </p>
        </div>
      </div>

      <div class="surface-card p-6 mb-6" data-testid="section-filters">
        <div class="flex items-center gap-2 mb-4">
          <FunnelIcon class="w-5 h-5 txt-secondary" />
          <h2 class="text-lg font-semibold txt-primary">
            {{ $t('history.searchFilters') }}
          </h2>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
          <div class="md:col-span-2">
            <label class="block text-sm font-medium txt-primary mb-2">
              {{ $t('history.keywordSearch') }}
            </label>
            <input
              v-model="searchKeyword"
              type="text"
              placeholder="Search in messages..."
              class="w-full rounded-lg surface-chip txt-primary px-4 py-2 min-h-[44px] focus:outline-none focus:ring-2 focus:ring-[var(--brand)] transition-all"
              data-testid="input-keyword"
            />
          </div>

          <div>
            <label class="block text-sm font-medium txt-primary mb-2">
              {{ $t('history.hasFiles') }}
            </label>
            <Select v-model="hasFiles" data-testid="input-has-files">
              <option value="all">{{ $t('history.all') }}</option>
              <option value="yes">Yes</option>
              <option value="no">No</option>
            </Select>
          </div>

          <div>
            <label class="block text-sm font-medium txt-primary mb-2">
              {{ $t('history.fromDate') }}
            </label>
            <input
              v-model="fromDate"
              type="date"
              class="w-full rounded-lg surface-chip txt-primary px-4 py-2 min-h-[44px] focus:outline-none focus:ring-2 focus:ring-[var(--brand)] transition-all"
              data-testid="input-from-date"
            />
          </div>
        </div>

        <div class="flex items-center gap-3 mt-4">
          <Button variant="primary" size="md" data-testid="btn-apply">
            <MagnifyingGlassIcon class="w-5 h-5" />
            {{ $t('history.applyFilters') }}
          </Button>
          <Button variant="secondary" size="md" data-testid="btn-clear">
            <XMarkIcon class="w-5 h-5 txt-secondary" />
            {{ $t('history.clear') }}
          </Button>
        </div>
      </div>

      <div class="space-y-4 max-h-[600px] overflow-y-auto scroll-thin" data-testid="section-history-list">
        <div
          v-for="item in historyItems"
          :key="item.id"
          class="surface-card p-6"
          data-testid="item-history"
        >
          <div class="flex items-start justify-between mb-3">
            <div class="flex flex-wrap gap-2">
              <span class="surface-chip px-3 py-1 text-xs font-medium txt-primary">
                User Request
              </span>
              <span class="surface-chip px-3 py-1 text-xs font-medium txt-primary">
                general
              </span>
            </div>
            <span class="text-xs txt-secondary">
              {{ item.timestamp }}
            </span>
          </div>

          <p class="txt-primary mb-4">
            {{ item.content }}
          </p>

          <Button variant="secondary" size="sm">
            Load AI Answer
          </Button>
        </div>
      </div>
    </div>
  </MainLayout>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { ArrowUturnLeftIcon, FunnelIcon, MagnifyingGlassIcon, XMarkIcon } from '@heroicons/vue/24/outline'
import MainLayout from '../components/MainLayout.vue'
import Button from '../components/Button.vue'
import Select from '../components/Select.vue'
import { mockHistoryItems } from '@/mocks/history'

const searchKeyword = ref('')
const hasFiles = ref('all')
const fromDate = ref('')

const historyItems = ref(mockHistoryItems)
</script>
