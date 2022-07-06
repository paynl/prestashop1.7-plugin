<?php
/*
* 2007-2015 PrestaShop
*
* NOTICE OF LICENSE
*
* This source file is subject to the Academic Free License (AFL 3.0)
* that is bundled with this package in the file LICENSE.txt.
* It is also available through the world-wide-web at this URL:
* http://opensource.org/licenses/afl-3.0.php
* If you did not receive a copy of the license and are unable to
* obtain it through the world-wide-web, please send an email
* to license@prestashop.com so we can send you a copy immediately.
*
* DISCLAIMER
*
* Do not edit or add to this file if you wish to upgrade PrestaShop to newer
* versions in the future. If you wish to customize PrestaShop for your
* needs please refer to http://www.prestashop.com for more information.
*
*  @author PrestaShop SA <contact@prestashop.com>
*  @copyright  2007-2015 PrestaShop SA
*  @license    http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
*  International Registered Trademark & Property of PrestaShop SA
*/

use PaynlPaymentMethods\PrestaShop\Transaction;

/**
 * @since 1.5.0
 */
class PaynlPaymentMethodsAjaxModuleFrontController extends ModuleFrontController
{
    public function initContent()
    {
        $calltype = Tools::getValue('calltype');
        $prestaorderid = Tools::getValue('prestaorderid');
        $amount = Tools::getValue('amount');
        $module = $this->module;

        try {
            $order = new Order($prestaorderid);
        } catch (Exception $e) {
            $amount = empty($amount) ? '' : $amount;
            $module->payLog('Capture', 'Failed trying to ' . $calltype . ' ' . $amount . ' on ps-orderid ' . $prestaorderid . ' Order not found. Errormessage: ' . $e->getMessage());
            $this->returnResponse(false, 0, 'Could not find order');
        }

        $paymenyArr = $order->getOrderPayments();
        $orderPayment = reset($paymenyArr);
        $transactionId = $orderPayment->transaction_id;

        $currencyId = $orderPayment->id_currency;
        $currency = new Currency($currencyId);
        $strCurrency = $currency->iso_code;

        $cartId = !empty($order->id_cart) ? $order->id_cart : null;

        $transaction = new Transaction;

        if ($calltype == 'refund') {
            $return = $transaction->processRefund($prestaorderid, $amount, $cartId, $transactionId, $strCurrency, $module);
        } else if ($calltype == 'capture') {
            $return = $transaction->processCapture($prestaorderid, $amount, $cartId, $transactionId, $strCurrency, $module);
        }

        $this->returnResponse($return["result"], $return["amountRefunded"], $return["message"]);
    }

    /**
     * @param $result
     * @param string $amountRefunded
     * @param string $message
     */
    private function returnResponse($result, $amountRefunded = '', $message = '')
    {
        header('Content-Type: application/json;charset=UTF-8');

        $returnarray = array(
            'success' => $result,
            'amountrefunded' => $amountRefunded,
            'message' => $message
        );

        die(json_encode($returnarray));
    }
}