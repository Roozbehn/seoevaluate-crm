#!/usr/bin/env bash
#
# se-secret-install.sh — safe, owner-only installer for SE integration secrets.
#
# The owner runs this INTERACTIVELY over their own SSH session. It never takes a
# secret value as a command argument, never echoes it, and prints only file
# metadata (name, owner, mode, readable) — never the contents. This keeps secret
# values out of Claude chat, tool calls, command arguments, shell history,
# process lists, logs, git, and screenshots.
#
#   Single-line secrets (hidden no-echo prompt, read from stdin):
#     ./se-secret-install.sh meta_app
#     ./se-secret-install.sh meta_page_22
#     ./se-secret-install.sh meta_capi_22
#
#   Multi-line Google service-account JSON (secure file transfer, or pasted):
#     ./se-secret-install.sh google_sa_22 --file /home/hyundaic/key.json
#     ./se-secret-install.sh google_sa_22           # then paste JSON, Ctrl-D
#
# WhatsApp needs NO wa_app file: it inherits the shared meta_app (same Meta app).
#
set -euo pipefail
umask 077

SECRET_DIR="${SE_SECRET_DIR:-/home/hyundaic/_secrets}"
ALLOWED="meta_app meta_page_22 meta_capi_22 google_sa_22 wa_token"

usage() {
    echo "Usage: $0 <provider>            (one of: $ALLOWED)" >&2
    echo "       $0 google_sa_22 --file <path-to-json>" >&2
    exit 2
}

prov="${1:-}"
[ -n "$prov" ] || usage
case " $ALLOWED " in
    *" $prov "*) ;;
    *) echo "Refused: '$prov' is not an installable provider (wa_app is never installed; WhatsApp inherits meta_app)." >&2; usage ;;
esac

mkdir -p "$SECRET_DIR"
chmod 700 "$SECRET_DIR"

dest="$SECRET_DIR/$prov"
tmp="$(mktemp "$SECRET_DIR/.tmp.XXXXXX")"
trap 'rm -f "$tmp"' EXIT

validate_json() {
    php -r '$d=file_get_contents($argv[1]); json_decode($d); if (json_last_error()!==JSON_ERROR_NONE){fwrite(STDERR,"not valid JSON\n");exit(1);}' "$1"
}

if [ "$prov" = "google_sa_22" ] && [ "${2:-}" = "--file" ]; then
    src="${3:-}"
    [ -f "$src" ] || { echo "Refused: no such file: $src" >&2; exit 1; }
    validate_json "$src" || { echo "Refused: file is not valid JSON." >&2; exit 1; }
    cat "$src" > "$tmp"
elif [ "$prov" = "google_sa_22" ]; then
    echo "Paste the service-account JSON, then press Ctrl-D on a new line:" >&2
    cat > "$tmp"
    validate_json "$tmp" || { echo "Refused: pasted text is not valid JSON." >&2; exit 1; }
else
    # Single-line secret: hidden, read from stdin into a variable (never argv).
    printf "Paste the value for %s (input hidden), then press Enter: " "$prov" >&2
    value=""
    IFS= read -rs value || true   # tolerate EOF; the empty-check below decides
    echo >&2
    [ -n "$value" ] || { echo "Refused: empty input." >&2; exit 1; }
    printf '%s' "$value" > "$tmp"
    unset value
fi

[ -s "$tmp" ] || { echo "Refused: empty input." >&2; exit 1; }

mv -f "$tmp" "$dest"
chmod 600 "$dest"
trap - EXIT

# Report ONLY metadata. Never the contents.
echo "Installed: $(basename "$dest")"
echo "  path     : $dest"
echo "  owner    : $(stat -c '%U:%G' "$dest" 2>/dev/null || stat -f '%Su:%Sg' "$dest")"
echo "  mode     : $(stat -c '%a' "$dest" 2>/dev/null || stat -f '%Lp' "$dest")"
echo "  readable : $([ -r "$dest" ] && echo yes || echo no)"
echo
echo "Next: open the CRM → Integration Credentials to confirm the new state,"
echo "and Integration Health for the updated blockers."
