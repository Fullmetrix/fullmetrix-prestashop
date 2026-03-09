<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

class FullmetrixConnectorCaptureContactModuleFrontController extends ModuleFrontController
{
    public $ajax = true;
    public $ssl = true;

    public function init()
    {
        // Skip parent init to avoid loading theme
        $this->ajax = true;
    }

    public function postProcess()
    {
        header('Content-Type: application/json');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Access-Control-Allow-Origin: *');

        $cartId = (int) Tools::getValue('cart_id', 0);
        $email = Tools::getValue('email', '');
        $phone = Tools::getValue('phone', '');

        // Verify cart belongs to current session (security)
        $currentCartId = (int) $this->context->cart->id;
        if ($cartId <= 0 || ($currentCartId > 0 && $cartId !== $currentCartId)) {
            $this->jsonResponse(['success' => false, 'error' => 'Invalid cart']);
        }

        if (empty($email) && empty($phone)) {
            $this->jsonResponse(['success' => false, 'error' => 'No data']);
        }

        // Validate email format
        if (!empty($email) && !Validate::isEmail($email)) {
            $this->jsonResponse(['success' => false, 'error' => 'Invalid email']);
        }

        // Clean phone (keep only digits and +)
        if (!empty($phone)) {
            $phone = preg_replace('/[^\d+]/', '', $phone);
            if (strlen($phone) < 7) {
                $phone = '';
            }
        }

        if (empty($email) && empty($phone)) {
            $this->jsonResponse(['success' => false, 'error' => 'No valid data']);
        }

        // Upsert into cart contacts table
        $db = Db::getInstance();
        $table = _DB_PREFIX_ . 'fullmetrix_cart_contacts';

        // Check if table exists (for stores that haven't upgraded yet)
        $tableExists = $db->executeS("SHOW TABLES LIKE '" . $table . "'");
        if (empty($tableExists)) {
            // Auto-create table if missing
            $db->execute('CREATE TABLE IF NOT EXISTS `' . $table . '` (
                `id_cart` INT(10) UNSIGNED NOT NULL,
                `email` VARCHAR(255) DEFAULT NULL,
                `phone` VARCHAR(50) DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id_cart`)
            ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4');
        }

        // Check if exists
        $existing = $db->getRow(
            'SELECT email, phone FROM `' . $table . '` WHERE id_cart = ' . $cartId
        );

        if ($existing) {
            // Update only if we have new data
            $updates = [];
            if (!empty($email) && (empty($existing['email']) || $existing['email'] !== $email)) {
                $updates[] = "email = '" . pSQL($email) . "'";
            }
            if (!empty($phone) && (empty($existing['phone']) || $existing['phone'] !== $phone)) {
                $updates[] = "phone = '" . pSQL($phone) . "'";
            }

            if (!empty($updates)) {
                $db->execute(
                    'UPDATE `' . $table . '` SET ' . implode(', ', $updates) .
                    ' WHERE id_cart = ' . $cartId
                );
            }
        } else {
            // Insert new
            $db->execute(
                'INSERT INTO `' . $table . '` (id_cart, email, phone, created_at) VALUES (' .
                $cartId . ', ' .
                (!empty($email) ? "'" . pSQL($email) . "'" : 'NULL') . ', ' .
                (!empty($phone) ? "'" . pSQL($phone) . "'" : 'NULL') . ', ' .
                'NOW())'
            );
        }

        $this->jsonResponse(['success' => true]);
    }

    private function jsonResponse($data)
    {
        echo json_encode($data);
        exit;
    }
}
