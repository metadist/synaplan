#!/bin/sh
set -e

echo "🚀 Starting Synaplan Frontend..."

# Install dependencies if node_modules doesn't exist
if [ ! -d "node_modules" ]; then
  echo "📦 Installing dependencies..."
  npm ci
fi

# Initialize environment files
/usr/local/bin/init-env.sh

echo "🚀 Starting development server..."
exec npm run dev -- --host 0.0.0.0

