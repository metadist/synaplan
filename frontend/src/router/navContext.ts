/**
 * Additive route classification for the Work / Manage / Operate shell.
 * Does not change guards — callers may read `meta.context` only.
 */
export type NavContext = 'work' | 'manage' | 'operate' | 'personal' | 'public'

export function inferNavContext(path: string, meta: Record<string, unknown> = {}): NavContext {
  if (
    meta.public === true ||
    path.startsWith('/shared') ||
    path.startsWith('/addin/') ||
    path === '/account-deletion' ||
    path === '/login' ||
    path === '/register' ||
    path === '/forgot-password' ||
    path === '/reset-password'
  ) {
    return 'public'
  }

  if (path.startsWith('/admin') || path === '/setup' || meta.requiresAdmin === true) {
    return 'operate'
  }

  if (
    path.startsWith('/profile') ||
    path.startsWith('/settings') ||
    path.startsWith('/memories') ||
    path.startsWith('/statistics') ||
    path.startsWith('/feedbacks') ||
    path.startsWith('/subscription')
  ) {
    return 'personal'
  }

  if (
    path.startsWith('/channels') ||
    path.startsWith('/ai') ||
    path.startsWith('/plugins') ||
    path.startsWith('/tools') ||
    path.startsWith('/config')
  ) {
    return 'manage'
  }

  return 'work'
}
