> **[Main README](../README.md)** · **[Documentation Index](README.md)** · **Track 5: Developer & Contributor Guide (4/11)**  
> **← Previous:** [5.3 Adding an AI Provider](PROVIDERS.md) · **Next:** [5.5 Frontend Vue & TypeScript Conventions →](FRONTEND_CONVENTIONS.md)

# Backend PHP Conventions

> **In this guide:** Coding standards, architectural layer boundaries, DBAL 4 and request-reading rules, error handling, and the `#[AdminPreview]` pattern for PHP 8.4 / Symfony 8.1 code in `backend/`.

---

## 1. Core Standards

- **PSR-12** formatting enforced by `php-cs-fixer` (`make -C backend format`).
- **`declare(strict_types=1)`** at the top of every PHP file, with explicit parameter and return types on every method.
- **`final` classes** by default (`final readonly` for stateless services and DTOs).
- **Named constants** instead of magic numbers or repeated strings; **specific exceptions** (`\InvalidArgumentException`, domain exceptions in `App\Exception\`) instead of generic `throw new \Exception(...)`.
- **Alphabetically sorted `use` statements** and no spaces around string concatenation (`$a.$b`, not `$a . $b`).
- **No comments about deleted code**—explain *why* a constraint exists today, not what used to be there.
- **PHPStan** (`make -C backend phpstan`) analyzes both `src/` and `tests/` and must pass with zero errors.

---

## 2. Layer Responsibilities & Ratchets

Three automated checks in `make ci-local` enforce the backend shape (see [ARCHITECTURE.md](ARCHITECTURE.md)):

| Layer | Responsibility | Hard Rules (Enforced by Tests) |
| ----- | -------------- | ------------------------------ |
| **Controller (`App\Controller\`)** | HTTP transport only: read request, validate input, call a service or repository, return `JsonResponse` or `StreamedResponse`. | Max **50 lines** per action (`ThinControllerTest`). **Never** inject `EntityManagerInterface` or `AiFacade` into a controller. Every action carries complete `@OA\*` OpenAPI attributes ([API_PATTERNS.md](API_PATTERNS.md)). |
| **Service (`App\Service\`)** | Business logic, orchestration, external API calls, transactions. | `final readonly`, constructor injection. Max **800 lines** per file, **10 parameters** per constructor, **100 lines** per method (`SizeRatchetTest`). |
| **Repository (`App\Repository\`)** | Every database query (DQL, QueryBuilder, raw DBAL SQL) and persistence helper. | Never import `App\AI\*` or application services (`deptrac.yaml`). Move shared types to `App\Model\` or `App\DTO\`. |
| **Entity (`App\Entity\`)** | Doctrine ORM state and domain invariants. | Never import services (`deptrac.yaml`). |

> **Ratchet ledgers only shrink.** `sizeRatchetBaseline.json`, `thinControllerBaseline.json`, and `deptrac.baseline.yaml` freeze legacy classes that predate the limits. Put new logic in a new focused class rather than growing a frozen file.

---

## 3. Request Reading & DBAL 4 Rules

### Reading `Symfony\Component\HttpFoundation\Request`

Always use the typed bag accessors (never removed Symfony 5/6 `$request->get()`):

```php
$page = max(1, (int) $request->query->get('page', 1));
$routeId = (int) $request->attributes->get('id');
$payload = $request->toArray(); // for JSON request bodies
```

### Doctrine DBAL 4 Queries

In DBAL 4, bind parameters explicitly with `bindValue()` and call `executeQuery()` or `executeStatement()` with no arguments:

```php
$stmt = $this->connection->prepare(
    'SELECT BID, BNAME FROM BWIDGETS WHERE BOWNERID = :ownerId AND BSTATUS = :status'
);
$stmt->bindValue('ownerId', $ownerId);
$stmt->bindValue('status', 'active');

$rows = $stmt->executeQuery()->fetchAllAssociative();
```

---

## 4. Complete Example: Repository → Service → Controller

### Repository (`backend/src/Repository/WidgetRepository.php`)

```php
<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Widget;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Widget>
 */
final class WidgetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Widget::class);
    }

    public function save(Widget $widget, bool $flush = true): void
    {
        $this->getEntityManager()->persist($widget);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
```

### Service (`backend/src/Service/Widget/WidgetCreationService.php`)

```php
<?php

declare(strict_types=1);

namespace App\Service\Widget;

use App\Entity\User;
use App\Entity\Widget;
use App\Repository\WidgetRepository;

final readonly class WidgetCreationService
{
    private const MIN_NAME_LENGTH = 3;

    public function __construct(
        private WidgetRepository $widgets,
    ) {
    }

    public function create(User $owner, string $name): Widget
    {
        $trimmed = trim($name);
        if (mb_strlen($trimmed) < self::MIN_NAME_LENGTH) {
            throw new \InvalidArgumentException('Widget name must be at least 3 characters.');
        }

        $widget = new Widget();
        $widget->setOwner($owner);
        $widget->setName($trimmed);

        $this->widgets->save($widget);

        return $widget;
    }
}
```

### Controller (`backend/src/Controller/WidgetController.php`)

```php
<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\Widget\WidgetCreationService;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

final class WidgetController extends AbstractController
{
    public function __construct(
        private readonly WidgetCreationService $widgetCreation,
    ) {
    }

    #[Route('/api/v1/widgets', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/widgets',
        summary: 'Create a chat widget',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', example: 'Support Chat'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'Created widget',
                content: new OA\JsonContent(
                    required: ['id', 'name'],
                    properties: [
                        new OA\Property(property: 'id', type: 'integer', example: 42),
                        new OA\Property(property: 'name', type: 'string', example: 'Support Chat'),
                    ]
                )
            ),
        ]
    )]
    public function create(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $data = $request->toArray();

        try {
            $widget = $this->widgetCreation->create($user, (string) ($data['name'] ?? ''));

            return $this->json([
                'id' => $widget->getId(),
                'name' => $widget->getName(),
            ], Response::HTTP_CREATED);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }
}
```

---

## 5. Admin Preview (`#[AdminPreview]`)

