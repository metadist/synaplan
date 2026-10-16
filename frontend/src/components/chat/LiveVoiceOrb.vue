<script setup lang="ts">
import { onMounted, onUnmounted, ref, watch } from 'vue'
import type { LivePhase } from '@/services/liveVoice/machine'
import {
  orbBreath,
  orbEnergy,
  orbLevel,
  orbScale,
  smoothOrbLevel,
  voiceLineAmplitude,
  voiceLineWave,
} from '@/services/liveVoice/orb'

const props = defineProps<{
  phase: LivePhase
  micLevel: number
  outLevel: number
  muted: boolean
}>()

const BODY_RADIUS = 0.27
const LINE_GAP = 0.022
const LINE_SPREAD = 0.016
const LINE_REACH = 0.085
const LINE_POINTS = 160
const LINES = [
  { width: 0.011, alpha: 0.95 },
  { width: 0.0075, alpha: 0.62 },
  { width: 0.005, alpha: 0.4 },
]
const RIPPLE_LIFE_MS = 1800
const COLOR_REFRESH_MS = 500

type Rgb = [number, number, number]
interface Ripple {
  born: number
  strength: number
}

const root = ref<HTMLElement | null>(null)
const canvas = ref<HTMLCanvasElement | null>(null)

let frame = 0
let level = 0
let amplitude = 0
let ripples: Ripple[] = []
let lastRipple = 0
let brand: Rgb = [0, 63, 199]
let brandLight: Rgb = [107, 143, 214]
let colorsReadAt = 0
let motion: MediaQueryList | null = null
let resizer: ResizeObserver | null = null

