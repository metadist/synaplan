type Closer = () => void

let active: Closer | null = null

/** One kebab menu at a time: opening a second instance closes the first. */
export function claimOfficeActionsMenu(close: Closer): void {
  if (active && active !== close) {
    const previous = active
    active = close
    previous()
    return
  }
  active = close
}

export function releaseOfficeActionsMenu(close: Closer): void {
  if (active === close) {
    active = null
  }
}
