#!/usr/bin/env bash
set -euo pipefail
# Only disposable fixtures and PATH stubs are used: no application boot or network.
script_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)
# Git Bash cannot create real POSIX links without Windows symlink privileges.
# Run the exact harness on the cached Linux target, with no network or services.
if [[ "$(uname -s)" == MINGW* ]]; then
    export MSYS_NO_PATHCONV=1
    exec docker run --rm --network none --mount "type=bind,source=$(cygpath -w "$script_dir/.."),target=/contract,readonly" --entrypoint bash training-center-dev-linux:latest /contract/tests/release-smoke.sh "$@"
fi
original_script="$script_dir/../release.sh"
[[ -f "$original_script" ]] || { echo 'FAIL: release implementation missing' >&2; exit 1; }
sandbox=$(mktemp -d)
trap '[[ -d "$sandbox" && "$sandbox" != / ]] && rm -rf -- "$sandbox"' EXIT
if [[ "${1:-}" == --runtime-helpers ]]; then
    # Exercise the shipped invocations with inherited stdin owned by another UID.
    # The ordinary deployment harness below deliberately stubs PHP and sudo.
    as_root() { if (( EUID == 0 )); then "$@"; else sudo -n "$@"; fi; }
    trap '[[ -d "$sandbox" && "$sandbox" != / ]] && as_root rm -rf -- "$sandbox"' EXIT
    real_php=$(command -v php)
    runtime_php() { as_root runuser -u www-data -- "$real_php" "$@"; }
    chmod 0755 "$sandbox"
    root="$sandbox/host with spaces"
    mkdir -p "$root/shared/storage" "$root/releases/active"
    chmod 0755 "$root" "$root/shared" "$root/releases" "$root/releases/active"
    as_root chown www-data:www-data "$root/shared/storage"
    as_root chmod 0770 "$root/shared/storage"
    log="$sandbox/runtime.log"
    storage_check=$(awk '/^step=runtime-storage$/ { capture=1; next } /^step=php-fpm-extensions$/ { capture=0 } capture' "$original_script")
    process_check=$(awk '/# \/proc cwd access/ { capture=1; next } capture && /then ready=1/ { exit } capture' "$original_script" | sed '1s/^.* \&\& runtime_php/runtime_php/')
    [[ -n "$storage_check" && -n "$process_check" ]] || { echo 'FAIL: runtime helper extraction'; exit 1; }
    eval "$storage_check" || { cat "$log"; echo 'FAIL: runtime storage across UID switch'; exit 1; }
    for directory in app/secure app/public framework/cache/data framework/sessions framework/views logs; do
        as_root test -d "$root/shared/storage/$directory" || { echo 'FAIL: missing runtime storage directory'; exit 1; }
    done
    echo 'PASS: runtime storage across UID switch'
    as_root ln -s "$sandbox" "$root/shared/storage/escape"
    if (eval "$storage_check"); then echo 'FAIL: accepted storage symlink'; exit 1; fi
    as_root rm "$root/shared/storage/escape"
    as_root chmod 0550 "$root/shared/storage"
    if (eval "$storage_check"); then echo 'FAIL: accepted unwritable storage'; exit 1; fi
    as_root chmod 0770 "$root/shared/storage"
    grep -q 'Runtime storage is unsafe or unavailable' "$log"
    echo 'PASS: runtime storage rejects symlinks and unwritable directories'
    release="$root/releases/active"
    cd -- "$release"
    runtime_php -r 'echo getmypid(), PHP_EOL; fflush(STDOUT); sleep(30);' > "$sandbox/worker.pid" 2> "$sandbox/worker.stderr" &
    launcher=$!
    for ((attempt=0; attempt<100; attempt++)); do
        [[ -s "$sandbox/worker.pid" ]] && break
        kill -0 "$launcher" || { cat "$sandbox/worker.stderr"; echo 'FAIL: runtime worker exited'; exit 1; }
        sleep 0.01
    done
    pid=$(cat "$sandbox/worker.pid")
    [[ "$pid" =~ ^[1-9][0-9]*$ ]] || { echo 'FAIL: runtime worker PID'; exit 1; }
    trap 'as_root kill "$pid" 2>/dev/null || true; wait "$launcher" 2>/dev/null || true; as_root rm -rf -- "$sandbox"' EXIT
    eval "$process_check" || { cat "$log"; echo 'FAIL: runtime process cwd across UID switch'; exit 1; }
    release="$root/releases/wrong"
    if (eval "$process_check"); then echo 'FAIL: accepted wrong worker cwd'; exit 1; fi
    echo 'PASS: runtime process cwd across UID switch and mismatch refusal'
    exit 0
