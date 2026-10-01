export interface AmbientPlacement {
  topPct: number
  rightPct: number
  rotateDeg: number
}

/**
 * Base watermark corner (top 8%, right 5%, 12 degrees clockwise) drifted per
 * page: +0..20 points down/right and a 0..15 degree tilt to the left.
 */
export function randomizeAmbientPlacement(rng: () => number = Math.random): AmbientPlacement {
  return {
    topPct: 8 + rng() * 20,
    rightPct: 5 + rng() * 20,
    rotateDeg: 12 - rng() * 15,
  }
}
