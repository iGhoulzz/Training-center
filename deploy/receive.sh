#!/usr/bin/env bash
set -euo pipefail
umask 0027
export LC_ALL=C
# SSH forced command for the non-root training-center deploy account.
#
# Installed root-owned at /usr/local/bin/training-center-receive by the operator
# and pinned in the deploy key's authorized_keys line:
#   command="/usr/local/bin/training-center-receive",restrict ssh-ed25519 AAAA...
# A leaked GitHub secret therefore buys exactly this: the right to hand over one
# SHA and one archive. No shell, no forwarding, no file access.
#
# Contract: the only argument is a 40-hex SHA in SSH_ORIGINAL_COMMAND and the
# archive is streamed on stdin (`ssh ... "$SHA" < release.tar.gz`). The archive
# lands in a mktemp file inside shared/incoming (the script never names it from
# caller input, never creates the directory, and never writes outside it), is
# size-capped and deadline-capped, is checked against a digest the operator
# approved on the host, is handed to the host-installed release script, and is
# removed afterwards whatever happens. The release script's exit code is the
# exit code.
#
# THE ARCHIVE IS BOUND TO A HOST-TRUSTED DIGEST. A leaked deploy key can send any
# bytes under any SHA, and the release script boots the PHP it extracts. So the
# operator, while the deploy waits at the production approval, writes the
# archive's sha256 (shown in the build job's summary) to the root-owned file
# shared/archive-approvals/<SHA>. Without that file, or if it or its directory
# is a link, not root's, or writable by group or world, or if the digest does not
# match what arrived, nothing reaches the release script.
#
# ONE UPLOAD AT A TIME. A non-blocking lock on shared/incoming/.receive.lock is
# taken before anything is written and held through the release call, so
# concurrent or stalled sessions cannot each park a near-cap file, and a second
# upload cannot start while a release runs. The read has a deadline.
#
# The paths, cap and deadline are fixed below. TC_RECEIVE_ROOT, TC_RELEASE_BIN,
# TC_RECEIVE_MAX_BYTES and TC_RECEIVE_TIMEOUT_SECONDS exist only so
# deploy/tests/receive-smoke.sh can point the script at a disposable sandbox. They are honoured only outside an SSH session:
# sshd always sets SSH_CONNECTION, and under it the fixed values win, so a
# client cannot redirect the script even if the host allows environment
# passing. The authorized_keys line above pins the command itself and sets no
# environment.
root=/srv/training-center
release_bin=/usr/local/bin/training-center-release
max_bytes=314572800
timeout_seconds=900
if [[ -z "${SSH_CONNECTION:-}" ]]; then
    root=${TC_RECEIVE_ROOT:-$root}
    release_bin=${TC_RELEASE_BIN:-$release_bin}
    max_bytes=${TC_RECEIVE_MAX_BYTES:-$max_bytes}
    timeout_seconds=${TC_RECEIVE_TIMEOUT_SECONDS:-$timeout_seconds}
fi
tempfile=
fail() { printf 'Receive refused: %s\n' "$1" >&2; exit 2; }
cleanup() {
    if [[ -n "$tempfile" ]]; then rm -f -- "$tempfile"; fi
}
# Same rules as release.sh's approval_is_safe: not a link, owned by root, and not
# writable by group or world.
approval_is_safe() {
    [[ ! -L "$1" && "$(stat -c %u -- "$1")" == 0 ]] || return 1
    local mode
    mode=$(stat -c %a -- "$1")
    (( (8#$mode & 0022) == 0 ))
}
# Prints the approved sha256 for this SHA, or refuses.
approved_digest() {
    local approvals="$root/shared/archive-approvals" approval digest
    [[ -d "$approvals" && "$(realpath -e -- "$approvals")" == "$approvals" ]] && approval_is_safe "$approvals" || fail 'archive approvals directory is missing or unsafe'
    approval="$approvals/$sha"
    [[ -f "$approval" ]] && approval_is_safe "$approval" || fail 'no safe digest approval for this SHA'
    digest=$(head -c 200 -- "$approval")
    [[ "$digest" =~ ^[0-9a-f]{64}$ ]] || fail 'digest approval is malformed'
    printf '%s' "$digest"
}
trap cleanup EXIT
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM

command_line=${SSH_ORIGINAL_COMMAND:-}
[[ -n "$command_line" ]] || fail 'no command: this key only deploys a release (expected a 40-hex SHA)'
[[ ! -t 0 ]] || fail 'refusing to read an archive from a terminal'
[[ "$command_line" != *$'\n'* && "$command_line" =~ ^[0-9a-f]{40}$ ]] || fail 'expected exactly one 40-hex lowercase SHA'
sha=$command_line
[[ "$max_bytes" =~ ^[1-9][0-9]{0,12}$ ]] || fail 'invalid size cap'
[[ "$timeout_seconds" =~ ^[1-9][0-9]{0,5}$ ]] || fail 'invalid upload deadline'

incoming="$root/shared/incoming"
[[ "$root" == /* && -d "$incoming" && ! -L "$incoming" && "$(realpath -e -- "$incoming")" == "$incoming" ]] || fail 'incoming directory is missing or not a physical directory'
[[ -f "$release_bin" && -x "$release_bin" ]] || fail 'release script is not installed'

# Refuse early when no approval exists, before an archive is read at all.
approved_digest > /dev/null

lock="$incoming/.receive.lock"
[[ ! -L "$lock" ]] || fail 'lock file is a symlink'
exec 7> "$lock"
flock -n 7 || fail 'another upload is in progress'

tempfile=$(mktemp -- "$incoming/receive.XXXXXXXXXX")
# One byte past the cap is enough to tell "exactly at the cap" from "over it".
# A stalled sender is killed at the deadline instead of holding the lock.
timeout --signal=KILL "$timeout_seconds" head -c "$((max_bytes + 1))" > "$tempfile" || fail 'upload did not finish before the deadline'
size=$(wc -c < "$tempfile")
(( size > 0 )) || fail 'empty archive'
(( size <= max_bytes )) || fail 'archive exceeds the size cap'

actual_digest=$(sha256sum -- "$tempfile")
actual_digest=${actual_digest%% *}
[[ "$actual_digest" == "$(approved_digest)" ]] || fail 'archive does not match the approved digest'

# Once the release runs it must finish: a dropped connection (SIGHUP) would
# otherwise abandon it half-way and delete the archive it is still extracting.
# INT and TERM wait for it too, because bash defers a trap until the foreground
# command returns.
trap '' HUP
status=0
"$release_bin" "$sha" "$tempfile" < /dev/null || status=$?
exit "$status"
