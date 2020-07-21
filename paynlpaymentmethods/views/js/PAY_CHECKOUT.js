jQuery(document).ready(function () {
    jQuery(".payment-option IMG").each(function () {
        jQuery(this).parent().parent().addClass('PAYNL');
        var label = jQuery(this).parent().prop('for');
        jQuery('#pay-with-' + label + '-form').addClass('banksDiv');
    });
});