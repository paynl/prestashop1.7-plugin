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
class PaynlPaymentMethodsStartPaymentModuleFrontController extends ModuleFrontController
{
    /**
     * @see FrontController::postProcess()
     */
    public function postProcess()
    {
        $cart = $this->context->cart;

        if ($cart->id_customer == 0 || $cart->id_address_delivery == 0 || $cart->id_address_invoice == 0 || !$this->module->active) {
            Tools::redirect('index.php?controller=order&step=1');
        }


        $authorized = false;
        $paymentOptionId = $_REQUEST['payment_option_id'];
        foreach (Module::getPaymentModules() as $module) {
            if ($module['name'] == 'paynlpaymentmethods') {
                $authorized = $this->module->isPaymentMethodAvailable($cart, $paymentOptionId);
                break;
            }
        }

        if (!$authorized) {
            die($this->module->l('This payment method is not available.', 'validation'));
        }

        $extra_data = array();
        if(isset($_REQUEST['bank'])){
            $extra_data['bank'] = $_REQUEST['bank'];
        }
        try{
            $redirectUrl = $this->module->startPayment($cart, $paymentOptionId, $extra_data);
            Tools::redirect($redirectUrl);
        } catch (Exception $e){
          $this->module->payLog('postProcess', 'Error startPayment: ' . $e->getMessage(), $cart->id);
          die('Error: ' . $e->getMessage());
        }


    }
}
