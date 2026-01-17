<template>
  <Teleport to="body">
    <Transition name="fade">
      <div
        v-if="isOpen"
        class="fixed inset-0 bg-black/50 z-[110] flex items-center justify-center p-4"
        @click.self="close"
      >
        <div class="surface-card rounded-xl shadow-2xl max-w-lg w-full overflow-hidden" @click.stop>
          <!-- Header -->
          <div
            class="flex items-center justify-between p-6 border-b border-light-border/10 dark:border-dark-border/10"
          >
            <h3 class="text-lg font-semibold txt-primary">
              {{ memory ? $t('memories.edit.title') : $t('memories.create.title') }}
            </h3>
            <button
              class="w-8 h-8 rounded-lg hover:bg-black/5 dark:hover:bg-white/5 flex items-center justify-center transition-colors"
              @click="close"
            >
              <Icon icon="mdi:close" class="w-5 h-5 txt-secondary" />
            </button>
          </div>

          <!-- Form -->
          <form class="p-6 space-y-4" @submit.prevent="handleSubmit">
            <div>
              <label class="block text-sm font-medium txt-primary mb-2">
                {{ $t('memories.create.category') }}
              </label>
              <input
                v-model="formData.category"
                type="text"
                :placeholder="$t('memories.create.categoryPlaceholder')"
                required
                list="category-suggestions"
                class="w-full px-4 py-2.5 rounded-lg surface-chip txt-primary placeholder:txt-secondary focus:outline-none focus:ring-2 focus:ring-brand/50 transition-all"
              />
              <datalist id="category-suggestions">
                <option v-for="category in availableCategories" :key="category" :value="category" />
              </datalist>
              <p class="mt-1 text-xs txt-secondary">{{ $t('memories.create.categoryHint') }}</p>
            </div>

            <div>
              <label class="block text-sm font-medium txt-primary mb-2">
                {{ $t('memories.create.key') }}
              </label>
              <input
                v-model="formData.key"
                type="text"
                required
                :placeholder="$t('memories.create.keyPlaceholder')"
                class="w-full px-4 py-2.5 rounded-lg surface-chip txt-primary placeholder:txt-secondary focus:outline-none focus:ring-2 focus:ring-brand/50 transition-all"
              />
            </div>

            <div>
              <label class="block text-sm font-medium txt-primary mb-2">
                {{ $t('memories.create.value') }}
              </label>
              <textarea
                v-model="formData.value"
                required
                :placeholder="$t('memories.create.valuePlaceholder')"
                rows="4"
                class="w-full px-4 py-2.5 rounded-lg surface-chip txt-primary placeholder:txt-secondary focus:outline-none focus:ring-2 focus:ring-brand/50 resize-none transition-all"
              ></textarea>
            </div>

            <!-- Actions -->
            <div class="flex items-center gap-3 pt-4">
              <button
                type="button"
                class="flex-1 btn-secondary px-4 py-2.5 rounded-lg font-medium transition-all"
                @click="close"
              >
                {{ $t('common.cancel') }}
              </button>
              <button type="submit" class="flex-1 btn-primary px-4 py-2.5 rounded-lg font-medium">
                {{ $t('common.save') }}
              </button>
            </div>
          </form>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<script setup lang="ts">
import { ref, watch } from 'vue'
import { Icon } from '@iconify/vue'
import type {
  UserMemory,
  CreateMemoryRequest,
  UpdateMemoryRequest,
} from '@/services/api/userMemoriesApi'

interface Props {
  isOpen: boolean
  memory?: UserMemory | null
  availableCategories?: string[]
}

interface Emits {
  (e: 'close'): void
  (e: 'save', data: CreateMemoryRequest | UpdateMemoryRequest): void
}

const props = defineProps<Props>()
const emit = defineEmits<Emits>()

const formData = ref({
  category: 'preferences',
  key: '',
  value: '',
})

watch(
  () => props.memory,
  (memory) => {
    if (memory) {
      formData.value = {
        category: memory.category,
        key: memory.key,
        value: memory.value,
      }
    } else {
      formData.value = {
        category: 'preferences',
        key: '',
        value: '',
      }
    }
  },
  { immediate: true }
)

function close() {
  emit('close')
}

function handleSubmit() {
  emit('save', {
    category: formData.value.category,
    key: formData.value.key,
    value: formData.value.value,
  })
}
</script>

<style scoped>
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.2s ease;
}

.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}
</style>