A feature that should be visible only to administrators while being tested in production is gated in code (no operator toggle):

1. Add a constant and list it in `App\Service\Feature\AdminPreview::FEATURES` (`backend/src/Service/Feature/AdminPreview.php`).
2. Add `#[AdminPreview(AdminPreviewFeature::MY_FEATURE)]` to the controller class or action (`App\Attribute\AdminPreview`). Non-admins receive `404 {"error":"not_found"}` (unauthenticated requests to protected routes still receive `401`). `ROLE_ADMIN` is checked, so OIDC-mapped admins are included.
3. In background worker or webhook handlers (where there is no HTTP session), call `AdminPreview::allowsUserId()` and drop the work rather than returning `404` to a webhook that expects `200`.
4. To release the feature to everyone, remove its ID from `FEATURES` and every attribute / `allows()` / `allowsUserId()` call in the same commit as the frontend `<AdminPreview>` removal ([FRONTEND_CONVENTIONS.md](FRONTEND_CONVENTIONS.md#admin-preview)). Any leftover call site throws immediately.

---

## 6. Essential Backend Commands

```bash
make -C backend lint       # Check PSR-12 formatting
make -C backend format     # Auto-fix PSR-12 formatting
make -C backend phpstan    # Static analysis (src/ + tests/)
make -C backend deptrac    # Enforce architectural layer boundaries
make -C backend metrics    # Report file/method/constructor size ratchets
make -C backend test       # Run full PHPUnit test suite
make -C backend migrate    # Run Doctrine migrations
make -C backend seed       # Run idempotent catalog seeders
make -C backend shell      # Open bash shell inside the backend container
```

---

> **[Main README](../README.md)** · **[Documentation Index](README.md)** · **Track 5: Developer & Contributor Guide (4/11)**  
> **← Previous:** [5.3 Adding an AI Provider](PROVIDERS.md) · **Next:** [5.5 Frontend Vue & TypeScript Conventions →](FRONTEND_CONVENTIONS.md)
