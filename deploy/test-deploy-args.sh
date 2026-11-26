#!/usr/bin/env bash
# Checks that deploy.sh accepts only "deploy <staging|prod> <40-hex sha>".
set -uo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
sha=0123456789abcdef0123456789abcdef01234567
fail=0
expect() {
  local name="$1" want="$2" cmd="$3"
  out="$(DEPLOY_DRY_RUN=1 ACP_ROOT=/opt/acp IMAGE_REPO=ghcr.io/o/r SSH_ORIGINAL_COMMAND="$cmd" bash "$here/deploy.sh" 2>&1)"
  got=$?
  if [ "$got" = "$want" ]; then echo "ok   - $name"; else echo "FAIL - $name: exit $got, want $want ($out)"; fail=1; fi
}
expect "valid staging"            0 "deploy staging $sha"
expect "valid prod"               0 "deploy prod $sha"
expect "short sha rejected"       2 "deploy prod abc"
expect "unknown env rejected"     2 "deploy dev $sha"
expect "uppercase sha rejected"   2 "deploy staging ${sha^^}"
expect "command chaining rejected" 2 "deploy staging $sha; rm -rf /"
expect "extra args rejected"      2 "deploy staging $sha extra"
expect "empty rejected"           2 ""
expect "other command rejected"   2 "bash"

out="$(DEPLOY_DRY_RUN=1 ACP_ROOT=/opt/acp IMAGE_REPO=ghcr.io/o/r SSH_ORIGINAL_COMMAND="deploy staging $sha" bash "$here/deploy.sh")"
case "$out" in
  *"APP_IMAGE=ghcr.io/o/r:$sha"*"-p acp-staging"*"/opt/acp/staging/.env"*) echo "ok   - dry run shows compose command";;
  *) echo "FAIL - dry run output: $out"; fail=1;;
esac
exit $fail
