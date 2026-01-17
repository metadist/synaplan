<template>
  <div
    class="relative w-full overflow-hidden bg-gradient-to-br from-slate-900 via-purple-900 to-slate-900"
    style="min-height: 600px; height: 100%"
  >
    <!-- Empty State -->
    <div
      v-if="props.memories.length === 0"
      class="absolute inset-0 flex items-center justify-center z-20"
    >
      <div class="text-center text-white/70 max-w-md p-8">
        <Icon icon="mdi:brain" class="w-20 h-20 mx-auto mb-4 text-white/30" />
        <h3 class="text-xl font-semibold mb-2 text-white/90">{{ $t('memories.empty') }}</h3>
        <p class="text-sm">{{ $t('memories.emptyDesc') }}</p>
      </div>
    </div>

    <!-- Neural Network Canvas -->
    <canvas
      v-show="props.memories.length > 0"
      ref="canvasRef"
      class="absolute inset-0 w-full h-full touch-none"
      style="display: block"
      @mousedown="handleMouseDown"
      @mousemove="handleMouseMove"
      @mouseup="handleMouseUp"
      @touchstart="handleTouchStart"
      @touchmove="handleTouchMove"
      @touchend="handleTouchEnd"
      @wheel="handleWheel"
    ></canvas>

    <!-- Toggle Button - Compact & Elegant Top Left -->
    <div v-if="props.memories.length > 0" class="absolute top-4 left-4 z-20">
      <button
        class="px-3 md:px-4 py-2 md:py-2.5 rounded-lg text-xs md:text-sm font-medium transition-all duration-200 backdrop-blur-sm flex items-center gap-2 shadow-lg"
        :class="
          groupBy === 'category'
            ? 'bg-white/10 text-white hover:bg-white/20 border border-white/20'
            : 'bg-brand-500 text-white hover:bg-brand-600'
        "
        @click="toggleGroupBy"
      >
        <Icon
          :icon="groupBy === 'category' ? 'mdi:folder-multiple' : 'mdi:key'"
          class="w-4 h-4 md:w-5 md:h-5"
        />
        <span class="hidden sm:inline">
          {{
            groupBy === 'category'
              ? $t('memories.graph.groupingByCategory')
              : $t('memories.graph.groupingByKey')
          }}
        </span>
        <span class="sm:hidden">
          {{ groupBy === 'category' ? 'Kategorie' : 'Keys' }}
        </span>
      </button>
    </div>

    <!-- Filter Overlay (Category or Keys) -->
    <div
      v-if="props.memories.length > 0"
      class="absolute top-16 md:top-[4.5rem] left-4 z-10 max-w-[calc(100%-8rem)] md:max-w-3xl"
    >
      <!-- Category Filters -->
      <div v-if="groupBy === 'category'" class="flex flex-wrap gap-2">
        <button
          v-for="cat in availableCategories"
          :key="cat.category"
          class="px-3 py-1.5 rounded-full text-xs font-medium transition-all duration-200 whitespace-nowrap"
          :class="
            selectedCategories.includes(cat.category)
              ? 'bg-brand-500 text-white shadow-lg shadow-brand-500/50'
              : 'bg-white/10 text-white/70 hover:bg-white/20'
          "
          @click="toggleCategory(cat.category)"
        >
          <span
            class="inline-block w-2 h-2 rounded-full mr-1.5"
            :style="{ backgroundColor: categoryColors[cat.category] || categoryColors.default }"
          ></span>
          {{ $t(`memories.categories.${cat.category}`, cat.category) }} ({{ cat.count }})
        </button>
      </div>

      <!-- Key Filters -->
      <div v-else class="flex flex-wrap gap-2 max-h-32 overflow-y-auto scroll-thin">
        <button
          v-for="keyItem in availableKeys"
          :key="keyItem.key"
          class="px-3 py-1.5 rounded-lg text-xs font-medium transition-all duration-200 whitespace-nowrap"
          :class="
            selectedKeys.includes(keyItem.key)
              ? 'bg-brand-500 text-white shadow-lg shadow-brand-500/50'
              : 'bg-white/10 text-white/70 hover:bg-white/20'
          "
          @click="toggleKey(keyItem.key)"
        >
          {{ keyItem.key }} <span class="text-[10px] opacity-70">({{ keyItem.count }})</span>
        </button>
      </div>
    </div>

    <!-- No Results After Filter -->
    <div
      v-if="
        props.memories.length > 0 &&
        nodes.length === 0 &&
        (selectedCategories.length > 0 || selectedKeys.length > 0)
      "
      class="absolute inset-0 flex items-center justify-center z-20 p-4"
    >
      <div class="text-center text-white/70 max-w-md">
        <Icon
          icon="mdi:filter-off"
          class="w-12 h-12 md:w-16 md:h-16 mx-auto mb-3 md:mb-4 text-white/30"
        />
        <h3 class="text-base md:text-lg font-semibold mb-2 text-white/90">
          {{ $t('memories.noResults') }}
        </h3>
        <p class="text-xs md:text-sm mb-3 md:mb-4 px-4">
          {{
            groupBy === 'category'
              ? $t('memories.graph.noResultsDesc')
              : $t('memories.graph.noResultsKeys')
          }}
        </p>
        <button
          class="px-4 py-2 rounded-lg bg-brand-500 text-white hover:bg-brand-600 transition-colors text-sm"
          @click="clearFilters"
        >
          {{ $t('memories.graph.clearFilters', 'Filter zurücksetzen') }}
        </button>
      </div>
    </div>

    <!-- Controls -->
    <div
      v-if="props.memories.length > 0 && nodes.length > 0"
      class="absolute top-4 right-4 z-10 flex flex-row md:flex-col gap-2"
    >
      <button
        class="p-2 md:p-2.5 rounded-lg bg-white/10 text-white hover:bg-white/20 transition-colors backdrop-blur-sm"
        :title="$t('memories.graph.resetView')"
        @click="resetView"
      >
        <Icon icon="mdi:refresh" class="w-4 h-4 md:w-5 md:h-5" />
      </button>
      <button
        class="p-2 md:p-2.5 rounded-lg transition-colors backdrop-blur-sm"
        :class="
          physicsEnabled ? 'bg-brand-500 text-white' : 'bg-white/10 text-white hover:bg-white/20'
        "
        :title="$t('memories.graph.togglePhysics')"
        @click="togglePhysics"
      >
        <Icon icon="mdi:atom" class="w-4 h-4 md:w-5 md:h-5" />
      </button>
    </div>

    <!-- Memory Detail Panel -->
    <Transition
      enter-active-class="transition-all duration-300 ease-out"
      leave-active-class="transition-all duration-200 ease-in"
      enter-from-class="translate-y-full opacity-0"
      leave-to-class="translate-y-full opacity-0"
    >
      <div
        v-if="selectedMemory"
        class="absolute bottom-2 md:bottom-4 left-2 right-2 md:left-1/2 md:right-auto md:transform md:-translate-x-1/2 z-20 md:max-w-md md:w-full"
      >
        <div
          class="surface-card p-4 md:p-6 rounded-2xl backdrop-blur-xl bg-white/90 dark:bg-slate-800/90 shadow-2xl"
        >
          <div class="flex items-start justify-between mb-3 md:mb-4">
            <div class="flex-1 min-w-0">
              <div
                class="text-xs font-semibold uppercase tracking-wider mb-1 flex items-center gap-2"
              >
                <span
                  class="inline-block w-2 h-2 rounded-full flex-shrink-0"
                  :style="{
                    backgroundColor:
                      categoryColors[selectedMemory.category] || categoryColors.default,
                  }"
                ></span>
                <span class="txt-brand truncate">{{
                  $t(`memories.categories.${selectedMemory.category}`, selectedMemory.category)
                }}</span>
              </div>
              <h3 class="text-base md:text-lg font-bold txt-primary truncate">
                {{ selectedMemory.key }}
              </h3>
            </div>
            <button
              class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 p-1 -mr-1 flex-shrink-0"
              @click="selectedMemory = null"
            >
              <Icon icon="mdi:close" class="w-5 h-5" />
            </button>
          </div>
          <p class="txt-secondary text-xs md:text-sm mb-3 md:mb-4 line-clamp-3">
            {{ selectedMemory.value }}
          </p>
          <div class="flex items-center justify-between text-xs txt-tertiary mb-3 md:mb-4">
            <span>{{ $t(`memories.source.${selectedMemory.source}`) }}</span>
            <span class="truncate ml-2">{{ formatTimestamp(selectedMemory.updated) }}</span>
          </div>
          <div class="flex gap-2">
            <button class="btn-primary flex-1 py-2 text-sm" @click="handleEdit(selectedMemory)">
              <Icon icon="mdi:pencil" class="w-4 h-4 inline mr-1" />
              <span class="hidden sm:inline">{{ $t('common.edit') }}</span>
              <span class="sm:hidden">{{ $t('common.edit') }}</span>
            </button>
            <button class="btn-secondary flex-1 py-2 text-sm" @click="handleDelete(selectedMemory)">
              <Icon icon="mdi:delete" class="w-4 h-4 inline mr-1" />
              <span class="hidden sm:inline">{{ $t('common.delete') }}</span>
              <span class="sm:hidden">{{ $t('common.delete') }}</span>
            </button>
          </div>
        </div>
      </div>
    </Transition>

    <!-- Legend -->
    <div
      v-if="props.memories.length > 0"
      class="absolute bottom-2 md:bottom-4 left-2 md:left-4 z-10 surface-card p-3 md:p-4 rounded-lg backdrop-blur-xl bg-white/80 dark:bg-slate-800/80 max-w-[calc(100%-1rem)] md:max-w-xs"
    >
      <div class="text-xs font-semibold txt-primary mb-2">{{ $t('memories.graph.legend') }}</div>
      <div class="space-y-1.5 text-xs txt-secondary">
        <!-- Dynamic category colors -->
        <div
          v-for="cat in availableCategories.slice(0, 5)"
          :key="cat.category"
          class="flex items-center gap-2"
        >
          <div
            class="w-3 h-3 rounded-full flex-shrink-0"
            :style="{ backgroundColor: categoryColors[cat.category] || categoryColors.default }"
          ></div>
          <span class="truncate">{{
            $t(`memories.categories.${cat.category}`, cat.category)
          }}</span>
        </div>
        <div
          v-if="availableCategories.length > 5"
          class="flex items-center gap-2 txt-tertiary italic"
        >
          <span
            >+{{ availableCategories.length - 5 }}
            {{ $t('memories.graph.moreCategories', 'weitere') }}</span
          >
        </div>
        <div class="border-t border-light-border/20 dark:border-dark-border/20 pt-1.5 mt-2">
          <div class="flex items-center gap-2">
            <div class="w-3 h-0.5 bg-purple-500/50 flex-shrink-0"></div>
            <span>{{ $t('memories.graph.connection') }}</span>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, onBeforeUnmount, watch } from 'vue'
