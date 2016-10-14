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
require_once __DIR__ . '/vendor/autoload.php';
use PrestaShop\PrestaShop\Core\Payment\PaymentOption;

if (!defined('_PS_VERSION_')) {
    exit;
}

class PaynlPaymentMethods extends PaymentModule
{
    public $details;
    public $owner;
    public $address;
    public $extra_mail_vars;
    protected $_html = '';
    protected $_postErrors = array();

    private $statusPending;
    private $statusPaid;
    private $statusCanceled;

    public function __construct()
    {

        $this->name = 'paynlpaymentmethods';
        $this->tab = 'payments_gateways';
        $this->version = '4.0.0';
        $this->ps_versions_compliancy = array('min' => '1.7', 'max' => _PS_VERSION_);
        $this->author = 'Pay.nl';
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

        $this->displayName = $this->l('Pay.nl');
        $this->description = $this->l('Add many payment methods to you webshop');

        if (!count(Currency::checkPaymentCurrencies($this->id))) {
            $this->warning = $this->l('No currency has been set for this module.');
        }
    }

    public function install()
    {
        if (!parent::install() || !$this->registerHook('paymentOptions') || !$this->registerHook('paymentReturn')) {
            return false;
        }
        return true;
    }


    public function hookPaymentOptions($params)
    {
        if (!$this->active) {
            return;
        }

        if (!$this->checkCurrency($params['cart'])) {
            return;
        }

        $payment_options = $this->getPaymentMethods($params['cart']);

        return $payment_options;
    }

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

    private function getPaymentMethodsForCart(Cart $cart){
        /**
         * @var $cart CartCore
         */
        $cartTotal = $cart->getOrderTotal(true, Cart::BOTH);
        $paymentMethods = json_decode(Configuration::get('PAYNL_PAYMENTMETHODS'));

        $result = array();
        foreach($paymentMethods as $paymentMethod){
            if(isset($paymentMethod->enabled) && $paymentMethod->enabled == true){
                // check min and max amount
                if(!empty($paymentMethod->min_amount) && $cartTotal < $paymentMethod->min_amount){
                    continue;
                }
                if(!empty($paymentMethod->max_amount) && $cartTotal > $paymentMethod->max_amount){
                    continue;
                }

                $result[] = $paymentMethod;
            }
        }
        return $result;
    }

    private function getPaymentMethods(Cart $cart)
    {
        /**
         * @var $cart CartCore
         */
        $availablePaymentMethods = $this->getPaymentMethodsForCart($cart);

        $paymentmethods = [];
        foreach ($availablePaymentMethods as $paymentMethod) {
            $objPaymentMethod = new PaymentOption();

            $objPaymentMethod->setCallToActionText($paymentMethod->name)
                ->setAction($this->context->link->getModuleLink($this->name, 'startPayment', array(), true))
                ->setInputs([
                    'payment_option_id' => [
                        'name' => 'payment_option_id',
                        'type' => 'hidden',
                        'value' => $paymentMethod->id,
                    ],
                ])
                ->setLogo('https://www.pay.nl/images/payment_profiles/50x32/' . $paymentMethod->id . '.png');
            if(isset($paymentMethod->description)){
                $objPaymentMethod->setAdditionalInformation($paymentMethod->description);
            }

            if ($paymentMethod->id == 10) {
                $objPaymentMethod->setForm($this->getBanksForm($paymentMethod->id));
            }
            $paymentmethods[] = $objPaymentMethod;
        }
        return $paymentmethods;
    }

    private function sdkLogin()
    {
        $apitoken = Tools::getValue('PAYNL_API_TOKEN', Configuration::get('PAYNL_API_TOKEN'));
        $serviceId = Tools::getValue('PAYNL_SERVICE_ID', Configuration::get('PAYNL_SERVICE_ID'));
        \Paynl\Config::setApiToken($apitoken);
        \Paynl\Config::setServiceId($serviceId);
    }