fi
export TEST_SANDBOX="$sandbox"
mkdir "$sandbox/bin"
export PATH="$sandbox/bin:$PATH"
export MSYS=winsymlinks:nativestrict
export MSYS_NO_PATHCONV=1
cat > "$sandbox/bin/php" <<'STUB'
#!/usr/bin/env bash
set -euo pipefail
[[ "${TEST_RUNTIME_USER:-}" == www-data ]] || exit 95
printf '%s|%s\n' "$PWD" "$*" >> "$TEST_SANDBOX/commands"
[[ "${1:-}" != --version ]] || { echo 'PHP fixture'; exit 0; }
if [[ "${1:-}" == artisan || "${1:-}" == "$TRAINING_CENTER_ROOT/current/artisan" ]]; then
    case "$2" in
        schedule:run) echo 'scheduler stdout'; echo 'scheduler stderr' >&2 ;;
        backup:run) [[ "${FAIL_STAGE:-}" != backup ]] || exit 1; touch "$TEST_SANDBOX/backup-ok" ;;
        migrate) [[ -f "$TEST_SANDBOX/backup-ok" || ! -L "$TRAINING_CENTER_ROOT/current" ]] || exit 91 ;;
        horizon:terminate|pulse:restart)
            service=horizon; [[ "$2" != pulse:restart ]] || service=pulse
            # Simulate a clean old-process exit; systemd's always policy relaunches.
            unit="$TEST_UNITS/training-center-$service.service"
            grep -q '^Restart=always$' "$unit" || exit 92
            grep -q '^RestartSec=5s$' "$unit" || exit 93
            readlink "$TRAINING_CENTER_ROOT/current" > "$TEST_SANDBOX/$service-active"
            ;;
    esac
    exit 0
fi
if [[ "${1:-}" == -r ]]; then
    source_code=$2
    shift 2
    [[ "${1:-}" == -- ]] || exit 94
    shift
    set -- inline "$@"
else
    source_code=$(cat)
fi
case "$source_code" in
    *TC_DEPLOY_PROCESS*) [[ "$(readlink "$TRAINING_CENTER_ROOT/current")" == "$3" ]] ;;
    *TC_DEPLOY_STORAGE*)
        [[ -z "$(find "$2" -type l -print -quit)" ]] || exit 1
        for path in app/secure app/public framework/cache/data framework/sessions framework/views logs; do mkdir -p "$2/$path"; done
        ;;
    *TC_DEPLOY_BACKUP*)
        [[ "${FAIL_STAGE:-}" != backup && "${FAIL_STAGE:-}" != backup-lock ]] || exit 1
        touch "$TEST_SANDBOX/backup-ok"
        ;;
    *TC_DEPLOY_PREFLIGHT*)
        case "${FAIL_STAGE:-}" in redis|eviction|backup-config|mysql|mail|extensions) exit 1 ;; esac
        printf 'center.example\n'
        ;;
    *TC_DEPLOY_POSTFLIGHT*)
        [[ "${FAIL_STAGE:-}" != pre-smoke ]] || exit 1
        if [[ "${FAIL_STAGE:-}" == post-dependency && "$(readlink "$TRAINING_CENTER_ROOT/current")" == "$TRAINING_CENTER_ROOT/releases/2222222222222222222222222222222222222222" ]]; then exit 1; fi
        ;;
    *) exit 94 ;;
esac
STUB
cat > "$sandbox/bin/php-fpm8.4" <<'STUB'
#!/usr/bin/env bash
[[ "${FAIL_STAGE:-}" != fpm-extensions ]] || exit 1
# What Ubuntu 24.04 + ondrej/php php-fpm8.4 -m lists: no pcntl, which is CLI-only.
for ext in redis posix pdo_mysql mbstring intl bcmath gd zip; do
    [[ "${FAIL_STAGE:-}" == fpm-intl && "$ext" == intl ]] || echo "$ext"
