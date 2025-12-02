#!/bin/sh
set -e

echo "🚀 Starting Synaplan Frontend..."

# Initialize environment files
/usr/local/bin/init-env.sh

echo "🚀 Starting development server..."
exec npm run dev -- --host 0.0.0.0

