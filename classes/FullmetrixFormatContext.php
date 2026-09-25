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

class FullmetrixFormatContext
{
    private static $currentShop;
    private static $context;

    public static function bind($context)
    {
        if (is_object($context)) {
            self::$context = $context;
        }
    }

    public static function context()
    {
        if (!is_object(self::$context)) {
            throw new RuntimeException('Fullmetrix format context is not bound');
        }

        return self::$context;
    }

    public static function lockCookie()
    {
        if (is_object(self::$context) && isset(self::$context->cookie) && self::$context->cookie instanceof Cookie) {
            self::$context->cookie->disallowWriting();
        }
    }

    public static function enter($idShop)
    {
        $context = self::context();
        self::lockCookie();

        $idShop = (int) $idShop;
        if ($idShop <= 0) {
            $idShop = (int) Configuration::get('PS_SHOP_DEFAULT');
        }
        if ($idShop > 0 && self::$currentShop !== $idShop) {
            Shop::setContext(Shop::CONTEXT_SHOP, $idShop);
            $shop = new Shop($idShop);
            if (Validate::isLoadedObject($shop)) {
                $context->shop = $shop;
            }
            self::$currentShop = $idShop;
        }

        $language = new Language((int) Configuration::get('PS_LANG_DEFAULT'));
        if (Validate::isLoadedObject($language)) {
            $context->language = $language;
        }
        $currency = new Currency((int) Configuration::get('PS_CURRENCY_DEFAULT'));
        if (Validate::isLoadedObject($currency)) {
            $context->currency = $currency;
        }
        $country = new Country((int) Configuration::get('PS_COUNTRY_DEFAULT'));
        if (Validate::isLoadedObject($country)) {
            $context->country = $country;
        }
        $context->customer = new Customer();
        $context->cart = new Cart();
        $context->link = new Link();

        return $context;
    }

    public static function currentShop()
    {
        return self::$currentShop;
    }
}
