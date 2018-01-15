<?php
/**
 * @param $module PaynlPaymentMethods
 */
function upgrade_module_4_1($module)
{
    $results = array();

    $results[] = Db::getInstance()->execute(
        'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'paynl_pfee_cart` (
        `id_cart` int(11) UNSIGNED NOT NULL,
        `payment_option_id` int(11) NOT NULL,
        `type` tinyint(4) NOT NULL,
        `fee` decimal(20,6) NOT NULL DEFAULT \'0.000000\',
        `total` decimal(20,6) NOT NULL DEFAULT \'0.000000\',
        `date_add` datetime NOT NULL,
        `date_updated` datetime NOT NULL,
        PRIMARY KEY (`id_cart`)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8;'
    );

    $results[] = $module->registerHook('actionValidateOrder');
    $results[] = $module->createPaymentFeeProduct();

    $results[] = (bool)$module->uninstallOverrides();
    $results[] = (bool)$module->installOverrides();

    if(in_array(false, $results)) return false;
    return true;
}