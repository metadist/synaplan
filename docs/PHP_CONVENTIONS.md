# PHP Conventions

## Standards

- PSR-12 compliance enforced by php-cs-fixer
- Symfony coding conventions
- 4-space indentation
- Type hints required (`declare(strict_types=1)`)
- Readonly properties when possible
- Final classes by default
- Import statements (`use`) sorted alphabetically
- No spaces around concatenation: `$a.$b` not `$a . $b`
- PHPStan level 5 compliance

## Fat Service / Thin Controller Pattern

**Controllers**: HTTP handling ONLY. Keep under 50 lines. Use attributes for validation/security.

**Services**: All business logic. Use dependency injection. Split if exceeding 500 lines.

**Repositories**: All database queries. No DQL in Controllers.

## Example

```php
<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Entity\Widget;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final readonly class WidgetService
{
    public function __construct(
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    public function createWidget(User $owner, string $name): Widget
    {
        if (strlen($name) < 3) {
            throw new \InvalidArgumentException('Name too short');
        }

        $widget = new Widget();
        $widget->setOwner($owner);
        $widget->setName($name);

        $this->em->persist($widget);
        $this->em->flush();

        return $widget;
    }
}
```

## Controller Example

```php
#[Route('/api/v1/widgets', methods: ['POST'])]
public function create(Request $request, #[CurrentUser] User $user): JsonResponse
{
    $data = $request->toArray();
    try {
        $widget = $this->widgetService->createWidget($user, $data['name']);
        return $this->json(['widget' => $widget], Response::HTTP_CREATED);
    } catch (\InvalidArgumentException $e) {
        return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
    }
}
```

## Error Handling

```php
try {
    $result = $this->service->doSomething();
    return $this->json(['success' => true, 'data' => $result]);
} catch (\InvalidArgumentException $e) {
    return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
} catch (\Exception $e) {
    $this->logger->error('Operation failed', ['error' => $e->getMessage()]);
    return $this->json(['error' => 'Internal error'], Response::HTTP_INTERNAL_SERVER_ERROR);
}
```

## Commands

```bash
make -C backend lint      # Check PSR-12
make -C backend format    # Fix formatting
make -C backend phpstan   # Static analysis
make -C backend test      # Run tests
make -C backend migrate   # Run migrations
make -C backend shell     # Open bash shell
```
