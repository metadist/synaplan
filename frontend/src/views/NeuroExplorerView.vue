<template>
  <div ref="containerRef" class="neuro-explorer" tabindex="0" @keydown="onKeyDown" @keyup="onKeyUp">
    <canvas ref="canvasRef" class="neuro-canvas" />

    <!-- HUD overlay -->
    <div class="hud-top">
      <div class="hud-title">
        <span class="hud-icon">&#x2B22;</span>
        <span>NEURO EXPLORER</span>
      </div>
      <button class="hud-back" @click="goBack">
        <svg width="16" height="16" viewBox="0 0 20 20" fill="currentColor">
          <path
            fill-rule="evenodd"
            d="M17 10a.75.75 0 01-.75.75H5.612l4.158 3.96a.75.75 0 11-1.04 1.08l-5.5-5.25a.75.75 0 010-1.08l5.5-5.25a.75.75 0 111.04 1.08L5.612 9.25H16.25A.75.75 0 0117 10z"
            clip-rule="evenodd"
          />
        </svg>
        {{ $t('neuroExplorer.back') }}
      </button>
    </div>

    <!-- Controls hint -->
    <div v-if="!activeNode" class="hud-controls">
      <div class="control-rows">
        <div class="control-row">
          <div class="control-keys">
            <span class="key">W</span><span class="key">A</span><span class="key">S</span
            ><span class="key">D</span>
          </div>
          <span class="control-label">{{ $t('neuroExplorer.controls') }}</span>
        </div>
        <div class="control-row">
          <span class="key key-wide">SPACE</span>
          <span class="control-label">{{ $t('neuroExplorer.jump') }}</span>
        </div>
        <div class="control-row">
          <span class="key key-wide">SHIFT</span>
          <span class="control-label">{{ $t('neuroExplorer.sprint') }}</span>
        </div>
      </div>
    </div>

    <!-- Stamina bar -->
    <div v-if="!activeNode" class="hud-stamina">
      <div class="stamina-icon">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor">
          <path d="M13 10V3L4 14h7v7l9-11h-7z" />
        </svg>
      </div>
      <div class="stamina-track">
        <div
          class="stamina-fill"
          :style="{ width: stamina * 100 + '%' }"
          :class="{ 'stamina-low': stamina < 0.25 }"
        />
      </div>
    </div>

    <!-- Score / Stats HUD -->
    <div v-if="!activeNode" class="hud-stats">
      <div class="stat-item">
        <span class="stat-label">{{ $t('neuroExplorer.nodesVisited') }}</span>
        <span class="stat-value">{{ visitedNodes.size }} / {{ categoryNodes.length }}</span>
      </div>
      <div class="stat-item">
        <span class="stat-label">{{ $t('neuroExplorer.distance') }}</span>
        <span class="stat-value">{{ Math.floor(totalDistance) }}m</span>
      </div>
    </div>

    <!-- Proximity hint -->
    <Transition name="fade">
      <div v-if="nearestNode && !activeNode" class="hud-proximity">
        <div class="proximity-ring" />
        <span>{{ $t('neuroExplorer.pressEnter') }}</span>
        <span class="proximity-name">{{ nearestNode.label }}</span>
      </div>
    </Transition>

    <!-- Detail panel -->
    <Transition name="panel">
      <div v-if="activeNode" class="detail-panel">
        <div class="panel-header">
          <div class="panel-icon-wrap" :style="{ background: activeNode.color + '22' }">
            <svg
              width="20"
              height="20"
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              stroke-width="2"
              :style="{ color: activeNode.color }"
            >
              <circle cx="12" cy="12" r="10" />
              <path d="M12 6v6l4 2" />
            </svg>
          </div>
          <div class="panel-title-wrap">
            <h2 class="panel-title">{{ activeNode.label }}</h2>
            <p class="panel-subtitle">{{ activeNode.description }}</p>
          </div>
          <button class="panel-close" @click="closePanel">
            <svg width="18" height="18" viewBox="0 0 20 20" fill="currentColor">
              <path
                d="M6.28 5.22a.75.75 0 00-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 101.06 1.06L10 11.06l3.72 3.72a.75.75 0 101.06-1.06L11.06 10l3.72-3.72a.75.75 0 00-1.06-1.06L10 8.94 6.28 5.22z"
              />
            </svg>
          </button>
        </div>
        <div class="panel-body scroll-thin">
          <div v-if="panelLoading" class="panel-loading">
            <div class="panel-spinner" />
          </div>
          <template v-else>
            <div
              v-for="item in panelItems"
              :key="item.id"
              class="panel-item"
              @click="navigateToItem(item)"
            >
              <div class="item-dot" :style="{ background: activeNode.color }" />
              <div class="item-content">
                <p class="item-name">{{ item.name }}</p>
                <p v-if="item.subtitle" class="item-subtitle">{{ item.subtitle }}</p>
              </div>
              <svg
                width="16"
                height="16"
                viewBox="0 0 20 20"
                fill="currentColor"
                class="item-arrow"
              >
                <path
                  fill-rule="evenodd"
                  d="M3 10a.75.75 0 01.75-.75h10.638l-3.96-4.158a.75.75 0 111.08-1.04l5.25 5.5a.75.75 0 010 1.08l-5.25 5.5a.75.75 0 11-1.08-1.04l3.96-4.158H3.75A.75.75 0 013 10z"
                  clip-rule="evenodd"
                />
              </svg>
            </div>
            <p v-if="panelItems.length === 0" class="panel-empty">
              {{ $t('neuroExplorer.empty') }}
            </p>
          </template>
        </div>
        <div class="panel-footer">
          <button class="panel-nav-btn" @click="navigateToCategory">
            {{ $t('neuroExplorer.openFull') }}
            <svg width="14" height="14" viewBox="0 0 20 20" fill="currentColor">
              <path
                fill-rule="evenodd"
                d="M5.22 14.78a.75.75 0 001.06 0l7.22-7.22v5.69a.75.75 0 001.5 0v-7.5a.75.75 0 00-.75-.75h-7.5a.75.75 0 000 1.5h5.69l-7.22 7.22a.75.75 0 000 1.06z"
                clip-rule="evenodd"
              />
            </svg>
          </button>
        </div>
      </div>
    </Transition>
  </div>
