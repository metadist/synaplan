# AI Agent Rules for Yusuf's Environment

## ⚠️ CRITICAL RULES

### Language
- **Code & Comments**: ALWAYS English
- **Chat responses**: ALWAYS German
- **NEVER** write German in code/comments

### Git Operations
- **NEVER execute git commands** (add, commit, push, etc.)
- **ONLY output** git commands for user to execute
- User runs all git commands manually

### Docker Environment
- Use `docker compose exec` for all commands in containers
- Backend: `docker compose exec backend [command]`
- Frontend: `docker compose exec frontend [command]`
- Example: `docker compose exec backend php bin/console cache:clear`

---

## Frontend Rules

### CSS & Styling
- **ALWAYS use Tailwind CSS** from `@/style.css`
- NO custom CSS classes (use Tailwind utilities)
- Dark mode: Use Tailwind classes (`dark:bg-gray-800`)

### Translations (i18n)
- **ALWAYS use vue-i18n** for all text
- Template: `{{ $t('key.path') }}`
- Script: `const { t } = useI18n()`
- Add translations to `src/i18n/de.json` and `src/i18n/en.json`

### Type Safety & Validation
- **ALWAYS use Zod schemas** for API responses
- Generate schemas from OpenAPI: `npm run generate:schemas`
- Use with httpClient: `httpClient('/api/endpoint', { schema: MySchema })`
- **NEVER** write manual TypeScript interfaces for API responses
- Types: `type MyType = z.infer<typeof MySchema>`

### Configuration
- **NO** `VITE_*` env vars for runtime config (only dev/build-time)
- Load config from `/api/v1/config/runtime` API
- Use `useConfigStore()` for runtime config
- Dev-only flags are OK as `VITE_*` (e.g., `VITE_AUTO_LOGIN_DEV`)

### Modern JavaScript
- Use top-level `await` (not `.then()`)
- Use `const`/`let` (never `var`)
- Arrow functions preferred
- No semicolons (ESLint enforces this)

### Vue 3 Composition API Rules
- **ALWAYS use `<script setup>`** (modern syntax)
- **Prefer `ref()` over `reactive()`** (more flexible)
- **Use `computed()` for derived state** (auto-cached, efficient)
- **Clean up in `onUnmounted()`** (event listeners, timers, intervals)
- **Unique `:key` in `v-for`** loops (ALWAYS required)
- **NEVER mutate props** (read-only, emit events to parent)
- **NEVER combine `v-if` and `v-for`** on same element (performance issue)

### Performance: Lazy Loading Components
```typescript
// ✅ Lazy load heavy components (charts, modals, editor)
import { defineAsyncComponent } from 'vue'

const HeavyChart = defineAsyncComponent({
  loader: () => import('@/components/HeavyChart.vue'),
  loadingComponent: LoadingSpinner,
  delay: 200,
  timeout: 5000
})

// ✅ Lazy load routes
const routes = [
  {
    path: '/dashboard',
    component: () => import('@/views/Dashboard.vue')
  }
]

// ❌ Don't lazy load small/frequently used components
// ❌ Don't lazy load components needed immediately on page load
```

---

## Backend Rules

### OpenAPI & Code Generation
- **ALWAYS write OpenAPI annotations** for API endpoints
- Frontend generates Zod schemas from these annotations
- Complete annotations: `@OA\Response`, `@OA\Property`, required fields, examples

### Database Usage
- **NEVER hardcode AI model names** (use `ModelRepository`)
- Query models from database: `$modelRepository->find($modelId)`
- Check model availability: `/api/v1/config/models/{id}/check`
- User can configure models via UI

### API Documentation (Swagger UI)
- **ALWAYS use Swagger UI** to test endpoints: `http://localhost:8000/api/doc`
- Interactive API testing (no need for Postman/curl)
- See all parameters, request/response examples
- Test authentication, file uploads, streaming
- Before creating new endpoints: Check if similar exists in Swagger

