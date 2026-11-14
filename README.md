# Fullmetrix for PrestaShop

Official Fullmetrix module for PrestaShop. It connects your store to [Fullmetrix](https://fullmetrix.com), the analytics, segmentation and marketing platform for ecommerce.

Compatible with PrestaShop 1.6.1 to 9.x, PHP 7.1 or later.

## Installation

1. Download the latest ZIP from the [releases](https://github.com/Fullmetrix/fullmetrix-prestashop/releases) or from your Fullmetrix account.
2. In the PrestaShop back office, go to Modules, then Upload a module, and select the ZIP.
3. Open the module configuration and enter the connection code shown in Fullmetrix.

## Measurement script, service and cookies

The module loads the Fullmetrix measurement script from the store domain (`modules/fullmetrixconnector/views/js/fm/`) or, when that file is missing, from `https://fullmetrix.com/assets/js/s.js`. The script sends page views, cart and order events to `https://fullmetrix.com` and sets first-party cookies on the store domain: `_kvo_v` (anonymous visitor id, up to 390 days), `_kvo_s` (session, 30 minutes) and `_kvo_c` (contact identifiers after a login or an order). Stores connected before version 2.2.0 keep the older `fm_vid`, `fm_sid` and `fm_cid` cookies until they expire. The store server sets `_kvo_v` again on a same-origin request to the `keep` controller so browsers keep it longer; no PrestaShop session cookie is set by that request. Tracking consent is not handled by the module: call `fm('consent', {...})` from your tag manager or consent tool. Privacy policy: https://fullmetrix.com/privacy

## Support

- support@fullmetrix.com
- https://fullmetrix.com
