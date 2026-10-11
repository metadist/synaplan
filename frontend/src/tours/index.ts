import type { TourDefinition } from './types'

const definitions: TourDefinition[] = [
  {
    id: 'chats',
    steps: [
      { target: 'rail', stepKey: 'rail', side: 'right' },
      { target: 'chat-new', stepKey: 'new', side: 'right' },
      { target: 'chat-input', stepKey: 'input', side: 'top' },
      { target: 'chat-plus', stepKey: 'plus', side: 'top' },
      { target: 'chat-model', stepKey: 'model', side: 'top' },
      { target: 'search', stepKey: 'search', side: 'right' },
    ],
  },
  {
    id: 'library',
    steps: [
      { target: 'library-tabs', stepKey: 'tabs' },
      { target: 'library-upload', stepKey: 'upload' },
      { stepKey: 'use' },
    ],
  },
  {
    id: 'assistants',
    steps: [
      { target: 'assistants-create', stepKey: 'create', side: 'left' },
      { target: 'assistants-gallery', stepKey: 'gallery', side: 'top' },
      { target: 'rail-assistants', stepKey: 'more', side: 'right' },
    ],
  },
  {
    id: 'tasks',
    steps: [{ target: 'tasks-new', stepKey: 'new', side: 'left' }, { stepKey: 'approvals' }],
  },
  {
    id: 'apps',
    steps: [
      { target: 'apps-search', stepKey: 'search' },
      { target: 'apps-tabs', stepKey: 'tabs' },
      { target: 'apps-list', stepKey: 'list', side: 'top' },
    ],
  },
  {
    id: 'admin',
    steps: [
      { target: 'admin-cards', stepKey: 'cards' },
      { target: 'admin-attention', stepKey: 'attention', side: 'top' },
      { target: 'rail-operate', stepKey: 'sections', side: 'right' },
    ],
  },
]

const registry = new Map(definitions.map((tour) => [tour.id, tour]))

export function getTour(id: string): TourDefinition | undefined {
  return registry.get(id)
}

export function tourIds(): string[] {
  return [...registry.keys()]
}