### Code Style (PHP)
- PSR-12 compliance (enforced by php-cs-fixer)
- Type hints required: `public function foo(string $bar): int`
- Readonly properties when possible
- Final classes by default
- Import statements sorted alphabetically
- No spaces around `.` (string concatenation): `$a.$b` not `$a . $b`

### Symfony & Doctrine Best Practices (2024/2025)

#### ✅ User::eraseCredentials() Deprecation (Symfony 7.3+)
**Problem:** `eraseCredentials()` is deprecated and will be removed in Symfony 8.0

**Solution 1:** Add `#[\Deprecated]` attribute (if method is empty):
```php
use Symfony\Component\Security\Core\User\UserInterface;

class User implements UserInterface
{
    #[\Deprecated(
        message: 'eraseCredentials() is empty and will be removed in the future. Sensitive data is not stored in User entity.',
        since: 'symfony/security-http 7.3'
    )]
    public function eraseCredentials(): void
    {
        // Nothing to do - we don't store sensitive temp data
    }
}
```

**Solution 2:** Move sensitive data cleanup to `__serialize()` (if needed):
```php
class User implements UserInterface
{
    private string $plainPassword; // Temporary, not persisted

    #[\Deprecated]
    public function eraseCredentials(): void
    {
        // Empty - logic moved to __serialize()
    }

    public function __serialize(): array
    {
        $data = (array) $this;
        // Remove sensitive data before serialization
        unset($data["\0".self::class."\0plainPassword"]);
        return $data;
    }
}
```

**Why:** Symfony 7.3+ prefers explicit serialization control over automatic credential erasure.

#### ✅ Doctrine DBAL Statement::executeQuery() Parameters (DBAL 3.4+)
**Problem:** Passing parameters directly to `executeQuery($params)` is deprecated

**❌ Old (deprecated):**
```php
$stmt = $conn->prepare('SELECT * FROM users WHERE email = :email');
$result = $stmt->executeQuery(['email' => $email]); // Deprecated!
```

**✅ New (use bindValue first):**
```php
$stmt = $conn->prepare('SELECT * FROM users WHERE email = :email');
$stmt->bindValue('email', $email);
$result = $stmt->executeQuery(); // No parameters!
```

**✅ With type hints:**
```php
$stmt = $conn->prepare('SELECT * FROM users WHERE id = :id AND active = :active');
$stmt->bindValue('id', $userId, \PDO::PARAM_INT);
$stmt->bindValue('active', true, \PDO::PARAM_BOOL);
$result = $stmt->executeQuery();
```

**✅ Array parameters (IN clause):**
```php
use Doctrine\DBAL\ArrayParameterType;

$stmt = $conn->prepare('SELECT * FROM users WHERE id IN (:ids)');
$stmt->bindValue('ids', $userIds, ArrayParameterType::INTEGER);
$result = $stmt->executeQuery();
```

**Why:** Explicit binding improves clarity and type safety. DBAL 4.0 will remove parameter passing entirely.

#### ✅ Other Symfony 7.4+ Best Practices

**1. Use explicit Request methods (not `Request::get()`):**
```php
// ❌ Deprecated (ambiguous)
$value = $request->get('param');

// ✅ Explicit
$value = $request->query->get('param');      // Query string (?param=value)
$value = $request->request->get('param');    // POST body
$value = $request->attributes->get('param'); // Route parameters
```

**2. Security: HTTPS & Secure Cookies:**
```yaml
# config/packages/framework.yaml
framework:
    session:
        cookie_secure: auto
        cookie_httponly: true
        cookie_samesite: lax
```

**3. Performance: Enable OPcache in production:**
```ini
; php.ini
opcache.enable=1
opcache.memory_consumption=256
opcache.interned_strings_buffer=16
opcache.max_accelerated_files=20000
```

**4. Use Composer optimization in production:**
```bash
composer install --no-dev --optimize-autoloader --classmap-authoritative
```

**Why:** These changes prepare for Symfony 8.0 (removes deprecated features) and improve security/performance.