import { Icon } from '@iconify/vue'
import type { UserMemory } from '@/services/api/userMemoriesApi'

interface Props {
  memories: UserMemory[]
  availableCategories: Array<{ category: string; count: number }>
}

interface Emits {
  (e: 'edit', memory: UserMemory): void
  (e: 'delete', memory: UserMemory): void
}

const props = defineProps<Props>()
const emit = defineEmits<Emits>()

// Canvas & Animation
const canvasRef = ref<HTMLCanvasElement | null>(null)
let ctx: CanvasRenderingContext2D | null = null
let animationId: number | null = null

// Graph State
interface Node {
  id: number
  x: number
  y: number
  vx: number
  vy: number
  radius: number
  memory: UserMemory
  color: string
}

interface Edge {
  source: Node
  target: Node
}

const nodes = ref<Node[]>([])
const edges = ref<Edge[]>([])
const selectedMemory = ref<UserMemory | null>(null)
const selectedCategories = ref<string[]>([])
const selectedKeys = ref<string[]>([])
const physicsEnabled = ref(true)
const groupBy = ref<'category' | 'key'>('category')

// Available keys for key-based filtering
const availableKeys = computed(() => {
  const keysMap = new Map<string, number>()
  props.memories.forEach((m) => {
    keysMap.set(m.key, (keysMap.get(m.key) || 0) + 1)
  })
  return Array.from(keysMap.entries())
    .map(([key, count]) => ({ key, count }))
    .sort((a, b) => a.key.localeCompare(b.key))
})

