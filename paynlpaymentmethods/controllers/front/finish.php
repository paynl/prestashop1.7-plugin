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
 * @since 1.5.0
 */

use PaynlPaymentMethods\PrestaShop\Transaction;
use PaynlPaymentMethods\PrestaShop\PaymentMethod;
use PaynlPaymentMethods\PrestaShop\Instore;

class PaynlPaymentMethodsFinishModuleFrontController extends ModuleFrontController
{   
    private $order = null;
    private $payOrderId = null;
    private $orderStatusId = null;
    private $paymentSessionId = null;
  /**
   * @see FrontController::postProcess()
   */
    public function postProcess()
    {
      $transactionId = Tools::getValue('orderId');
      $iAttempt = Tools::getValue('attempt');

      $bValidationDelay = Configuration::get('PAYNL_VALIDATION_DELAY') == 1;

      $this->payOrderId = $transactionId;
      $this->orderStatusId = Tools::getValue('orderStatusId');
      $this->paymentSessionId = Tools::getValue('paymentSessionId');

      if (Tools::getValue('terminalerror') == 1) {
        Instore::terminalError(Tools::getValue('error'), $this);
      }

      /**
       * @var $module PaynlPaymentMethods
       */
      $module = $this->module;

        try {
            $transaction = $module->getTransaction($transactionId);
            $transactionData = $transaction->getData();
            $ppid = !empty($transactionData['paymentDetails']['paymentOptionId']) ? $transactionData['paymentDetails']['paymentOptionId'] : null;
            $stateName = !empty($transactionData['paymentDetails']['stateName']) ? $transactionData['paymentDetails']['stateName'] : 'unknown';
        } catch (Exception $e) {
            $module->payLog('finishPostProcess', 'Could not retrieve transaction', null, $transactionId);
            return;
        }

        $module->payLog('finishPostProcess', 'Returning to webshop. Method: ' . $transaction->getPaymentMethodName() . '. Status: ' . $stateName , $transaction->getOrderNumber(), $transactionId);

      if ($transaction->isPaid() || $transaction->isPending() || $transaction->isBeingVerified() || $transaction->isAuthorized()) {

          $cart = $this->context->cart;
          $customer = new Customer($cart->id_customer);
          $slow = '';

          $dbTransaction = Transaction::get($transactionId);
          if (!empty($dbTransaction['hash']) && !empty($dbTransaction['payment_option_id']) && $dbTransaction['payment_option_id'] == PaymentMethod::METHOD_INSTORE) {
            Instore::handlePin($dbTransaction['hash'], $transactionId, $this);
          }

          if (!$transaction->isPaid()) {
              $slow = '&slowvalidation=1';
              $iTotalAttempts = in_array($ppid, array(PaymentMethod::METHOD_OVERBOEKING, PaymentMethod::METHOD_SOFORT)) ? 1 : 20;

              if ($bValidationDelay == 1 && $iAttempt < $iTotalAttempts) {
                  return;
              }
          }

          unset($this->context->cart);
          unset($this->context->cookie->id_cart);

          $cartId = $transaction->getOrderNumber();
          $orderId = Order::getIdByCartId($cartId);

          $this->order = $orderId;

          Tools::redirect('index.php?controller=order-confirmation' . $slow . '&id_cart=' . $cartId . '&id_module=' . $this->module->id . '&id_order=' . $orderId . '&key=' . $customer->secure_key);

      } else {
          # Delete old payment fee
          $this->context->cart->deleteProduct(Configuration::get('PAYNL_FEE_PRODUCT_ID'), 0);

          if ($this->orderStatusId == '-63') {
              $this->createNewCart($this->context->cart);
              $this->errors[] = $this->module->l('The payment has been denied', 'finish');
              $this->redirectWithNotifications('index.php?controller=order&step=1');
          } elseif ($transaction->isCanceled()) {
              $this->errors[] = $this->module->l('The payment has been canceled', 'finish');
              $this->redirectWithNotifications('index.php?controller=order&step=1');

          } else {
              # To checkout
              Tools::redirect('index.php?controller=order&step=1');
          }
      }

  }

