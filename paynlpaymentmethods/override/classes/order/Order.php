<?php
class Order extends OrderCore {
	public function getProductsDetail() {
		$_details = parent::getProductsDetail();
		if (Module::isEnabled('paynlpaymentmethods')) {
			foreach ($_details as &$_detail) {
				if ($_detail['product_id'] == (int)Configuration::get('PAYNL_FEE_PRODUCT_ID')) {
					$taxRate = (float)Module::getInstanceByName('paynlpaymentmethods')->getTaxRate();
					$_detail['tax_rate'] = $taxRate;
					$_detail['unit_price_tax_excl'] = $_detail['product_price'];
					$_detail['unit_price_tax_incl'] = $_detail['unit_price_tax_excl']*(1+($taxRate/100));
				}
			}
			unset($_detail);
		}
		return $_details;
	}
}