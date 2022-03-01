<?php


namespace PaynlPaymentMethods\PrestaShop\Helper;

use PrestaShopLogger;

class PayHelper
{
    /**
     * Duplicates the cart object.
     * Commonly used after cancel an order.
     *
     * @param Cart $cart
     * @return void
     */
    public static function logText($text)
    {
        $strTransaction = 1;
        $strCartId = 1;

       PrestaShopLogger::addLog('PAY. - ' . $text . ' - ' . $strTransaction . $strCartId . ': ' . $text);
    }

}