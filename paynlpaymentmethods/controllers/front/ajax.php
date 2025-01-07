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
    /**
     * @return void
     */
    public function init()
    {
        parent::init();

        if (!$this->isAdminSessionValid()) {
            header('HTTP/1.1 403 Forbidden');
            $this->errors[] = $this->module->l('Access Denied: You do not have permission to access this page.');
            $this->redirectWithNotifications('index.php');
            exit();
        }
    }

    /**
     * @return boolean
     */
    private function isAdminSessionValid()
    {
        $cookie = new Cookie('psAdmin');
        if (isset($cookie->id_employee)) {
            return true;
        }
        return false;
    }

    /**
     * @return void
     */
    public function initContent()
    {
        $calltype = Tools::getValue('calltype');
        $module = $this->module;
        if ($calltype == 'refund' || $calltype == 'capture') {
            $prestaorderid = Tools::getValue('prestaorderid');
            $amount = Tools::getValue('amount');
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
        }

        if ($calltype == 'refund') {
            $this->processRefund($prestaorderid, $amount, $cartId, $transactionId, $strCurrency, $module);
        } elseif ($calltype == 'capture') {
            $this->processCapture($prestaorderid, $amount, $cartId, $transactionId, $strCurrency, $module);
        } elseif ($calltype == 'feature_request') {
            $email = Tools::getValue('email');
            $message = Tools::getValue('message');
            $this->processFeatureRequest($module, $email, $message);
        }
    }

    /**
     * @param string $prestaorderid
     * @param string $amount
     * @param string $cartId
     * @param string $transactionId
     * @param string $strCurrency
     * @param string $module
     * @return void
     */
    public function processRefund($prestaorderid, $amount, $cartId, $transactionId, $strCurrency, $module)
    {
        try {
            $module->payLog('Refund', 'Trying to refund ' . $amount . ' ' . $strCurrency . ' on prestashop-orderid ' . $prestaorderid, $cartId, $transactionId);
            $arrRefundResult = Transaction::doRefund($transactionId, $amount, $strCurrency);
            $refundResult = $arrRefundResult['data'];
            if ($arrRefundResult['result']) {
                $arrResult = $refundResult->getData();
                $amountRefunded = !empty($arrResult['amountRefunded']) ? $arrResult['amountRefunded'] : '';
                $desc = !empty($arrResult['description']) ? $arrResult['description'] : 'empty';
                $module->payLog('Refund', 'Refund success, result message: ' . $desc, $cartId, $transactionId);
                $this->returnResponse(true, $amountRefunded, 'succesfully_refunded ' . $strCurrency . ' ' . $amount);
            } else {
                throw new Exception('Could not process refund.');
            }
        } catch (\Exception $e) {
            $module->payLog('Refund', 'Refund failed: ' . $e->getMessage(), $cartId, $transactionId);
            $this->returnResponse(false, 0, $e->getMessage());
        }
    }

    /**
     * @param string $prestaorderid
     * @param string $amount
     * @param string $cartId
     * @param string $transactionId
     * @param string $strCurrency
     * @param string $module
     * @return void
     */
    public function processCapture($prestaorderid, $amount, $cartId, $transactionId, $strCurrency, $module)
    {
        $amount = empty($amount) ? '' : $amount;
        $module->payLog('Capture', 'Trying to capture ' . $amount . ' ' . $strCurrency . ' on prestashop-orderid ' . $prestaorderid, $cartId, $transactionId);
        $arrCaptureResult = Transaction::doCapture($transactionId, $amount);
        $captureResult = $arrCaptureResult['data'];
        if ($arrCaptureResult['result']) {
            $module->payLog('Capture', 'Capture success', $cartId, $transactionId);
            $amount = empty($amount) ? '' : $amount;
            $this->returnResponse(true, $amount, 'succesfully_captured ' . $strCurrency . ' ' . $amount);
        } else {
            $module->payLog('Capture', 'Capture failed: ' . $captureResult, $cartId, $transactionId);
            $this->returnResponse(false, 0, 'could_not_process_capture');
        }
    }

    /**
     * @param string $module
     * @param string $email
     * @param string $message
     * @return void
     */
    public function processFeatureRequest($module, $email = '', $message = '')
    {
        try {
            $message_HTML = '<p>A client has sent a feature request via Prestashop.</p><br/>';
            if (!empty($email)) {
                $message_HTML .= '<p> Email: ' . $email . '</p>';
            }
            $message_HTML .= '<p> Message: <br/><p style="border:solid 1px #ddd; padding:5px;">' . nl2br($message) . '</p></p>';
            $message_HTML .= '<p> Plugin version: ' . $module->version . '</p>';
            $message_HTML .= '<p> Prestashop version: ' . _PS_VERSION_ . '</p>';
            $message_HTML .= '<p> PHP version: ' . substr(phpversion(), 0, 3) . '</p>';
            Mail::Send(
                (int) (Configuration::get('PS_LANG_DEFAULT')), // defaut language id
                'reply_msg', // email template file to be use
                ' Feature Request - Prestashop', // email subject
                array(
                    '{firstname}' => 'Pay.',
                    '{lastname}' => 'Plugin Team',
                    '{reply}' => $message_HTML,
                ),
                'webshop@pay.nl', // receiver email address
                'Pay. Plugins', //receiver name
                null, //from email address
                null//from name
            );
            $this->returnResponse(true, 0, 'succesfully_sent');
        } catch (Exception $e) {
            $module->payLog('FeatureRequest', 'Failed:' . $e->getMessage());
            $this->returnResponse(false, 0, 'error');
        }
    }

    /**
     * @param string $result
     * @param string $amountRefunded
     * @param string $message
     * @return void
     */
    private function returnResponse($result, $amountRefunded = '', $message = '')
    {
        header('Content-Type: application/json;charset=UTF-8');
        $returnarray = array(
            'success' => $result,
            'amountrefunded' => $amountRefunded,
            'message' => $message,
        );
        die(json_encode($returnarray));
    }
}
