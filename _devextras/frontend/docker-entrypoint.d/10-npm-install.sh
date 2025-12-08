#!/bin/bash
set -euo pipefail

echo "🔧 [dev] Installing npm dependencies..."
npm ci
echo "✅ [dev] npm dependencies installed"
