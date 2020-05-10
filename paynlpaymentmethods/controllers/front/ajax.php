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
class PaynlPaymentMethodsAjaxModuleFrontController extends ModuleFrontController
{

  public function initContent()
  {
    $prestaorderid = Tools::getValue('prestaorderid');
    $amount = Tools::getValue('amount');
    $module = $this->module;
    $module->payLog('PAY.: Refund - start. Trying to refund ' . $amount . ' on prestashop-orderid ' . $prestaorderid);

    try {
      $order = new Order($prestaorderid);
    } catch (Exception $e) {
      $module->payLog('PAY.: Refund - Failed. Prestasshop order not found.');
      $this->returnResponse(false, 0, 'Could not find order');
    }

    $paymenyArr = $order->getOrderPayments();
    $orderPayment = reset($paymenyArr);
    $transactionId = $orderPayment->transaction_id;

    $currencyId = $orderPayment->id_currency;
    $currency = new Currency($currencyId);
    $strCurrency = $currency->iso_code;

    /**
     * @var $module PaynlPaymentMethods
     */
    $refundResult = $module->doRefund($transactionId, $amount);
    if (!empty($refundResult)) {
      $arrResult = $refundResult->getData();
      $amountRefunded = !empty($arrResult['amountRefunded']) ? $arrResult['amountRefunded'] : '';

      $desc = !empty($arrResult['description']) ? $arrResult['description'] : 'empty';
      $module->payLog('PAY.: Refund - Success, result message:' . $desc);

      $this->returnResponse(true, $amountRefunded, 'succesfully_refunded ' . $strCurrency . ' ' . $amount);
    } else {
      $module->payLog('PAY.: Refund - Failed. Possible PAY. connection error or refund too fast or invalid amount.');
      $this->returnResponse(false, 0, 'could_not_process_refund');
    }

  }

  private function returnResponse($result, $amountRefunded = '', $message = '')
  {
    header('Content-Type: application/json;charset=UTF-8');

    $returnarray = array(
      'success' => $result,
      'amountrefunded' => $amountRefunded,
      'message' => $message
    );

    die(json_encode($returnarray));
  }

}
