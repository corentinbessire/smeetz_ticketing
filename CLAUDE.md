# dagda-smeetz: agent context

Reusable Drupal 11 module (`corentinbessire/dagda_smeetz`, type
`drupal-module`, installed at `web/modules/contrib/dagda_smeetz/`) for Smeetz
ticketing. Read `README.md` first.

## Rules

- **No project-specific code.** No field names, templates or strings of a
  client site. All UI strings carry `['context' => 'Smeetz']` and ship in
  `translations/fr.po`.
- Plane: work items live in the project that needs them (first user: FRA,
  Rencontres de l'Aventure, FRA-5 and FRA-6).
- Code follows the `dagda:drupal-developer` skill: attributes, OOP hooks,
  services behind interfaces, `#[RunTestsInSeparateProcesses]` on kernel
  tests, PHPCS Drupal + DrupalPractice and PHPStan (level in `phpstan.neon`)
  clean.
- Never load anything from Smeetz before a click: the loader tracks visitors.
- Secrets (the Smeetz API key) come from `$settings['dagda_smeetz']`, never
  from config.

## Workflow

- Branch from `main`; Conventional Commits (`feat(button): …`).
- Run `tests/local.sh` before pushing. CI runs on pull requests only.
- Tag `vX.Y.Z` after merging, with the CHANGELOG entry as the release notes.
- To try a branch in a project: `composer require corentinbessire/dagda_smeetz:dev-<branch>`.
