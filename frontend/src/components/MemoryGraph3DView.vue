<script setup lang="ts">
import { ref, onMounted, onUnmounted, computed, watch } from 'vue'
import { Icon } from '@iconify/vue'
import * as THREE from 'three'
import { OrbitControls } from 'three/examples/jsm/controls/OrbitControls.js'
import type { UserMemory as Memory } from '@/services/api/userMemoriesApi'
import MemorySelectionCard from '@/components/memories/MemorySelectionCard.vue'

interface Props {
  memories: Memory[]
  selectedMemory: Memory | null
}

const props = defineProps<Props>()
const emit = defineEmits<{
  (e: 'select-memory', memory: Memory | null): void
  (e: 'edit-memory', memory: Memory): void
  (e: 'delete-memory', memory: Memory): void
}>()

// Fullscreen
const rootRef = ref<HTMLDivElement>()
const isFullscreen = ref(false)
const isPseudoFullscreen = ref(false)
let resizeObserver: ResizeObserver | null = null
let onFullscreenChangeHandler: (() => void) | null = null

// Canvas & Three.js refs
const canvasContainer = ref<HTMLDivElement>()
let scene: THREE.Scene
let camera: THREE.PerspectiveCamera
let renderer: THREE.WebGLRenderer
let controls: OrbitControls
let animationFrameId: number

// Performance detection
const isMobileDevice = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(
  navigator.userAgent
)
const isLowPerformance = isMobileDevice || navigator.hardwareConcurrency <= 2

// Navigation state
// (no drilldown) – render full hierarchy at once

// 3D Objects
interface Node3D {
  mesh: THREE.Mesh
  type: 'category' | 'key' | 'memory'
  data: { label: string; count?: number; category?: string; key?: string; memory?: Memory }
  position: THREE.Vector3
  targetPosition: THREE.Vector3
  velocity: THREE.Vector3
}

const nodes: Node3D[] = []
const lines: Array<{ line: THREE.Line; geometry: THREE.BufferGeometry; material: THREE.Material }> =
  []
const raycaster = new THREE.Raycaster()
const mouse = new THREE.Vector2()
let hoveredNode: Node3D | null = null

const graphCategoryColors: Record<string, string> = {
  preferences: '#3b82f6',
  personal: '#10b981',
  work: '#f59e0b',
  projects: '#8b5cf6',
  default: '#6366f1',
}

function hexToInt(hex: string): number {
  return Number.parseInt(hex.replace('#', ''), 16)
}

function intToHex(color: number): string {
  return `#${color.toString(16).padStart(6, '0')}`
}

function mixColors(a: number, b: number, t: number): number {
  const ar = (a >> 16) & 0xff
  const ag = (a >> 8) & 0xff
  const ab = a & 0xff
  const br = (b >> 16) & 0xff
  const bg = (b >> 8) & 0xff
  const bb = b & 0xff
  const r = Math.round(ar + (br - ar) * t)
  const g = Math.round(ag + (bg - ag) * t)
  const b2 = Math.round(ab + (bb - ab) * t)
  return (r << 16) | (g << 8) | b2
}

const legendColors = computed(() => ({
  category: intToHex(COLORS.category),
  key: intToHex(COLORS.key),
  memory: intToHex(COLORS.memory),
}))

// Colors
const COLORS = {
  category: 0x3b82f6, // blue
  key: 0x8b5cf6, // purple
  memory: 0x10b981, // green
  hover: 0xfbbf24, // amber
  selected: 0xef4444, // red
}

const hierarchy = computed(() => {
  const categories = new Map<string, Map<string, Memory[]>>()
  for (const memory of props.memories) {
    if (!categories.has(memory.category)) {
      categories.set(memory.category, new Map())
    }
    const keys = categories.get(memory.category)!
    if (!keys.has(memory.key)) {
      keys.set(memory.key, [])
    }
    keys.get(memory.key)!.push(memory)
  }
  return categories
})

