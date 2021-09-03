<?php
/**
 * API functionality
 *
 * @package Standard Books for WooCommerce
 * @author Konekt
 */

namespace Konekt\WooCommerce\Standard_Books;

use SkyVerge\WooCommerce\PluginFramework\v5_6_1 as Framework;

defined( 'ABSPATH' ) or exit;

class API extends Framework\SV_WC_API_Base {


	/** @var \Konekt\WooCommerce\Standard_Books\Integration the integration class instance */
	private $integration;


	/**
	 * API constructor
	 *
	 * @param \Konekt\WooCommerce\Standard_Books\Integration $integration
	 */
	public function __construct( $integration ) {

		$this->integration = $integration;

		$this->api_username = $this->integration->get_option( 'api_username' );
		$this->api_password = $this->integration->get_option( 'api_password' );
		$this->request_uri  = trailingslashit( $this->integration->get_option( 'api_url' ) );

		$this->set_request_header( 'Connection', 'keep-alive' );
		$this->set_http_basic_auth( $this->api_username, $this->api_password );
		$this->set_request_content_type_header( 'application/x-www-form-urlencoded; charset=utf-8' );

		$this->response_handler = API\Response::class;
	}


	/**
	 * Create customer
	 *
	 * @param \WC_Order $order
	 *
	 * @return integer
	 */
	public function create_customer( $order ) {

		$customer_code = '';
		$customer_id   = $order->get_customer_id();

		if ( $customer_id ) {
			$customer_code = $this->get_customer_code( $customer_id );
		}

		$customer = [
			'Code'        => $customer_code,
			'Name'        => $order->get_billing_company(),
			'Person'      => $order->get_formatted_billing_full_name(),
			'InvAddr0'    => $order->get_billing_city(),
			'InvAddr1'    => $order->get_billing_address_1(),
			'InvAddr2'    => $order->get_billing_address_2(),
			'Phone'       => $order->get_billing_phone(),
			'CountryCode' => $order->get_billing_country(),
			'Email'       => $order->get_billing_email(),
		];

		$response = $this->perform_request(
			$this->get_new_request( [
				'path'   => 'CUVc',
				'method' => 'POST',
				'params' => $this->add_set_field_prefix( $customer ),
			] )
		);

		$customer_code = $response->CUVc->Code;

		if ( $customer_code ) {
			$this->set_customer_code( $customer_id, $customer_code );
		}

		return $response->CUVc;
	}


	public function get_customer_code( $customer_id ) {
		return get_user_meta( $customer_id, '_wc_' . $this->get_plugin()->get_id() . '_customer_id', true );
	}


	public function set_customer_code( $customer_id, $customer_code ) {
		update_user_meta( $customer_id, $customer_id, '_wc_' . $this->get_plugin()->get_id() . '_customer_id', $customer_code );
	}