// Camera
const camera = ref({
  x: 0,
  y: 0,
  zoom: 1,
})

// Interaction
let isDragging = false
let draggedNode: Node | null = null
let lastMouseX = 0
let lastMouseY = 0

// Category Colors
const categoryColors: Record<string, string> = {
  preferences: '#3b82f6', // blue
  personal: '#10b981', // green
  work: '#f59e0b', // amber
  projects: '#8b5cf6', // purple
  default: '#6366f1', // indigo
}

// Initialize Graph
function initializeGraph() {
  console.log('🚀 initializeGraph called')
  if (!canvasRef.value) {
    console.warn('⚠️ No canvasRef.value')
    return
  }

  const canvas = canvasRef.value
  ctx = canvas.getContext('2d')
  if (!ctx) {
    console.warn('⚠️ No canvas context')
    return
  }

  console.log('📐 Initial canvas offsetWidth/Height:', {
    offsetWidth: canvas.offsetWidth,
    offsetHeight: canvas.offsetHeight,
  })

  // Set canvas size
  canvas.width = canvas.offsetWidth * window.devicePixelRatio
  canvas.height = canvas.offsetHeight * window.devicePixelRatio
  ctx.scale(window.devicePixelRatio, window.devicePixelRatio)

  console.log('📐 Canvas size after setup:', {
    width: canvas.width,
    height: canvas.height,
    devicePixelRatio: window.devicePixelRatio,
  })

  // Create nodes from memories
  createNodes()

  // Create edges (connections based on category similarity)
  createEdges()

  console.log('🔗 Created edges:', edges.value.length)

  // Start animation loop
  console.log('🎬 Starting animation')
  animate()
}

