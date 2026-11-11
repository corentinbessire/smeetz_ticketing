# Smeetz Ticketing

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
composer config repositories.smeetz-ticketing \
  '{"type": "vcs", "url": "git@github.com:corentinbessire/smeetz_ticketing.git", "no-api": true}'
composer require corentinbessire/smeetz_ticketing
drush en smeetz_ticketing
```

It installs to `web/modules/contrib/smeetz_ticketing`.

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

## Availability sync ("sold out")

Cron asks Smeetz which sessions are sold out and shows **Sold out** (label
set in the formatter) instead of the button.

1. Put the key in `settings.local.php`, never in configuration:
   ```php
   $settings['smeetz_ticketing']['api_key'] = '…';
   ```
   It is in the Smeetz back office → apps menu → Integrations.
2. On each item, fill the **Option UUID**.
3. In the field settings, choose the **Session date**: past content is
   skipped, and each item is matched to the Smeetz slot that starts at that
   time (within a minute). Optionally, name a list field the sync keeps in
   step (for a site that already shows its own status), with the value to
   write for each state.
4. **Check every** (settings form) sets the interval, 10 minutes by default;
   0 turns the sync off. `drush smeetz:sync` runs it on demand.

How it calls Smeetz:

- **One bulk check**: the option UUIDs of every upcoming published item, without
  duplicates, in chunks of 50, each read page by page (100 slots a page).
  A 60-session festival is about one request per sync, far under Smeetz's
  limit of 250 requests a minute per IP.
- No upcoming item: no call at all.
- HTTP 429 or any error: the run stops, the last known statuses stay, the error
  is logged, the next cron run tries again. No retry loop.
- An item is saved only when its status changes, without a new revision.
- No successful sync for 24 hours: the sold-out labels are hidden (the button
  shows again) until Smeetz answers. The status report shows the last success
  and how many requests it took.
- The key never reaches the browser.

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
they are set up in Smeetz.

### The built-in question

Turn on **Ask before loading Smeetz** in the settings. The first click on a
booking button then asks, in a dialog, whether to book on this page (Smeetz
loads here) or on smeetz.com in a new tab. Escape closes it and nothing
happens. The choice is kept in the browser's localStorage
(`smeetzTicketing.consent`), so later clicks do not ask. Add an optional
privacy policy link to the dialog with **Privacy policy link**.

Place the **Smeetz: booking choice** block (in the footer, for example) so
visitors can change their mind. Its button forgets the choice and removes
what Smeetz stored on the site (`__smtz_uid`, `smtz-tid`, `smtz-tracker`).
Smeetz's own cookies on smeetz.com can only be removed from smeetz.com. The
block shows only while the question is on, and its button only once the
script runs.

The dialog has minimal styles; restyle it in the theme through
`.smeetz-ticketing-consent`, `.smeetz-ticketing-consent__actions` and the
buttons' `value` (`granted`, `declined`).

### A consent manager instead

Leave the question off and plug the consent manager into the button:

```js
// Resolve TRUE to load Smeetz, FALSE to open the booking page instead,
// NULL to do nothing.
Drupal.smeetzTicketing.consent = (button) => myConsentManager.ask('smeetz');
```

Set it in a library that loads before `smeetz_ticketing/booking`. It is called
before Smeetz loads, never after.

## Theming

Override `smeetz-ticketing-button.html.twig` in the theme. Keep the `href` and the
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

`tests/local.sh` clones the Dagda Quickstart, which is private. Without access
to it, open a pull request: CI (`.github/workflows/ci.yml`) runs the same
`tests/ci.sh` against a plain Drupal 11 site.
