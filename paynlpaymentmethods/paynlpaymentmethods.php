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
//check if the SDK nieeds to be loaded
if ( ! class_exists('\Paynl\Paymentmethods')) {
    $autoload_location = __DIR__ . '/vendor/autoload.php';
    if (file_exists($autoload_location)) {
        require_once $autoload_location;
    }
}

use PrestaShop\PrestaShop\Core\Payment\PaymentOption;

if ( ! defined('_PS_VERSION_')) {
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
	private $paymentMethods;
	private $payment_option_id;
	private $cartTotal;
	private $taxRate;

	const DEFAULT_FEE_STOCK = 1;

	public function __construct() {
		$this->name                   = 'paynlpaymentmethods';
		$this->tab                    = 'payments_gateways';
		$this->version                = '4.1.0';
		$this->ps_versions_compliancy = array( 'min' => '1.7', 'max' => _PS_VERSION_ );
		$this->author                 = 'Pay.nl';
		$this->controllers            = array( 'startPayment', 'finish', 'exchange' );
		$this->is_eu_compatible       = 1;

        $this->currencies      = true;
        $this->currencies_mode = 'checkbox';

        $this->bootstrap = true;
        parent::__construct();
        $this->statusPending  = Configuration::get('PS_OS_CHEQUE');
        $this->statusPaid     = Configuration::get('PS_OS_PAYMENT');
        $this->statusCanceled = Configuration::get('PS_OS_CANCELED');
        $this->statusRefund   = Configuration::get('PS_OS_REFUND');

        $this->displayName = $this->l('Pay.nl');
        $this->description = $this->l('Add many payment methods to you webshop');

        if ( ! count(Currency::checkPaymentCurrencies($this->id))) {
            $this->warning = $this->l('No currency has been set for this module.');
        }

    }

	public function install() {

		if (!parent::install()
	    || !$this->registerHook('paymentOptions')
	    || !$this->registerHook('paymentReturn')
	    || !$this->registerHook('actionValidateOrder')
		) {
			return false;
		}

		$queries = array();
		include( _PS_MODULE_DIR_ . $this->name . DIRECTORY_SEPARATOR . 'sql/install.php');
		foreach ( $queries as $query ) {
			Db::getInstance()->Execute( $query );
		}
		$this->createPaymentFeeProduct();
		return true;
	}

	private function createPaymentFeeProduct() {
		$id_product = Configuration::get( 'PAYNL_FEE_PRODUCT_ID' );

		// check if paymentfee product exists
		if ( ! $id_product ) {
			$objProduct               = new Product();
			$objProduct->price        = 0;
			$objProduct->is_virtual   = 1;
			$objProduct->out_of_stock = 2;
			$objProduct->visibility   =  'none';

			foreach ( Language::getLanguages() as $language ) {
				$objProduct->name[ $language['id_lang'] ]         = 'Payment fee';
				$objProduct->link_rewrite[ $language['id_lang'] ] = Tools::link_rewrite( $objProduct->name[ $language['id_lang'] ] );
			}

			if ( $objProduct->add() ) {
				//allow buy product out of stock
				StockAvailable::setProductDependsOnStock($objProduct->id, false);
				StockAvailable::setQuantity($objProduct->id, $objProduct->getDefaultIdProductAttribute(), 9999999);
				StockAvailable::setProductOutOfStock( $objProduct->id, true);

				//update product id
				$id_product = $objProduct->id;
				Configuration::updateValue( 'PAYNL_FEE_PRODUCT_ID', $id_product );
			}
		}
	}

	public function uninstall() {

		if ( parent::uninstall() ) {

			Configuration::deleteByName('PAYNL_FEE_PRODUCT_ID');

			$queries = array();
			include( _PS_MODULE_DIR_ . $this->name . DIRECTORY_SEPARATOR . 'sql/uninstall.php');
			foreach ( $queries as $query ) {
				Db::getInstance()->Execute( $query );
			}
		}
		return true;
	}


	public function hookPaymentOptions( $params ) {
		if ( ! $this->active ) {
			return;
		}

		if ( isset( $params['cart'] ) && ! $this->checkCurrency( $params['cart'] ) ) {
			return;
		}
		$cart = null;
		if ( isset( $params['cart'] ) ) {
			$cart = $params['cart'];
		}
		$payment_options = $this->getPaymentMethods( $cart );

        return $payment_options;
    }

	public function hookActionValidateOrder( $params ) {
		if ( $params['order']->module != $this->name ) {
			return;
		}

		$this->payment_option_id = (int) Db::getInstance()->getValue( '
            SELECT `payment_option_id`
            FROM `' . _DB_PREFIX_ . 'paynl_pfee_cart`
            WHERE id_cart = ' . (int) $params['cart']->id );
	}

	public function hookActionCartSave( $params ) {
		$fee_product_id = (int) Configuration::get( 'PAYNL_FEE_PRODUCT_ID' );
		//check if FEE already exists in cart
		$hasInCart = (int) Db::getInstance()->getValue( '
            select id_cart
            from `' . _DB_PREFIX_ . 'cart_product`
            where id_cart = ' . (int) $params['cart']->id . '
            and id_product = ' . (int) $fee_product_id . '
        ' );
		if ( ! $hasInCart ) {
			//add product to cart
			$params['cart']->updateQty( self::DEFAULT_FEE_STOCK, $fee_product_id );
		}

		$this->getPaymentFee( null, null, true );
	}

	public function checkCurrency( $cart ) {
		$currency_order    = new Currency( $cart->id_currency );
		$currencies_module = $this->getCurrency( $cart->id_currency );

        if (is_array($currencies_module)) {
            foreach ($currencies_module as $currency_module) {
                if ($currency_order->id == $currency_module['id_currency']) {
                    return true;
                }
            }
        }
        return false;
    }

	private function getPaymentMethods( $cart = null ) {
		/**
		 * @var $cart Cart
		 */
		$availablePaymentMethods = $this->getPaymentMethodsForCart( $cart );

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
            if (isset($paymentMethod->description)) {
                $objPaymentMethod->setAdditionalInformation('<p>'.$paymentMethod->description.'</p>');
            }

            if ($paymentMethod->id == 10) {
                $objPaymentMethod->setForm($this->getBanksForm($paymentMethod->id));
            }
            $paymentmethods[] = $objPaymentMethod;
        }
        return $paymentmethods;
    }

	private function getPaymentMethodsForCart( $cart = null ) {
		/**
		 * @var $cart Cart
		 */

		// Return listed paymentmethods if allready checked
		if ( isset( $this->paymentMethods ) && count( $this->paymentMethods ) > 0 ) {
			return $this->paymentMethods;
		}

		$paymentMethods = json_decode( Configuration::get( 'PAYNL_PAYMENTMETHODS' ) );
		if ( $cart === null ) {
			$this->paymentMethods = $paymentMethods;

			return $paymentMethods;
		}

		$cartTotal       = $cart->getOrderTotal( true, Cart::BOTH );
		$this->cartTotal = $cartTotal;
		$result          = array();
		foreach ( $paymentMethods as $paymentMethod ) {
			if ( isset( $paymentMethod->enabled ) && $paymentMethod->enabled == true ) {

				$strFee         = "";
				$iTempCartTotal = $cartTotal;

				// Show payment fee
				$paymentMethod->fee = self::getPaymentFee( $paymentMethod, $cartTotal );
				if ( $paymentMethod->fee > 0 ) {
					$strFee         = " (+ €" . self::convertToEuro( $paymentMethod->fee ) . ")";
					$iTempCartTotal += (float) number_format( ( $paymentMethod->fee ), 2 );
				}

				$paymentMethod->name .= $strFee;

				// check min and max amount
				if ( ! empty( $paymentMethod->min_amount ) && $iTempCartTotal < $paymentMethod->min_amount ) {
					continue;
				}
				if ( ! empty( $paymentMethod->max_amount ) && $iTempCartTotal > $paymentMethod->max_amount ) {
					continue;
				}

                // check country
                if($paymentMethod->limit_countries){
                    $address = new Address($cart->id_address_delivery);
                    $address->id_country;
                    $allowed_countries = $paymentMethod->allowed_countries;
                    if(!in_array($address->id_country, $allowed_countries)){
                        continue;
                    }
                }


                $result[] = $paymentMethod;
            }
        }
        $this->paymentMethods = $result;
        return $result;
    }


	private function convertToEuro( $cents ) {
		return number_format( (float) $cents, 2, ',', '.' );
	}

	private function getBanksForm( $payment_option_id ) {
		$this->sdkLogin();
		$banks = \Paynl\Paymentmethods::getBanks( $payment_option_id );

        $this->context->smarty->assign([
            'action'            => $this->context->link->getModuleLink($this->name, 'startPayment', array(), true),
            'banks'             => $banks,
            'payment_option_id' => $payment_option_id,
        ]);

        return $this->context->smarty->fetch('module:paynlpaymentmethods/views/templates/front/payment_form_ideal.tpl');
    }

    private function sdkLogin()
    {
        $apitoken = Tools::getValue('PAYNL_API_TOKEN', Configuration::get('PAYNL_API_TOKEN'));
        $serviceId = Tools::getValue('PAYNL_SERVICE_ID', Configuration::get('PAYNL_SERVICE_ID'));
        \Paynl\Config::setApiToken($apitoken);
        \Paynl\Config::setServiceId($serviceId);
    }

    public function getTransaction($transactionId){
	    $this->sdkLogin();

	    $transaction = \Paynl\Transaction::get($transactionId);

	    return $transaction;
    }

	private function getPaymentMethod() {
		foreach ( $this->getPaymentMethodsForCart( $this->context->cart ) as $objPaymentOption ) {
			if ( $objPaymentOption->id == (int) $this->payment_option_id ) {
				return $objPaymentOption;
			}
		}

		return null;
	}

	/**
	 * @param $transactionId
	 * @param null $message
	 *
	 * @return \Paynl\Result\Transaction\Transaction
	 * @throws Exception
	 */
	public function processPayment( $transactionId, &$message = null ) {
		$transaction = $this->getTransaction( $transactionId );

		$order_state = $this->statusPending;
		if ($transaction->isPaid()) {
			$order_state = $this->statusPaid;
		} elseif ($transaction->isCanceled()) {
			$order_state = $this->statusCanceled;
		}
		if ($transaction->isRefunded(false)) {
			$order_state = $this->statusRefund;
		}

		/**
		 * @var $orderState OrderStateCore
		 */
		$orderState     = new OrderState( $order_state );
		$orderStateName = $orderState->name;
		if ( is_array( $orderStateName ) ) {
			$orderStateName = array_pop( $orderStateName );
		}

		$cart = new Cart( (int) $transaction->getExtra1() );

		/**
		 * @var $cart CartCore
		 */
		if ( version_compare( _PS_VERSION_, '1.7.1.0', '>=' ) ) {
			$orderId = Order::getIdByCartId( $transaction->getExtra1() );
		} else {
			//Deprecated since prestashop 1.7.1.0
			$orderId = Order::getIdByCartId( $transaction->getExtra1() );
		}

		if ( $orderId ) {
			$order = new Order( $orderId );

			/**
			 * @var $order OrderCore
			 */
			if ( $order->hasBeenPaid() && ! $transaction->isRefunded( false ) ) {
				$message = 'Order is already paid | OrderRefercene: ' . $order->reference;

				return $transaction;
			}

			$orderPayment    = null;
			$arrOrderPayment = OrderPayment::getByOrderReference( $order->reference );
			foreach ( $arrOrderPayment as $objOrderPayment ) {
				if ( $objOrderPayment->transaction_id == $transactionId ) {
					$orderPayment = $objOrderPayment;
				}
			}

			/**
			 * @var $orderPayment OrderPaymentCore
			 */
			if ( empty( $orderPayment ) ) {
				$orderPayment                  = new OrderPayment();
				$orderPayment->order_reference = $order->reference;
			}

			$orderPayment->payment_method = $transaction->getData()['paymentDetails']['paymentProfileName'];
			$orderPayment->amount         = $transaction->getPaidCurrencyAmount();
			$orderPayment->transaction_id = $transactionId;
			$orderPayment->id_currency    = $order->id_currency;

			$orderPayment->save();


			$history = new OrderHistory();

			$history->id_order = $order->id;

			$history->changeIdOrderState( $order_state, $order->id, true );
			$history->addWs();

			$message = "Updated order (" . $order->reference . ") to: " . $orderStateName;

		} else {
			if ( $transaction->isPaid() ) {

				$this->payment_option_id = (int) Db::getInstance()->getValue( '
		            SELECT `payment_option_id`
		            FROM `' . _DB_PREFIX_ . 'paynl_pfee_cart`
		            WHERE id_cart = ' . (int) $transaction->getExtra1());
				try {
					$this->validateOrder( (int) $transaction->getExtra1(), $order_state, $transaction->getPaidCurrencyAmount(), $transaction->getData()['paymentDetails']['paymentProfileName'], null, array( 'transaction_id' => $transactionId ), null, false, $cart->secure_key );

					/** @var OrderCore $orderId */
					$orderId = Order::getIdByCartId( $transaction->getExtra1() );
					$order   = new Order( $orderId );

					$message = "Validated order (" . $order->reference . ") with status: " . $orderStateName;
				} catch ( Exception $ex ) {
					$message = "Could not find order";
					Throw new Exception( $message );
				}

			}
		}

		return $transaction;
	}

	/**
	 * @param null $objPaymentMethod
	 * @param null $cartTotal
	 * @param bool $processFee
	 *
	 * @return string
	 */
	public function getPaymentFee( $objPaymentMethod = null, $cartTotal = null, $processFee = false ) {
		if ( is_null( $objPaymentMethod )) {
			$objPaymentMethod = $this->getPaymentMethod();
		}
		if ( is_null( $cartTotal ) ) {
			$cartTotal = $this->cartTotal;
		}

		$iReturn = 0;
		if ( isset( $objPaymentMethod->fee_value ) ) {
			if ( isset( $objPaymentMethod->fee_percentage ) && $objPaymentMethod->fee_percentage == true ) {
				$iReturn = ( (float) ( $cartTotal * ( $objPaymentMethod->fee_value / 100 ) * 100 ) );

			} else {
				$iReturn = $objPaymentMethod->fee_value * 100;
			}
		}

		$iFee = number_format( $iReturn / 100, 6 );

		if ( $processFee ) {
			$this->processFee( $iFee, null, $objPaymentMethod );
		}

		return $iFee;
	}

	/**
	 * @param int $fee
	 * @param int $id_cart
	 * @param null $objPaymentMethod
	 */
	private function processFee( $fee = 0, $id_cart = 0, $objPaymentMethod = null ) {
		if ( ! $id_cart ) {
			if ( isset( $this->context->cart ) ) {
				$id_cart = $this->context->cart->id;
			} else {
				return;
			}
		}

		if ( is_null( $objPaymentMethod ) || empty( $objPaymentMethod->fee_value ) ) {
			return;
		}

		if ( $fee > 0 ) {
			$total                   = $this->cartTotal;
			$type                    = $objPaymentMethod->fee_percentage ? 1 : 0;
			$this->payment_option_id = (int) $objPaymentMethod->id;

			Db::getInstance()->execute( '
                INSERT INTO `' . _DB_PREFIX_ . 'paynl_pfee_cart`
                (
                    `id_cart`,
                    `total`,
                    `type`,
                    `payment_option_id`,
                    `fee`,
                    `date_add`,
                    `date_updated`
                ) 
                VALUES 
                (
                    ' . (int) $id_cart . ',
                    ' . (float) $total . ',
                    ' . (int) $type . ',
                    ' . (int) $this->payment_option_id . ',
                    ' . (float) $fee . ',
                    "' . date( 'Y-m-d H:i:s' ) . '",
                    "' . date( 'Y-m-d H:i:s' ) . '" 
                )
                ON DUPLICATE KEY UPDATE 
                    `total` = ' . (float) $total . ',
                    `type` = ' . (int) $type . ',
                    `payment_option_id` = ' . (int) $this->payment_option_id . ',
                    `fee` = ' . (float) $fee . ',
                    `date_add` = "' . date( 'Y-m-d H:i:s' ) . '",
                    `date_updated` = "' . date( 'Y-m-d H:i:s' ) . '" 
            ' );
		}
	}

	/**
	 * @param $cart
	 *
	 * @return ProductCore
	 */
	private function getPaymentFeeProduct( $cart ) {
		$fee_product_id = Configuration::get( 'PAYNL_FEE_PRODUCT_ID' );
		foreach ( $cart->getProducts() as $product ) {
			if ( $product['id_product'] == $fee_product_id ) {
				return $product;
			}
		}
	}

	/**
	 * @param Cart $cart
	 * @param $iFee_wt
	 */
	private function addPaymentFee( Cart $cart, $iFee_wt ) {
		if ( $iFee_wt <= 0 ) {
			return;
		}

		// Get the paymentfee product
		$feeProduct = $this->getPaymentFeeProduct( $cart );
		if ( is_null( $feeProduct ) ) { // if not exists; add it and get it
			$cart->updateQty( self::DEFAULT_FEE_STOCK, Configuration::get( 'PAYNL_FEE_PRODUCT_ID' ) );
			$feeProduct = $this->getPaymentFeeProduct( $cart );
		}

		$vatRate = $feeProduct['rate'];

		$iFee_wt = (float) number_format( $iFee_wt, 2 );
		$iFee    = (float) number_format( (float) $iFee_wt / ( 1 + ( $vatRate / 100 ) ), 2 );

		$specific_price_rule                 = new SpecificPriceRule();
		$specific_price_rule->name           = 'Payment fee';
		$specific_price_rule->id_shop        = (int) $this->context->shop->id;
		$specific_price_rule->id_currency    = $cart->id_currency;
		$specific_price_rule->id_country     = 0;
		$specific_price_rule->id_group       = 0;
		$specific_price_rule->from_quantity  = 1;
		$specific_price_rule->reduction      = 0;
		$specific_price_rule->reduction_tax  = 1;
		$specific_price_rule->reduction_type = 'amount';
		$specific_price_rule->from           = date( "Y-m-d H:i:s" );
		$specific_price_rule->to             = date( "Y-m-d H:i:s", time() + 1 );
		$specific_price_rule->price          = (float) $iFee;
		$specific_price_rule->add();
	}

	/**
	 * @param bool $bRefresh
	 *
	 * @return int
	 */
	public function getTaxRate( $bRefresh = false ) {
		if ( $this->taxRate > 0 && ! $bRefresh ) {
			return $this->taxRate;
		}

		if(!isset($this->context)
		|| !isset($this->context->cart))
			return 0;

		$iRate = 0;
		foreach ( $this->context->cart->getProducts() as $product ) {
			if ( $product['rate'] > $iRate ) {
				$iRate = $product['rate'];
			}
		}

		$this->taxRate = $iRate;

		return $iRate;
	}

	/**
	 * @param Cart $cart
	 * @param $payment_option_id
	 * @param array $extra_data
	 *
	 * @return string
	 */
	public function startPayment(Cart $cart, $payment_option_id, $extra_data = array() ) {
		$this->payment_option_id = $payment_option_id;
		$this->sdkLogin();

		$currency = new Currency( $cart->id_currency );
		/** @var CurrencyCore $currency */

		$iPaymentFee = $this->getPaymentFee( null, null, true );
		$this->addPaymentFee( $cart, $iPaymentFee );

		$products = $this->_getProductData( $cart );

		$startData = array(
			'amount'        => $cart->getOrderTotal( true, Cart::BOTH ),
			'currency'      => $currency->iso_code,
			'returnUrl'     => $this->context->link->getModuleLink( $this->name, 'finish', array(), true ),
			'exchangeUrl'   => $this->context->link->getModuleLink( $this->name, 'exchange', array(), true ),
			'paymentMethod' => $payment_option_id,
			'description'   => $cart->id,
			'testmode'      => Configuration::get( 'PAYNL_TEST_MODE' ),
			'extra1'        => $cart->id,
			'language'      => Language::getIsoById( $cart->id_lang ),
			'products'      => $products
		);

		$addressData = $this->_getAddressData( $cart );
		$startData   = array_merge( $startData, $addressData );

		if ( isset( $extra_data['bank'] ) ) {
			$startData['bank'] = $extra_data['bank'];
		}

        // Taal betaalscherm bepalen
        $language                         = $this->getLanguageForOrder();
        $startData['language'] = $language;

        $result = \Paynl\Transaction::start($startData);

		if ( $this->shouldValidateOnStart( $payment_option_id ) ) {
			$this->validateOrder( $cart->id, $this->statusPending, 0, $this->getPaymentMethodName( $payment_option_id ), null, array(), null, false, $cart->secure_key );
		}

		return $result->getRedirectUrl();
	}

	/**
	 * @param Cart $cart
	 *
	 * @return array
	 */
	private function _getProductData( Cart $cart ) {
		$arrResult = array();
		foreach ( $cart->getProducts() as $product ) {


			$arrResult[] = array(
				'id'            => $product['id_product'],
				'name'          => $product['name'],
				'price'         => $product['price_wt'],
				'vatPercentage' => $product['rate'],
				'qty'           => $product['cart_quantity']
			);
		}
		$shippingCost_wt = $cart->getTotalShippingCost();
		$shippingCost    = $cart->getTotalShippingCost( null, false );
		$arrResult[]     = array(
			'id'    => 'shipping',
			'name'  => $this->l( 'Shipping costs' ),
			'price' => $shippingCost_wt,
			'tax'   => $shippingCost_wt - $shippingCost,
			'qty'   => 1,
		);

		return $arrResult;
	}

	/**
	 * @param Cart $cart
	 *
	 * @return array
	 */
	private function _getAddressData( Cart $cart ) {
		/** @var CartCore $cart */
		$shippingAddressId  = $cart->id_address_delivery;
		$invoiceAddressId   = $cart->id_address_invoice;
		$customerId         = $cart->id_customer;
		$objShippingAddress = new Address( $shippingAddressId );
		$objInvoiceAddress  = new Address( $invoiceAddressId );
		$customer           = new Customer( $customerId );
		/** @var AddressCore $objShippingAddress */
		/** @var AddressCore $objInvoiceAddress */
		/** @var CustomerCore $customer */
		$enduser                 = array();
		$enduser['initials']     = substr( $objShippingAddress->firstname, 0, 1 );
		$enduser['lastName']     = $objShippingAddress->lastname;
		$enduser['birthDate']    = $customer->birthday;
		$enduser['phoneNumber']  = $objShippingAddress->phone ? $objShippingAddress->phone : $objShippingAddress->phone_mobile;
		$enduser['emailAddress'] = $customer->email;

		list( $shipStreet, $shipHousenr ) = Paynl\Helper::splitAddress( trim( $objShippingAddress->address1 . ' ' . $objShippingAddress->address2 ) );
		list( $invoiceStreet, $invoiceHousenr ) = Paynl\Helper::splitAddress( trim( $objInvoiceAddress->address1 . ' ' . $objInvoiceAddress->address2 ) );

		/** @var CountryCore $shipCountry */
		$shipCountry = new Country( $objShippingAddress->id_country );
		$address     = array(
			'streetName'  => @$shipStreet,
			'houseNumber' => @$shipHousenr,
			'zipCode'     => $objShippingAddress->postcode,
			'city'        => $objShippingAddress->city,
			'country'     => $shipCountry->iso_code
		);

		/** @var CountryCore $invoiceCountry */
		$invoiceCountry = new Country( $objInvoiceAddress->id_country );
		$invoiceAddress = array(
			'initials'    => substr( $objInvoiceAddress->firstname, 0, 1 ),
			'lastName'    => $objInvoiceAddress->lastname,
			'streetName'  => @$invoiceStreet,
			'houseNumber' => @$invoiceHousenr,
			'zipcode'     => $objInvoiceAddress->postcode,
			'city'        => $objInvoiceAddress->city,
			'country'     => $invoiceCountry->iso_code
		);

		return array(
			'enduser'        => $enduser,
			'address'        => $address,
			'invoiceAddress' => $invoiceAddress
		);
	}

	/**
	 * @param $payment_option_id
	 *
	 * @return bool
	 */
	public function shouldValidateOnStart( $payment_option_id ) {
		if ( $payment_option_id == 136 ) {
			return true;
		}

		return false;
	}

	/**
	 * @param $payment_option_id
	 *
	 * @return string
	 */
	private function getPaymentMethodName( $payment_option_id ) {
		$this->sdkLogin();

		$payment_methods = \Paynl\Paymentmethods::getList();
		if ( isset( $payment_methods[ $payment_option_id ] ) ) {
			return $payment_methods[ $payment_option_id ]['name'];
		} else {
			return "Unknown";
		}
	}


	/**
	 * @return string|void
	 */
	public function getContent() {

		if ( Tools::isSubmit( 'btnSubmit' ) ) {
			$this->_postValidation();
			if ( ! count( $this->_postErrors ) ) {
				$this->_postProcess();
			} else {
				foreach ( $this->_postErrors as $err ) {
					$this->_html .= $this->displayError( $err );
				}
			}
		} else {
			$this->_html .= '<br />';
		}
		$loggedin = false;
		if ( ! class_exists( '\Paynl\Paymentmethods' ) ) {
			$this->adminDisplayWarning( $this->l( 'Cannot find Pay.nl SDK, did you install the source code instead of the package?' ) );

			return;
		}
		try {
			$this->sdkLogin();
			//call api to check if the credentials are correct
			\Paynl\Paymentmethods::getList();
			$loggedin = true;
		} catch ( \Exception  $e ) {

		}

		$this->_html .= $this->renderAccountSettingsForm();
		if ( $loggedin ) {
			$this->_html .= $this->renderPaymentMethodsForm();
		}

		return $this->_html;
	}

	/**
	 *
	 */
	protected function _postValidation() {
		if ( Tools::isSubmit( 'btnSubmit' ) ) {
			if ( ! Tools::getValue( 'PAYNL_API_TOKEN' ) ) {
				$this->_postErrors[] = $this->l( 'APItoken is required' );
			} elseif ( ! Tools::getValue( 'PAYNL_SERVICE_ID' ) ) {
				$this->_postErrors[] = $this->l( 'ServiceId is required' );
			}

			if ( empty( $this->_postErrors ) ) {
				// check if apitoken and serviceId are valid
				$this->sdkLogin();

				try {
					Paynl\Paymentmethods::getList();
				} catch ( \Paynl\Error\Error $e ) {
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
            Configuration::updateValue('PAYNL_LANGUAGE', Tools::getValue('PAYNL_LANGUAGE'));
        }
        $this->_html .= $this->displayConfirmation($this->l('Settings updated'));
    }

    public function getLanguages()
    {
        return array(
            array(
                'language_id' => 'nl',
                'label'       => $this->l('Dutch')
            ),
            array(
                'language_id' => 'en',
                'label'       => $this->l('English')
            ),
            array(
                'language_id' => 'es',
                'label'       => $this->l('Spanish')
            ),
            array(
                'language_id' => 'it',
                'label'       => $this->l('Italian')
            ),
            array(
                'language_id' => 'fr',
                'label'       => $this->l('French')
            ),
            array(
                'language_id' => 'de',
                'label'       => $this->l('German')
            ),
            array(
                'language_id' => 'auto',
                'label'       => $this->l('Automatic')
            ),
        );
    }

    public function getCountries()
    {
        return Country::getCountries($this->context->language->id);
    }

    public function renderAccountSettingsForm()
    {
        $fields_form = array(
            'form' => array(
                'legend' => array(
                    'title' => $this->l('Pay.nl Account Settings'),
                    'icon'  => 'icon-envelope'
                ),
                'input'  => array(
                    array(
                        'type'     => 'text',
                        'label'    => $this->l('APIToken'),
                        'name'     => 'PAYNL_API_TOKEN',
                        'desc'     => $this->l('You can find your API token at the bottom of https://admin.pay.nl/my_merchant'),
                        'required' => true
                    ),
                    array(
                        'type'     => 'text',
                        'label'    => $this->l('ServiceId'),
                        'name'     => 'PAYNL_SERVICE_ID',
                        'desc'     => $this->l('The SL-code of your service on https://admin.pay.nl/programs/programs'),
                        'required' => true
                    ),
                    array(
                        'type'   => 'switch',
                        'label'  => $this->l('Test mode'),
                        'name'   => 'PAYNL_TEST_MODE',
                        'desc'   => $this->l('Start transactions in sandbox mode for testing.'),
                        'values' => array(
                            array(
                                'id'    => 'active_on',
                                'value' => 1,
                                'label' => $this->l('Enabled')
                            ),
                            array(
                                'id'    => 'active_off',
                                'value' => 0,
                                'label' => $this->l('Disabled')
                            )
                        ),
                    ),
                    array(
                        'type'    => 'select',
                        'label'   => $this->l('Payment screen language'),
                        'name'    => 'PAYNL_LANGUAGE',
                        'desc'    => $this->l("Select the language to show the payment screen in, automatic uses the browser preference"),
                        'options' => array(
                            'query' => $this->getLanguages(),
                            'id'    => 'language_id',
                            'name'  => 'label'
                        )
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

		$helper                           = new HelperForm();
		$helper->show_toolbar             = false;
		$helper->table                    = $this->table;
		$lang                             = new Language( (int) Configuration::get( 'PS_LANG_DEFAULT' ) );
		$helper->default_form_language    = $lang->id;
		$helper->allow_employee_form_lang = Configuration::get( 'PS_BO_ALLOW_EMPLOYEE_FORM_LANG' ) ? Configuration::get( 'PS_BO_ALLOW_EMPLOYEE_FORM_LANG' ) : 0;
		$this->fields_form                = array();
		$helper->id                       = (int) Tools::getValue( 'id_carrier' );
		$helper->identifier               = $this->identifier;
		$helper->submit_action            = 'btnSubmit';
		$helper->currentIndex             = $this->context->link->getAdminLink( 'AdminModules', false ) . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
		$helper->token                    = Tools::getAdminTokenLite( 'AdminModules' );
		$helper->tpl_vars                 = array(
			'fields_value' => $this->getConfigFieldsValues(),
			'languages'    => $this->context->controller->getLanguages(),
			'id_language'  => $this->context->language->id
		);

		return $helper->generateForm( array( $fields_form ) );
	}

	/**
	 * @return array
	 */
	public function getConfigFieldsValues() {
		$paymentMethods = Tools::getValue( 'PAYNL_PAYMENTMETHODS', '[]' );

		if ( $paymentMethods == '[]' ) {
			$paymentMethods = $this->getPaymentMethodsCombined();
			$paymentMethods = json_encode( $paymentMethods );
		}

        return array(
            'PAYNL_API_TOKEN'  => Tools::getValue('PAYNL_API_TOKEN', Configuration::get('PAYNL_API_TOKEN')),
            'PAYNL_SERVICE_ID' => Tools::getValue('PAYNL_SERVICE_ID', Configuration::get('PAYNL_SERVICE_ID')),
            'PAYNL_TEST_MODE'  => Tools::getValue('PAYNL_TEST_MODE', Configuration::get('PAYNL_TEST_MODE')),
            'PAYNL_LANGUAGE'  => Tools::getValue('PAYNL_LANGUAGE', Configuration::get('PAYNL_LANGUAGE')),

            'PAYNL_PAYMENTMETHODS' => $paymentMethods
        );
    }

	/**
	 * @return array
	 */
	private function getPaymentMethodsCombined() {
		$resultArray         = array();
		$savedPaymentMethods = json_decode( Configuration::get( 'PAYNL_PAYMENTMETHODS' ) );
		try {
			$this->sdkLogin();
			$paymentmethods = \Paynl\Paymentmethods::getList();
			$paymentmethods = (array) $paymentmethods;
			foreach ( $savedPaymentMethods as $paymentmethod ) {
				if ( isset( $paymentmethods[ $paymentmethod->id ] ) ) {
					$resultArray[] = $paymentmethod;
					unset( $paymentmethods[ $paymentmethod->id ] );
				}
			}
			foreach ( $paymentmethods as $paymentmethod ) {
				$resultArray[] = array(
					'id'      => $paymentmethod['id'],
					'name'    => $paymentmethod['name'],
					'enabled' => false,
				);
			}
		} catch ( \Exception  $e ) {

		}

		return $resultArray;
	}

	/**
	 * @return string
	 */
	public function renderPaymentMethodsForm() {

		$this->context->controller->addJs( $this->_path . 'views/js/jquery-ui/jquery-ui.js' );
		$this->context->controller->addJs( $this->_path . 'views/js/angular/angular.js' );

		$this->context->controller->addJs( $this->_path . 'views/js/angular-ui-sortable/sortable.js' );
		$this->context->controller->addJs( $this->_path . 'views/js/angular-ui-switch/angular-ui-switch.js' );

		$this->context->controller->addCss( $this->_path . 'views/js/angular-ui-switch/angular-ui-switch.css' );
		$this->context->controller->addCss( $this->_path . 'css/admin.css' );

        $this->smarty->assign(array(
            'available_countries' => $this->getCountries()
        ));

        return $this->display(__FILE__, 'admin_paymentmethods.tpl');
    }

    private function getLanguageForOrder()
    {
        $languageSetting = Tools::getValue('PAYNL_LANGUAGE', Configuration::get('PAYNL_LANGUAGE'));
        if ($languageSetting == 'auto') {
            return $this->getBrowserLanguage();
        } else {
            return $languageSetting;
        }
    }

    private function getBrowserLanguage()
    {
        if (isset($_SERVER["HTTP_ACCEPT_LANGUAGE"])) {
            return $this->parseDefaultLanguage($_SERVER["HTTP_ACCEPT_LANGUAGE"]);
        } else {
            return $this->parseDefaultLanguage(null);
        }
    }

    private function parseDefaultLanguage($http_accept, $deflang = "en")
    {
        if (isset($http_accept) && strlen($http_accept) > 1) {
            # Split possible languages into array
            $x = explode(",", $http_accept);
            foreach ($x as $val) {
                #check for q-value and create associative array. No q-value means 1 by rule
                if (preg_match("/(.*);q=([0-1]{0,1}.[0-9]{0,4})/i", $val,
                    $matches)) {
                    $lang[$matches[1]] = (float)$matches[2] . '';
                } else {
                    $lang[$val] = 1.0;
                }
            }

            $arrLanguages          = $this->getLanguages();
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
                        $qval    = (float)$value;
                        $deflang = $key;
                    }
                }
            }
        }

        return strtolower(substr($deflang, 0, 2));
    }
}
