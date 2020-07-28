{*
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
*}
<form action="{$action}" method="POST" id="payment-form">
    <input type="hidden" name="payment_option_id" value="{$payment_option_id}"/>
    <div class="form-group row PaynlBanks {$logoClass}">
        <span for="bank" class="form-control-label">{l s='Bank' mod='paynlpaymentmethods'}</span>
        <div>
            <select class="form-control form-control-select" id="bank" name="bank">
                <option value="">{l s='Please select your bank' mod='paynlpaymentmethods'}</option>
                {foreach from=$banks item=bank}
                    <option value="{$bank['id']}">{$bank['name']}</option>
                {/foreach}
            </select>
        </div>
    </div>
    {if !empty($description)}
        <div class="paynl_payment_description">
            {{$description}}
        </div>
    {/if}
</form>