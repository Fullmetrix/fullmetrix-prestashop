/**
 * Fullmetrix Tracker - Bulletproof Client-side Behavioral Tracking
 *
 * Features:
 * - INSTANT event sending (no batching delay)
 * - Automatic retry with exponential backoff
 * - localStorage queue for offline resilience
 * - Works on ANY theme (WooCommerce, PrestaShop, custom)
 * - SPA/AJAX navigation support
 * - sendBeacon for page unload reliability
 * - 2-year visitor persistence
 *
 * @version 2.0.0
 */
(function () {
  'use strict';

  // ─── Configuration ───────────────────────────────────────────────
  const CONFIG = {
    QUEUE_KEY: 'fm_event_queue',
    FAILED_QUEUE_KEY: 'fm_failed_queue',
    LAST_PRODUCTS_KEY: 'fm_last_products',
    SEND_TIMEOUT: 5000, // 5 seconds timeout
    MAX_RETRIES: 3,
    RETRY_DELAYS: [1000, 3000, 10000], // Exponential backoff
    MAX_QUEUE_SIZE: 100,
    MAX_LAST_PRODUCTS: 20,
    SESSION_TIMEOUT: 30 * 60 * 1000, // 30 minutes
    COOKIE_DURATION_DAYS: 730, // 2 years
    DEBOUNCE_MS: 300,
  };

  // Get config from PHP (supports both WooCommerce and PrestaShop)
  const fmConfig = window.fm_config || window.fullmetrixConfig || {};

  // Validate config
  if (!fmConfig.api_url) {
    console.warn('[Fullmetrix] No API URL configured, tracking disabled');
    return;
  }

  // ─── State ───────────────────────────────────────────────────────
  let pendingSends = 0;
  let isOnline = navigator.onLine !== false;

  // ─── Cookie Helpers (2-year persistence) ─────────────────────────
  const Cookies = {
    get(name) {
      const match = document.cookie.match(new RegExp('(^| )' + name + '=([^;]+)'));
      return match ? decodeURIComponent(match[2]) : null;
    },

    set(name, value, days = CONFIG.COOKIE_DURATION_DAYS) {
      const expires = days > 0 ? new Date(Date.now() + days * 864e5).toUTCString() : '';
      const expiresStr = days > 0 ? `;expires=${expires}` : '';
      document.cookie = `${name}=${encodeURIComponent(value)}${expiresStr};path=/;SameSite=Lax${location.protocol === 'https:' ? ';Secure' : ''}`;
    },

    delete(name) {
      document.cookie = `${name}=;expires=Thu, 01 Jan 1970 00:00:00 GMT;path=/`;
    },
  };

  // ─── Visitor & Session Management ────────────────────────────────
  const Identity = {
    getVisitorId() {
      let vid = Cookies.get('fm_vid') || fmConfig.visitor_id;
      if (!vid || !/^[a-f0-9-]{36}$/.test(vid)) {
        vid = this.generateUUID();
        Cookies.set('fm_vid', vid, CONFIG.COOKIE_DURATION_DAYS);
      }
      return vid;
    },

    getSessionId() {
      const sidData = Cookies.get('fm_sid');
      if (sidData) {
        const [sid, timestamp] = sidData.split('.');
        const elapsed = Date.now() - parseInt(timestamp, 10);
        if (elapsed < CONFIG.SESSION_TIMEOUT && /^[a-f0-9-]{36}$/.test(sid)) {
          // Refresh session
          Cookies.set('fm_sid', `${sid}.${Date.now()}`, 0);
          return sid;
        }
      }
      // New session
      const newSid = this.generateUUID();
      Cookies.set('fm_sid', `${newSid}.${Date.now()}`, 0);
      return newSid;
    },

    getContact() {
      const encoded = Cookies.get('fm_cid');
      if (!encoded) return fmConfig.contact || null;
      try {
        return JSON.parse(atob(encoded));
      } catch {
        return fmConfig.contact || null;
      }
    },

    setContact(email, phone = null) {
      const contactData = {
        email: email,
        phone: phone,
        identified_at: Date.now(),
      };
      Cookies.set('fm_cid', btoa(JSON.stringify(contactData)), CONFIG.COOKIE_DURATION_DAYS);
    },

    generateUUID() {
      // Crypto-safe UUID v4
      if (crypto && crypto.randomUUID) {
        return crypto.randomUUID();
      }
      return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
        const r = (Math.random() * 16) | 0;
        const v = c === 'x' ? r : (r & 0x3) | 0x8;
        return v.toString(16);
      });
    },
  };

  // ─── Universal Page Detection ────────────────────────────────────
  const PageDetector = {
    // Cache page type for SPA navigation detection
    _lastUrl: null,
    _lastPageType: null,

    isProductPage() {
      // WooCommerce
      if (document.body.classList.contains('single-product')) return true;
      // PrestaShop
      if (document.body.id === 'product') return true;
      if (document.body.classList.contains('product-page')) return true;
      // Schema.org (works on any platform)
      if (document.querySelector('[itemtype*="schema.org/Product"]')) return true;
      if (document.querySelector('meta[property="og:type"][content="product"]')) return true;
      // URL patterns
      if (/\/product\/|\/produit\/|\/producto\//i.test(location.pathname)) return true;
      // Data attributes
      if (document.querySelector('[data-product-id], [data-product]')) return true;
      return false;
    },

    isCategoryPage() {
      // WooCommerce
      if (document.body.classList.contains('tax-product_cat')) return true;
      if (document.body.classList.contains('woocommerce-shop')) return true;
      // PrestaShop
      if (document.body.id === 'category') return true;
      if (document.body.classList.contains('category-page')) return true;
      // URL patterns
      if (/\/category\/|\/categorie\/|\/categoria\//i.test(location.pathname)) return true;
      return false;
    },

    isCartPage() {
      // WooCommerce
      if (document.body.classList.contains('woocommerce-cart')) return true;
      // PrestaShop
      if (document.body.id === 'cart') return true;
      // URL patterns (universal)
      if (/\/(cart|panier|carrito|warenkorb)\/?(\?|$)/i.test(location.pathname)) return true;
      // Data attributes
      if (document.querySelector('[data-cart-page], .cart-page, #cart-page')) return true;
      return false;
    },

    isCheckoutPage() {
      // WooCommerce
      if (document.body.classList.contains('woocommerce-checkout')) return true;
      // PrestaShop
      if (document.body.id === 'checkout') return true;
      if (document.body.classList.contains('checkout-page')) return true;
      // URL patterns (universal)
      if (/\/(checkout|commande|pago|kasse)\/?(\?|$)/i.test(location.pathname)) return true;
      return false;
    },

    isOrderCompletePage() {
      // WooCommerce
      if (document.body.classList.contains('woocommerce-order-received')) return true;
      // PrestaShop
      if (document.body.id === 'order-confirmation') return true;
      // URL patterns
      if (/order-received|order-confirmation|confirmation|thank-you|merci/i.test(location.pathname)) return true;
      // Success indicators
      if (document.querySelector('.woocommerce-thankyou-order-received, .order-confirmation, [data-order-complete]')) return true;
      return false;
    },

    isSearchPage() {
      if (document.body.classList.contains('search-results')) return true;
      if (document.body.classList.contains('search')) return true;
      if (document.body.id === 'search') return true;
      const params = new URLSearchParams(location.search);
      return params.has('s') || params.has('search_query') || params.has('q') || params.has('search');
    },

    getPageType() {
      // Use cached value if URL hasn't changed
      if (this._lastUrl === location.href && this._lastPageType) {
        return this._lastPageType;
      }

      this._lastUrl = location.href;

      if (this.isOrderCompletePage()) this._lastPageType = 'order_complete';
      else if (this.isCheckoutPage()) this._lastPageType = 'checkout';
      else if (this.isCartPage()) this._lastPageType = 'cart';
      else if (this.isProductPage()) this._lastPageType = 'product';
      else if (this.isCategoryPage()) this._lastPageType = 'category';
      else if (this.isSearchPage()) this._lastPageType = 'search';
      else this._lastPageType = 'other';

      return this._lastPageType;
    },

    // Detect if URL changed (for SPA support)
    hasNavigated() {
      return this._lastUrl !== location.href;
    },
  };

  // ─── Universal Data Extraction ───────────────────────────────────
  const DataExtractor = {
    getPageData() {
      // Try PHP-injected data first (most reliable)
      const el = document.getElementById('fm-page-data');
      if (el) {
        try {
          return JSON.parse(el.textContent);
        } catch {}
      }
      return null;
    },

    getProductFromSchema() {
      const scripts = document.querySelectorAll('script[type="application/ld+json"]');
      for (const script of scripts) {
        try {
          const data = JSON.parse(script.textContent);
          const product =
            data['@type'] === 'Product' ? data : data['@graph']?.find((i) => i['@type'] === 'Product');
          if (product) {
            return {
              id: product.sku || product.productID || product['@id'] || null,
              name: product.name,
              price: parseFloat(product.offers?.price || product.offers?.[0]?.price || 0),
              currency: product.offers?.priceCurrency || product.offers?.[0]?.priceCurrency || fmConfig.currency,
              image_url: Array.isArray(product.image) ? product.image[0] : product.image,
              in_stock: String(product.offers?.availability || '').includes('InStock'),
              brand: product.brand?.name,
              description: product.description?.substring(0, 200),
              url: location.href,
            };
          }
        } catch {}
      }
      return null;
    },

    // Extract product from DOM (universal selectors)
    getProductFromDOM() {
      const product = {};

      // Product ID (multiple strategies)
      const idEl =
        document.querySelector('[data-product-id]') ||
        document.querySelector('[data-id]') ||
        document.querySelector('input[name="add-to-cart"]') ||
        document.querySelector('input[name="product_id"]') ||
        document.querySelector('button[data-product_id]');
      if (idEl) {
        product.id =
          idEl.dataset.productId || idEl.dataset.id || idEl.value || idEl.dataset.product_id;
      }

      // Product name
      const nameEl =
        document.querySelector('.product_title, .product-title, h1.title, [itemprop="name"]');
      if (nameEl) product.name = nameEl.textContent.trim();

      // Price
      const priceEl = document.querySelector(
        '.price ins .amount, .price .amount, .current-price, [itemprop="price"], .product-price'
      );
      if (priceEl) {
        const priceText = priceEl.textContent || priceEl.getAttribute('content');
        product.price = parseFloat(priceText.replace(/[^\d.,]/g, '').replace(',', '.'));
      }

      // Image
      const imgEl = document.querySelector(
        '.woocommerce-product-gallery__image img, .product-images img, [itemprop="image"]'
      );
      if (imgEl) product.image_url = imgEl.src || imgEl.dataset.src;

      product.url = location.href;

      return product.id || product.name ? product : null;
    },

    getSearchQuery() {
      const params = new URLSearchParams(location.search);
      return params.get('s') || params.get('search_query') || params.get('q') || params.get('search') || '';
    },

    getDeviceType() {
      const ua = navigator.userAgent;
      if (/tablet|ipad|playbook|silk/i.test(ua)) return 'tablet';
      if (/mobile|iphone|ipod|android|blackberry|opera mini|iemobile/i.test(ua)) return 'mobile';
      return 'desktop';
    },

    getReferrer() {
      const ref = document.referrer;
      if (!ref) return { type: 'direct', value: null };

      try {
        const url = new URL(ref);
        if (url.hostname === location.hostname) {
          return { type: 'internal', value: ref };
        }

        const hostname = url.hostname.toLowerCase();
        if (/google\.|bing\.|yahoo\.|duckduckgo\.|baidu\.|yandex\./i.test(hostname)) {
          return { type: 'organic_search', value: hostname };
        }
        if (/facebook\.|instagram\.|twitter\.|linkedin\.|pinterest\.|tiktok\.|youtube\./i.test(hostname)) {
          return { type: 'social', value: hostname };
        }
        return { type: 'referral', value: hostname };
      } catch {
        return { type: 'unknown', value: ref };
      }
    },

    getUTMParams() {
      const params = new URLSearchParams(location.search);
      const utm = {};
      ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'].forEach((key) => {
        const value = params.get(key);
        if (value) utm[key] = value;
      });
      return Object.keys(utm).length > 0 ? utm : null;
    },
  };

  // ─── Event Queue (localStorage for resilience) ───────────────────
  const Queue = {
    get(key = CONFIG.QUEUE_KEY) {
      try {
        const data = localStorage.getItem(key);
        return data ? JSON.parse(data) : [];
      } catch {
        return [];
      }
    },

    add(event, key = CONFIG.QUEUE_KEY) {
      const queue = this.get(key);

      // Dedupe by event_id
      if (queue.some((e) => e.event_id === event.event_id)) {
        return false;
      }

      queue.push(event);

      // Trim if too large
      while (queue.length > CONFIG.MAX_QUEUE_SIZE) {
        queue.shift();
      }

      try {
        localStorage.setItem(key, JSON.stringify(queue));
        return true;
      } catch {
        // Storage full, clear old events
        localStorage.removeItem(key);
        return false;
      }
    },

    remove(eventIds, key = CONFIG.QUEUE_KEY) {
      const queue = this.get(key).filter((e) => !eventIds.includes(e.event_id));
      try {
        localStorage.setItem(key, JSON.stringify(queue));
      } catch {}
    },

    clear(key = CONFIG.QUEUE_KEY) {
      localStorage.removeItem(key);
    },
  };

  // ─── Last Viewed Products ────────────────────────────────────────
  const LastProducts = {
    get() {
      try {
        const data = localStorage.getItem(CONFIG.LAST_PRODUCTS_KEY);
        return data ? JSON.parse(data) : [];
      } catch {
        return [];
      }
    },

    add(product) {
      if (!product || !product.id) return;

      let products = this.get();
      products = products.filter((p) => p.id !== product.id);
      products.unshift({
        id: product.id,
        name: product.name,
        price: product.price,
        image_url: product.image_url,
        url: location.href,
        viewed_at: Date.now(),
      });
      products = products.slice(0, CONFIG.MAX_LAST_PRODUCTS);

      try {
        localStorage.setItem(CONFIG.LAST_PRODUCTS_KEY, JSON.stringify(products));
      } catch {}
    },
  };

  // ─── Core Tracker (INSTANT sending with retry) ───────────────────
  const Tracker = {
    track(eventType, properties = {}) {
      const event = {
        event_type: eventType,
        event_id: Identity.generateUUID(),
        timestamp: Date.now(),
        visitor_id: Identity.getVisitorId(),
        session_id: Identity.getSessionId(),
        properties: properties,
        page: {
          url: location.href,
          path: location.pathname,
          title: document.title,
          referrer: document.referrer,
        },
        device: {
          type: DataExtractor.getDeviceType(),
          language: navigator.language,
          timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
          screen: `${screen.width}x${screen.height}`,
        },
        source: 'js',
      };

      // Add contact if identified
      const contact = Identity.getContact();
      if (contact) {
        event.contact = contact;
      }

      // Add UTM params if present
      const utm = DataExtractor.getUTMParams();
      if (utm) {
        event.utm = utm;
      }

      // Debug mode
      if (window.FM_DEBUG) {
        console.log('[FM] Tracked:', eventType, event);
      }

      // Send INSTANTLY (no batching)
      this.sendEvent(event);
    },

    // Send single event immediately
    async sendEvent(event, retryCount = 0) {
      // Add to queue first (for resilience)
      Queue.add(event);

      // Don't send if offline
      if (!isOnline) {
        if (window.FM_DEBUG) console.log('[FM] Offline, queued for later');
        return;
      }

      pendingSends++;

      try {
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), CONFIG.SEND_TIMEOUT);

        const response = await fetch(fmConfig.api_url, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            ...(fmConfig.nonce ? { 'X-WP-Nonce': fmConfig.nonce } : {}),
          },
          body: JSON.stringify([event]),
          keepalive: true,
          signal: controller.signal,
        });

        clearTimeout(timeoutId);

        if (response.ok) {
          // Success - remove from queue
          Queue.remove([event.event_id]);
          if (window.FM_DEBUG) console.log('[FM] Sent:', event.event_type);
        } else if (retryCount < CONFIG.MAX_RETRIES) {
          // Retry on server error
          this.scheduleRetry(event, retryCount);
        }
      } catch (err) {
        if (retryCount < CONFIG.MAX_RETRIES) {
          this.scheduleRetry(event, retryCount);
        } else if (window.FM_DEBUG) {
          console.error('[FM] Failed after retries:', err);
        }
      } finally {
        pendingSends--;
      }
    },

    // Retry with exponential backoff
    scheduleRetry(event, retryCount) {
      const delay = CONFIG.RETRY_DELAYS[retryCount] || CONFIG.RETRY_DELAYS[CONFIG.RETRY_DELAYS.length - 1];
      if (window.FM_DEBUG) console.log(`[FM] Retry ${retryCount + 1} in ${delay}ms`);
      setTimeout(() => this.sendEvent(event, retryCount + 1), delay);
    },

    // Send all queued events (for page unload or coming back online)
    async flushQueue() {
      const queue = Queue.get();
      if (queue.length === 0) return;

      if (window.FM_DEBUG) console.log('[FM] Flushing', queue.length, 'events');

      // Use sendBeacon for reliability on page unload
      if (navigator.sendBeacon) {
        const blob = new Blob([JSON.stringify(queue)], { type: 'application/json' });
        const url = fmConfig.api_url + (fmConfig.api_url.includes('?') ? '&' : '?') + '_nonce=' + (fmConfig.nonce || '');
        const success = navigator.sendBeacon(url, blob);
        if (success) {
          Queue.clear();
          if (window.FM_DEBUG) console.log('[FM] Beacon sent');
        }
      } else {
        // Fallback to sync XHR
        const xhr = new XMLHttpRequest();
        xhr.open('POST', fmConfig.api_url, false);
        xhr.setRequestHeader('Content-Type', 'application/json');
        if (fmConfig.nonce) xhr.setRequestHeader('X-WP-Nonce', fmConfig.nonce);
        try {
          xhr.send(JSON.stringify(queue));
          if (xhr.status === 200) Queue.clear();
        } catch {}
      }
    },

    // Identify a contact
    identify(email, phone = null, source = 'manual') {
      if (!email && !phone) return;

      // Set cookie
      Identity.setContact(email, phone);

      // Track identification event
      this.track('contact_identified', {
        email: email,
        phone: phone,
        source: source,
      });

      // Notify server immediately
      if (fmConfig.identify_url) {
        fetch(fmConfig.identify_url, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            ...(fmConfig.nonce ? { 'X-WP-Nonce': fmConfig.nonce } : {}),
          },
          body: JSON.stringify({ email, phone, source }),
          keepalive: true,
        }).catch(() => {});
      }
    },
  };

  // ─── Auto-tracking Setup ─────────────────────────────────────────
  const AutoTrack = {
    init() {
      // Track page view
      this.trackPageView();

      // Page-specific tracking
      const pageType = PageDetector.getPageType();
      const pageData = DataExtractor.getPageData();

      switch (pageType) {
        case 'product':
          this.trackProductView(pageData);
          break;
        case 'category':
          this.trackCategoryView(pageData);
          break;
        case 'cart':
          this.trackCartView(pageData);
          break;
        case 'checkout':
          this.trackCheckoutStarted(pageData);
          break;
        case 'order_complete':
          this.trackOrderComplete(pageData);
          break;
        case 'search':
          this.trackSearch();
          break;
      }

      // Setup listeners
      this.setupAddToCartListeners();
      this.setupEmailCaptureListeners();
      this.setupCheckoutStepListeners();
      this.setupSPANavigation();
      this.setupOnlineOfflineHandlers();
      this.setupPageUnloadHandler();

      // Process any queued events from previous session
      this.processQueuedEvents();
    },

    trackPageView() {
      const referrer = DataExtractor.getReferrer();
      Tracker.track('page_viewed', {
        page_type: PageDetector.getPageType(),
        referrer_type: referrer.type,
        referrer_value: referrer.value,
      });
    },

    trackProductView(pageData) {
      let product = pageData?.data;

      // Fallback strategies
      if (!product) product = DataExtractor.getProductFromSchema();
      if (!product) product = DataExtractor.getProductFromDOM();

      if (!product) return;

      LastProducts.add(product);

      Tracker.track('product_viewed', {
        product: product,
        viewed_from: DataExtractor.getReferrer().type,
      });
    },

    trackCategoryView(pageData) {
      const category = pageData?.data;
      Tracker.track('category_viewed', { category: category || {} });
    },

    trackCartView(pageData) {
      Tracker.track('cart_viewed', { cart: pageData?.data || {} });
    },

    trackCheckoutStarted(pageData) {
      Tracker.track('checkout_started', { cart: pageData?.data || {} });
    },

    trackOrderComplete(pageData) {
      const order = pageData?.data;
      if (!order) return;

      Tracker.track('order_completed', { order: order });
      localStorage.removeItem(CONFIG.LAST_PRODUCTS_KEY);
    },

    trackSearch() {
      const query = DataExtractor.getSearchQuery();
      if (!query) return;

      let resultsCount = null;
      const countEl = document.querySelector(
        '.woocommerce-result-count, .result-count, [data-results-count], .search-count'
      );
      if (countEl) {
        const match = countEl.textContent.match(/(\d+)/);
        if (match) resultsCount = parseInt(match[1], 10);
      }

      Tracker.track('search_performed', { query, results_count: resultsCount });
    },

    // Universal add-to-cart listeners (works on any theme)
    setupAddToCartListeners() {
      // Click handler for all add-to-cart buttons
      document.addEventListener(
        'click',
        (e) => {
          const btn = e.target.closest(
            // WooCommerce
            '.add_to_cart_button, .single_add_to_cart_button, ' +
            // PrestaShop
            '.add-to-cart, [data-button-action="add-to-cart"], ' +
            // Universal
            '[data-add-to-cart], [data-action="add-to-cart"], ' +
            'button[name="add-to-cart"], input[name="add-to-cart"], ' +
            // Common class patterns
            '.btn-add-to-cart, .btn-addtocart, .add-cart-btn, .addtocart-button, ' +
            // Form submit buttons inside product forms
            'form.cart button[type="submit"], form[action*="cart"] button[type="submit"]'
          );

          if (!btn) return;

          // Extract product ID from multiple sources
          const productId =
            btn.dataset.productId ||
            btn.dataset.product_id ||
            btn.dataset.id ||
            btn.value ||
            btn.closest('form')?.querySelector('[name="add-to-cart"], [name="id_product"], [name="product_id"]')?.value ||
            btn.closest('[data-product-id]')?.dataset.productId;

          // Extract quantity
          const qtyInput = btn.closest('form')?.querySelector('[name="quantity"], [name="qty"]');
          const quantity = qtyInput ? parseInt(qtyInput.value, 10) || 1 : 1;

          if (productId) {
            Tracker.track('add_to_cart_clicked', {
              product_id: String(productId),
              quantity: quantity,
              source: 'button_click',
            });
          }
        },
        true
      );

      // jQuery events for WooCommerce AJAX
      if (window.jQuery) {
        jQuery(document.body).on('added_to_cart', function (e, fragments, cartHash, $button) {
          const productId = $button?.data('product_id') || $button?.data('productId');
          if (productId) {
            Tracker.track('product_added', {
              product_id: String(productId),
              source: 'ajax_success',
            });
          }
        });
      }

      // PrestaShop AJAX events
      if (window.prestashop) {
        document.addEventListener('updateCart', (e) => {
          if (e.detail?.reason?.idProduct) {
            Tracker.track('product_added', {
              product_id: String(e.detail.reason.idProduct),
              source: 'prestashop_ajax',
            });
          }
        });
      }

      // Form submission tracking
      document.addEventListener('submit', (e) => {
        const form = e.target;
        if (form.classList.contains('cart') || form.action?.includes('cart')) {
          const productInput = form.querySelector('[name="add-to-cart"], [name="id_product"]');
          if (productInput) {
            Tracker.track('add_to_cart_clicked', {
              product_id: String(productInput.value),
              source: 'form_submit',
            });
          }
        }
      });
    },

    // Email capture listeners (checkout, newsletter)
    setupEmailCaptureListeners() {
      const emailSelectors = [
        // WooCommerce
        '#billing_email', 'input[name="billing_email"]',
        '.wc-block-components-text-input input[type="email"]',
        // PrestaShop
        '#customer-email', 'input[name="email"]',
        // Universal
        '#email', 'input[type="email"]',
        '[data-email-capture]',
      ];

      let lastCapturedEmail = '';
      let captureTimeout = null;

      const captureEmail = (email, source) => {
        if (!email || email === lastCapturedEmail) return;
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) return;

        lastCapturedEmail = email;
        Tracker.identify(email, null, source);
      };

      const debouncedCapture = (email, source) => {
        clearTimeout(captureTimeout);
        captureTimeout = setTimeout(() => captureEmail(email, source), CONFIG.DEBOUNCE_MS);
      };

      // Attach to all email inputs
      emailSelectors.forEach((selector) => {
        document.querySelectorAll(selector).forEach((input) => {
          input.addEventListener('blur', (e) => debouncedCapture(e.target.value.trim(), 'checkout_email'));
          input.addEventListener('change', (e) => debouncedCapture(e.target.value.trim(), 'checkout_email'));
        });
      });

      // Phone capture
      const phoneSelectors = ['#billing_phone', 'input[name="billing_phone"]', 'input[name="phone"]', 'input[type="tel"]'];
      phoneSelectors.forEach((selector) => {
        document.querySelectorAll(selector).forEach((input) => {
          input.addEventListener('blur', (e) => {
            const phone = e.target.value.trim();
            if (phone && phone.length >= 8) {
              const contact = Identity.getContact();
              if (contact?.email) {
                Tracker.identify(contact.email, phone, 'checkout_phone');
              }
            }
          });
        });
      });

      // Newsletter forms
      document.querySelectorAll('form[class*="newsletter"], form[class*="subscribe"], form[id*="newsletter"]').forEach((form) => {
        form.addEventListener('submit', () => {
          const emailInput = form.querySelector('input[type="email"]');
          if (emailInput) captureEmail(emailInput.value.trim(), 'newsletter');
        });
      });
    },

    setupCheckoutStepListeners() {
      if (!PageDetector.isCheckoutPage()) return;

      document.addEventListener('change', (e) => {
        if (e.target.name === 'shipping_method[0]' || e.target.name?.includes('shipping')) {
          Tracker.track('checkout_step_completed', { step: 'shipping_method', value: e.target.value });
        }
        if (e.target.name === 'payment_method' || e.target.name?.includes('payment')) {
          Tracker.track('checkout_step_completed', { step: 'payment_method', value: e.target.value });
        }
      });

      // WooCommerce jQuery events
      if (window.jQuery) {
        jQuery(document.body).on('checkout_error', function () {
          Tracker.track('checkout_error', {
            errors: jQuery('.woocommerce-error li').map(function () { return jQuery(this).text(); }).get(),
          });
        });
        jQuery('form.checkout').on('checkout_place_order', function () {
          Tracker.track('checkout_step_completed', { step: 'place_order_clicked' });
        });
      }
    },

    // SPA/AJAX navigation support
    setupSPANavigation() {
      // History API
      const originalPushState = history.pushState;
      const originalReplaceState = history.replaceState;

      const handleNavigation = () => {
        if (PageDetector.hasNavigated()) {
          setTimeout(() => this.trackPageView(), 100);
        }
      };

      history.pushState = function (...args) {
        originalPushState.apply(this, args);
        handleNavigation();
      };

      history.replaceState = function (...args) {
        originalReplaceState.apply(this, args);
        handleNavigation();
      };

      window.addEventListener('popstate', handleNavigation);

      // MutationObserver for SPA content changes
      const observer = new MutationObserver(() => {
        if (PageDetector.hasNavigated()) {
          setTimeout(() => this.trackPageView(), 100);
        }
      });

      observer.observe(document.body, { childList: true, subtree: true });
    },

    // Online/offline handling
    setupOnlineOfflineHandlers() {
      window.addEventListener('online', () => {
        isOnline = true;
        if (window.FM_DEBUG) console.log('[FM] Back online, processing queue');
        this.processQueuedEvents();
      });

      window.addEventListener('offline', () => {
        isOnline = false;
        if (window.FM_DEBUG) console.log('[FM] Gone offline');
      });
    },

    // Page unload handler
    setupPageUnloadHandler() {
      // Multiple events for maximum reliability
      window.addEventListener('pagehide', () => Tracker.flushQueue());
      window.addEventListener('beforeunload', () => Tracker.flushQueue());
      window.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'hidden') {
          Tracker.flushQueue();
        }
      });
    },

    // Process queued events from previous sessions
    processQueuedEvents() {
      const queue = Queue.get();
      if (queue.length === 0) return;

      if (window.FM_DEBUG) console.log('[FM] Processing', queue.length, 'queued events');

      // Send each event
      queue.forEach((event) => {
        Tracker.sendEvent(event);
      });
    },
  };

  // ─── Public API ──────────────────────────────────────────────────
  window.fullmetrix = {
    track: (eventType, properties) => Tracker.track(eventType, properties),
    identify: (email, phone, source) => Tracker.identify(email, phone, source),
    getVisitorId: () => Identity.getVisitorId(),
    getSessionId: () => Identity.getSessionId(),
    getContact: () => Identity.getContact(),
    getLastViewedProducts: () => LastProducts.get(),
    flush: () => Tracker.flushQueue(),
    debug: (enable = true) => { window.FM_DEBUG = enable; },
  };

  // ─── Initialize ──────────────────────────────────────────────────
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => AutoTrack.init());
  } else {
    AutoTrack.init();
  }
})();
