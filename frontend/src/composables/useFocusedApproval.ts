import { computed, ref, watch, type Ref } from 'vue'
import { useRoute } from 'vue-router'
import { approvalsApi, type Approval } from '@/services/api/approvalsApi'
import { useApprovalsStore } from '@/stores/approvals'

/**
 * The approval named in the address, kept in step with the inbox lists.
 *
 * A decision in this inbox or in another session replaces the pending card,
 * so approve and reject do not stay on a row that already finished.
 */
export function useFocusedApproval(tab: Ref<'pending' | 'decided'>) {
  const route = useRoute()
  const store = useApprovalsStore()
  const focusedApproval = ref<Approval | null>(null)
  let focusRequest = 0
  let statusRequest = 0

  const focusedApprovalId = computed(() => positiveQueryId(route.query.approval))
  const taskFilter = computed(() => numericQueryId(route.query.task))

  function adoptFresher(): void {
    focusedApproval.value = adoptListed(focusedApproval.value, store.pending, store.decided)
  }

  function book(): FocusState {
    return {
      tab,
      focused: focusedApproval,
      pending: store.pending,
      decided: store.decided,
      load: (status) => store.load(status),
      openedAt: focusRequest,
      beginRead: () => ++statusRequest,
      isCurrent: (request, openedAt) => request === statusRequest && openedAt === focusRequest,
    }
  }

  async function openFocusedApproval(): Promise<void> {
    const request = ++focusRequest
    statusRequest += 1
    focusedApproval.value = null
    const id = focusedApprovalId.value
    if (id === null) {
      tab.value = 'pending'
      await store.load('pending')
      return
    }
    try {
      const approval = await approvalsApi.get(id)
      if (request !== focusRequest) return
      tab.value = approval.status === 'pending' ? 'pending' : 'decided'
      await store.load(tab.value)
      if (request !== focusRequest) return
      const source = tab.value === 'pending' ? store.pending : store.decided
      focusedApproval.value = source.find((row) => row.id === approval.id) ?? approval
      await rereadIfDecidedElsewhere(book())
    } catch {
      if (request !== focusRequest) return
      focusedApproval.value = null
      await store.load(tab.value)
    }
  }

  watch(focusedApprovalId, () => {
    void openFocusedApproval()
  })
  watch(
    () => store.pending,
    () => {
      adoptFresher()
      void rereadIfDecidedElsewhere(book())
    }
  )
  watch(
    () => store.decided,
    () => {
      adoptFresher()
      void rereadIfStillUnsettled(book())
    }
  )

  const rows = computed(() =>
    inboxRows(tab.value, store.pending, store.decided, focusedApproval.value, taskFilter.value)
  )

  return { focusedApproval, openFocusedApproval, rows }
}

interface FocusState {
  tab: Ref<'pending' | 'decided'>
  focused: Ref<Approval | null>
  pending: Approval[]
  decided: Approval[]
  load: (status: 'pending' | 'decided') => Promise<void>
  openedAt: number
  beginRead: () => number
  isCurrent: (request: number, openedAt: number) => boolean
}

async function rereadIfStillUnsettled(state: FocusState): Promise<void> {
  const focused = state.focused.value
  const listed = newerDecidedCopy(focused, state.decided)
  if (listed) {
    state.focused.value = listed
    return
  }
  if (focused === null || !approvedAndMissing(focused, state.decided)) return
  const request = state.beginRead()
  const again = await readChangedStatus(focused)
  if (again === undefined || !state.isCurrent(request, state.openedAt)) return
  if (state.focused.value?.id !== again.id) return
  state.focused.value = keepTerminal(state.focused.value, again)
}

