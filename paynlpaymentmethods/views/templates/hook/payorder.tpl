<div class="PAY panel card">
    <div class="panel-heading card-header">
        <i class="icon-money"></i> {$lang.title}
    </div>
    <div class="card-body">
    <a href="https://admin.pay.nl" target="_blank">
        <img class="payLogo" src="https://static.pay.nl/generic/images/75x75/logo.png"/>
    </a>
    <input type="hidden" id="pay-currency" value="{$currency}">
    <input type="hidden" id="pay-transactionid" value="{$pay_orderid}">
    <input type="hidden" id="pay-prestaorderid" value="{$PrestaOrderId}">
    <input type="hidden" id="pay-ajaxurl" value="{$ajaxURL}">
    <input type="hidden" id="pay-lang-areyoursure" value="{$lang.are_you_sure}">
    <input type="hidden" id="pay-lang-areyoursurecapture" value="{$lang.are_you_sure_capture}">
    <input type="hidden" id="pay-lang-areyoursurecaptureremaining" value="{$lang.are_you_sure_capture_remaining}">
    <input type="hidden" id="pay-lang-invalidamount" value="{$lang.invalidamount}">
    <input type="hidden" id="pay-lang-refunding" value="{$lang.refunding}">
    <input type="hidden" id="pay-lang-capturing" value="{$lang.capturing}">
    <input type="hidden" id="pay-lang-succesfullyrefunded" value="{$lang.succesfully_refunded}">
    <input type="hidden" id="pay-lang-refundbutton" value="{$lang.refund_button}">
    <input type="hidden" id="pay-lang-capturebutton" value="{$lang.capture_button}">
    <input type="hidden" id="pay-lang-captureremainingbutton" value="{$lang.capture_remaining_button}">
    <input type="hidden" id="pay-lang-couldnotprocess" value="{$lang.could_not_process_refund}">
    <input type="hidden" id="pay-lang-couldnotprocesscapture" value="{$lang.could_not_process_capture}">
    <input type="hidden" id="pay-lang-succesfullycaptured" value="{$lang.succesfully_captured}">
    <input type="hidden" id="pay-lang-succesfullycapturedremaining" value="{$lang.succesfully_captured_remaining}">

    <div class="payFields">
        <div class="label">PAY. Order id</div>
        <div class="labelvalue">{$pay_orderid}</div>
        <div class="label">PAY. Status</div>
        <div class="labelvalue" id="pay-status">{$status}</div>
        <div class="label">{$lang.paymentmethod}</div>
        <div class="labelvalue">{$method}</div>
        <div class="label">{$lang.amount} (Cart)</div>
        <div class="labelvalue">{$currency} {$amountFormatted}</div>
        <div class="label">{$lang.amount} (PAY.)</div>
        <div class="labelvalue">EUR {$amountPayFormatted}</div>
    </div>
    <div>
        <hr>
        {if $showRefundButton eq true}
            <div class="payOption" id="refund-div" style="display: inline-block">
                <div class="label">{$lang.amount_to_refund} ({$currency}) :
                    <input type="text" placeholder="0,00" value="{$amountFormatted}" id="pay-refund-amount"
                           class="fixed-width-sm" style="display: inline;margin-right:10px"/>
                </div>
                <button type="button" id="pay-refund-button" class="btn btn-danger" style="display: inline">{$lang.refund_button}</button>
                <div class="tooltipPAY">
                    ? <span class="tooltipPAYtext"> {$lang.info_refund_text} </span>
                </div>
            </div>
        {/if}
        {if $showCaptureButton eq true}
            <div class="payOption" id="refund-div" style="display: inline-block">
                <div class="label">{$lang.amount_to_capture} ({$currency}) : {$amountFormatted}
                    <input type="hidden" placeholder="0,00" value="{$amountFormatted}" id="pay-capture-amount"
                           class="fixed-width-sm" style="display: inline;margin-right:10px"/>
                </div>
                <button type="button" id="pay-capture-button" class="btn btn-danger"
                        style="display: inline; margin-left: 10px">{$lang.capture_button}</button>
                <div class="tooltipPAY">
                    ? <span class="tooltipPAYtext"> {$lang.info_capture_text} </span>
                </div>
            </div>
        {/if}
        {if $showCaptureRemainingButton eq true}
            <div class="payOption" id="refund-div" style="display: inline-block">
                <button type="button" id="pay-capture-remaining-button" class="btn btn-danger"
                        style="display: inline">{$lang.capture_remaining_button}</button>
                <div class="tooltipPAY">
                    ? <span class="tooltipPAYtext"> {$lang.info_capture_remaining_text} </span>
                </div>
            </div>
        {/if}
     </div>
    </div>
</div>