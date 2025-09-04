# Synaplan Testing

All testing-related files and configurations are centrally organized here.

## 📁 Structure

```
testing/
├── config/
│   ├── phpunit.xml               # PHPUnit configuration
│   └── docker-compose.test.yml   # Docker test environment
├── tests/
│   ├── Unit/                     # Unit tests
│   ├── Integration/              # Integration tests
│   ├── Helpers/                  # Test helpers
│   └── bootstrap.php             # Test bootstrap
├── reports/                      # All generated reports
│   ├── testdox.html             # PHPUnit TestDox (human-readable)
│   ├── junit.xml                # JUnit XML (machine-readable)
│   └── coverage/                # Coverage reports (if Xdebug available)
├── env.test                     # Test environment variables
├── Makefile                     # Test commands
└── README.md                    # This file
```

## 🚀 Quick Start

### Method 1: With Docker Database (Recommended)
Perfect for your setup where you only have Docker databases running:

```bash
cd testing/
make test                    # Run tests with Docker DB
make test-reports           # Generate HTML reports
```

**What happens:**
- Starts your existing Docker database (`docker compose up -d db`)
- Runs PHPUnit locally with connection to Docker DB
- Generates HTML reports in `testing/reports/`

### Method 2: Full Docker Environment
Everything runs in Docker containers:

```bash
cd testing/
make test-docker            # Everything in Docker containers
```

**What happens:**
- Creates isolated test database container
- Runs PHPUnit in separate container
- All dependencies isolated from your local system

### Method 3: Direct PHPUnit Commands
For advanced users who want full control:

```bash
cd testing/

# With Docker DB running:
docker compose -f ../docker-compose.yml up -d db
../vendor/bin/phpunit --configuration config/phpunit.xml

# Generate reports:
../vendor/bin/phpunit --configuration config/phpunit.xml --testdox-html reports/testdox.html --log-junit reports/junit.xml
```

## 📊 Generated Reports

After running `make test-reports`:
- **TestDox Report:** `testing/reports/testdox.html` (open in browser)
- **JUnit XML:** `testing/reports/junit.xml` (for CI/CD systems)
- **Coverage Report:** `testing/reports/coverage/` (if Xdebug available)

## 🔧 Configuration

### Database Options:

**Option A: Your Docker Database (Default)**
- Uses your existing `docker-compose.yml` database
- Database: `synaplan` on `localhost:3306`
- User: `synaplan_user`

**Option B: Isolated Test Database**
- Creates separate test database container
- Database: `synaplan_test` on `localhost:3307`  
- User: `synaplan_test_user`

### Environment Variables:
Copy `env.test` to `.env.test` if you need custom configuration:
```bash
cp env.test .env.test
```

### PHPUnit Configuration:
- **Config:** `testing/config/phpunit.xml`
- **Bootstrap:** `testing/tests/bootstrap.php`
- **Test Suites:** Unit, Integration, Feature

## 🧹 Cleanup

```bash
cd testing/
make clean                  # Remove all reports and test containers
```

## 🔍 Troubleshooting

### Database Connection Issues:
```bash
# Check if Docker DB is running:
docker compose -f ../docker-compose.yml ps

# Check database connection:
docker compose -f ../docker-compose.yml exec db mariadb -u synaplan_user -psynaplan_password synaplan -e "SELECT 'Connection OK';"
```

### Missing Dependencies:
```bash
# Install PHPUnit and dependencies:
cd ..
composer install
```

### Permission Issues:
```bash
# Fix permissions:
chmod -R 755 testing/
```
