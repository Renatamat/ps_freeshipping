<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\PrestaShop\Adapter\Presenter\Order\OrderLazyArray;

class Ps_hidefreeshippingdiscount extends Module
{
    public function __construct()
    {
        $this->name = 'ps_hidefreeshippingdiscount';
        $this->tab = 'front_office_features';
        $this->version = '1.1.0';
        $this->author = 'Custom';
        $this->need_instance = 0;

        parent::__construct();

        $this->displayName = $this->l('Hide free shipping discount amount');
        $this->description = $this->l('Shows only free shipping text in shipping subtotal when a free-shipping voucher is applied.');
    }

    public function install()
    {
        return parent::install()
            && $this->registerHook('actionPresentCart')
            && $this->registerHook('actionPresentOrder');
    }

    public function hookActionPresentCart(array &$params)
    {
        if (empty($params['presentedCart'])) {
            return;
        }

        $presentedCart = &$params['presentedCart'];
        $label = $this->getFreeShippingText();

        $subtotals = $this->getNode($presentedCart, 'subtotals');
        if ($this->isContainer($subtotals)) {
            if ($this->hasAnyFreeShippingRule($presentedCart)) {
                $shippingSubtotal = $this->getNode($subtotals, 'shipping');
                if ($this->isContainer($shippingSubtotal)) {
                    $shippingSubtotal = $this->applyFreeShippingTextToShippingSubtotal($shippingSubtotal, $label);
                    $this->setNode($subtotals, 'shipping', $shippingSubtotal);
                }
            }

            $this->setNode($presentedCart, 'subtotals', $subtotals);
        }

        $this->debugLog('Cart presentation adjusted for free shipping.');
    }

    public function hookActionPresentOrder(array &$params)
    {
        if (empty($params['presentedOrder'])) {
            return;
        }

        $presentedOrder = &$params['presentedOrder'];
        $label = $this->getFreeShippingText();

        $totals = $this->getNode($presentedOrder, 'totals');
        if ($this->isContainer($totals)) {
            if ($this->hasAnyFreeShippingRule($presentedOrder)) {
                $shippingTotal = $this->getNode($totals, 'shipping');
                if ($this->isContainer($shippingTotal)) {
                    $shippingTotal = $this->applyFreeShippingTextToShippingSubtotal($shippingTotal, $label);
                    $this->setNode($totals, 'shipping', $shippingTotal);
                }
            }

            $this->setNode($presentedOrder, 'totals', $totals);
        }

        $this->debugLog('Order presentation adjusted for free shipping.');
    }

    private function getFreeShippingText()
    {
        return $this->l('Za darmo');
    }

    private function applyFreeShippingTextToShippingSubtotal($shippingSubtotal, $label)
    {
        foreach (['value', 'value_formatted', 'label_value', 'amount_formatted'] as $textKey) {
            if ($this->hasNode($shippingSubtotal, $textKey)) {
                $this->setNode($shippingSubtotal, $textKey, $label);
            }
        }

        return $shippingSubtotal;
    }

    private function isFreeShippingRow($row)
    {
        if (!$this->isContainer($row)) {
            return false;
        }

        $flag = $this->getNode($row, 'free_shipping');
        if ((int) $flag === 1 || $flag === true) {
            return true;
        }

        $idCartRule = (int) $this->getNode($row, 'id_cart_rule');
        if ($idCartRule <= 0) {
            $idCartRule = (int) $this->getNode($row, 'cart_rule_id');
        }
        if ($idCartRule <= 0) {
            $idCartRule = (int) $this->getNode($row, 'id');
        }

        if ($idCartRule > 0) {
            $rule = new CartRule($idCartRule, (int) $this->context->language->id);
            if (Validate::isLoadedObject($rule) && (int) $rule->free_shipping === 1) {
                return true;
            }
        }

        return false;
    }

    private function hasAnyFreeShippingRule($container)
    {
        foreach (['vouchers', 'discounts'] as $key) {
            $node = $this->getNode($container, $key);
            if (!$this->isContainer($node) && !is_array($node)) {
                continue;
            }

            if ($key === 'vouchers' && $this->isContainer($node)) {
                $node = $this->getNode($node, 'added');
            }

            if (is_array($node)) {
                foreach ($node as $row) {
                    if ($this->isFreeShippingRow($row)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private function isContainer($value)
    {
        return is_array($value) || $value instanceof ArrayAccess || $value instanceof OrderLazyArray;
    }

    private function hasNode($container, $key)
    {
        if (is_array($container)) {
            return array_key_exists($key, $container);
        }

        if ($container instanceof ArrayAccess) {
            return $container->offsetExists($key);
        }

        return false;
    }

    private function getNode($container, $key)
    {
        if (is_array($container)) {
            return array_key_exists($key, $container) ? $container[$key] : null;
        }

        if ($container instanceof ArrayAccess) {
            return $container->offsetExists($key) ? $container[$key] : null;
        }

        return null;
    }

    private function setNode(&$container, $key, $value)
    {
        if (is_array($container)) {
            $container[$key] = $value;
            return;
        }

        if ($container instanceof ArrayAccess) {
            try {
                $container->offsetSet($key, $value, true);
            } catch (Throwable $e) {
                $container->offsetSet($key, $value);
            }
        }
    }

    private function debugLog($message)
    {
        if (defined('_PS_MODE_DEV_') && _PS_MODE_DEV_ === true) {
            PrestaShopLogger::addLog('[ps_hidefreeshippingdiscount] ' . $message, 1);
        }
    }
}
