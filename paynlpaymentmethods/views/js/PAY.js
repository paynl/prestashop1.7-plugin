jQuery(document).ready(function () {
    jQuery("#PAY-info-button").click(function () {
        jQuery("#dialog-info-modal").dialog({
            modal: true,
            closeOnEscape: false
        });
    });

    jQuery("#pay-refund-button").click(function () {
        var amount = jQuery('#pay-refund-amount').val();
        var errorMessage = jQuery('#pay-lang-invalidamount').val();

        if (!/^[0-9,]+$/.test(amount)) {
            alert(errorMessage);
            return;
        }

        if (amount.indexOf(',') === -1) {
            amount = amount / 100;
        } else {
            amount = parseFloat(amount.replace(',', '.').replace(' ', ''));
        }
        var transactionid = jQuery('#pay-transactionid').val();
        var PrestaOrderId = jQuery('#pay-prestaorderid').val();
        var ajaxurl = jQuery('#pay-ajaxurl').val();
        var presentationAmount = amount.toFixed(2);
        var currency = jQuery('#pay-currency').text();
        var lang_areyoursure = jQuery('#pay-lang-areyoursure').val();
        var lang_refunding = jQuery('#pay-lang-refunding').val();
        var lang_sucrefund = jQuery("#pay-lang-succesfullyrefunded").val();
        var lang_refundbutton = jQuery("#pay-lang-refundbutton").val();
        var lang_couldnotprocess = jQuery("#pay-lang-couldnotprocess").val();

        presentationAmount = presentationAmount.replace('.', ',');

        if (confirm(lang_areyoursure + ': ' + currency + ' ' + presentationAmount + ' ?')) {

            var data = {};
            jQuery.extend(data, {amount: amount});
            jQuery.extend(data, {orderid: transactionid});
            jQuery.extend(data, {prestaorderid: PrestaOrderId});


            var refundButton = jQuery(this);
            var payOption = jQuery(this).parent();

            jQuery(refundButton).text(lang_refunding);

            setTimeout(function () {
                jQuery.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: data,
                    dataType: 'json',
                    success: function (data) {
                        if (data.success) {
                            jQuery('#pay-status').text(' - ');
                            jQuery(payOption).text(lang_sucrefund + ' ' + currency + ' ' + presentationAmount);
                        } else {
                            jQuery(refundButton).text(lang_refundbutton);
                            alert(lang_couldnotprocess);
                        }
                    },
                    error: function () {
                        jQuery(refundButton).text(lang_refundbutton);
                        alert('Refund failed');
                    }
                });
            }, 750);
        }
    });
});
