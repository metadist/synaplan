#!/bin/sh
set -e

echo "🚀 Starting Synaplan Frontend..."

# Always run npm ci - it's quick and ensures dependencies are always correct
echo "📦 Installing dependencies..."
npm ci

# Initialize environment files
/usr/local/bin/init-env.sh

echo "🚀 Starting development server..."
exec npm run dev -- --host 0.0.0.0