	/**
	 * Create invoice
	 *
	 * @param \WC_Order $order
	 *
	 * @return bool
	 */
	public function create_invoice( $order, $customer_code, $update_stock = true, $create_payment = true ) {

		$order_items = [];
		$row_number  = 0;
		$giftcard    = null;

		if ( ! empty( $coupons = $order->get_coupons() ) ) {
			foreach ( $coupons as $coupon ) {
				$_coupon = new \WC_Coupon( $coupon->get_code() );

				if ( 'yes' === $_coupon->get_meta( 'is_coupon_giftcard', true ) ) {
					$giftcard = $coupon;

					break;
				}
			}
		}

		// Add order items
		/** @var \WC_Order_Item_Product $order_item */
		foreach ( $order->get_items( [ 'line_item', 'shipping' ] ) as $order_item ) {

			$item_price = $order_item->get_total( 'edit' );

			if ( $giftcard && ! $order_item->is_type( 'shipping' ) ) {
				$item_price = $order_item->get_subtotal( 'edit' );
			}

			$order_row = [
				'stp'      => 1,
				'Spec'     => $order_item->get_name(),
				'Quant'    => $order_item->get_quantity(),
				'Price'    => wc_format_decimal( $item_price / $order_item->get_quantity() ),
				'Sum'      => wc_format_decimal( $item_price ),
				'ItemType' => $this->integration->get_option( 'invoice_item_type', '0' ),
			];

			if ( is_callable( array( $order_item, 'get_product' ) ) ) {

				$product   = $order_item->get_product();
				$order_row = [
					'ArtCode' => $product->get_sku() ?? '',
				] + $order_row;

			} elseif ( $order_item->is_type( 'shipping' ) ) {

				$order_row['ItemType'] = '3';

				if ( $shipping_sku = $this->integration->get_option( 'invoice_shipping_sku' ) ) {
					$order_row = [
						'ArtCode' => $shipping_sku,
					] + $order_row;
				}
			}

			if ( $order_item->get_tax_class() ) {
				$order_row['VATCode'] = $this->integration->get_matching_tax_code( $order_item->get_tax_class() );
			}

			$order_items[] = $order_row;

			$row_number++;
		}

		if ( $giftcard ) {
			$order_items[] = [
				'stp'      => 1,
				'ArtCode'  => $this->integration->get_option( 'invoice_giftcard_sku' ),
				'Spec'     => __( 'Giftcard', 'konekt-standard-books' ),
				'Quant'    => 1,
				'Price'    => wc_format_decimal( 0 - $giftcard->get_discount( 'edit' ) - $giftcard->get_discount_tax( 'edit' ) ),
				'Sum'      => wc_format_decimal( 0 - $giftcard->get_discount( 'edit' ) - $giftcard->get_discount_tax( 'edit' ) ),
				'ItemType' => '0',
			];
		}

		// Prepare invoice data
		$invoice = [
			'RefStr'       => $this->integration->get_option( 'order_number_prefix', '' ) . $order->get_order_number(),
			'InvDate'      => $order->get_date_created()->format( 'Y-m-d' ),
			'Addr1'        => $order->get_billing_address_1(),
			'Addr2'        => $order->get_billing_address_2(),
			'Addr2'        => $order->get_billing_country(),
			'InvComment'   => $order->get_customer_note( 'edit' ),
			'InvType'      => 1,
			'InclVAT'      => 0,
			'Sum0'         => wc_get_rounding_precision(),
			'CurncyCode'   => $order->get_currency(),
			'LangCode'     => get_locale(),
			'UpdStockFlag' => 1,
			'ShipAddr0'    => $order->get_formatted_shipping_full_name(),
			'ShipAddr1'    => $order->get_shipping_address_1(),
			'ShipAddr2'    => $order->get_shipping_address_2(),
			'ShipAddr2'    => $order->get_shipping_country(),
			'Phone'        => $order->get_billing_phone(),
			'rows'         => $order_items,
			'CustCode'     => $customer_code,
			'PayDeal'      => $this->integration->get_option( 'invoice_payment_deal', 7 ),
			'Addr0'        => $order->get_formatted_billing_full_name(),
		];

		// Support for "Estonian Banklinks for WooCommerce"
		$reference_number = $order->get_meta( '_wc_estonian_banklinks_reference_number', true );

		if ( $reference_number ) {
			$invoice['CalcFinRef'] = $reference_number;
		}

		if ( $update_stock ) {
			$invoice['UpdStockFlag'] = 1;
		} else {
			$invoice['UpdStockFlag'] = 0;
		}

		if ( $order->is_paid() ) {
			$invoice['PayDate'] = $order->get_date_paid()->format( 'Y-m-d' );
		}

		if ( ! empty( $warehouse = $this->integration->get_option( 'primary_warehouse' ) ) ) {
			$invoice['Location'] = $warehouse;
		}

		if ( 'bacs' === $order->get_payment_method() && 'yes' === $this->integration->get_option( 'bacs_invoice_unconfirmed', 'no' ) ) {
			$invoice['OKFlag'] = 0;
		} elseif ( 'yes' === $this->integration->get_option( 'invoice_confirmed', 'no' ) ) {
			$invoice['OKFlag'] = 1;
		} else {
			$invoice['OKFlag'] = 0;
		}

		$order_current_invoice_id = $this->get_plugin()->get_order_meta( $order, 'invoice_id' );

		$this->get_plugin()->log( print_r( $invoice, true ) );

		try {
			$request = $this->get_new_request( [
				'method' => $order_current_invoice_id ? 'PATCH' : 'POST',
				'path'   => $order_current_invoice_id ? 'IVVc/' . $order_current_invoice_id : 'IVVc',
				'params' => $this->add_set_field_prefix( apply_filters( 'wc_' . $this->get_plugin()->get_id() . '_invoice_data', $invoice ) ),
			] );

			$response = $this->perform_request( $request );

			if ( 200 === $this->get_response_code() ) {

				if ( $response->IVVc ) {

					if ( ! $order_current_invoice_id ) {
						// Save order and customer IDs from response
						$this->get_plugin()->add_order_meta( $order, [
							'invoice_id'  => $response->IVVc->SerNr,
							'customer_id' => $response->IVVc->CustCode,
						] );

						// Add order note
						$this->get_plugin()->add_order_note(
							$order,
							sprintf(
								__( 'Created invoice with ID %s. Customer ID is %s.', 'konekt-standard-books' ),
								$response->IVVc->SerNr,
								$response->IVVc->CustCode
							)
						);
					} else {

						// Add order note
						$this->get_plugin()->add_order_note(
							$order,
							sprintf(
								__( 'Updated invoice with ID %s. Customer ID is %s.', 'konekt-standard-books' ),
								$response->IVVc->SerNr,
								$response->IVVc->CustCode
							)
						);
					}

					if ( true === $create_payment && $order->is_paid() ) {
						try {
							$payment = [
								'PayMode'   => $this->integration->get_option( 'payments_code', 'P' ),
								'TransDate' => $order->get_date_paid()->format( 'Y-m-d' ),
								'Comment'   => $order->get_payment_method_title(),
								'rows'      => [
									[
										'InvoiceNr' => $response->IVVc->SerNr,
									]
								],
								'OKFlag'    => 1,
							];

							$payment_request = $this->get_new_request( [
								'method' => 'POST',
								'path'   => 'IPVc',
								'params' => $this->add_set_field_prefix( apply_filters( 'wc_' . $this->get_plugin()->get_id() . '_invoice_payment_data', $payment ) ),
							] );

							$payment_response = $this->perform_request( $payment_request );
						}
						catch ( \Exception $e ) {
							$this->get_plugin()->log( print_r( $payment_request->get_params(), true ) );
						}
					}

					// Save customer ID to user
					if ( false !== ( $customer_id = $order->get_customer_id() ) ) {
						update_user_meta( $customer_id, '_wc_' . $this->get_plugin()->get_id() . '_customer_id', $response->IVVc->CustCode );
					}
				} else {
					$this->get_plugin()->log( print_r( $response, true ) );
				}

				if ( 'yes' === $this->integration->get_option( 'save_api_messages_to_notes', 'no' ) ) {
					if ( ! empty( $response->get_messages() ) ) {
						foreach ( $response->get_messages() as $message ) {
							$this->get_plugin()->add_order_note( $order, $message );
						}
					}
				}

			} else {
				// Request failed
			}
		}
		catch ( \Exception $e ) {
			$this->get_plugin()->add_order_note(
				$order,
				__( 'Invoice generation failed.', 'konekt-standard-books' ),
			);

			$this->get_plugin()->log( print_r( $request->get_params(), true ) );
		}

		return 200 === $this->get_response_code();
	}