// Initialize Three.js scene
function initThreeJS() {
  if (!canvasContainer.value) return

  const width = canvasContainer.value.clientWidth
  const height = canvasContainer.value.clientHeight

  // Scene
  scene = new THREE.Scene()
  // keep background transparent (renderer alpha=true) so it blends with app theme
  scene.background = null
  scene.fog = new THREE.Fog(0x0a0a0a, 80, 260)

  // Camera
  camera = new THREE.PerspectiveCamera(60, width / height, 0.1, 1000)
  camera.position.set(0, 55, 110)
  camera.lookAt(0, 0, 0)

  // Renderer
  renderer = new THREE.WebGLRenderer({
    antialias: !isLowPerformance, // Disable antialiasing on mobile for performance
    alpha: true,
    powerPreference: 'high-performance',
  })
  renderer.setSize(width, height)
  // Limit pixel ratio on low-performance devices
  const pixelRatio = isLowPerformance ? 1 : Math.min(window.devicePixelRatio, 2)
  renderer.setPixelRatio(pixelRatio)
  canvasContainer.value.appendChild(renderer.domElement)

  // Controls
  controls = new OrbitControls(camera, renderer.domElement)
  controls.enableDamping = true
  controls.dampingFactor = 0.05
  controls.minDistance = 20
  controls.maxDistance = 220
  controls.maxPolarAngle = Math.PI / 2

  // Lights
  const ambientLight = new THREE.AmbientLight(0xffffff, 0.55)
  scene.add(ambientLight)

  const pointLight = new THREE.PointLight(0xffffff, 1, 200)
  pointLight.position.set(50, 50, 50)
  scene.add(pointLight)

  const hemi = new THREE.HemisphereLight(0xffffff, 0x0a0a0a, 0.35)
  scene.add(hemi)

  const rim = new THREE.DirectionalLight(0xffffff, 0.55)
  rim.position.set(-60, 80, -40)
  scene.add(rim)

  // Event listeners
  renderer.domElement.addEventListener('mousemove', onMouseMove)
  renderer.domElement.addEventListener('click', onClick)
  renderer.domElement.addEventListener('touchstart', onTouchStart, { passive: false })
  window.addEventListener('resize', onWindowResize)

  // Initial nodes
  createGraph()

  // Start animation loop
  animate()
}

function clearLines() {
  for (const l of lines) {
    scene.remove(l.line)
    l.geometry.dispose()
    l.material.dispose()
  }
  lines.length = 0
}

function addLine(
  from: THREE.Vector3,
  to: THREE.Vector3,
  color: number,
  opacity: number,
  dashed: boolean
) {
  const geometry = new THREE.BufferGeometry().setFromPoints([from, to])
  const material = dashed
    ? new THREE.LineDashedMaterial({
        color,
        transparent: true,
        opacity,
        dashSize: 1.2,
        gapSize: 1.1,
      })
    : new THREE.LineBasicMaterial({ color, transparent: true, opacity })
  const line = new THREE.Line(geometry, material as any)
  if (dashed) {
    line.computeLineDistances()
  }
  scene.add(line)
  lines.push({ line, geometry, material })
}

