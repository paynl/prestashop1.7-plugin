<?php


namespace PaynlPaymentMethods\PrestaShop;

use Configuration;

class PaymentMethod
{
    const METHOD_SANDBOX = 613;
    const METHOD_OVERBOEKING = 136;
    const METHOD_IDEAL = 10;
    const METHOD_SOFORT = 556;
    const METHOD_INSTORE = 1729;
    const METHOD_INSTORE_PROFILE_ID = 1633;

    /**
     * @param null $transactionId
     * @param null $profileId
     * @return string
     */
    public static function getName($transactionId = null, $profileId = null)
    {
        if ($profileId == self::METHOD_SANDBOX) {
            $paymentMethodName = 'Sandbox';
        } else {
            $dbTransaction = Transaction::get($transactionId);
            if (!empty($dbTransaction['payment_option_id'])) {
                $settings = self::getPaymentMethodSettings($dbTransaction['payment_option_id']);
            } else {
                $settings = self::getPaymentMethodSettings($profileId);
            }
            $paymentMethodName = empty($settings->name) ? '' : $settings->name;
        }
        if (empty(trim($paymentMethodName))) {
            $paymentMethodName = 'PAY.';
        }
        return $paymentMethodName;
    }

    /**
     * Retrieve the settings of a specific payment with payment_profile_id
     *
     * @param $payment_profile_id
     * @return bool
     */
    public static function getPaymentMethodSettings($payment_profile_id)
    {
        $paymentMethods = json_decode(Configuration::get('PAYNL_PAYMENTMETHODS'));
        foreach ($paymentMethods as $objPaymentSettings) {
            if ($objPaymentSettings->id == $payment_profile_id) {
                return $objPaymentSettings;
            }
        }
        return false;
    }

}