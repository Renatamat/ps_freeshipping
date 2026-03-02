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
        $this->description = $this->l('Shows free-shipping discount as text only in cart/order presentation and keeps discount subtotal clean.');
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
        $label = $this->getFreeShippingLabel();

        $freeShippingDiscount = 0.0;

        $vouchers = $this->getNode($presentedCart, 'vouchers');
        if ($this->isContainer($vouchers)) {
            $added = $this->getNode($vouchers, 'added');
            if (is_array($added)) {
                foreach ($added as $idx => $voucher) {
                    if (!$this->isFreeShippingRow($voucher)) {
                        continue;
                    }

                    $freeShippingDiscount += $this->extractAmount($voucher, ['reduction_float', 'reduction', 'value_float', 'value', 'amount']);
                    $voucher = $this->applyTextOnlyDiscountLabel($voucher, $label);
                    $added[$idx] = $voucher;
                }

                $this->setNode($vouchers, 'added', $added);
                $this->setNode($presentedCart, 'vouchers', $vouchers);
            }
        }

        $discountRows = $this->getNode($presentedCart, 'discounts');
        if (is_array($discountRows)) {
            foreach ($discountRows as $idx => $row) {
                if (!$this->isFreeShippingRow($row)) {
                    continue;
                }

                $freeShippingDiscount += $this->extractAmount($row, ['value_float', 'value', 'amount', 'reduction_float', 'reduction']);
                $row = $this->applyTextOnlyDiscountLabel($row, $label);
                $discountRows[$idx] = $row;
            }

            $this->setNode($presentedCart, 'discounts', $discountRows);
        }

        $subtotals = $this->getNode($presentedCart, 'subtotals');
        if ($this->isContainer($subtotals)) {
            $discountSubtotal = $this->getNode($subtotals, 'discounts');
            if ($this->isContainer($discountSubtotal) && $freeShippingDiscount > 0) {
                $current = $this->extractAmount($discountSubtotal, ['amount', 'value', 'amount_float', 'value_float']);
                $new = max(0.0, $current - $freeShippingDiscount);
                $this->setNumericPresentation($discountSubtotal, $new);
                $this->setNode($subtotals, 'discounts', $discountSubtotal);
            }

            if ($freeShippingDiscount > 0 || $this->hasAnyFreeShippingRule($presentedCart)) {
                $shippingSubtotal = $this->getNode($subtotals, 'shipping');
                if ($this->isContainer($shippingSubtotal)) {
                    $shippingSubtotal = $this->applyFreeShippingToShippingSubtotal($shippingSubtotal, $label);
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
        $label = $this->getFreeShippingLabel();

        $freeShippingDiscount = 0.0;

        $discounts = $this->getNode($presentedOrder, 'discounts');
        if (is_array($discounts)) {
            foreach ($discounts as $idx => $discount) {
                if (!$this->isFreeShippingRow($discount)) {
                    continue;
                }

                $freeShippingDiscount += $this->extractAmount($discount, ['value_float', 'value', 'amount', 'reduction', 'reduction_float']);
                $discount = $this->applyTextOnlyDiscountLabel($discount, $label);
                $discounts[$idx] = $discount;
            }

            $this->setNode($presentedOrder, 'discounts', $discounts);
        }

        $totals = $this->getNode($presentedOrder, 'totals');
        if ($this->isContainer($totals)) {
            $discountTotal = $this->getNode($totals, 'discounts');
            if ($this->isContainer($discountTotal) && $freeShippingDiscount > 0) {
                $current = $this->extractAmount($discountTotal, ['amount', 'value', 'amount_float', 'value_float']);
                $new = max(0.0, $current - $freeShippingDiscount);
                $this->setNumericPresentation($discountTotal, $new);
                $this->setNode($totals, 'discounts', $discountTotal);
            }

            if ($freeShippingDiscount > 0 || $this->hasAnyFreeShippingRule($presentedOrder)) {
                $shippingTotal = $this->getNode($totals, 'shipping');
                if ($this->isContainer($shippingTotal)) {
                    $shippingTotal = $this->applyFreeShippingToShippingSubtotal($shippingTotal, $label);
                    $this->setNode($totals, 'shipping', $shippingTotal);
                }
            }

            $this->setNode($presentedOrder, 'totals', $totals);
        }

        $this->debugLog('Order presentation adjusted for free shipping.');
    }

    private function getFreeShippingLabel()
    {
        return $this->trans('Free shipping', [], 'Shop.Theme.Checkout', $this->context->language->locale);
    }

    private function applyTextOnlyDiscountLabel($row, $label)
    {
        if (!$this->isContainer($row)) {
            return $row;
        }

        foreach (['reduction_formatted', 'value_formatted', 'reduction', 'value', 'amount', 'discount'] as $key) {
            if ($this->hasNode($row, $key)) {
                $this->setNode($row, $key, $label);
            }
        }

        foreach (['reduction_float', 'value_float', 'amount_float', 'discount_float'] as $key) {
            if ($this->hasNode($row, $key)) {
                $this->setNode($row, $key, 0.0);
            }
        }

        return $row;
    }

    private function applyFreeShippingToShippingSubtotal($shippingSubtotal, $label)
    {
        foreach (['value', 'value_formatted', 'label_value', 'amount_formatted'] as $textKey) {
            if ($this->hasNode($shippingSubtotal, $textKey)) {
                $this->setNode($shippingSubtotal, $textKey, $label);
            }
        }

        foreach (['amount', 'value_float', 'amount_float', 'shipping_cost', 'price', 'raw_amount'] as $numericKey) {
            if ($this->hasNode($shippingSubtotal, $numericKey)) {
                $this->setNode($shippingSubtotal, $numericKey, 0.0);
            }
        }

        return $shippingSubtotal;
    }

    private function setNumericPresentation(&$container, $amount)
    {
        foreach (['amount', 'amount_float', 'value_float', 'value'] as $key) {
            if ($this->hasNode($container, $key)) {
                $this->setNode($container, $key, $amount);
            }
        }

        foreach (['value_formatted', 'amount_formatted'] as $key) {
            if ($this->hasNode($container, $key)) {
                $this->setNode($container, $key, Tools::displayPrice($amount));
            }
        }
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

    private function extractAmount($container, array $keys)
    {
        foreach ($keys as $key) {
            $value = $this->getNode($container, $key);
            if (is_numeric($value)) {
                return abs((float) $value);
            }
        }

        return 0.0;
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
