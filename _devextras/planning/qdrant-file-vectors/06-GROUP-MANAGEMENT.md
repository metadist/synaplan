# Group Management

## Overview

Groups organize vectorized files by purpose, enabling scoped searches and better organization.

---

## Group Key Patterns

### System Groups (Reserved)

| Pattern | Purpose | Created By |
|---------|---------|------------|
| `DEFAULT` | User's general knowledge base | File upload without group |
| `WIDGET:{widgetId}` | Widget-specific files | Widget file upload |
| `TASKPROMPT:{topic}` | Task prompt context | Linking files to prompts |
| `WORDPRESS_WIZARD` | WordPress integration | WordPress wizard |

### User-Defined Groups

Users can create custom groups for organizing files:

| Example | Use Case |
|---------|----------|
| `PROJECT:marketing` | Marketing project files |
| `CLIENT:acme` | Client-specific documents |
| `RESEARCH:ai-trends` | Research collection |
| `PERSONAL:recipes` | Personal knowledge base |

---

## Group Lifecycle

### Creation

Groups are created implicitly when first file is assigned:

```php
// File upload with new group
$groupKey = 'PROJECT:new-initiative';
$vectorStorage->storeChunkBatch($userId, $chunks); // Group created
```

### Listing

```php
// Get all groups for user
$groups = $vectorStorage->getGroupKeys($userId);
// Returns: ['DEFAULT', 'PROJECT:marketing', 'WIDGET:wdg_abc123', ...]
```

### Renaming

```php
// Move file to different group
$vectorStorage->updateGroupKey($userId, $fileId, 'PROJECT:archived');
```

### Deletion

Groups are deleted when:
1. All files in group are deleted
2. Group is explicitly cleared

```php
// Delete all vectors in a group
$vectorStorage->deleteByGroupKey($userId, 'PROJECT:archived');
```

---

## Default Group Behavior

### Standard Chat Upload

When a user uploads a file via the main chat interface without specifying a group:

```php
// FileController.php
$groupKey = $request->get('group_key', 'DEFAULT');

// Files are searchable in general chat context
// RAG searches DEFAULT group by default
```

### Widget Upload

Widget uploads automatically use the widget's group:

```php
// WidgetPublicController.php
$groupKey = sprintf('WIDGET:%s', $widget->getWidgetId());

// Isolated from other widgets and user's main files
```

### Task Prompt Linking

When files are linked to task prompts:

```php
// PromptController.php
$groupKey = sprintf('TASKPROMPT:%s', $topic);

// Files searchable when that prompt is active
```

---

## API Endpoints

### List User Groups

```php
// GET /api/v1/rag/groups
#[Route('/api/v1/rag/groups', methods: ['GET'])]
public function getGroups(#[CurrentUser] User $user): JsonResponse
{
    $groups = $this->vectorStorage->getGroupKeys($user->getId());
    
    return $this->json([
        'groups' => $groups,
        'count' => count($groups),
    ]);
}
```

### Get Group Statistics

```php
// GET /api/v1/rag/groups/{groupKey}/stats
#[Route('/api/v1/rag/groups/{groupKey}/stats', methods: ['GET'])]
public function getGroupStats(
    string $groupKey,
    #[CurrentUser] User $user
): JsonResponse {
    $stats = $this->vectorStorage->getStats($user->getId());
    
    // Filter to specific group
    $groupStats = array_filter(
        $stats['groups'],
        fn($g) => $g['BGROUPKEY'] === $groupKey
    );
    
    return $this->json($groupStats);
}
```

### Create/Rename Group

```php
// POST /api/v1/rag/groups/{groupKey}/rename
#[Route('/api/v1/rag/groups/{groupKey}/rename', methods: ['POST'])]
public function renameGroup(
    string $groupKey,
    Request $request,
    #[CurrentUser] User $user
): JsonResponse {
    $newGroupKey = $request->get('new_group_key');
    
    // Validate new group key
    if (!$this->isValidGroupKey($newGroupKey)) {
        return $this->json(['error' => 'Invalid group key'], 400);
    }
    
    // Update all files in the group
    // Note: This requires iterating files, not a direct rename
    $files = $this->fileRepository->findByGroupKey($user->getId(), $groupKey);
    $updated = 0;
    
    foreach ($files as $file) {
        $updated += $this->vectorStorage->updateGroupKey(
            $user->getId(),
            $file->getId(),
            $newGroupKey
        );
    }
    
    return $this->json(['updated' => $updated]);
}
```

