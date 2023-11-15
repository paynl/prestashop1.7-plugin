<?php

namespace PaynlPaymentMethods\PrestaShop;

use Configuration;
use Tools;

class PayHelper
{
    /**
     * @param $useMultiCore
     * @return void
     */
    public static function sdkLogin($useMultiCore = false)
    {
        $apitoken = Tools::getValue('PAYNL_API_TOKEN', Configuration::get('PAYNL_API_TOKEN'));
        if (empty($apitoken) && !empty(Configuration::get('PAYNL_API_TOKEN'))) {
            $apitoken = Configuration::get('PAYNL_API_TOKEN');
        }
        $serviceId = Tools::getValue('PAYNL_SERVICE_ID', Configuration::get('PAYNL_SERVICE_ID'));

        if ($useMultiCore) {
            $gateway = self::getFailoverGateway();
            if (!empty(trim($gateway))) {
                \Paynl\Config::setApiBase(trim($gateway));
            }
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
            $gateway = self::getFailoverGateway();

            if (!empty($gateway) && str_contains($gateway, 'https://rest.achterelkebetaling.nl')) {
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

    /**
     * @param string $object
     * @return array
     */
    public static function checkCredentials($object)
    {

        $apiToken = Tools::getValue('PAYNL_API_TOKEN', Configuration::get('PAYNL_API_TOKEN'));
        if (empty($apiToken) && !empty(Configuration::get('PAYNL_API_TOKEN'))) {
            $apiToken = Configuration::get('PAYNL_API_TOKEN');
        }
        $serviceId = Tools::getValue('PAYNL_SERVICE_ID', Configuration::get('PAYNL_SERVICE_ID'));

        $error = '';
        $status = true;
        if (!empty($apiToken) && !empty($serviceId)) {
            try {
                PayHelper::sdkLogin();
                \Paynl\Paymentmethods::getList();
            } catch (\Exception $e) {
                $error = $e->getMessage();
            }
        } elseif (!empty($apiToken) || !empty($serviceId)) {
            $error = $object->l('API token and SL-code are required.');
        } else {
            $status = false;
        }
        if (!empty($error)) {
            switch ($error) {
                case 'HTTP/1.0 401 Unauthorized':
                    $error = $object->l('SL-code or API token invalid');
                    break;
                case 'PAY-404 - Service not found':
                    $error = $object->l('SL-code is invalid');
                    break;
                case 'PAY-403 - Access denied: Token not valid for this company':
                    $error = $object->l('SL-code / API token combination invalid');
                    break;
                default:
                    $error = $object->l('Could not authorize');
            }
            $status = false;
        }
        return ['status' => $status, 'error' => $error];
    }

    /**
     * @return mixed
     */
    public static function getFailoverGateway()
    {
        $gateway = Tools::getValue('PAYNL_FAILOVER_GATEWAY', Configuration::get('PAYNL_FAILOVER_GATEWAY'));
        if ($gateway == 'custom') {
            $gateway = Tools::getValue('PAYNL_CUSTOM_FAILOVER_GATEWAY', Configuration::get('PAYNL_CUSTOM_FAILOVER_GATEWAY'));
        }
        return $gateway;
    }
}