</template>

<script setup lang="ts">
import { onMounted, onBeforeUnmount, ref, shallowRef } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import * as THREE from 'three'
import * as widgetsApi from '@/services/api/widgetsApi'
import { getCategories } from '@/services/api/userMemoriesApi'

const router = useRouter()
const { t } = useI18n()

interface CategoryNode {
  id: string
  label: string
  description: string
  color: string
  position: THREE.Vector3
  route: string
}

interface PanelItem {
  id: string
  name: string
  subtitle?: string
  route?: string
}

const containerRef = ref<HTMLDivElement | null>(null)
const canvasRef = ref<HTMLCanvasElement | null>(null)
const nearestNode = ref<CategoryNode | null>(null)
const activeNode = ref<CategoryNode | null>(null)
const panelLoading = ref(false)
const panelItems = ref<PanelItem[]>([])

const keysPressed = new Set<string>()
const playerPos = new THREE.Vector3(0, 0, 0)
const playerVel = new THREE.Vector3(0, 0, 0)
const SPEED = 0.055
const SPRINT_SPEED = 0.11
const FRICTION = 0.91
const INTERACT_DIST = 4.5
const MAX_SPEED = 0.12
const MAX_SPRINT_SPEED = 0.22
const JUMP_FORCE = 0.18
const GRAVITY = 0.006
const DOUBLE_JUMP_FORCE = 0.14

const scene = shallowRef<THREE.Scene | null>(null)
const camera = shallowRef<THREE.PerspectiveCamera | null>(null)
const renderer = shallowRef<THREE.WebGLRenderer | null>(null)
const playerMesh = shallowRef<THREE.Group | null>(null)
const categoryNodes: CategoryNode[] = []
const nodeMeshes: THREE.Mesh[] = []
const connectionLines: THREE.Line[] = []
const particleSystems: THREE.Points[] = []
let animationId = 0
let clock: THREE.Clock
let playerFacingAngle = 0
let walkCycle = 0

// Jump state
let playerYPos = 0
let playerYVel = 0
let isGrounded = true
let canDoubleJump = true
let jumpSquash = 0
let landingImpact = 0

// Sprint & stamina
const stamina = ref(1)
let isSprinting = false

// Stats
const visitedNodes = ref(new Set<string>())
let totalDistance = 0
let lastPos = new THREE.Vector3(0, 0, 0)

// Player limb refs for animation
let leftLeg: THREE.Mesh | null = null
let rightLeg: THREE.Mesh | null = null
let leftArm: THREE.Mesh | null = null
let rightArm: THREE.Mesh | null = null
let playerBody: THREE.Mesh | null = null
let playerHead: THREE.Mesh | null = null
let footGlow: THREE.Mesh | null = null
let trailParticles: THREE.Points | null = null
const TRAIL_COUNT = 120
const trailPositions = new Float32Array(TRAIL_COUNT * 3)
const trailAlphas = new Float32Array(TRAIL_COUNT)
let trailIndex = 0

// Jump particles
let jumpBurstParticles: THREE.Points | null = null
let jumpBurstLife = 0

const categories: Omit<CategoryNode, 'position'>[] = [
  {
    id: 'widgets',
    label: 'Chat Widgets',
    description: 'Embeddable AI chat widgets',
    color: '#6366f1',
    route: '/tools/chat-widget',
  },
  {
    id: 'memories',
    label: 'Memories',
    description: 'AI knowledge & memory graph',
    color: '#10b981',
    route: '/memories',
  },
  {
    id: 'chats',
    label: 'Conversations',
    description: 'Your AI conversations',
    color: '#f59e0b',
    route: '/',
  },
  {
    id: 'files',
    label: 'Files',
    description: 'Uploaded documents & data',
    color: '#ef4444',
    route: '/files',
  },
  {
    id: 'rag',
    label: 'RAG Search',
    description: 'Retrieval-augmented generation',
    color: '#8b5cf6',
    route: '/rag',
  },
  {
    id: 'settings',
    label: 'Configuration',
    description: 'System settings & integrations',
    color: '#64748b',
    route: '/config',
  },
]

function initScene() {
  const canvas = canvasRef.value!
  const container = containerRef.value!
  clock = new THREE.Clock()

  scene.value = new THREE.Scene()
  scene.value.fog = new THREE.FogExp2(0x0a0a1a, 0.018)

  camera.value = new THREE.PerspectiveCamera(
    60,
    container.clientWidth / container.clientHeight,
    0.1,
    200
  )
  camera.value.position.set(0, 18, 14)
  camera.value.lookAt(0, 0, 0)

  renderer.value = new THREE.WebGLRenderer({ canvas, antialias: true, alpha: false })
  renderer.value.setSize(container.clientWidth, container.clientHeight)
  renderer.value.setPixelRatio(Math.min(window.devicePixelRatio, 2))
  renderer.value.setClearColor(0x0a0a1a)

  // Ambient + directional light
  scene.value.add(new THREE.AmbientLight(0x334466, 1.5))
  const dirLight = new THREE.DirectionalLight(0xffffff, 0.6)
  dirLight.position.set(10, 20, 10)
  scene.value.add(dirLight)

  // Ground grid
  createGround()

  // Category nodes in a circle
  const radius = 14
  categories.forEach((cat, i) => {
    const angle = (i / categories.length) * Math.PI * 2 - Math.PI / 2
    const x = Math.cos(angle) * radius
    const z = Math.sin(angle) * radius
    const pos = new THREE.Vector3(x, 0, z)
    const node: CategoryNode = { ...cat, position: pos }
    categoryNodes.push(node)
    createNodeMesh(node)
  })

  // Neural connections between nodes
  createConnections()

  // Floating particles
  createParticles()

  // Player character
  createPlayer()
}

