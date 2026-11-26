#!/usr/bin/env bash
# deploy.sh is installed as two forced commands, one per key:
#   command="/opt/acp/bin/deploy.sh staging" ...  and  command="/opt/acp/bin/deploy.sh prod" ...
# Each key may only deploy its own environment.
set -uo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
sha=0123456789abcdef0123456789abcdef01234567
fail=0
expect() {
  local name="$1" want="$2" bound="$3" cmd="$4"
  out="$(DEPLOY_DRY_RUN=1 ACP_ROOT=/opt/acp IMAGE_REPO=ghcr.io/o/r SSH_ORIGINAL_COMMAND="$cmd" bash "$here/deploy.sh" $bound 2>&1)"
  got=$?
  if [ "$got" = "$want" ]; then echo "ok   - $name"; else echo "FAIL - $name: exit $got, want $want ($out)"; fail=1; fi
}
expect "staging key deploys staging"      0 staging "deploy staging $sha"
expect "prod key deploys prod"            0 prod    "deploy prod $sha"
expect "staging key cannot deploy prod"   2 staging "deploy prod $sha"
expect "prod key cannot deploy staging"   2 prod    "deploy staging $sha"
expect "missing bound env rejected"       2 ""      "deploy staging $sha"
expect "unknown bound env rejected"       2 dev     "deploy dev $sha"
expect "short sha rejected"               2 prod    "deploy prod abc"
expect "uppercase sha rejected"           2 staging "deploy staging ${sha^^}"
expect "command chaining rejected"        2 staging "deploy staging $sha; rm -rf /"
expect "extra args rejected"              2 staging "deploy staging $sha extra"
expect "empty rejected"                   2 staging ""
expect "other command rejected"           2 staging "bash"

out="$(DEPLOY_DRY_RUN=1 ACP_ROOT=/opt/acp IMAGE_REPO=ghcr.io/o/r SSH_ORIGINAL_COMMAND="deploy staging $sha" bash "$here/deploy.sh" staging)"
case "$out" in
  *"APP_IMAGE=ghcr.io/o/r:$sha"*"-p acp-staging"*"/opt/acp/staging/.env"*) echo "ok   - dry run shows compose command";;
  *) echo "FAIL - dry run output: $out"; fail=1;;
esac
exit $fail
