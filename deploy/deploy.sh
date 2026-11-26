#!/usr/bin/env bash
# Forced command for the `acp-deploy` SSH key (see deploy/server-setup.md):
#   command="/opt/acp/bin/deploy.sh",no-port-forwarding,no-agent-forwarding,no-pty,no-X11-forwarding ssh-ed25519 ...
# Only accepts: deploy <staging|prod> <40-hex git sha>
set -euo pipefail

ACP_ROOT="${ACP_ROOT:-/opt/acp}"
IMAGE_REPO="${IMAGE_REPO:-ghcr.io/yoosook75/ai-collab-pipeline}"
cmd="${SSH_ORIGINAL_COMMAND:-}"

if [[ ! "$cmd" =~ ^deploy\ (staging|prod)\ ([0-9a-f]{40})$ ]]; then
  echo "rejected: expected 'deploy <staging|prod> <sha>'" >&2
  exit 2
fi
env_name="${BASH_REMATCH[1]}"
sha="${BASH_REMATCH[2]}"

env_file="$ACP_ROOT/$env_name/.env"
project="acp-$env_name"
image="$IMAGE_REPO:$sha"
compose=(docker compose -p "$project" --env-file "$env_file" -f "$ACP_ROOT/compose.yml")

if [ "${DEPLOY_DRY_RUN:-0}" = "1" ]; then
  echo "ACP_ENV_FILE=$env_file APP_IMAGE=$image ${compose[*]} pull && up -d --remove-orphans"
  exit 0
fi

export ACP_ENV_FILE="$env_file" APP_IMAGE="$image"

# Keep the previously deployed sha for manual rollback.
if [ -f "$ACP_ROOT/$env_name/last_sha" ]; then
  cp "$ACP_ROOT/$env_name/last_sha" "$ACP_ROOT/$env_name/previous_sha"
fi

"${compose[@]}" pull web worker scheduler
"${compose[@]}" up -d --remove-orphans

for _ in $(seq 1 30); do
  if "${compose[@]}" exec -T web curl -fsS -o /dev/null http://localhost:8080/up; then
    echo "$sha" > "$ACP_ROOT/$env_name/last_sha"
    echo "deployed $env_name $sha"
    exit 0
  fi
  sleep 3
done

echo "health check failed for $env_name $sha" >&2
exit 3