function createGround() {
  const s = scene.value!

  // Solid dark floor
  const floorGeo = new THREE.PlaneGeometry(200, 200)
  const floorMat = new THREE.MeshBasicMaterial({ color: 0x08081a })
  const floor = new THREE.Mesh(floorGeo, floorMat)
  floor.rotation.x = -Math.PI / 2
  floor.position.y = -0.51
  s.add(floor)

  // Fine grid
  const gridGeo = new THREE.PlaneGeometry(100, 100, 100, 100)
  const gridMat = new THREE.MeshBasicMaterial({
    color: 0x151530,
    wireframe: true,
    transparent: true,
    opacity: 0.12,
  })
  const grid = new THREE.Mesh(gridGeo, gridMat)
  grid.rotation.x = -Math.PI / 2
  grid.position.y = -0.49
  s.add(grid)

  // Large grid overlay for depth
  const gridGeo2 = new THREE.PlaneGeometry(100, 100, 20, 20)
  const gridMat2 = new THREE.MeshBasicMaterial({
    color: 0x1a1a40,
    wireframe: true,
    transparent: true,
    opacity: 0.08,
  })
  const grid2 = new THREE.Mesh(gridGeo2, gridMat2)
  grid2.rotation.x = -Math.PI / 2
  grid2.position.y = -0.48
  s.add(grid2)

  // Radial gradient glow at center
  const glowGeo = new THREE.CircleGeometry(18, 48)
  const glowMat = new THREE.MeshBasicMaterial({
    color: 0x0d1040,
    transparent: true,
    opacity: 0.4,
  })
  const glowCircle = new THREE.Mesh(glowGeo, glowMat)
  glowCircle.rotation.x = -Math.PI / 2
  glowCircle.position.y = -0.47
  s.add(glowCircle)
}

function createNodeMesh(node: CategoryNode) {
  const s = scene.value!
  const color = new THREE.Color(node.color)

  // Outer glow ring
  const ringGeo = new THREE.TorusGeometry(2, 0.08, 16, 48)
  const ringMat = new THREE.MeshBasicMaterial({ color, transparent: true, opacity: 0.4 })
  const ring = new THREE.Mesh(ringGeo, ringMat)
  ring.rotation.x = -Math.PI / 2
  ring.position.copy(node.position)
  ring.position.y = 0.1
  s.add(ring)

  // Core sphere
  const sphereGeo = new THREE.IcosahedronGeometry(0.9, 2)
  const sphereMat = new THREE.MeshPhongMaterial({
    color,
    emissive: color,
    emissiveIntensity: 0.3,
    shininess: 80,
    transparent: true,
    opacity: 0.9,
  })
  const sphere = new THREE.Mesh(sphereGeo, sphereMat)
  sphere.position.copy(node.position)
  sphere.position.y = 1.5
  sphere.userData = { nodeId: node.id }
  s.add(sphere)
  nodeMeshes.push(sphere)

  // Point light
  const light = new THREE.PointLight(color, 1.5, 8)
  light.position.copy(node.position)
  light.position.y = 2
  s.add(light)

  // Vertical beam
  const beamGeo = new THREE.CylinderGeometry(0.03, 0.03, 6, 8)
  const beamMat = new THREE.MeshBasicMaterial({ color, transparent: true, opacity: 0.2 })
  const beam = new THREE.Mesh(beamGeo, beamMat)
  beam.position.copy(node.position)
  beam.position.y = 3
  s.add(beam)

  // Label sprite
  const labelCanvas = document.createElement('canvas')
  labelCanvas.width = 512
  labelCanvas.height = 128
  const ctx = labelCanvas.getContext('2d')!
  ctx.clearRect(0, 0, 512, 128)
  ctx.fillStyle = 'white'
  ctx.font = 'bold 42px system-ui, -apple-system, sans-serif'
  ctx.textAlign = 'center'
  ctx.fillText(node.label, 256, 60)
  ctx.font = '24px system-ui, -apple-system, sans-serif'
  ctx.globalAlpha = 0.5
  ctx.fillText(node.description, 256, 100)

  const tex = new THREE.CanvasTexture(labelCanvas)
  const spriteMat = new THREE.SpriteMaterial({ map: tex, transparent: true, depthTest: false })
  const sprite = new THREE.Sprite(spriteMat)
  sprite.position.copy(node.position)
  sprite.position.y = 4.5
  sprite.scale.set(6, 1.5, 1)
  s.add(sprite)
}

function createConnections() {
  const s = scene.value!
  for (let i = 0; i < categoryNodes.length; i++) {
    for (let j = i + 1; j < categoryNodes.length; j++) {
      if (Math.random() > 0.55) continue
      const a = categoryNodes[i].position
      const b = categoryNodes[j].position
      const mid = new THREE.Vector3().lerpVectors(a, b, 0.5)
      mid.y = 2 + Math.random() * 2

      const curve = new THREE.QuadraticBezierCurve3(
        new THREE.Vector3(a.x, 0.5, a.z),
        mid,
        new THREE.Vector3(b.x, 0.5, b.z)
      )
      const points = curve.getPoints(30)
      const geo = new THREE.BufferGeometry().setFromPoints(points)
      const mat = new THREE.LineBasicMaterial({
        color: 0x334466,
        transparent: true,
        opacity: 0.25,
      })
      const line = new THREE.Line(geo, mat)
      s.add(line)
      connectionLines.push(line)
    }
  }
}

