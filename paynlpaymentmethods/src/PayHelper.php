<?php

namespace PaynlPaymentMethods\PrestaShop;

use Configuration;
use Tools;

class PayHelper
{
    /**
     * @return void
     */
    public static function sdkLogin()
    {
        $apitoken = Tools::getValue('PAYNL_API_TOKEN', Configuration::get('PAYNL_API_TOKEN'));
        if (empty($apitoken) && !empty(Configuration::get('PAYNL_API_TOKEN'))) {
            $apitoken = Configuration::get('PAYNL_API_TOKEN');
        }
        $serviceId = Tools::getValue('PAYNL_SERVICE_ID', Configuration::get('PAYNL_SERVICE_ID'));
        $gateway = Tools::getValue('PAYNL_FAILOVER_GATEWAY', Configuration::get('PAYNL_FAILOVER_GATEWAY'));

        if (!empty(trim($gateway))) {
            \Paynl\Config::setApiBase(trim($gateway));
        }
        \Paynl\Config::setApiToken($apitoken);
        \Paynl\Config::setServiceId($serviceId);
    }

    /**
     * @return boolean
     */
    public static function isLoggedIn()
    {
        try {            
            PayHelper::sdkLogin();
            \Paynl\Paymentmethods::getList();
            return ['status' => true];
        } catch (\Paynl\Error\Error $e) {
            $gateway = Tools::getValue('PAYNL_FAILOVER_GATEWAY', Configuration::get('PAYNL_FAILOVER_GATEWAY'));
            if (!empty($gateway) && str_contains($gateway, 'https://rest-api.achterelkebetaling.nl')) {
                return ['status' => true];
            }         
            return ['status' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * @param $exchange
     * @return string
     */
    public static function getExchangeUrl(string $exchange)
    {
        $alternativeExchangeUrl = trim(Configuration::get('PAYNL_EXCHANGE_URL'));

        if (!empty($alternativeExchangeUrl)) {
            return $alternativeExchangeUrl;
        }

        return $exchange;
    }

    /**
     * @return boolean
     */
    public static function isTestMode()
    {
        $ip = Tools::getRemoteAddr();
        $ipconfig = Configuration::get('PAYNL_TEST_IPADDRESS');
        if (!empty($ipconfig)) {
            $allowed_ips = explode(',', $ipconfig);
            if (
                in_array($ip, $allowed_ips) &&
                filter_var($ip, FILTER_VALIDATE_IP) &&
                strlen($ip) > 0 &&
                count($allowed_ips) > 0
            ) {
                return true;
            }
        }
        return Configuration::get('PAYNL_TEST_MODE');
    }

    /**
     * @param string $exceptionMessage
     * @return string
     */
    public static function getFriendlyMessage($exceptionMessage, $object)
    {
        $exceptionMessage = strtolower(trim($exceptionMessage));

        if (stripos($exceptionMessage, 'minimum amount') !== false) {
            $strMessage = $object->l('Unfortunately the order amount does not fit the requirements for this payment method.');
        } elseif (stripos($exceptionMessage, 'not enabled for this service') !== false) {
            $strMessage = $object->l('The selected payment method is not enabled. Please select another payment method.');
        } else {
            $strMessage = $object->l('Unfortunately something went wrong.');
        }

        return $strMessage;
    }
}