// Create full hierarchy graph (Category -> Key -> Memory) with connecting lines.
function createGraph() {
  clearNodes()
  clearLines()

  const categories = Array.from(hierarchy.value.entries())
  if (categories.length === 0) return

  // Layout params
  const categoryRadius = 42
  const baseKeyRadius = 18
  const baseMemoryRadius = 7
  const categoryY = 18
  const keyY = 0
  const memoryY = -10

  const categoryAngleStep = (Math.PI * 2) / categories.length
  const segments = isLowPerformance ? 16 : 32

  categories.forEach(([category, keysMap], i) => {
    const categoryColorHex = graphCategoryColors[category] || graphCategoryColors.default
    const categoryColor = hexToInt(categoryColorHex)
    const keyColor = mixColors(categoryColor, COLORS.key, 0.55)
    const memoryColor = mixColors(categoryColor, COLORS.memory, 0.25)

    const angle = i * categoryAngleStep
    const catCenter = new THREE.Vector3(
      Math.cos(angle) * categoryRadius,
      categoryY,
      Math.sin(angle) * categoryRadius
    )

    // Category node (bigger)
    const categorySize = Math.min(7 + keysMap.size * 0.45, 14)
    const catGeometry = new THREE.SphereGeometry(categorySize, segments, segments)
    const catMaterial = new THREE.MeshStandardMaterial({
      color: categoryColor,
      metalness: 0.4,
      roughness: 0.55,
      emissive: mixColors(categoryColor, 0xffffff, 0.25),
      emissiveIntensity: 0.18,
    })
    const catMesh = new THREE.Mesh(catGeometry, catMaterial)
    catMesh.position.copy(catCenter)
    scene.add(catMesh)
    nodes.push({
      mesh: catMesh,
      type: 'category',
      data: {
        label: category,
        count: Array.from(keysMap.values()).reduce((a, b) => a + b.length, 0),
        category,
      },
      position: catCenter.clone(),
      targetPosition: catCenter.clone(),
      velocity: new THREE.Vector3(),
    })
    addTextLabel(category, catMesh, 'category')

    const keys = Array.from(keysMap.entries())
    const keyRadius = baseKeyRadius + Math.min(keys.length, 24) * 0.35
    const keyAngleStep = (Math.PI * 2) / Math.max(keys.length, 1)
    keys.forEach(([key, memories], k) => {
      const keyAngle = k * keyAngleStep
      const keyPos = new THREE.Vector3(
        catCenter.x + Math.cos(keyAngle) * keyRadius,
        keyY,
        catCenter.z + Math.sin(keyAngle) * keyRadius
      )

      const keySize = Math.min(3.5 + memories.length * 0.15, 7)
      const keyGeometry = new THREE.SphereGeometry(keySize, segments, segments)
      const keyMaterial = new THREE.MeshStandardMaterial({
        color: keyColor,
        metalness: 0.4,
        roughness: 0.55,
        emissive: mixColors(keyColor, 0xffffff, 0.2),
        emissiveIntensity: 0.16,
      })
      const keyMesh = new THREE.Mesh(keyGeometry, keyMaterial)
      keyMesh.position.copy(keyPos)
      scene.add(keyMesh)
      nodes.push({
        mesh: keyMesh,
        type: 'key',
        data: { label: key, count: memories.length, category, key },
        position: keyPos.clone(),
        targetPosition: keyPos.clone(),
        velocity: new THREE.Vector3(),
      })
      addTextLabel(key, keyMesh, 'key')
      addLine(catCenter, keyPos, mixColors(categoryColor, 0xffffff, 0.2), 0.22, false)

      const memoryRadius = baseMemoryRadius + Math.min(memories.length, 30) * 0.08
      const memAngleStep = (Math.PI * 2) / Math.max(memories.length, 1)
      memories.forEach((memory, m) => {
        const memAngle = m * memAngleStep
        const memPos = new THREE.Vector3(
          keyPos.x + Math.cos(memAngle) * memoryRadius,
          memoryY,
          keyPos.z + Math.sin(memAngle) * memoryRadius
        )

        const memGeometry = new THREE.SphereGeometry(1.6, segments, segments)
        const memMaterial = new THREE.MeshStandardMaterial({
          color: memoryColor,
          metalness: 0.35,
          roughness: 0.6,
          emissive: mixColors(memoryColor, 0xffffff, 0.15),
          emissiveIntensity: 0.12,
        })
        const memMesh = new THREE.Mesh(memGeometry, memMaterial)
        memMesh.position.copy(memPos)
        scene.add(memMesh)
        nodes.push({
          mesh: memMesh,
          type: 'memory',
          data: { label: memory.key, category, key, memory },
          position: memPos.clone(),
          targetPosition: memPos.clone(),
          velocity: new THREE.Vector3(),
        })
        addLine(keyPos, memPos, mixColors(keyColor, 0xffffff, 0.15), 0.07, true)
      })
    })
  })

  // Camera: show whole graph
  camera.position.set(0, 60, 125)
  controls.target.set(0, 0, 0)
  controls.update()
}

// Add text label above node (using CSS2DRenderer would be better, but keeping it simple)
function addTextLabel(text: string, mesh: THREE.Mesh, type: string) {
  // Skip labels on low-performance devices for better FPS
  if (type === 'memory') {
    return
  }

  const canvas = document.createElement('canvas')
  const context = canvas.getContext('2d')!
  // Reduce texture size on mobile
  canvas.width = isLowPerformance ? 256 : 512
  canvas.height = isLowPerformance ? 64 : 128

  context.fillStyle = 'rgba(0, 0, 0, 0.65)'
  context.fillRect(0, 0, canvas.width, canvas.height)
  context.strokeStyle = 'rgba(255, 255, 255, 0.18)'
  context.lineWidth = 3
  context.strokeRect(3, 3, canvas.width - 6, canvas.height - 6)

  const fontSize = isLowPerformance
    ? type === 'category'
      ? 40
      : 28
    : type === 'category'
      ? 64
      : 40
  context.font =
    type === 'category' ? `Bold ${fontSize}px Inter, sans-serif` : `${fontSize}px Inter, sans-serif`
  context.fillStyle = 'white'
  context.textAlign = 'center'
  context.textBaseline = 'middle'

  const truncated = text.length > 20 ? text.substring(0, 20) + '...' : text
  context.fillText(truncated, canvas.width / 2, canvas.height / 2)

  const texture = new THREE.CanvasTexture(canvas)
  const spriteMaterial = new THREE.SpriteMaterial({ map: texture, transparent: true })
  const sprite = new THREE.Sprite(spriteMaterial)

  const scale = type === 'category' ? 10 : type === 'key' ? 7 : 4
  sprite.scale.set(scale, scale / 4, 1)

  // Get radius from geometry - handle both old and new Three.js APIs
  const radius = (mesh.geometry as any).parameters?.radius || 2
  sprite.position.set(0, radius + 2, 0)

  mesh.add(sprite)
}

