#!/bin/bash
set -euo pipefail

# PHPUnit runs as root (`docker compose exec`), and its DG\BypassFinals stream
# wrapper makes PHP judge is_writable() by mode bits, where root gets no bypass.
# The main entrypoint chowns var/ to www-data:775 after this block, so a test
# cache left by an earlier run fails Symfony's "Unable to write in the cache
# directory" check the next time the test container is rebuilt. Without the
# directory, PHPUnit recreates it owned by root.
rm -rf /var/www/backend/var/cache/test
