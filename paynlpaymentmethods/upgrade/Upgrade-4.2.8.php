<?php
/**
 * @param $module PaynlPaymentMethods
 */
function upgrade_module_4_2_8($module)
{
    return (bool) $module->registerHook('actionOrderStatusPostUpdate');
}