---

## Code Organization & Architecture

### ❌ DON'T: Everything in One File

```php
// ❌ BAD: 2000 lines Controller with everything
class UserController extends AbstractController
{
    // User CRUD
    public function create() { /* 100 lines */ }
    public function update() { /* 150 lines */ }
    public function delete() { /* 80 lines */ }

    // Email logic
    private function sendWelcomeEmail() { /* 50 lines */ }
    private function sendPasswordReset() { /* 60 lines */ }

    // Validation
    private function validateEmail() { /* 30 lines */ }
    private function validatePassword() { /* 40 lines */ }

    // Business logic
    private function calculateUserLevel() { /* 100 lines */ }
    private function checkSubscription() { /* 80 lines */ }

    // Database queries
    private function findUsersByLevel() { /* 40 lines */ }
    private function getUserStats() { /* 60 lines */ }
}
```

### ✅ DO: Separation of Concerns

```
backend/src/
├── Controller/
│   └── UserController.php          # HTTP only (50 lines)
├── Service/
│   ├── UserService.php             # Business logic (150 lines)
│   └── EmailService.php            # Email handling (100 lines)
├── Repository/
│   └── UserRepository.php          # Database queries (80 lines)
└── Validator/
    └── UserValidator.php           # Validation logic (60 lines)
```

### Backend: File Organization

```php
// ✅ Controller: HTTP handling ONLY (thin)
#[Route('/api/v1/users')]
class UserController extends AbstractController
{
    public function __construct(
        private UserService $userService,
        private UserValidator $validator,
    ) {}

    #[Route('', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        // Validate
        $errors = $this->validator->validate($data);
        if ($errors) {
            return $this->json(['errors' => $errors], Response::HTTP_BAD_REQUEST);
        }

        // Delegate to Service
        try {
            $user = $this->userService->createUser($data);
            return $this->json(['user' => $user], Response::HTTP_CREATED);
        } catch (\Exception $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }
}

// ✅ Service: Business logic
final readonly class UserService
{
    public function __construct(
        private EntityManagerInterface $em,
        private UserRepository $userRepository,
        private EmailService $emailService,
        private PasswordHasherInterface $passwordHasher,
    ) {}

    public function createUser(array $data): User
    {
        // Check if exists
        if ($this->userRepository->findOneBy(['email' => $data['email']])) {
            throw new \InvalidArgumentException('Email already exists');
        }

        // Create entity
        $user = new User();
        $user->setEmail($data['email']);
        $user->setPassword($this->passwordHasher->hash($data['password']));
        $user->setLevel('FREE');

        // Persist
        $this->em->persist($user);
        $this->em->flush();

        // Send email (async)
        $this->emailService->sendWelcomeEmail($user);

        return $user;
    }
}

// ✅ Repository: Database queries
class UserRepository extends ServiceEntityRepository
{
    public function findActiveUsers(): array
    {
        return $this->createQueryBuilder('u')
            ->where('u.active = :active')
            ->setParameter('active', true)
            ->orderBy('u.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findByLevel(string $level): array
    {
        return $this->findBy(['level' => $level], ['createdAt' => 'DESC']);
    }
}

// ✅ Validator: Validation logic
final readonly class UserValidator
{
    public function validate(array $data): ?array
    {
        $errors = [];

        if (empty($data['email']) || !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Invalid email address';
        }

        if (empty($data['password']) || strlen($data['password']) < 8) {
            $errors['password'] = 'Password must be at least 8 characters';
        }

        return $errors ?: null;
    }
}
```

### Frontend: File Organization

