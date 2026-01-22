<template>
  <div
    ref="rootRef"
    class="relative w-full overflow-hidden bg-chat"
    :class="isPseudoFullscreen ? 'fixed inset-0 z-[9998] w-screen h-screen' : ''"
    style="min-height: 600px; height: 100%"
  >
    <!-- Empty State -->
    <div
      v-if="props.memories.length === 0"
      class="absolute inset-0 flex items-center justify-center z-20"
    >
      <div class="text-center txt-secondary max-w-md p-8">
        <Icon icon="mdi:brain" class="w-20 h-20 mx-auto mb-4 opacity-30" />
        <h3 class="text-xl font-semibold mb-2 txt-primary">{{ $t('memories.empty') }}</h3>
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
        class="px-3 md:px-4 py-2 md:py-2.5 rounded-lg text-xs md:text-sm font-medium transition-all duration-200 flex items-center gap-2 nav-item"
        :class="groupBy === 'category' ? 'nav-item--active' : ''"
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
          class="px-3 py-1.5 rounded-full text-xs font-medium transition-all duration-200 whitespace-nowrap nav-item"
          :class="selectedCategories.includes(cat.category) ? 'nav-item--active' : ''"
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
          class="px-3 py-1.5 rounded-lg text-xs font-medium transition-all duration-200 whitespace-nowrap nav-item"
          :class="selectedKeys.includes(keyItem.key) ? 'nav-item--active' : ''"
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
      <div class="text-center txt-secondary max-w-md">
        <Icon
          icon="mdi:filter-off"
          class="w-12 h-12 md:w-16 md:h-16 mx-auto mb-3 md:mb-4 opacity-30"
        />
        <h3 class="text-base md:text-lg font-semibold mb-2 txt-primary">
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
          class="px-4 py-2 rounded-lg btn-primary transition-colors text-sm"
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
      <button
        class="p-2 md:p-2.5 rounded-lg bg-white/10 text-white hover:bg-white/20 transition-colors backdrop-blur-sm"
        :title="isFullscreen ? $t('memories.fullscreen.exit') : $t('memories.fullscreen.enter')"
        @click="toggleFullscreen"
      >
        <Icon
          :icon="isFullscreen ? 'mdi:fullscreen-exit' : 'mdi:fullscreen'"
          class="w-4 h-4 md:w-5 md:h-5"
        />
      </button>
    </div>

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

    <!-- Fullscreen bottom sheet (must be INSIDE fullscreen element to be visible) -->
    <div
      v-if="(isFullscreen || isPseudoFullscreen) && selectedMemory"
      class="absolute left-0 right-0 bottom-0 z-[9999] p-4"
    >
      <div class="surface-elevated rounded-2xl p-4 max-h-[40vh] overflow-y-auto scroll-thin">
        <MemorySelectionCard
          :memory="selectedMemory"
          :category-color="categoryColors[selectedMemory.category] || categoryColors.default"
          @close="handleCloseSelectedMemory"
          @edit="emit('edit', $event)"
          @delete="emit('delete', $event)"
        />
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, onBeforeUnmount, watch } from 'vue'
import { Icon } from '@iconify/vue'
import type { UserMemory } from '@/services/api/userMemoriesApi'
import MemorySelectionCard from '@/components/memories/MemorySelectionCard.vue'

interface Props {
  memories: UserMemory[]
  availableCategories: Array<{ category: string; count: number }>
  selectedMemoryId?: number | null
}

interface Emits {
  (e: 'select', memory: UserMemory | null): void
  (e: 'edit', memory: UserMemory): void
  (e: 'delete', memory: UserMemory): void
}

const props = defineProps<Props>()
const emit = defineEmits<Emits>()

// Fullscreen
const rootRef = ref<HTMLElement | null>(null)
const isFullscreen = ref(false)
const isPseudoFullscreen = ref(false)

// Canvas & Animation
const canvasRef = ref<HTMLCanvasElement | null>(null)
let ctx: CanvasRenderingContext2D | null = null
let animationId: number | null = null

// Graph State
type NodeType = 'hub' | 'memory'

interface BaseNode {
  id: number
  type: NodeType
  x: number
  y: number
  vx: number
  vy: number
  radius: number
  color: string
  groupKey: string
}

interface HubNode extends BaseNode {
  type: 'hub'
  label: string
  count: number
}

