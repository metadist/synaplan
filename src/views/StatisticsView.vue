<template>
  <MainLayout>
    <div class="flex flex-col h-full overflow-y-auto bg-chat scroll-thin">
      <div class="max-w-7xl mx-auto w-full px-6 py-8">
        <!-- Header -->
        <div class="mb-8 flex items-center justify-between">
          <h1 class="text-3xl font-semibold txt-primary">
            {{ $t('statistics.title') }}
          </h1>
          <div class="flex gap-3">
            <router-link
              to="/"
              class="pill pill--active"
            >
              <ChatBubbleLeftRightIcon class="w-5 h-5" />
              <span class="text-sm font-medium">Chat</span>
            </router-link>
            <button
              @click="navigateToHistory"
              class="pill"
            >
              <SparklesIcon class="w-5 h-5" />
              <span class="text-sm font-medium">Prompts</span>
            </button>
          </div>
        </div>

        <!-- Message Statistics Section -->
        <div class="mb-8">
          <div class="flex items-center gap-2 mb-4">
            <ChartBarIcon class="w-5 h-5 txt-primary" />
            <h2 class="text-lg font-semibold txt-primary">
              {{ $t('statistics.messageStats') }}
            </h2>
          </div>

          <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            <!-- Total Messages -->
            <div class="surface-card p-6">
              <div class="text-4xl font-bold txt-primary mb-2">
                {{ stats.totalMessages }}
              </div>
              <div class="text-sm txt-secondary">
                {{ $t('statistics.totalMessages') }}
              </div>
            </div>

            <!-- Messages Sent -->
            <div class="surface-card p-6">
              <div class="text-4xl font-bold text-[#10B981] mb-2">
                {{ stats.messagesSent }}
              </div>
              <div class="text-sm txt-secondary">
                {{ $t('statistics.messagesSent') }}
              </div>
            </div>

            <!-- Messages Received -->
            <div class="surface-card p-6">
              <div class="text-4xl font-bold text-[#06B6D4] mb-2">
                {{ stats.messagesReceived }}
              </div>
              <div class="text-sm txt-secondary">
                {{ $t('statistics.messagesReceived') }}
              </div>
            </div>

            <!-- Total Files -->
            <div class="surface-card p-6">
              <div class="text-4xl font-bold text-[#F59E0B] mb-2">
                {{ stats.totalFiles }}
              </div>
              <div class="text-sm txt-secondary">
                {{ $t('statistics.totalFiles') }}
              </div>
            </div>

            <!-- Files Sent -->
            <div class="surface-card p-6">
              <div class="text-4xl font-bold text-[#10B981] mb-2">
                {{ stats.filesSent }}
              </div>
              <div class="text-sm txt-secondary">
                {{ $t('statistics.filesSent') }}
              </div>
            </div>

            <!-- Files Received -->
            <div class="surface-card p-6">
              <div class="text-4xl font-bold text-[#06B6D4] mb-2">
                {{ stats.filesReceived }}
              </div>
              <div class="text-sm txt-secondary">
                {{ $t('statistics.filesReceived') }}
              </div>
            </div>
          </div>
        </div>

        <!-- Latest Files Section -->
        <div>
          <div class="flex items-center gap-2 mb-4">
            <FolderIcon class="w-5 h-5 txt-primary" />
            <h2 class="text-lg font-semibold txt-primary">
              {{ $t('statistics.latestFiles') }}
            </h2>
          </div>

          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div
              v-for="file in latestFiles"
              :key="file.id"
              class="surface-card p-4"
            >
              <div class="flex items-start gap-3">
                <!-- File Icon -->
                <div class="flex-shrink-0">
                  <component
                    :is="getFileIcon(file.type)"
                    :class="[
                      'w-10 h-10',
                      getFileIconColor(file.type)
                    ]"
                  />
                </div>

                <!-- File Info -->
                <div class="flex-1 min-w-0">
                  <div class="font-medium txt-primary truncate mb-1">
                    {{ file.name }}
                  </div>
                  <div class="flex items-center gap-2 text-xs txt-secondary mb-2">
                    <component
                      :is="file.direction === 'received' ? ArrowDownTrayIcon : ArrowUpTrayIcon"
                      class="w-4 h-4"
                    />
                    <span>
                      {{ $t(`statistics.${file.direction}`) }}
                    </span>
                    <span>•</span>
                    <span>{{ formatDate(file.timestamp) }}</span>
                  </div>
                  <div v-if="file.description" class="text-sm txt-secondary">
                    {{ file.description }}
                  </div>
                  <div v-if="file.user" class="mt-2 inline-flex items-center gap-1.5 px-2 py-1 surface-chip text-xs font-medium txt-secondary">
                    {{ file.user }}
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </MainLayout>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import MainLayout from '../components/MainLayout.vue'
import { 
  ChartBarIcon, 
  FolderIcon, 
  ChatBubbleLeftRightIcon, 
  SparklesIcon,
  ArrowDownTrayIcon,
  ArrowUpTrayIcon,
  DocumentTextIcon,
  PhotoIcon,
  DocumentIcon,
  TableCellsIcon
} from '@heroicons/vue/24/outline'
import type { FileItem, Statistics } from '../mocks/statistics'
import { mockStatistics, mockLatestFiles } from '../mocks/statistics'

const router = useRouter()

const stats = ref<Statistics>({
  totalMessages: 0,
  messagesSent: 0,
  messagesReceived: 0,
  totalFiles: 0,
  filesSent: 0,
  filesReceived: 0,
})

const latestFiles = ref<FileItem[]>([])

const fetchStatistics = async () => {
  stats.value = mockStatistics
}

const fetchLatestFiles = async () => {
  latestFiles.value = mockLatestFiles
}

onMounted(() => {
  fetchStatistics()
  fetchLatestFiles()
})

const navigateToHistory = () => {
  router.push('/')
}

const getFileIcon = (type: FileItem['type']) => {
  switch (type) {
    case 'pdf':
      return DocumentTextIcon
    case 'image':
      return PhotoIcon
    case 'excel':
      return TableCellsIcon
    default:
      return DocumentIcon
  }
}

const getFileIconColor = (type: FileItem['type']) => {
  switch (type) {
    case 'pdf':
      return 'text-red-500'
    case 'image':
      return 'text-cyan-500'
    case 'excel':
      return 'text-green-600'
    default:
      return 'txt-secondary'
  }
}

const formatDate = (date: Date) => {
  return date.toLocaleString('de-DE', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}
</script>