```
frontend/src/
├── views/                    # Pages (routes)
│   ├── ChatView.vue         # Main chat page
│   ├── LoginView.vue        # Login page
│   └── SettingsView.vue     # Settings page
├── components/              # Reusable components
│   ├── ChatInput.vue        # Chat input component
│   ├── Dialog.vue           # Global dialog
│   └── config/              # Config-specific components
│       ├── AIModelsConfiguration.vue
│       └── APIKeysConfiguration.vue
├── composables/             # Reusable logic
│   ├── useAuth.ts           # Auth logic
│   ├── useDialog.ts         # Dialog system
│   └── useNotification.ts   # Notifications
├── stores/                  # Pinia state management
│   ├── auth.ts              # Auth state
│   ├── config.ts            # App config
│   └── models.ts            # AI models state
├── services/                # API clients
│   ├── authService.ts       # Auth API
│   └── api/                 # HTTP clients
│       ├── httpClient.ts    # Base HTTP client
│       ├── widgetsApi.ts    # Widgets API
│       └── configApi.ts     # Config API
├── router/                  # Vue Router
│   └── index.ts             # Route definitions
└── i18n/                    # Translations
    ├── de.json              # German
    └── en.json              # English
```

### Component Size Guidelines

**❌ TOO BIG** (needs splitting):
```vue
<!-- UserDashboard.vue - 2000 lines -->
<template>
  <!-- Profile section -->
  <div>...</div>
  <!-- Settings section -->
  <div>...</div>
  <!-- Statistics section -->
  <div>...</div>
  <!-- Billing section -->
  <div>...</div>
</template>

<script setup lang="ts">
// 1500 lines of logic...
</script>
```

**✅ WELL ORGANIZED**:
```
components/user/
├── UserDashboard.vue           # 100 lines (orchestrator)
├── UserProfile.vue             # 150 lines
├── UserSettings.vue            # 200 lines
├── UserStatistics.vue          # 120 lines
└── UserBilling.vue             # 180 lines
```

### When to Create New Files

**Create new file when:**
- Component > 300 lines → Split into smaller components
- Controller method > 50 lines → Extract to Service
- Service > 500 lines → Split by responsibility (UserService → UserCreationService, UserUpdateService)
- Repeated code in 3+ places → Extract to composable/service
- Logic unrelated to main purpose → Separate file

**Don't create new file when:**
- Only 1-2 simple methods
- Tightly coupled to parent (would need too many props)
- Used only once in entire codebase

---

## Common AI Mistakes to Avoid

### ❌ DON'T

#### Frontend Anti-Patterns
```typescript
// ❌ Native JS dialogs (ugly, blocking, not customizable)
alert('Something happened')
confirm('Are you sure?')
prompt('Enter name:')

// ❌ Manual interface (will break if API changes)
interface RuntimeConfig {
  recaptcha: { enabled: boolean }
}

// ❌ setTimeout for race conditions (fix the logic!)
setTimeout(() => { flag = false }, 500)

// ❌ .then() chains (use await)
config.init().then(() => { app.mount('#app') })

// ❌ Hardcoded text (use i18n)
<h1>Welcome to Synaplan</h1>
const message = 'File deleted successfully'

// ❌ Custom CSS classes
<div class="my-custom-class">...</div>
.my-custom-class { color: red; }

// ❌ Options API (old Vue style)
export default {
  data() { return { count: 0 } },
  methods: { increment() { this.count++ } }
}

// ❌ Direct DOM manipulation
document.getElementById('myElement').style.display = 'none'
$('#myElement').hide()

// ❌ Synchronous HTTP (blocking)
const xhr = new XMLHttpRequest()
xhr.open('GET', '/api/data', false)

// ❌ console.log everywhere
console.log('Debug:', data)
console.log('User clicked button')

// ❌ Mutating props directly
props.user.name = 'New Name' // Props are read-only!

// ❌ v-if and v-for on same element
<div v-for="item in items" v-if="item.active"> // Performance issue!

// ❌ Missing :key in v-for
<div v-for="item in items"> // ALWAYS need :key

// ❌ Using reactive() for everything
const state = reactive({ count: 0, name: '' }) // Use ref() instead

// ❌ Methods for derived state (use computed)
const fullName = () => firstName.value + ' ' + lastName.value

// ❌ Not cleaning up event listeners
window.addEventListener('resize', handler) // Memory leak if not removed!

// ❌ Lazy loading everything
const SmallButton = defineAsyncComponent(() => import('./SmallButton.vue')) // Overkill!
```