interface MemoryNode extends BaseNode {
  type: 'memory'
  memory: UserMemory
}

type Node = HubNode | MemoryNode

interface Edge {
  source: Node
  target: Node
  restLength: number
}

const nodes = ref<Node[]>([])
const edges = ref<Edge[]>([])
const selectedMemory = ref<UserMemory | null>(null)

function handleCloseSelectedMemory() {
  selectedMemory.value = null
  emit('select', null)
}
const selectedCategories = ref<string[]>([])
const selectedKeys = ref<string[]>([])
const physicsEnabled = ref(true)
const groupBy = ref<'category' | 'key'>('category')

let onFullscreenChangeHandler: (() => void) | null = null
let onKeyDownHandler: ((e: KeyboardEvent) => void) | null = null

function resizeCanvas() {
  if (!canvasRef.value) return
  canvasRef.value.width = canvasRef.value.offsetWidth * window.devicePixelRatio
  canvasRef.value.height = canvasRef.value.offsetHeight * window.devicePixelRatio
  // Reset transform before scaling to avoid compounding scales across resizes
  ctx?.setTransform(1, 0, 0, 1, 0, 0)
  ctx?.scale(window.devicePixelRatio, window.devicePixelRatio)
}

async function toggleFullscreen() {
  const el = rootRef.value
  if (!el) return

  try {
    if (document.fullscreenElement) {
      await document.exitFullscreen()
      return
    }
    if (el.requestFullscreen) {
      await el.requestFullscreen()
      return
    }
  } catch {
    // Fallback to pseudo fullscreen below
  }

  isPseudoFullscreen.value = !isPseudoFullscreen.value
  // Let layout settle, then resize
  requestAnimationFrame(() => resizeCanvas())
}

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
  // Hub is the "main bubble" for category/key. Make it a bit larger for readability.
  const hubRadius = isMobile ? 34 : 28

  console.log('🎯 Canvas dimensions:', {
    width: canvasRef.value!.offsetWidth,
    height: canvasRef.value!.offsetHeight,
    centerX,
    centerY,
    baseRadius,
    isMobile,
  })

  // Build groups (category or key) -> memories
  const groups = new Map<string, UserMemory[]>()
  for (const memory of filteredMemories) {
    const groupKey = groupBy.value === 'category' ? memory.category : memory.key
    const list = groups.get(groupKey) || []
    list.push(memory)
    groups.set(groupKey, list)
  }

  const groupKeys = Array.from(groups.keys()).sort((a, b) => a.localeCompare(b))
  const groupAngles = new Map<string, number>()
  groupKeys.forEach((k, i) => {
    const angle = (i / Math.max(1, groupKeys.length)) * Math.PI * 2
    groupAngles.set(k, angle)
  })

  const newNodes: Node[] = []

  // Place a hub per group, then place memories around that hub
  for (let i = 0; i < groupKeys.length; i++) {
    const groupKey = groupKeys[i]
    const groupMemories = groups.get(groupKey) || []
    const angle = groupAngles.get(groupKey) || 0

    // Spread group hubs around center; if only one group, keep it centered
    const hubOrbitRadius = groupKeys.length <= 1 ? 0 : baseRadius * 0.55
    const hubX = centerX + Math.cos(angle) * hubOrbitRadius
    const hubY = centerY + Math.sin(angle) * hubOrbitRadius

    // Hub color: category color in category-mode, otherwise use the first memory's category color
    const hubColor =
      groupBy.value === 'category'
        ? categoryColors[groupKey] || categoryColors.default
        : categoryColors[groupMemories[0]?.category] || categoryColors.default

    const hubNode: HubNode = {
      id: -1 - i,
      type: 'hub',
      x: hubX,
      y: hubY,
      vx: 0,
      vy: 0,
      radius: hubRadius,
      color: hubColor,
      groupKey,
      label: groupKey,
      count: groupMemories.length,
    }
    newNodes.push(hubNode)

    // Sort memories in group for stable placement
    const sorted = [...groupMemories].sort((a, b) => a.key.localeCompare(b.key))

    // Place memory nodes around hub
    // Keep memory nodes further away now that hub is larger.
    const ringRadius = isMobile ? 95 : 80
    sorted.forEach((memory, idx) => {
      const memAngle =
        sorted.length <= 1 ? angle : angle + (idx / sorted.length - 0.5) * (Math.PI / 1.8)
      const jitter = (Math.random() - 0.5) * 14

      const node: MemoryNode = {
        id: memory.id,
        type: 'memory',
        x: hubX + Math.cos(memAngle) * (ringRadius + jitter),
        y: hubY + Math.sin(memAngle) * (ringRadius + jitter),
        vx: (Math.random() - 0.5) * 0.5,
        vy: (Math.random() - 0.5) * 0.5,
        radius: nodeBaseRadius + Math.random() * radiusVariance,
        memory,
        color: categoryColors[memory.category] || categoryColors.default,
        groupKey,
      }
      newNodes.push(node)
    })
  }

  nodes.value = newNodes

  console.log('✅ Created nodes:', nodes.value.length)
}

