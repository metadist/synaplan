<template>
  <div
    v-if="due"
    class="fixed bottom-4 left-4 right-4 z-40 mx-auto max-w-lg surface-card border border-light-border/30 dark:border-dark-border/20 rounded-xl p-4 shadow-lg space-y-3"
    data-testid="card-ai-literacy"
    role="dialog"
    aria-labelledby="ai-literacy-ask"
  >
    <p id="ai-literacy-ask" class="text-sm txt-primary">{{ t('people.literacy.ask') }}</p>
    <div class="flex flex-wrap gap-2">
      <button
        type="button"
        class="btn-primary px-4 py-2.5 text-sm font-medium"
        data-testid="btn-literacy-read"
        @click="readNow"
      >
        {{ t('people.literacy.read') }}
      </button>
      <button
        type="button"
        class="btn-secondary px-4 py-2.5 text-sm font-medium"
        data-testid="btn-literacy-remind"
        @click="remind"
      >
        {{ t('people.literacy.remind') }}
      </button>
    </div>
  </div>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { myAiLiteracy, remindAiLiteracy } from '@/services/api/aiLiteracyApi'

const { t } = useI18n()
const router = useRouter()
const auth = useAuthStore()
const due = ref(false)

onMounted(async () => {
  if (!auth.isAuthenticated) {
    return
  }
  try {
    const status = await myAiLiteracy()
    due.value = status.due
  } catch {
    due.value = false
  }
})

function readNow(): void {
  due.value = false
  void router.push({ name: 'using-ai-well' })
}

async function remind(): Promise<void> {
  due.value = false
  try {
    await remindAiLiteracy()
  } catch {
    due.value = false
  }
}
</script>