function createNodes() {
  let filteredMemories = props.memories

  // Apply category filter (only in category mode)
  if (groupBy.value === 'category' && selectedCategories.value.length > 0) {
    filteredMemories = filteredMemories.filter((m) => selectedCategories.value.includes(m.category))
  }

  // Apply key filter (only in key mode)
  if (groupBy.value === 'key' && selectedKeys.value.length > 0) {
    filteredMemories = filteredMemories.filter((m) => selectedKeys.value.includes(m.key))
  }

  console.log('🧠 MemoryGraphView - createNodes:', {
    totalMemories: props.memories.length,
    filteredCount: filteredMemories.length,
    groupBy: groupBy.value,
    selectedCategories: selectedCategories.value,
    selectedKeys: selectedKeys.value,
  })

  // Early return if no memories
  if (filteredMemories.length === 0) {
    nodes.value = []
    return
  }

  const centerX = canvasRef.value!.offsetWidth / 2
  const centerY = canvasRef.value!.offsetHeight / 2
  const baseRadius = Math.min(centerX, centerY) * 0.6

  // Responsive node size based on screen width
  const isMobile = canvasRef.value!.offsetWidth < 768
  const nodeBaseRadius = isMobile ? 12 : 8
  const radiusVariance = isMobile ? 6 : 4

  console.log('🎯 Canvas dimensions:', {
    width: canvasRef.value!.offsetWidth,
    height: canvasRef.value!.offsetHeight,
    centerX,
    centerY,
    baseRadius,
    isMobile,
  })

  // Sort/group memories based on groupBy setting
  let sortedMemories = [...filteredMemories]

  if (groupBy.value === 'category') {
    // Group by category, then sort within each category by key
    sortedMemories.sort((a, b) => {
      if (a.category !== b.category) {
        return a.category.localeCompare(b.category)
      }
      return a.key.localeCompare(b.key)
    })
  } else {
    // Sort by key
    sortedMemories.sort((a, b) => a.key.localeCompare(b.key))
  }

  // Position nodes based on groupBy mode
  if (groupBy.value === 'category') {
    // Group by category in clusters
    const categories = Array.from(new Set(sortedMemories.map((m) => m.category)))
    const categoryAngles = new Map<string, number>()

    categories.forEach((cat, i) => {
      const angle = (i / categories.length) * Math.PI * 2
      categoryAngles.set(cat, angle)
    })

    const categoryMemoryCounts = new Map<string, { count: number; index: number }>()
    sortedMemories.forEach((m) => {
      const current = categoryMemoryCounts.get(m.category) || { count: 0, index: 0 }
      current.count++
      categoryMemoryCounts.set(m.category, current)
    })

    nodes.value = sortedMemories.map((memory) => {
      const categoryAngle = categoryAngles.get(memory.category)!
      const categoryInfo = categoryMemoryCounts.get(memory.category)!
      const memoryIndex = categoryInfo.index++
      const memoriesInCategory = categoryInfo.count

      // Position in a sub-cluster around category angle
      const clusterRadius = baseRadius * (0.6 + Math.random() * 0.3)
      const angleOffset =
        memoriesInCategory > 1
          ? (memoryIndex / memoriesInCategory - 0.5) * (Math.PI / 3) // Spread within 60 degrees
          : 0

      const finalAngle = categoryAngle + angleOffset

      return {
        id: memory.id,
        x: centerX + Math.cos(finalAngle) * clusterRadius,
        y: centerY + Math.sin(finalAngle) * clusterRadius,
        vx: (Math.random() - 0.5) * 0.5,
        vy: (Math.random() - 0.5) * 0.5,
        radius: nodeBaseRadius + Math.random() * radiusVariance,
        memory,
        color: categoryColors[memory.category] || categoryColors.default,
      }
    })
  } else {
    // Key mode: Group by key in clusters
    const keys = Array.from(new Set(sortedMemories.map((m) => m.key)))
    const keyAngles = new Map<string, number>()

    keys.forEach((key, i) => {
      const angle = (i / keys.length) * Math.PI * 2
      keyAngles.set(key, angle)
    })

    const keyMemoryCounts = new Map<string, { count: number; index: number }>()
    sortedMemories.forEach((m) => {
      const current = keyMemoryCounts.get(m.key) || { count: 0, index: 0 }
      current.count++
      keyMemoryCounts.set(m.key, current)
    })

    nodes.value = sortedMemories.map((memory) => {
      const keyAngle = keyAngles.get(memory.key)!
      const keyInfo = keyMemoryCounts.get(memory.key)!
      const memoryIndex = keyInfo.index++
      const memoriesWithKey = keyInfo.count

      // Position in a sub-cluster around key angle
      const clusterRadius = baseRadius * (0.6 + Math.random() * 0.3)
      const angleOffset =
        memoriesWithKey > 1
          ? (memoryIndex / memoriesWithKey - 0.5) * (Math.PI / 4) // Spread within 45 degrees
          : 0

      const finalAngle = keyAngle + angleOffset

      return {
        id: memory.id,
        x: centerX + Math.cos(finalAngle) * clusterRadius,
        y: centerY + Math.sin(finalAngle) * clusterRadius,
        vx: (Math.random() - 0.5) * 0.5,
        vy: (Math.random() - 0.5) * 0.5,
        radius: nodeBaseRadius + Math.random() * radiusVariance,
        memory,
        color: categoryColors[memory.category] || categoryColors.default,
      }
    })
  }

  console.log('✅ Created nodes:', nodes.value.length)
}

