<?php
function upgrade_module_4_1($module)
{
    return Db::getInstance()->execute(
        'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'paynl_pfee_cart` (
        `id_cart` int(11) UNSIGNED NOT NULL,
        `payment_option_id` int(11) NOT NULL,
        `type` tinyint(4) NOT NULL,
        `fee` decimal(20,6) NOT NULL DEFAULT \'0.000000\',
        `total` decimal(20,6) NOT NULL DEFAULT \'0.000000\',
        `date_add` datetime NOT NULL,
        `date_updated` datetime NOT NULL
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8
        PRIMARY KEY (`id_cart`);'
    );
}