# 05 - Groups and User Isolation

## Overview

Ensure complete user isolation and provide flexible group-based file organization.

---

## User Isolation Guarantee

### Principle: Every Query Filters by user_id

**MariaDB:**
```sql
-- ALWAYS includes user filter
WHERE BUID = :userId AND ...
```

**Qdrant:**
```rust
// ALWAYS includes user filter
Filter::must(vec![
    Condition::matches("user_id", user_id),
    // ... other conditions
])
```

### Enforcement Layers

```
┌────────────────────────────────────────────────────────┐
│  Layer 1: Controller Authentication                     │
│  - #[CurrentUser] attribute extracts authenticated user │
│  - Impossible to query without authentication           │
└────────────────────────────────────────────────────────┘
                         │
                         ▼
┌────────────────────────────────────────────────────────┐
│  Layer 2: Service Parameter                             │
│  - userId is REQUIRED parameter in all service methods  │
│  - Cannot be null or optional                           │
└────────────────────────────────────────────────────────┘
                         │
                         ▼
┌────────────────────────────────────────────────────────┐
│  Layer 3: Storage Filter                                │
│  - MariaDB: WHERE BUID = :userId (always)              │
│  - Qdrant: Filter::must user_id condition (always)     │
│  - No query path exists without user filter            │
└────────────────────────────────────────────────────────┘
```

### Code Example

```php
// VectorStorageInterface - userId is REQUIRED
public function search(SearchQuery $query): array;

// SearchQuery DTO - userId cannot be null
final readonly class SearchQuery
{
    public function __construct(
        public int $userId,        // REQUIRED - not nullable
        public array $vector,
        public ?string $groupKey = null,
        // ...
    ) {}
}

// MariaDBVectorStorage - always filters
public function search(SearchQuery $query): array
{
    $sql = <<<'SQL'
        SELECT ... FROM BRAG r
        WHERE r.BUID = :userId  -- ALWAYS present
        AND ...
    SQL;

    $stmt->bindValue('userId', $query->userId);  // Always bound
}

// QdrantVectorStorage - always filters
public function search(SearchQuery $query): array
{
    $results = $this->client->searchDocuments(
        vector: $query->vector,
        userId: $query->userId,  // ALWAYS passed
        // ...
    );
}
```

---

## Group Key System

### Standard Group Keys

| Pattern | Purpose | Example |
|---------|---------|---------|
| `DEFAULT` | Standard chat uploads, general knowledge | Default for all uploads |
| `WIDGET:{widgetId}` | Widget-specific files | `WIDGET:wdg_abc123...` |
| `TASKPROMPT:{topic}` | Task prompt linked files | `TASKPROMPT:codeme` |
| `WORDPRESS_WIZARD` | WordPress integration files | WordPress setup wizard |
| Custom | User-defined organization | `PROJECT:myapp`, `CATEGORY:legal` |

### Group Key Validation

```php
// GroupKeyValidator.php
final readonly class GroupKeyValidator
{
    private const MAX_LENGTH = 100;
    private const RESERVED_PREFIXES = ['WIDGET:', 'TASKPROMPT:', 'WORDPRESS_', 'SYSTEM:'];
    private const VALID_PATTERN = '/^[A-Za-z0-9_:\-]+$/';

    public function validate(string $groupKey): bool
    {
        // Length check
        if (strlen($groupKey) > self::MAX_LENGTH) {
            return false;
        }

        // Character validation
        if (!preg_match(self::VALID_PATTERN, $groupKey)) {
            return false;
        }

        return true;
    }

    public function isReserved(string $groupKey): bool
    {
        foreach (self::RESERVED_PREFIXES as $prefix) {
            if (str_starts_with($groupKey, $prefix)) {
                return true;
            }
        }
        return false;
    }

    public function sanitize(string $input): string
    {
        // Remove invalid characters, truncate
        $cleaned = preg_replace('/[^A-Za-z0-9_:\-]/', '_', $input);
        return substr($cleaned, 0, self::MAX_LENGTH);
    }
}
```

---

## Default Group Behavior

### Standard Chat Upload → DEFAULT Group

When a user uploads a file in the standard chat (not a widget, not linked to a task prompt):

```php
// FileController::processUploadedFile()
$groupKey = $request->request->get('group_key', 'DEFAULT');

// Validate and sanitize
if (!$this->groupKeyValidator->validate($groupKey)) {
    $groupKey = 'DEFAULT';
}

// Prevent user from using reserved prefixes directly
if ($this->groupKeyValidator->isReserved($groupKey) && !$this->isSystemContext()) {
    throw new BadRequestHttpException('Reserved group key prefix');
}

$this->vectorizationService->vectorizeAndStore(
    $text,
    $user->getId(),
    $file->getId(),
    $groupKey,  // 'DEFAULT' if not specified
    $fileType
);
```

### Widget Upload → WIDGET:{widgetId}

Automatically assigned, cannot be overridden:

```php
// WidgetPublicController::processWidgetFile()
// Group key is ALWAYS the widget ID - not user configurable
$groupKey = sprintf('WIDGET:%s', $widget->getWidgetId());
```

### Task Prompt Link → TASKPROMPT:{topic}

Assigned when linking file to task prompt:

```php
// PromptController::linkFile()
$groupKey = sprintf('TASKPROMPT:%s', $topic);
$this->vectorStorageFacade->updateGroupKey($userId, $fileId, $groupKey);
```

---

## User-Defined Groups

### API Endpoints

