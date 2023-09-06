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
        var currency = jQuery('#pay-currency').val();
        var lang_areyoursure = jQuery('#pay-lang-areyoursure').val();
        var lang_refunding = jQuery('#pay-lang-refunding').val();
        var lang_succes = jQuery("#pay-lang-succesfullyrefunded").val() + ': ' + currency + ' ' + presentationAmount;
        var lang_button = jQuery("#pay-lang-refundbutton").val();
        var lang_couldnotprocess = jQuery("#pay-lang-couldnotprocess").val();
        var errorMessage = 'Refund failed';

        presentationAmount = presentationAmount.replace('.', ',');

        if (confirm(lang_areyoursure + ': ' + currency + ' ' + presentationAmount + ' ?')) {

            var data = {};
            jQuery.extend(data, {amount: amount});
            jQuery.extend(data, {orderid: transactionid});
            jQuery.extend(data, {prestaorderid: PrestaOrderId});
            jQuery.extend(data, {calltype: 'refund'});

            var actionButton = jQuery(this);
            var payOption = jQuery(this).parent();

            jQuery(actionButton).text(lang_refunding);

            exchangeCall(ajaxurl, data, payOption, lang_succes, actionButton, lang_button, lang_couldnotprocess, errorMessage);

        }
    });

    jQuery("#pay-capture-button").click(function () {
        var amount = jQuery('#pay-capture-amount').val();
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
        var currency = jQuery('#pay-currency').val();
        var lang_areyoursurecapture = jQuery('#pay-lang-areyoursurecapture').val();
        var lang_capturing = jQuery('#pay-lang-capturing').val();
        var lang_succes = jQuery("#pay-lang-succesfullycaptured").val() + ': ' + currency + ' ' + presentationAmount;
        var lang_button = jQuery("#pay-lang-capture-button").val();
        var lang_couldnotprocess = jQuery("#pay-lang-couldnotprocesscapture").val();
        var errorMessage = 'Capture failed';

        presentationAmount = presentationAmount.replace('.', ',');


        if (confirm(lang_areyoursurecapture + ': ' + currency + ' ' + presentationAmount + ' ?')) {

            var data = {};
            jQuery.extend(data, {amount: amount});
            jQuery.extend(data, {orderid: transactionid});
            jQuery.extend(data, {prestaorderid: PrestaOrderId});
            jQuery.extend(data, {calltype: 'capture'});

            var actionButton = jQuery(this);
            var payOption = jQuery(this).parent();

            jQuery(actionButton).text(lang_capturing);

            exchangeCall(ajaxurl, data, payOption, lang_succes, actionButton, lang_button, lang_couldnotprocess, errorMessage);
        }
    });

    jQuery("#pay-capture-remaining-button").click(function () {
        var amount = null;

        var transactionid = jQuery('#pay-transactionid').val();
        var PrestaOrderId = jQuery('#pay-prestaorderid').val();
        var ajaxurl = jQuery('#pay-ajaxurl').val();
        var lang_areyoursurecapture = jQuery('#pay-lang-areyoursurecaptureremaining').val();
        var lang_capturing = jQuery('#pay-lang-capturing').val();
        var lang_succes = jQuery("#pay-lang-succesfullycapturedremaining").val();
        var lang_button = jQuery("#pay-lang-capture-remaining-button").val();
        var lang_couldnotprocess = jQuery("#pay-lang-couldnotprocesscapture").val();
        var errorMessage = 'Capture failed';

        if (confirm(lang_areyoursurecapture)) {

            var data = {};
            jQuery.extend(data, {amount: amount});
            jQuery.extend(data, {orderid: transactionid});
            jQuery.extend(data, {prestaorderid: PrestaOrderId});
            jQuery.extend(data, {calltype: 'capture'});

            var actionButton = jQuery(this);
            var payOption = jQuery(this).parent();

            jQuery(actionButton).text(lang_capturing);

            exchangeCall(ajaxurl, data, payOption, lang_succes, actionButton, lang_button, lang_couldnotprocess, errorMessage);
        }
    });

    jQuery("#pay-version-check").click(function () {
        var current_version = jQuery('#pay-version-check').val();
        getVersion(current_version);
    });

    function getVersion(current_version){
        $.ajax({
            url: 'https://api.github.com/repos/paynl/prestashop1.7-plugin/releases',
            method: 'GET',
            dataType: 'json',
            asynchronous: true,
            success: function (data) {
                var newest_version = data[0].tag_name;
                let result = '';

                if (newest_version.startsWith('v')) {
                    newest_version = newest_version.substring(1);
                }

                if (newest_version > current_version) {
                    result = 'There is a new version available (' + newest_version + ')'
                } else {
                    result = 'You are up to date with the latest version'
                    jQuery('#pay-version-check-current-version').addClass('versionUpToDate');
                }

                jQuery('#pay-version-check').hide();
                jQuery('#pay-version-check-result').html(result);
                jQuery('#pay-version-check-result').css('display', 'block');
            }
        })
    }

    function exchangeCall(ajaxurl, data, payOption, lang_succes, actionButton, lang_button, lang_couldnotprocess, errorMessage){
        setTimeout(function () {
            jQuery.ajax({
                url: ajaxurl,
                type: 'POST',
                data: data,
                dataType: 'json',
                success: function (data) {
                    if (data.success) {
                        jQuery('#pay-status').text(' - ');
                        jQuery(payOption).text(lang_succes);
                    } else {
                        jQuery(actionButton).text(lang_button);
                        alert(lang_couldnotprocess);
                    }
                },
                error: function () {
                    jQuery(actionButton).text(lang_button);
                    alert(errorMessage);
                }
            });
        }, 750);
    }
});
