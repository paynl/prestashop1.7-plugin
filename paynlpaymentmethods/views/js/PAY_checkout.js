jQuery(document).ready(function () {
    jQuery(".payment-option IMG[src*='paynlpayment']").each(function (indexNr) {
        jQuery(this).parent().parent().addClass((indexNr == 0 ? 'PAYNL firstMethod' : 'PAYNL'));
    });
});