/**
 * @file
 * Opens the Smeetz booking widget from a Smeetz booking button.
 *
 * Nothing from Smeetz loads with the page: the loader is added on the first
 * click, once per page. The Smeetz loader sets tracking cookies as soon as it
 * runs, so a consent manager can ask first by replacing
 * Drupal.smeetzTicketing.consent (see README.md). If the loader fails or is
 * blocked, the button's link to the Smeetz booking page opens instead.
 */
((Drupal, drupalSettings, once) => {
  const LOAD_TIMEOUT = 10000;
  const WATCH_TIMEOUT = 15000;
  let loading = null;

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
   * to plug in a consent manager.
   *
   * @param {HTMLElement} button
   *   The button that was clicked.
   *
   * @return {Promise<boolean>}
   *   Whether Smeetz may load.
   */
  Drupal.smeetzTicketing.consent =
    Drupal.smeetzTicketing.consent || (() => Promise.resolve(true));

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
    },
  };
})(Drupal, drupalSettings, once);
