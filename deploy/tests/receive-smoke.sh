#!/usr/bin/env bash
set -euo pipefail
# Only a disposable sandbox and a stub release script are used: no network, no
# real host root, no SSH. The forced command is exercised exactly as sshd would
# run it: SSH_ORIGINAL_COMMAND set, the archive on stdin.
script_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)
# Git Bash cannot create real POSIX links without Windows symlink privileges.
# Run the exact harness on the cached Linux target, with no network or services.
if [[ "$(uname -s)" == MINGW* ]]; then
    export MSYS_NO_PATHCONV=1
    exec docker run --rm --network none --mount "type=bind,source=$(cygpath -w "$script_dir/.."),target=/contract,readonly" --entrypoint bash training-center-dev-linux:latest /contract/tests/receive-smoke.sh
fi
receive="$script_dir/../receive.sh"
[[ -f "$receive" ]] || { echo 'FAIL: receive implementation missing' >&2; exit 1; }
# The harness overrides the host root; it must never run beside a real one.
[[ ! -e /srv/training-center ]] || { echo 'FAIL: refusing to run on a host that has /srv/training-center' >&2; exit 1; }
sandbox=$(mktemp -d)
trap '[[ -d "$sandbox" && "$sandbox" != / ]] && rm -rf -- "$sandbox"' EXIT
export TEST_SANDBOX="$sandbox"
export LC_ALL=C
sha=abcdef0123456789abcdef0123456789abcdef01
root="$sandbox/host"
incoming="$root/shared/incoming"
stub="$sandbox/release-stub"
cat > "$stub" <<'STUB'
#!/usr/bin/env bash
# Records everything receive.sh hands to the release script.
printf '%s\n' "$#" > "$TEST_SANDBOX/stub-argc"
printf '%s' "$1" > "$TEST_SANDBOX/stub-sha"
printf '%s' "$2" > "$TEST_SANDBOX/stub-archive"
[[ -f "$2" && ! -L "$2" ]] || exit 97
stat -c %a -- "$2" > "$TEST_SANDBOX/stub-mode"
cat -- "$2" > "$TEST_SANDBOX/stub-content"
touch "$TEST_SANDBOX/stub-started"
if [[ -n "${STUB_SLEEP:-}" ]]; then sleep "$STUB_SLEEP"; fi
echo 'stub release output'
touch "$TEST_SANDBOX/stub-finished"
exit "${STUB_EXIT:-0}"
STUB
chmod +x "$stub"
# Approval files must be root-owned. A harness may run as any user, so the owner
# lookup is stubbed (as release-smoke.sh does): every file is root's unless the
# test lists it in foreign-owned. Everything else about stat is the real thing.
mkdir "$sandbox/bin"
export REAL_STAT
REAL_STAT=$(command -v stat)
cat > "$sandbox/bin/stat" <<'STUB'
#!/usr/bin/env bash
if [[ "$1" == -c && "$2" == %u ]]; then
    if grep -qxF -- "$4" "$TEST_SANDBOX/foreign-owned" 2> /dev/null; then echo 1000; else echo 0; fi
else
    exec "$REAL_STAT" "$@"
fi
STUB
chmod +x "$sandbox/bin/stat"
export PATH="$sandbox/bin:$PATH"

