# Dagda Smeetz

Book [Smeetz](https://www.smeetz.com/) tickets from a Drupal 11 site. The
module adds a **Smeetz ticket** field and a **Smeetz booking button** that
opens the official Smeetz booking widget over the page, for that entity's
product and date.

Nothing in it is specific to one site: add the field to any content type.

## How it behaves

- The button is a link to the product's Smeetz booking page. Without
  JavaScript, or while no group ID is set, it opens that page in a new tab.
- With a group ID set, a click loads the Smeetz loader, then opens the widget.
  **Nothing from Smeetz loads before the first click**, and it loads once per
  page.
- If the loader fails or is blocked (ad blocker), the booking page opens in a
  new tab instead.
- Focus moves into the widget when it opens and back to the button when the
  visitor closes it.
- The widget opens in the page language and, if a date field is chosen, on
  that day.

## Install

The module is not on drupal.org. Add its repository, then require it:

```bash
composer config repositories.dagda-smeetz \
  '{"type": "vcs", "url": "git@github.com:corentinbessire/dagda-smeetz.git", "no-api": true}'
composer require corentinbessire/dagda_smeetz
drush en dagda_smeetz
```

It installs to `web/modules/contrib/dagda_smeetz`.

## Set up

1. **Configuration → Web services → Smeetz**
   (`/admin/config/services/smeetz`, permission *Administer Smeetz*):
   - **Environment**: *Test* while building, *Live* at launch.
   - **Group ID**: the organiser ID, a number. It is the `gId` in the embed
     code of any widget generated in the Smeetz back office.
2. Add a **Smeetz ticket** field to a content type.
3. In **Manage display**, use the **Smeetz booking button** formatter:
   - **Button label**: translatable with Configuration Translation.
   - **Date passed to Smeetz**: a date, date range, Smart Date or timestamp
     field of the same entity. Smeetz opens on that day.
   - **Open the widget as an overlay**: on by default.

### Where editors find the IDs

- **Product ID**: in the Smeetz back office, the number in the address of the
  product page. Content sold on one ticket shares the same product ID.
- **Option UUID** (optional): the ID of a ticket option, only needed for the
  availability sync.

## Consent

The Smeetz loader sets cookies as soon as it runs (checked on the test
environment, November 2026):

| Where | Name | What |
|---|---|---|
| The site's domain | `__smtz_uid` | Smeetz visitor ID |
| `smeetz.com` | `__smtz_id` | Tracking ID, expires in 200 years |
| `smeetz.com` | `AWSALB`, `AWSALBCORS` | Load balancer, 7 days |
| localStorage | `smtz-tid` | Tracking ID |
| sessionStorage | `smtz-tracker` | Tracker state |

It also sends analytics to Mixpanel. The widget itself loads Google reCAPTCHA
and Klarna, and runs the organiser's Facebook Pixel and Google Tag Manager if
they are set up in Smeetz. A site that must ask for consent first plugs a
consent manager into the button:

```js
// Resolve TRUE to load Smeetz, FALSE to open the booking page instead.
Drupal.dagdaSmeetz.consent = (button) => myConsentManager.ask('smeetz');
```

Set it in a library that loads before `dagda_smeetz/booking`. It is called
before Smeetz loads, never after.

## Theming

Override `dagda-smeetz-button.html.twig` in the theme. Keep the `href` and the
`data-smeetz-*` attributes: the script reads them.

## Known limits

- Smeetz filters by **day**, not by time: two sessions of the same product on
  the same day both appear. The clean setup is one Smeetz product per session.
- Smeetz documents the widget only on its public test pages, not in its
  developer docs. The embed code here is the one Smeetz's own widget script
  generates.

## Development

```bash
tests/local.sh          # PHPCS, PHPStan, unit and kernel tests in a throwaway DDEV Quickstart
tests/local.sh lint     # or one step: lint, test
```

CI (`.github/workflows/ci.yml`) runs the same `tests/ci.sh` on pull requests,
against a plain Drupal 11 site.
