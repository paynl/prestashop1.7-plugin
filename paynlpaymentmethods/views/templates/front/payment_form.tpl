<form action="{$action}" method="POST" id="payment-form" class="paynl">
    <input type="hidden" name="payment_option_id" value="{$payment_option_id}"/>
    {if !empty($banks)}
        <div class="form-group row PaynlBanks {$logoClass}">
            <select class="form-control form-control-select" id="bank" name="bank">
                <option value="">{$payment_option_text}</option>
                {foreach from=$banks item=bank}
                    <option value="{$bank['id']}">{$bank['name']}</option>
                {/foreach}
            </select>
        </div>
    {/if}
    {if !empty($description)}
        <div class="paynl_payment_description">
            {{$description}}
        </div>
    {/if}
</form>