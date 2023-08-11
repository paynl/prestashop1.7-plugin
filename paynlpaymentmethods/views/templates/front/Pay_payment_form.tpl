<form action="{$action}" method="POST" id="payment-form" class="paynl">
    <input type="hidden" name="payment_option_id" value="{$payment_option_id}"/>
    {if !empty($banks)}
        <div class="form-group row PaynlBanks {$logoClass} {$type}">   
            {if $type == 'dropdown'}     
                <fieldset>
                    <legend>{$payment_dropdown_text}</legend>
                    <select class="form-control form-control-select" id="bank" name="bank">
                        {foreach from=$banks item=bank}
                            <option value="{$bank['id']}">{$bank['name']}</option>
                        {/foreach}
                    </select>
                </fieldset>       
             {elseif $type == 'radio'}     
                <ul class="pay_radio_select">
                    {foreach from=$banks item=bank}
                        <li>
                            <label>
                                <input type="radio" name="bank" value="{$bank['id']}">
                                {if $logoClass != 'noLogo'}  
                                    <img src="/modules/paynlpaymentmethods/views/images/issuers/qr-{$bank['id']}.png" loading="lazy">
                                {/if}  
                                &nbsp;
                                <span>{$bank['name']}</span>
                            </label>
                    {/foreach}
                </ul>
             {/if}   
        </div>
    {/if}
    {if !empty($description)}
        <div class="paynl_payment_description">
            {{$description}}
        </div>
    {/if}
</form>