// Clear all nodes
function clearNodes() {
  nodes.forEach((node) => {
    scene.remove(node.mesh)
    node.mesh.geometry.dispose()
    ;(node.mesh.material as THREE.Material).dispose()
  })
  nodes.length = 0
  hoveredNode = null
}

// (camera animation removed – fixed camera for full graph view)

// Mouse interaction
function onMouseMove(event: MouseEvent) {
  if (!canvasContainer.value) return

  const rect = canvasContainer.value.getBoundingClientRect()
  mouse.x = ((event.clientX - rect.left) / rect.width) * 2 - 1
  mouse.y = -((event.clientY - rect.top) / rect.height) * 2 + 1

  updateHover()
}

function onTouchStart(event: TouchEvent) {
  if (!canvasContainer.value || event.touches.length === 0) return
  event.preventDefault()

  const rect = canvasContainer.value.getBoundingClientRect()
  const touch = event.touches[0]
  mouse.x = ((touch.clientX - rect.left) / rect.width) * 2 - 1
  mouse.y = -((touch.clientY - rect.top) / rect.height) * 2 + 1

  updateHover()
  onClick() // Treat touch as click
}

function updateHover() {
  raycaster.setFromCamera(mouse, camera)
  const intersects = raycaster.intersectObjects(nodes.map((n) => n.mesh))

  if (hoveredNode) {
    const material = hoveredNode.mesh.material as THREE.MeshStandardMaterial
    material.emissiveIntensity = 0.2
  }

  if (intersects.length > 0) {
    const foundNode = nodes.find((n) => n.mesh === intersects[0].object)
    if (foundNode) {
      hoveredNode = foundNode
      const material = foundNode.mesh.material as THREE.MeshStandardMaterial
      material.emissiveIntensity = 0.6
      canvasContainer.value!.style.cursor = 'pointer'
      return
    }
  }

  hoveredNode = null
  canvasContainer.value!.style.cursor = 'default'
}

function onClick() {
  if (!hoveredNode) return

  if (hoveredNode.type === 'memory' && hoveredNode.data.memory) {
    emit('select-memory', hoveredNode.data.memory)
  }
}

// Window resize
function onWindowResize() {
  if (!canvasContainer.value) return

  const width = canvasContainer.value.clientWidth
  const height = canvasContainer.value.clientHeight

  camera.aspect = width / height
  camera.updateProjectionMatrix()
  renderer.setSize(width, height)
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
    // Fallback below
  }

  isPseudoFullscreen.value = !isPseudoFullscreen.value
  requestAnimationFrame(() => onWindowResize())
}

// Animation loop
function animate() {
  animationFrameId = requestAnimationFrame(animate)

  // Update controls
  controls.update()

  // No need to manually rotate nodes - looks cleaner without
  // Render
  renderer.render(scene, camera)
}

// Lifecycle
onMounted(() => {
  initThreeJS()

  onFullscreenChangeHandler = () => {
    isFullscreen.value = Boolean(document.fullscreenElement)
    if (!isFullscreen.value) {
      isPseudoFullscreen.value = false
    }
    requestAnimationFrame(() => onWindowResize())
  }
  document.addEventListener('fullscreenchange', onFullscreenChangeHandler)

  if (canvasContainer.value && 'ResizeObserver' in window) {
    resizeObserver = new ResizeObserver(() => onWindowResize())
    resizeObserver.observe(canvasContainer.value)
  }
})

