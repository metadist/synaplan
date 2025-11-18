# Testing Environment

## Quick Start

### 1. Start Test Infrastructure
```bash
# Start only basic test services (DB + Redis)
docker compose -f docker-compose.test.yml up -d

# OR: Start with integration services (Ollama, Tika)
docker compose -f docker-compose.test.yml --profile integration up -d
```

### 2. Setup Test Database
```bash
# Run migrations
docker compose -f docker-compose.test.yml exec app_test php bin/console doctrine:migrations:migrate --no-interaction --env=test

# Load fixtures (optional)
docker compose -f docker-compose.test.yml exec app_test php bin/console doctrine:fixtures:load --no-interaction --env=test
```

### 3. Run Tests
```bash
# PHPUnit
docker compose -f docker-compose.test.yml exec app_test php bin/phpunit

# Specific test
docker compose -f docker-compose.test.yml exec app_test php bin/phpunit tests/Unit/SomeTest.php
```

### 4. Stop Test Infrastructure
```bash
docker compose -f docker-compose.test.yml down

# Remove volumes (clean state)
docker compose -f docker-compose.test.yml down -v
```

## Test Environment Details

### Services
- **app_test**: Symfony app (Port 8001)
- **db_test**: MariaDB test database (Port 3308)
- **redis_test**: Redis cache (Port 6381)
- **ollama_test**: Ollama AI (Port 11436) - Only with `--profile integration`
- **tika_test**: Apache Tika (Port 9997) - Only with `--profile integration`

### Environment Variables
All test configuration is in `.env.test` (committed to git, no secrets).

### Test Database
- Database: `synaplan_test`
- User: `synaplan_test`
- Password: `synaplan_test`
- Port: `3308` (host) → `3306` (container)

### Parallel Execution
Test infrastructure runs on different ports, so you can run tests while the dev environment is running:
- Dev DB: `3307` → Test DB: `3308`
- Dev Redis: `6380` → Test Redis: `6381`
- Dev App: `8000` → Test App: `8001`

## CI/CD Integration

```yaml
# Example GitHub Actions
- name: Start test infrastructure
  run: docker compose -f docker-compose.test.yml up -d

- name: Run tests
  run: docker compose -f docker-compose.test.yml exec -T app_test php bin/phpunit
```

