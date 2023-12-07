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

/**
* @phpcs:disable Squiz.Commenting.FunctionComment.TypeHintMissing
*/

//check if the SDK nieeds to be loaded
if (!class_exists('\Paynl\Paymentmethods')) {
    $autoload_location = __DIR__ . '/vendor/autoload.php';
    if (file_exists($autoload_location)) {
        require_once $autoload_location;
    }
}

use Paynl\Result\Transaction\Refund;
use PaynlPaymentMethods\PrestaShop\PayHelper;
use PrestaShop\PrestaShop\Core\Payment\PaymentOption;
use PaynlPaymentMethods\PrestaShop\Transaction;
use PaynlPaymentMethods\PrestaShop\PaymentMethod;
use PrestaShop\PrestaShop\Core\Domain\Order\CancellationActionType;
if (!defined('_PS_VERSION_')) {
    exit;
}

class PaynlPaymentMethods extends PaymentModule
{
    protected $_html = '';
    protected $_postErrors = array();
    private $statusPending;
    private $statusPaid;
    private $statusRefund;
    private $statusCanceled;
    private $paymentMethods;
    private $payLogEnabled;

    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->name = 'paynlpaymentmethods';
        $this->tab = 'payments_gateways';
        $this->version = '4.16.0';
        $this->payLogEnabled = null;
        $this->ps_versions_compliancy = array('min' => '1.7', 'max' => _PS_VERSION_);
        $this->author = 'PAY.';
        $this->controllers = array('startPayment', 'finish', 'exchange');
        $this->is_eu_compatible = 1;
        $this->currencies = true;
        $this->currencies_mode = 'checkbox';
        $this->bootstrap = true;
        parent::__construct();
        $this->statusPending = Configuration::get('PS_OS_CHEQUE');
        $this->statusPaid = Configuration::get('PS_OS_PAYMENT');
        $this->statusCanceled = Configuration::get('PS_OS_CANCELED');
        $this->statusRefund = Configuration::get('PS_OS_REFUND');
        $this->displayName = $this->l('PAY.');
        $this->description = $this->l('PAY. Payment Methods for PrestaShop');
        if (!count(Currency::checkPaymentCurrencies($this->id))) {
            $this->warning = $this->l('No currency has been set for this module.');
        }

        if (!$this->isRegisteredInHook('displayAdminOrder')) {
            $this->registerHook('displayAdminOrder');
        }

        if (!$this->isRegisteredInHook('displayHeader')) {
            $this->registerHook('displayHeader');
        }

        if (!$this->isRegisteredInHook('actionAdminControllerSetMedia')) {
            $this->registerHook('actionAdminControllerSetMedia');
        }

        if (!$this->isRegisteredInHook('actionOrderStatusPostUpdate')) {
            $this->registerHook('actionOrderStatusPostUpdate');
        }

        if ($this->isRegisteredInHook('paymentReturn')) {
            $this->unregisterHook('paymentReturn');
        }

        if (!$this->isRegisteredInHook('displayPaymentReturn')) {
            $this->registerHook('displayPaymentReturn');
        }