```php
// Create/manage custom groups
#[Route('/api/v1/files/groups', methods: ['GET'])]
public function listGroups(#[CurrentUser] User $user): JsonResponse
{
    $groups = $this->vectorStorageFacade->getGroupKeys($user->getId());

    // Categorize groups
    $result = [
        'system' => [],
        'custom' => [],
    ];

    foreach ($groups as $group) {
        if ($this->groupKeyValidator->isReserved($group) || $group === 'DEFAULT') {
            $result['system'][] = $group;
        } else {
            $result['custom'][] = $group;
        }
    }

    return $this->json($result);
}

#[Route('/api/v1/files/{fileId}/group', methods: ['PUT'])]
public function updateFileGroup(
    int $fileId,
    Request $request,
    #[CurrentUser] User $user
): JsonResponse {
    $data = json_decode($request->getContent(), true);
    $newGroupKey = $data['group_key'] ?? 'DEFAULT';

    // Validate
    if (!$this->groupKeyValidator->validate($newGroupKey)) {
        return $this->json(['error' => 'Invalid group key'], 400);
    }

    // Prevent reserved unless system context
    if ($this->groupKeyValidator->isReserved($newGroupKey)) {
        return $this->json(['error' => 'Cannot use reserved group key'], 400);
    }

    $updated = $this->vectorStorageFacade->updateGroupKey(
        $user->getId(),
        $fileId,
        $newGroupKey
    );

    return $this->json(['success' => true, 'updated_chunks' => $updated]);
}
```

### Frontend Group Management

```vue
<!-- frontend/src/components/files/FileGroupSelector.vue -->
<template>
  <div class="space-y-2">
    <label class="block text-sm font-medium">
      {{ $t('files.group') }}
    </label>

    <!-- Existing groups dropdown -->
    <select v-model="selectedGroup" class="w-full rounded-md border-gray-300">
      <option value="DEFAULT">{{ $t('files.groups.default') }}</option>
      <option
        v-for="group in customGroups"
        :key="group"
        :value="group"
      >
        {{ group }}
      </option>
      <option value="__NEW__">{{ $t('files.groups.createNew') }}</option>
    </select>

    <!-- New group input -->
    <input
      v-if="selectedGroup === '__NEW__'"
      v-model="newGroupName"
      type="text"
      :placeholder="$t('files.groups.newName')"
      class="w-full rounded-md border-gray-300"
      @keyup.enter="createGroup"
    />
  </div>
</template>

<script setup lang="ts">
const props = defineProps<{
  fileId: number
  currentGroup: string
}>()

const emit = defineEmits<{
  update: [groupKey: string]
}>()

const selectedGroup = ref(props.currentGroup)
const newGroupName = ref('')
const customGroups = ref<string[]>([])

// Load existing groups
onMounted(async () => {
  const response = await httpClient('/api/v1/files/groups')
  customGroups.value = response.custom
})

// Watch for group changes
watch(selectedGroup, async (newValue) => {
  if (newValue === '__NEW__') return

  await httpClient(`/api/v1/files/${props.fileId}/group`, {
    method: 'PUT',
    body: { group_key: newValue }
  })

  emit('update', newValue)
})

const createGroup = async () => {
  if (!newGroupName.value.trim()) return

  const groupKey = newGroupName.value.trim()
    .toUpperCase()
    .replace(/[^A-Z0-9_-]/g, '_')

  await httpClient(`/api/v1/files/${props.fileId}/group`, {
    method: 'PUT',
    body: { group_key: groupKey }
  })

  customGroups.value.push(groupKey)
  selectedGroup.value = groupKey
  newGroupName.value = ''
  emit('update', groupKey)
}
</script>
```

---

## RAG Search Group Filtering

### Chat with Specific Group

```php
// When user wants to search only in specific group(s)
$ragResults = $this->vectorSearchService->semanticSearch(
    userId: $user->getId(),
    queryText: $message,
    groupKey: 'PROJECT:myapp',  // Only search this group
    limit: 10,
    minScore: 0.3
);
```

### Widget Chat (Always Scoped)

```php
// WidgetPublicController::message()
$processingOptions = [
    'rag_group_key' => sprintf('WIDGET:%s', $widget->getWidgetId()),
    // Widget chat ALWAYS searches only in widget's files
];
```

### Task Prompt Chat

```php
// ChatHandler::loadRagContext()
// When message is routed to a task prompt, search that prompt's files
$groupKey = sprintf('TASKPROMPT:%s', $taskPromptTopic);
$ragResults = $this->vectorSearchService->semanticSearch(
    $userId,
    $messageText,
    $groupKey,
    $limit,
    $minScore
);
```

### Cross-Group Search (All User Files)

```php
// When groupKey is null, search ALL user's files
$ragResults = $this->vectorSearchService->semanticSearch(
    userId: $user->getId(),
    queryText: $message,
    groupKey: null,  // Search across ALL groups
    limit: 10,
    minScore: 0.3
);
```

---

## Future: Group Sharing (Not in v1)

For future consideration - sharing groups between users:

```php
// NOT IMPLEMENTED IN V1
// Potential future structure:

// Table: BRAG_GROUP_SHARES
// - group_owner_id (user who owns the group)
// - group_key
// - shared_with_user_id
// - permission_level ('read', 'write')
// - created_at

// Search would then check:
// WHERE (BUID = :userId OR EXISTS (share_permission))
```

**Note:** V1 maintains strict user isolation. Group sharing is a future feature that would require careful security review.

---

## Security Checklist

- [x] user_id is REQUIRED in all storage methods
- [x] user_id filter is ALWAYS applied in queries
- [x] No query path exists without user_id
- [x] Reserved group keys cannot be assigned by users
- [x] Group keys are validated and sanitized
- [x] Widget files cannot be moved to other groups
- [x] Task prompt links are system-managed
- [x] No cross-user data access possible