#### Backend Anti-Patterns
```php
// ❌ Hardcoded model names
if ($modelName === 'gpt-4') { ... }
$model = 'claude-3-opus';

// ❌ Direct array access without validation
$data = json_decode($request->getContent(), true);
$email = $data['email']; // What if 'email' doesn't exist?

// ❌ Database operations in controllers (use Services!)
$user = new User();
$user->setEmail($email);
$this->em->persist($user);
$this->em->flush();

// ❌ Generic error messages
throw new \Exception('Error occurred');
return $this->json(['error' => 'Something went wrong']);

// ❌ No type hints
function processData($data) { ... }
public function getData() { ... }

// ❌ Comments about deleted code
// ─────────────────────────────────────────
// Old implementation (removed 2024-12-19)
// This code was replaced by new approach
// ─────────────────────────────────────────

// ❌ Magic numbers without constants
if ($score > 0.5) { ... }
sleep(300);

// ❌ Not using Repositories
$users = $this->em->createQuery('SELECT u FROM App\Entity\User u')->getResult();

// ❌ Deprecated Doctrine DBAL usage (will break in DBAL 4.0)
$stmt = $conn->prepare('SELECT * FROM users WHERE email = :email');
$result = $stmt->executeQuery(['email' => $email]); // Parameters in executeQuery()

// ❌ Missing eraseCredentials() deprecation attribute
public function eraseCredentials(): void { } // Missing #[\Deprecated]

// ❌ Using Request::get() (ambiguous, deprecated in Symfony 7.4)
$value = $request->get('param'); // Query? POST? Route param?
```

### ✅ DO

#### Frontend Best Practices
```typescript
// ✅ Use Dialog composable (styled, promise-based)
const { confirm, alert, prompt } = useDialog()
const confirmed = await confirm({
  title: t('deleteFile.title'),
  message: t('deleteFile.message'),
  danger: true
})

// ✅ Use Notification system (toast messages)
const { success, error, warning } = useNotification()
success(t('file.uploadSuccess'))
error(t('file.uploadFailed'))

// ✅ Generated Zod schema with validation
import { GetRuntimeConfigResponseSchema } from '@/generated/api-schemas'
type RuntimeConfig = z.infer<typeof GetRuntimeConfigResponseSchema>

const config = await httpClient('/api/v1/config/runtime', {
  schema: GetRuntimeConfigResponseSchema
})

// ✅ Proper async/await
await config.init()
app.mount('#app')

// ✅ i18n for all text
<h1>{{ $t('welcome.title') }}</h1>
const message = t('file.deleteSuccess')

// ✅ Tailwind utilities only
<div class="flex items-center gap-4 p-6 bg-white dark:bg-gray-800">
<button class="btn-primary">{{ $t('actions.save') }}</button>

// ✅ Composition API (modern Vue)
const count = ref(0)
const increment = () => count.value++

// ✅ Vue reactivity (not direct DOM)
const isVisible = ref(true)
<div v-if="isVisible">Content</div>

// ✅ Async with proper error handling
try {
  const data = await fetchData()
  success(t('data.loadSuccess'))
} catch (err) {
  error(t('data.loadFailed'))
  console.error('Failed to load data:', err)
}

// ✅ Computed properties for derived state
const fullName = computed(() => `${firstName.value} ${lastName.value}`)
const isValid = computed(() => email.value.includes('@'))

// ✅ Emit events (don't mutate props)
const props = defineProps<{ user: User }>()
const emit = defineEmits<{ update: [user: User] }>()
const updateName = (name: string) => {
  emit('update', { ...props.user, name })
}

// ✅ Separate v-if and v-for (use computed)
const activeItems = computed(() => items.value.filter(i => i.active))
<div v-for="item in activeItems" :key="item.id">

// ✅ ALWAYS use :key in v-for
<div v-for="item in items" :key="item.id">{{ item.name }}</div>

// ✅ Prefer ref() over reactive()
const count = ref(0)        // ✅ Simple, flexible
const user = ref<User>({})  // ✅ Works with any type

// ✅ Clean up in onUnmounted
const handler = () => { /* ... */ }
window.addEventListener('resize', handler)
onUnmounted(() => {
  window.removeEventListener('resize', handler)
})

// ✅ Lazy load heavy components only
const RichTextEditor = defineAsyncComponent({
  loader: () => import('@/components/RichTextEditor.vue'),
  loadingComponent: LoadingSpinner
})
```

