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
# size-capped, is handed to the host-installed release script, and is removed
# afterwards whatever happens. The release script's exit code is the exit code.
#
# The paths and the cap are fixed below. TC_RECEIVE_ROOT, TC_RELEASE_BIN and
# TC_RECEIVE_MAX_BYTES exist only so deploy/tests/receive-smoke.sh can point the
# script at a disposable sandbox. They are honoured only outside an SSH session:
# sshd always sets SSH_CONNECTION, and under it the fixed values win, so a
# client cannot redirect the script even if the host allows environment
# passing. The authorized_keys line above pins the command itself and sets no
# environment.
root=/srv/training-center
release_bin=/usr/local/bin/training-center-release
max_bytes=314572800
if [[ -z "${SSH_CONNECTION:-}" ]]; then
    root=${TC_RECEIVE_ROOT:-$root}
    release_bin=${TC_RELEASE_BIN:-$release_bin}
    max_bytes=${TC_RECEIVE_MAX_BYTES:-$max_bytes}
fi
tempfile=
fail() { printf 'Receive refused: %s\n' "$1" >&2; exit 2; }
cleanup() {
    if [[ -n "$tempfile" ]]; then rm -f -- "$tempfile"; fi
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

incoming="$root/shared/incoming"
[[ "$root" == /* && -d "$incoming" && ! -L "$incoming" && "$(realpath -e -- "$incoming")" == "$incoming" ]] || fail 'incoming directory is missing or not a physical directory'
[[ -f "$release_bin" && -x "$release_bin" ]] || fail 'release script is not installed'

tempfile=$(mktemp -- "$incoming/receive.XXXXXXXXXX")
# One byte past the cap is enough to tell "exactly at the cap" from "over it".
head -c "$((max_bytes + 1))" > "$tempfile"
size=$(wc -c < "$tempfile")
(( size > 0 )) || fail 'empty archive'
(( size <= max_bytes )) || fail 'archive exceeds the size cap'

# Once the release runs it must finish: a dropped connection (SIGHUP) would
# otherwise abandon it half-way and delete the archive it is still extracting.
# INT and TERM wait for it too, because bash defers a trap until the foreground
# command returns.
trap '' HUP
status=0
"$release_bin" "$sha" "$tempfile" < /dev/null || status=$?
exit "$status"
