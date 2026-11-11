/**
 * @file
 * Opens the Smeetz booking widget from a Smeetz booking button.
 *
 * Nothing from Smeetz loads with the page: the loader is added on the first
 * click, once per page. The Smeetz loader sets tracking cookies as soon as it
 * runs. With "Ask before loading Smeetz" on, the first click asks whether to
 * open the booking here or on smeetz.com, and the choice is remembered; a
 * consent manager can instead replace Drupal.smeetzTicketing.consent (see
 * README.md). If the loader fails or is blocked, the button's link to the
 * Smeetz booking page opens instead.
 */
((Drupal, drupalSettings, once) => {
  const LOAD_TIMEOUT = 10000;
  const WATCH_TIMEOUT = 15000;
  const CHOICE_KEY = 'smeetzTicketing.consent';
  let loading = null;
  let asking = null;

  /**
   * Reads or writes a storage entry, ignoring browsers that block storage.
   *
   * @param {Storage} storage
   *   localStorage or sessionStorage.
   * @param {string} key
   *   The entry.
   * @param {string|null|undefined} value
   *   Undefined to read, NULL to remove, a string to write.
   *
   * @return {string|null}
   *   The stored value when reading.
   */
  function storage(storage, key, value) {
    try {
      if (value === undefined) {
        return storage.getItem(key);
      }
      if (value === null) {
        storage.removeItem(key);
      } else {
        storage.setItem(key, value);
      }
    } catch (e) {
      // Storage blocked: the visitor is asked again next time.
    }
    return null;
  }

  /**
   * Asks the visitor where to open the booking.
   *
   * @param {object} settings
   *   The consent settings: privacyUrl.
   *
   * @return {Promise<boolean|null>}
   *   TRUE to load Smeetz here, FALSE for smeetz.com, NULL if dismissed.
   */
  function ask(settings) {
    if (asking) {
      return asking;
    }
    asking = new Promise((resolve) => {
      const dialog = document.createElement('dialog');
      dialog.className = 'smeetz-ticketing-consent';
      dialog.setAttribute('aria-labelledby', 'smeetz-ticketing-consent-title');
      dialog.setAttribute('aria-describedby', 'smeetz-ticketing-consent-text');
      const privacy = settings.privacyUrl
        ? `<p><a href="${encodeURI(settings.privacyUrl)}" target="_blank" rel="noopener">${Drupal.t('Privacy policy', {}, { context: 'Smeetz' })}</a></p>`
        : '';
      dialog.innerHTML = `
        <form method="dialog">
          <h2 id="smeetz-ticketing-consent-title">${Drupal.t('Book with Smeetz', {}, { context: 'Smeetz' })}</h2>
          <p id="smeetz-ticketing-consent-text">${Drupal.t('Tickets are sold by Smeetz. Booking on this page loads Smeetz here, and Smeetz sets cookies to measure its use. You can book on smeetz.com in a new tab instead.', {}, { context: 'Smeetz' })}</p>
          ${privacy}
          <div class="smeetz-ticketing-consent__actions">
            <button value="granted" autofocus>${Drupal.t('Book on this page', {}, { context: 'Smeetz' })}</button>
            <button value="declined">${Drupal.t('Open smeetz.com', {}, { context: 'Smeetz' })}</button>
          </div>
          <p class="smeetz-ticketing-consent__note">${Drupal.t('Your choice is remembered in this browser.', {}, { context: 'Smeetz' })}</p>
        </form>`;
      dialog.addEventListener('close', () => {
        const choice = dialog.returnValue;
        dialog.remove();
        asking = null;
        if (choice === 'granted' || choice === 'declined') {
          storage(window.localStorage, CHOICE_KEY, choice);
          resolve(choice === 'granted');
        } else {
          resolve(null);
        }
      });
      document.body.appendChild(dialog);
      dialog.showModal();
    });
    return asking;
  }

  /**
   * Public API, for consent managers and themes.
   *
   * @namespace
   */
  Drupal.smeetzTicketing = Drupal.smeetzTicketing || {};

  /**
   * Resolves with TRUE when Smeetz may load, FALSE to open the link instead.
   *
   * Called before the loader is added, never after it has loaded. Replace it
   * to plug in a consent manager. NULL cancels the click.
   *
   * @param {HTMLElement} button
   *   The button that was clicked.
   *
   * @return {Promise<boolean|null>}
   *   Whether Smeetz may load.
   */
  Drupal.smeetzTicketing.consent =
    Drupal.smeetzTicketing.consent ||
    (() => {
      const { consent } = drupalSettings.smeetzTicketing || {};
      if (!consent) {
        return Promise.resolve(true);
      }
      const choice = storage(window.localStorage, CHOICE_KEY);
      if (choice === 'granted' || choice === 'declined') {
        return Promise.resolve(choice === 'granted');
      }
      return ask(consent);
    });

  /**
   * Forgets the visitor's choice and what Smeetz stored on this site.
   *
   * Smeetz's own cookie on smeetz.com can only be removed from smeetz.com.
   */
  Drupal.smeetzTicketing.resetConsent = () => {
    storage(window.localStorage, CHOICE_KEY, null);
    storage(window.localStorage, 'smtz-tid', null);
    storage(window.sessionStorage, 'smtz-tracker', null);
    // The cookie may be set on this host or a parent domain.
    const parts = window.location.hostname.split('.');
    const domains = [''];
    for (let i = 0; i < parts.length - 1; i++) {
      domains.push(`; domain=.${parts.slice(i).join('.')}`);
    }
    domains.forEach((domain) => {
      document.cookie = `__smtz_uid=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/${domain}`;
    });
  };

  /**
   * Adds the Smeetz loader to the page, once.
   *
   * @return {Promise<void>}
   *   Resolves when Smeetz is ready, rejects if it fails or times out.
   */
  Drupal.smeetzTicketing.load = () => {
    if (loading) {
      return loading;
    }
    const { groupId, loaderUrl } = drupalSettings.smeetzTicketing || {};
    loading = new Promise((resolve, reject) => {
      // The official Smeetz stub: calls are queued until the loader runs.
      if (!window._smtz) {
        const stub = function smtz(...args) {
          if (stub.p) {
            stub.p.apply(stub, args);
          } else {
            stub.q.push(args);
          }
        };
        stub.q = [];
        stub.t = Date.now();
        stub.g = String(groupId);
        window._smtz = stub;
      }
      const script = document.createElement('script');
      let timedOut = false;
      const timer = window.setTimeout(() => {
        timedOut = true;
        reject(new Error('Smeetz loader timed out'));
      }, LOAD_TIMEOUT);
      script.async = true;
      script.src = loaderUrl;
      // The loader adds more scripts; Smeetz is ready once one of them has
      // set _smtz.p, the function that runs commands.
      script.onload = () => {
        const ready = () => {
          if (timedOut) {
            return;
          }
          if (window._smtz && typeof window._smtz.p === 'function') {
            window.clearTimeout(timer);
            resolve();
          } else {
            window.setTimeout(ready, 100);
          }
        };
        ready();
      };
      script.onerror = () => {
        window.clearTimeout(timer);
        reject(new Error('Smeetz loader failed to load'));
      };
      document.head.appendChild(script);
    });
    // A failed load can be retried on the next click.
    loading.catch(() => {
      loading = null;
    });
    return loading;
  };

  /**
   * Finds the visible Smeetz widget iframe, if any.
   *
   * @return {HTMLIFrameElement|null}
   *   The iframe.
   */
  function visibleWidget() {
    const frames = document.querySelectorAll('iframe[id^="smtz"]');
    return (
      Array.from(frames).find(
        (frame) =>
          frame.getClientRects().length > 0 &&
          window.getComputedStyle(frame).visibility !== 'hidden',
      ) || null
    );
  }

  /**
   * Moves focus into the widget once it shows, and back when it closes.
   *
   * Smeetz leaves focus on the page behind its overlay, so keyboard users
   * would otherwise not reach it.
   *
   * @param {HTMLElement} button
   *   The button to return focus to.
   */
  function manageFocus(button) {
    let opened = false;
    const check = (observer) => {
      const frame = visibleWidget();
      if (!opened && frame) {
        opened = true;
        frame.setAttribute('tabindex', '-1');
        frame.focus();
      } else if (opened && !frame) {
        observer.disconnect();
        button.focus();
      }
    };
    const observer = new MutationObserver(() => check(observer));
    observer.observe(document.body, {
      childList: true,
      subtree: true,
      attributes: true,
      attributeFilter: ['class', 'style', 'hidden'],
    });
    check(observer);
    window.setTimeout(() => {
      if (!opened) {
        observer.disconnect();
      }
    }, WATCH_TIMEOUT);
  }

  /**
   * Opens the Smeetz booking page in a new tab, or this tab if blocked.
   *
   * @param {HTMLAnchorElement} button
   *   The button, whose href is the booking page.
   */
  function openFallback(button) {
    const tab = window.open(button.href, '_blank');
    if (tab) {
      tab.opener = null;
    } else {
      window.location.assign(button.href);
    }
  }

  /**
   * Opens the widget for a button.
   *
   * @param {HTMLAnchorElement} button
   *   The button.
   */
  function openWidget(button) {
    const { groupId } = drupalSettings.smeetzTicketing || {};
    const options = {
      productId: button.dataset.smeetzProduct,
      gId: String(groupId),
      lightbox: button.dataset.smeetzLightbox !== 'false',
      listView: false,
    };
    if (button.dataset.smeetzLang) {
      options.lang = button.dataset.smeetzLang;
    }
    if (button.dataset.smeetzDate) {
      options.showDate = button.dataset.smeetzDate;
    }
    window._smtz('openwidget', Date.now(), options);
    manageFocus(button);
  }

  /**
   * Turns Smeetz booking links into buttons that open the widget.
   *
   * @type {Drupal~behavior}
   */
  Drupal.behaviors.smeetzTicketing = {
    attach(context) {
      once('smeetz-ticketing', 'a[data-smeetz-product]', context).forEach(
        (button) => {
          button.addEventListener('click', (event) => {
            // Let modified clicks open the link in a new tab as usual.
            if (
              event.button !== 0 ||
              event.metaKey ||
              event.ctrlKey ||
              event.shiftKey ||
              event.altKey
            ) {
              return;
            }
            event.preventDefault();
            button.setAttribute('aria-busy', 'true');
            Drupal.smeetzTicketing
              .consent(button)
              .then((allowed) => {
                if (allowed === null) {
                  return null;
                }
                if (!allowed) {
                  openFallback(button);
                  return null;
                }
                return Drupal.smeetzTicketing.load().then(() => openWidget(button));
              })
              .catch(() => openFallback(button))
              .finally(() => button.removeAttribute('aria-busy'));
          });
        },
      );
      once(
        'smeetz-ticketing-reset',
        'button[data-smeetz-consent-reset]',
        context,
      ).forEach((reset) => {
        reset.hidden = false;
        reset.addEventListener('click', () => {
          Drupal.smeetzTicketing.resetConsent();
          Drupal.announce(
            Drupal.t(
              'Your choice is forgotten. The next booking will ask again.',
              {},
              { context: 'Smeetz' },
            ),
          );
        });
      });
    },
  };
})(Drupal, drupalSettings, once);
