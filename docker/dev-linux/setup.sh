#!/usr/bin/env bash
# Prepare the Linux working copy (P35-T14). Idempotent: safe to re-run.
#
#   docker compose exec app bash /bootstrap/setup.sh
#
# WHY IT CLONES INSTEAD OF USING THE MOUNT
# ----------------------------------------
# /host-repo is the Windows checkout, mounted read-only. Running the suite over
# that mount means every file read crosses the VM boundary, on a suite of 2,300+
# tests. Cloning into the /workspace volume keeps the working copy on the Linux
# filesystem, and leaves the Windows checkout untouched — no shared vendor/, no
# shared .env, no chance of one side's composer install fighting the other's.
#
# The clone's origin is the local path, deliberately: no credentials are needed
# and nothing here can push. Pushing stays on Windows, where the pre-push gate
# and its hooks live.
set -euo pipefail

WORKSPACE=/workspace
SOURCE=/host-repo

# The clone is owned by root here while /host-repo carries Windows ownership;
# git refuses to read a repository it considers someone else's without this.
git config --global --add safe.directory '*'

# A linked worktree carries a .git FILE pointing at a Windows path, which does
# not resolve here; git would fail with "not a git repository" a few steps later
# and blame the wrong thing.
if [ -f "${SOURCE}/.git" ]; then
    echo "ERROR: ${SOURCE} is a linked git worktree, which cannot be cloned inside this container." >&2
    echo '       Start compose with HOST_REPO set to the main checkout, for example:' >&2
    echo '         PowerShell:  $env:HOST_REPO = ''C:\Users\User\Desktop\Training-center''' >&2
    echo '         then:        docker compose up -d --build' >&2
    exit 1
fi

if [ ! -d "${WORKSPACE}/.git" ]; then
    echo '==> Cloning the working copy into the Linux volume'
    git clone --no-hardlinks "${SOURCE}" "${WORKSPACE}"
else
    echo '==> Working copy already present; fetching from the Windows checkout'
    git -C "${WORKSPACE}" fetch origin
fi

cd "${WORKSPACE}"

if [ ! -f .env ]; then
    echo '==> Creating .env from .env.example'
    cp .env.example .env
fi

# The compose file already exports DB_*, REDIS_* and the mail addresses as real
# environment variables, which win over .env. These lines only keep a human
# reading .env from being misled about what this container talks to — appended
# once, so re-running this script does not grow the file.
if ! grep -q 'P35-T14 Linux target' .env; then
    echo '==> Recording the container settings in .env'
    {
        echo
        echo '# P35-T14 Linux target - the real values come from compose.yaml.'
        echo 'DB_HOST=mysql'
        echo 'DB_DATABASE=training_center_linux'
        echo 'DB_USERNAME=root'
        echo 'DB_PASSWORD=secret'
        echo 'REDIS_HOST=redis'
        echo 'REDIS_CLIENT=phpredis'
        echo 'QUEUE_CONNECTION=redis'
    } >> .env
fi

echo '==> Installing PHP dependencies'
composer install --no-interaction

echo '==> Generating an application key for this container'
php artisan key:generate --force

echo '==> Proving the four capabilities this target exists for'
php -m | grep -E '^(pcntl|posix|redis)$'
php -r '$redis = new Redis(); $redis->connect(getenv("REDIS_HOST"), 6379); echo "redis: ", $redis->ping(), PHP_EOL;'
php artisan db:show --json | head -c 400
echo

cat <<'NEXT'

==> Ready. The evidence T14 asks for is the gate itself:

      docker compose exec app composer verify

    Paste what actually happens — including anything that fails first — into
    docs/LINUX-DEV-TARGET.md. A runbook assembled from upstream documentation
    rather than from a real run is the defect this task exists to avoid.
NEXT