function parseColor(value: string, fallback: Rgb): Rgb {
  const raw = value.trim()
  const hex = /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.exec(raw)
  if (hex) {
    const digits =
      hex[1].length === 3
        ? hex[1]
            .split('')
            .map((d) => d + d)
            .join('')
        : hex[1]
    return [0, 2, 4].map((i) => parseInt(digits.slice(i, i + 2), 16)) as Rgb
  }
  const rgb = /^rgba?\(\s*([\d.]+)[\s,]+([\d.]+)[\s,]+([\d.]+)/i.exec(raw)
  if (rgb) return [Number(rgb[1]), Number(rgb[2]), Number(rgb[3])]
  return fallback
}

function readColors(now: number) {
  if (!root.value || now - colorsReadAt < COLOR_REFRESH_MS) return
  colorsReadAt = now
  const style = getComputedStyle(root.value)
  brand = parseColor(style.getPropertyValue('--brand'), brand)
  brandLight = parseColor(style.getPropertyValue('--brand-light'), brandLight)
}

function rgba([r, g, b]: Rgb, alpha: number): string {
  return `rgba(${r}, ${g}, ${b}, ${Math.max(0, Math.min(1, alpha)).toFixed(3)})`
}

function fitCanvas() {
  const el = canvas.value
  if (!el) return
  const ratio = Math.min(window.devicePixelRatio || 1, 2)
  const width = Math.round(el.clientWidth * ratio)
  if (width > 0 && el.width !== width) {
    el.width = width
    el.height = width
  }
}

function drawGlow(ctx: CanvasRenderingContext2D, size: number, body: number, energy: number) {
  const center = size / 2
  const glow = ctx.createRadialGradient(center, center, body * 0.85, center, center, center)
  glow.addColorStop(0, rgba(brand, 0.3 + 0.4 * energy))
  glow.addColorStop(0.55, rgba(brand, 0.08 + 0.12 * energy))
  glow.addColorStop(1, rgba(brand, 0))
  ctx.fillStyle = glow
  ctx.fillRect(0, 0, size, size)
}

function drawRipples(ctx: CanvasRenderingContext2D, size: number, body: number, now: number) {
  const center = size / 2
  ripples = ripples.filter((ripple) => now - ripple.born < RIPPLE_LIFE_MS)
  for (const ripple of ripples) {
    const progress = (now - ripple.born) / RIPPLE_LIFE_MS
    const eased = 1 - (1 - progress) ** 3
    ctx.beginPath()
    ctx.arc(center, center, body + (center - body) * eased, 0, Math.PI * 2)
    ctx.lineWidth = Math.max(1, size * 0.008 * (1 - progress * 0.7))
    ctx.strokeStyle = rgba(brand, (1 - progress) ** 2 * 0.75 * ripple.strength)
    ctx.stroke()
  }
}

function drawVoiceLines(
  ctx: CanvasRenderingContext2D,
  size: number,
  body: number,
  seconds: number
) {
  const center = size / 2
  const inner = body + size * LINE_GAP
  const reach = size * LINE_REACH
  const time = props.phase === 'thinking' ? seconds * 2.4 : seconds

  const stroke = ctx.createLinearGradient(0, 0, size, size)
  stroke.addColorStop(0, rgba(brandLight, 1))
  stroke.addColorStop(1, rgba(brand, 1))

  ctx.save()
  ctx.lineJoin = 'round'
  ctx.strokeStyle = stroke
  ctx.shadowColor = rgba(brand, 0.55)
  ctx.shadowBlur = size * 0.035
  LINES.forEach((line, index) => {
    const base = inner + index * size * LINE_SPREAD
    ctx.beginPath()
    for (let p = 0; p <= LINE_POINTS; p++) {
      const angle = (p / LINE_POINTS) * Math.PI * 2
      const swing = 0.25 + 0.75 * (0.5 + 0.5 * voiceLineWave(angle, time, index))
      const radius = base + reach * amplitude * swing
      const x = center + Math.cos(angle) * radius
      const y = center + Math.sin(angle) * radius
      if (p === 0) ctx.moveTo(x, y)
      else ctx.lineTo(x, y)
    }
    ctx.closePath()
    ctx.globalAlpha = line.alpha * (props.muted ? 0.35 : 1)
    ctx.lineWidth = Math.max(1, size * line.width)
    ctx.stroke()
  })
  ctx.restore()
}

function step(now: number, animate: boolean) {
  const seconds = animate ? now / 1000 : 0
  level = animate ? smoothOrbLevel(level, orbLevel(props)) : 0
  const energy = orbEnergy(level)
  const scale = orbScale(props.phase, energy) + orbBreath(props.phase, seconds)
  root.value?.style.setProperty('--orb-scale', scale.toFixed(3))

  const target = voiceLineAmplitude({ phase: props.phase, energy, muted: props.muted })
  amplitude = animate ? amplitude + (target - amplitude) * 0.18 : target

  if (animate && energy > 0.22 && now - lastRipple > 560 - 300 * energy) {
    ripples.push({ born: now, strength: energy })
    lastRipple = now
  }
  if (!animate) ripples = []

  const el = canvas.value
  const ctx = el?.getContext('2d')
  if (!el || !ctx) return
  readColors(now)
  const size = el.width
  const body = size * BODY_RADIUS * scale
  ctx.clearRect(0, 0, size, size)
  drawGlow(ctx, size, body, energy)
  drawRipples(ctx, size, body, now)
  drawVoiceLines(ctx, size, body, seconds)
}

function tick(now: number) {
  step(now, true)
  frame = window.requestAnimationFrame(tick)
}

function stop() {
  if (frame) window.cancelAnimationFrame(frame)
  frame = 0
}

function start() {
  stop()
  colorsReadAt = 0
  if (motion?.matches) {
    step(performance.now(), false)
    return
  }
  frame = window.requestAnimationFrame(tick)
}

watch(
  () => [props.phase, props.muted],
  () => {
    if (motion?.matches) step(performance.now(), false)
  }
)

onMounted(() => {
  motion = window.matchMedia?.('(prefers-reduced-motion: reduce)') ?? null
  motion?.addEventListener?.('change', start)
  fitCanvas()
  if (typeof ResizeObserver !== 'undefined' && canvas.value) {
    resizer = new ResizeObserver(() => {
      fitCanvas()
      if (motion?.matches) step(performance.now(), false)
    })
    resizer.observe(canvas.value)
  }
  start()
})

onUnmounted(() => {
  stop()
  resizer?.disconnect()
  motion?.removeEventListener?.('change', start)
})
</script>

<template>
  <div
    ref="root"
    class="live-orb relative h-52 w-52 min-[400px]:h-68 min-[400px]:w-68"
    :data-phase="phase"
    :data-muted="muted ? 'true' : 'false'"
    aria-hidden="true"
    data-testid="live-voice-orb"
  >
    <canvas ref="canvas" class="live-orb__glow absolute inset-0 h-full w-full" />
    <div
      v-if="phase === 'connecting'"
      class="live-orb__connect"
      data-testid="live-voice-connecting-ring"
      aria-hidden="true"
    />
    <div class="live-orb__scaler">
      <div class="live-orb__body">
        <div class="live-orb__cloud live-orb__cloud--a" />
        <div class="live-orb__cloud live-orb__cloud--b" />
        <div class="live-orb__cloud live-orb__cloud--c" />
        <div class="live-orb__sheen" />
        <div class="live-orb__rim" />
        <div class="live-orb__shine" />
      </div>
    </div>
  </div>
</template>

<style scoped>
/* Every hue comes from the brand tokens, so light, dark and OLED each get
   their own orb without a second color definition. */
.live-orb {
  --orb-scale: 1;
  --orb-drift: 9s;
  --orb-spin: 16s;
}

/* Scale lives on the wrapper. The circle itself must not be transformed:
   WebKit drops border-radius clipping on a transformed layer, so the
   blurred clouds paint a square behind the orb. Firefox keeps the clip. */
.live-orb__scaler {
  position: absolute;
  inset: 23%;
  border-radius: 9999px;
  transform: scale(var(--orb-scale));
  box-shadow:
    0 26px 56px -18px color-mix(in srgb, var(--brand) 75%, transparent),
    0 0 60px color-mix(in srgb, var(--brand) 30%, transparent);
  transition:
    filter 400ms ease,
    opacity 400ms ease;
}

.live-orb__glow {
  background: transparent;
}

.live-orb__body {
  position: absolute;
  inset: 0;
  overflow: hidden;
  border-radius: inherit;
  background: radial-gradient(
    circle at 34% 26%,
    color-mix(in srgb, var(--brand-light) 55%, #fff) 0%,
    var(--brand-light) 20%,
    var(--brand) 48%,
    var(--brand-fill) 82%,
    color-mix(in srgb, var(--brand-fill) 80%, #000) 100%
  );
  box-shadow:
    inset 0 -10px 24px color-mix(in srgb, #000 16%, transparent),
    inset 0 0 0 1px color-mix(in srgb, #fff 22%, transparent);
  clip-path: circle(50%);
  /* A mask, not only overflow, so filter and mix-blend stay inside the circle. */
  -webkit-mask-image: radial-gradient(circle, #fff 99%, transparent 100%);
  mask-image: radial-gradient(circle, #fff 99%, transparent 100%);
  isolation: isolate;
}

.live-orb__cloud {
  position: absolute;
  width: 72%;
  height: 72%;
  border-radius: 9999px;
  filter: blur(10px);
  mix-blend-mode: screen;
  animation: live-orb-drift var(--orb-drift) ease-in-out infinite alternate;
}

.live-orb__cloud--a {
  top: -8%;
  left: -12%;
  background: radial-gradient(
    circle,
    color-mix(in srgb, var(--brand-light) 70%, #fff),
    transparent 68%
  );
  opacity: 0.85;
}

.live-orb__cloud--b {
  right: -18%;
  bottom: -10%;
  background: radial-gradient(circle, color-mix(in srgb, var(--brand) 70%, #fff), transparent 68%);
  opacity: 0.55;
  animation-duration: calc(var(--orb-drift) * 1.3);
  animation-direction: alternate-reverse;
}

.live-orb__cloud--c {
  top: 26%;
  left: 20%;
  width: 55%;
  height: 55%;
  background: radial-gradient(circle, color-mix(in srgb, #fff 60%, transparent), transparent 62%);
  opacity: 0.5;
  animation-duration: calc(var(--orb-drift) * 0.8);
}

.live-orb__sheen {
  position: absolute;
  inset: -40%;
  background: conic-gradient(
    from 0deg,
    transparent 0deg,
    color-mix(in srgb, #fff 30%, transparent) 60deg,
    transparent 130deg,
    color-mix(in srgb, var(--brand-light) 45%, transparent) 220deg,
    transparent 300deg
  );
  filter: blur(14px);
  mix-blend-mode: soft-light;
  animation: live-orb-spin var(--orb-spin) linear infinite;
}

.live-orb__rim {
  position: absolute;
  inset: 0;
  border-radius: 9999px;
  background: radial-gradient(
    circle at 55% 105%,
    color-mix(in srgb, var(--brand-light) 75%, transparent) 0%,
    transparent 42%
  );
  mix-blend-mode: screen;
}

.live-orb__shine {
  position: absolute;
  inset: 9% 34% 64% 20%;
  border-radius: 9999px;
  background: radial-gradient(ellipse, color-mix(in srgb, #fff 85%, transparent), transparent 70%);
  filter: blur(3px);
}

.live-orb__connect {
  position: absolute;
  inset: 4%;
  border-radius: 9999px;
  border: 3px solid color-mix(in srgb, var(--brand) 28%, transparent);
  border-top-color: var(--brand);
  border-right-color: var(--brand);
  animation: live-orb-spin 0.9s linear infinite;
  pointer-events: none;
}

.live-orb[data-phase='connecting'] {
  --orb-drift: 5s;
  --orb-spin: 8s;
}

.live-orb[data-phase='connecting'] .live-orb__scaler {
  opacity: 0.8;
}

.live-orb[data-phase='thinking'] {
  --orb-drift: 3.5s;
  --orb-spin: 4s;
}

.live-orb[data-phase='speaking'] {
  --orb-drift: 5s;
  --orb-spin: 9s;
}

.live-orb[data-muted='true'] .live-orb__scaler {
  filter: saturate(0.3);
  opacity: 0.6;
}

.live-orb[data-phase='ended'] .live-orb__scaler,
.live-orb[data-phase='idle'] .live-orb__scaler {
  opacity: 0.5;
}

.live-orb[data-phase='ended'] *,
.live-orb[data-phase='idle'] * {
  animation-play-state: paused;
}

@keyframes live-orb-drift {
  0% {
    transform: translate(0, 0) scale(1);
  }
  50% {
    transform: translate(18%, 12%) scale(1.15);
  }
  100% {
    transform: translate(-8%, 22%) scale(0.9);
  }
}

@keyframes live-orb-spin {
  to {
    transform: rotate(360deg);
  }
}

@media (prefers-reduced-motion: reduce) {
  .live-orb *,
  .live-orb {
    animation: none !important;
    transition: none !important;
  }
}
</style>