	public function get_article( $code ) {

		$response = $this->perform_request(
			$this->get_new_request( [
				'path'   => 'INVc',
				'params' => $this->add_filter_prefix( apply_filters( 'wc_' . $this->get_plugin()->get_id() . '_get_article', [
					'Code' => $code
				] ) ),
			] )
		);

		if ( 200 === $this->get_response_code() ) {
			return $response->INVc;
		}

		return null;
	}


	public function get_article_stock( $code ) {
		$params = [
			'Code'     => $code,
			'Location' => $this->integration->get_option( 'primary_warehouse' ),
		];

		$response = $this->perform_request(
			$this->get_new_request( [
				'path'   => 'ItemStatusVc',
				'params' => $this->add_filter_prefix( apply_filters( 'wc_' . $this->get_plugin()->get_id() . '_get_article_stock', $params ) ),
			] )
		);

		if ( 200 === $this->get_response_code() ) {
			return $response->ItemStatusVc;
		}

		return null;
	}


	/**
	 * Get available taxes
	 *
	 * @return void
	 */
	public function get_taxes() {
		try {
			$request = $this->perform_request(
				$this->get_new_request( [
					'path' => 'VATCodeBlock',
				] )
			);
		}
		catch ( Framework\SV_WC_Plugin_Exception $e ) {
			$this->get_plugin()->log( $e );
		}

		return $request->VATCodeBlock->rows->row;
	}