fresh() {
    rm -rf -- "$root" "$sandbox"/stub-* "$sandbox/pwn" "$sandbox/output" "$sandbox/foreign-owned"
    mkdir -p "$incoming" "$root/shared/archive-approvals"
    chmod 755 "$root/shared/archive-approvals"
    auto_approve=1
}
# The one file receive.sh may leave behind: its lock.
listing() { find "$root" -mindepth 1 ! -name .receive.lock | sort; }
leftovers() { find "$incoming" -mindepth 1 ! -name .receive.lock 2> /dev/null || true; }
# approve <archive-file> [sha]: what the operator does on the host from the build summary.
approve() {
    local approval="$root/shared/archive-approvals/${2:-$sha}"
    rm -f -- "$approval"
    printf '%s\n' "$(sha256sum "$1" | cut -d' ' -f1)" > "$approval"
    chmod 644 "$approval"
}
# run_receive <ssh-original-command> <stdin-file> [extra env assignments...]
# The archive is approved by its real digest first, unless a test sets auto_approve=0.
run_receive() {
    local command=$1 input=$2
    shift 2
    rc=0
    if [[ "$auto_approve" == 1 ]]; then approve "$input"; fi
    env SSH_ORIGINAL_COMMAND="$command" TC_RECEIVE_ROOT="$root" TC_RELEASE_BIN="$stub" TC_RECEIVE_MAX_BYTES=1024 TC_RECEIVE_TIMEOUT_SECONDS=20 "$@" \
        bash "$receive" < "$input" > "$sandbox/output" 2>&1 || rc=$?
}
refused() { # <label>: non-zero, release never called, nothing left behind or created
    [[ "$rc" != 0 ]] || { cat "$sandbox/output"; echo "FAIL: accepted $1"; exit 1; }
    [[ "$rc" == 2 && "$(cat "$sandbox/output")" == Receive\ refused:* ]] || { cat "$sandbox/output"; echo "FAIL: $1 failed for a reason other than the guard"; exit 1; }
    [[ ! -e "$sandbox/stub-started" && ! -e "$sandbox/stub-argc" ]] || { echo "FAIL: release called for $1"; exit 1; }
    [[ -z "$(leftovers)" ]] || { echo "FAIL: leftover file for $1"; exit 1; }
    [[ ! -e "$sandbox/pwn" ]] || { echo "FAIL: command injection for $1"; exit 1; }
    echo "PASS: refused $1"
}

head -c 700 /dev/urandom > "$sandbox/archive"
head -c 1024 /dev/urandom > "$sandbox/archive-at-cap"
head -c 1025 /dev/urandom > "$sandbox/archive-over-cap"
head -c 1000000 /dev/zero > "$sandbox/archive-huge"
: > "$sandbox/archive-empty"

