#!/usr/bin/env bash
# Deploy Clipper Studio at a given commit. Run on the VPS as `deploy`:
#   /srv/clipper/deploy.sh <40-char git sha>
# Exit 0 = new version is up and /up answers 200. Non-zero = failed (see output).
# Optional env: CLIPPER_DIR (default /srv/clipper), CLIPPER_PORT (default 8095),
# COMPOSE_PROJECT_NAME (default clipper). Safe to re-run with the same sha.
# Order follows spec §4.4; failures before `up` leave the old version running.

# Everything lives in main() so bash parses the whole script before
# `git checkout` replaces this file on disk.
main() {
    set -euo pipefail

    local sha=${1:-}
    if [[ $# -ne 1 || ! $sha =~ ^[0-9a-f]{40}$ ]]; then
        echo "usage: $(basename "$0") <40-char lowercase hex git sha>" >&2
        exit 2
    fi

    CLIPPER_DIR=${CLIPPER_DIR:-/srv/clipper}
    CLIPPER_PORT=${CLIPPER_PORT:-8095}
    COMPOSE_PROJECT_NAME=${COMPOSE_PROJECT_NAME:-clipper}
    export CLIPPER_PORT COMPOSE_PROJECT_NAME
    export APP_SHA=$sha

    local model_url=https://huggingface.co/ggerganov/whisper.cpp/resolve/main/ggml-base.bin
    local backup_dir=$CLIPPER_DIR/backups
    local keep_backups=7 keep_images=3

    MODEL_TMP=
    trap 'if [[ -n $MODEL_TMP ]]; then rm -rf "$MODEL_TMP"; fi' EXIT

    cd "$CLIPPER_DIR"
    if [[ ! -f .env ]]; then
        die "$CLIPPER_DIR/.env not found"
    fi
    if ! grep -Eq '^DB_PASSWORD=.+' .env; then
        die "DB_PASSWORD is empty or missing in $CLIPPER_DIR/.env"
    fi
    # /up does not need APP_KEY, so the health check would pass without one
    # while every real page returns 500. Refuse before touching anything.
    if ! grep -Eq '^APP_KEY=.+' .env; then
        die "APP_KEY is empty or missing in $CLIPPER_DIR/.env"
    fi

    # One deploy at a time per project (manual run vs. CI).
    exec 9>"/tmp/clipper-deploy-$COMPOSE_PROJECT_NAME.lock"
    flock -n 9 || die "another deploy of $COMPOSE_PROJECT_NAME is running"

    log "checkout $sha"
    git fetch --quiet origin
    git cat-file -e "$sha^{commit}" 2>/dev/null || die "commit $sha not found after fetch"
    git checkout --force --quiet "$sha"

    ensure_whisper_model "$model_url"

    if docker image inspect "clipper-app:$sha" >/dev/null 2>&1; then
        log "image clipper-app:$sha already exists, skipping build"
    else
        log "build clipper-app:$sha"
        dc build app
    fi

    log "start postgres"
    dc up -d --wait --wait-timeout 120 postgres

    backup_db "$backup_dir" "$sha" "$keep_backups"

    log "start app + scheduler"
    dc up -d --no-deps app scheduler

    log "migrate"
    dc run --rm --no-deps app php artisan migrate --force

    health_check

    log "start queue (waits for a running job to finish, up to 65m)"
    dc up -d queue

    prune_images "$keep_images"
    log "deploy $sha OK"
}

log() { printf '[deploy %s] %s\n' "$(date -u +%H:%M:%S)" "$*"; }
die() { printf '[deploy] ERROR: %s\n' "$*" >&2; exit 1; }

dc() {
    docker compose -p "$COMPOSE_PROJECT_NAME" -f "$CLIPPER_DIR/docker-compose.prod.yml" \
        --env-file "$CLIPPER_DIR/.env" "$@"
}

# Download the whisper model into the whisper-models volume once.
# Host curl -> temp file -> copy to .part in the volume -> size check -> rename.
ensure_whisper_model() {
    local url=$1 tmp size
    if dc run --rm --no-deps -T whisper-model 'test -s /models/ggml-base.bin'; then
        log "whisper model present"
        return 0
    fi
    log "download whisper model ggml-base.bin"
    tmp=$(mktemp -d "${TMPDIR:-/tmp}/clipper-model.XXXXXX")
    MODEL_TMP=$tmp   # removed by the EXIT trap, also on failure
    curl -fL --retry 3 --silent --show-error -o "$tmp/ggml-base.bin" "$url"
    size=$(stat -c %s "$tmp/ggml-base.bin")
    [[ $size -gt 1000000 ]] || die "downloaded model is too small ($size bytes)"
    chmod 0755 "$tmp"
    chmod 0644 "$tmp/ggml-base.bin"
    dc run --rm --no-deps -T -v "$tmp:/src:ro" whisper-model \
        "cp /src/ggml-base.bin /models/.ggml-base.bin.part \
         && [ \"\$(stat -c %s /models/.ggml-base.bin.part)\" = $size ] \
         && chmod 0644 /models/.ggml-base.bin.part \
         && mv /models/.ggml-base.bin.part /models/ggml-base.bin"
    rm -rf "$tmp"
    MODEL_TMP=
    log "whisper model installed ($size bytes)"
}

# pg_dump to backups/<timestamp>-<sha>.sql.gz, keep the newest $3.
backup_db() {
    local dir=$1 sha=$2 keep=$3 file
    mkdir -p "$dir"
    chmod 0700 "$dir"
    file="$dir/$(date -u +%Y%m%dT%H%M%SZ)-$sha.sql.gz"
    log "backup -> $file"
    # shellcheck disable=SC2016  # $POSTGRES_* expand inside the container
    if ! dc exec -T postgres sh -c 'pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB"' \
        | gzip > "$file.part"; then
        rm -f "$file.part"
        die "pg_dump failed; deploy stopped, previous version still running"
    fi
    chmod 0600 "$file.part"
    mv "$file.part" "$file"
    find "$dir" -maxdepth 1 -type f -name '*.sql.gz' | sort -r | tail -n +"$((keep + 1))" \
        | while IFS= read -r old; do
            log "remove old backup $(basename "$old")"
            rm -f -- "$old"
        done
}

health_check() {
    local url="http://127.0.0.1:$CLIPPER_PORT/up" code deadline=$((SECONDS + 30))
    log "health check $url"
    while ((SECONDS < deadline)); do
        code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 5 "$url" || true)
        if [[ $code == 200 ]]; then
            log "health OK (200)"
            return 0
        fi
        sleep 2
    done
    echo "[deploy] ERROR: $url did not return 200 within 30s (last: ${code:-none})" >&2
    dc logs --tail 50 app >&2 || true
    exit 1
}

# Keep the $1 newest clipper-app:<sha> tags. Only 40-hex tags are considered,
# tags used by any container (running or not) are never removed, and no
# other image is touched.
prune_images() {
    local keep=$1 tag
    docker image ls clipper-app --format '{{.Tag}}' \
        | grep -E '^[0-9a-f]{40}$' \
        | while IFS= read -r tag; do
            printf '%s %s\n' "$(docker image inspect -f '{{.Created}}' "clipper-app:$tag")" "$tag"
        done \
        | sort -r | tail -n +"$((keep + 1))" | cut -d' ' -f2 \
        | while IFS= read -r tag; do
            # ancestor= matches by image ID, so retagged/aliased images count too
            if [[ -n $(docker ps -aq --filter "ancestor=clipper-app:$tag") ]]; then
                log "keep clipper-app:$tag (used by a container)"
                continue
            fi
            log "remove image clipper-app:$tag"
            docker image rm "clipper-app:$tag" >/dev/null || log "could not remove clipper-app:$tag"
        done
}

main "$@"