async function rereadIfDecidedElsewhere(state: FocusState): Promise<void> {
  const known = decidedCopyOfPending(state.focused.value, state.pending, state.decided)
  if (known === 'skip') return
  if (known !== 'fetch') {
    state.focused.value = known
    if (known.status !== 'pending' && state.tab.value === 'pending') {
      state.tab.value = 'decided'
      await state.load('decided')
    }
    return
  }
  const focused = state.focused.value
  if (focused === null) return
  const request = state.beginRead()
  let again: Approval
  try {
    again = await approvalsApi.get(focused.id)
  } catch {
    return
  }
  if (!state.isCurrent(request, state.openedAt)) return
  if (state.focused.value?.id !== again.id || state.focused.value.status !== 'pending') return
  state.focused.value = keepTerminal(state.focused.value, again)
  if (again.status !== 'pending' && state.tab.value === 'pending') {
    state.tab.value = 'decided'
    await state.load('decided')
  }
}

const TERMINAL_STATUS = new Set(['executed', 'failed', 'rejected', 'expired'])

function keepTerminal(current: Approval | null, incoming: Approval): Approval {
  if (
    current !== null &&
    current.id === incoming.id &&
    TERMINAL_STATUS.has(current.status) &&
    !TERMINAL_STATUS.has(incoming.status)
  ) {
    return current
  }
  return incoming
}

function positiveQueryId(raw: unknown): number | null {
  if (typeof raw !== 'string' || raw === '') return null
  const id = Number(raw)
  return Number.isInteger(id) && id > 0 ? id : null
}

function numericQueryId(raw: unknown): number | null {
  if (typeof raw !== 'string' || raw === '') return null
  const id = Number(raw)
  return Number.isFinite(id) ? id : null
}

function adoptListed(
  focused: Approval | null,
  pending: Approval[],
  decided: Approval[]
): Approval | null {
  if (focused === null) return null
  const decidedRow = decided.find((row) => row.id === focused.id)
  if (decidedRow && decidedRow.status !== 'pending' && decidedRow.status !== focused.status) {
    return decidedRow
  }
  const source = focused.status === 'pending' ? pending : decided
  return source.find((row) => row.id === focused.id) ?? focused
}

function newerDecidedCopy(focused: Approval | null, decided: Approval[]): Approval | undefined {
  if (focused === null || focused.status !== 'approved') return undefined
  const listed = decided.find((row) => row.id === focused.id)
  if (listed && listed.status !== focused.status) return listed
  return undefined
}

function approvedAndMissing(focused: Approval | null, decided: Approval[]): boolean {
  return (
    focused !== null &&
    focused.status === 'approved' &&
    !decided.some((row) => row.id === focused.id)
  )
}

async function readChangedStatus(focused: Approval): Promise<Approval | undefined> {
  try {
    const again = await approvalsApi.get(focused.id)
    return again.status === focused.status ? undefined : again
  } catch {
    return undefined
  }
}

function decidedCopyOfPending(
  focused: Approval | null,
  pending: Approval[],
  decided: Approval[]
): Approval | 'fetch' | 'skip' {
  if (focused === null || focused.status !== 'pending') return 'skip'
  if (pending.some((row) => row.id === focused.id)) return 'skip'
  const decidedRow = decided.find((row) => row.id === focused.id)
  if (decidedRow && decidedRow.status !== 'pending') return decidedRow
  return 'fetch'
}

export function inboxRows(
  tab: 'pending' | 'decided',
  pending: Approval[],
  decided: Approval[],
  focused: Approval | null,
  taskId: number | null
): Approval[] {
  const source = tab === 'pending' ? pending : decided
  const stored = focused ? source.find((row) => row.id === focused.id) : undefined
  const stalePending =
    tab === 'pending' &&
    focused !== null &&
    focused.status !== 'pending' &&
    stored?.status === 'pending'
  const visible = stalePending ? source.filter((row) => row.id !== focused.id) : source
  const head = stalePending ? focused : (stored ?? focused)
  const onThisTab =
    head !== null && head !== undefined && (tab === 'pending') === (head.status === 'pending')
  const list = onThisTab ? [head, ...visible.filter((row) => row.id !== head.id)] : visible
  if (taskId === null) return list
  return list.filter((row) => row.requestedBy.taskId === taskId)
}
