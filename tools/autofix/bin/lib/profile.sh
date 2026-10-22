#!/bin/bash
# profile.sh — repo profiles. Source after common.sh, don't execute.
# A profile replaces the values autofix used to hardcode for the monorepo. A
# run with no `profile` decision gets newspack-workspace, which reproduces the
# old behavior exactly.
: "${AUTOFIX_PROFILES:=$AUTOFIX_BIN_DIR/../profiles.json}"

profile_name() { # run_id
  local p; p="$(ledger_decision "$1" profile)"
  printf '%s' "${p:-newspack-workspace}"
}

# profile_get <run_id> <jq-filter> — dies when the run's profile is not in
# profiles.json. A run can draft a profile for a repo that has none, but the
# scripts never read a drafted one: it would let the run write the allowlist
# and path scope it is then checked against. The run stops and asks instead.
profile_get() { # run_id filter
  local name; name="$(profile_name "$1")"
  jq -e --arg n "$name" 'has($n)' "$AUTOFIX_PROFILES" >/dev/null 2>&1 \
    || die "profile '$name' is not in $AUTOFIX_PROFILES — a drafted profile is not used until the operator keeps it; stop and ask"
  jq -r --arg n "$name" ".[\$n] | $2" "$AUTOFIX_PROFILES"
}

# profile_base <run_id> <dir> — fetch the profile's base when it names a fetch,
# then print the base ref. Fails closed like fetch_upstream_main: a failed fetch
# is tolerated only when the ref already resolves.
profile_base() { # run_id dir
  local ref f; ref="$(profile_get "$1" '.base.ref')"; f="$(profile_get "$1" '.base.fetch // empty')"
  if [ -n "$f" ]; then
    # shellcheck disable=SC2086  # "origin main" is two words on purpose
    if ! git -C "$2" fetch $f; then
      git -C "$2" rev-parse -q --verify "$ref" >/dev/null 2>&1 \
        || die "cannot resolve $ref in $2 (fetch failed and no cached ref exists) — refusing to guess the base"
      log "git fetch $f failed in $2; using cached $ref"
    fi
  fi
  git -C "$2" rev-parse -q --verify "$ref" >/dev/null 2>&1 || die "base ref $ref does not resolve in $2"
  printf '%s' "$ref"
}

# profile_offending_paths <run_id> <affected_repo> — read paths on stdin, print
# those outside path_scope. An entry ending in "/" is a directory prefix; any
# other entry matches that exact path only, so "n" never admits "node_modules".
profile_offending_paths() { # run_id affected_repo
  local scope; scope="$(profile_get "$1" '.path_scope[]' | sed "s#{affected_repo}#$2#g")" || return 1
  # Through the environment, not -v: BSD awk rejects a newline in a -v value.
  PROFILE_SCOPE="$scope" awk '
    BEGIN { n = split(ENVIRON["PROFILE_SCOPE"], s, "\n") }
    { ok = 0
      for (i = 1; i <= n; i++) {
        e = s[i]; if (e == "") continue
        if (substr(e, length(e)) == "/") { if (index($0, e) == 1) ok = 1 }
        else if ($0 == e) ok = 1
      }
      if (!ok) print }'
}

# profile_allows_cmd <run_id> <cmd-string> — is the command one the profile
# names, alone or with arguments after a space?
profile_allows_cmd() { # run_id cmd
  local c cmds
  cmds="$(profile_get "$1" '.commands[]')" || return 1
  while IFS= read -r c; do
    [ -n "$c" ] || continue
    [ "$2" = "$c" ] && return 0
    case "$2" in "$c "*) return 0 ;; esac
  done <<EOF2
$cmds
EOF2
  return 1
}
