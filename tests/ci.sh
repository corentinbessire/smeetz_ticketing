#!/usr/bin/env bash
#
# Runs the module's checks against a Drupal site that has it installed at
# web/modules/contrib/smeetz_ticketing. GitHub Actions and tests/local.sh both
# call it, so CI and a local run check the same things.
#
# Usage: tests/ci.sh <site root> <step>
#
# Steps:
#   lint   PHPCS (Drupal, DrupalPractice), PHPStan (phpstan.neon), JS syntax.
#   test   PHPUnit: unit and kernel tests (SIMPLETEST_DB must be set).
#   all    lint, then test.

set -uo pipefail

site_root="$(cd "$1" && pwd)"
step="${2:-all}"
module=web/modules/contrib/smeetz_ticketing

cd "$site_root" || exit 1

lint() {
  local status=0
  echo "::group::PHPCS"
  php vendor/bin/phpcs -p --colors \
    --standard="Drupal,DrupalPractice" \
    --extensions="php,inc,module,install,yml,twig" \
    --ignore="$module/vendor/*" \
    "$module" || status=1
  echo "::endgroup::"
  echo "::group::PHPStan"
  php vendor/bin/phpstan analyse --no-progress \
    --configuration "$module/phpstan.neon" \
    --memory-limit 2G || status=1
  echo "::endgroup::"
  echo "::group::JavaScript syntax"
  if command -v node >/dev/null; then
    node --check "$module/js/smeetz-ticketing.js" || status=1
  else
    echo "node not found: skipped."
  fi
  echo "::endgroup::"
  return $status
}

test_step() {
  php vendor/bin/phpunit --no-progress --colors=always \
    --bootstrap web/core/tests/bootstrap.php "$module"
}

case "$step" in
  lint) lint ;;
  test) test_step ;;
  all) lint; l=$?; test_step; t=$?; exit $((l || t)) ;;
  *) echo "Unknown step: $step" >&2; exit 2 ;;
esac
