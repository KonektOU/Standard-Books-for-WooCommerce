<?php
/**
 * Integration
 *
 * @package Standard Books for WooCommerce
 * @author Konekt
 */

namespace Konekt\WooCommerce\Standard_Books\Data_Stores;

defined( 'ABSPATH' ) or exit;

class Product_Variable extends \WC_Product_Variable_Data_Store_CPT implements \WC_Object_Data_Store_Interface, \WC_Product_Data_Store_Interface {


	/**
	 * Construct
	 *
	 * @param \Konekt\WooCommerce\Standard_Books\Product_Data_Store $base_store
	 */
	public function __construct( $base_store ) {
		$this->base = $base_store;
	}


	/**
	 * Read product data
	 *
	 * @param \WC_product $product
	 *
	 * @return void
	 */
	public function read( &$product ) {

		parent::read( $product );

		if ( ! empty( $product->get_sku() ) ) {

			$this->base->read( $product );
		}
	}


}