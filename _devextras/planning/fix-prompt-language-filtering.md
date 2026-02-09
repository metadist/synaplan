# Fix Prompt Language Filtering Bug

**Date:** 2026-02-09
**Branch:** fix/sorting-prompt
**Status:** Planning

## Problem

System prompts (ownerId=0) are seeded only with `BLANG='en'` in `backend/src/DataFixtures/PromptFixtures.php`. When a user switches the UI to German/Spanish/Turkish, API calls include `?language=de` (etc.), and all listing queries apply `WHERE language = :lang` -- finding zero system prompts for non-English languages. This causes:

- Empty task list on `/config/sorting-prompt`
- Empty prompt dropdowns in widget creation/editing
- Missing categories in the sorting prompt's `[DYNAMICLIST]`

The **runtime message sorting** (`MessageSorter`) already works around this by looping through all supported languages, but the UI-facing endpoints are broken.

## Root Cause Flow

```mermaid
sequenceDiagram
    participant User
    participant Frontend
    participant API as PromptController
    participant DB as BPROMPTS

    User->>Frontend: Switch language to DE
    Frontend->>API: GET /api/v1/prompts?language=de
    API->>DB: WHERE ownerId=0 AND language='de'
    DB-->>API: [] (empty - system prompts only have language='en')
    API-->>Frontend: { prompts: [] }
    Frontend-->>User: Empty prompt list
```

## Solution Strategy

**For system prompts (ownerId=0):** Remove the language filter -- they should always be visible regardless of the user's UI language. Their text stays in English.

**For user custom prompts:** Keep the language filter -- custom prompts are shown in the language they were created in.

**Frontend:** Use the current locale instead of hardcoding `'en'`, and watch for locale changes to reload data.

## Backend Changes

### 1. `PromptRepository.php` -- 3 methods

**`findAllForUser()`** (line 158-171): Split into two queries -- system prompts without language filter, user prompts with language filter. Merge results with user prompts overriding system ones.

```php
public function findAllForUser(int $userId, string $lang = 'en'): array
{
    // System prompts: no language filter
    $system = $this->createQueryBuilder('p')
        ->where('p.ownerId = 0')
        ->andWhere('p.topic NOT LIKE :toolsPrefix')
        ->setParameter('toolsPrefix', 'tools:%')
        ->orderBy('p.topic', 'ASC')
        ->getQuery()->getResult();

    // User prompts: filtered by language
    $user = $this->createQueryBuilder('p')
        ->where('p.ownerId = :userId')
        ->andWhere('p.language = :lang')
        ->andWhere('p.topic NOT LIKE :toolsPrefix')
        ->setParameter('userId', $userId)
        ->setParameter('lang', $lang)
        ->setParameter('toolsPrefix', 'tools:%')
        ->orderBy('p.topic', 'ASC')
        ->getQuery()->getResult();

    // Merge: user prompts override system for same topic
    $map = [];
    foreach ($system as $p) { $map[$p->getTopic()] = $p; }
    foreach ($user as $p) { $map[$p->getTopic()] = $p; }
    return array_values($map);
}
```

**`getTopicsWithDescriptions()`** (line 84-126): Same split -- system prompts always included, user prompts filtered by language.

**`findPromptsWithSelectionRules()`** (line 182-197): Same split pattern.

### 2. `PromptController.php` -- `list()` method

**`list()` action** (line 106-185): The system prompts query (line 117-125) currently has `->andWhere('p.language = :lang')`. Remove this language filter for system prompts. Keep it for user prompts query (line 128-137).

### 3. Simplify `MessageSorter.php` (optional cleanup)

The `MessageSorter` lines 106-115 loop through ALL supported languages as a workaround. After fixing the repository, this workaround can be simplified to a single call.

Similarly, the selection rules lookup (line 281-283) loops through languages and can be simplified.

## Frontend Changes

### 4. `TaskPromptsConfiguration.vue` -- line 903

Change `getPrompts('en')` to use current locale:

```typescript
const { locale } = useI18n()
// ...
const data = await promptsApi.getPrompts(locale.value || 'en')
```

Add a watcher to reload when language changes.

### 5. `SortingPromptConfiguration.vue` -- add locale watcher

Already passes `locale.value` on load (line 352), but does NOT watch for changes. Add:

```typescript
import { watch } from 'vue'
watch(locale, () => { loadSortingPrompt() })
```

### 6. `AdvancedWidgetConfig.vue` -- line 1415

Change `getPrompts('en')` to `getPrompts(locale.value || 'en')`.

### 7. `WidgetEditorModal.vue` -- line 851

Change `listPrompts()` (defaults to `'en'`) to `listPrompts(locale.value || 'en')`.

### 8. `WidgetCreationWizard.vue` -- line 986

Change `listPrompts()` to `listPrompts(locale.value || 'en')`.

## Files Affected Summary

- `backend/src/Repository/PromptRepository.php` -- 3 methods: remove lang filter for system prompts
- `backend/src/Controller/PromptController.php` -- `list()`: remove lang filter for system prompts query
- `backend/src/Service/Message/MessageSorter.php` -- Simplify language loop workaround (cleanup)
- `frontend/src/components/config/TaskPromptsConfiguration.vue` -- Use locale instead of hardcoded 'en', add watcher
- `frontend/src/components/config/SortingPromptConfiguration.vue` -- Add locale watcher for reload
- `frontend/src/components/widgets/AdvancedWidgetConfig.vue` -- Use locale instead of hardcoded 'en'
- `frontend/src/components/widgets/WidgetEditorModal.vue` -- Pass locale to listPrompts()
- `frontend/src/components/widgets/WidgetCreationWizard.vue` -- Pass locale to listPrompts()

## What Is NOT Affected

- **Runtime message sorting**: `findByTopic()` and `findByTopicAndUser()` do NOT filter by language -- the actual AI processing pipeline is unaffected
- **Prompt creation/update/delete**: These write operations are not affected by this change
- **Widget runtime**: Widgets reference prompts by `taskPromptTopic` (a topic key), not by language
- **WhatsApp flows**: WhatsApp uses the standard message pipeline which uses `findByTopicAndUser()` -- no language filter
- **SortX plugin**: Uses its own endpoints and references prompts by topic

## Testing Checklist

- [ ] Switch UI to DE, navigate to `/config/sorting-prompt` -- categories should show
- [ ] Switch UI to DE, navigate to `/config/task-prompts` -- system prompts should appear
- [ ] Create a custom prompt in DE, switch to EN -- custom prompt should not appear, system prompts should
- [ ] Widget creation wizard in DE -- system prompts should appear in dropdown
- [ ] Widget editor modal in DE -- prompt selection should work
- [ ] Switch language while on sorting-prompt page -- content should reload