function createEdges() {
  edges.value = []

  if (groupBy.value === 'category') {
    // Connect nodes within the same category
    for (let i = 0; i < nodes.value.length; i++) {
      for (let j = i + 1; j < nodes.value.length; j++) {
        const nodeA = nodes.value[i]
        const nodeB = nodes.value[j]

        // Only connect if same category
        if (nodeA.memory.category === nodeB.memory.category) {
          edges.value.push({ source: nodeA, target: nodeB })
        }
      }
    }
  } else {
    // Key mode: Connect nodes with the same key
    for (let i = 0; i < nodes.value.length; i++) {
      for (let j = i + 1; j < nodes.value.length; j++) {
        const nodeA = nodes.value[i]
        const nodeB = nodes.value[j]

        // Only connect if same key
        if (nodeA.memory.key === nodeB.memory.key) {
          edges.value.push({ source: nodeA, target: nodeB })
        }
      }
    }
  }

  console.log('🔗 Created edges:', edges.value.length, 'in', groupBy.value, 'mode')
}

function animate() {
  if (!ctx || !canvasRef.value) return

  const canvas = canvasRef.value
  const width = canvas.offsetWidth
  const height = canvas.offsetHeight

  // Clear canvas with dark background
  ctx.fillStyle = '#0f172a' // Dark slate background
  ctx.fillRect(0, 0, width, height)

  // Apply physics
  if (physicsEnabled.value) {
    applyForces()
  }

  // Draw edges
  ctx.strokeStyle = 'rgba(168, 85, 247, 0.3)' // purple with opacity
  ctx.lineWidth = 2
  for (const edge of edges.value) {
    ctx.beginPath()
    ctx.moveTo(edge.source.x, edge.source.y)
    ctx.lineTo(edge.target.x, edge.target.y)
    ctx.stroke()
  }

  // Draw nodes
  let drawnNodes = 0
  for (const node of nodes.value) {
    // Validate node position and radius
    if (!isFinite(node.x) || !isFinite(node.y) || !isFinite(node.radius) || node.radius <= 0) {
      console.warn('⚠️ Invalid node:', node)
      continue // Skip invalid nodes
    }

    // Glow effect
    const gradient = ctx.createRadialGradient(node.x, node.y, 0, node.x, node.y, node.radius * 2)
    gradient.addColorStop(0, `${node.color}cc`) // More opaque
    gradient.addColorStop(1, `${node.color}00`)
    ctx.fillStyle = gradient
    ctx.beginPath()
    ctx.arc(node.x, node.y, node.radius * 2, 0, Math.PI * 2)
    ctx.fill()

    // Node circle
    ctx.fillStyle = node.color
    ctx.beginPath()
    ctx.arc(node.x, node.y, node.radius, 0, Math.PI * 2)
    ctx.fill()

    // White border for visibility
    ctx.strokeStyle = 'rgba(255, 255, 255, 0.3)'
    ctx.lineWidth = 1
    ctx.stroke()

    drawnNodes++

    // Highlight selected
    if (selectedMemory.value?.id === node.id) {
      ctx.strokeStyle = '#ffffff'
      ctx.lineWidth = 3
      ctx.beginPath()
      ctx.arc(node.x, node.y, node.radius + 4, 0, Math.PI * 2)
      ctx.stroke()
    }

    // Draw label on hover
    const dx = lastMouseX - node.x
    const dy = lastMouseY - node.y
    const dist = Math.sqrt(dx * dx + dy * dy)
    if (dist < node.radius + 10) {
      ctx.fillStyle = '#ffffff'
      ctx.font = 'bold 14px sans-serif'
      ctx.textAlign = 'center'
      ctx.fillText(node.memory.key, node.x, node.y - node.radius - 12)
    }
  }

  // Debug: Log first frame
  if (!animationId) {
    console.log(`🎨 First frame drawn - ${drawnNodes} nodes rendered`)
  }

  animationId = requestAnimationFrame(animate)
}