function createParticles() {
  const s = scene.value!
  const count = 300
  const positions = new Float32Array(count * 3)
  const colors = new Float32Array(count * 3)

  for (let i = 0; i < count; i++) {
    positions[i * 3] = (Math.random() - 0.5) * 60
    positions[i * 3 + 1] = Math.random() * 12
    positions[i * 3 + 2] = (Math.random() - 0.5) * 60
    const c = new THREE.Color().setHSL(0.6 + Math.random() * 0.2, 0.8, 0.6)
    colors[i * 3] = c.r
    colors[i * 3 + 1] = c.g
    colors[i * 3 + 2] = c.b
  }

  const geo = new THREE.BufferGeometry()
  geo.setAttribute('position', new THREE.BufferAttribute(positions, 3))
  geo.setAttribute('color', new THREE.BufferAttribute(colors, 3))

  const mat = new THREE.PointsMaterial({
    size: 0.12,
    vertexColors: true,
    transparent: true,
    opacity: 0.6,
    blending: THREE.AdditiveBlending,
    depthWrite: false,
  })

  const pts = new THREE.Points(geo, mat)
  s.add(pts)
  particleSystems.push(pts)
}

function createPlayer() {
  const s = scene.value!
  const group = new THREE.Group()

  const coreMat = new THREE.MeshPhongMaterial({
    color: 0x0090cc,
    emissive: 0x00a8e8,
    emissiveIntensity: 0.25,
    shininess: 90,
  })
  const accentMat = new THREE.MeshPhongMaterial({
    color: 0x00d4ff,
    emissive: 0x00d4ff,
    emissiveIntensity: 0.5,
    shininess: 120,
  })

  // Torso
  const torsoGeo = new THREE.CapsuleGeometry(0.22, 0.45, 8, 16)
  playerBody = new THREE.Mesh(torsoGeo, coreMat)
  playerBody.position.y = 0.85
  group.add(playerBody)

  // Head
  const headGeo = new THREE.SphereGeometry(0.2, 16, 16)
  playerHead = new THREE.Mesh(headGeo, accentMat)
  playerHead.position.y = 1.38
  group.add(playerHead)

  // Visor (face indicator)
  const visorGeo = new THREE.SphereGeometry(0.1, 8, 8, 0, Math.PI * 2, 0, Math.PI * 0.4)
  const visorMat = new THREE.MeshBasicMaterial({
    color: 0x00ffff,
    transparent: true,
    opacity: 0.8,
  })
  const visor = new THREE.Mesh(visorGeo, visorMat)
  visor.position.set(0, 1.42, 0.14)
  visor.rotation.x = -0.2
  group.add(visor)

  // Left leg
  const legGeo = new THREE.CapsuleGeometry(0.08, 0.35, 6, 10)
  leftLeg = new THREE.Mesh(legGeo, coreMat)
  leftLeg.position.set(-0.12, 0.32, 0)
  group.add(leftLeg)

  // Right leg
  rightLeg = new THREE.Mesh(legGeo, coreMat)
  rightLeg.position.set(0.12, 0.32, 0)
  group.add(rightLeg)

  // Left arm
  const armGeo = new THREE.CapsuleGeometry(0.06, 0.3, 6, 10)
  leftArm = new THREE.Mesh(armGeo, coreMat)
  leftArm.position.set(-0.32, 0.9, 0)
  group.add(leftArm)

  // Right arm
  rightArm = new THREE.Mesh(armGeo, coreMat)
  rightArm.position.set(0.32, 0.9, 0)
  group.add(rightArm)

  // Foot glow ring
  const glowGeo = new THREE.TorusGeometry(0.4, 0.03, 8, 32)
  const glowMat = new THREE.MeshBasicMaterial({
    color: 0x00d4ff,
    transparent: true,
    opacity: 0.3,
  })
  footGlow = new THREE.Mesh(glowGeo, glowMat)
  footGlow.rotation.x = -Math.PI / 2
  footGlow.position.y = 0.02
  group.add(footGlow)

  // Player light
  const pLight = new THREE.PointLight(0x00d4ff, 1.5, 5)
  pLight.position.y = 1
  group.add(pLight)

  s.add(group)
  playerMesh.value = group

  // Trail particle system
  trailPositions.fill(0)
  trailAlphas.fill(0)
  const trailGeo = new THREE.BufferGeometry()
  trailGeo.setAttribute('position', new THREE.BufferAttribute(trailPositions, 3))
  const trailMat = new THREE.PointsMaterial({
    size: 0.08,
    color: 0x00d4ff,
    transparent: true,
    opacity: 0.4,
    blending: THREE.AdditiveBlending,
    depthWrite: false,
  })
  trailParticles = new THREE.Points(trailGeo, trailMat)
  s.add(trailParticles)

  // Jump burst particle system
  const burstCount = 30
  const burstPositions = new Float32Array(burstCount * 3)
  const burstGeo = new THREE.BufferGeometry()
  burstGeo.setAttribute('position', new THREE.BufferAttribute(burstPositions, 3))
  const burstMat = new THREE.PointsMaterial({
    size: 0.15,
    color: 0x00ffff,
    transparent: true,
    opacity: 0,
    blending: THREE.AdditiveBlending,
    depthWrite: false,
  })
  jumpBurstParticles = new THREE.Points(burstGeo, burstMat)
  s.add(jumpBurstParticles)
}