        if (!$this->isRegisteredInHook('actionProductCancel')) {
            $this->registerHook('actionProductCancel');
        }
    }

    /**
     * @param array $params
     * @return void
     */
    public function hookDisplayHeader(array $params)
    {
        $this->context->controller->addJs($this->_path . 'views/js/PAY_checkout.js');
        if (Configuration::get('PAYNL_STANDARD_STYLE') !== '0') {
            $this->context->controller->addCSS($this->_path . 'views/css/PAY_checkout.css');
        }
    }

    /**
     * @return boolean
     */
    public function install()
    {

        if (
            !parent::install()
            || !$this->registerHook('paymentOptions')
            || !$this->registerHook('displayPaymentReturn')
            || !$this->registerHook('displayAdminOrder')
            || !$this->registerHook('actionAdminControllerSetMedia')
            || !$this->registerHook('displayHeader')
            || !$this->registerHook('actionOrderStatusPostUpdate')
        ) {
            return false;
        }

        $this->createPaymentFeeProduct();
        $this->createDatabaseTable();
        return true;
    }

    /**
     * @return boolean
     */
    public function createDatabaseTable()
    {
        Db::getInstance()->execute('CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'pay_transactions` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
				        `transaction_id` varchar(255) DEFAULT NULL,
                `cart_id` int(11) DEFAULT NULL,
                `customer_id` int(11) DEFAULT NULL,
                `payment_option_id` int(11) DEFAULT NULL,
                `amount` decimal(20,6) DEFAULT NULL,
                `hash` varchar(255) DEFAULT NULL,
                `order_reference` varchar(255) DEFAULT NULL,
                `status` varchar(255) DEFAULT NULL,
                `created_at` datetime NOT NULL DEFAULT current_timestamp(),
                `updated_at` datetime NOT NULL DEFAULT current_timestamp(),
				PRIMARY KEY (`id`),
                INDEX (`transaction_id`)
			) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8 ;');
        Db::getInstance()->execute('CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'pay_processing` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `payOrderId` varchar(255) DEFAULT NULL,
            `created_at` datetime NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `payOrderId` (`payOrderId`) USING BTREE
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8 ;');
        return true;
    }

    /**
     * @return void
     */
    public function hookActionAdminControllerSetMedia()
    {
        $this->context->controller->addCSS($this->_path . 'views/css/PAY.css');
        $this->context->controller->addJS($this->_path . 'views/js/PAY.js');
    }

    /**
    * @param array $params
    * @return boolean|string|void
    */
    public function hookDisplayAdminOrder($params)
    {

        try {
            $cartId = Cart::getCartIdByOrderId((int)$params['id_order']);
            $orderId = Order::getIdByCartId($cartId);
            $order = new Order($orderId);
        } catch (Exception $e) {
            return;
        }

      # Check if the order is processed by PAY.
        if ($order->module !== 'paynlpaymentmethods') {
            return;
        }

        $orderPayments = $order->getOrderPayments();
        $orderPayment = reset($orderPayments);
        $status = 'unavailable';
        $currency = new Currency($orderPayment->id_currency);
        $transactionId = $orderPayment->transaction_id;
        $payOrderAmount = 0;
        $methodName = 'PAY.';
        try {
            $transaction = $this->getTransaction($transactionId);
            $arrTransactionDetails = $transaction->getData();
            $payOrderAmount = $transaction->getPaidAmount();
            $status = $arrTransactionDetails['paymentDetails']['stateName'];
            $amoutRefunded = $arrTransactionDetails['paymentDetails']['refundAmount'] / 100;
            $profileId = $transaction->getPaymentProfileId();
            $methodName = PaymentMethod::getName($transactionId, $profileId);
            $showCaptureButton = $transaction->isAuthorized();
            $showCaptureRemainingButton = $arrTransactionDetails['paymentDetails']['state'] == 97;
            $showRefundButton = ($transaction->isPaid() || $transaction->isPartiallyRefunded()) && ($profileId != PaymentMethod::METHOD_INSTORE_PROFILE_ID && $profileId != PaymentMethod::METHOD_INSTORE); // phpcs:ignore
        } catch (Exception $exception) {
            $showRefundButton = false;
            $showCaptureButton = false;
            $showCaptureRemainingButton = false;
        }

        $amountFormatted = number_format($order->total_paid, 2, ',', '.');
        $amountPayFormatted = number_format($payOrderAmount, 2, ',', '.');
        $amountFormattedRefunded = number_format($amoutRefunded, 2, ',', '.');
        $amountFormattedRefundable = number_format($order->total_paid - $amoutRefunded, 2, ',', '.');

        $this->context->smarty->assign(array(
        'lang' => $this->getMultiLang(),
        'this_version'    => $this->version,
        'PrestaOrderId' => $orderId,
        'amountFormatted' => $amountFormatted,
        'amountPayFormatted' => $amountPayFormatted,
        'amountFormattedRefunded' => $amountFormattedRefunded,
        'amountFormattedRefundable' => $amountFormattedRefundable,
        'amount' => $order->total_paid,
        'amoutRefunded' => $amoutRefunded,
        'currency' => $currency->iso_code,
        'pay_orderid' => $transactionId,
        'status' => $status,
        'method' => $methodName,
        'ajaxURL' => $this->context->link->getModuleLink($this->name, 'ajax', array(), true),
        'showRefundButton' => $showRefundButton,
        'showCaptureButton' => $showCaptureButton,
        'showCaptureRemainingButton' => $showCaptureRemainingButton,
        ));
        return $this->display(__FILE__, 'payorder.tpl');
    }

    /**
     * @param array $params
     * @return void
     */
    public function hookActionOrderStatusPostUpdate($params)
    {
        try {
            $orderId = (int)$params['id_order'];
            $cartId = Cart::getCartIdByOrderId($orderId);
            $order = new Order($orderId);
        } catch (Exception $e) {
            return;
        }

        # Check if the order is processed by PAY.
        if ($order->module !== 'paynlpaymentmethods') {
            return;
        }

        # Check if the order has been Shipped and Auto-capture is on
        if ($params['newOrderStatus']->shipped == 1 && Configuration::get('PAYNL_AUTO_CAPTURE')) {
            $orderPayments = $order->getOrderPayments();
            $orderPayment = reset($orderPayments);
            $transactionId = $orderPayment->transaction_id;
            $transaction = $this->getTransaction($transactionId);
            # Check if status is Authorized
            if ($transaction->isAuthorized()) {
                $this->payLog('Auto-capture', 'Starting auto-capture', $cartId, $transactionId);
                try {
                    PayHelper::sdkLogin();
                    \Paynl\Transaction::capture($transactionId);
                    $this->payLog('Auto-capture', 'Capture success ', $transactionId);
                } catch (Exception $e) {
                    $this->payLog('Auto-capture', 'Capture failed (' . $e->getMessage() . ') ', $cartId, $transactionId);
                }
            }
        }

        # Check if the order has been Cancelled and Auto-void is on
        if ($params['newOrderStatus']->template == "order_canceled" && Configuration::get('PAYNL_AUTO_VOID')) {
            $orderPayments = $order->getOrderPayments();
            $orderPayment = reset($orderPayments);
            $transactionId = $orderPayment->transaction_id;
            $transaction = $this->getTransaction($transactionId);
            # Check if status is Authorized
            if ($transaction->isAuthorized()) {
                $this->payLog('Auto-void', 'Starting auto-void', $cartId, $transactionId);
                try {
                    PayHelper::sdkLogin();
                    \Paynl\Transaction::void($transactionId);
                    $this->payLog('Auto-void', 'Void success ', $transactionId);
                } catch (Exception $e) {
                    $this->payLog('Auto-void', 'Void failed (' . $e->getMessage() . ') ', $cartId, $transactionId);
                }
            }
        }
    }

    /**
     * @param array $params
     * @return void
     */
    public function hookActionProductCancel($params)
    {
        if ($params['action'] == CancellationActionType::PARTIAL_REFUND && $params['order']->module == 'paynlpaymentmethods') {
            try {
                $cartId = $params['order']->id_cart ?? null;
                $orderId = Order::getIdByCartId($cartId);
                $order = new Order($orderId);

                $orderPayments = $order->getOrderPayments();
                $orderPayment = reset($orderPayments);

                if (!empty($orderPayment)) {
                    $currencyId = $orderPayment->id_currency;
                    $currency = new Currency($currencyId);
                    $strCurrency = $currency->iso_code;

                    $transactionId = $orderPayment->transaction_id ?? null;
                    $refundAmount = $params['cancel_amount'] ?? null;

                    PayHelper::sdkLogin();
                    \Paynl\Transaction::refund($transactionId, $refundAmount, null, null, null, $strCurrency);

                    $this->payLog('Partial Refund', 'Partial Refund (' . $refundAmount . ') success ', $transactionId);
                } else {
                    throw new Exception('Order has no Payments.');
                }
            } catch (Exception $e) {
                $this->payLog('Partial Refund', 'Partial Refund failed (' . $e->getMessage() . ') ');
                throw new Exception($this->l('Pay. Could not process Partial Refund please try again later.'));
            }
        }
        return;
    }

    /**
     * @return array
     */
    private function getMultiLang()
    {
        $lang['title'] = $this->l('PAY.');
        $lang['are_you_sure'] = $this->l('Are you sure want to refund this amount');
        $lang['are_you_sure_capture'] = $this->l('Are you sure you want to capture this transaction for this amount');
        $lang['are_you_sure_capture_remaining'] = $this->l('Are you sure you want to capture the remaining amount of this transaction?');
        $lang['refund_button'] = $this->l('REFUND');
        $lang['capture_button'] = $this->l('CAPTURE');
        $lang['capture_remaining_button'] = $this->l('CAPTURE REMAINING');
        $lang['my_text'] = $this->l('Are you sure?');
        $lang['refund_not_possible'] = $this->l('Refund is not possible');
        $lang['amount_to_refund'] = $this->l('Amount to refund');
        $lang['amount_to_capture'] = $this->l('Amount to capture');
        $lang['refunding'] = $this->l('Processing');
        $lang['capturing'] = $this->l('Processing');
        $lang['currency'] = $this->l('Currency');
        $lang['amount'] = $this->l('Amount');
        $lang['refunded'] = $this->l('Refunded');
        $lang['invalidamount'] = $this->l('Invalid amount');
        $lang['succesfully_refunded'] = $this->l('Succesfully refunded');
        $lang['succesfully_captured'] = $this->l('Succesfully captured');
        $lang['succesfully_captured_remaining'] = $this->l('Succesfully captured the remaining amount.');
        $lang['paymentmethod'] = $this->l('Paymentmethod');
        $lang['could_not_process_refund'] = $this->l('Could not process refund. Refund might be too fast or amount is invalid');
        $lang['could_not_process_capture'] = $this->l('Could not process this capture.');
        $lang['info_refund_title'] = $this->l('Refund');
        $lang['info_refund_text'] = $this->l('The orderstatus will only change to `Refunded` when the full amount is refunded. Stock wont be updated.');
        $lang['info_log_title'] = $this->l('Logs');
        $lang['info_log_text'] = $this->l('For log information see `Advanced settings` and then `Logs`. Then filter on `PAY.`.');
        $lang['info_capture_title'] = $this->l('Capture');
        $lang['info_capture_text'] = $this->l('The order will be captured via PAY. and the customer will receive the invoice of the order from the payment method they ordered with.');
        $lang['info_capture_remaining_text'] = $this->l('This order has already been partially captured, therefore you can only capture the remaining amount. The order will be captured via PAY. and the customer will receive the invoice of the order from the payment method they ordered with.'); // phpcs:ignore
        return $lang;
    }

  /**
   * Update order status
   *
   * @param string $orderId
   * @param string $orderState
   * @param string $cartId
   * @param string $transactionId
   * @return void
   */
    public function updateOrderHistory($orderId, $orderState, $cartId = '', $transactionId = '')
    {
        $this->payLog('updateOrderHistory', 'Update status. orderId: ' . $orderId . '. orderState: ' . $orderState, $cartId, $transactionId);
        $history = new OrderHistory();
        $history->id_order = $orderId;
        $history->changeIdOrderState($orderState, $orderId, true);
        $history->addWs();
    }

    /**
     * @return boolean
     */
    public function createPaymentFeeProduct()
    {
        $id_product = Configuration::get('PAYNL_FEE_PRODUCT_ID');
        $feeProduct = new Product(Configuration::get('PAYNL_FEE_PRODUCT_ID'), true);
// check if paymentfee product exists
        if (! $id_product || ! $feeProduct->id) {
            $objProduct               = new Product();
            $objProduct->price        = 0;
            $objProduct->is_virtual   = 1;
            $objProduct->out_of_stock = 2;
            $objProduct->visibility = 'none';
            foreach (Language::getLanguages() as $language) {
                $objProduct->name[$language['id_lang']] = $this->l('Payment fee');
                $objProduct->link_rewrite[$language['id_lang']] = Tools::link_rewrite($objProduct->name[$language['id_lang']]);
            }

            if ($objProduct->add()) {
//allow buy product out of stock
                StockAvailable::setProductDependsOnStock($objProduct->id, false);
                StockAvailable::setQuantity($objProduct->id, $objProduct->getDefaultIdProductAttribute(), 9999999);
                StockAvailable::setProductOutOfStock($objProduct->id, true);
//update product id
                $id_product = $objProduct->id;
                Configuration::updateValue('PAYNL_FEE_PRODUCT_ID', $id_product);
            }
        }

        return true;
    }

    /**
     * @return boolean
     */
    public function installOverrides()
    {
        // This version doesn't have overrides anymode, but prestashop still keeps them around.
        // By overriding this method we can prevent prestashop from reinstalling the old overrides
        return true;
    }

    /**
     * @return boolean
     */
    public function uninstall()
    {

        if (parent::uninstall()) {
            Configuration::deleteByName('PAYNL_FEE_PRODUCT_ID');
        }

        return true;
    }


    /**
     * @param array $params
     * @return array|false
     */
    public function hookPaymentOptions($params)
    {
        if (!$this->active) {
            return false;
        }

        if (isset($params['cart']) && !$this->checkCurrency($params['cart'])) {
            return false;
        }
        $cart = null;
        if (isset($params['cart'])) {
            $cart = $params['cart'];
        }
        $payment_options = $this->getPaymentMethods($cart);
        return $payment_options;
    }

    /**
     * @param Cart $cart
     * @return boolean
     */
    public function checkCurrency($cart)
    {
        $currency_order = new Currency($cart->id_currency);
        $currencies_module = $this->getCurrency($cart->id_currency);
        if (is_array($currencies_module)) {
            foreach ($currencies_module as $currency_module) {
                if ($currency_order->id == $currency_module['id_currency']) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param Cart $cart
     * @return array
     */
    private function getPaymentMethods($cart = null)
    {
        /**
         * @var $cart Cart
         */
        $availablePaymentMethods = $this->getPaymentMethodsForCart($cart);
        if (!isset($availablePaymentMethods[0]->brand_id)) {
        // Set brand_id if missing.
            $this->getPaymentMethodsCombined();
            $availablePaymentMethods = $this->getPaymentMethodsForCart($cart);
        }
        $bShowLogo = Configuration::get('PAYNL_SHOW_IMAGE');
        $paymentmethods = [];
        foreach ($availablePaymentMethods as $paymentMethod) {
            $objPaymentMethod = new PaymentOption();
            global $cookie;
            $iso_code = Language::getIsoById((int)$cookie->id_lang);
            $name = $paymentMethod->name;
            if (isset($paymentMethod->{'name_' . $iso_code}) && !empty($paymentMethod->{'name_' . $iso_code})) {
                $name = $paymentMethod->{'name_' . $iso_code};
            }

            $objPaymentMethod->setCallToActionText($name)
                ->setAction($this->context->link->getModuleLink(
                    $this->name,
                    'startPayment',
                    array(),
                    true
                ))
                        ->setInputs([
                    'payment_option_id' => [
                        'name' => 'payment_option_id',
                        'type' => 'hidden',
                        'value' => $paymentMethod->id,
                    ],
                ]);
            if ($bShowLogo) {
                $objPaymentMethod->setLogo($this->_path . 'views/images/' . $paymentMethod->brand_id . '.png');
                if (!empty($paymentMethod->external_logo)) {
                    $objPaymentMethod->setLogo($paymentMethod->external_logo);
                }
            }

            $strDescription = empty($paymentMethod->description) ? null : $paymentMethod->description;
            if (isset($paymentMethod->{'description_' . $iso_code}) && !empty($paymentMethod->{'description_' . $iso_code})) {
                $strDescription = $paymentMethod->{'description_' . $iso_code};
            }

            try {
                $payForm = $this->getPayForm($paymentMethod->id, $strDescription, $bShowLogo);
            } catch (Exception $e) {
            }

            if (!empty($payForm)) {
                $objPaymentMethod->setForm($payForm);
            }

            $objPaymentMethod->setModuleName('paynl');
            $paymentmethods[] = $objPaymentMethod;
        }

        return $paymentmethods;
    }


    /**
     * @param Cart $cart
     * @param integer $paymentMethodId
     * @param float $cartTotal
     * @return boolean
     */
    public function isPaymentMethodAvailable($cart, $paymentMethodId, $cartTotal = null)
    {
        if (is_null($cartTotal)) {
            $cartTotal = $cart->getOrderTotal(true, Cart::BOTH);
        }

        $paymentMethods = json_decode(Configuration::get('PAYNL_PAYMENTMETHODS'));
        $paymentMethod = array_filter($paymentMethods, function ($value) use ($paymentMethodId) {

            return $value->id == $paymentMethodId;
        });
        if (empty($paymentMethod)) {
            return false;
        }

        $paymentMethod = array_pop($paymentMethod);
        if (!isset($paymentMethod->enabled) || $paymentMethod->enabled == false) {
            return false;
        }

        $paymentFee = $this->getPaymentFee($paymentMethod, $cartTotal);
        $totalWithFee = $cartTotal + $paymentFee;
// check min and max amount
        if (!empty($paymentMethod->min_amount) && $totalWithFee < $paymentMethod->min_amount) {
            return false;
        }
        if (!empty($paymentMethod->max_amount) && $totalWithFee > $paymentMethod->max_amount) {
            return false;
        }

        // check country
        if (isset($paymentMethod->limit_countries) && $paymentMethod->limit_countries == 1) {
            $address = new Address($cart->id_address_delivery);
            $address->id_country;
            $allowed_countries = $paymentMethod->allowed_countries;
            if (!in_array($address->id_country, $allowed_countries)) {
                return false;
            }
        }

        // check carriers
        if (isset($paymentMethod->limit_carriers) && $paymentMethod->limit_carriers == 1) {
            $allowed_carriers = $paymentMethod->allowed_carriers;
            if (!in_array($cart->id_carrier, $allowed_carriers)) {
                return false;
            }
        }

        // check customer type
        $invoiceAddressId = $cart->id_address_invoice;
        $objInvoiceAddress = new Address($invoiceAddressId);
        if (isset($objInvoiceAddress->company) && isset($paymentMethod->customer_type)) {
            if (!empty(trim($objInvoiceAddress->company)) && $paymentMethod->customer_type == 'private') {
                return false;
            }
            if (empty(trim($objInvoiceAddress->company)) && $paymentMethod->customer_type == 'business') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param Cart $cart
     * @return array
     */
    private function getPaymentMethodsForCart($cart = null)
    {
        /**
         * @var $cart Cart
         */
        // Return listed payment methods if already checked
        if (isset($this->paymentMethods) && count($this->paymentMethods) > 0) {
            return $this->paymentMethods;
        }

        $paymentMethods = json_decode(Configuration::get('PAYNL_PAYMENTMETHODS'));
        if ($cart === null) {
            $this->paymentMethods = $paymentMethods;
            return $paymentMethods;
        }

        $cartTotal = $cart->getOrderTotal(true, Cart::BOTH);
        $result = array();
        foreach ($paymentMethods as $paymentMethod) {
            if ($this->isPaymentMethodAvailable($cart, $paymentMethod->id, $cartTotal)) {
                $strFee = "";
        // Show payment fee
                $paymentMethod->fee = $this->getPaymentFee($paymentMethod, $cartTotal);
                if ($paymentMethod->fee > 0) {
                    $strFee = " (+ " . Tools::displayPrice($paymentMethod->fee, (int)$cart->id_currency, true) . ")";
                }

                $paymentMethod->name .= $strFee;
                $result[] = $paymentMethod;
            }
        }
        $this->paymentMethods = $result;
        return $result;
    }

    /**
     * @param object $objPaymentMethod
     * @param integer $cartTotal
     *
     * @return string
     */
    public function getPaymentFee($objPaymentMethod, $cartTotal)
    {

        $iFee = 0;
        if (isset($objPaymentMethod->fee_value)) {
            if (isset($objPaymentMethod->fee_percentage) && $objPaymentMethod->fee_percentage == true) {
                $iFee = (float)($cartTotal * $objPaymentMethod->fee_value / 100);
            } else {
                $iFee = (float)$objPaymentMethod->fee_value;
            }
        }

        return $iFee;
    }

  /**
   * @param string $payment_option_id
   * @param null $description
   * @param boolean $logo
   * @return boolean|string
   * @throws SmartyException
   */
    private function getPayForm($payment_option_id, $description = null, $logo = true)
    {
        $paymentOptions = array();
        $paymentOptionText = null;
        $paymentDropdownText = null;
        $type = 'dropdown';
        if ($payment_option_id == PaymentMethod::METHOD_IDEAL) {
            PayHelper::sdkLogin();
            $paymentOptions = \Paynl\Paymentmethods::getBanks($payment_option_id);
            $paymentOptionText = $this->l('Please select your bank');
            $paymentDropdownText = $this->l('Choose your bank');

            $type = 'radio';
            $objPaymentMethod = $this->getPaymentMethod($payment_option_id);
            if (!empty($objPaymentMethod->bank_selection)) {
                $type = $objPaymentMethod->bank_selection;
            }
        }
        if ($payment_option_id == PaymentMethod::METHOD_INSTORE) {
            PayHelper::sdkLogin();
            $terminals = \Paynl\Instore::getAllTerminals();
            $paymentOptions = $terminals->getList();
            $paymentOptionText = $this->l('Select card terminal');
            $paymentDropdownText = $this->l('Select card terminal');
            $type = 'dropdown';
        }
        $this->context->smarty->assign([
            'action' => $this->context->link->getModuleLink($this->name, 'startPayment', array(), true),
            'banks' => $paymentOptions,
            'payment_option_id' => $payment_option_id,
            'payment_option_text' => $paymentOptionText,
            'payment_dropdown_text' => $paymentDropdownText,
            'description' => $description,
            'logoClass' => $logo ? '' : 'noLogo',
            'type' => $type,
        ]);
        return $this->context->smarty->fetch('module:paynlpaymentmethods/views/templates/front/Pay_payment_form.tpl');
    }

    /**
     * @param Cart $cart
     * @return string
     */
    private function getCartTotalPrice($cart)
    {
        $summary = $cart->getSummaryDetails();
        $id_order = (int) Order::getIdByCartId($this->id);
        $order = new Order($id_order);
        if (Validate::isLoadedObject($order)) {
            $taxCalculationMethod = $order->getTaxCalculationMethod();
        } else {
            $taxCalculationMethod = Group::getPriceDisplayMethod(Group::getCurrent()->id);
        }

        return $taxCalculationMethod == PS_TAX_EXC ?
            $summary['total_price_without_tax'] :
            $summary['total_price'];
    }

    /**
     * @param string $transactionId
     * @param null $message
     *
     * @return \Paynl\Result\Transaction\Transaction
     * @throws Exception
     */
    public function processPayment($transactionId, &$message = null)
    {
        $transaction = $this->getTransaction($transactionId);
        $arrPayData = $transaction->getData();
        $iOrderState = $this->statusPending;
        if ($transaction->isPaid() || $transaction->isAuthorized()) {
            $iOrderState = $this->statusPaid;
        } elseif ($transaction->isCanceled()) {
            $iOrderState = $this->statusCanceled;
        }
        if ($transaction->isRefunded(false)) {
            $iOrderState = $this->statusRefund;
        }

        /**
         * @var $orderState OrderStateCore
         */
        $orderState = new OrderState($iOrderState);
        $orderStateName = $orderState->name;
        if (is_array($orderStateName)) {
            $orderStateName = array_pop($orderStateName);
        }

        $cartId = $transaction->getOrderNumber();
        $this->payLog('processPayment', 'orderStateName:' . $orderStateName . '. iOrderState: ' . $iOrderState, $cartId, $transactionId);
        if (version_compare(_PS_VERSION_, '1.7.1.0', '>=')) {
            $orderId = Order::getIdByCartId($cartId);
        } else {
        # Deprecated since prestashop 1.7.1.0
            $orderId = Order::getOrderByCartId($cartId);
        }

        $profileId = $transaction->getPaymentProfileId();
        $paymentMethodName = PaymentMethod::getName($transactionId, $profileId);
        $cart = new Cart((int)$cartId);
        $this->context->cart = $cart;
        $cartTotalPrice = (version_compare(_PS_VERSION_, '1.7.7.0', '>=')) ? $cart->getCartTotalPrice() : $this->getCartTotalPrice($cart);
        $arrPayAmounts = array($transaction->getCurrencyAmount(), $transaction->getPaidCurrencyAmount(), $transaction->getPaidAmount());
        $amountPaid = in_array(round($cartTotalPrice, 2), $arrPayAmounts) ? $cartTotalPrice : null;
        if (is_null($amountPaid)) {
            if (in_array(round($cart->getOrderTotal(), 2), $arrPayAmounts)) {
                $amountPaid = $cart->getOrderTotal();
            } elseif (in_array(round($cart->getOrderTotal(false), 2), $arrPayAmounts)) {
                $amountPaid = $cart->getOrderTotal(false);
            }
        }

        $this->payLog('processPayment (order)', 'getOrderTotal: ' . $cart->getOrderTotal() . ' getOrderTotal(false): ' . $cart->getOrderTotal(false) . '. cartTotalPrice: ' . $cartTotalPrice . ' - ' . print_r($arrPayAmounts, true), $cartId, $transactionId); // phpcs:ignore
        if ($orderId) {
            $order = new Order($orderId);
            $this->payLog('processPayment (order)', 'orderStateName:' . $orderStateName . '. iOrderState: ' . $iOrderState . '. ' .
                'orderRef:' . $order->reference . '. orderModule:' . $order->module, $cartId, $transactionId);
        # Check if the order is processed by PAY.
            if ($order->module !== 'paynlpaymentmethods') {
                $message = 'Not a PAY. order. Customer seemed to used different provider. Not updating the order.';
                return $transaction;
            }

            if ($transaction->isPartiallyRefunded()) {
                $message = 'Partial refund recieved | OrderReference: ' . $order->reference;
                return $transaction;
            }

            if ($order->hasBeenPaid() && !$transaction->isRefunded(false)) {
                $message = 'Order is already paid | OrderReference: ' . $order->reference;
                return $transaction;
            }

            if (!$transaction->isRefunded(false)) {
                $orderPayment = null;
                $arrOrderPayment = OrderPayment::getByOrderReference($order->reference);
                foreach ($arrOrderPayment as $objOrderPayment) {
                    if ($objOrderPayment->transaction_id == $transactionId) {
                        $orderPayment = $objOrderPayment;
                    }
                }
                if (empty($orderPayment)) {
                    $orderPayment = new OrderPayment();
                    $orderPayment->order_reference = $order->reference;
                }
                if (empty($orderPayment->payment_method)) {
                    $orderPayment->payment_method = $paymentMethodName;
                }
                if (empty($orderPayment->amount)) {
                    $orderPayment->amount = $amountPaid;
                }
                if (empty($orderPayment->transaction_id)) {
                    $orderPayment->transaction_id = $transactionId;
                }
                if (empty($orderPayment->id_currency)) {
                    $orderPayment->id_currency = $order->id_currency;
                }

                $orderPayment->save();
        # In case of banktransfer the total_paid_real isn't set, we're doing that now.
                if ($iOrderState == $this->statusPaid && $order->total_paid_real == 0) {
                    $order->total_paid_real = $orderPayment->amount;
                    $order->save();
                }
            }

                    $this->updateOrderHistory($order->id, $iOrderState, $cartId, $transactionId);
            $message = "Updated order (" . $order->reference . ") to: " . $orderStateName;
        } else {
            $iState = !empty($arrPayData['paymentDetails']['state']) ? $arrPayData['paymentDetails']['state'] : null;
            if ($transaction->isPaid() || $transaction->isAuthorized() || $transaction->isBeingVerified()) {
                try {
                    $currency_order = new Currency($cart->id_currency);
                    $this->payLog('processPayment (paid)', 'orderStateName:' . $orderStateName . '. iOrderState: ' . $iOrderState . '. iState:' . $iState .
                      '. CurrencyOrder: ' . $currency_order->iso_code . '. CartOrderTotal: ' . $cart->getOrderTotal() .
                      '. CartTotalPrice: ' . $cartTotalPrice .
                      '. paymentMethodName: ' . $paymentMethodName .
                      '. profileId: ' . $profileId .
                      '. AmountPaid : ' . $amountPaid, $cartId, $transactionId);
                            $this->validateOrder((int)$cartId, $iOrderState, $amountPaid, $paymentMethodName, null, array('transaction_id' => $transactionId), null, false, $cart->secure_key);
                            $orderId = Order::getIdByCartId($cartId);
                            $order = new Order($orderId);
                            $message = "Validated order (" . $order->reference . ") with status: " . $orderStateName;
                            $this->payLog('processPayment', 'Order created. Amount: ' . $order->getTotalPaid(), $cartId, $transactionId);
                } catch (Exception $ex) {
                    $this->payLog('processPayment', 'Could not validate(create) order.', $cartId, $transactionId);
                    $message = "Could not validate order, error: " . $ex->getMessage();
                    throw new Exception($message);
                }
            } else {
                if ($transaction->isCanceled()) {
                    $message = "Status updated to CANCELED";
                }

                $this->payLog('processPayment 3', 'OrderStateName:' . $orderStateName . '. iOrderState: ' . $iOrderState . '. iState:' . $iState, $cartId, $transactionId);
            }
        }

        return $transaction;
    }

    /**
     * @param string $transactionId
     * @return \Paynl\Result\Transaction\Status
     * @throws \Paynl\Error\Api
     * @throws \Paynl\Error\Error
     * @throws \Paynl\Error\Required\ApiToken
     * @throws \Paynl\Error\Required\ServiceId
     */
    public function getTransaction($transactionId)
    {
        PayHelper::sdkLogin(true);
        return \Paynl\Transaction::status($transactionId);
    }

  /**
   * @param string $method
   * @param string $message
   * @param null $cartid
   * @param null $transactionId
   * @return void
   */
    public function payLog($method, $message, $cartid = null, $transactionId = null)
    {
        if (is_null($this->payLogEnabled)) {
            $this->payLogEnabled = Configuration::get('PAYNL_PAYLOGGER') == 1;
        }

        if ($this->payLogEnabled) {
            $strCartId = empty($cartid) ? '' : ' CartId: ' . $cartid;
            $strTransaction = empty($transactionId) ? '' : ' [ ' . $transactionId . ' ] ';
            PrestaShopLogger::addLog('PAY. - ' . $method . ' - ' . $strTransaction . $strCartId . ': ' . $message);
        }
    }


    /**
     * @param Cart $cart
     * @param string $payment_option_id
     * @param array $extra_data
     * @return string
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     * @throws Exception
     */
    public function startPayment(Cart $cart, $payment_option_id, $extra_data = array())
    {
        PayHelper::sdkLogin(true);
        $currency = new Currency($cart->id_currency);
/** @var CurrencyCore $currency */

        $exchangeUrl = PayHelper::getExchangeUrl($this->context->link->getModuleLink($this->name, 'exchange', array(), true));

        $objPaymentMethod = $this->getPaymentMethod($payment_option_id);
# Make sure no fee is in the cart
        $cart->deleteProduct(Configuration::get('PAYNL_FEE_PRODUCT_ID'), 0);
        $cartTotal = $cart->getOrderTotal(true, Cart::BOTH, null, null, false);
        $iPaymentFee = $this->getPaymentFee($objPaymentMethod, $cartTotal);
        $iPaymentFee = empty($iPaymentFee) ? 0 : $iPaymentFee;
        $cartId = $cart->id;
        try {
            $this->addPaymentFee($cart, $iPaymentFee);
        } catch (Exception $e) {
            $this->payLog('startPayment', 'Could not add payment fee: ' . $e->getMessage(), $cartId);
        }

        $products = $this->_getProductData($cart);
        $orderId = null;
        if ($this->shouldValidateOnStart($payment_option_id, $objPaymentMethod)) {
            $this->payLog('startPayment', 'Pre-Creating order for pp : ' . $payment_option_id, $cartId);
        # Flush the package list, so the fee is added to it.
            $this->context->cart->getPackageList(true);
            $paymentMethodSettings = PaymentMethod::getPaymentMethodSettings($payment_option_id);
            $paymentMethodName = empty($paymentMethodSettings->name) ? 'PAY. Overboeking' : $paymentMethodSettings->name;
            $this->validateOrder($cart->id, $this->statusPending, 0, $paymentMethodName, null, array(), null, false, $cart->secure_key);
            $orderId = Order::getIdByCartId($cartId);
        } else {
            $this->payLog('startPayment', 'Not pre-creating the order, waiting for payment.', $cartId);
        }

        $description = !empty($orderId) ? $orderId : $cartId;
        if (Configuration::get('PAYNL_DESCRIPTION_PREFIX')) {
            $description = Configuration::get('PAYNL_DESCRIPTION_PREFIX') . $description;
        }

        $startData = array(
            'amount' => $cart->getOrderTotal(),
            'currency' => $currency->iso_code,
            'returnUrl' => $this->context->link->getModuleLink($this->name, 'finish', array(), true),
            'exchangeUrl' => $exchangeUrl,
            'paymentMethod' => $payment_option_id,
            'description' => $description,
            'testmode' => PayHelper::isTestMode(),
            'orderNumber' => $cart->id,
            'extra1' => $cart->id,
            'extra2' => !empty($orderId) ? $orderId : null,
            'products' => $products,
            'object' => $this->getObjectInfo()
        );
        $addressData = $this->_getAddressData($cart);
        $startData = array_merge($startData, $addressData);
        if (isset($extra_data['bank'])) {
            $startData['bank'] = $extra_data['bank'];
        }

        # Retrieve language
        $startData['language'] = $this->getLanguageForOrder($cart);
        try {
            $payTransaction = \Paynl\Transaction::start($startData);
        } catch (Exception $e) {
            $this->payLog('startPayment', 'Starting new payment failed: ' . $cartTotal . '. Fee: ' . $iPaymentFee . ' Currency (cart): ' . $currency->iso_code . ' e:' . $e->getMessage(), $cartId);
            return $this->context->link->getModuleLink($this->name, 'finish', array('paymentError' => true, 'error' => PayHelper::getFriendlyMessage($e->getMessage(), $this)), true);
        }

        $payTransactionData = $payTransaction->getData();
        $payTransactionId = !empty($payTransactionData['transaction']['transactionId']) ? $payTransactionData['transaction']['transactionId'] : '';
        $this->payLog('startPayment', 'Starting new payment with cart-total: ' . $cartTotal . '. Fee: ' . $iPaymentFee . ' Currency (cart): ' . $currency->iso_code, $cartId, $payTransactionId);
        Transaction::addTransaction($payTransactionId, $cart->id, $cart->id_customer, $payment_option_id, $cart->getOrderTotal());
        if ($this->shouldValidateOnStart($payment_option_id, $objPaymentMethod)) {
            $order = new Order($orderId);
            $orderPayment = new OrderPayment();
            $orderPayment->order_reference = $order->reference;
            $orderPayment->payment_method = $paymentMethodName;
            $orderPayment->amount = $startData['amount'];
            $orderPayment->transaction_id = $payTransactionData['transaction']['transactionId'];
            $orderPayment->id_currency = $cart->id_currency;
            $orderPayment->save();
        }

        if ($payment_option_id == PaymentMethod::METHOD_INSTORE) {
            $this->payLog('startPayment', 'Starting Instore Payment', $cartId, $payTransactionId);
            $terminalId = null;
            if (isset($extra_data['bank'])) {
                $terminalId = $extra_data['bank'];
            }
            try {
                if (empty($terminalId)) {
                    throw new \Exception('Please select a pin-terminal', 201);
                }
                $instorePayment = \Paynl\Instore::payment(['transactionId' => $payTransactionId, 'terminalId' => $terminalId]);
                $hash = $instorePayment->getHash();
                Transaction::addTransactionHash($payTransactionId, $hash);
                return $instorePayment->getRedirectUrl();
            } catch (\Exception $e) {
                $this->payLog('startPayment', 'Instore Payment error: ' . $e->getMessage(), $cartId, $payTransactionId);
                return $this->context->link->getModuleLink($this->name, 'finish', array('paymentError' => true, 'error' => $this->l('Pin transaction could not be started.')), true);
            }
        }

        return $payTransaction->getRedirectUrl();
    }

    /**
     * @return false|string
     */
    private function getObjectInfo()
    {
        $object_string = 'prestashop ';
        $object_string .= !empty($this->version) ? $this->version : '-';
        $object_string .= ' | ';
        $object_string .= defined('_PS_VERSION_') ? _PS_VERSION_ : '-';
        $object_string .= ' | ';
        $object_string .= substr(phpversion(), 0, 3);
        return substr($object_string, 0, 64);
    }

    /**
     * @param string $payment_option_id
     * @return object|null
     */
    private function getPaymentMethod($payment_option_id)
    {
        foreach ($this->getPaymentMethodsForCart() as $objPaymentOption) {
            if ($objPaymentOption->id == (int)$payment_option_id) {
                return $objPaymentOption;
            }
        }

        return null;
    }

    /**
     * @param Cart $cart
     * @param string $iFee_wt
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     * @return void
     */
    private function addPaymentFee(Cart $cart, $iFee_wt)
    {
        if ($iFee_wt <= 0) {
            return;
        }
        $this->createPaymentFeeProduct();
        $feeProduct = new Product(Configuration::get('PAYNL_FEE_PRODUCT_ID'), true);
        $cart->updateQty(1, Configuration::get('PAYNL_FEE_PRODUCT_ID'));
        $cart->save();
        $vatRate = $feeProduct->tax_rate;
// if product doesn't exists, it assumes to have a taxrate 0
        if ($vatRate == 0) {
            foreach ($cart->getProducts() as $product) {
                if ($vatRate < $product['rate']) {
                    $vatRate = $product['rate'];
                }
            }
        }

        $iFee_wt = (float)number_format($iFee_wt, 2);
        $iFee = (float)number_format((float)$iFee_wt / (1 + ($vatRate / 100)), 2);
        $specific_price = new SpecificPrice();
        $specific_price->id_product = (int)$feeProduct->id;
// choosen product id
        $specific_price->id_product_attribute = $feeProduct->getDefaultAttribute($feeProduct->id);
        $specific_price->id_cart = (int)$cart->id;
        $specific_price->id_shop = (int)$this->context->shop->id;
        $specific_price->id_currency = 0;
        $specific_price->id_country = 0;
        $specific_price->id_group = 0;
        $specific_price->id_customer = 0;
        $specific_price->from_quantity = 1;
        $specific_price->price = (float)$iFee;
        $specific_price->reduction_type = 'amount';
        $specific_price->reduction_tax = 1;
        $specific_price->reduction = 0;
        $specific_price->from = date("Y-m-d H:i:s", strtotime('-1 day'));
        $specific_price->to = date("Y-m-d H:i:s", strtotime('+1 week'));
        $specific_price->add();
    }

    /**
     * @param Cart $cart
     *
     * @return array
     */
    private function _getProductData(Cart $cart) // phpcs:ignore
    {
        $arrResult = array();
        foreach ($cart->getProducts(true) as $product) {
            $arrResult[] = array(
                'id' => substr($product['id_product'], 0, 25),
                'name' => $product['name'],
                'price' => $product['price_wt'],
                'vatPercentage' => $product['rate'],
                'qty' => $product['cart_quantity'],
                'type' => 'ARTICLE'
            );
        }
        $shippingCost_wt = $cart->getTotalShippingCost();
        $shippingCost = $cart->getTotalShippingCost(null, false);
        $arrResult[] = array(
            'id' => 'shipping',
            'name' => $this->l('Shipping costs'),
            'price' => $shippingCost_wt,
            'tax' => $shippingCost_wt - $shippingCost,
            'qty' => 1,
            'type' => 'SHIPPING'
        );
        $free_shipping_coupon_applied = false;
        $cartDetails = $cart->GetSummaryDetails();
        $discounts = (isset($cartDetails['discounts'])) ? $cartDetails['discounts'] : array();
        foreach ($discounts as $discount) {
            if ((!empty($discount['reduction_amount']) && $discount['reduction_amount'] > 0) || (!empty($discount['reduction_percent']) && $discount['reduction_percent'] > 0) || (!empty($discount['free_shipping']) && $discount['free_shipping'] === 1 && $free_shipping_coupon_applied === false)) { // phpcs:ignore
                $discountValue = !empty($discount['value_real']) ? $discount['value_real'] : 0;
                $discountTax =  !empty($discount['value_tax_exc']) ? $discount['value_tax_exc'] : 0;
                if ($discount['free_shipping'] === 1 && $free_shipping_coupon_applied === true) {
                    $discountValue -= $shippingCost_wt;
                    $discountTax -= $shippingCost;
                }
                if ($discountValue > 0) {
                    $arrResult[] = array(
                    'id' => (empty(substr($discount['code'], 0, 25))) ? 'discount' : substr($discount['code'], 0, 25),
                    'name' => $discount['description'],
                    'price' => -$discountValue,
                    'tax' => $discountTax - $discountValue,
                    'qty' => 1,
                    'type' => 'DISCOUNT'
                    );
                    if ($discount['free_shipping'] === 1) {
                        $free_shipping_coupon_applied = true;
                    }
                }
            }
        }

        return $arrResult;
    }

    /**
     * @param Cart $cart
     *
     * @return array
     */
    private function _getAddressData(Cart $cart) // phpcs:ignore
    {
        /** @var CartCore $cart */
        $shippingAddressId = $cart->id_address_delivery;
        $invoiceAddressId = $cart->id_address_invoice;
        $customerId = $cart->id_customer;
        $objShippingAddress = new Address($shippingAddressId);
        $objInvoiceAddress = new Address($invoiceAddressId);
        $customer = new Customer($customerId);
/** @var AddressCore $objShippingAddress */
        /** @var AddressCore $objInvoiceAddress */
        /** @var CustomerCore $customer */
        $enduser = array();
        $enduser['initials'] = $objShippingAddress->firstname;
        $enduser['firstName'] = $objShippingAddress->firstname;
        $enduser['lastName'] = $objShippingAddress->lastname;
        $enduser['birthDate'] = $this->getDOB($customer->birthday);
        $enduser['phoneNumber'] = $objShippingAddress->phone ? $objShippingAddress->phone : $objShippingAddress->phone_mobile;
        $enduser['emailAddress'] = $customer->email;
        $enduser['gender'] = $customer->id_gender == 1 ? 'M' : ($customer->id_gender == 2 ? 'F' : '');
        list($shipStreet, $shipHousenr) = Paynl\Helper::splitAddress(trim($objShippingAddress->address1 . ' ' . $objShippingAddress->address2));
        list($invoiceStreet, $invoiceHousenr) = Paynl\Helper::splitAddress(trim($objInvoiceAddress->address1 . ' ' . $objInvoiceAddress->address2));
/** @var CountryCore $shipCountry */
        $shipCountry = new Country($objShippingAddress->id_country);
        $address = array(
            'streetName' => @$shipStreet,
            'houseNumber' => @$shipHousenr,
            'zipCode' => $objShippingAddress->postcode,
            'city' => $objShippingAddress->city,
            'country' => $shipCountry->iso_code
        );
/** @var CountryCore $invoiceCountry */
        $invoiceCountry = new Country($objInvoiceAddress->id_country);
        $invoiceAddress = array(
            'initials' => substr($objInvoiceAddress->firstname, 0, 1),
            'lastName' => $objInvoiceAddress->lastname,
            'streetName' => @$invoiceStreet,
            'houseNumber' => @$invoiceHousenr,
            'zipCode' => $objInvoiceAddress->postcode,
            'city' => $objInvoiceAddress->city,
            'country' => $invoiceCountry->iso_code
        );
/** @var Company $invoiceAddress */
        $enduser['company']['name'] = $objInvoiceAddress->company;
        $enduser['company']['vatNumber'] = $objInvoiceAddress->vat_number;
        return array(
            'enduser' => $enduser,
            'address' => $address,
            'invoiceAddress' => $invoiceAddress
        );
    }

    /**
     * @param string $dob
     * @return string|null
     */
    private function getDOB($dob)
    {
        if (empty(trim($dob))) {
            return null;
        } elseif ($dob == '00-00-0000' || $dob == '0000-00-00') {
            return null;
        }
        return $dob;
    }

    /**
     * Retrieve language
     *
     * @param Cart $cart
     * @return mixed|string
     */
    private function getLanguageForOrder($cart)
    {
        $languageSetting = Tools::getValue('PAYNL_LANGUAGE', Configuration::get('PAYNL_LANGUAGE'));
        if ($languageSetting == 'auto') {
            return $this->getBrowserLanguage();
        } elseif ($languageSetting == 'cart') {
            return Language::getIsoById($cart->id_lang);
        } else {
            return $languageSetting;
        }
    }

    /**
     * @return string
     */
    private function getBrowserLanguage()
    {
        if (isset($_SERVER["HTTP_ACCEPT_LANGUAGE"])) {
            return $this->parseDefaultLanguage($_SERVER["HTTP_ACCEPT_LANGUAGE"]);
        } else {
            return $this->parseDefaultLanguage(null);
        }
    }

    /**
     * @param string $http_accept
     * @param string $deflang
     * @return string
     */
    private function parseDefaultLanguage($http_accept, $deflang = "en")
    {
        if (isset($http_accept) && strlen($http_accept) > 1) {
            $lang = array();
# Split possible languages into array
            $x = explode(",", $http_accept);
            foreach ($x as $val) {
#check for q-value and create associative array. No q-value means 1 by rule
                if (
                    preg_match(
                        "/(.*);q=([0-1]{0,1}.[0-9]{0,4})/i",
                        $val,
                        $matches
                    )
                ) {
                    $lang[$matches[1]] = (float)$matches[2] . '';
                } else {
                    $lang[$val] = 1.0;
                }
            }

            $arrLanguages = $this->getLanguages();
            $arrAvailableLanguages = array();
            foreach ($arrLanguages as $language) {
                if ($language['language_id'] != 'auto') {
                    $arrAvailableLanguages[] = $language['language_id'];
                }
            }

            #return default language (highest q-value)
            $qval = 0.0;
            foreach ($lang as $key => $value) {
                $languagecode = strtolower(substr($key, 0, 2));
                if (in_array($languagecode, $arrAvailableLanguages)) {
                    if ($value > $qval) {
                            $qval = (float)$value;
                            $deflang = $key;
                    }
                }
            }
        }

        return strtolower(substr($deflang, 0, 2));
    }

    /**
     * @return array
     */
    public function getLanguages()
    {
        return array(
            array(
                'language_id' => 'nl',
                'label' => $this->l('Dutch')
            ),
            array(
                'language_id' => 'en',
                'label' => $this->l('English')
            ),
            array(
                'language_id' => 'es',
                'label' => $this->l('Spanish')
            ),
            array(
                'language_id' => 'it',
                'label' => $this->l('Italian')
            ),
            array(
                'language_id' => 'fr',
                'label' => $this->l('French')
            ),
            array(
                'language_id' => 'de',
                'label' => $this->l('German')
            ),
            array(
                'language_id' => 'cart',
                'label' => $this->l('Webshop language')
            ),
            array(
                'language_id' => 'auto',
                'label' => $this->l('Automatic (Browser language)')
            ),
        );
    }

    /**
     * @return array
     */
    public function getGateways()
    {
        $cores = \Paynl\Config::getCores();
        $cores_array = array_merge($cores, ['custom' => $this->l('Custom')]);

        $arrResult = [];
        foreach ($cores_array as $value => $label) {
            $arrResult[] = ['failover_gateway_id' => $value, 'label' => $label];
        }

        return $arrResult;
    }

    /**
     * @param integer $payment_option_id
     * @param object $objPaymentMethod
     *
     * @return boolean
     */
    public function shouldValidateOnStart($payment_option_id, $objPaymentMethod)
    {
        if (($payment_option_id == PaymentMethod::METHOD_OVERBOEKING) || (isset($objPaymentMethod->create_order_on) && $objPaymentMethod->create_order_on == 'start')) {
            return true;
        }
        return false;
    }

    /**
     * @param string $payment_option_id
     *
     * @return string
     */
    private function getPaymentMethodName($payment_option_id)
    {
        PayHelper::sdkLogin();
        $payment_methods = \Paynl\Paymentmethods::getList();
        if (isset($payment_methods[$payment_option_id])) {
            return $payment_methods[$payment_option_id]['name'];
        } else {
            return "Unknown";
        }
    }

    /**
     * @return mixed|boolean
     */
    public function getContent()
    {

        if (Tools::isSubmit('btnSubmit')) {
            $this->_postValidation();
            if (!count($this->_postErrors)) {
                $this->_postProcess();
            } else {
                foreach ($this->_postErrors as $err) {
                    $this->_html .= $this->displayError($err);
                }
            }
        } else {
            $this->_html .= '<br />';
        }
        $loggedin = false;
        if (!class_exists('\Paynl\Paymentmethods')) {
            $this->adminDisplayWarning($this->l('Cannot find PAY. SDK, did you install the source code instead of the package?'));
            return false;
        }

        $this->_html .= $this->renderAccountSettingsForm();
        $this->_html .= $this->renderPaymentMethodsForm();
        $this->_html .= $this->renderFeatureRequest();

        return $this->_html;
    }

    /**
     * @return void
     */
    protected function _postValidation() // phpcs:ignore
    {
        if (Tools::isSubmit('btnSubmit')) {
            if (!Tools::getValue('PAYNL_API_TOKEN') && empty(Configuration::get('PAYNL_API_TOKEN'))) {
                $this->_postErrors[] = $this->l('API token is required');
            } elseif (!Tools::getValue('PAYNL_SERVICE_ID')) {
                $this->_postErrors[] = $this->l('Sales location code is required');
            }
        }
    }

    /**
     * @return void
     */
    protected function _postProcess() // phpcs:ignore
    {
        if (Tools::isSubmit('btnSubmit')) {
            if (!empty(Tools::getValue('PAYNL_API_TOKEN'))) {
                Configuration::updateValue('PAYNL_API_TOKEN', Tools::getValue('PAYNL_API_TOKEN'));
            }
            Configuration::updateValue('PAYNL_SERVICE_ID', Tools::getValue('PAYNL_SERVICE_ID'));
            Configuration::updateValue('PAYNL_TEST_MODE', Tools::getValue('PAYNL_TEST_MODE'));
            Configuration::updateValue('PAYNL_FAILOVER_GATEWAY', Tools::getValue('PAYNL_FAILOVER_GATEWAY'));
            Configuration::updateValue('PAYNL_CUSTOM_FAILOVER_GATEWAY', Tools::getValue('PAYNL_CUSTOM_FAILOVER_GATEWAY'));
            Configuration::updateValue('PAYNL_EXCHANGE_URL', Tools::getValue('PAYNL_EXCHANGE_URL'));
            Configuration::updateValue('PAYNL_VALIDATION_DELAY', Tools::getValue('PAYNL_VALIDATION_DELAY'));
            Configuration::updateValue('PAYNL_PAYLOGGER', Tools::getValue('PAYNL_PAYLOGGER'));
            Configuration::updateValue('PAYNL_DESCRIPTION_PREFIX', Tools::getValue('PAYNL_DESCRIPTION_PREFIX'));
            Configuration::updateValue('PAYNL_PAYMENTMETHODS', Tools::getValue('PAYNL_PAYMENTMETHODS'));
            Configuration::updateValue('PAYNL_LANGUAGE', Tools::getValue('PAYNL_LANGUAGE'));
            Configuration::updateValue('PAYNL_SHOW_IMAGE', Tools::getValue('PAYNL_SHOW_IMAGE'));
            Configuration::updateValue('PAYNL_STANDARD_STYLE', Tools::getValue('PAYNL_STANDARD_STYLE'));
            Configuration::updateValue('PAYNL_AUTO_CAPTURE', Tools::getValue('PAYNL_AUTO_CAPTURE'));
            Configuration::updateValue('PAYNL_TEST_IPADDRESS', Tools::getValue('PAYNL_TEST_IPADDRESS'));
            Configuration::updateValue('PAYNL_AUTO_VOID', Tools::getValue('PAYNL_AUTO_VOID'));
        }
        $this->_html .= $this->displayConfirmation($this->l('Settings updated'));
    }

    /**
     * @return string
     */
    public function renderAccountSettingsForm()
    {
        $status = PayHelper::checkCredentials($this);
        $statusHTML = '';
        if ($status['status'] == 1) {
            $statusHTML = '<span class="value pay_connect_success">' . $this->l('Pay. successfully connected') . '</span>';
        } elseif (!empty($status['error'])) {
            if ($status['error'] == 'Could not authorize') {
                $statusHTML = '<span class="value pay_connect_failure">' . sprintf($this->l('We are experiencing technical issues. Please check %s for the latest updates.'), '<a href="https://status.pay.nl" target="_BLANK">status.pay.nl</a>') . '<br/>' . $this->l('You can set your core in the \'Custom core\' input field.') . '</span>'; // phpcs:ignore
            } else {
                $statusHTML = '<span class="value pay_connect_failure">' . $this->l('Pay. connection failed') . ' (' . $status['error'] . ')' . '</span>';
            }
        } else {
            $statusHTML = '<span class="value pay_connect_empty">' . $this->l('Pay. not connected') . '</span>';
        }
        $fields_form = array(
            'form' => array(
                'legend' => array(
                    'title' => sprintf($this->l('PAY. Account Settings. Plugin version %s'), $this->version),
                    'icon' => 'icon-envelope'
                ),
                'input' => array(
                    array(
                        'type' => '',
                        'label' => $this->l('Version'),
                        'name' => 'PAYNL_VERSION',
                        'desc' => '<span class="version-check"><span id="pay-version-check-current-version">' . $this->version . '</span><span id="pay-version-check-result"></span><button type="button" value="' . $this->version . '" id="pay-version-check" class="btn btn-info">' . $this->l('Check version') . '</button></span>',  // phpcs:ignore
                    ),
                    array(
                        'type' => '',
                        'label' => $this->l('Status'),
                        'name' => 'PAYNL_STATUS',
                        'desc' => '<span class="pay-status">'.$statusHTML.'</span>', // phpcs:ignore
                    ),
                    array(
                        'type' => 'password',
                        'label' => $this->l('API-token'),
                        'name' => 'PAYNL_API_TOKEN',
                        'desc' => $this->l('You can find your API-token ') . '<a href="https://admin.pay.nl/company/tokens">' . $this->l('here') . '</a>' . $this->l(', not registered at PAY? Sign up ') . '<a href="https://www.pay.nl/en?register">' . $this->l('here') . '</a>', // phpcs:ignore
                        'required' => true
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('ServiceId'),
                        'name' => 'PAYNL_SERVICE_ID',
                        'desc' => $this->l('You can find the SL-code of your service ') . '<a href="https://admin.pay.nl/programs/programs">' . $this->l('here') . '</a>' . $this->l(', not registered at PAY? Sign up ') . '<a href="https://www.pay.nl/en?register">' . $this->l('here') . '</a>', // phpcs:ignore
                        'required' => true
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Transaction description prefix'),
                        'name' => 'PAYNL_DESCRIPTION_PREFIX',
                        'desc' => $this->l('A prefix added to the transaction description'),
                        'required' => false
                    ),
                    array(
                        'type' => 'select',
                        'label' => $this->l('Multicore'),
                        'name' => 'PAYNL_FAILOVER_GATEWAY',
                        'desc' => $this->l('Select the core to be used for processing payments'),
                        'options' => array(
                            'query' => $this->getGateways(),
                            'id' => 'failover_gateway_id',
                            'name' => 'label'
                        )
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Custom multicore'),
                        'name' => 'PAYNL_CUSTOM_FAILOVER_GATEWAY',
                        'desc' => $this->l('Leave this empty unless Pay. advised otherwise'),
                        'required' => false
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Custom exchange URL'),
                        'name' => 'PAYNL_EXCHANGE_URL',
                        'placeholder' => 'https//www.yourdomain.nl/exchange_handler',
                        'desc' => $this->l('Use your own exchange-handler.') . '<br/>' . $this->l('Example: https://www.yourdomain.nl/exchange_handler?action=#action#&order_id=#order_id#') . '<br>' . $this->l('For more info see: ') . '<a href="https://docs.pay.nl/developers#exchange-parameters">' . $this->l('docs.pay.nl') . '</a>', // phpcs:ignore
                        'required' => false
                    ),
                  array(
                    'type' => 'switch',
                    'label' => $this->l('Validation delay'),
                    'name' => 'PAYNL_VALIDATION_DELAY',
                    'desc' => $this->l('When payment is done, wait for Pay.nl to validate payment before redirecting to success page'),
                    'values' => array(
                      array(
                        'id' => 'validation_delay_on',
                        'value' => 1,
                        'label' => $this->l('Enabled')
                      ),
                      array(
                        'id' => 'validation_delay_off',
                        'value' => 0,
                        'label' => $this->l('Disabled')
                      )
                    ),
                  ),
                  array(
                    'type' => 'switch',
                    'label' => $this->l('Pay. logging'),
                    'name' => 'PAYNL_PAYLOGGER',
                    'desc' => $this->l('Log internal PAY. processing information.'),
                    'values' => array(
                      array(
                        'id' => 'paylogger_on',
                        'value' => 1,
                        'label' => $this->l('Enabled')
                      ),
                      array(
                        'id' => 'paylogger_off',
                        'value' => 0,
                        'label' => $this->l('Disabled')
                      )
                    ),
                  ),
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Test mode'),
                        'name' => 'PAYNL_TEST_MODE',
                        'desc' => $this->l('Start transactions in sandbox mode for testing.'),
                        'values' => array(
                            array(
                                'id' => 'active_on',
                                'value' => 1,
                                'label' => $this->l('Enabled')
                            ),
                            array(
                                'id' => 'active_off',
                                'value' => 0,
                                'label' => $this->l('Disabled')
                            )
                        ),
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Show images'),
                        'name' => 'PAYNL_SHOW_IMAGE',
                        'desc' => $this->l('Show the images of the payment methods in checkout.'),
                        'values' => array(
                            array(
                                'id' => 'active_on',
                                'value' => 1,
                                'label' => $this->l('Enabled')
                            ),
                            array(
                                'id' => 'active_off',
                                'value' => 0,
                                'label' => $this->l('Disabled')
                            )
                        ),
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Pay. styling'),
                        'name' => 'PAYNL_STANDARD_STYLE',
                        'desc' => $this->l('Enable this if you want to use the Pay. styling in your checkout'),
                        'values' => array(
                            array(
                                'id' => 'active_on',
                                'value' => 1,
                                'label' => $this->l('Enabled')
                            ),
                            array(
                                'id' => 'active_off',
                                'value' => 0,
                                'label' => $this->l('Disabled')
                            )
                        ),
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Auto-capture'),
                        'name' => 'PAYNL_AUTO_CAPTURE',
                        'desc' => $this->l('Capture authorized transactions automatically when order is shipped.'),
                        'values' => array(
                            array(
                                'id' => 'active_on',
                                'value' => 1,
                                'label' => $this->l('Enabled')
                            ),
                            array(
                                'id' => 'active_off',
                                'value' => 0,
                                'label' => $this->l('Disabled')
                            )
                        ),
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Auto-void'),
                        'name' => 'PAYNL_AUTO_VOID',
                        'desc' => $this->l('Void authorized transactions automatically when order is cancelled.'),
                        'values' => array(
                            array(
                                'id' => 'active_on',
                                'value' => 1,
                                'label' => $this->l('Enabled')
                            ),
                            array(
                                'id' => 'active_off',
                                'value' => 0,
                                'label' => $this->l('Disabled')
                            )
                        ),
                    ),
                    array(
                        'type' => 'select',
                        'label' => $this->l('Payment screen language'),
                        'name' => 'PAYNL_LANGUAGE',
                        'desc' => $this->l('Select the language to show the payment screen in, automatic uses the browser preference'),
                        'options' => array(
                            'query' => $this->getLanguages(),
                            'id' => 'language_id',
                            'name' => 'label'
                        )
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Test IP address'),
                        'name' => 'PAYNL_TEST_IPADDRESS',
                        'desc' => $this->l('Forces testmode on these IP addresses. Separate IP\'s by comma\'s for multiple IP\'s. ') . '<br/>' . $this->l('Current user IP address: ') . Tools::getRemoteAddr(), // phpcs:ignore
                        'required' => false
                    ),
                    array(
                        'type' => 'hidden',
                        'name' => 'PAYNL_PAYMENTMETHODS',
                    )
                ),
                'buttons' => array(
                    array(
                        'href' => '#feature_request',
                        'title' => $this->l('Suggestions?'),
                        'icon' => 'process-icon-back'
                    )
                ),
                'submit' => array(
                    'title' => $this->l('Save'),
                )
            ),
        );
        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $lang = new Language((int)Configuration::get('PS_LANG_DEFAULT'));
        $helper->default_form_language = $lang->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') ? Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') : 0;
        $this->fields_form = array();
        $helper->id = (int)Tools::getValue('id_carrier');
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'btnSubmit';
        $helper->currentIndex = $this->context->link->getAdminLink(
            'AdminModules',
            false
        ) . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->tpl_vars = array(
            'fields_value' => $this->getConfigFieldsValues(),
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id
        );
        return $helper->generateForm(array($fields_form));
    }

    /**
     * @return array
     */
    public function getConfigFieldsValues()
    {
        $paymentMethods = Tools::getValue('PAYNL_PAYMENTMETHODS', '[]');
        if ($paymentMethods == '[]') {
            $paymentMethods = $this->getPaymentMethodsCombined();
            $paymentMethods = json_encode($paymentMethods);
        }

        $showImage = Configuration::get('PAYNL_SHOW_IMAGE');
        if ($showImage === false) {
            $showImage = 1;
            Configuration::updateValue('PAYNL_SHOW_IMAGE', $showImage);
        }

        $standardStyle = Configuration::get('PAYNL_STANDARD_STYLE');
        if ($standardStyle === false) {
            $standardStyle = 1;
            Configuration::updateValue('PAYNL_STANDARD_STYLE', $standardStyle);
        }

        $logging = Configuration::get('PAYNL_PAYLOGGER');
        if ($logging === false) {
            $logging = 1;
            Configuration::updateValue('PAYNL_PAYLOGGER', $logging);
        }

        return array(
            'PAYNL_API_TOKEN' => Tools::getValue('PAYNL_API_TOKEN', Configuration::get('PAYNL_API_TOKEN')),
            'PAYNL_SERVICE_ID' => Tools::getValue('PAYNL_SERVICE_ID', Configuration::get('PAYNL_SERVICE_ID')),
            'PAYNL_TEST_MODE' => Tools::getValue('PAYNL_TEST_MODE', Configuration::get('PAYNL_TEST_MODE')),
            'PAYNL_FAILOVER_GATEWAY' => Tools::getValue('PAYNL_FAILOVER_GATEWAY', Configuration::get('PAYNL_FAILOVER_GATEWAY')),
            'PAYNL_CUSTOM_FAILOVER_GATEWAY' => Tools::getValue('PAYNL_CUSTOM_FAILOVER_GATEWAY', Configuration::get('PAYNL_CUSTOM_FAILOVER_GATEWAY')),
            'PAYNL_EXCHANGE_URL' => Tools::getValue('PAYNL_EXCHANGE_URL', Configuration::get('PAYNL_EXCHANGE_URL')),
            'PAYNL_VALIDATION_DELAY' => Tools::getValue('PAYNL_VALIDATION_DELAY', Configuration::get('PAYNL_VALIDATION_DELAY')),
            'PAYNL_PAYLOGGER' => $logging,
            'PAYNL_DESCRIPTION_PREFIX' => Tools::getValue('PAYNL_DESCRIPTION_PREFIX', Configuration::get('PAYNL_DESCRIPTION_PREFIX')),
            'PAYNL_LANGUAGE' => Tools::getValue('PAYNL_LANGUAGE', Configuration::get('PAYNL_LANGUAGE')),
            'PAYNL_SHOW_IMAGE' => $showImage,
            'PAYNL_STANDARD_STYLE' => $standardStyle,
            'PAYNL_AUTO_CAPTURE' => Tools::getValue('PAYNL_AUTO_CAPTURE', Configuration::get('PAYNL_AUTO_CAPTURE')),
            'PAYNL_AUTO_VOID' => Tools::getValue('PAYNL_AUTO_VOID', Configuration::get('PAYNL_AUTO_VOID')),
            'PAYNL_TEST_IPADDRESS' => Tools::getValue('PAYNL_TEST_IPADDRESS', Configuration::get('PAYNL_TEST_IPADDRESS')),
            'PAYNL_PAYMENTMETHODS' => $paymentMethods
        );
    }

    /**
     * @return array
     */
    private function getPaymentMethodsCombined()
    {
        $changed = false;
        $resultArray = array();
        $savedPaymentMethods = json_decode(Configuration::get('PAYNL_PAYMENTMETHODS'));
        try {
            if (Configuration::get('PAYNL_FAILOVER_GATEWAY') !== 'https://rest-api.pay.nl') {
                $resultArray = $savedPaymentMethods;
            } else {
                PayHelper::sdkLogin();
                $paymentmethods = \Paynl\Paymentmethods::getList();
                $paymentmethods = (array)$paymentmethods;
                $languages = Language::getLanguages(true);
                if (is_array($savedPaymentMethods)) {
                    foreach ($savedPaymentMethods as $paymentmethod) {
                        if (isset($paymentmethods[$paymentmethod->id])) {
                            # The paymentmethod allready exists in the config. Check if fields are set..
                            $extMethod = $paymentmethods[$paymentmethod->id];
                            if (!isset($paymentmethod->min_amount)) {
                                $paymentmethod->min_amount = isset($extMethod['min_amount']) ? intval($extMethod['min_amount'] / 100) : 0;
                                $changed = true;
                            }

                            if (!isset($paymentmethod->max_amount)) {
                                $paymentmethod->max_amount = isset($extMethod['max_amount']) ? intval($extMethod['max_amount'] / 100) : 0;
                                $changed = true;
                            }

                            if (!isset($paymentmethod->description)) {
                                $paymentmethod->description = isset($extMethod['brand']['public_description']) ? $extMethod['brand']['public_description'] : '';
                                $changed = true;
                            }

                            if (!isset($paymentmethod->brand_id)) {
                                $paymentmethod->brand_id = isset($extMethod['brand']['id']) ? $extMethod['brand']['id'] : '';
                                $changed = true;
                            }

                            if (!isset($paymentmethod->limit_countries)) {
                                $paymentmethod->limit_countries = false;
                                $changed = true;
                            }

                            if (!isset($paymentmethod->allowed_countries)) {
                                $paymentmethod->allowed_countries = [];
                                $changed = true;
                            }
                            if (isset($paymentmethod->allowed_countries) && !is_array($paymentmethod->allowed_countries)) {
                                $paymentmethod->allowed_countries = [];
                                $changed = true;
                            }

                            if (!isset($paymentmethod->limit_carriers)) {
                                $paymentmethod->limit_carriers = false;
                                $changed = true;
                            }

                            if (!isset($paymentmethod->allowed_carriers)) {
                                $paymentmethod->allowed_carriers = [];
                                $changed = true;
                            }
                            if (isset($paymentmethod->allowed_carriers) && !is_array($paymentmethod->allowed_carriers)) {
                                $paymentmethod->allowed_carriers = [];
                                $changed = true;
                            }

                            if (!isset($paymentmethod->fee_percentage)) {
                                $paymentmethod->fee_percentage = false;
                                $changed = true;
                            }

                            if (!isset($paymentmethod->fee_value)) {
                                $paymentmethod->fee_value = '';
                                $changed = true;
                            }

                            if (!isset($paymentmethod->customer_type)) {
                                $paymentmethod->customer_type = 'both';
                                $changed = true;
                            }

                            if (!isset($paymentmethod->external_logo)) {
                                $paymentmethod->external_logo = '';
                                $changed = true;
                            }

                            if (!isset($paymentmethod->create_order_on)) {
                                $paymentmethod->create_order_on = 'success';
                                $changed = true;
                            }

                            if (!isset($paymentmethod->bank_selection)) {
                                $paymentmethod->bank_selection = '';
                                if ($paymentmethod->id == PaymentMethod::METHOD_INSTORE) {
                                    $paymentmethod->bank_selection = 'dropdown';
                                }
                                if ($paymentmethod->id == PaymentMethod::METHOD_IDEAL) {
                                    $paymentmethod->bank_selection = 'radio';
                                }
                                $changed = true;
                            }

                            foreach ($languages as $language) {
                                $key_name = 'name_' . $language['iso_code'];
                                if (!isset($paymentmethod->$key_name)) {
                                    $paymentmethod->$key_name = '';
                                    $changed = true;
                                }
                                $key_description = 'description_' . $language['iso_code'];
                                if (!isset($paymentmethod->$key_description)) {
                                    $paymentmethod->$key_description = '';
                                    $changed = true;
                                }
                            }

                            $resultArray[] = $paymentmethod;
                            unset($paymentmethods[$paymentmethod->id]);
                        }
                    }
                }
                # Nieuwe payment methods voorzien van standaard values.
                foreach ($paymentmethods as $paymentmethod) {
                    $defaultArray = [
                        'id' => $paymentmethod['id'],
                        'name' => empty($paymentmethod['visibleName']) ? $paymentmethod['name'] : $paymentmethod['visibleName'],
                        'enabled' => false,
                        'min_amount' => isset($paymentmethod['min_amount']) ? intval($paymentmethod['min_amount'] / 100) : null,
                        'max_amount' => isset($paymentmethod['max_amount']) ? intval($paymentmethod['max_amount'] / 100) : null,
                        'description' => isset($paymentmethod['brand']['public_description']) ? $paymentmethod['brand']['public_description'] : '',
                        'brand_id' => isset($paymentmethod['brand']['id']) ? $paymentmethod['brand']['id'] : '',
                        'limit_countries' => false,
                        'allowed_countries' => [],
                        'limit_carriers' => false,
                        'allowed_carriers' => [],
                        'fee_percentage' => false,
                        'fee_value' => '',
                        'customer_type' => 'both',
                        'external_logo' => '',
                        'create_order_on' => 'success',
                        'bank_selection' => ''
                    ];
                    foreach ($languages as $language) {
                        $defaultArray['name_' . $language['iso_code']] = '';
                        $defaultArray['description_' . $language['iso_code']] = '';
                    }

                    $resultArray[] = (object) $defaultArray;
                    $changed = true;
                }

                if ($changed) {
                    Configuration::updateValue('PAYNL_PAYMENTMETHODS', json_encode($resultArray));
                }
            }
        } catch (\Exception  $e) {
        }

        return $resultArray;
    }

    /**
     * @return string
     */
    public function renderPaymentMethodsForm()
    {

        $this->context->controller->addJs($this->_path . 'views/js/jquery-ui/jquery-ui.js');
        $this->context->controller->addCss($this->_path . 'css/admin.css');
        $this->smarty->assign(array(
            'available_countries' => $this->getCountries(),
            'available_carriers' => $this->getCarriers(),
            'image_url' => $this->_path . 'views/images/',
            'languages' => Language::getLanguages(true),
            'paymentmethods' => (array) $this->getPaymentMethodsCombined(),
            'showExternalLogoList' => [PaymentMethod::METHOD_GIVACARD],
            'showCreateOrderOnList' => [PaymentMethod::METHOD_OVERBOEKING]
        ));
        return $this->display(__FILE__, 'admin_paymentmethods.tpl');
    }

    /**
     * @return string
     */
    public function renderFeatureRequest()
    {
        $this->context->controller->addJs($this->_path . 'views/js/jquery-ui/jquery-ui.js');
        $this->context->controller->addCss($this->_path . 'css/admin.css');
        $this->smarty->assign(array(
            'ajaxURL' => $this->context->link->getModuleLink($this->name, 'ajax', array(), true),
        ));
        return $this->display(__FILE__, 'admin_featurerequest.tpl');
    }

    /**
     * @return array
     */
    public function getCarriers()
    {
        return Carrier::getCarriers($this->context->language->id, true);
    }

    /**
     * @return array
     */
    public function getCountries()
    {
        return Country::getCountries($this->context->language->id, true);
    }
}