# 1. A valid SHA and archive reach the release script, and the temp file is gone.
fresh
approve "$sandbox/archive"
before=$(listing)
run_receive "$sha" "$sandbox/archive"
[[ "$rc" == 0 ]] || { cat "$sandbox/output"; echo 'FAIL: valid deploy failed'; exit 1; }
[[ "$(cat "$sandbox/stub-argc")" == 2 && "$(cat "$sandbox/stub-sha")" == "$sha" ]]
archive_path=$(cat "$sandbox/stub-archive")
[[ "$archive_path" == "$incoming"/* && "$archive_path" != *"$sha"* ]] || { echo 'FAIL: temp file name or location'; exit 1; }
cmp -s "$sandbox/archive" "$sandbox/stub-content" || { echo 'FAIL: archive bytes changed in transit'; exit 1; }
[[ "$(cat "$sandbox/stub-mode")" == 600 ]] || { echo 'FAIL: temp file mode'; exit 1; }
[[ ! -e "$archive_path" ]] || { echo 'FAIL: temp file survived'; exit 1; }
[[ "$(listing)" == "$before" ]] || { echo 'FAIL: tree changed beyond the temp file'; exit 1; }
grep -q 'stub release output' "$sandbox/output"
echo 'PASS: valid SHA and archive reach the release script, temp file removed'

# 2. The cap is inclusive: exactly the cap passes.
fresh
run_receive "$sha" "$sandbox/archive-at-cap"
[[ "$rc" == 0 ]] && cmp -s "$sandbox/archive-at-cap" "$sandbox/stub-content" || { cat "$sandbox/output"; echo 'FAIL: input at the cap refused'; exit 1; }
echo 'PASS: input exactly at the size cap is accepted'

# 3. Bad or missing commands never reach the release script.
cases=(
    'empty'
    'abc'
    'ABCDEF0123456789ABCDEF0123456789ABCDEF01'
    "${sha}0"
    "${sha:1}"
    "$sha extra"
    "$sha $sha"
    " $sha"
    "$sha "
    "$sha;id"
    "$sha && touch $sandbox/pwn"
    "$sha | touch $sandbox/pwn"
    "$sha\$(touch $sandbox/pwn)"
    "\$(touch $sandbox/pwn)"
    "\`touch $sandbox/pwn\`"
    "$sha > $sandbox/pwn"
    "../$sha"
    "$sha/../x"
    '--help'
    "$sha"$'\n'
    "$sha"$'\n'"$sha"
    "$sha"$'\n'"touch $sandbox/pwn"
    "bash -c 'touch $sandbox/pwn'"
)
index=0
for command in "${cases[@]}"; do
    index=$((index + 1))
    fresh
    [[ "$command" != empty ]] || command=''
    run_receive "$command" "$sandbox/archive"
    refused "command case $index"
done
fresh
rc=0
env -u SSH_ORIGINAL_COMMAND TC_RECEIVE_ROOT="$root" TC_RELEASE_BIN="$stub" bash "$receive" < "$sandbox/archive" > "$sandbox/output" 2>&1 || rc=$?
refused 'missing SSH_ORIGINAL_COMMAND'

# 4. Over-cap and empty input are refused with nothing left behind.
for input in archive-over-cap archive-huge archive-empty; do
    fresh
    run_receive "$sha" "$sandbox/$input"
    refused "$input"
done

# 5. An unsafe incoming directory is refused, and nothing is created.
fresh
mv "$incoming" "$root/shared/incoming-real"
ln -s "$root/shared/incoming-real" "$incoming"
run_receive "$sha" "$sandbox/archive"
[[ "$rc" != 0 && ! -e "$sandbox/stub-started" ]] || { echo 'FAIL: symlinked incoming accepted'; exit 1; }
[[ -z "$(ls -A "$root/shared/incoming-real")" ]] || { echo 'FAIL: wrote through a symlinked incoming'; exit 1; }
echo 'PASS: refused symlinked incoming directory'
fresh
mv "$root/shared" "$root/shared-real"
ln -s "$root/shared-real" "$root/shared"
run_receive "$sha" "$sandbox/archive"
[[ "$rc" != 0 && ! -e "$sandbox/stub-started" ]] || { echo 'FAIL: symlinked shared accepted'; exit 1; }
[[ -z "$(ls -A "$root/shared-real/incoming")" ]] || { echo 'FAIL: wrote through a symlinked shared'; exit 1; }
echo 'PASS: refused symlinked parent of incoming'
fresh
rmdir "$incoming"
run_receive "$sha" "$sandbox/archive"
[[ "$rc" != 0 && ! -e "$sandbox/stub-started" && ! -e "$incoming" ]] || { echo 'FAIL: missing incoming accepted or created'; exit 1; }
echo 'PASS: refused missing incoming directory without creating it'
fresh
rmdir "$incoming"
touch "$incoming"
run_receive "$sha" "$sandbox/archive"
[[ "$rc" != 0 && ! -e "$sandbox/stub-started" ]] || { echo 'FAIL: file in place of incoming accepted'; exit 1; }
echo 'PASS: refused a file in place of the incoming directory'

# 6. A failing release script propagates its exit code and still cleans up.
fresh
run_receive "$sha" "$sandbox/archive" STUB_EXIT=7
[[ "$rc" == 7 ]] || { cat "$sandbox/output"; echo "FAIL: exit code $rc instead of 7"; exit 1; }
[[ -e "$sandbox/stub-finished" && -z "$(leftovers)" ]] || { echo 'FAIL: no cleanup after a failing release'; exit 1; }
echo 'PASS: failing release propagates its exit code and cleans up'

# 7. A missing release script is refused before any input is read.
fresh
run_receive "$sha" "$sandbox/archive" TC_RELEASE_BIN="$sandbox/does-not-exist"
refused 'missing release script'

# 8. Over ssh the environment cannot redirect the root or the release script.
fresh
run_receive "$sha" "$sandbox/archive" SSH_CONNECTION='203.0.113.9 50000 198.51.100.1 22'
refused 'environment overrides under an ssh session'

# 9. A hang-up from a dropped connection does not abandon a running release.
fresh
approve "$sandbox/archive"
(
    STUB_SLEEP=2 SSH_ORIGINAL_COMMAND="$sha" TC_RECEIVE_ROOT="$root" TC_RELEASE_BIN="$stub" TC_RECEIVE_MAX_BYTES=1024 \
        exec bash "$receive" < "$sandbox/archive" > "$sandbox/output" 2>&1
) &
pid=$!
for _ in $(seq 1 100); do [[ -e "$sandbox/stub-started" ]] && break; sleep 0.1; done
[[ -e "$sandbox/stub-started" ]] || { echo 'FAIL: release never started'; exit 1; }
kill -HUP "$pid"
rc=0
wait "$pid" || rc=$?
[[ "$rc" == 0 && -e "$sandbox/stub-finished" && -z "$(leftovers)" ]] || { cat "$sandbox/output"; echo "FAIL: hang-up abandoned the release (exit $rc)"; exit 1; }
echo 'PASS: a hang-up lets the running release finish, then cleans up'

# 10. The archive must match the digest the operator approved on the host.
fresh
head -c 700 /dev/urandom > "$sandbox/forged"
approve "$sandbox/archive"
auto_approve=0
run_receive "$sha" "$sandbox/forged"
refused 'a forged archive under an approved SHA (wrong digest)'
fresh
auto_approve=0
run_receive "$sha" "$sandbox/archive"
refused 'an archive with no digest approval'
fresh
auto_approve=0
mv "$root/shared/archive-approvals" "$root/shared/archive-approvals-real"
run_receive "$sha" "$sandbox/archive"
refused 'a missing approvals directory'
fresh
auto_approve=0
printf 'not-a-digest\n' > "$root/shared/archive-approvals/$sha"
chmod 644 "$root/shared/archive-approvals/$sha"
run_receive "$sha" "$sandbox/archive"
refused 'a malformed approval'
fresh
auto_approve=0
printf '%s\n' "$(sha256sum "$sandbox/archive" | cut -d' ' -f1 | tr a-f A-F)" > "$root/shared/archive-approvals/$sha"
chmod 644 "$root/shared/archive-approvals/$sha"
run_receive "$sha" "$sandbox/archive"
refused 'an uppercase approval digest'
fresh
auto_approve=0
printf '%s\nextra\n' "$(sha256sum "$sandbox/archive" | cut -d' ' -f1)" > "$root/shared/archive-approvals/$sha"
chmod 644 "$root/shared/archive-approvals/$sha"
run_receive "$sha" "$sandbox/archive"
refused 'an approval with trailing content'

# 11. An approval that anyone but root could have written is not trusted.
fresh
approve "$sandbox/archive"
auto_approve=0
mv "$root/shared/archive-approvals/$sha" "$root/shared/real-approval"
ln -s "$root/shared/real-approval" "$root/shared/archive-approvals/$sha"
run_receive "$sha" "$sandbox/archive"
refused 'a symlinked approval'
for mode in 664 646; do
    fresh
    approve "$sandbox/archive"
    auto_approve=0
    chmod "$mode" "$root/shared/archive-approvals/$sha"
    run_receive "$sha" "$sandbox/archive"
    refused "an approval with mode $mode"
done
fresh
approve "$sandbox/archive"
auto_approve=0
printf '%s\n' "$root/shared/archive-approvals/$sha" > "$sandbox/foreign-owned"
run_receive "$sha" "$sandbox/archive"
refused 'an approval not owned by root'
fresh
approve "$sandbox/archive"
auto_approve=0
chmod 775 "$root/shared/archive-approvals"
run_receive "$sha" "$sandbox/archive"
refused 'a group-writable approvals directory'
fresh
approve "$sandbox/archive"
auto_approve=0
printf '%s\n' "$root/shared/archive-approvals" > "$sandbox/foreign-owned"
run_receive "$sha" "$sandbox/archive"
refused 'an approvals directory not owned by root'
fresh
approve "$sandbox/archive"
auto_approve=0
mv "$root/shared/archive-approvals" "$root/shared/archive-approvals-real"
ln -s "$root/shared/archive-approvals-real" "$root/shared/archive-approvals"
run_receive "$sha" "$sandbox/archive"
refused 'a symlinked approvals directory'

# 12. The correct digest reaches the release, with or without a trailing newline.
fresh
auto_approve=0
printf '%s' "$(sha256sum "$sandbox/archive" | cut -d' ' -f1)" > "$root/shared/archive-approvals/$sha"
chmod 644 "$root/shared/archive-approvals/$sha"
run_receive "$sha" "$sandbox/archive"
[[ "$rc" == 0 && "$(cat "$sandbox/stub-sha")" == "$sha" ]] || { cat "$sandbox/output"; echo 'FAIL: the approved digest did not reach the release'; exit 1; }
echo 'PASS: the approved digest reaches the release script'

# 13. One upload at a time, and the lock is held through the release call.
fresh
approve "$sandbox/archive"
(
    STUB_SLEEP=3 SSH_ORIGINAL_COMMAND="$sha" TC_RECEIVE_ROOT="$root" TC_RELEASE_BIN="$stub" TC_RECEIVE_MAX_BYTES=1024 \
        exec bash "$receive" < "$sandbox/archive" > "$sandbox/output-first" 2>&1
) &
first=$!
for _ in $(seq 1 100); do [[ -e "$sandbox/stub-started" ]] && break; sleep 0.1; done
[[ -e "$sandbox/stub-started" ]] || { echo 'FAIL: first release never started'; exit 1; }
first_file=$(cat "$sandbox/stub-archive")
started=$SECONDS
run_receive "$sha" "$sandbox/archive"
(( SECONDS - started < 3 )) || { echo 'FAIL: the second upload waited instead of refusing at once'; exit 1; }
[[ "$rc" == 2 && "$(cat "$sandbox/output")" == *'another upload is in progress'* ]] || { cat "$sandbox/output"; echo 'FAIL: second upload not refused for the lock'; exit 1; }
[[ -f "$first_file" ]] && cmp -s "$first_file" "$sandbox/archive" || { echo 'FAIL: the second upload touched the first upload file'; exit 1; }
[[ "$(leftovers | wc -l)" == 1 ]] || { echo 'FAIL: a second temp file was created'; exit 1; }
rc=0
wait "$first" || rc=$?
[[ "$rc" == 0 && -z "$(leftovers)" ]] || { cat "$sandbox/output-first"; echo "FAIL: first upload did not complete cleanly (exit $rc)"; exit 1; }
echo 'PASS: a second upload during a running release is refused at once, the first is untouched'
fresh
touch "$sandbox/lock-target"
ln -s "$sandbox/lock-target" "$incoming/.receive.lock"
run_receive "$sha" "$sandbox/archive"
[[ "$rc" == 2 && ! -e "$sandbox/stub-started" && ! -s "$sandbox/lock-target" && -z "$(leftovers)" ]] || { cat "$sandbox/output"; echo 'FAIL: symlinked lock accepted'; exit 1; }
echo 'PASS: refused a symlinked lock file'

# 14. A stalled upload is killed at the deadline and cleaned up, and nothing else
#     can upload while it holds the lock.
fresh
approve "$sandbox/archive"
rm -f "$sandbox/fifo"
mkfifo "$sandbox/fifo"
(
    SSH_ORIGINAL_COMMAND="$sha" TC_RECEIVE_ROOT="$root" TC_RELEASE_BIN="$stub" TC_RECEIVE_MAX_BYTES=1024 TC_RECEIVE_TIMEOUT_SECONDS=3 \
        exec bash "$receive" < "$sandbox/fifo" > "$sandbox/output-first" 2>&1
) &
first=$!
( head -c 100 /dev/urandom; exec sleep 30 ) > "$sandbox/fifo" &
writer=$!
for _ in $(seq 1 100); do [[ -n "$(leftovers)" ]] && break; sleep 0.1; done
[[ -n "$(leftovers)" ]] || { echo 'FAIL: the stalled upload never started'; exit 1; }
started=$SECONDS
run_receive "$sha" "$sandbox/archive"
(( SECONDS - started < 3 )) || { echo 'FAIL: the second upload waited behind a stalled one'; exit 1; }
[[ "$rc" == 2 && "$(cat "$sandbox/output")" == *'another upload is in progress'* ]] || { cat "$sandbox/output"; echo 'FAIL: second upload not refused during a stalled one'; exit 1; }
started=$SECONDS
rc=0
wait "$first" || rc=$?
kill "$writer" 2> /dev/null || true
(( SECONDS - started < 10 )) || { echo 'FAIL: the stalled upload outlived its deadline'; exit 1; }
[[ "$rc" == 2 && "$(cat "$sandbox/output-first")" == *"Receive refused: upload did not finish before the deadline"* ]] || { cat "$sandbox/output-first"; echo "FAIL: stalled upload not refused (exit $rc)"; exit 1; }
[[ -z "$(leftovers)" && ! -e "$sandbox/stub-started" ]] || { echo 'FAIL: stalled upload left a file or reached the release'; exit 1; }
echo 'PASS: a stalled upload is killed at the deadline and cleaned up; a second one is refused meanwhile'