function spawnJumpBurst() {
  if (!jumpBurstParticles) return
  const positions = jumpBurstParticles.geometry.attributes.position.array as Float32Array
  for (let i = 0; i < positions.length; i += 3) {
    const angle = Math.random() * Math.PI * 2
    const r = 0.3 + Math.random() * 0.5
    positions[i] = playerPos.x + Math.cos(angle) * r
    positions[i + 1] = 0.1
    positions[i + 2] = playerPos.z + Math.sin(angle) * r
  }
  jumpBurstParticles.geometry.attributes.position.needsUpdate = true
  ;(jumpBurstParticles.material as THREE.PointsMaterial).opacity = 0.8
  jumpBurstLife = 1
}

function doJump() {
  if (isGrounded) {
    playerYVel = JUMP_FORCE
    isGrounded = false
    canDoubleJump = true
    jumpSquash = -0.15
    spawnJumpBurst()
  } else if (canDoubleJump) {
    playerYVel = DOUBLE_JUMP_FORCE
    canDoubleJump = false
    jumpSquash = -0.12
    spawnJumpBurst()
  }
}

function animate() {
  animationId = requestAnimationFrame(animate)
  if (!scene.value || !camera.value || !renderer.value) return

  const dt = clock.getDelta()
  const elapsed = clock.getElapsedTime()

  // Sprint detection
  isSprinting = keysPressed.has('shift') && stamina.value > 0.01

  // Player movement with acceleration
  const dir = new THREE.Vector3()
  if (keysPressed.has('w') || keysPressed.has('arrowup')) dir.z -= 1
  if (keysPressed.has('s') || keysPressed.has('arrowdown')) dir.z += 1
  if (keysPressed.has('a') || keysPressed.has('arrowleft')) dir.x -= 1
  if (keysPressed.has('d') || keysPressed.has('arrowright')) dir.x += 1

  const moveSpeed = isSprinting && dir.length() > 0 ? SPRINT_SPEED : SPEED
  const maxSpeed = isSprinting && dir.length() > 0 ? MAX_SPRINT_SPEED : MAX_SPEED

  const isMoving = dir.length() > 0
  if (isMoving) {
    dir.normalize().multiplyScalar(moveSpeed)
    playerVel.add(dir)
  }

  if (playerVel.length() > maxSpeed) {
    playerVel.normalize().multiplyScalar(maxSpeed)
  }
  playerVel.multiplyScalar(FRICTION)
  if (playerVel.length() < 0.001) playerVel.set(0, 0, 0)
  playerPos.add(playerVel)

  // Stamina management
  if (isSprinting && isMoving) {
    stamina.value = Math.max(0, stamina.value - dt * 0.4)
  } else {
    stamina.value = Math.min(1, stamina.value + dt * 0.2)
  }

  // Jump physics
  if (!isGrounded) {
    playerYVel -= GRAVITY
    playerYPos += playerYVel
    if (playerYPos <= 0) {
      playerYPos = 0
      playerYVel = 0
      isGrounded = true
      canDoubleJump = true
      landingImpact = 0.3
      spawnJumpBurst()
    }
  }

  // Landing squash recovery
  jumpSquash *= 0.85
  landingImpact *= 0.88

  playerPos.x = THREE.MathUtils.clamp(playerPos.x, -28, 28)
  playerPos.z = THREE.MathUtils.clamp(playerPos.z, -28, 28)

  // Track distance
  const dist = playerPos.distanceTo(lastPos)
  if (dist > 0.01) totalDistance += dist * 5
  lastPos.copy(playerPos)

  const speed = playerVel.length()
  const speedRatio = Math.min(speed / MAX_SPRINT_SPEED, 1)

  if (playerMesh.value) {
    // Squash & stretch for jump
    const squashY = 1 + jumpSquash - landingImpact * 0.15
    const squashXZ = 1 - jumpSquash * 0.5 + landingImpact * 0.1
    playerMesh.value.scale.set(squashXZ, squashY, squashXZ)

    playerMesh.value.position.set(playerPos.x, playerYPos, playerPos.z)

    // Walk/run cycle
    const cycleSpeed = isSprinting ? 75 : 55
    if (speed > 0.005 && isGrounded) {
      walkCycle += dt * speed * cycleSpeed
    } else if (isGrounded) {
      walkCycle *= 0.92
    }

    // Walking bob (only when grounded)
    if (isGrounded) {
      const bobAmount = speedRatio * (isSprinting ? 0.07 : 0.04)
      playerMesh.value.position.y += Math.abs(Math.sin(walkCycle)) * bobAmount
    }

    // Smooth facing rotation
    if (speed > 0.005) {
      const targetAngle = Math.atan2(playerVel.x, playerVel.z)
      let angleDiff = targetAngle - playerFacingAngle
      while (angleDiff > Math.PI) angleDiff -= Math.PI * 2
      while (angleDiff < -Math.PI) angleDiff += Math.PI * 2
      playerFacingAngle += angleDiff * (isSprinting ? 0.18 : 0.12)
    }
    playerMesh.value.rotation.y = playerFacingAngle

    // Leg animation — wider swing when sprinting, tuck when airborne
    if (!isGrounded) {
      // Airborne tuck
      const tuck = Math.min(playerYVel * 3, 0.4)
      if (leftLeg) leftLeg.rotation.x = -0.3 + tuck
      if (rightLeg) rightLeg.rotation.x = 0.15 + tuck
      if (leftArm) leftArm.rotation.x = 0.4
      if (rightArm) rightArm.rotation.x = -0.2
    } else {
      const swingMultiplier = isSprinting ? 0.8 : 0.5
      const legSwing = Math.sin(walkCycle) * speedRatio * swingMultiplier
      if (leftLeg) {
        leftLeg.rotation.x = legSwing
        leftLeg.position.y = 0.32 - Math.abs(Math.sin(walkCycle)) * speedRatio * 0.04
      }
      if (rightLeg) {
        rightLeg.rotation.x = -legSwing
        rightLeg.position.y = 0.32 - Math.abs(Math.cos(walkCycle)) * speedRatio * 0.04
      }
      if (leftArm) leftArm.rotation.x = -legSwing * 0.8
      if (rightArm) rightArm.rotation.x = legSwing * 0.8
    }

    // Body lean
    if (playerBody) {
      const forwardLean = isGrounded ? speedRatio * (isSprinting ? 0.15 : 0.08) : -0.1
      playerBody.rotation.x = forwardLean
    }

    if (playerHead) {
      playerHead.rotation.x = isGrounded ? -speedRatio * 0.05 : 0.15
    }

    // Foot glow
    if (footGlow) {
      footGlow.rotation.z = elapsed * 0.5
      const gBase = isSprinting ? 1.4 : 1 + speedRatio * 0.2
      footGlow.scale.set(gBase, gBase, 1)
      const glowOpacity = isSprinting ? 0.5 + Math.sin(elapsed * 8) * 0.15 : 0.2 + speedRatio * 0.25
      ;(footGlow.material as THREE.MeshBasicMaterial).opacity = glowOpacity
    }

    // Trail particles
    if (trailParticles && speed > 0.008) {
      const trailsPerFrame = isSprinting ? 3 : 1
      for (let t = 0; t < trailsPerFrame; t++) {
        trailPositions[trailIndex * 3] = playerPos.x + (Math.random() - 0.5) * 0.4
        trailPositions[trailIndex * 3 + 1] = playerYPos + 0.05 + Math.random() * 0.3
        trailPositions[trailIndex * 3 + 2] = playerPos.z + (Math.random() - 0.5) * 0.4
        trailIndex = (trailIndex + 1) % TRAIL_COUNT
      }
      trailParticles.geometry.attributes.position.needsUpdate = true
      const tMat = trailParticles.material as THREE.PointsMaterial
      tMat.opacity = isSprinting ? 0.6 : 0.35
      tMat.size = isSprinting ? 0.12 : 0.08
    }

    // Jump burst decay
    if (jumpBurstParticles && jumpBurstLife > 0) {
      jumpBurstLife -= dt * 3
      const bPositions = jumpBurstParticles.geometry.attributes.position.array as Float32Array
      for (let i = 0; i < bPositions.length; i += 3) {
        const dx = bPositions[i] - playerPos.x
        const dz = bPositions[i + 2] - playerPos.z
        bPositions[i] += dx * 0.08
        bPositions[i + 1] += 0.02
        bPositions[i + 2] += dz * 0.08
      }
      jumpBurstParticles.geometry.attributes.position.needsUpdate = true
      ;(jumpBurstParticles.material as THREE.PointsMaterial).opacity = Math.max(
        0,
        jumpBurstLife * 0.6
      )
    }
  }

  // Camera follow — wider when sprinting, lifts when jumping
  const camTargetX = playerPos.x
  const camTargetZ = playerPos.z + (isSprinting ? 14 : 12)
  const camTargetY = 15 - speedRatio * 1.5 + playerYPos * 0.5
  camera.value.position.x += (camTargetX - camera.value.position.x) * 0.04
  camera.value.position.z += (camTargetZ - camera.value.position.z) * 0.04
  camera.value.position.y += (camTargetY - camera.value.position.y) * 0.03
  camera.value.lookAt(playerPos.x, 0.8 + playerYPos * 0.3, playerPos.z)

  // Track visited nodes
  for (const node of categoryNodes) {
    if (playerPos.distanceTo(node.position) < INTERACT_DIST) {
      visitedNodes.value.add(node.id)
    }
  }

  // Animate nodes
  nodeMeshes.forEach((mesh, i) => {
    mesh.rotation.y = elapsed * 0.4 + i
    mesh.rotation.x = Math.sin(elapsed * 0.25 + i) * 0.15
    mesh.position.y = 1.5 + Math.sin(elapsed * 0.6 + i * 1.2) * 0.25
  })

  // Animate particles
  particleSystems.forEach((ps) => {
    const positions = ps.geometry.attributes.position.array as Float32Array
    for (let i = 0; i < positions.length; i += 3) {
      positions[i + 1] += Math.sin(elapsed * 0.5 + positions[i] * 0.5) * 0.002
    }
    ps.geometry.attributes.position.needsUpdate = true
    ps.rotation.y = elapsed * 0.015
  })

  // Connection pulse
  connectionLines.forEach((line, i) => {
    const mat = line.material as THREE.LineBasicMaterial
    mat.opacity = 0.15 + Math.sin(elapsed * 1.5 + i * 0.8) * 0.1
  })

  // Nearest node detection
  let closest: CategoryNode | null = null
  let closestDist = Infinity
  for (const node of categoryNodes) {
    const d = playerPos.distanceTo(node.position)
    if (d < INTERACT_DIST && d < closestDist) {
      closest = node
      closestDist = d
    }
  }
  nearestNode.value = closest

  renderer.value.render(scene.value, camera.value)
}

