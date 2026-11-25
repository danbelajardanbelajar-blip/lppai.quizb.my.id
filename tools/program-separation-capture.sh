#!/bin/sh
# PHP never starts a child process in this workflow.
set -eu
umask 077
if [ "$#" -ne 1 ]; then printf '%s\n' 'Usage: sh tools/program-separation-capture.sh --output-dir=PRIVATE_DIRECTORY' >&2; exit 2; fi
case "$1" in --output-dir=?*) capture_parent=${1#--output-dir=} ;; *) printf '%s\n' 'Only --output-dir is accepted.' >&2; exit 2 ;; esac
cd -- "$(dirname -- "$0")/.."
php_bin=${PHP_BIN:-php}
command -v "$php_bin" >/dev/null 2>&1 || { printf '%s\n' 'PHP CLI not found.' >&2; exit 1; }
command -v mysqldump >/dev/null 2>&1 || { printf '%s\n' 'mysqldump not found; no capture started.' >&2; exit 1; }
capture_run=$("$php_bin" tools/program-separation-capture.php "--prepare=$capture_parent")
test -n "$capture_run" && test -f "$capture_run/dump.sh" || { printf '%s\n' 'Capture preparation incomplete.' >&2; exit 1; }
sh "$capture_run/dump.sh" || { printf '%s\n' 'Shell backup failed. No migration performed. Keep partial files private.' >&2; exit 1; }
"$php_bin" tools/program-separation-capture.php "--finish=$capture_run"
