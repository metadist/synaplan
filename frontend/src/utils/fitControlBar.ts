/**
 * How the single composer control row gives up space.
 *
 * The tools summary leaves first. The model name collapses to the service
 * icon and chevron only when the row still overflows. Plus, files, the
 * microphone and send stay.
 */
export interface ControlBarFit {
  hideToolsSummary: boolean
  collapseModelName: boolean
}

export function chooseControlBarFit(
  fits: (hideToolsSummary: boolean, collapseModelName: boolean) => boolean
): ControlBarFit {
  if (fits(false, false)) {
    return { hideToolsSummary: false, collapseModelName: false }
  }
  if (fits(true, false)) {
    return { hideToolsSummary: true, collapseModelName: false }
  }
  return { hideToolsSummary: true, collapseModelName: true }
}
