<script setup lang="ts">
interface JumpItem {
  id: string
  label: string
}

interface Props {
  items: JumpItem[]
  activeId?: string | null
  navLabel: string
}

withDefaults(defineProps<Props>(), {
  activeId: null,
})

defineEmits<{
  select: [id: string]
}>()
</script>

<template>
  <nav class="flex flex-wrap gap-2" :aria-label="navLabel" data-testid="section-jump-nav">
    <button
      v-for="item in items"
      :key="item.id"
      type="button"
      class="pill text-sm font-medium"
      :class="activeId === item.id && 'pill--active'"
      :data-testid="`btn-jump-section-${item.id}`"
      @click="$emit('select', item.id)"
    >
      {{ item.label }}
    </button>
  </nav>
</template>
