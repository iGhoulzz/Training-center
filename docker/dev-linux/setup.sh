#!/usr/bin/env bash
# Prepare the Linux working copy (P35-T14). Safe to re-run.
#
#   docker compose exec app bash /bootstrap/setup.sh [ref]
#
# `ref` is the branch or commit to test, as it exists in the Windows checkout
# named by HOST_REPO. Without it, a first run clones whatever that checkout has
# checked out, and a later run only fetches — it does NOT move the working copy.
# To test anything else, name it:
#
#   docker compose exec app bash /bootstrap/setup.sh p35/t10b-horizon
#
# Only COMMITTED work can be tested: the working copy is a clone, so uncommitted
# edits on Windows are invisible to it.
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
REF="${1:-}"

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
fi

cd "${WORKSPACE}"

if [ -n "${REF}" ]; then
    echo "==> Checking out ${REF} from the Windows checkout"
    git fetch --quiet origin "${REF}"
    git checkout --quiet --detach FETCH_HEAD
else
    git fetch --quiet origin
fi

echo "==> Code under test: $(git log --oneline -1)"

if [ ! -f .env ]; then
    echo '==> Creating .env from .env.example'
    cp .env.example .env
fi

# Two kinds of line, appended once so re-running this script does not grow .env:
#
#   - DB_* and REDIS_* are also real environment variables from compose.yaml,
#     which win over .env. Written here only so a human reading .env is not
#     misled about what this container talks to.
#   - QUEUE_CONNECTION=redis lives ONLY here, on purpose. As a compose variable
#     it overrode phpunit.xml's `sync` and broke the suite; in .env it applies to
#     Horizon and the load tests while PHPUnit's <env> still outranks it for
#     tests. See the environment block in compose.yaml.
if ! grep -q 'P35-T14 Linux target' .env; then
    echo '==> Recording the container settings in .env'
    {
        echo
        echo '# P35-T14 Linux target - DB and Redis also come from compose.yaml; the queue only from here.'
        echo 'DB_HOST=mysql'
        echo 'DB_DATABASE=training_center_linux'
        echo 'DB_USERNAME=root'
        echo 'DB_PASSWORD=secret'
        echo 'REDIS_HOST=redis'
        echo 'REDIS_CLIENT=phpredis'
        echo 'QUEUE_CONNECTION=redis'
    } >> .env
fi

# Every run: a different ref can carry a different composer.lock (Horizon's does).
echo '==> Installing PHP dependencies'
composer install --no-interaction

# Once only. Regenerating on every run would rotate APP_KEY underneath anything
# already encrypted with the old one.
if ! grep -qE '^APP_KEY=.+' .env; then
    echo '==> Generating an application key for this container'
    php artisan key:generate --force
fi

# THE TEST DATABASE IS NOT FOR LOAD RUNS OR HORIZON.
# training_center_linux is rebuilt with migrate:fresh by every `composer verify`,
# and the suite lock covers test processes only — a load run or a seeded
# performance dataset sharing it would be wiped mid-run. Load work gets its own
# database on this same server; T05's allowlist already names it. Select it per
# command with `-e DB_DATABASE=training_center_performance` (see the runbook).
echo '==> Ensuring the separate database for load runs exists'
php -r '
    $pdo = new PDO("mysql:host=" . getenv("DB_HOST") . ";port=" . getenv("DB_PORT"), getenv("DB_USERNAME"), getenv("DB_PASSWORD"));
    $pdo->exec("CREATE DATABASE IF NOT EXISTS training_center_performance");
    echo "training_center_performance: present", PHP_EOL;
'

echo '==> Proving the capabilities this target exists for'
php -m | grep -E '^(pcntl|posix|redis)$'
php -r '$redis = new Redis(); $redis->connect(getenv("REDIS_HOST"), 6379); echo "redis: ", $redis->ping(), PHP_EOL;'
php artisan db:show --json | head -c 400
echo

cat <<'NEXT'

==> Ready. Run the gate inside the target with:

      docker compose exec -T app composer verify

    -T gives the command no terminal, which is how the recorded green run was
    made. Load runs and Horizon use their own database:

      docker compose exec -e DB_DATABASE=training_center_performance app php artisan ...
NEXT
