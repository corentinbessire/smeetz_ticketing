# Changelog

All notable changes to this module. Versions follow [Semantic Versioning](https://semver.org/).

## Unreleased

### Added

- **Smeetz ticket** field type: product ID, optional option UUID, and
  availability columns for the availability sync.
- **Smeetz booking button** formatter: a link to the Smeetz booking page that
  opens the official Smeetz widget over the page. Passes a date field of the
  entity as the day to show, and the page language.
- The Smeetz loader is added on the first click only, once per page. If it
  fails or is blocked, the Smeetz booking page opens in a new tab.
- `Drupal.dagdaSmeetz.consent()`: a hook for a consent manager to ask before
  Smeetz loads.
- Focus moves into the widget when it opens and back to the button when it
  closes (Smeetz leaves it on the page behind).
- Settings form at `/admin/config/services/smeetz` (environment, group ID).
- French translation.
