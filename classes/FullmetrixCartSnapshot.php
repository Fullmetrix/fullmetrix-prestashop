<?php
/**
 * Fullmetrix - E-commerce analytics platform connector
 *
 * @author    Fullmetrix <contact@fullmetrix.com>
 * @copyright 2024-2026 Fullmetrix
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__) . '/FullmetrixFormatContext.php';

class FullmetrixCartSnapshot
{
    public static function fingerprintRow($idCart)
    {
        $idCart = (int) $idCart;
        if ($idCart <= 0) {
            return null;
        }
        $p = _DB_PREFIX_;
        $row = Db::getInstance()->getRow(
            'SELECT c.`id_cart`, c.`id_customer`, c.`id_guest`, c.`date_upd`, c.`id_carrier`, c.`id_address_delivery`, c.`id_address_invoice`, c.`id_currency`,'
            . ' (SELECT COALESCE(SUM(cp.`quantity`), 0) FROM `' . $p . 'cart_product` cp WHERE cp.`id_cart` = c.`id_cart`) AS qty,'
            . ' (SELECT COUNT(*) FROM `' . $p . 'cart_cart_rule` ccr WHERE ccr.`id_cart` = c.`id_cart`) AS rules'
            . ' FROM `' . $p . 'cart` c WHERE c.`id_cart` = ' . $idCart,
            false
        );

        return is_array($row) && !empty($row) ? $row : null;
    }

    public static function fingerprint($row, $idCustomer, $logged)
    {
        $parts = [];
        if (is_array($row)) {
            foreach (['id_cart', 'date_upd', 'id_carrier', 'id_address_delivery', 'id_address_invoice', 'id_currency', 'qty', 'rules'] as $field) {
                $parts[] = isset($row[$field]) ? (string) $row[$field] : '';
            }
        }
        $parts[] = (string) (int) $idCustomer;
        $parts[] = $logged ? '1' : '0';

        return substr(hash('sha256', implode('|', $parts)), 0, 16);
    }

    public static function isOrdered($idCart)
    {
        return (int) Db::getInstance()->getValue(
            'SELECT `id_order` FROM `' . _DB_PREFIX_ . 'orders` WHERE `id_cart` = ' . (int) $idCart,
            false
        ) > 0;
    }

    public static function build($idCart, $logged, $fp)
    {
        $idCart = (int) $idCart;
        $row = Db::getInstance()->getRow('SELECT * FROM `' . _DB_PREFIX_ . 'cart` WHERE `id_cart` = ' . $idCart, false);
        if (!is_array($row) || empty($row)) {
            return ['status' => 'missing'];
        }
        if (self::isOrdered($idCart)) {
            return ['status' => 'ok', 'payload' => ['ordered' => true], 'empty' => false, 'ordered' => true];
        }

        $cart = self::enterCartContext($row, $logged);
        $snapshot = self::snapshot($cart);
        $properties = [
            'cart' => $snapshot,
            'source' => 'server',
            'id_cart' => $idCart,
            'id_guest' => (int) $row['id_guest'],
        ];
        if ($fp !== null) {
            $properties['fp'] = $fp;
        }

        return [
            'status' => 'ok',
            'payload' => $properties,
            'empty' => empty($snapshot['items']),
            'ordered' => false,
        ];
    }

    private static function enterCartContext(array $row, $logged)
    {
        $context = FullmetrixFormatContext::context();
        FullmetrixFormatContext::lockCookie();

        $idShop = (int) $row['id_shop'];
        if ($idShop > 0 && (!isset($context->shop->id) || (int) $context->shop->id !== $idShop)) {
            Shop::setContext(Shop::CONTEXT_SHOP, $idShop);
            $shop = new Shop($idShop);
            if (Validate::isLoadedObject($shop)) {
                $context->shop = $shop;
            }
        }

        $language = new Language((int) $row['id_lang']);
        if (!Validate::isLoadedObject($language)) {
            $language = new Language((int) Configuration::get('PS_LANG_DEFAULT'));
        }
        if (Validate::isLoadedObject($language)) {
            $context->language = $language;
        }

        $currency = new Currency((int) $row['id_currency']);
        if (!Validate::isLoadedObject($currency)) {
            $currency = new Currency((int) Configuration::get('PS_CURRENCY_DEFAULT'));
        }
        if (Validate::isLoadedObject($currency)) {
            $context->currency = $currency;
        }

        $customer = new Customer();
        if ((int) $row['id_customer'] > 0) {
            $loaded = new Customer((int) $row['id_customer']);
            if (Validate::isLoadedObject($loaded)) {
                $loaded->logged = (bool) $logged;
                $customer = $loaded;
            }
        }
        $context->customer = $customer;

        $country = null;
        $taxField = Configuration::get('PS_TAX_ADDRESS_TYPE') === 'id_address_invoice' ? 'id_address_invoice' : 'id_address_delivery';
        $idAddress = isset($row[$taxField]) ? (int) $row[$taxField] : 0;
        if ($idAddress > 0) {
            $address = new Address($idAddress);
            if (Validate::isLoadedObject($address)) {
                $country = new Country((int) $address->id_country);
            }
        }
        if ($country === null || !Validate::isLoadedObject($country)) {
            $country = new Country((int) Configuration::get('PS_COUNTRY_DEFAULT'));
        }
        if (Validate::isLoadedObject($country)) {
            $context->country = $country;
        }

        $cart = new Cart();
        $cart->hydrate($row);
        $cart->id = (int) $row['id_cart'];
        $context->cart = $cart;
        $context->link = new Link();

        return $cart;
    }

    private static function snapshot($cart)
    {
        $context = FullmetrixFormatContext::context();
        $link = $context->link;
        $products = $cart->getProducts();
        if (!is_array($products)) {
            $products = [];
        }

        $items = [];
        foreach ($products as $p) {
            $imageUrl = null;
            if ($link && !empty($p['id_image'])) {
                try {
                    $imageUrl = $link->getImageLink(isset($p['link_rewrite']) ? $p['link_rewrite'] : '', $p['id_image'], 'home_default');
                    if ($imageUrl && strpos($imageUrl, 'http') !== 0) {
                        $imageUrl = 'https://' . $imageUrl;
                    }
                } catch (Exception $e) {
                    $imageUrl = null;
                } catch (Throwable $e) {
                    $imageUrl = null;
                }
            }

            $productUrl = null;
            if ($link) {
                try {
                    $productUrl = $link->getProductLink((int) $p['id_product']);
                } catch (Exception $e) {
                    $productUrl = null;
                } catch (Throwable $e) {
                    $productUrl = null;
                }
            }

            $items[] = [
                'product_id' => (int) $p['id_product'],
                'variation_id' => !empty($p['id_product_attribute']) ? (int) $p['id_product_attribute'] : null,
                'name' => isset($p['name']) ? $p['name'] : '',
                'quantity' => isset($p['cart_quantity']) ? (int) $p['cart_quantity'] : 0,
                'price' => isset($p['price_wt']) ? (float) $p['price_wt'] : 0.0,
                'line_total' => isset($p['total_wt']) ? (float) $p['total_wt'] : 0.0,
                'sku' => !empty($p['reference']) ? $p['reference'] : null,
                'image_url' => $imageUrl,
                'url' => $productUrl,
            ];
        }

        $total = 0.0;
        $subtotal = 0.0;
        $discountTotal = 0.0;
        $shippingTotal = 0.0;
        $taxTotal = 0.0;
        try {
            $totalWithTax = (float) $cart->getOrderTotal(true, Cart::BOTH);
            $totalNoTax = (float) $cart->getOrderTotal(false, Cart::BOTH);
            $total = $totalWithTax;
            $subtotal = (float) $cart->getOrderTotal(true, Cart::ONLY_PRODUCTS);
            $discountTotal = abs((float) $cart->getOrderTotal(true, Cart::ONLY_DISCOUNTS));
            $shippingTotal = (float) $cart->getOrderTotal(true, Cart::ONLY_SHIPPING);
            $taxTotal = $totalWithTax - $totalNoTax;
        } catch (Exception $e) {
            list($total, $subtotal) = self::fallbackTotals($items);
        } catch (Throwable $e) {
            list($total, $subtotal) = self::fallbackTotals($items);
        }

        $couponCodes = self::couponCodes($cart);

        $recoveryUrl = null;
        try {
            $recoveryUrl = self::recoveryUrl($products, $couponCodes);
        } catch (Exception $e) {
            $recoveryUrl = null;
        } catch (Throwable $e) {
            $recoveryUrl = null;
        }

        $itemCount = 0;
        try {
            $itemCount = (int) $cart->nbProducts();
        } catch (Exception $e) {
            $itemCount = self::countItems($items);
        } catch (Throwable $e) {
            $itemCount = self::countItems($items);
        }

        return [
            'currency' => $context->currency ? $context->currency->iso_code : 'EUR',
            'total' => $total,
            'subtotal' => $subtotal,
            'discount_total' => $discountTotal,
            'shipping_total' => $shippingTotal,
            'tax_total' => $taxTotal,
            'coupon_codes' => $couponCodes,
            'item_count' => $itemCount,
            'items' => $items,
            'recovery_url' => $recoveryUrl,
        ];
    }

    private static function fallbackTotals(array $items)
    {
        $subtotal = 0.0;
        foreach ($items as $item) {
            $subtotal += (float) $item['line_total'];
        }

        return [$subtotal, $subtotal];
    }

    private static function countItems(array $items)
    {
        $count = 0;
        foreach ($items as $item) {
            $count += (int) $item['quantity'];
        }

        return $count;
    }

    private static function couponCodes($cart)
    {
        $codes = [];
        try {
            $rules = $cart->getCartRules(CartRule::FILTER_ACTION_ALL, false);
            if (is_array($rules)) {
                foreach ($rules as $rule) {
                    if (isset($rule['name'])) {
                        $codes[] = $rule['name'];
                    }
                }
            }
        } catch (Exception $e) {
            return [];
        } catch (Throwable $e) {
            return [];
        }

        return $codes;
    }

    public static function hasStandaloneCartPage()
    {
        return version_compare(_PS_VERSION_, '1.7.0.0', '>=');
    }

    public static function legacyCartController()
    {
        return Configuration::get('PS_ORDER_PROCESS_TYPE') ? 'order-opc' : 'order';
    }

    private static function recoveryUrl(array $products, array $coupons)
    {
        if (empty($products)) {
            return null;
        }
        $itemsData = [];
        foreach ($products as $p) {
            $itemsData[] = [
                'id' => (int) $p['id_product'],
                'v' => !empty($p['id_product_attribute']) ? (int) $p['id_product_attribute'] : 0,
                'q' => isset($p['cart_quantity']) ? (int) $p['cart_quantity'] : 1,
            ];
        }
        $secret = Configuration::get('FULLMETRIX_CONNECTION_SECRET');
        if (empty($secret)) {
            return null;
        }
        $payloadJson = json_encode(['items' => $itemsData, 'c' => $coupons, 'ts' => time()]);
        if ($payloadJson === false) {
            return null;
        }
        $encoded = strtr(base64_encode($payloadJson), '+/', '-_');
        $signature = hash_hmac('sha256', $encoded, $secret);

        $context = FullmetrixFormatContext::context();
        if (!$context->link) {
            return null;
        }
        $baseUrl = $context->link->getPageLink(self::hasStandaloneCartPage() ? 'cart' : self::legacyCartController(), true);
        if (!is_string($baseUrl) || $baseUrl === '') {
            return null;
        }
        $separator = strpos($baseUrl, '?') !== false ? '&' : '?';

        return $baseUrl . $separator . 'fm_cart=' . $encoded . '&fm_cart_sig=' . $signature;
    }

    public static function identify($idCustomer)
    {
        $customer = new Customer((int) $idCustomer);
        if (!Validate::isLoadedObject($customer) || empty($customer->email)) {
            return null;
        }
        $contact = [
            'email' => $customer->email,
            'first_name' => $customer->firstname ? $customer->firstname : null,
            'last_name' => $customer->lastname ? $customer->lastname : null,
            'customer_id' => (int) $customer->id,
        ];
        try {
            $addressId = (int) Address::getFirstCustomerAddressId($customer->id);
            if ($addressId > 0) {
                $address = new Address($addressId);
                if (Validate::isLoadedObject($address)) {
                    $phone = $address->phone_mobile ? $address->phone_mobile : ($address->phone ? $address->phone : null);
                    if ($phone) {
                        $contact['phone'] = $phone;
                    }
                }
            }
        } catch (Exception $e) {
            return $contact;
        } catch (Throwable $e) {
            return $contact;
        }

        return $contact;
    }
}
