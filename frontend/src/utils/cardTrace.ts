import type { TaskCard } from '@/stores/taskCard'
import { parseFolderRead } from '@/utils/folderRead'
import { parseSearchRecovery } from '@/utils/searchRecovery'

/**
 * Card fields a step reports besides its text, file and error, in their wire
 * form. The live `task_update` event, the reloaded message and the in-progress
 * turn all carry them under these names.
 */
export interface CardTraceWire {
  step_input?: string | null
  step_output?: string | null
  step_output_truncated?: boolean | null
  duration_ms?: number | null
  folder_read?: unknown
  recovery?: unknown
}

type CardTraceFields = Pick<
  TaskCard,
  'stepInput' | 'stepOutput' | 'stepOutputTruncated' | 'durationMs' | 'folderRead' | 'recovery'
>

/** Only the fields that are present, so a later event never blanks an earlier one. */
export function cardTraceFrom(wire: CardTraceWire): CardTraceFields {
  const fields: CardTraceFields = {}
  if (typeof wire.step_input === 'string' && wire.step_input) fields.stepInput = wire.step_input
  if (typeof wire.step_output === 'string' && wire.step_output) fields.stepOutput = wire.step_output
  if (wire.step_output_truncated === true) fields.stepOutputTruncated = true
  if (typeof wire.duration_ms === 'number') fields.durationMs = wire.duration_ms
  const folderRead = parseFolderRead(wire.folder_read)
  if (folderRead) fields.folderRead = folderRead
  const recovery = parseSearchRecovery(wire.recovery)
  if (recovery) fields.recovery = recovery
  return fields
}