function onKeyDown(e: KeyboardEvent) {
  const key = e.key.toLowerCase()
  keysPressed.add(key)

  if (key === ' ' || key === 'space') {
    e.preventDefault()
    doJump()
  }
  if (key === 'enter' && nearestNode.value && !activeNode.value) {
    e.preventDefault()
    openNode(nearestNode.value)
  }
  if (key === 'escape' && activeNode.value) {
    closePanel()
  }
}

function onKeyUp(e: KeyboardEvent) {
  keysPressed.delete(e.key.toLowerCase())
}

async function openNode(node: CategoryNode) {
  activeNode.value = node
  panelLoading.value = true
  panelItems.value = []

  try {
    if (node.id === 'widgets') {
      const widgets = await widgetsApi.listWidgets()
      panelItems.value = widgets.map((w) => ({
        id: w.widgetId,
        name: w.name,
        subtitle: w.widgetId,
        route: `/tools/chat-widget/${w.widgetId}`,
      }))
    } else if (node.id === 'memories') {
      const cats = await getCategories()
      panelItems.value = cats.map((c) => ({
        id: c.category,
        name: `${c.category} (${c.count})`,
        subtitle: t('neuroExplorer.memoryCategory'),
        route: '/memories',
      }))
    } else if (node.id === 'chats') {
      panelItems.value = [{ id: 'open', name: t('neuroExplorer.openChat'), route: '/' }]
    } else if (node.id === 'files') {
      panelItems.value = [{ id: 'open', name: t('neuroExplorer.openFiles'), route: '/files' }]
    } else if (node.id === 'rag') {
      panelItems.value = [{ id: 'open', name: t('neuroExplorer.openRag'), route: '/rag' }]
    } else if (node.id === 'settings') {
      panelItems.value = [
        { id: 'inbound', name: t('neuroExplorer.settingsInbound'), route: '/config/inbound' },
        { id: 'ai', name: t('neuroExplorer.settingsAi'), route: '/config/ai-models' },
        { id: 'users', name: t('neuroExplorer.settingsUsers'), route: '/config/users' },
      ]
    }
  } catch {
    panelItems.value = []
  } finally {
    panelLoading.value = false
  }
}

