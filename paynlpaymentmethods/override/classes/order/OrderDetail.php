<?php

class OrderDetail extends OrderDetailCore
{

	public static $definition = array(
		'table' => 'order_detail',
		'primary' => 'id_order_detail',
		'fields' => array(
			'id_order' =>                    array('type' => self::TYPE_INT, 'validate' => 'isUnsignedId', 'required' => true),
			'id_order_invoice' =>            array('type' => self::TYPE_INT, 'validate' => 'isUnsignedId'),
			'id_warehouse' =>                array('type' => self::TYPE_INT, 'validate' => 'isUnsignedId', 'required' => true),
			'id_shop' =>                array('type' => self::TYPE_INT, 'validate' => 'isUnsignedId', 'required' => true),
			'product_id' =>                array('type' => self::TYPE_INT, 'validate' => 'isUnsignedId'),
			'product_attribute_id' =>        array('type' => self::TYPE_INT, 'validate' => 'isUnsignedId'),
			'id_customization' =>        array('type' => self::TYPE_INT, 'validate' => 'isUnsignedId'),
			'product_name' =>                array('type' => self::TYPE_STRING, 'validate' => 'isGenericName', 'required' => true),
			'product_quantity' =>            array('type' => self::TYPE_INT, 'validate' => 'isInt', 'required' => true),
			'product_quantity_in_stock' =>    array('type' => self::TYPE_INT, 'validate' => 'isInt'),
			'product_quantity_return' =>    array('type' => self::TYPE_INT, 'validate' => 'isUnsignedInt'),
			'product_quantity_refunded' =>    array('type' => self::TYPE_INT, 'validate' => 'isUnsignedInt'),
			'product_quantity_reinjected' =>array('type' => self::TYPE_INT, 'validate' => 'isUnsignedInt'),
			//'product_price' =>                array('type' => self::TYPE_FLOAT, 'validate' => 'isPrice', 'required' => true),
			'product_price' =>                array('type' => self::TYPE_FLOAT, 'validate' => 'isFloat', 'required' => true), //override reason
			'reduction_percent' =>            array('type' => self::TYPE_FLOAT, 'validate' => 'isFloat'),
			'reduction_amount' =>            array('type' => self::TYPE_FLOAT, 'validate' => 'isPrice'),
			'reduction_amount_tax_incl' =>  array('type' => self::TYPE_FLOAT, 'validate' => 'isPrice'),
			'reduction_amount_tax_excl' =>  array('type' => self::TYPE_FLOAT, 'validate' => 'isPrice'),
			'group_reduction' =>            array('type' => self::TYPE_FLOAT, 'validate' => 'isFloat'),
			'product_quantity_discount' =>    array('type' => self::TYPE_FLOAT, 'validate' => 'isFloat'),
			'product_ean13' =>                array('type' => self::TYPE_STRING, 'validate' => 'isEan13'),
			'product_isbn' =>                array('type' => self::TYPE_STRING, 'validate' => 'isIsbn'),
			'product_upc' =>                array('type' => self::TYPE_STRING, 'validate' => 'isUpc'),
			'product_reference' =>            array('type' => self::TYPE_STRING, 'validate' => 'isReference'),
			'product_supplier_reference' => array('type' => self::TYPE_STRING, 'validate' => 'isReference'),
			'product_weight' =>            array('type' => self::TYPE_FLOAT, 'validate' => 'isFloat'),
			'tax_name' =>                    array('type' => self::TYPE_STRING, 'validate' => 'isGenericName'),
			'tax_rate' =>                    array('type' => self::TYPE_FLOAT, 'validate' => 'isFloat'),
			'tax_computation_method' =>        array('type' => self::TYPE_INT, 'validate' => 'isUnsignedId'),
			'id_tax_rules_group' =>        array('type' => self::TYPE_INT, 'validate' => 'isInt'),
			'ecotax' =>                    array('type' => self::TYPE_FLOAT, 'validate' => 'isFloat'),
			'ecotax_tax_rate' =>            array('type' => self::TYPE_FLOAT, 'validate' => 'isFloat'),
			'discount_quantity_applied' =>    array('type' => self::TYPE_INT, 'validate' => 'isInt'),
			'download_hash' =>                array('type' => self::TYPE_STRING, 'validate' => 'isGenericName'),
			'download_nb' =>                array('type' => self::TYPE_INT, 'validate' => 'isInt'),
			'download_deadline' =>            array('type' => self::TYPE_DATE, 'validate' => 'isDateFormat'),
			'unit_price_tax_incl' =>        array('type' => self::TYPE_FLOAT, 'validate' => 'isPrice'),
			'unit_price_tax_excl' =>        array('type' => self::TYPE_FLOAT, 'validate' => 'isPrice'),
			'total_price_tax_incl' =>        array('type' => self::TYPE_FLOAT, 'validate' => 'isPrice'),
			'total_price_tax_excl' =>        array('type' => self::TYPE_FLOAT, 'validate' => 'isPrice'),
			'total_shipping_price_tax_excl' => array('type' => self::TYPE_FLOAT, 'validate' => 'isPrice'),
			'total_shipping_price_tax_incl' => array('type' => self::TYPE_FLOAT, 'validate' => 'isPrice'),
			'purchase_supplier_price' =>    array('type' => self::TYPE_FLOAT, 'validate' => 'isPrice'),
			'original_product_price' =>    array('type' => self::TYPE_FLOAT, 'validate' => 'isPrice'),
			'original_wholesale_price' =>    array('type' => self::TYPE_FLOAT, 'validate' => 'isPrice')
		),
	);
}