    private function getBanksForm($payment_option_id)
    {
        $this->sdkLogin();
        $banks = \Paynl\Paymentmethods::getBanks($payment_option_id);

        $this->context->smarty->assign([
            'action' => $this->context->link->getModuleLink($this->name, 'startPayment', array(), true),
            'banks' => $banks,
            'payment_option_id' => $payment_option_id,
        ]);

        return $this->context->smarty->fetch('module:paynlpaymentmethods/views/templates/front/payment_form_ideal.tpl');
    }

    public function processPayment($transactionId, &$message = null)
    {
        $this->sdkLogin();

        $transaction = \Paynl\Transaction::get($transactionId);

        $order_state = $this->statusPending;
        if ($transaction->isPaid()) {
            $order_state = $this->statusPaid;


        } elseif ($transaction->isCanceled()) {
            $order_state = $this->statusCanceled;
            $status = 'CANCELED';
        }
        if ($transaction->isRefunded(false)) {
            $order_state = $this->statusRefund;
            $status = 'REFUND';
        }

        /**
         * @var $orderState OrderStateCore
         */
        $orderState = new OrderState($order_state);

        $cart = new Cart($transaction->getExtra1());
        /**
         * @var $cart CartCore
         */

        if ($orderId = Order::getOrderByCartId($transaction->getExtra1())) {
            $order = new Order($orderId);

            /**
             * @var $order OrderCore
             */
            if ($order->hasBeenPaid() && !$transaction->isRefunded(false)) {
                $message = 'Order is already paid | OrderRefercene: ' . $order->reference;
                return $transaction;
            }
            /**
             * @var $history OrderHistoryCore
             */
            $orderPayment = OrderPayment::getByOrderReference($order->reference);
            /**
             * @var $orderPayment OrderPaymentCore
             */
            if (empty($orderPayment)) {
                $orderPayment = new OrderPayment();
                $orderPayment->order_reference = $order->reference;
            }

            $orderPayment->payment_method = $transaction->getData()['paymentDetails']['paymentProfileName'];
            $orderPayment->amount = $transaction->getPaidCurrencyAmount();
            $orderPayment->transaction_id = $transactionId;
            $orderPayment->id_currency = $order->id_currency;

            $orderPayment->save();


            $history = new OrderHistory();

            $history->id_order = $order->id;

            $history->changeIdOrderState($order_state, $order->id, true);
            $history->addWs();

            $message = "Updated order (" . $order->reference . ") to: " . $orderState->name;

        } else {
            if ($transaction->isPaid()) {
                $this->validateOrder($transaction->getExtra1(), $order_state, $transaction->getPaidCurrencyAmount(),
                    $transaction->getData()['paymentDetails']['paymentProfileName'], null, array('transaction_id' => $transactionId),
                    null, false, $cart->secure_key);

                $orderId = Order::getOrderByCartId($transaction->getExtra1());
                $order = new Order($orderId);

                $message = "Validated order (" . $order->reference . ") with status: " . $orderState->name;
            }
        }
        return $transaction;
    }