onUnmounted(() => {
  if (animationFrameId) {
    cancelAnimationFrame(animationFrameId)
  }

  if (renderer) {
    renderer.domElement.removeEventListener('mousemove', onMouseMove)
    renderer.domElement.removeEventListener('click', onClick)
    renderer.domElement.removeEventListener('touchstart', onTouchStart)
    window.removeEventListener('resize', onWindowResize)
    if (onFullscreenChangeHandler) {
      document.removeEventListener('fullscreenchange', onFullscreenChangeHandler)
    }
    resizeObserver?.disconnect()
    resizeObserver = null
    renderer.dispose()
  }

  if (controls) {
    controls.dispose()
  }

  clearNodes()
})

// Watch memories changes
watch(
  () => props.memories,
  () => {
    createGraph()
  }
)

// no expose
</script>

<template>
  <div
    ref="rootRef"
    class="relative w-full h-full"
    :class="isPseudoFullscreen ? 'fixed inset-0 z-[9998] w-screen h-screen bg-chat' : ''"
    style="min-height: 500px"
  >
    <!-- Canvas Container -->
    <div ref="canvasContainer" class="w-full h-full" style="min-height: 500px" />

    <!-- UI Overlay -->
    <div class="absolute top-4 left-4 right-4 pointer-events-none">
      <div class="flex items-center justify-between">
        <div class="surface-card px-4 py-2 rounded-lg pointer-events-auto">
          <span class="txt-primary font-semibold">{{ $t('memories.graph3dView.title') }}</span>
        </div>

        <div class="flex items-center gap-2 pointer-events-auto">
          <!-- Fullscreen -->
          <button
            class="surface-card px-3 py-2 rounded-lg hover-surface transition-colors"
            :title="isFullscreen ? $t('memories.fullscreen.exit') : $t('memories.fullscreen.enter')"
            @click="toggleFullscreen"
          >
            <Icon
              :icon="isFullscreen ? 'mdi:fullscreen-exit' : 'mdi:fullscreen'"
              class="w-5 h-5 txt-primary"
            />
          </button>

          <!-- Info Card -->
          <div class="surface-card px-4 py-2 rounded-lg">
            <div class="flex items-center gap-4 text-sm">
              <div class="flex items-center gap-2">
                <div
                  class="w-3 h-3 rounded-full"
                  :style="{ backgroundColor: legendColors.category }"
                />
                <span class="txt-secondary">{{ $t('memories.graph3d.category') }}</span>
              </div>
              <div class="flex items-center gap-2">
                <div class="w-3 h-3 rounded-full" :style="{ backgroundColor: legendColors.key }" />
                <span class="txt-secondary">{{ $t('memories.graph3d.key') }}</span>
              </div>
              <div class="flex items-center gap-2">
                <div
                  class="w-3 h-3 rounded-full"
                  :style="{ backgroundColor: legendColors.memory }"
                />
                <span class="txt-secondary">{{ $t('memories.graph3d.memory') }}</span>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Controls hint -->
    <div class="absolute bottom-4 left-4 surface-card px-4 py-2 rounded-lg text-sm txt-secondary">
      <div class="flex items-center gap-4">
        <span
          >{{ $t('memories.graph3d.controls.rotate') }}:
          {{ $t('memories.graph3d.controls.drag') }}</span
        >
        <span
          >{{ $t('memories.graph3d.controls.zoom') }}:
          {{ $t('memories.graph3d.controls.scroll') }}</span
        >
        <span
          >{{ $t('memories.graph3d.controls.select') }}:
          {{ $t('memories.graph3d.controls.click') }}</span
        >
      </div>
    </div>

    <!-- Fullscreen bottom sheet (must be INSIDE fullscreen element to be visible) -->
    <div
      v-if="(isFullscreen || isPseudoFullscreen) && props.selectedMemory"
      class="absolute left-0 right-0 bottom-0 z-[9999] p-4"
    >
      <div class="surface-elevated rounded-2xl p-4 max-h-[40vh] overflow-y-auto scroll-thin">
        <MemorySelectionCard
          :memory="props.selectedMemory"
          :category-color="
            graphCategoryColors[props.selectedMemory.category] || graphCategoryColors.default
          "
          @close="emit('select-memory', null)"
          @edit="emit('edit-memory', $event)"
          @delete="emit('delete-memory', $event)"
        />
      </div>
    </div>
  </div>
</template>

<style scoped>
/* Optional: Add any additional styles */
</style>