function closePanel() {
  activeNode.value = null
  containerRef.value?.focus()
}

function navigateToItem(item: PanelItem) {
  if (item.route) router.push(item.route)
}

function navigateToCategory() {
  if (activeNode.value?.route) router.push(activeNode.value.route)
}

function goBack() {
  router.push('/')
}

function onResize() {
  if (!containerRef.value || !camera.value || !renderer.value) return
  const w = containerRef.value.clientWidth
  const h = containerRef.value.clientHeight
  camera.value.aspect = w / h
  camera.value.updateProjectionMatrix()
  renderer.value.setSize(w, h)
}

onMounted(() => {
  initScene()
  animate()
  window.addEventListener('resize', onResize)
  containerRef.value?.focus()
})

onBeforeUnmount(() => {
  cancelAnimationFrame(animationId)
  window.removeEventListener('resize', onResize)
  renderer.value?.dispose()
  scene.value?.clear()
})
</script>

<style scoped>
.neuro-explorer {
  position: fixed;
  inset: 0;
  z-index: 50;
  background: #0a0a1a;
  outline: none;
  overflow: hidden;
}
.neuro-canvas {
  width: 100%;
  height: 100%;
  display: block;
}

/* HUD */
.hud-top {
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 20px 28px;
  pointer-events: none;
}
.hud-title {
  font-size: 14px;
  font-weight: 700;
  letter-spacing: 0.2em;
  color: rgba(255, 255, 255, 0.7);
  display: flex;
  align-items: center;
  gap: 8px;
  text-transform: uppercase;
}
.hud-icon {
  font-size: 18px;
  color: #00d4ff;
}
.hud-back {
  pointer-events: all;
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 8px 16px;
  border-radius: 10px;
  background: rgba(255, 255, 255, 0.06);
  border: 1px solid rgba(255, 255, 255, 0.1);
  color: rgba(255, 255, 255, 0.7);
  font-size: 13px;
  cursor: pointer;
  backdrop-filter: blur(12px);
  transition: all 0.2s;
}
.hud-back:hover {
  background: rgba(255, 255, 255, 0.12);
  color: white;
}

/* Controls */
.hud-controls {
  position: absolute;
  bottom: 28px;
  left: 50%;
  transform: translateX(-50%);
  padding: 12px 20px;
  border-radius: 12px;
  background: rgba(0, 0, 0, 0.55);
  border: 1px solid rgba(255, 255, 255, 0.08);
  backdrop-filter: blur(12px);
}
.control-rows {
  display: flex;
  gap: 16px;
  align-items: center;
}
.control-row {
  display: flex;
  align-items: center;
  gap: 8px;
}
.control-keys {
  display: flex;
  gap: 3px;
}
.key {
  width: 26px;
  height: 26px;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 5px;
  background: rgba(255, 255, 255, 0.1);
  border: 1px solid rgba(255, 255, 255, 0.15);
  color: rgba(255, 255, 255, 0.8);
  font-size: 10px;
  font-weight: 700;
  font-family:
    system-ui,
    -apple-system,
    sans-serif;
}
.key-wide {
  width: auto;
  padding: 0 8px;
  font-size: 9px;
  letter-spacing: 0.04em;
}
.control-label {
  color: rgba(255, 255, 255, 0.4);
  font-size: 11px;
  white-space: nowrap;
}