  public function initContent()
  {
    $iAttempt = Tools::getValue('attempt');

    if (empty($iAttempt)) {
      $iAttempt = 0;
    }

    $iAttempt += 1;
    $url =  'module/paynlpaymentmethods/finish?orderId=' . $this->payOrderId .
      '&orderStatusId=' . $this->orderStatusId .
      '&paymentSessionId=' . $this->paymentSessionId . '&utm_nooverride=1&attempt=' . $iAttempt;

    $this->context->smarty->assign(array('order' => $this->payOrderId, 'extendUrl' => $url));
    $this->setTemplate('module:paynlpaymentmethods/views/templates/front/waiting.tpl');
  }

  public function createNewCart($oldCart)
  {
    $this->context->cart = new Cart();
    $this->context->cart->id_lang = $this->context->language->id;
    $this->context->cart->id_currency = $this->context->currency->id;
    $this->context->cart->add();
    foreach ($oldCart->getProducts() as $product) {
      $customization_id = null;
      if (!empty($product['id_customization'])) {
        $customization_id = $this->addCustomization($oldCart->id, $product['id_product'], $product['id_customization']);
      }
      $this->context->cart->updateQty((int) $product['quantity'], (int) $product['id_product'], (int) $product['id_product_attribute'], $customization_id);
    }
    $this->context->cart->id_customer = $oldCart->id_customer;
    $this->context->cart->id_guest = $oldCart->id_guest;
    $this->context->cart->secure_key = $oldCart->secure_key;
    $this->context->cart->id_shop_group = $oldCart->id_shop_group;
    $this->context->cart->id_address_delivery = $oldCart->id_address_delivery;
    $this->context->cart->id_address_invoice = $oldCart->id_address_invoice;
    $this->context->cart->delivery_option = $oldCart->delivery_option;
    $this->context->cart->id_carrier = $oldCart->id_carrier;
    $this->context->cart->gift = $oldCart->gift;
    $this->context->cart->gift_message = $oldCart->gift_message;
    $this->context->cart->id_address_invoice = $oldCart->id_address_invoice;
    $this->context->cart->mobile_theme = $oldCart->mobile_theme;
    $this->context->cart->checkedTos = $oldCart->checkedTos;
    $this->context->cart->pictures = $oldCart->pictures;
    $this->context->cart->textFields = $oldCart->textFields;
    $this->context->cart->allow_seperated_package = $oldCart->allow_seperated_package;
    $this->context->cart->recyclable = $oldCart->recyclable;

    $this->context->cart->save();
    if ($this->context->cart->id) {
      $this->context->cookie->id_cart = $this->context->cart->id;
      $this->context->cookie->write();

      $sessionData = Db::getInstance()->getValue('SELECT checkout_session_data FROM ' . _DB_PREFIX_ . 'cart WHERE id_cart = ' . (int) $oldCart->id);
      Db::getInstance()->execute('UPDATE ' . _DB_PREFIX_ . 'cart SET checkout_session_data = "' . pSQL($sessionData) . '" WHERE id_cart = ' . (int) $this->context->cart->id);
    }
  }

  function addCustomization($oldCartId, $productId, $customizationId)
  {
    $exising_customization = Db::getInstance()->executeS(
      'SELECT cu.`id_customization`, cd.`index`, cd.`value`, cd.`type` FROM `' . _DB_PREFIX_ . 'customization` cu
      LEFT JOIN `' . _DB_PREFIX_ . 'customized_data` cd
      ON cu.`id_customization` = cd.`id_customization`
      WHERE cu.id_cart = ' . (int) $oldCartId . '
      AND cu.id_product = ' . (int) $productId . '
      AND cu.id_customization = ' . (int) $customizationId
    );
    $this->context->cart->addTextFieldToProduct((int)($productId), $exising_customization[0]['index'], Product::CUSTOMIZE_TEXTFIELD, $exising_customization[0]['value'], true);
    $customization = Db::getInstance()->executeS('SELECT id_customization FROM ' . _DB_PREFIX_ . 'customized_data ORDER BY id_customization DESC LIMIT 0,1');
    return (!empty($customization[0]['id_customization'])) ? $customization[0]['id_customization'] : null;
  }
}
