# Changelog

All notable changes to this module. Versions follow [Semantic Versioning](https://semver.org/).

## Unreleased

### Added

- **Ask before loading Smeetz** setting: the first booking click asks whether
  to book on the page (Smeetz loads) or on smeetz.com, and the choice is
  remembered in the browser. Optional privacy policy link in the question.
- **Smeetz: booking choice** block: a button that forgets the choice and the
  data Smeetz stored on the site.
- `Drupal.smeetzTicketing.consent` may resolve NULL to cancel the click.

### Upgrade

- Run `drush updatedb`: it adds the two new settings, off.

## 0.1.0 - 2026-11-11

### Added

- **Smeetz ticket** field type: product ID, optional option UUID, and
  availability columns for the availability sync.
- **Smeetz booking button** formatter: a link to the Smeetz booking page that
  opens the official Smeetz widget over the page. Passes a date field of the
  entity as the day to show, and the page language.
- The Smeetz loader is added on the first click only, once per page. If it
  fails or is blocked, the Smeetz booking page opens in a new tab.
- `Drupal.smeetzTicketing.consent()`: a hook for a consent manager to ask before
  Smeetz loads.
- Focus moves into the widget when it opens and back to the button when it
  closes (Smeetz leaves it on the page behind).
- Settings form at `/admin/config/services/smeetz` (environment, group ID).
- Availability sync: cron (or `drush smeetz:sync`) asks Smeetz which
  sessions are sold out, in one bulk check, and the button shows a "Sold out"
  label instead. Statuses older than a day are ignored. Status report entry.
- Field settings: the session date the sync matches slots on, and an
  optional list field kept in step with the availability.
- French translation.