    private function _getAddressData(Cart $cart){
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
        $enduser['initials'] = substr($objShippingAddress->firstname,0,1);
        $enduser['lastname'] = $objShippingAddress->lastname;
        $enduser['dob'] = $customer->birthday;
        $enduser['phone'] = $objShippingAddress->phone?$objShippingAddress->phone:$objShippingAddress->phone_mobile;
        $enduser['email'] = $customer->email;

        list($shipStreet, $shipHousenr) = Paynl\Helper::splitAddress(trim($objShippingAddress->address1.' '.$objShippingAddress->address2));
        list($invoiceStreet, $invoiceHousenr) = Paynl\Helper::splitAddress(trim($objInvoiceAddress->address1.' '.$objInvoiceAddress->address2));

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
            'zipcode' => $objInvoiceAddress->postcode,
            'city' => $objInvoiceAddress->city,
            'country' => $invoiceCountry->iso_code
        );
        return array(
            'enduser' => $enduser,
            'address' => $address,
            'invoiceAddress' => $invoiceAddress
        );
    }
    private function _getProductData(Cart $cart){
        /** @var CartCore $cart */
        $products = $cart->getProducts();
        $arrResult = array();
        foreach($products as $product){
            $arrResult[] = array(
                'id' => $product['id_product'],
                'name' => $product['name'],
                'price' => $product['price_wt'],
                'tax' => $product['price_wt']-$product['price'],
                'qty' => $product['cart_quantity']
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
        );

        return $arrResult;
    }
    public function startPayment(Cart $cart, $payment_option_id, $extra_data = array())
    {
        /** @var CartCore $cart */
        $this->sdkLogin();

        $currency = new Currency($cart->id_currency);
        /** @var CurrencyCore $currency */

        // todo Productdata meesturen
        $products = $this->_getProductData($cart);

        $startData = array('amount' => $cart->getOrderTotal(true, Cart::BOTH),
            'currency' => $currency->iso_code,
            'returnUrl' => $this->context->link->getModuleLink($this->name, 'finish', array(), true),
            'exchangeUrl' => $this->context->link->getModuleLink($this->name, 'exchange', array(), true),
            'paymentMethod' => $payment_option_id,
            'description' => $cart->id,
            'testmode' =>  Configuration::get('PAYNL_TEST_MODE'),
            'extra1' => $cart->id,
            'language' => Language::getIsoById($cart->id_lang),
            'products' => $products
            );
        $addressData = $this->_getAddressData($cart);
        $startData = array_merge($startData, $addressData);

        if (isset($extra_data['bank'])) {
            $startData['bank'] = $extra_data['bank'];
        }

        $result = \Paynl\Transaction::start($startData);

        if ($this->shouldValidateOnStart($payment_option_id)) {
            $this->validateOrder($cart->id, $this->statusPending, 0, $this->getPaymentMethodName($payment_option_id), null, array(),
                null, false, $cart->secure_key);
        }

        return $result->getRedirectUrl();
    }

    public function shouldValidateOnStart($payment_option_id)
    {
        if ($payment_option_id == 136) {
            return true;
        }
        return false;
    }

    private function getPaymentMethodName($payment_option_id)
    {
        $this->sdkLogin();

        $payment_methods = \Paynl\Paymentmethods::getList();
        if (isset($payment_methods[$payment_option_id])) {
            return $payment_methods[$payment_option_id]['name'];
        } else {
            return "Unknown";
        }
    }


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
        try{
            $this->sdkLogin();
            //call api to check if the credentials are correct
            \Paynl\Paymentmethods::getList();
            $loggedin = true;
        } catch (\Exception  $e){

        }

        $this->_html .= $this->renderAccountSettingsForm();
        if($loggedin){
            $this->_html .= $this->renderPaymentMethodsForm();
        }

        return $this->_html;
    }

    private function getPaymentMethodsCombined(){
        $resultArray = array();
        $savedPaymentMethods = json_decode(Configuration::get('PAYNL_PAYMENTMETHODS'));
        try{
            $this->sdkLogin();
            $paymentmethods = \Paynl\Paymentmethods::getList();
            $paymentmethods = (array)$paymentmethods;
            foreach ($savedPaymentMethods as $paymentmethod){
                if(isset($paymentmethods[$paymentmethod->id])){
                    $resultArray[] = $paymentmethod;
                    unset($paymentmethods[$paymentmethod->id]);
                }
            }
            foreach($paymentmethods as $paymentmethod){
                $resultArray[] = array(
                    'id' => $paymentmethod['id'],
                    'name' => $paymentmethod['name'],
                    'enabled' => false,
                );
            }
        } catch (\Exception  $e){

        }
        return $resultArray;
    }

    protected function _postValidation()
    {
        if (Tools::isSubmit('btnSubmit')) {
            if (!Tools::getValue('PAYNL_API_TOKEN')) {
                $this->_postErrors[] = $this->l('APItoken is required');
            } elseif (!Tools::getValue('PAYNL_SERVICE_ID')) {
                $this->_postErrors[] = $this->l('ServiceId is required');
            }

            if (empty($this->_postErrors)) {
                // check if apitoken and serviceId are valid
                $this->sdkLogin();

                try {
                    Paynl\Paymentmethods::getList();
                } catch (\Paynl\Error\Error $e) {
                    $this->_postErrors[] = $e->getMessage();
                }
            }
        }
    }

    protected function _postProcess()
    {
        if (Tools::isSubmit('btnSubmit')) {
            Configuration::updateValue('PAYNL_API_TOKEN', Tools::getValue('PAYNL_API_TOKEN'));
            Configuration::updateValue('PAYNL_SERVICE_ID', Tools::getValue('PAYNL_SERVICE_ID'));
            Configuration::updateValue('PAYNL_TEST_MODE', Tools::getValue('PAYNL_TEST_MODE'));
            Configuration::updateValue('PAYNL_PAYMENTMETHODS', Tools::getValue('PAYNL_PAYMENTMETHODS'));
        }
        $this->_html .= $this->displayConfirmation($this->l('Settings updated'));
    }

    public function renderPaymentMethodsForm(){

        $this->context->controller->addJs($this->_path.'views/js/jquery-ui/jquery-ui.js');
        $this->context->controller->addJs($this->_path.'views/js/angular/angular.js');
        $this->context->controller->addJs($this->_path.'views/js/angular-ui-sortable/sortable.js');
        $this->context->controller->addJs($this->_path.'views/js/angular-ui-switch/angular-ui-switch.js');

        $this->context->controller->addCss($this->_path.'views/js/angular-ui-switch/angular-ui-switch.css');
        $this->context->controller->addCss($this->_path.'css/admin.css');

        return $this->display(__FILE__, 'admin_paymentmethods.tpl');
    }

    public function renderAccountSettingsForm()
    {
        $fields_form = array(
            'form' => array(
                'legend' => array(
                    'title' => $this->l('Pay.nl Account Settings'),
                    'icon' => 'icon-envelope'
                ),
                'input' => array(
                    array(
                        'type' => 'text',
                        'label' => $this->l('APIToken'),
                        'name' => 'PAYNL_API_TOKEN',
                        'desc' => $this->l('You can find your API token at the bottom of https://admin.pay.nl/my_merchant'),
                        'required' => true
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('ServiceId'),
                        'name' => 'PAYNL_SERVICE_ID',
                        'desc' => $this->l('The SL-code of your service on https://admin.pay.nl/programs/programs'),
                        'required' => true
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
                        'type' => 'hidden',
                        'name' => 'PAYNL_PAYMENTMETHODS',
                    )
                ),
                'submit' => array(
                    'title' => $this->l('Save'),
                )
            ),
        );

        $helper = new HelperFormCore();
        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $lang = new Language((int)Configuration::get('PS_LANG_DEFAULT'));
        $helper->default_form_language = $lang->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') ? Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') : 0;
        $this->fields_form = array();
        $helper->id = (int)Tools::getValue('id_carrier');
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'btnSubmit';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false) . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->tpl_vars = array(
            'fields_value' => $this->getConfigFieldsValues(),
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id
        );

        return $helper->generateForm(array($fields_form));
    }

    public function getConfigFieldsValues()
    {
        return array(
            'PAYNL_API_TOKEN' => Tools::getValue('PAYNL_API_TOKEN', Configuration::get('PAYNL_API_TOKEN')),
            'PAYNL_SERVICE_ID' => Tools::getValue('PAYNL_SERVICE_ID', Configuration::get('PAYNL_SERVICE_ID')),
            'PAYNL_TEST_MODE' => Tools::getValue('PAYNL_TEST_MODE', Configuration::get('PAYNL_TEST_MODE')),
            'PAYNL_PAYMENTMETHODS' => Tools::getValue('PAYNL_PAYMENTMETHODS', json_encode($this->getPaymentMethodsCombined())),
        );
    }
}
