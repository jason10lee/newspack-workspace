#!/bin/bash

source "$(dirname "${BASH_SOURCE[0]}")/_common.sh"
source "$(dirname "${BASH_SOURCE[0]}")/repos.sh"

# Sanitize env name for use as a database name (replace dashes with underscores).
db_name_for_env() {
    echo "wordpress_$(echo "$1" | tr '-' '_')"
}

# Find the next available loopback IP (127.0.0.2+).
# Checks both compose files and running containers to avoid conflicts.
next_loopback_ip() {
    used_ips=""
    for f in "$NABSPATH"/docker-compose.env-*.yml; do
        [[ -f "$f" ]] || continue
        ip=$(grep -o '127\.0\.0\.[0-9]*' "$f" | head -1)
        [[ -n "$ip" ]] && used_ips="$used_ips $ip"
    done
    # Also check IPs bound by running containers (covers stale/recreated envs).
    for ip in $(docker ps --format '{{.Ports}}' 2>/dev/null | grep -o '127\.0\.0\.[0-9]*' | sort -u); do
        used_ips="$used_ips $ip"
    done
    octet=2
    while echo "$used_ips" | grep -qw "127\\.0\\.0\\.$octet"; do
        octet=$((octet + 1))
    done
    if [[ "$octet" -gt 254 ]]; then
        echo "Error: no available loopback IPs (127.0.0.2-254 exhausted)" >&2
        exit 1
    fi
    echo "127.0.0.$octet"
}

# Read domain from a compose file's WP_DOMAIN env var.
domain_for_env() {
    grep 'WP_DOMAIN=' "$1" | head -1 | sed 's/.*WP_DOMAIN=//'
}

# Read loopback IP from a compose file.
ip_for_env() {
    grep -o '127\.0\.0\.[0-9]*' "$1" | head -1
}

