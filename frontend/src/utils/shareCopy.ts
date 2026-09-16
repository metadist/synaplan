export type ShareKind =
  'conversation' | 'knowledge_folder' | 'assistant' | 'agent' | 'saved_task' | 'widget'

export type ShareVia = { type: string; name: string }

export const SHARE_PERMISSIONS: Record<ShareKind, readonly string[]> = {
  conversation: ['read', 'use'],
  knowledge_folder: ['read', 'use', 'edit', 'manage'],
  assistant: ['read', 'use', 'edit'],
  agent: ['read', 'use', 'edit'],
  saved_task: ['read', 'use'],
  widget: ['read', 'edit', 'manage'],
}

export function defaultSharePermission(kind: ShareKind): string {
  return SHARE_PERMISSIONS[kind].includes('use') ? 'use' : SHARE_PERMISSIONS[kind][0]
}

function copyKind(kind: ShareKind): Exclude<ShareKind, 'agent'> {
  return kind === 'agent' ? 'assistant' : kind
}

export function shareConsequenceKey(kind: ShareKind, permission: string): string {
  return `iam.dialog.consequence.${copyKind(kind)}.${permission}`
}

export function shareFindKey(kind: ShareKind): string {
  return `iam.dialog.find.${copyKind(kind)}`
}

export function shareBannerSourceKey(kind: ShareKind, viaType: string): string {
  const key = copyKind(kind)
  if (viaType === 'group') return `iam.banner.${key}.sourceGroup`
  if (viaType === 'everyone') return `iam.banner.${key}.sourceEveryone`
  return `iam.banner.${key}.sourceDirect`
}

export function isShareKind(value: string): value is ShareKind {
  return value in SHARE_PERMISSIONS
}
