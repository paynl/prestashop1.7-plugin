<?php

namespace PaynlPaymentMethods\PrestaShop;

use Tools;
use Configuration;

class PayHelper
{
    /**
     * @return void
     */
    public static function sdkLogin()
    {
        $apitoken = Tools::getValue('PAYNL_API_TOKEN', Configuration::get('PAYNL_API_TOKEN'));
        $serviceId = Tools::getValue('PAYNL_SERVICE_ID', Configuration::get('PAYNL_SERVICE_ID'));
        $gateway = Tools::getValue('PAYNL_FAILOVER_GATEWAY', Configuration::get('PAYNL_FAILOVER_GATEWAY'));

        if (!empty(trim($gateway))) {
            \Paynl\Config::setApiBase(trim($gateway));
        }
        \Paynl\Config::setApiToken($apitoken);
        \Paynl\Config::setServiceId($serviceId);
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
}