### Delete Group

```php
// DELETE /api/v1/rag/groups/{groupKey}
#[Route('/api/v1/rag/groups/{groupKey}', methods: ['DELETE'])]
public function deleteGroup(
    string $groupKey,
    #[CurrentUser] User $user
): JsonResponse {
    // Prevent deleting system groups
    if (in_array($groupKey, ['DEFAULT']) || str_starts_with($groupKey, 'WIDGET:')) {
        return $this->json(['error' => 'Cannot delete system groups'], 400);
    }
    
    $deleted = $this->vectorStorage->deleteByGroupKey($user->getId(), $groupKey);
    
    return $this->json(['deleted' => $deleted]);
}
```

---

## Search Scoping

### Search Within Group

```php
// POST /api/v1/rag/search
{
    "query": "marketing strategy",
    "group_key": "PROJECT:marketing",  // Optional filter
    "limit": 10,
    "min_score": 0.3
}
```

### Search All Groups

```php
// POST /api/v1/rag/search
{
    "query": "marketing strategy",
    "group_key": null,  // Search all user's vectors
    "limit": 10,
    "min_score": 0.3
}
```

### Chat Context Scoping

```php
// ChatHandler.php
$ragGroupKey = $options['rag_group_key'] ?? null;

// Widget chat: searches only widget's files
// Task prompt: searches task prompt's linked files
// General chat: searches DEFAULT or all (configurable)
```

---

## Group Key Validation

```php
// Valid group key patterns
private function isValidGroupKey(string $groupKey): bool
{
    // Allow alphanumeric, underscore, hyphen, colon
    if (!preg_match('/^[a-zA-Z0-9_:-]+$/', $groupKey)) {
        return false;
    }
    
    // Max length
    if (strlen($groupKey) > 64) {
        return false;
    }
    
    // Reserved prefixes for system use
    $reservedPrefixes = ['WIDGET:', 'TASKPROMPT:', 'SYSTEM:'];
    foreach ($reservedPrefixes as $prefix) {
        if (str_starts_with($groupKey, $prefix)) {
            return false; // Can't create these manually
        }
    }
    
    return true;
}
```

---

## Frontend Integration

### Group Selector Component

```vue
<!-- FileUploadDialog.vue -->
<template>
  <div class="space-y-4">
    <input type="file" @change="handleFile" />
    
    <div class="flex gap-2">
      <select v-model="selectedGroup" class="flex-1">
        <option value="DEFAULT">{{ $t('groups.default') }}</option>
        <option v-for="group in userGroups" :key="group" :value="group">
          {{ formatGroupName(group) }}
        </option>
      </select>
      
      <button @click="showNewGroup = true" class="btn-secondary">
        {{ $t('groups.create') }}
      </button>
    </div>
    
    <input
      v-if="showNewGroup"
      v-model="newGroupName"
      :placeholder="$t('groups.namePlaceholder')"
      class="input"
    />
  </div>
</template>

<script setup lang="ts">
const userGroups = ref<string[]>([])
const selectedGroup = ref('DEFAULT')

onMounted(async () => {
  const response = await httpClient('/api/v1/rag/groups')
  userGroups.value = response.groups.filter(g => !g.startsWith('WIDGET:'))
})
</script>
```

### Group Management Page

```vue
<!-- GroupManagement.vue -->
<template>
  <div class="space-y-6">
    <h2>{{ $t('groups.title') }}</h2>
    
    <div v-for="group in groups" :key="group.key" class="card">
      <div class="flex justify-between items-center">
        <div>
          <h3>{{ formatGroupName(group.key) }}</h3>
          <p class="text-sm text-gray-500">
            {{ group.file_count }} {{ $t('groups.files') }}, 
            {{ group.chunk_count }} {{ $t('groups.chunks') }}
          </p>
        </div>
        
        <div class="flex gap-2">
          <button @click="renameGroup(group)" class="btn-secondary">
            {{ $t('actions.rename') }}
          </button>
          <button 
            @click="deleteGroup(group)" 
            class="btn-danger"
            :disabled="isSystemGroup(group.key)"
          >
            {{ $t('actions.delete') }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
```

---

## Migration Considerations

### Existing Files Without Groups

Files uploaded before group feature have `BGROUPKEY = 'DEFAULT'`. No migration needed - they're already in the default group.

### Widget Files

Already use `WIDGET:{widgetId}` pattern. No changes needed.

### Task Prompt Files

Already use `TASKPROMPT:{topic}` pattern. No changes needed.