function applyForces() {
  const centerX = canvasRef.value!.offsetWidth / 2
  const centerY = canvasRef.value!.offsetHeight / 2

  for (const node of nodes.value) {
    // Apply spring force to edges
    for (const edge of edges.value) {
      if (edge.source === node || edge.target === node) {
        const other = edge.source === node ? edge.target : edge.source
        const dx = other.x - node.x
        const dy = other.y - node.y
        const distance = Math.sqrt(dx * dx + dy * dy)
        const targetDistance = 80
        const force = (distance - targetDistance) * 0.01

        node.vx += (dx / distance) * force
        node.vy += (dy / distance) * force
      }
    }

    // Repel from other nodes
    for (const other of nodes.value) {
      if (other === node) continue
      const dx = node.x - other.x
      const dy = node.y - other.y
      const distance = Math.sqrt(dx * dx + dy * dy)
      if (distance < 100) {
        const force = 50 / (distance * distance)
        node.vx += (dx / distance) * force
        node.vy += (dy / distance) * force
      }
    }

    // Center attraction
    const dx = centerX - node.x
    const dy = centerY - node.y
    node.vx += dx * 0.0001
    node.vy += dy * 0.0001

    // Damping
    node.vx *= 0.95
    node.vy *= 0.95

    // Update position
    if (!draggedNode || draggedNode !== node) {
      node.x += node.vx
      node.y += node.vy

      // Boundaries
      const margin = 50
      if (node.x < margin) node.vx = Math.abs(node.vx)
      if (node.x > canvasRef.value!.offsetWidth - margin) node.vx = -Math.abs(node.vx)
      if (node.y < margin) node.vy = Math.abs(node.vy)
      if (node.y > canvasRef.value!.offsetHeight - margin) node.vy = -Math.abs(node.vy)
    }
  }
}

// Interaction Handlers
function handleMouseDown(e: MouseEvent) {
  const rect = canvasRef.value!.getBoundingClientRect()
  const x = e.clientX - rect.left
  const y = e.clientY - rect.top

  // Check if clicked on a node
  for (const node of nodes.value) {
    const dx = x - node.x
    const dy = y - node.y
    const dist = Math.sqrt(dx * dx + dy * dy)
    if (dist < node.radius + 5) {
      draggedNode = node
      selectedMemory.value = node.memory
      isDragging = true
      return
    }
  }

  // Otherwise, start camera drag
  isDragging = true
  lastMouseX = x
  lastMouseY = y
}