/* Stamina bar */
.hud-stamina {
  position: absolute;
  bottom: 80px;
  left: 50%;
  transform: translateX(-50%);
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 6px 14px;
  border-radius: 10px;
  background: rgba(0, 0, 0, 0.45);
  border: 1px solid rgba(255, 255, 255, 0.06);
  backdrop-filter: blur(8px);
}
.stamina-icon {
  color: #f59e0b;
  display: flex;
}
.stamina-track {
  width: 100px;
  height: 6px;
  border-radius: 3px;
  background: rgba(255, 255, 255, 0.08);
  overflow: hidden;
}
.stamina-fill {
  height: 100%;
  border-radius: 3px;
  background: linear-gradient(90deg, #f59e0b, #fbbf24);
  transition: width 0.15s ease-out;
}
.stamina-fill.stamina-low {
  background: linear-gradient(90deg, #ef4444, #f87171);
  animation: staminaPulse 0.5s ease-in-out infinite;
}

/* Stats HUD */
.hud-stats {
  position: absolute;
  top: 70px;
  left: 28px;
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.stat-item {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 5px 12px;
  border-radius: 8px;
  background: rgba(0, 0, 0, 0.4);
  border: 1px solid rgba(255, 255, 255, 0.06);
  backdrop-filter: blur(8px);
}
.stat-label {
  font-size: 10px;
  color: rgba(255, 255, 255, 0.35);
  text-transform: uppercase;
  letter-spacing: 0.06em;
}
.stat-value {
  font-size: 12px;
  font-weight: 700;
  color: rgba(255, 255, 255, 0.8);
  font-variant-numeric: tabular-nums;
}

@keyframes staminaPulse {
  0%,
  100% {
    opacity: 1;
  }
  50% {
    opacity: 0.5;
  }
}

/* Proximity hint */
.hud-proximity {
  position: absolute;
  bottom: 90px;
  left: 50%;
  transform: translateX(-50%);
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 4px;
  color: rgba(255, 255, 255, 0.8);
  font-size: 13px;
  text-align: center;
}
.proximity-ring {
  width: 40px;
  height: 40px;
  border-radius: 50%;
  border: 2px solid #00d4ff;
  animation: proximityPulse 1.5s ease-in-out infinite;
  margin-bottom: 4px;
}
.proximity-name {
  font-size: 16px;
  font-weight: 700;
  color: #00d4ff;
}

/* Detail panel */
.detail-panel {
  position: absolute;
  top: 80px;
  right: 28px;
  width: 360px;
  max-height: calc(100vh - 120px);
  background: rgba(14, 14, 30, 0.92);
  border: 1px solid rgba(255, 255, 255, 0.1);
  border-radius: 16px;
  backdrop-filter: blur(20px);
  display: flex;
  flex-direction: column;
  overflow: hidden;
}
.panel-header {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 20px;
  border-bottom: 1px solid rgba(255, 255, 255, 0.06);
}
.panel-icon-wrap {
  width: 40px;
  height: 40px;
  border-radius: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.panel-title-wrap {
  flex: 1;
  min-width: 0;
}
.panel-title {
  font-size: 16px;
  font-weight: 700;
  color: white;
  margin: 0;
}
.panel-subtitle {
  font-size: 12px;
  color: rgba(255, 255, 255, 0.4);
  margin: 2px 0 0;
}
.panel-close {
  width: 32px;
  height: 32px;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 8px;
  background: transparent;
  border: none;
  color: rgba(255, 255, 255, 0.4);
  cursor: pointer;
  transition: all 0.2s;
  flex-shrink: 0;
}
.panel-close:hover {
  background: rgba(255, 255, 255, 0.1);
  color: white;
}
.panel-body {
  flex: 1;
  overflow-y: auto;
  padding: 12px;
}
.panel-loading {
  display: flex;
  justify-content: center;
  padding: 32px;
}
.panel-spinner {
  width: 28px;
  height: 28px;
  border: 3px solid rgba(255, 255, 255, 0.1);
  border-top-color: #00d4ff;
  border-radius: 50%;
  animation: spin 0.8s linear infinite;
}
.panel-item {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 12px 14px;
  border-radius: 10px;
  cursor: pointer;
  transition: all 0.2s;
}
.panel-item:hover {
  background: rgba(255, 255, 255, 0.06);
}
.item-dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  flex-shrink: 0;
}
.item-content {
  flex: 1;
  min-width: 0;
}
.item-name {
  font-size: 14px;
  font-weight: 500;
  color: white;
  margin: 0;
}
.item-subtitle {
  font-size: 11px;
  color: rgba(255, 255, 255, 0.35);
  margin: 2px 0 0;
}
.item-arrow {
  color: rgba(255, 255, 255, 0.2);
  flex-shrink: 0;
  transition: color 0.2s;
}
.panel-item:hover .item-arrow {
  color: rgba(255, 255, 255, 0.6);
}
.panel-empty {
  text-align: center;
  color: rgba(255, 255, 255, 0.3);
  font-size: 13px;
  padding: 24px;
}
.panel-footer {
  padding: 12px 16px;
  border-top: 1px solid rgba(255, 255, 255, 0.06);
}
.panel-nav-btn {
  width: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  padding: 10px;
  border-radius: 10px;
  background: rgba(255, 255, 255, 0.06);
  border: 1px solid rgba(255, 255, 255, 0.1);
  color: rgba(255, 255, 255, 0.7);
  font-size: 13px;
  font-weight: 500;
  cursor: pointer;
  transition: all 0.2s;
}
.panel-nav-btn:hover {
  background: rgba(255, 255, 255, 0.12);
  color: white;
}

/* Transitions */
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.3s;
}
.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}
.panel-enter-active {
  transition: all 0.35s cubic-bezier(0.16, 1, 0.3, 1);
}
.panel-leave-active {
  transition: all 0.2s ease-in;
}
.panel-enter-from {
  opacity: 0;
  transform: translateX(24px) scale(0.96);
}
.panel-leave-to {
  opacity: 0;
  transform: translateX(24px) scale(0.96);
}

@keyframes proximityPulse {
  0%,
  100% {
    transform: scale(1);
    opacity: 0.5;
  }
  50% {
    transform: scale(1.3);
    opacity: 1;
  }
}
@keyframes spin {
  to {
    transform: rotate(360deg);
  }
}
</style>