#### Backend Best Practices
```php
// ✅ Database-driven models
$model = $this->modelRepository->find($modelId);
if ($model && $model->getActive()) { ... }

// ✅ Validate input with proper error messages
$data = json_decode($request->getContent(), true);
if (!isset($data['email']) || !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
    return $this->json(['error' => 'Invalid email address'], Response::HTTP_BAD_REQUEST);
}

// ✅ Business logic in Services (not Controllers)
class UserService {
    public function createUser(string $email, string $password): User {
        $user = new User();
        $user->setEmail($email);
        $user->setPassword($this->passwordHasher->hash($password));
        $this->em->persist($user);
        $this->em->flush();
        return $user;
    }
}

// ✅ Specific error messages
throw new InvalidArgumentException("Email '{$email}' is already registered");
return $this->json(['error' => 'Model not found', 'modelId' => $modelId], Response::HTTP_NOT_FOUND);

// ✅ Type hints everywhere
public function processData(array $data): ProcessedData { ... }
public function getData(): ?User { ... }

// ✅ No comments explaining missing code (Git history shows that)
// Just delete old code, commit message explains what changed

// ✅ Named constants
private const MIN_SCORE_THRESHOLD = 0.5;
private const CACHE_TTL_SECONDS = 300;

if ($score > self::MIN_SCORE_THRESHOLD) { ... }

// ✅ Use Repositories for queries
$users = $this->userRepository->findBy(['active' => true], ['createdAt' => 'DESC']);
$user = $this->userRepository->findOneBy(['email' => $email]);

// ✅ Modern Doctrine DBAL (DBAL 3.4+, ready for DBAL 4.0)
$stmt = $conn->prepare('SELECT * FROM users WHERE email = :email');
$stmt->bindValue('email', $email); // Bind first
$result = $stmt->executeQuery();  // Then execute (no params!)

// ✅ With type hints for safety
$stmt->bindValue('id', $userId, \PDO::PARAM_INT);
$stmt->bindValue('active', true, \PDO::PARAM_BOOL);

// ✅ Array parameters (IN clause)
use Doctrine\DBAL\ArrayParameterType;
$stmt->bindValue('ids', $userIds, ArrayParameterType::INTEGER);

// ✅ Deprecated method with attribute (Symfony 7.3+)
#[\Deprecated(message: 'Empty method, logic moved to __serialize()', since: 'symfony/security-http 7.3')]
public function eraseCredentials(): void { }

// ✅ Explicit request methods (Symfony 7.4+)
$queryParam = $request->query->get('search');      // ?search=term
$postData = $request->request->get('name');        // POST body
$routeParam = $request->attributes->get('id');     // Route {id}
```

---

## Project-Specific Patterns

### Frontend: User Feedback

```typescript
// ✅ Success notification (auto-dismiss)
const { success } = useNotification()
await saveSettings()
success(t('settings.saved'))

// ✅ Error notification (stays longer)
const { error } = useNotification()
try {
  await deleteFile(id)
} catch (err) {
  error(t('file.deleteFailed'), 8000) // 8 seconds
}

// ✅ Confirmation dialog (dangerous action)
const { confirm } = useDialog()
const confirmed = await confirm({
  title: t('deleteAccount.title'),
  message: t('deleteAccount.warning'),
  confirmText: t('actions.delete'),
  danger: true // Red button
})
if (!confirmed) return

// ✅ Prompt dialog (get user input)
const { prompt } = useDialog()
const newName = await prompt({
  title: t('chat.rename'),
  message: t('chat.enterNewName'),
  placeholder: t('chat.namePlaceholder'),
  defaultValue: currentName
})
if (!newName) return
```

