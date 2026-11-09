const MARKERS = [
  '**Admin-Diagnose (nur für dich sichtbar)**',
  '**Admin diagnostics (visible to you only)**',
]

/** Older media failures appended the provider dump to the sentence. */
export function stripStoredAdminDiagnostics(text: string): string {
  let cut = text.length
  for (const marker of MARKERS) {
    const pos = text.indexOf(marker)
    if (pos !== -1 && pos < cut) cut = pos
  }
  if (cut === text.length) return text
  return text
    .slice(0, cut)
    .replace(/\n+---\s*$/, '')
    .trim()
}
