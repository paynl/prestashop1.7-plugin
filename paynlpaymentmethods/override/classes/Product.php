<?php
class Product extends ProductCore {
    public static function getPriceStatic($id_product, $usetax = true, $id_product_attribute = null, $decimals = 6, $divisor = null,
		$only_reduc = false, $usereduc = true, $quantity = 1, $force_associated_tax = false, $id_customer = null, $id_cart = null,
		$id_address = null, &$specific_price_output = null, $with_ecotax = true, $use_group_reduction = true, Context $context = null,
		$use_customer_price = true, $id_customization = null)
	{
		if (Module::isEnabled('paynlpaymentmethods')) {
			if ($id_product == (int)Configuration::get('PAYNL_FEE_PRODUCT_ID'))
			{
				$taxRate = (float)Module::getInstanceByName('paynlpaymentmethods')->getTaxRate();
				$price_wt = (float)Module::getInstanceByName('paynlpaymentmethods')->getPaymentFee(null, null, true);
				$price =  (float)$price_wt / (1+($taxRate/100));
				return (float)number_format($usetax ? $price_wt : $price, 2);
			}
		}

		return parent::getPriceStatic($id_product, $usetax, $id_product_attribute, $decimals, $divisor,
			$only_reduc, $usereduc, $quantity, $force_associated_tax, $id_customer, $id_cart,
			$id_address, $specific_price_output, $with_ecotax, $use_group_reduction, $context,
			$use_customer_price, $id_customization);
	}
}