done
STUB
cat > "$sandbox/bin/systemctl" <<'STUB'
#!/usr/bin/env bash
set -euo pipefail
echo "$*" >> "$TEST_SANDBOX/services"
if [[ "$1" == is-active ]]; then
    [[ "${FAIL_STAGE:-}" != post-worker ]] || exit 1
    service=${*: -1}; service=${service#training-center-}; service=${service%.service}
    [[ -f "$TEST_SANDBOX/$service-active" ]]
    [[ "$(cat "$TEST_SANDBOX/$service-active")" == "$(readlink "$TRAINING_CENTER_ROOT/current")" ]]
fi
if [[ "$1" == show ]]; then echo 1234; fi
STUB
cat > "$sandbox/bin/curl" <<'STUB'
#!/usr/bin/env bash
echo "$*" >> "$TEST_SANDBOX/http"
[[ "${FAIL_STAGE:-}" != post-http ]]
STUB
cat > "$sandbox/bin/chgrp" <<'STUB'
#!/usr/bin/env bash
exit 0
STUB
cat > "$sandbox/bin/sudo" <<'STUB'
#!/usr/bin/env bash
[[ "$1" == -n ]] || exit 90
[[ "${FAIL_STAGE:-}" != runtime-privileges ]] || exit 1
shift
if [[ "$1" == -l ]]; then exit 0; fi
if [[ "$1" == -u ]]; then [[ "$2" == www-data ]] || exit 90; export TEST_RUNTIME_USER=www-data; shift 2; fi
exec "$@"
STUB
real_readlink=$(command -v readlink)
export REAL_READLINK="$real_readlink"
cat > "$sandbox/bin/readlink" <<'STUB'
#!/usr/bin/env bash
if [[ "${*: -1}" == /proc/1234/cwd ]]; then "$REAL_READLINK" -f "$TRAINING_CENTER_ROOT/current"; else "$REAL_READLINK" "$@"; fi
STUB
cat > "$sandbox/bin/id" <<'STUB'
#!/usr/bin/env bash
case "$1" in -u) echo 1001 ;; -un) echo training-center ;; *) exit 90 ;; esac
STUB
real_stat=$(command -v stat)
export REAL_STAT="$real_stat"
cat > "$sandbox/bin/stat" <<'STUB'
#!/usr/bin/env bash
if [[ "$1" == -c && "$2" == %u ]]; then echo 0; else "$REAL_STAT" "$@"; fi
STUB
cat > "$sandbox/bin/sleep" <<'STUB'
#!/usr/bin/env bash
exit 0
STUB
real_find=$(command -v find)
export REAL_FIND="$real_find"
cat > "$sandbox/bin/find" <<'STUB'
#!/usr/bin/env bash
if [[ "${FAIL_SCHEDULER_FIND:-}" == 1 ]]; then echo 'simulated find failure' >&2; exit 1; fi
exec "$REAL_FIND" "$@"
STUB
chmod +x "$sandbox/bin/"*
export TEST_UNITS="$script_dir/../systemd"
old=1111111111111111111111111111111111111111
new=2222222222222222222222222222222222222222
third=3333333333333333333333333333333333333333
fixture() {
    case_root="$sandbox/$1"
    export TRAINING_CENTER_ROOT="$case_root/training-center"
    release_script="$case_root/release.sh"
    mkdir -p "$case_root"
    # Production paths are fixed. Bind only the host root and PHP executable to
    # their disposable counterparts; all release logic stays identical.
    [[ "$(grep -c '^root=/srv/training-center$' "$original_script")" == 1 ]]
    [[ "$(grep -c '^php_binary=/usr/bin/php$' "$original_script")" == 1 ]]
    sed -e "s|^root=/srv/training-center$|root=$TRAINING_CENTER_ROOT|" -e "s|^php_binary=/usr/bin/php$|php_binary=$sandbox/bin/php|" "$original_script" > "$release_script"
    rm -f "$TEST_SANDBOX/backup-ok"
    mkdir -p "$TRAINING_CENTER_ROOT/shared/storage" "$TRAINING_CENTER_ROOT/shared/migration-approvals" "$TRAINING_CENTER_ROOT/releases/$old" "$case_root/artifact/bootstrap/cache" "$case_root/artifact/vendor" "$case_root/artifact/public/build" "$case_root/artifact/public/js/filament" "$case_root/artifact/public/css/filament" "$case_root/artifact/public/fonts/filament"
    printf 'fixture only\n' > "$TRAINING_CENTER_ROOT/shared/.env"
    printf '%s\n' "$new" > "$TRAINING_CENTER_ROOT/shared/migration-approvals/$new"
    printf '%s\n' "$old" > "$TRAINING_CENTER_ROOT/releases/$old/REVISION"
    ln -s "$TRAINING_CENTER_ROOT/releases/$old" "$TRAINING_CENTER_ROOT/current"
    for file in artisan vendor/autoload.php public/index.php public/build/manifest.json public/js/filament/a.js public/css/filament/a.css public/fonts/filament/a.woff2; do printf 'fixture\n' > "$case_root/artifact/$file"; done
    printf '%s\n' "$new" > "$case_root/artifact/REVISION"
}
pack() { tar -cf "$case_root/release.tar" -C "$case_root/artifact" .; }
unchanged() { [[ "$(readlink "$TRAINING_CENTER_ROOT/current")" == "$TRAINING_CENTER_ROOT/releases/$old" ]] || { echo "FAIL: current changed for $1"; exit 1; }; }
reject() {
    if bash "$release_script" "$1" "$case_root/release.tar" > "$case_root/output" 2>&1; then cat "$case_root/output"; echo "FAIL: accepted $2"; exit 1; fi
    unchanged "$2"
    echo "PASS: rejected $2"
}
fixture scheduler-retention
logs="$TRAINING_CENTER_ROOT/shared/storage/logs"
mkdir -p "$logs"
printf 'expired\n' > "$logs/scheduler-2000-01-01.log"
printf 'unrelated\n' > "$logs/laravel-2000-01-01.log"
printf 'recent\n' > "$logs/scheduler-2000-01-02.log"
touch -d '16 days ago' "$logs/scheduler-2000-01-01.log" "$logs/laravel-2000-01-01.log"
cron_entry=$(grep '^\* \* \* \* \* www-data ' "$script_dir/../cron/training-center.cron")
[[ "$(printf '%s\n' "$cron_entry" | wc -l)" == 1 ]]
cron_without_escaped_percent=${cron_entry//\\%/}
[[ "$cron_without_escaped_percent" != *%* ]] || { echo 'FAIL: unescaped cron percent'; exit 1; }
cron_command=${cron_entry#\* \* \* \* \* www-data }
cron_command=${cron_command//\/srv\/training-center/$TRAINING_CENTER_ROOT}
cron_command=${cron_command//\/usr\/bin\/php/$sandbox/bin/php}
cron_command=${cron_command//\/usr\/bin\/find/$sandbox/bin/find}
TEST_RUNTIME_USER=www-data sh -c "$cron_command"
daily_log="$logs/scheduler-$(date -u +%F).log"
[[ -f "$daily_log" && ! -e "$logs/scheduler-2000-01-01.log" && -f "$logs/scheduler-2000-01-02.log" && -f "$logs/laravel-2000-01-01.log" ]] || { echo 'FAIL: scheduler log retention'; exit 1; }
grep -q 'scheduler stdout' "$daily_log"
grep -q 'scheduler stderr' "$daily_log"
[[ "$(grep -c 'artisan schedule:run' "$TEST_SANDBOX/commands")" == 1 ]]
echo 'PASS: bounded scheduler log retention and one-minute command'
if ! FAIL_SCHEDULER_FIND=1 TEST_RUNTIME_USER=www-data sh -c "$cron_command"; then echo 'FAIL: scheduler suppressed by cleanup failure'; exit 1; fi
[[ "$(grep -c 'artisan schedule:run' "$TEST_SANDBOX/commands")" == 2 ]]
grep -q 'simulated find failure' "$daily_log"
grep -q 'Scheduler log cleanup failed' "$daily_log"
echo 'PASS: scheduler continues after visible cleanup failure'
for failure in invalid-sha manifest missing-env vite filament-js filament-css filament-fonts redis eviction backup-config mysql mail pre-smoke extensions fpm-extensions fpm-intl backup backup-lock migration-approval archive-link archive-traversal cached-config shared-link current-outside runtime-privileges; do
    fixture "$failure"
    export FAIL_STAGE="$failure"
    sha="$new"
    case "$failure" in
        invalid-sha) sha=not-a-sha ;;
        manifest) printf '%s\n' "$old" > "$case_root/artifact/REVISION" ;;
        missing-env) rm "$TRAINING_CENTER_ROOT/shared/.env" ;;
        vite) rm "$case_root/artifact/public/build/manifest.json" ;;
        filament-js) rm "$case_root/artifact/public/js/filament/a.js" ;;
        filament-css) rm "$case_root/artifact/public/css/filament/a.css" ;;
        filament-fonts) rm "$case_root/artifact/public/fonts/filament/a.woff2" ;;
        migration-approval) rm "$TRAINING_CENTER_ROOT/shared/migration-approvals/$new" ;;
        archive-link) ln -s "$TRAINING_CENTER_ROOT/shared" "$case_root/artifact/escape" ;;
        cached-config) printf 'unsafe\n' > "$case_root/artifact/bootstrap/cache/config.php" ;;
        shared-link) mkdir "$case_root/outside"; ln -s "$case_root/outside" "$TRAINING_CENTER_ROOT/shared/storage/escape" ;;
        current-outside)
            rm "$TRAINING_CENTER_ROOT/current"
            mkdir "$case_root/outside"
            ln -s "$case_root/outside" "$TRAINING_CENTER_ROOT/current"
            ;;
    esac
    pack
    if [[ "$failure" == archive-traversal ]]; then tar -cf "$case_root/release.tar" --transform='s|REVISION|../REVISION|' -C "$case_root/artifact" REVISION; fi
    if [[ "$failure" == current-outside ]]; then
        if bash "$release_script" "$sha" "$case_root/release.tar" > "$case_root/output" 2>&1; then exit 1; fi
        [[ "$(readlink "$TRAINING_CENTER_ROOT/current")" == "$case_root/outside" && -d "$case_root/outside" ]]
        echo 'PASS: rejected current-outside'
    else
        reject "$sha" "$failure"
    fi