### Backend: Response Patterns

```php
// ✅ Success response (consistent structure)
return $this->json([
    'success' => true,
    'data' => $result,
    'message' => 'Operation completed successfully'
]);

// ✅ Error response (with context)
return $this->json([
    'error' => 'Invalid model configuration',
    'details' => 'Model must have a valid provider',
    'modelId' => $modelId
], Response::HTTP_BAD_REQUEST);

// ✅ List response (with metadata)
return $this->json([
    'items' => $items,
    'total' => count($items),
    'page' => $page,
    'perPage' => $perPage
]);

// ✅ Validation error (specific fields)
return $this->json([
    'error' => 'Validation failed',
    'fields' => [
        'email' => 'Invalid email format',
        'password' => 'Must be at least 8 characters'
    ]
], Response::HTTP_UNPROCESSABLE_ENTITY);
```

### Services Layer Pattern

```php
// ✅ Service handles business logic, Controller calls Service
// Controller (thin - just HTTP handling)
#[Route('/api/v1/widgets', methods: ['POST'])]
public function createWidget(Request $request, #[CurrentUser] User $user): JsonResponse
{
    $data = json_decode($request->getContent(), true);

    if (!isset($data['name'])) {
        return $this->json(['error' => 'Widget name required'], Response::HTTP_BAD_REQUEST);
    }

    try {
        $widget = $this->widgetService->createWidget($user, $data['name'], $data['config'] ?? []);
        return $this->json(['widget' => $widget], Response::HTTP_CREATED);
    } catch (\InvalidArgumentException $e) {
        return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
    }
}

// Service (fat - business logic)
final readonly class WidgetService
{
    public function __construct(
        private EntityManagerInterface $em,
        private WidgetRepository $widgetRepository,
    ) {}

    public function createWidget(User $user, string $name, array $config): Widget
    {
        // Validation
        if (strlen($name) < 3) {
            throw new \InvalidArgumentException('Widget name must be at least 3 characters');
        }

        // Business logic
        $widget = new Widget();
        $widget->setOwner($user);
        $widget->setName($name);
        $widget->setConfig($config);
        $widget->setWidgetId($this->generateWidgetId());

        // Persistence
        $this->em->persist($widget);
        $this->em->flush();

        return $widget;
    }
}
```

---

## Testing

### Quick Commands (from Project Root)
```bash
# Run ALL quality checks + tests
make lint        # Lint backend + frontend
make test        # Test backend + frontend
make build       # Build frontend + widget

# Backend only
make -C backend lint format test
make -C backend phpstan  # Static analysis

# Frontend only
make -C frontend lint test build
make -C frontend generate-schemas  # Regenerate API schemas
```

### Frontend (Vitest)
- Mock `/api/v1/config/runtime` in `tests/setup.ts`
- Mock fetch globally: `global.fetch = vi.fn()`
- Test cookie-based auth (NO localStorage tokens)
- Run: `make -C frontend test`

### Backend (PHPUnit)
- Test with fixtures: `php bin/console doctrine:fixtures:load`
- Use test database
- Run: `make -C backend test`

---

## Git Workflow

### Commit Messages
- Conventional Commits: `feat:`, `fix:`, `refactor:`, `docs:`, `chore:`
- Example: `feat(frontend): add runtime config API support`
- **NEVER** add attribution: ❌ `Generated with Claude`, ❌ `Co-Authored-By: AI`

### Commands (Output Only)
```bash
# AI outputs these commands, user executes manually
git add .
git commit -m "feat: add feature"
git push origin branch-name
```

---

## Code Quality Checklist

