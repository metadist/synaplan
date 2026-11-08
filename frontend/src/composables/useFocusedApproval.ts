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

  const focusedApprovalId = computed(() => {
    const raw = route.query.approval
    if (typeof raw !== 'string' || raw === '') return null
    const id = Number(raw)
    return Number.isInteger(id) && id > 0 ? id : null
  })

  const taskFilter = computed(() => {
    const raw = route.query.task
    if (typeof raw !== 'string' || raw === '') return null
    const id = Number(raw)
    return Number.isFinite(id) ? id : null
  })

  function adoptFresher(): void {
    const focused = focusedApproval.value
    if (focused === null) return
    const decided = store.decided.find((row) => row.id === focused.id)
    if (decided && decided.status !== 'pending' && focused.status === 'pending') {
      focusedApproval.value = decided
      return
    }
    const source = focused.status === 'pending' ? store.pending : store.decided
    const here = source.find((row) => row.id === focused.id)
    if (here) focusedApproval.value = here
  }

  async function rereadIfDecidedElsewhere(): Promise<void> {
    const focused = focusedApproval.value
    const openedAt = focusRequest
    if (focused === null || focused.status !== 'pending') return
    if (store.pending.some((row) => row.id === focused.id)) return
    const decided = store.decided.find((row) => row.id === focused.id)
    if (decided && decided.status !== 'pending') {
      focusedApproval.value = decided
      return
    }
    const request = ++statusRequest
    try {
      const again = await approvalsApi.get(focused.id)
      if (request !== statusRequest || openedAt !== focusRequest) return
      if (focusedApproval.value?.id !== focused.id || focusedApproval.value.status !== 'pending') {
        return
      }
      focusedApproval.value = again
      if (again.status !== 'pending' && tab.value === 'pending') {
        tab.value = 'decided'
        await store.load('decided')
      }
    } catch {
      // A failed refresh is not a decision. The card stays until the next load.
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
      await rereadIfDecidedElsewhere()
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
      void rereadIfDecidedElsewhere()
    }
  )

  watch(
    () => store.decided,
    () => {
      adoptFresher()
    }
  )

  const rows = computed(() => {
    const source = tab.value === 'pending' ? store.pending : store.decided
    const focused = focusedApproval.value
    const stored = focused ? source.find((row) => row.id === focused.id) : undefined
    const stalePending =
      tab.value === 'pending' &&
      focused !== null &&
      focused.status !== 'pending' &&
      stored?.status === 'pending'
    const visible = stalePending ? source.filter((row) => row.id !== focused.id) : source
    const head = stalePending ? focused : (stored ?? focused)
    const onThisTab =
      head !== null &&
      head !== undefined &&
      (tab.value === 'pending') === (head.status === 'pending')
    const list = onThisTab ? [head, ...visible.filter((row) => row.id !== head.id)] : visible
    if (taskFilter.value === null) return list
    return list.filter((row) => row.requestedBy.taskId === taskFilter.value)
  })

  return { focusedApproval, openFocusedApproval, rows }
}