done
unset FAIL_STAGE
fixture success
pack
bash "$release_script" "$new" "$case_root/release.tar" > "$case_root/output" 2>&1 || { cat "$case_root/output"; exit 1; }
[[ "$(readlink "$TRAINING_CENTER_ROOT/current")" == "$TRAINING_CENTER_ROOT/releases/$new" ]]
[[ "$(cat "$TRAINING_CENTER_ROOT/shared/deployed-sha")" == "$new" ]]
[[ "$(grep -h -c "Switched current to $new" "$TRAINING_CENTER_ROOT/shared/deploy-logs/"*.log)" == 1 ]]
[[ -d "$TRAINING_CENTER_ROOT/releases/$old" && -f "$TRAINING_CENTER_ROOT/shared/.env" ]]
[[ -L "$TRAINING_CENTER_ROOT/releases/$new/.env" && -L "$TRAINING_CENTER_ROOT/releases/$new/storage" ]]
for service in horizon pulse; do [[ "$(cat "$sandbox/$service-active")" == "$TRAINING_CENTER_ROOT/releases/$new" ]]; done
echo 'PASS: clean worker restarts'
printf '%s\n' "$third" > "$case_root/artifact/REVISION"
printf '%s\n' "$third" > "$TRAINING_CENTER_ROOT/shared/migration-approvals/$third"
pack
rm -f "$sandbox/backup-ok"
bash "$release_script" "$third" "$case_root/release.tar" > "$case_root/output" 2>&1 || { cat "$case_root/output"; exit 1; }
[[ "$(cat "$TRAINING_CENTER_ROOT/shared/deployed-sha")" == "$third" ]]
[[ -d "$TRAINING_CENTER_ROOT/releases/$new" ]]
echo 'PASS: second release'
fixture first-install
rm "$TRAINING_CENTER_ROOT/current"
printf '%s\n' "$new" > "$TRAINING_CENTER_ROOT/shared/first-install-approved"
pack
rm -f "$sandbox/backup-ok"
bash "$release_script" "$new" "$case_root/release.tar" > "$case_root/output" 2>&1 || { cat "$case_root/output"; exit 1; }
[[ "$(cat "$TRAINING_CENTER_ROOT/shared/deployed-sha")" == "$new" ]]
[[ ! -f "$sandbox/backup-ok" ]]
echo 'PASS: first installation'
for failure in post-http post-dependency post-worker; do
    fixture "$failure"
    pack
    export FAIL_STAGE="$failure"
    if bash "$release_script" "$new" "$case_root/release.tar" > "$case_root/output" 2>&1; then exit 1; fi
    [[ "$(readlink "$TRAINING_CENTER_ROOT/current")" == "$TRAINING_CENTER_ROOT/releases/$new" ]]
    grep -q 'operator-led recovery required' "$case_root/output"
    [[ ! -f "$TRAINING_CENTER_ROOT/shared/deployed-sha" ]]
    echo "PASS: post-switch recovery ($failure)"
done