Before committing:
- [ ] Run `make lint` (backend + frontend)
- [ ] Run `make test` (all tests pass)
- [ ] No trailing whitespace (`.editorconfig` enforces this)
- [ ] OpenAPI annotations complete (if backend changes)
- [ ] Translations added (if new text)
- [ ] Zod schemas regenerated (if API changes): `make -C frontend generate-schemas`
- [ ] No `console.log` (use proper logging)
- [ ] No `any` types (use proper TypeScript types)

---

## Quick Reference

### Start Development
```bash
docker compose up -d
# Frontend: http://localhost:5173
# Backend: http://localhost:8000
# API Docs: http://localhost:8000/api/doc
```

### Common Commands
```bash
# Backend
make -C backend lint format test
make -C backend console -- list
make -C backend migrate

# Frontend
make -C frontend lint test build
make -C frontend generate-schemas

# Both
make lint test build
```

### File Locations
- Frontend styles: `frontend/src/style.css` (Tailwind)
- Translations: `frontend/src/i18n/{de,en}.json`
- API schemas: `frontend/src/generated/api-schemas.ts` (auto-generated)
- Backend controllers: `backend/src/Controller/`
- Backend entities: `backend/src/Entity/`

---

## Summary

**Remember:**
1. 🇩🇪 Chat in German, code in English
2. 🐋 Use Docker for everything (`docker compose exec`)
3. 🎨 Tailwind for styling, i18n for text, no native dialogs
4. ✅ Zod schemas from OpenAPI (never manual interfaces)
5. 🗄️ Database-driven (no hardcoded models/values)
6. 🚫 NO git commands (output only)
7. 📝 Conventional commits, no attribution
8. 🧪 Run tests before committing
9. 🔔 Use `useDialog()` and `useNotification()` (never `alert()`/`confirm()`)
10. 🏗️ Services for logic, Controllers for HTTP (thin controllers, fat services)
11. 💬 All user-facing text via i18n (`{{ $t('key') }}`)
12. ⚡ Modern JS: top-level await, composition API, computed properties
13. 🔄 Modern Doctrine: `bindValue()` then `executeQuery()` (no params!)
14. 🏷️ Symfony 7.3+: Add `#[\Deprecated]` to empty `eraseCredentials()`
15. 📍 Explicit request access: `query`, `request`, `attributes` (not `get()`)

## When in Doubt

- **Frontend**: Check `src/composables/` for existing utilities
- **Backend**: Check `src/Service/` for business logic patterns
- **Styling**: Search `style.css` for existing Tailwind patterns
- **Dialogs**: Always use `useDialog()`, never `window.confirm()`
- **Notifications**: Always use `useNotification()`, never `window.alert()`
- **API**: Check OpenAPI docs at `http://localhost:8000/api/doc`
- **Types**: Generate from OpenAPI with `make -C frontend generate-schemas`

## Red Flags to Avoid

🚨 If you find yourself doing any of these, STOP and rethink:
- Using `alert()`, `confirm()`, or `prompt()`
- Writing a TypeScript interface for an API response
- Putting business logic in a Controller
- Hardcoding strings instead of using `$t()`
- Writing custom CSS instead of using Tailwind
- Using `setTimeout()` to "fix" race conditions
- Committing with attribution ("Generated by AI")
- Executing git commands directly
- Using `console.log()` without `try/catch` error handling
- Creating services without dependency injection
- Not writing OpenAPI annotations for new endpoints
- **Controller method > 50 lines** (extract to Service)
- **Component > 300 lines** (split into smaller components)
- **Repeated code 3+ times** (extract to composable/service)
- **Using curl/Postman** instead of Swagger UI (`/api/doc`)
- **Creating one massive file** instead of proper separation
- **Direct EntityManager in Controller** (use Service layer)
- **Custom validation in Controller** (create Validator class)
- **Passing parameters to `executeQuery()`** (use `bindValue()` first)
- **Missing `#[\Deprecated]`** on empty `eraseCredentials()`
- **Using `Request::get()`** (use explicit `query`, `request`, `attributes`)

