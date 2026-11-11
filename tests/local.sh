#!/usr/bin/env bash
#
# Runs the CI checks on your machine, in a throwaway DDEV Quickstart that has
# this working tree installed in place of a released version. Uncommitted
# changes are included.
#
# Usage: tests/local.sh [--refresh] [step]
#
#   step       lint, test or all (default: all). See tests/ci.sh.
#   --refresh  Pull the Quickstart's latest main and reinstall its packages.
#
# The site lives in $DAGDA_CI_SITE (default ~/.cache/dagda-smeetz-ci) as the
# DDEV project "dagda-smeetz-ci". The first run clones the Quickstart and
# builds it (a few minutes); later runs reuse it and only copy this tree in.
# `ddev delete -Oy dagda-smeetz-ci` and removing the directory clean it up.

set -euo pipefail

package_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
site="${DAGDA_CI_SITE:-$HOME/.cache/dagda-smeetz-ci}"
quickstart_repo="${QUICKSTART_REPO:-git@github.com:corentinbessire/quickstart.git}"
project=dagda-smeetz-ci
module_path=web/modules/contrib/dagda_smeetz

refresh=0
if [ "${1:-}" = "--refresh" ]; then
  refresh=1
  shift
fi
step="${1:-all}"

if ! command -v ddev >/dev/null; then
  echo "DDEV is required: https://ddev.com" >&2
  exit 1
fi

copy_package() {
  rsync -a --delete --exclude=.git --exclude=vendor --exclude=composer.lock \
    "$package_root/" "$site/$1/"
  ddev mutagen sync >/dev/null 2>&1 || true
}

if [ ! -d "$site/.git" ]; then
  mkdir -p "$(dirname "$site")"
  git clone --depth 1 "$quickstart_repo" "$site"
  refresh=1
elif [ $refresh -eq 1 ]; then
  git -C "$site" fetch --depth 1 origin main
  git -C "$site" reset --hard origin/main
fi

cd "$site"

if [ $refresh -eq 1 ]; then
  ddev config --project-name="$project" --webimage-extra-packages="" >/dev/null
  ddev start -y
  ddev composer install --prefer-dist --no-progress --no-interaction
  copy_package .dagda_smeetz
  ddev composer config repositories.dagda-smeetz \
    '{"type": "path", "url": ".dagda_smeetz", "options": {"symlink": false, "versions": {"corentinbessire/dagda_smeetz": "dev-local"}}}'
  ddev composer require corentinbessire/dagda_smeetz:dev-local \
    --with-all-dependencies --no-progress --no-interaction
else
  ddev start -y >/dev/null
fi

copy_package "$module_path"
ddev exec bash "$module_path/tests/ci.sh" /var/www/html "$step"
