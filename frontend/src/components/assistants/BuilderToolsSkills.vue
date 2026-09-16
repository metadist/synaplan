<template>
  <section class="surface-card rounded-xl p-5 space-y-4" data-testid="section-builder-tools">
    <h2 class="txt-primary font-medium">{{ $t('assistants.toolsSkills') }}</h2>
    <p class="txt-secondary text-sm">{{ $t('assistants.toolsSkillsHint') }}</p>

    <label class="flex items-start gap-2">
      <input
        type="checkbox"
        class="mt-1"
        :checked="tools.internet"
        data-testid="chk-tool-internet"
        @change="patchTool('internet', ($event.target as HTMLInputElement).checked)"
      />
      <span>
        <span class="txt-primary text-sm">{{ $t('assistants.toolInternet') }}</span>
        <span class="block txt-secondary text-sm">{{ $t('assistants.toolInternetHint') }}</span>
      </span>
    </label>

    <label class="flex items-start gap-2">
      <input
        type="checkbox"
        class="mt-1"
        :checked="tools.files"
        data-testid="chk-tool-files"
        @change="patchTool('files', ($event.target as HTMLInputElement).checked)"
      />
      <span>
        <span class="txt-primary text-sm">{{ $t('assistants.toolFiles') }}</span>
        <span class="block txt-secondary text-sm">{{ $t('assistants.toolFilesHint') }}</span>
      </span>
    </label>

    <label class="block">
      <span class="txt-secondary text-sm">{{ $t('assistants.mcpServers') }}</span>
      <select
        multiple
        class="mt-1 w-full px-3 py-2 rounded-lg surface-card border border-light-border/30 dark:border-dark-border/20 txt-primary text-sm focus:outline-none focus:ring-2 focus:ring-[var(--brand)] min-h-24"
        data-testid="select-mcp-servers"
        :value="tools.mcpServers.map(String)"
        @change="onMcpChange($event)"
      >
        <option v-for="server in mcpServers" :key="server.id" :value="String(server.id)">
          {{ server.name }}
        </option>
      </select>
      <span class="block txt-secondary text-sm mt-1">{{ $t('assistants.mcpServersHint') }}</span>
    </label>

    <div class="space-y-2">
      <p class="txt-primary text-sm font-medium">{{ $t('assistants.skillsAllow') }}</p>
      <p class="txt-secondary text-sm">{{ $t('assistants.skillsAllowHint') }}</p>
      <div class="flex flex-wrap gap-2">
        <button
          v-for="skill in skillChoices"
          :key="skill"
          type="button"
          class="px-3 py-1.5 rounded-lg text-sm font-medium"
          :class="isSkillAllowed(skill) ? 'btn-primary' : 'btn-secondary'"
          :data-testid="`chip-skill-${skill}`"
          @click="toggleSkill(skill)"
        >
          {{ $t(`assistants.skill.${skill}`) }}
        </button>
      </div>
    </div>
  </section>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { emptyAgentDraft } from '@/services/api/agentsApi'
import { mcpServersApi, type McpServer } from '@/services/api/mcpServersApi'
import { useAgentsStore } from '@/stores/agents'

const SKILL_CHOICES = [
  'chat',
  'rag_query',
  'web_search',
  'url_fetch',
  'email_me',
  'summarize',
  'translate',
  'file_analysis',
  'document_generation',
  'image_generation',
  'calendar_event',
  'save_to_folder',
] as const

const store = useAgentsStore()
const mcpServers = ref<McpServer[]>([])

const tools = computed(() => store.current?.draft?.tools ?? emptyAgentDraft().tools)
const skills = computed(() => store.current?.draft?.skills ?? emptyAgentDraft().skills)
const skillChoices = SKILL_CHOICES

function patchTool(key: 'internet' | 'files', value: boolean): void {
  if (!store.current) {
    return
  }
  const draft = store.current.draft ?? emptyAgentDraft()
  store.current.draft = {
    ...draft,
    tools: { ...draft.tools, [key]: value },
  }
  store.markDirty()
}

function onMcpChange(event: Event): void {
  if (!store.current) {
    return
  }
  const select = event.target as HTMLSelectElement
  const ids = Array.from(select.selectedOptions).map((option) => Number(option.value))
  const draft = store.current.draft ?? emptyAgentDraft()
  store.current.draft = {
    ...draft,
    tools: { ...draft.tools, mcpServers: ids },
  }
  store.markDirty()
}

function isSkillAllowed(skill: string): boolean {
  const allow = skills.value.allow
  const deny = skills.value.deny
  if (deny.includes(skill)) {
    return false
  }
  if (allow.length === 0) {
    return true
  }
  return allow.includes(skill)
}

function toggleSkill(skill: string): void {
  if (!store.current) {
    return
  }
  const draft = store.current.draft ?? emptyAgentDraft()
  const current = draft.skills
  let allow = [...current.allow]
  let deny = [...current.deny]
  if (isSkillAllowed(skill)) {
    deny = deny.includes(skill) ? deny : [...deny, skill]
    allow = allow.filter((item) => item !== skill)
  } else {
    deny = deny.filter((item) => item !== skill)
    if (allow.length > 0 && !allow.includes(skill)) {
      allow = [...allow, skill]
    }
  }
  store.current.draft = {
    ...draft,
    skills: { allow, deny },
  }
  store.markDirty()
}

onMounted(async () => {
  try {
    const result = await mcpServersApi.list()
    mcpServers.value = result.servers
  } catch {
    mcpServers.value = []
  }
})
</script>
