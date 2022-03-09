<?php


namespace PaynlPaymentMethods\PrestaShop;

use PaynlPaymentMethods\PrestaShop\PaymentMethod;
use db;

class Instore extends PaymentMethod
{
    /**
     * Show terminal errors
     *
     * @param $error
     */
    public static function terminalError($error, $object)
    {
        $object->errors[] = $object->module->l($error, 'finish');
        $object->redirectWithNotifications('index.php?controller=order&step=1');
    }

    /**
     * Check the status of the pin
     *
     * @param $hash
     * @param $transactionId
     * @param $object
     * @return \Paynl\Result\Instore\Status
     */
    public static function handlePin($hash, $transactionId, $object)
    {
        try {
            $status = \Paynl\Instore::status(['hash' => $hash]);
            Db::getInstance()->execute("UPDATE `" . _DB_PREFIX_ . "pay_transactions` SET `status` = '" . $status->getTransactionState() . "', `updated_at` = now() WHERE `" . _DB_PREFIX_ . "pay_transactions`.`transaction_id` = '" . Db::getInstance()->escape($transactionId) . "';");
            if (in_array($status->getTransactionState(), ['cancelled', 'expired', 'error'])) {
                $object->errors[] = $object->module->l('The payment could not be completed', 'finish');
                $object->redirectWithNotifications('index.php?controller=order&step=1');
            }
            return $status;
        } catch (Exception $objException) {
            $object->errors[] = $object->module->l('The payment could not be completed due to an error. Error: ' . $objException->getMessage(), 'finish');
            $object->redirectWithNotifications('index.php?controller=order&step=1');
        }
    }
}