# Emit per-worktree metadata for a generated compose file.
# Output: tab-separated tuples of <repo>\t<branch>\t<safe_branch>\t<host_path>
#   repo        — project name (e.g. newspack-plugin, newspack-community)
#   branch      — original branch (from metadata comment, or sanitized fallback)
#   safe_branch — sanitized directory-name form (slashes -> dashes)
#   host_path   — host-side dir relative to workspace root (plugins/X, themes/X, repos/X)
# Reads `# newspack-wt:` comments (commit 4+) as ground truth for the original
# branch; falls back to safe_branch from the mount path when comments are absent
# (e.g., compose files generated before metadata existed). Emits a one-time note
# to stderr when falling back, unless `--quiet` is passed (used by read-only
# callers like env list, where the note would repeat per-env).
parse_env_worktrees() {
    local compose_file="$1"
    local quiet="${2:-}"
    [[ -f "$compose_file" ]] || return 0

    local comment_repos=() comment_branches=()
    while IFS= read -r line; do
        if [[ "$line" =~ ^[[:space:]]*#[[:space:]]*newspack-wt:[[:space:]]*repo=([^[:space:]]+)[[:space:]]+branch=([^[:space:]]+) ]]; then
            comment_repos+=("${BASH_REMATCH[1]}")
            comment_branches+=("${BASH_REMATCH[2]}")
        fi
    done < "$compose_file"

    local warned_fallback=false warned_legacy=false
    while IFS= read -r line; do
        local repo="" safe_branch="" host=""
        # Legacy mount shape (pre-PR-#154): ./worktrees/<repo>/<branch>:/newspack-repos/<name>
        # The destroy / list helpers can't safely manage these; warn once per
        # compose file and skip. Affected envs need manual cleanup (`n env destroy`
        # will still drop the container/db/compose file even when worktrees are skipped).
        if [[ "$line" =~ \./worktrees/[^/]+/[^:]+:/newspack-repos/[^[:space:]]+ ]] \
           && [[ ! "$line" =~ \./worktrees/standalone/ ]]; then
            if [[ "$warned_legacy" != true && "$quiet" != "--quiet" ]]; then
                echo "[env] note: $(basename "$compose_file") uses legacy worktree mounts (pre-PR-#154); their worktrees will not be auto-managed" >&2
                warned_legacy=true
            fi
            continue
        fi
        # Tier 2 first (more specific path shape).
        if [[ "$line" =~ \./worktrees/standalone/([^/]+)/([^:]+):/newspack-(plugins|themes)/([^[:space:]]+) ]]; then
            repo="${BASH_REMATCH[1]}"
            safe_branch="${BASH_REMATCH[2]}"
            host="repos/${BASH_REMATCH[1]}"
        elif [[ "$line" =~ \./worktrees/([^/]+)/([^:]+):/newspack-(plugins|themes)/([^[:space:]]+) ]]; then
            safe_branch="${BASH_REMATCH[1]}"
            repo="${BASH_REMATCH[4]}"
            host="${BASH_REMATCH[2]}"
        else
            continue
        fi
        local branch="$safe_branch" found=false i
        for i in "${!comment_repos[@]}"; do
            if [[ "${comment_repos[$i]}" == "$repo" ]]; then
                branch="${comment_branches[$i]}"
                found=true
                break
            fi
        done
        if [[ "$found" != true && "$warned_fallback" != true && "$quiet" != "--quiet" ]]; then
            echo "[env] note: $(basename "$compose_file") lacks newspack-wt metadata; using sanitized branch names" >&2
            warned_fallback=true
        fi
        printf '%s\t%s\t%s\t%s\n' "$repo" "$branch" "$safe_branch" "$host"
    done < <(grep -E '^[[:space:]]*-[[:space:]]+\./worktrees/' "$compose_file")
}

# Roll back worktrees this `env create` attempt created — and only those.
# Reads two arrays populated by the --worktree parser:
#   created_workspace_wts=("<safe_branch>" ...)        tier-1 entries
#   created_standalone_wts=("<repo>/<safe_branch>" ...) tier-2 entries
# Safe to call repeatedly; entries already gone are silently skipped.
cleanup_partial_env_state() {
    local wt
    for wt in "${created_workspace_wts[@]}"; do
        local ws_wt="$NABSPATH/worktrees/$wt"
        if [[ -d "$ws_wt" ]]; then
            (cd "$NABSPATH" && git worktree remove --force "$ws_wt" 2>/dev/null) || true
        fi
    done
    for wt in "${created_standalone_wts[@]}"; do
        local s2_repo="${wt%%/*}"
        local s2_wt="$NABSPATH/worktrees/standalone/$wt"
        if [[ -d "$s2_wt" ]]; then
            (cd "$NABSPATH/repos/$s2_repo" && git worktree remove --force "$s2_wt" 2>/dev/null) || true
        fi
    done
}

case $1 in
    create)
        env_name="$2"
        if [[ -z "$env_name" ]]; then
            echo "Usage: n env create <name> --worktree <repo>:<branch> [--worktree ...] [--domain <domain>] [--up]"
            exit 1
        fi
        validate_env_name "$env_name"
        # Reject names that would collide after dash/underscore normalization.
        normalized=$(echo "$env_name" | tr '-' '_')
        for f in "$NABSPATH"/docker-compose.env-*.yml; do
            [[ -f "$f" ]] || continue
            existing=$(basename "$f" | sed 's/docker-compose\.env-//' | sed 's/\.yml//')
            [[ "$existing" == "$env_name" ]] && continue
            if [[ "$(echo "$existing" | tr '-' '_')" == "$normalized" ]]; then
                echo "Error: '$env_name' conflicts with existing environment '$existing' (same container/database name after normalization)"
                exit 1
            fi
        done
        shift 2
        worktree_volumes=""
        worktree_metadata=""
        # Track worktrees this attempt creates so failure cleanup is scoped.
        created_workspace_wts=()
        created_standalone_wts=()
        domain=""
        auto_up=false
        while [[ $# -gt 0 ]]; do
            case $1 in
                --worktree)
                    if [[ -z "$2" || "$2" == --* ]]; then
                        echo "Error: --worktree requires a value (plugin:branch)"
                        exit 1
                    fi
                    IFS=':' read -r wt_repo wt_branch <<< "$2"
                    validate_name "$wt_repo" "repo"
                    validate_name "$wt_branch" "branch"
                    # Sanitize branch for directory name (feat/foo -> feat-foo).
                    safe_branch=$(echo "$wt_branch" | tr '/' '-')
                    # Resolve tier BEFORE checking worktree existence — avoids
                    # leaking a workspace worktree when the repo name is unknown.
                    wt_host_path=$(get_repo_host_path "$wt_repo")
                    if [[ -z "$wt_host_path" ]]; then
                        echo "Error: unknown project '$wt_repo'"
                        exit 1
                    fi
                    if [[ "$wt_host_path" == repos/* ]]; then
                        # Tier 2: standalone-repo worktree, mounted at /newspack-plugins/<name>
                        # so link-repos.sh picks it up as an active plugin in this env.
                        worktree_dir="./worktrees/standalone/$wt_repo/$safe_branch"
                        wt_container_path="/newspack-plugins/$wt_repo"
                        if [[ ! -d "$NABSPATH/$worktree_dir" ]]; then
                            echo "Creating standalone worktree at branch $wt_branch in $wt_repo..."
                            if ! "$NABSPATH/bin/worktree.sh" add "$wt_branch" --repo "$wt_repo"; then
                                cleanup_partial_env_state
                                exit 1
                            fi
                            created_standalone_wts+=("$wt_repo/$safe_branch")
                        fi
                    else
                        # Tier 1: workspace worktree of the monorepo.
                        if [[ ! -d "$NABSPATH/worktrees/$safe_branch" ]]; then
                            echo "Creating worktree at branch $wt_branch..."
                            if ! "$NABSPATH/bin/worktree.sh" add "$wt_branch"; then
                                cleanup_partial_env_state
                                exit 1
                            fi
                            created_workspace_wts+=("$safe_branch")
                        fi
                        if [[ "$wt_host_path" == themes/* ]]; then
                            wt_container_path="/newspack-themes/$wt_repo"
                        else
                            wt_container_path="/newspack-plugins/$wt_repo"
                        fi
                        worktree_dir="./worktrees/$safe_branch/$wt_host_path"
                    fi
                    worktree_volumes="$worktree_volumes      - $worktree_dir:$wt_container_path
"
                    # Persist original branch + repo so destroy/list don't have to
                    # reconstruct from sanitized paths (commit 5 reads this).
                    worktree_metadata="$worktree_metadata      # newspack-wt: repo=$wt_repo branch=$wt_branch host=$wt_host_path
"
                    shift 2
                    ;;
                --domain)
                    if [[ -z "$2" || "$2" == --* ]]; then
                        echo "Error: --domain requires a value"
                        exit 1
                    fi
                    domain="$2"
                    validate_domain "$domain"
                    shift 2
                    ;;
                --up)
                    auto_up=true
                    shift
                    ;;
                *)
                    echo "Unknown option: $1"
                    exit 1
                    ;;
            esac
        done
        ip=$(next_loopback_ip)
        if [[ -z "$domain" ]]; then
            domain="${env_name}.test"
        fi
        compose_file="$NABSPATH/docker-compose.env-${env_name}.yml"
        container_name=$(echo "newspack_env_${env_name}" | tr '-' '_')
        db_name=$(db_name_for_env "$env_name")
        # Create isolated html directory.
        mkdir -p "$NABSPATH/envs/${env_name}/html"
        cat > "$compose_file" <<YAML
services:
  env-${env_name}:
    container_name: ${container_name}
    platform: linux/arm64
    depends_on:
      - db
    image: newspack-dev:latest
    volumes:
      - ./logs/env-${env_name}/apache2:/var/log/apache2
      - ./logs/env-${env_name}/php:/var/log/php
      - ./bin:/var/scripts
      - .:/newspack-monorepo
      - ./plugins:/newspack-plugins
      - ./themes:/newspack-themes
      - ./repos:/newspack-repos
${worktree_volumes}${worktree_metadata}      - ./envs/${env_name}/html:/var/www/html
      - ./manager-html:/var/www/manager-html
      - ./additional-sites-html:/var/www/additional-sites-html
      - ./snapshots:/snapshots
    ports:
      - "${ip}:80:80"
      - "${ip}:443:443"
    env_file:
      - default.env
      - .env
    environment:
      - HOST_PORT=80
      - MYSQL_DATABASE=${db_name}
      - WP_CACHE_KEY_SALT=env_${env_name}_
      - WP_DOMAIN=${domain}
      - APACHE_RUN_USER=\${USE_CUSTOM_APACHE_USER:-www-data}
    extra_hosts:
      - "host.docker.internal:host-gateway"
    networks:
      default: {}
      newspack_envs:
        aliases:
          - ${domain}
networks:
  newspack_envs:
    external: true
YAML
        echo "Created $compose_file (db: $db_name, domain: $domain, ip: $ip)"
        # Check networking prerequisites (macOS only — Linux routes all 127.x.x.x by default).
        if [[ "$(uname)" == "Darwin" ]] && ! ifconfig lo0 2>/dev/null | grep -q "$ip"; then
            if command -v newspack-manage-host >/dev/null 2>&1; then
                sudo newspack-manage-host alias-add "$ip"
            else
                echo "Note: loopback alias for $ip is missing. Run 'n start' or: sudo ifconfig lo0 alias $ip"
            fi
        fi
        # Custom domains (not IP-based) need a /etc/hosts entry.
        if [[ "$domain" != "$ip" ]] && ! grep -q "[[:space:]]${domain}" /etc/hosts 2>/dev/null; then
            if command -v newspack-manage-host >/dev/null 2>&1 && [[ "$domain" == *.test || "$domain" == *.local ]]; then
                # Passwordless via the locked-down wrapper — works without a TTY.
                sudo newspack-manage-host host-add "$ip" "$domain"
            elif [ -t 0 ] && [ -t 1 ]; then
                read -p "Add $domain to /etc/hosts? (Y/n): " choice
                choice=$(echo "$choice" | tr '[:upper:]' '[:lower:]')
                if [[ "$choice" != "n" ]]; then
                    echo "$ip $domain" | sudo tee -a /etc/hosts > /dev/null
                fi
            else
                echo "Note: add hosts entry before browser access: sudo sh -c 'echo \"$ip $domain\" >> /etc/hosts'"
            fi
        fi
        # Start the environment immediately or prompt.
        if [[ "$auto_up" == true ]]; then
            exec "$NABSPATH/bin/env.sh" up "$env_name"
        elif [ -t 0 ] && [ -t 1 ]; then
            read -p "Start environment now? (Y/n): " choice
            choice=$(echo "$choice" | tr '[:upper:]' '[:lower:]')
            if [[ "$choice" != "n" ]]; then
                exec "$NABSPATH/bin/env.sh" up "$env_name"
            else
                echo "Run: n env up $env_name"
            fi
        else
            echo "Run: n env up $env_name"
        fi
        ;;
    up)
        env_name="$2"
        if [[ -z "$env_name" ]]; then
            echo "Usage: n env up <name> [--build]"
            echo "       n env up --all [--build]"
            exit 1
        fi
        # --all: start all existing environments.
        if [[ "$env_name" == "--all" ]]; then
            shift 2
            pass_args=()
            while [[ $# -gt 0 ]]; do
                pass_args+=("$1"); shift
            done
            started=0
            failed=0
            for f in "$NABSPATH"/docker-compose.env-*.yml; do
                [[ -f "$f" ]] || continue
                name=$(basename "$f" | sed 's/docker-compose\.env-//' | sed 's/\.yml//')
                echo ""
                echo "=== Starting $name ==="
                if "$NABSPATH/bin/env.sh" up "$name" "${pass_args[@]}"; then
                    started=$((started + 1))
                else
                    failed=$((failed + 1))
                fi
            done
            echo ""
            echo "Done: $started started, $failed failed."
            exit 0
        fi
        validate_env_name "$env_name"
        shift 2
        auto_build=false
        while [[ $# -gt 0 ]]; do
            case $1 in
                --build) auto_build=true; shift ;;
                *) echo "Unknown option: $1"; exit 1 ;;
            esac
        done
        compose_file="$NABSPATH/docker-compose.env-${env_name}.yml"
        if [[ ! -f "$compose_file" ]]; then
            echo "Error: environment '$env_name' not found. Run: n env create $env_name ..."
            exit 1
        fi
        container_name=$(echo "newspack_env_${env_name}" | tr '-' '_')
        db_name=$(db_name_for_env "$env_name")
        domain=$(domain_for_env "$compose_file")
        ip=$(ip_for_env "$compose_file")
        # --- Migration: add shared network + domain if missing ---
        if ! grep -q 'newspack_envs' "$compose_file"; then
            # Assign a .test domain if the env is IP-based.
            if [[ "$domain" == "$ip" || -z "$domain" ]]; then
                domain="${env_name}.test"
                # Update WP_DOMAIN in the compose file.
                sed -i '' "s|WP_DOMAIN=${ip}|WP_DOMAIN=${domain}|" "$compose_file" 2>/dev/null || \
                    sed -i "s|WP_DOMAIN=${ip}|WP_DOMAIN=${domain}|" "$compose_file"
            fi
            # Replace the old networks block. All existing env compose files end with:
            #     networks:
            #       - default
            # Remove those two trailing lines, then append the new config.
            # BSD head doesn't support -n -2, so use wc + awk.
            total=$(wc -l < "$compose_file")
            awk -v n="$((total - 2))" 'NR <= n' "$compose_file" > "${compose_file}.tmp" && mv "${compose_file}.tmp" "$compose_file"
            cat >> "$compose_file" <<MIGRATE
    networks:
      default: {}
      newspack_envs:
        aliases:
          - ${domain}
networks:
  newspack_envs:
    external: true
MIGRATE
            echo "Migrated $env_name: added shared network (domain: $domain)"
        fi
        # Re-read domain after potential migration.
        domain=$(domain_for_env "$compose_file")
        # Ensure loopback alias exists (macOS only — Linux routes all 127.x.x.x by default).
        if [[ "$(uname)" == "Darwin" && -n "$ip" && "$ip" != "127.0.0.1" ]] && ! ifconfig lo0 | grep -q "$ip"; then
            if command -v newspack-manage-host >/dev/null 2>&1; then
                sudo newspack-manage-host alias-add "$ip"
            else
                echo "Error: loopback alias for $ip is not set up."
                echo "Run 'n start' to set up networking, or manually: sudo ifconfig lo0 alias $ip"
                exit 1
            fi
        fi
        # Custom domains (not IP-based) need a /etc/hosts entry.
        if [[ -n "$domain" && "$domain" != "$ip" ]] && ! grep -q "[[:space:]]${domain}" /etc/hosts 2>/dev/null; then
            if command -v newspack-manage-host >/dev/null 2>&1 && [[ "$domain" == *.test || "$domain" == *.local ]]; then
                # Passwordless via the locked-down wrapper — works without a TTY.
                sudo newspack-manage-host host-add "$ip" "$domain"
                echo "Added $domain to /etc/hosts"
            elif [ -t 0 ] && [ -t 1 ]; then
                echo "Adding $domain to /etc/hosts (requires sudo)..."
                echo "$ip $domain" | sudo tee -a /etc/hosts > /dev/null
                echo "Added $domain to /etc/hosts"
            else
                echo "Warning: $domain not in /etc/hosts. Browser access won't work until added."
                echo "Run: sudo sh -c 'echo \"$ip $domain\" >> /etc/hosts'"
            fi
        fi
        # Source env files for DB credentials.
        set -a
        source "$NABSPATH/default.env"
        [[ -f "$NABSPATH/.env" ]] && source "$NABSPATH/.env"
        set +a
        # Ensure db is running and create the environment database.
        docker compose -f "$NABSPATH/docker-compose.yml" up -d db
        echo "Creating database $db_name..."
        docker compose -f "$NABSPATH/docker-compose.yml" exec -T db \
            mariadb -h localhost -u root -p"${MYSQL_ROOT_PASSWORD}" \
            -e "CREATE DATABASE IF NOT EXISTS \`${db_name}\`; GRANT ALL PRIVILEGES ON \`${db_name}\`.* TO '${MYSQL_USER}'@'%'; FLUSH PRIVILEGES;" 2>/dev/null
        # Start the env container.
        if ! docker compose -f "$NABSPATH/docker-compose.yml" -f "$compose_file" up -d "env-${env_name}"; then
            echo "Error: failed to start container"
            exit 1
        fi
        # Generate SSL certificate using host mkcert (trusted CA) and copy into container.
        echo "Setting up SSL for $domain..."
        certs_dir="$NABSPATH/envs/${env_name}/certs"
        mkdir -p "$certs_dir"
        if command -v mkcert >/dev/null 2>&1 && [[ ! -f "$certs_dir/${domain}.pem" ]]; then
            (cd "$certs_dir" && mkcert "$domain" 2>/dev/null)
        fi
        if [[ -f "$certs_dir/${domain}.pem" ]]; then
            docker cp "$certs_dir/${domain}.pem" "$container_name":/etc/ssl/certs/${domain}.pem
            docker cp "$certs_dir/${domain}-key.pem" "$container_name":/etc/ssl/certs/${domain}-key.pem
        else
            # Fallback to container-side mkcert (untrusted CA, but functional).
            docker exec "$container_name" /usr/local/bin/ssl "$domain" 2>/dev/null
        fi
        # Update Apache config: replace any ServerName, ServerAdmin, and cert paths with the env domain.
        docker exec "$container_name" bash -c "sed -i \
            -e 's|ServerName .*|ServerName $domain|' \
            -e 's|ServerAdmin .*|ServerAdmin webmaster@$domain|' \
            -e 's|SSLCertificateFile .*|SSLCertificateFile /etc/ssl/certs/${domain}.pem|' \
            -e 's|SSLCertificateKeyFile .*|SSLCertificateKeyFile /etc/ssl/certs/${domain}-key.pem|' \
            /etc/apache2/sites-available/000-default.conf"
        # Auto-install WordPress if not already installed.
        echo "Waiting for WordPress setup..."
        for i in $(seq 1 20); do
            if docker exec "$container_name" test -f /var/www/html/wp-config.php 2>/dev/null; then
                # Check if core tables exist (wp core is-installed returns true even without them).
                if docker exec "$container_name" wp --allow-root db query "SELECT 1 FROM wp_options LIMIT 1" 2>/dev/null | grep -q 1; then
                    echo "WordPress already installed."
                    # Update site URL if domain changed (e.g., migration from IP to .test).
                    current_url=$(docker exec "$container_name" wp --allow-root option get siteurl 2>/dev/null)
                    if [[ -n "$current_url" && "$current_url" != "https://${domain}" ]]; then
                        docker exec "$container_name" wp --allow-root search-replace "$current_url" "https://${domain}" --skip-columns=guid --quiet 2>/dev/null
                        docker exec "$container_name" wp --allow-root cache flush 2>/dev/null
                        echo "Updated site URL: $current_url -> https://${domain}"
                    fi
                    break
                fi
                echo "Installing WordPress..."
                docker exec "$container_name" wp --allow-root cache flush 2>/dev/null
                docker exec "$container_name" wp --allow-root core install \
                    --url="https://${domain}" \
                    --title="${WP_TITLE:-Newspack}" \
                    --admin_user="${WP_ADMIN_USER:-admin}" \
                    --admin_password="${WP_ADMIN_PASSWORD:-password}" \
                    --admin_email="${WP_ADMIN_EMAIL:-wordpress@example.com}" \
                    --skip-email
                break
            fi
            sleep 3
        done
        if ! docker exec "$container_name" wp --allow-root core is-installed 2>/dev/null; then
            echo "Warning: WordPress may not be fully installed. Run 'n env up $env_name' to retry."
        elif docker exec "$container_name" test -f /var/www/html/wp-config.php 2>/dev/null; then
            # Ensure pretty permalinks work. A fresh isolated env installs with WP's
            # default (plain) permalink structure, so the .htaccess rewrite block stays
            # empty — and a later `wp rewrite flush --hard` with an empty structure
            # rewrites .htaccess down to a bare "# BEGIN/END WordPress" marker, 404-ing
            # every pretty permalink. Set a structure and flush --hard so the rewrite
            # rules are written out, then hand the file back to the Apache user so WP can
            # keep it updated. The main-site path does this via site-setup.sh; envs skip
            # that and install WordPress directly, so it has to happen here too.
            run_user="${USE_CUSTOM_APACHE_USER:-www-data}"
            docker exec "$container_name" wp --allow-root rewrite structure '/%year%/%monthnum%/%day%/%postname%/' --hard >/dev/null 2>&1
            docker exec "$container_name" wp --allow-root rewrite flush --hard >/dev/null 2>&1
            docker exec "$container_name" chown "$run_user":"$run_user" /var/www/html/.htaccess 2>/dev/null || true
        fi
        # Reload Apache to pick up SSL config (it's running by now).
        docker exec "$container_name" apachectl graceful 2>/dev/null
        echo "Environment '$env_name' is ready at https://${domain}/"
        # Copy built assets from canonical source into mounted worktrees.
        if [[ "$auto_build" == true ]]; then
            while IFS=$'\t' read -r repo _branch safe_branch host; do
                src="$NABSPATH/$host"
                if [[ "$host" == repos/* ]]; then
                    dst="$NABSPATH/worktrees/standalone/$repo/$safe_branch"
                else
                    dst="$NABSPATH/worktrees/$safe_branch/$host"
                fi
                echo "Copying built assets for $repo..."
                for dir in node_modules vendor dist build; do
                    if [[ -d "$src/$dir" ]]; then
                        cp -al "$src/$dir" "$dst/$dir" 2>/dev/null || cp -a "$src/$dir" "$dst/$dir"
                    fi
                done
            done < <(parse_env_worktrees "$compose_file")
        fi
        ;;
    down)
        env_name="$2"
        if [[ -z "$env_name" ]]; then
            echo "Usage: n env down <name>"
            exit 1
        fi
        validate_env_name "$env_name"
        container_name=$(echo "newspack_env_${env_name}" | tr '-' '_')
        docker stop "$container_name" 2>/dev/null
        docker rm "$container_name" 2>/dev/null
        ;;
    destroy)
        env_name="$2"
        if [[ -z "$env_name" ]]; then
            echo "Usage: n env destroy <name>"
            exit 1
        fi
        validate_env_name "$env_name"
        compose_file="$NABSPATH/docker-compose.env-${env_name}.yml"
        container_name=$(echo "newspack_env_${env_name}" | tr '-' '_')
        db_name=$(db_name_for_env "$env_name")
        # Read domain, IP, and worktrees before removing compose file.
        domain=""
        ip=""
        # Read worktree tuples (repo, branch, safe_branch, host) before
        # removing the compose file. Each tuple drives one worktree.sh remove.
        worktree_tuples=()
        if [[ -f "$compose_file" ]]; then
            domain=$(domain_for_env "$compose_file")
            ip=$(ip_for_env "$compose_file")
            while IFS= read -r tuple; do
                worktree_tuples+=("$tuple")
            done < <(parse_env_worktrees "$compose_file")
        fi
        docker stop "$container_name" 2>/dev/null
        docker rm "$container_name" 2>/dev/null
        # Drop the environment database via docker compose (avoids hardcoding container name).
        set -a
        source "$NABSPATH/default.env"
        [[ -f "$NABSPATH/.env" ]] && source "$NABSPATH/.env"
        set +a
        docker compose -f "$NABSPATH/docker-compose.yml" up -d db 2>/dev/null
        docker compose -f "$NABSPATH/docker-compose.yml" exec -T db \
            mariadb -h localhost -u root -p"${MYSQL_ROOT_PASSWORD}" \
            -e "DROP DATABASE IF EXISTS \`${db_name}\`" 2>/dev/null
        echo "Dropped database $db_name"
        # Remove env html and certs directories.
        if [[ -d "$NABSPATH/envs/${env_name}" ]]; then
            rm -rf "$NABSPATH/envs/${env_name}"
            echo "Removed envs/${env_name}/"
        fi
        # Remove log directories.
        if [[ -d "$NABSPATH/logs/env-${env_name}" ]]; then
            rm -rf "$NABSPATH/logs/env-${env_name}"
            echo "Removed logs/env-${env_name}/"
        fi
        # Remove /etc/hosts entry (only for custom domains, not IP-based).
        if [[ -n "$domain" && "$domain" != "$ip" ]] && grep -q "$domain" /etc/hosts 2>/dev/null; then
            if command -v newspack-manage-host >/dev/null 2>&1 && [[ "$domain" == *.test || "$domain" == *.local ]]; then
                sudo newspack-manage-host host-remove "$domain"
            else
                escaped_domain="${domain//./\\.}"
                { sudo sed -i '' "/[[:space:]]${escaped_domain}$/d" /etc/hosts 2>/dev/null || \
                  sudo sed -i "/[[:space:]]${escaped_domain}$/d" /etc/hosts; }
            fi
            echo "Removed $domain from /etc/hosts"
        fi
        # Remove compose file before worktrees so worktree.sh doesn't see them as env-bound.
        rm -f "$compose_file"
        # Remove worktrees that were mounted by this environment.
        for tuple in "${worktree_tuples[@]}"; do
            IFS=$'\t' read -r wt_repo wt_branch _safe wt_host <<< "$tuple"
            if [[ "$wt_host" == repos/* ]]; then
                "$NABSPATH/bin/worktree.sh" remove --yes "$wt_branch" --repo "$wt_repo"
            else
                "$NABSPATH/bin/worktree.sh" remove --yes "$wt_branch"
            fi
        done
        echo "Destroyed environment '$env_name'"
        ;;
    list)
        porcelain=false
        if [[ "$2" == "--porcelain" ]]; then
            porcelain=true
        fi
        [[ "$porcelain" == false ]] && echo "Environments:"
        for f in "$NABSPATH"/docker-compose.env-*.yml; do
            [[ -f "$f" ]] || continue
            name=$(basename "$f" | sed 's/docker-compose\.env-//' | sed 's/\.yml//')
            container_name=$(echo "newspack_env_${name}" | tr '-' '_')
            domain=$(domain_for_env "$f")
            if status=$(docker inspect -f '{{.State.Status}}' "$container_name" 2>/dev/null); then
                :
            else
                status="stopped"
            fi
            # Collect worktrees as repo:branch pairs via the shared helper.
            worktrees=""
            worktree_pairs=()
            while IFS=$'\t' read -r repo branch _safe _host; do
                pair="${repo}:${branch}"
                worktree_pairs+=("$pair")
                [[ -n "$worktrees" ]] && worktrees="${worktrees},"
                worktrees="${worktrees}${pair}"
            done < <(parse_env_worktrees "$f" --quiet)
            if [[ "$porcelain" == true ]]; then
                printf '%s\t%s\thttps://%s/\t%s\n' "$name" "$status" "$domain" "$worktrees"
            else
                echo "  $name ($status) https://${domain}/"
                for pair in "${worktree_pairs[@]}"; do
                    nm="${pair%:*}"
                    br="${pair#*:}"
                    echo "    └ $nm ($br)"
                done
            fi
        done
        ;;
    cleanup)
        shift
        cleanup_all=false
        cleanup_yes=false
        while [[ $# -gt 0 ]]; do
            case $1 in
                --all) cleanup_all=true; shift ;;
                --yes) cleanup_yes=true; shift ;;
                *) echo "Usage: n env cleanup [--all] [--yes]"; exit 1 ;;
            esac
        done
        envs=()
        for f in "$NABSPATH"/docker-compose.env-*.yml; do
            [[ -f "$f" ]] || continue
            name=$(basename "$f" | sed 's/docker-compose\.env-//' | sed 's/\.yml//')
            envs+=("$name")
        done
        if [[ ${#envs[@]} -eq 0 ]]; then
            echo "No environments to clean up."
            exit 0
        fi
        # --all: skip interactive selection (select all for removal).
        # --yes: skip final confirmation prompt.
        if [[ "$cleanup_all" != true ]]; then
            if ! [ -t 0 ] || ! [ -t 1 ]; then
                echo "Interactive mode requires a terminal. Use --all --yes for non-interactive cleanup."
                exit 1
            fi
            # Interactive toggle loop.
            keep_flags=()
            for i in "${!envs[@]}"; do keep_flags[$i]=false; done
            while true; do
                echo ""
                echo "Environments (marked for REMOVAL unless toggled):"
                for i in "${!envs[@]}"; do
                    name="${envs[$i]}"
                    container_name=$(echo "newspack_env_${name}" | tr '-' '_')
                    domain=$(domain_for_env "$NABSPATH/docker-compose.env-${name}.yml")
                    status="stopped"
                    docker inspect -f '{{.State.Status}}' "$container_name" >/dev/null 2>&1 && \
                        status=$(docker inspect -f '{{.State.Status}}' "$container_name" 2>/dev/null)
                    if [[ "${keep_flags[$i]}" == true ]]; then
                        echo "  $((i+1)). [KEEP]    $name ($status) https://${domain}/"
                    else
                        echo "  $((i+1)). [REMOVE]  $name ($status) https://${domain}/"
                    fi
                done
                echo ""
                echo "Enter a number to toggle, 'a' to select all for removal, or 'delete' to proceed:"
                read -p "> " choice
                if [[ "$choice" == "delete" ]]; then
                    break
                elif [[ "$choice" == "a" ]]; then
                    for i in "${!envs[@]}"; do keep_flags[$i]=false; done
                elif [[ "$choice" =~ ^[0-9]+$ ]] && [[ "$choice" -ge 1 && "$choice" -le ${#envs[@]} ]]; then
                    idx=$((choice-1))
                    if [[ "${keep_flags[$idx]}" == true ]]; then
                        keep_flags[$idx]=false
                    else
                        keep_flags[$idx]=true
                    fi
                fi
            done
            to_remove=()
            for i in "${!envs[@]}"; do
                [[ "${keep_flags[$i]}" != true ]] && to_remove+=("${envs[$i]}")
            done
        else
            to_remove=("${envs[@]}")
        fi
        if [[ ${#to_remove[@]} -eq 0 ]]; then
            echo "Nothing to remove."
            exit 0
        fi
        echo "Will destroy: ${to_remove[*]}"
        if [[ "$cleanup_yes" != true ]]; then
            read -p "Confirm? (y/N): " confirm
            if [[ ! "$confirm" =~ ^[Yy]$ ]]; then
                echo "Aborted."
                exit 0
            fi
        fi
        for name in "${to_remove[@]}"; do
            echo ""
            echo "--- Destroying $name ---"
            "$NABSPATH/bin/env.sh" destroy "$name"
        done
        ;;
    e2e-setup)
        shift
        exec "$NABSPATH/bin/setup-local-e2e.sh" "$@"
        ;;
    *)
        echo "Usage: n env <create|up|down|destroy|list|cleanup|e2e-setup>"
        echo "  up <name> [--build]      Start an environment"
        echo "  up --all [--build]       Start all environments"
        echo "  cleanup [--all] [--yes]  Remove environments (--all selects everything, --yes skips confirmation)"
        echo "  e2e-setup <name> [opts]  Build a ready-to-run local e2e-tests environment (see --help)"
        ;;
esac
