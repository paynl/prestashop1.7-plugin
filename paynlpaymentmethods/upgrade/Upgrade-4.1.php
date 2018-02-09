<?php
/**
 * @param $module PaynlPaymentMethods
 */
function upgrade_module_4_1($module)
{
    $results = array();

    $results[] = (bool)$module->createPaymentFeeProduct();

    if(in_array(false, $results)) return false;
    return true;
}