	private function format_date( $datetime ) {
		return $datetime->format( 'Y-m-d' );
	}


	private function add_filter_prefix( $data ) {

		return $this->set_data_prefix( $data, 'filter', '' );
	}


	private function add_set_field_prefix( $data ) {

		return $this->set_data_prefix( $data, 'set_field', 'set_row_field' );
	}


	private function set_data_prefix( $data, $prefix, $subprefix ) {

		$new_data = [];

		foreach ( $data as $key => $item ) {
			if ( $key == '@attributes' ) {
				continue;
			}

			if ( is_array( $item ) ) {

				foreach ( $item as $row_index => $item_array_value ) {
					foreach ( $item_array_value as $item_key => $item_value ) {
						if ( $key == '@attributes' ) {
							continue;
						}

						$new_data[ $subprefix . '.' . $row_index . '.' . $item_key ] = ( $item_value );
					}
				}
			} else {
				$new_data[ $prefix . '.' . $key ] = ( $item );
			}
		}

		return $new_data;
	}


	/**
	 * Construct new API request
	 *
	 * @param array $args
	 *
	 * @return \Konekt\WooCommerce\Standard_Books\API\Request
	 */
	protected function get_new_request( $args = [] ) {
		$args = wp_parse_args( $args, [
			'path'   => '',
			'params' => [],
			'method' => 'GET',
			'data'   => [],
		] );

		return new API\Request( $args['path'], $args['method'], $args['params'], $args['data'] );
	}


	/**
	 * Simple wrapper for wp_remote_request()
	 *
	 * @since 1.0.0
	 *
	 * @param string $request_uri
	 * @param string $request_args
	 * @return array|\WP_Error
	 */
	protected function do_remote_request( $request_uri, $request_args ) {

		return wp_remote_request( $request_uri, $request_args );
	}


	/**
	 * Gets the request body.
	 *
	 * @since 4.5.0
	 * @return string
	 */
	protected function get_request_body() {

		// GET & HEAD requests don't support a body
		if ( in_array( strtoupper( $this->get_request_method() ), array( 'GET', 'HEAD' ) ) ) {
			return '';
		}

		$body = '';

		foreach ( $this->get_request()->get_params() as $key => $value ) {
			$body .= $key . '=' . $value . "&\n";
		}

		return $body;
	}


	/**
	 * Get plugin
	 *
	 * @return Konekt\WooCommerce\Standard_Books\Plugin
	 */
	protected function get_plugin() {
		return wc_konekt_woocommerce_standard_books();
	}


}