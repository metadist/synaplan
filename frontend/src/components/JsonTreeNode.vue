<template>
  <li class="json-tree-node">
    <template v-if="isExpandable">
      <button
        type="button"
        class="inline-flex items-start gap-1 text-left txt-primary hover:opacity-80"
        :aria-expanded="open"
        :aria-label="open ? $t('message.jsonViewer.collapse') : $t('message.jsonViewer.expand')"
        @click="open = !open"
      >
        <span class="txt-secondary mt-0.5 w-3 shrink-0 select-none" aria-hidden="true">{{
          open ? '▾' : '▸'
        }}</span>
        <span v-if="label !== undefined" class="json-tree-key">{{ label }}</span>
        <span v-if="label !== undefined" class="txt-secondary">: </span>
        <span class="txt-secondary">{{ preview }}</span>
      </button>
      <ul
        v-if="open"
        class="m-0 mt-0.5 list-none border-l border-light-border/30 py-0.5 pl-3 dark:border-dark-border/20"
      >
        <JsonTreeNode
          v-for="(child, index) in children"
          :key="index"
          :value="child.value"
          :label="child.label"
          :depth="depth + 1"
        />
      </ul>
    </template>
    <template v-else>
      <span v-if="label !== undefined" class="json-tree-key">{{ label }}</span>
      <span v-if="label !== undefined" class="txt-secondary">: </span>
      <span :class="leafClass">{{ leafText }}</span>
    </template>
  </li>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import JsonTreeNode from './JsonTreeNode.vue'

defineOptions({ name: 'JsonTreeNode' })

const props = defineProps<{
  value: unknown
  label?: string
  depth?: number
}>()

const depth = computed(() => props.depth ?? 0)
const open = ref(depth.value === 0)

const isExpandable = computed(() => {
  if (props.value === null || typeof props.value !== 'object') {
    return false
  }
  return Object.keys(props.value as object).length > 0
})

const children = computed(() => {
  if (Array.isArray(props.value)) {
    return props.value.map((item, index) => ({
      label: String(index),
      value: item,
    }))
  }
  if (props.value !== null && typeof props.value === 'object') {
    return Object.entries(props.value as Record<string, unknown>).map(([key, value]) => ({
      label: key,
      value,
    }))
  }
  return []
})

const preview = computed(() => {
  if (Array.isArray(props.value)) {
    return `[${props.value.length}]`
  }
  if (props.value !== null && typeof props.value === 'object') {
    return `{${Object.keys(props.value as object).length}}`
  }
  return ''
})

const leafText = computed(() => {
  if (props.value === null) {
    return 'null'
  }
  if (Array.isArray(props.value) && props.value.length === 0) {
    return '[]'
  }
  if (
    props.value !== null &&
    typeof props.value === 'object' &&
    Object.keys(props.value).length === 0
  ) {
    return '{}'
  }
  if (typeof props.value === 'string') {
    return JSON.stringify(props.value)
  }
  return String(props.value)
})

const leafClass = computed(() => {
  if (props.value === null) {
    return 'json-tree-null'
  }
  if (typeof props.value === 'string') {
    return 'json-tree-string'
  }
  if (typeof props.value === 'number') {
    return 'json-tree-number'
  }
  if (typeof props.value === 'boolean') {
    return 'json-tree-bool'
  }
  return 'txt-secondary'
})
</script>

<style scoped>
.json-tree-key {
  color: var(--txt-primary);
  font-weight: 600;
}

.json-tree-string {
  color: var(--brand);
}

.json-tree-number,
.json-tree-bool,
.json-tree-null {
  color: var(--txt-secondary);
}
</style>
