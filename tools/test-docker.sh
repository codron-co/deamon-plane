#!/usr/bin/env bash
# Run the PHPUnit suite on the production PHP (8.2.35, Linux, same extensions)
# in Docker.
#
# Why: the WAMP PHP 8.2.26 ZTS build on the Windows dev PC segfaults whenever an
# HTTP fake answers with a connection failure (Http::failedConnection, a thrown
# ConnectionException): ComailWebmailHostTest and every later test in the same
# run die with no output. The same tests pass on the production PHP. GitHub
# Actions is off (billing), so this is the local gate on Windows.
#
# Usage (Git Bash or Linux):
#   tools/test-docker.sh                      # whole suite (~20 min on Windows)
#   tools/test-docker.sh tests/Feature/Comail # a folder, a file, or --filter ...
set -euo pipefail

here="$(cd "$(dirname "$0")" && pwd)"
# pwd -W gives a C:/ path Docker Desktop can mount from Git Bash; plain pwd elsewhere.
root="$(cd "$here/.." && { pwd -W 2>/dev/null || pwd; })"
image="${PLANE_TEST_PHP_IMAGE:-deamon-plane-test-php:8.2.35}"

if [ ! -f "$root/vendor/autoload.php" ]; then
  echo "vendor/ missing: run composer install first" >&2
  exit 1
fi

if ! docker image inspect "$image" >/dev/null 2>&1; then
  docker build -q -t "$image" -f "$here/test-docker.Dockerfile" "$here" >/dev/null
fi

MSYS_NO_PATHCONV=1 exec docker run --rm \
  -v "$root:/app" -w /app \
  "$image" \
  php -d memory_limit=2G vendor/bin/phpunit --colors=always "$@"
