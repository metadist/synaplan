export type TourSide = 'top' | 'right' | 'bottom' | 'left'

export interface TourStep {
  /** `data-tour` value of the target; omit for a centered step. */
  target?: string
  /** i18n keys under `tours.<tourId>.<stepKey>.title|body`. */
  stepKey: string
  side?: TourSide
}

export interface TourDefinition {
  id: string
  steps: TourStep[]
}