function createEdges() {
  edges.value = []

  const hubsByGroupKey = new Map<string, HubNode>()
  for (const node of nodes.value) {
    if (node.type === 'hub') {
      hubsByGroupKey.set(node.groupKey, node)
    }
  }

  // Connect each memory node to its hub node
  for (const node of nodes.value) {
    if (node.type !== 'memory') continue
    const hub = hubsByGroupKey.get(node.groupKey)
    if (!hub) continue

    edges.value.push({
      source: hub,
      target: node,
      restLength: 75,
    })
  }

  console.log('🔗 Created edges:', edges.value.length, 'in', groupBy.value, 'mode')
}

function animate() {
  if (!ctx || !canvasRef.value) return

  const context = ctx
  const canvas = canvasRef.value
  const width = canvas.offsetWidth
  const height = canvas.offsetHeight
  const isMobile = width < 768

  // Detect dark mode
  const isDarkMode = document.documentElement.classList.contains('dark')

  // Clear canvas with theme-aware background
  context.fillStyle = isDarkMode ? '#0f172a' : '#f8fafc' // dark slate vs light slate
  context.fillRect(0, 0, width, height)

  // Apply physics
  if (physicsEnabled.value) {
    applyForces()
  }

  // Draw edges with theme-aware colors
  context.strokeStyle = isDarkMode ? 'rgba(168, 85, 247, 0.3)' : 'rgba(168, 85, 247, 0.2)' // purple
  context.lineWidth = 2
  for (const edge of edges.value) {
    context.beginPath()
    context.moveTo(edge.source.x, edge.source.y)
    context.lineTo(edge.target.x, edge.target.y)
    context.stroke()
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
    const gradient = context.createRadialGradient(
      node.x,
      node.y,
      0,
      node.x,
      node.y,
      node.radius * 2
    )
    gradient.addColorStop(0, `${node.color}cc`) // More opaque
    gradient.addColorStop(1, `${node.color}00`)
    context.fillStyle = gradient
    context.beginPath()
    context.arc(node.x, node.y, node.radius * 2, 0, Math.PI * 2)
    context.fill()

    // Node circle
    context.fillStyle = node.color
    context.beginPath()
    context.arc(node.x, node.y, node.radius, 0, Math.PI * 2)
    context.fill()

    // Border with theme-aware color
    context.strokeStyle = isDarkMode ? 'rgba(255, 255, 255, 0.3)' : 'rgba(0, 0, 0, 0.2)'
    context.lineWidth = node.type === 'hub' ? 2 : 1
    context.stroke()

    drawnNodes++

    // Highlight selected
    if (node.type === 'memory' && selectedMemory.value?.id === node.id) {
      context.strokeStyle = isDarkMode ? '#ffffff' : '#000000'
      context.lineWidth = 3
      context.beginPath()
      context.arc(node.x, node.y, node.radius + 4, 0, Math.PI * 2)
      context.stroke()
    }

    // Hub label (always)
    if (node.type === 'hub') {
      const fontStack = 'ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, sans-serif'

      function truncateToWidth(text: string, maxWidth: number) {
        if (context.measureText(text).width <= maxWidth) return text
        const ellipsis = '…'
        let lo = 0
        let hi = text.length
        while (lo < hi) {
          const mid = Math.ceil((lo + hi) / 2)
          const candidate = text.slice(0, mid) + ellipsis
          if (context.measureText(candidate).width <= maxWidth) lo = mid
          else hi = mid - 1
        }
        return text.slice(0, lo) + ellipsis
      }

      context.textAlign = 'center'
      context.textBaseline = 'alphabetic'

      // Smaller, cleaner label within hub bubble
      context.fillStyle = isDarkMode ? '#ffffff' : '#0f172a'
      context.font = `600 ${isMobile ? 11 : 10}px ${fontStack}`
      const labelMaxWidth = node.radius * 1.45
      context.fillText(truncateToWidth(node.label, labelMaxWidth), node.x, node.y + 3)

      // Count below label, subtle
      context.font = `600 ${isMobile ? 10 : 9}px ${fontStack}`
      context.fillStyle = isDarkMode ? 'rgba(255,255,255,0.7)' : 'rgba(15,23,42,0.6)'
      context.fillText(`${node.count}`, node.x, node.y + Math.max(16, node.radius * 0.62))
    }

    // Memory label on hover with theme-aware color
    if (node.type === 'memory') {
      const dx = lastMouseX - node.x
      const dy = lastMouseY - node.y
      const dist = Math.sqrt(dx * dx + dy * dy)
      if (dist < node.radius + 10) {
        context.fillStyle = isDarkMode ? '#ffffff' : '#0f172a'
        context.font = 'bold 14px sans-serif'
        context.textAlign = 'center'
        context.fillText(node.memory.key, node.x, node.y - node.radius - 12)
      }
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
        const targetDistance = edge.restLength
        const safeDistance = Math.max(0.001, distance)
        const force = (safeDistance - targetDistance) * 0.01

        node.vx += (dx / safeDistance) * force
        node.vy += (dy / safeDistance) * force
      }
    }

    // Repel from other nodes
    for (const other of nodes.value) {
      if (other === node) continue
      const dx = node.x - other.x
      const dy = node.y - other.y
      const distance = Math.sqrt(dx * dx + dy * dy)
      const minDistance = node.radius + other.radius + 18
      if (distance < minDistance) {
        const safeDistance = Math.max(0.001, distance)
        const force = 80 / (safeDistance * safeDistance)
        node.vx += (dx / safeDistance) * force
        node.vy += (dy / safeDistance) * force
      }
    }

    // Center attraction
    const dx = centerX - node.x
    const dy = centerY - node.y
    const centerStrength = node.type === 'hub' ? 0.00025 : 0.00008
    node.vx += dx * centerStrength
    node.vy += dy * centerStrength

    // Damping
    node.vx *= 0.95
    node.vy *= 0.95

    // Update position
    if (!draggedNode || draggedNode !== node) {
      node.x += node.vx
      node.y += node.vy

      // Boundaries
      const margin = node.type === 'hub' ? 80 : 50
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
      if (node.type === 'memory') {
        selectedMemory.value = node.memory
        emit('select', node.memory)
      } else {
        selectedMemory.value = null
        emit('select', null)
      }
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
        if (node.type === 'memory') {
          selectedMemory.value = node.memory
          emit('select', node.memory)
        } else {
          selectedMemory.value = null
          emit('select', null)
        }
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
  resizeCanvas()

  onFullscreenChangeHandler = () => {
    isFullscreen.value = Boolean(document.fullscreenElement)
    if (!isFullscreen.value) {
      isPseudoFullscreen.value = false
    }
    requestAnimationFrame(() => resizeCanvas())
  }

  onKeyDownHandler = (e: KeyboardEvent) => {
    if (e.key === 'Escape' && isPseudoFullscreen.value) {
      isPseudoFullscreen.value = false
      requestAnimationFrame(() => resizeCanvas())
    }
  }

  window.addEventListener('resize', resizeCanvas)
  document.addEventListener('fullscreenchange', onFullscreenChangeHandler)
  window.addEventListener('keydown', onKeyDownHandler)
})

onBeforeUnmount(() => {
  if (animationId) {
    cancelAnimationFrame(animationId)
  }

  window.removeEventListener('resize', resizeCanvas)
  if (onFullscreenChangeHandler) {
    document.removeEventListener('fullscreenchange', onFullscreenChangeHandler)
  }
  if (onKeyDownHandler) {
    window.removeEventListener('keydown', onKeyDownHandler)
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

// Sync selection from parent (renders the detail card outside the graph)
watch(
  () => props.selectedMemoryId,
  (id) => {
    if (!id) {
      selectedMemory.value = null
      return
    }
    selectedMemory.value = props.memories.find((m) => m.id === id) || null
  }
)
</script>
