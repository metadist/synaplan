export const PREBUFFER_SECONDS: number
export const START_STALL_SECONDS: number

export class PcmStream {
  config(sourceRate: number): void
  push(incoming: ArrayLike<number>): void
  endTurn(): void
  flush(): void
  takeMessages(): { type: string; level?: number }[]
  render(output: Float32Array, deviceRate: number): void
}
