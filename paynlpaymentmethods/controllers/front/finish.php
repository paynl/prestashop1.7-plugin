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
class PaynlPaymentMethodsFinishModuleFrontController extends ModuleFrontController
{
    /**
     * @see FrontController::postProcess()
     */
    public function postProcess()
    {
        $transactionId = $_REQUEST['orderId'];

        $module = $this->module;
        /**
         * @var $module PaynlPaymentMethods
         */

        $transaction = $module->getTransaction($transactionId);

        if ($transaction->isPaid() || $transaction->isPending() || $transaction->isBeingVerified() || $transaction->isAuthorized()) {
            // naar success
            /**
             * @var $cart CartCore
             */
            $cart = $this->context->cart;

            /**
             * @var $customer CustomerCore
             */
            $customer = new Customer($cart->id_customer);

            $slow = '';
            if (!$transaction->isPaid()) {
                $slow = '&slowvalidation=1';
            }

            unset($this->context->cart);
            unset($this->context->cookie->id_cart);

            $cartId = $transaction->getExtra1();
            $orderId = Order::getIdByCartId($cartId);

            Tools::redirect('index.php?controller=order-confirmation&'.$slow.'id_cart=' . $cartId . '&id_module=' . $this->module->id . '&id_order=' . $orderId . '&key=' . $customer->secure_key);
        } else {
            // naar checkout
            Tools::redirect('index.php?controller=order&step=1');
        }

    }
}
