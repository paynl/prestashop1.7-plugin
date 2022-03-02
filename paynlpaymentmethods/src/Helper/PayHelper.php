<?php


namespace PaynlPaymentMethods\PrestaShop\Helper;

use PrestaShopLogger;
use Db;

class PayHelper
{   

    /**
     * Adds the transaction to the pay_transactions table
     *
     * @param int $transaction_id
     * @param int $cart_id
     * @param int $customer_id
     * @param int $payment_option_id
     * @param float $amount
     *      
     */
    public static function addTransaction($transaction_id, $cart_id, $customer_id, $payment_option_id, $amount)
    {
        $db = Db::getInstance();
    
        $data = array(
            'transaction_id' => $transaction_id,
            'cart_id' => $cart_id,
            'customer_id' => $customer_id,
            'payment_option_id' => $payment_option_id,
            'amount' => $amount 
        );        

        $db->insert('pay_transactions', $data);
    }

    /**
     * Adds the pinnterminal hash to the transaction, this is required for the instore option
     *
     * @param int $transaction_id
     * @param string $hash
     *      
     */
    public static function addTransactionHash($transaction_id, $hash)
    {
        $db = Db::getInstance();

        $sql = "UPDATE `" . _DB_PREFIX_ . "pay_transactions` SET `hash` = '" . $hash . "', `updated_at` = now() WHERE `" . _DB_PREFIX_ . "pay_transactions`.`transaction_id` = '" . $transaction_id . "';";
        $db->execute($sql);
    }

}