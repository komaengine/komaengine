#!/usr/bin/env bash

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
TEMP_ROOT="$(mktemp -d "${TMPDIR:-/tmp}/koma-log-bounds.XXXXXX")"
RUN_ID="$(openssl rand -hex 6)"
CONTAINER="koma-log-bounds-${RUN_ID}"
. "$ROOT/scripts/release/image-lock.env"

cleanup() {
    docker rm -f "$CONTAINER" >/dev/null 2>&1 || true
    rm -rf "$TEMP_ROOT"
}
trap cleanup EXIT

# Placeholder values only. This check renders configuration; it never reads
# the maintainer environment file and never creates a project, volume, or
# network.
printf '%s\n' \
    'KOMA_APP_KEY=base64:log-bounds-placeholder' \
    'KOMA_APP_URL=http://localhost' \
    'KOMA_DB_PASSWORD=log-bounds-placeholder' \
    'KOMA_DB_ROOT_PASSWORD=log-bounds-placeholder' \
    > "$TEMP_ROOT/compose.env"

docker compose \
    --project-name "$CONTAINER" \
    --env-file "$TEMP_ROOT/compose.env" \
    --file "$ROOT/docker-compose.yml" \
    --profile tools \
    config --format json > "$TEMP_ROOT/config.json"

# Every service, including optional tools, must select Docker's rotating
# driver with explicit finite limits.
python3 - "$TEMP_ROOT/config.json" > "$TEMP_ROOT/limits" <<'PY'
import json
import re
import sys

UNITS = {'': 1, 'k': 1024, 'm': 1024 ** 2, 'g': 1024 ** 3}
# A single service may retain at most this much container output.
CEILING = 256 * 1024 ** 2

services = json.load(open(sys.argv[1]))['services']
expected = {'adminer', 'app', 'db', 'migrate', 'scheduler', 'worker'}

if set(services) != expected:
    sys.exit(f'Unexpected Compose services: {sorted(services)}')

for name, service in sorted(services.items()):
    logging = service.get('logging') or {}
    options = logging.get('options') or {}

    if logging.get('driver') != 'local':
        sys.exit(f'{name} does not use the rotating local log driver.')

    size = re.fullmatch(r'([1-9][0-9]*)([kmg]?)', str(options.get('max-size', '')))
    files = re.fullmatch(r'[1-9][0-9]*', str(options.get('max-file', '')))

    if size is None or files is None:
        sys.exit(f'{name} has no explicit finite log size and file count.')

    budget = int(size.group(1)) * UNITS[size.group(2)] * int(files.group(0))

    if budget > CEILING:
        sys.exit(f'{name} may retain {budget} bytes of container output.')

    print(f'{name} {options["max-size"]} x {options["max-file"]} = {budget} bytes', file=sys.stderr)

print('ok')
PY

[[ "$(cat "$TEMP_ROOT/limits")" == 'ok' ]]

# Runtime proof that this Docker host honors the driver: write far more than
# a small budget and confirm only a bounded tail, including the newest line,
# is retained.
PROBE_SIZE=100k
PROBE_FILES=2
PROBE_BUDGET=$((100 * 1024 * PROBE_FILES))
PROBE_LINES=4000

docker run \
    --name "$CONTAINER" \
    --log-driver local \
    --log-opt "max-size=$PROBE_SIZE" \
    --log-opt "max-file=$PROBE_FILES" \
    "$KOMA_PHP_84_CLI_IMAGE" \
    php -r 'for ($i = 0; $i < (int) $argv[1]; $i++) { echo str_pad((string) $i, 999, "x"), "\n"; }' "$PROBE_LINES" \
    > /dev/null

docker logs "$CONTAINER" > "$TEMP_ROOT/retained.log" 2>/dev/null
retained="$(wc -c < "$TEMP_ROOT/retained.log" | tr -d '[:space:]')"
written=$((PROBE_LINES * 1000))

[[ "$retained" -gt 0 && "$retained" -le "$PROBE_BUDGET" ]] || {
    echo "Docker retained $retained of $written bytes; expected at most $PROBE_BUDGET." >&2
    exit 1
}

tail -n 1 "$TEMP_ROOT/retained.log" | grep -q "^$((PROBE_LINES - 1))x" || {
    echo 'Docker log rotation discarded the newest output.' >&2
    exit 1
}

printf 'Compose log bounds passed; runtime probe retained %s of %s bytes.\n' "$retained" "$written"