function handleMouseMove(e: MouseEvent) {
  const rect = canvasRef.value!.getBoundingClientRect()
  lastMouseX = e.clientX - rect.left
  lastMouseY = e.clientY - rect.top

  if (isDragging && draggedNode) {
    draggedNode.x = lastMouseX
    draggedNode.y = lastMouseY
    draggedNode.vx = 0
    draggedNode.vy = 0
  }
}

function handleMouseUp() {
  isDragging = false
  draggedNode = null
}

function handleWheel(e: WheelEvent) {
  e.preventDefault()
  camera.value.zoom *= e.deltaY > 0 ? 0.9 : 1.1
  camera.value.zoom = Math.max(0.5, Math.min(2, camera.value.zoom))
}

function resetView() {
  camera.value = { x: 0, y: 0, zoom: 1 }
  createNodes()
  createEdges()
}

function togglePhysics() {
  physicsEnabled.value = !physicsEnabled.value
}

function toggleGroupBy() {
  groupBy.value = groupBy.value === 'category' ? 'key' : 'category'
  // Clear filters when switching modes
  selectedCategories.value = []
  selectedKeys.value = []
  createNodes()
  createEdges()
}

function toggleCategory(category: string) {
  const index = selectedCategories.value.indexOf(category)
  if (index > -1) {
    selectedCategories.value.splice(index, 1)
  } else {
    selectedCategories.value.push(category)
  }
  createNodes()
  createEdges()
}

function toggleKey(key: string) {
  const index = selectedKeys.value.indexOf(key)
  if (index > -1) {
    selectedKeys.value.splice(index, 1)
  } else {
    selectedKeys.value.push(key)
  }
  createNodes()
  createEdges()
}

function clearFilters() {
  selectedCategories.value = []
  selectedKeys.value = []
  createNodes()
  createEdges()
}

function handleEdit(memory: UserMemory) {
  emit('edit', memory)
}

function handleDelete(memory: UserMemory) {
  emit('delete', memory)
}

function formatTimestamp(timestamp: number) {
  const date = new Date(timestamp * 1000)
  return date.toLocaleString()
}

// Touch Handlers for Mobile
function handleTouchStart(e: TouchEvent) {
  if (e.touches.length === 1) {
    const touch = e.touches[0]
    const rect = canvasRef.value!.getBoundingClientRect()
    const x = touch.clientX - rect.left
    const y = touch.clientY - rect.top

    // Check if touched on a node
    for (const node of nodes.value) {
      const dx = x - node.x
      const dy = y - node.y
      const dist = Math.sqrt(dx * dx + dy * dy)
      if (dist < node.radius + 10) {
        draggedNode = node
        selectedMemory.value = node.memory
        isDragging = true
        e.preventDefault()
        return
      }
    }

    // Otherwise, start camera drag
    isDragging = true
    lastMouseX = x
    lastMouseY = y
    e.preventDefault()
  }
}

function handleTouchMove(e: TouchEvent) {
  if (e.touches.length === 1) {
    const touch = e.touches[0]
    const rect = canvasRef.value!.getBoundingClientRect()
    lastMouseX = touch.clientX - rect.left
    lastMouseY = touch.clientY - rect.top

    if (isDragging && draggedNode) {
      draggedNode.x = lastMouseX
      draggedNode.y = lastMouseY
      draggedNode.vx = 0
      draggedNode.vy = 0
      e.preventDefault()
    }
  }
}

function handleTouchEnd() {
  isDragging = false
  draggedNode = null
}

// Lifecycle
onMounted(() => {
  initializeGraph()
  window.addEventListener('resize', () => {
    if (canvasRef.value) {
      canvasRef.value.width = canvasRef.value.offsetWidth * window.devicePixelRatio
      canvasRef.value.height = canvasRef.value.offsetHeight * window.devicePixelRatio
      ctx?.scale(window.devicePixelRatio, window.devicePixelRatio)
    }
  })
})

onBeforeUnmount(() => {
  if (animationId) {
    cancelAnimationFrame(animationId)
  }
})

watch(
  () => props.memories,
  () => {
    createNodes()
    createEdges()
  },
  { deep: true }
)
</script>
