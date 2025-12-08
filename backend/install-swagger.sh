#!/bin/bash

# Swagger/OpenAPI Installation Script
# This script installs NelmioApiDocBundle for API documentation

echo "================================"
echo "Swagger/OpenAPI Installation"
echo "================================"
echo ""

# Check if we're in the backend directory
if [ ! -f "composer.json" ]; then
    echo "Error: composer.json not found. Please run this script from the backend directory."
    exit 1
fi

echo "Step 1: Installing NelmioApiDocBundle..."
echo ""

# Install the bundle
composer require nelmio/api-doc-bundle --ignore-platform-req=ext-redis --no-scripts

if [ $? -ne 0 ]; then
    echo ""
    echo "⚠️  Installation failed due to permissions or other issues."
    echo ""
    echo "Alternative installation methods:"
    echo ""
    echo "1. Using Docker:"
    echo "   docker-compose exec backend composer require nelmio/api-doc-bundle"
    echo ""
    echo "2. Fix vendor permissions:"
    echo "   sudo chown -R \$USER:\$USER vendor/"
    echo "   composer require nelmio/api-doc-bundle --ignore-platform-req=ext-redis"
    echo ""
    exit 1
fi

echo ""
echo "Step 2: Clearing Symfony cache..."
echo ""

# Clear cache
php bin/console cache:clear

if [ $? -ne 0 ]; then
    echo "⚠️  Cache clear failed. You may need to run this manually:"
    echo "   php bin/console cache:clear"
fi

echo ""
echo "✅ Installation complete!"
echo ""
echo "Swagger UI is now available at:"
echo "  - http://localhost:8000/api/doc (Swagger UI)"
echo "  - http://localhost:8000/api/doc.json (OpenAPI JSON)"
echo ""
echo "For more information, see: backend/docs/SWAGGER.md"
echo ""

