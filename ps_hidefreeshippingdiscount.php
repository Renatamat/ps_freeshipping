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
        $this->version = '1.0.1';
        $this->author = 'Custom';
        $this->need_instance = 0;

        parent::__construct();

        $this->displayName = $this->l('Hide free shipping discount amount');
        $this->description = $this->l('Replaces free-shipping voucher amount with "Free shipping" label in cart and order presentation.');
    }

    public function install()
    {
        return parent::install()
            && $this->registerHook('actionPresentCart')
            && $this->registerHook('actionPresentOrder');
    }

   public function hookActionPresentCart(array &$params)
    {
       var_dump(empty($params['presentedCart']));
       exit;
        if (empty($params['presentedCart'])) {
            return;
        }
        
        $presentedCart = $params['presentedCart'];

        // PS 8: zwykle CartLazyArray (ArrayAccess), nie array
        if (!is_array($presentedCart) && !($presentedCart instanceof ArrayAccess)) {
            return;
        }

        // teraz już przejdzie
        // PrestaShopLogger::addLog('actionPresentCart OK', 1);

        $label = $this->trans('Free shipping', [], 'Shop.Theme.Checkout', $this->context->language->locale);

        $vouchers = $presentedCart['vouchers'] ?? null;
        if (is_array($vouchers) && !empty($vouchers['added']) && is_array($vouchers['added'])) {
            foreach ($vouchers['added'] as &$voucher) {
                if ($this->isFreeShippingVoucher($voucher)) {
                    $voucher['reduction_formatted'] = $label;
                }
            }
            unset($voucher);

            // WAŻNE: przy LazyArray najlepiej robić offsetSet
            if ($presentedCart instanceof ArrayAccess) {
                // CartLazyArray ma offsetSet; 3-ci parametr (true) bywa wspierany jak w OrderLazyArray,
                // ale jeśli rzuci błąd, użyj bez niego.
                try {
                    $presentedCart->offsetSet('vouchers', $vouchers, true);
                } catch (Throwable $e) {
                    $presentedCart->offsetSet('vouchers', $vouchers);
                }
            } else {
                $params['presentedCart']['vouchers'] = $vouchers;
            }
        }
    }

    public function hookActionPresentOrder(array &$params)
    {
        // w części instalacji najpierw ogarnijmy koszyk; order zostawiamy bezpiecznie
        if (empty($params['presentedOrder'])) {
            return;
        }
        var_dump('aaaaaaaaaaa');
        exit;
        // Część instalacji ma OrderLazyArray, część zwykłą tablicę; obsłużmy oba
        $label = $this->trans('Free shipping', [], 'Shop.Theme.Checkout', $this->context->language->locale);

        if ($params['presentedOrder'] instanceof OrderLazyArray) {
            $presentedOrder = $params['presentedOrder'];
            $discounts = $presentedOrder['discounts'] ?? null;

            if (is_array($discounts)) {
                foreach ($discounts as &$discount) {
                    if ($this->isFreeShippingVoucher($discount)) {
                        if (isset($discount['value_formatted'])) $discount['value_formatted'] = $label;
                        if (isset($discount['value'])) $discount['value'] = $label;
                    }
                }
                unset($discount);
                $presentedOrder->offsetSet('discounts', $discounts, true);
            }

            return;
        }

        // fallback: jeśli to tablica
        if (is_array($params['presentedOrder']) && !empty($params['presentedOrder']['discounts']) && is_array($params['presentedOrder']['discounts'])) {
            foreach ($params['presentedOrder']['discounts'] as &$discount) {
                if ($this->isFreeShippingVoucher($discount)) {
                    if (isset($discount['value_formatted'])) $discount['value_formatted'] = $label;
                    if (isset($discount['value'])) $discount['value'] = $label;
                }
            }
            unset($discount);
        }
    }

    private function isFreeShippingVoucher($row): bool
    {

        if (!is_array($row)) {
            return false;
        }

        // Jeśli prezenter już podał flagę
        if (!empty($row['free_shipping'])) {
            return true;
        }

        // Najczęściej jest id_cart_rule
        $idCartRule = (int)($row['id_cart_rule'] ?? 0);
        if ($idCartRule > 0) {
            $rule = new CartRule($idCartRule);
            return Validate::isLoadedObject($rule) && (int)$rule->free_shipping === 1;
        }

        return false;
    }
}