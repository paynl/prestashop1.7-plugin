<?php

class PaynlPaymentMethodsCaptureModuleFrontController extends ModuleFrontController
{

    public function initContent()
    {
        $prestaorderid = Tools::getValue('prestaorderid');
        $amount = Tools::getValue('amount');

        /**
         * @var $module PaynlPaymentMethods
         */
        $module = $this->module;

        try {
            $order = new Order($prestaorderid);
        } catch (Exception $e) {
            if (empty($amount)) {
                $module->payLog('Capture', 'Failed trying to do the remaining capture on ps-orderid ' . $prestaorderid . ' Order not found. Errormessage: ' . $e->getMessage());
            } else {
                $module->payLog('Capture', 'Failed trying to capture ' . $amount . ' on ps-orderid ' . $prestaorderid . ' Order not found. Errormessage: ' . $e->getMessage());
            }
            $this->captureResponse(false, 0, 'Could not find order');
        }

        $paymenyArr = $order->getOrderPayments();
        $orderPayment = reset($paymenyArr);
        $transactionId = $orderPayment->transaction_id;

        $currencyId = $orderPayment->id_currency;
        $currency = new Currency($currencyId);
        $strCurrency = $currency->iso_code;

        $cartId = !empty($order->id_cart) ? $order->id_cart : null;

        if (empty($amount)) {
            $module->payLog('Capture', 'Trying to do the remaining capture on prestashop-orderid ' . $prestaorderid, $cartId, $transactionId);
        } else {
            $module->payLog('Capture', 'Trying to capture ' . $amount . ' ' . $strCurrency . ' on prestashop-orderid ' . $prestaorderid, $cartId, $transactionId);
        }

        $arrCaptureResult = $module->doCapture($transactionId, $amount);
        $captureResult = $arrCaptureResult['data'];

        if ($arrCaptureResult['result']) {
            $module->payLog('Capture', 'Capture success, result message: ' . $cartId, $transactionId);

            if (empty($amount)) {
                $this->captureResponse(true, "", 'succesfully_captured_remaining');
            } else {
                $this->captureResponse(true, $amount, 'succesfully_captured ' . $strCurrency . ' ' . $amount);
            }
        } else {
            $module->payLog('Capture', 'Capture failed: ' . $captureResult, $cartId, $transactionId);
            $this->captureResponse(false, 0, 'could_not_process_capture');
        }

    }

    private function captureResponse($result, $amountCaptured = '', $message = '')
    {
        header('Content-Type: application/json;charset=UTF-8');

        $capturearray = array(
            'success' => $result,
            'amountcaptured' => $amountCaptured,
            'message' => $message
        );

        die(json_encode($capturearray));
    }

}