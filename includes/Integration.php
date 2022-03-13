<?php
/**
 * Integration
 *
 * @package Standard Books for WooCommerce
 * @author Konekt
 */

namespace Konekt\WooCommerce\Standard_Books;

defined( 'ABSPATH' ) or exit;

class Integration extends \WC_Integration {


	/** @var Konekt\WooCommerce\Standard_Books\API API handler instance */
	protected $api = null;


	/**
	 * Integration constructor
	 */
	public function __construct() {
		$this->id                 = 'standard_books';
		$this->method_title       = __( 'Standard Books', 'konekt-standard-books' );
		$this->method_description = __( 'Supercharge your WooCommerce with Standard Books integration for seamless orders data exchange.', 'konekt-standard-books' );

		$this->init_form_fields();
		$this->init_settings();

		// Bind to the save action for the settings.
		add_action( 'woocommerce_update_options_integration_' . $this->id, [ $this, 'process_admin_options' ] );

		// Add hooks when WP is initialized
		add_action( 'init', array( $this, 'init' ) );

		// Add custom coupon data
		add_action( 'woocommerce_coupon_options', array( $this, 'add_coupon_giftcard_option' ) );
		add_action( 'woocommerce_coupon_options_save', array( $this, 'save_coupon_giftcard_option' ), 10, 2 );
	}


	/**
	 * WordPress initialized.
	 *
	 * @return void
	 */
	public function init() {

		if ( $this->have_api_credentials() ) {
			if ( 'yes' === $this->get_option( 'invoice_sync_allowed', 'no' ) ) {
				add_action( 'woocommerce_order_status_changed', array( $this, 'maybe_create_invoice' ), 20, 4 );
			}

			// Manual update handling
			add_action( 'woocommerce_product_options_inventory_product_data', array( $this, 'add_product_update_data_field' ) );

			// Admin notices and bulk generating
			add_action( 'load-edit.php', array( $this, 'prepare_orders_for_submittance' ) );
			add_action( 'admin_footer', array( $this, 'add_bulk_actions' ), 10 );

			// Add "Submit again to Standard Books".
			add_filter( 'woocommerce_order_actions', array( $this, 'add_order_view_action' ), 90, 1 );
			add_action( 'woocommerce_order_action_wc_' . $this->get_plugin()->get_id() . '_submit_order_action', array( $this, 'process_order_submit_action' ), 90, 1 );

			// Add order status column
			add_filter( 'manage_edit-shop_order_columns', array( $this, 'add_order_listing_columns' ) );
			add_filter( 'manage_shop_order_posts_custom_column', array( $this, 'show_order_listing_column' ), 10, 1 );

			// Allow filtering orders with/without order ID
			add_action( 'restrict_manage_posts', array( $this, 'filter_orders_by_external_order_id') , 20 );
			add_filter( 'request', array( $this, 'filter_orders_by_external_order_id_query' ) );
		}

		if ( isset( $_GET['update_source'] ) && $this->id === $_GET['update_source'] ) {
			$product_id = sanitize_text_field( wp_unslash( $_GET['post'] ) );
			$product    = wc_get_product( $product_id );

			if ( $product && $product->get_sku() ) {
				$this->get_plugin()->delete_cache( $this->get_plugin()->get_stock_cache_key( $product->get_sku() ) );
				$this->get_plugin()->delete_cache( $this->get_plugin()->get_article_cache_key( $product->get_sku() ) );
			}
		}
	}


	/**
	 * Set integration settings fields
	 *
	 * @return void
	 */
	public function init_form_fields() {

		$this->form_fields = [

			// API configuration
			'api_section_title' => [
				'title' => __( 'API configuration', 'konekt-standard-books' ),
				'type'  => 'title',
			],

			'api_url' => [
				'title'       => __( 'API URL', 'konekt-standard-books' ),
				'type'        => 'text',
				'description' => __( 'A full API URL with protocol, port number and company ID. For example: https://mars.excellent.ee:4455/api/1/', 'konekt-standard-books' ),
			],

			'api_username' => [
				'title'   => __( 'API username', 'konekt-standard-books' ),
				'type'    => 'text',
				'default' => '',
			],

			'api_password' => [
				'title'   => __( 'API password', 'konekt-standard-books' ),
				'type'    => 'password',
				'default' => '',
			],

			// Invoices
			'invoices_section_title' => [
				'title' => __( 'Invoices configuration', 'konekt-standard-books' ),
				'type'  => 'title',
			],

			'invoice_sync_allowed' => [
				'title'   => __( 'Invoices', 'konekt-standard-books' ),
				'type'    => 'checkbox',
				'default' => 'no',
				'value'   => 'yes',
				'label'   => __( 'Allow sending invoices to Standard Books', 'konekt-standard-books' ),
			],

			'invoice_sync_status' => [
				'title'       => __( 'Order status', 'konekt-standard-books' ),
				'type'        => 'select',
				'default'     => 'processing',
				'options'     => [
					'processing' => __( 'Processing', 'woocommerce' ),
					'completed'  => __( 'Completed', 'woocommerce' ),
				],
				'description' => __( 'This determines which order status is needed to be sent to Standard Books', 'konekt-standard-books' ),
			],

			'order_number_prefix' => [
				'title'       => __( 'Invoice number prefix', 'konekt-standard-books' ),
				'type'        => 'text',
				'default'     => '',
			],

			'order_number_prefix_space' => [
				'title'   => __( 'Add space after prefix', 'konekt-standard-books' ),
				'type'    => 'checkbox',
				'default' => 'no',
				'value'   => 'yes',
				'label'   => __( 'Space is added after prefix if selected.', 'konekt-standard-books' ),
			],

			'invoice_item_type' => [
				'title'       => __( 'Invoice item type', 'konekt-standard-books' ),
				'type'        => 'select',
				'default'     => '1',
				'options'     => [
					'0' => __( 'Default', 'konekt-standard-books' ),
					'1' => __( 'Stock article', 'konekt-standard-books' ),
					'2' => __( 'Structural article', 'konekt-standard-books' ),
					'3' => __( 'Service', 'konekt-standard-books' ),
				],
			],

			'invoice_shipping_sku' => [
				'title'       => __( 'Shipping SKU', 'konekt-standard-books' ),
				'type'        => 'text',
				'default'     => '',
			],

			'invoice_private_person_code' => [
				'title'       => __( 'Private person customer code', 'konekt-standard-books' ),
				'type'        => 'text',
				'default'     => '1000',
			],

			'invoice_giftcard_sku' => [
				'title'   => __( 'Giftcard SKU', 'konekt-standard-books' ),
				'type'    => 'text',
				'default' => '',
			],

			'invoice_payment_deal' => [
				'title'       => __( 'Payment deal in days', 'konekt-standard-books' ),
				'type'        => 'text',
				'default'     => '7',
			],

			'invoice_confirmed' => [
				'title'   => __( 'Invoice confirmed', 'konekt-standard-books' ),
				'type'    => 'checkbox',
				'default' => 'no',
				'value'   => 'yes',
				'label'   => __( 'Invoices sent from the shop are automatically confirmed.', 'konekt-standard-books' ),
			],

			'bacs_invoice_unconfirmed' => [
				'title'   => __( 'BACS orders unconfirmed', 'konekt-standard-books' ),
				'type'    => 'checkbox',
				'default' => 'no',
				'value'   => 'yes',
				'label'   => __( 'Orders that are created with BACS payment method will not be confirmed.', 'konekt-standard-books' ),
			],

			// Payments
			'payments_section_title' => [
				'title' => __( 'Payments configuration', 'konekt-standard-books' ),
				'type'  => 'title',
			],

			'payments_enabled' => [
				'title'   => __( 'Mark payments', 'konekt-standard-books' ),
				'type'    => 'checkbox',
				'default' => 'no',
				'value'   => 'yes',
				'label'   => __( 'Payments are automatically added to the invoice.', 'konekt-standard-books' ),
			],

			'payments_code' => [
				'title'   => __( 'Payment code', 'konekt-standard-books' ),
				'type'    => 'text',
				'default' => 'P',
			],

			// Stock
			'stock_section_title' => [
				'title' => __( 'Stock management configuration', 'konekt-standard-books' ),
				'type'  => 'title',
			],

			'stock_sync_allowed' => [
				'title'   => __( 'Stock management', 'konekt-standard-books' ),
				'type'    => 'checkbox',
				'default' => 'no',
				'value'   => 'yes',
				'label'   => __( 'Allow syncing product stock with Standard Books', 'konekt-standard-books' ),
			],

			'primary_warehouse' => [
				'title'   => __( 'Primary warehouse code', 'konekt-standard-books' ),
				'type'    => 'text',
				'default' => '',
			],

			// Product
			'product_section_title' => [
				'title' => __( 'Product configuration', 'konekt-standard-books' ),
				'type'  => 'title',
			],

			'product_sync_allowed' => [
				'title'   => __( 'Products', 'konekt-standard-books' ),
				'type'    => 'checkbox',
				'default' => 'no',
				'value'   => 'yes',
				'label'   => __( 'Allow syncing product data with Standard Books', 'konekt-standard-books' ),
			],

			// Advanced
			'advanced_section_title' => [
				'title' => __( 'Advanced configuration', 'konekt-standard-books' ),
				'type'  => 'title',
			],

			'stock_refresh_rate' => [
				'title'       => __( 'Stock refresh rate', 'konekt-standard-books' ),
				'type'        => 'number',
				'default'     => '15',
				'description' => __( 'How often (in minutes) product stock is fetched from API?', 'konekt-standard-books' )
			],

			'product_refresh_rate' => [
				'title'       => __( 'Product refresh rate', 'konekt-standard-books' ),
				'type'        => 'number',
				'default'     => '30',
				'description' => __( 'How often (in days) product data is fetched from API?', 'konekt-standard-books' )
			],

			'save_api_messages_to_notes' => [
				'title'   => __( 'Messages', 'konekt-standard-books' ),
				'type'    => 'checkbox',
				'default' => 'no',
				'value'   => 'yes',
				'label'   => __( 'Save messages from API to order notes (privately).', 'konekt-standard-books' ),
			],
		];

		if ( $this->have_api_credentials() ) {

			// Taxes
			$this->form_fields['taxes_section_title'] = [
				'title' => __( 'Taxes configuration', 'konekt-standard-books' ),
				'type'  => 'title',
			];

			$this->form_fields['taxes'] = [
				'title' => __( 'Taxes' ),
				'type'  => 'tax_mapping_table',
			];
		}
	}


	/**
	 * Get taxes
	 *
	 * @return void
	 */
	public function get_taxes() {
		if ( false === ( $taxes = $this->get_plugin()->get_cache( 'taxes' ) ) ) {
			$taxes = $this->get_api()->get_taxes();

			if ( ! empty( $taxes ) ) {
				$taxes = array_column( (array) $taxes, 'Comment', 'VATCode' );
			} else {
				$taxes = [];
			}

			$this->get_plugin()->set_cache( 'taxes', $taxes, MONTH_IN_SECONDS );
		}

		return $taxes ?? [];
	}


	/**
	 * Create invoice (if order status is okay)
	 *
	 * @param integer $order_id
	 * @param string $order_old_status
	 * @param string $order_new_status
	 * @param \WC_Order $order
	 *
	 * @return void
	 */
	public function maybe_create_invoice( $order_id, $order_old_status, $order_new_status, $order, $update_stock = true ) {

		if ( $order_new_status !== $this->get_option( 'invoice_sync_status', 'processing' ) ) {
			return;
		}

		$customer_code = $this->get_option( 'invoice_private_person_code', 1000 );

		if ( ! $order->get_billing_company() ) {
			$customer_code = $this->get_option( 'invoice_private_person_code' );
		} else {
			if ( $order->get_customer_id() ) {
				$customer_code = $this->get_api()->get_customer_code( $order->get_customer_id() );
			}

			if ( ! $customer_code ) {
				$customer      = $this->get_api()->create_customer( $order );
				$customer_code = $customer->Code;
			}
		}

		$this->get_api()->create_invoice( $order, $customer_code, $update_stock, true === $this->get_option( 'payments_enabled', false ) );
	}


	/**
	 * Fetches all order and creates invoices
	 *
	 * @return void
	 */
	function prepare_orders_for_submittance() {
		// Only on orders page
		if ( ! isset( $_REQUEST['post_type'] ) || $_REQUEST['post_type'] != 'shop_order' ) {
			return false;
		}

		// Holder for post ids.
		$post_ids = array();

		// Integer validation.
		if ( isset( $_REQUEST['post'] ) ) {
			$post_ids = array_map( 'intval', $_REQUEST['post'] );
		}

		// Do nothing without selected posts
		if ( empty( $post_ids ) ) {
			return false;
		}

		$action_name  = $this->id . '_maybe_generate_invoices';
		$stock_update = empty( $_REQUEST[ $this->id . '_update_stock' ] ) ? 'yes' : $_REQUEST[ $this->id . '_update_stock' ];

		if( ( isset( $_REQUEST['action2'] ) && $_REQUEST['action2'] == $action_name ) || ( isset( $_REQUEST['action'] ) && $_REQUEST['action'] == $action_name ) ) {
			// Create invoices
			foreach( $post_ids as $post_id ) {
				$order      = wc_get_order( $post_id );
				$invoice_id = $this->get_plugin()->get_order_meta( $order, 'invoice_id' );

				if ( $invoice_id ) {
					continue;
				}

				$this->maybe_create_invoice( $post_id, '', $order->get_status(), $order, 'yes' === $stock_update );
			}
		}
	}


	/**
	 * Add bulk actions to Orders list screen via JavaScript, as there is no hook available to be used
	 */
	function add_bulk_actions() {
		global $post_type;

		if ( $post_type == 'shop_order' ) {

			$action_name = $this->id . '_maybe_generate_invoices';

			wc_enqueue_js("
				$( 'select[name=action], select[name=action2]' ).addClass( 'konekt-standard-books-order-actions' );

				$( '<option />' ).val( '" . $action_name . "' ).text( '". __( 'Submit to Standard Books', 'konekt-standard-books' ) . "' ).appendTo( '.konekt-standard-books-order-actions' );

				var update_stock = $( '<select name=\"" . $this->id . '_update_stock' . "\"></select>' )
					.addClass( 'konekt-standard-books-invoice-update-stock' )
					.append( $( '<option />' ).val( 'yes' ).text( '". __( 'Update stock status', 'konekt-standard-books' ) . "' ) )
					.append( $( '<option />' ).val( 'no' ).text( '". __( 'Do not update stock status', 'konekt-standard-books' ) . "' ) )
					.hide();

				$( '.konekt-standard-books-order-actions' ).after( update_stock );

				$( '#posts-filter' )
					.on( 'change', '.konekt-standard-books-order-actions', function(event) {
						if( $( this ).val() == '" . $action_name . "' ) {
							$( '.konekt-standard-books-invoice-update-stock' ).show();
						}
						else {
							$( '.konekt-standard-books-invoice-update-stock' ).hide();
						}
					})

					.on( 'change', '.konekt-standard-books-invoice-update-stock', function (event) {
						var select = $( this );

						$( '#posts-filter' ).find( '.konekt-standard-books-invoice-update-stock' ).not( select ).val( select.val() );
					});
			");
		}
	}


	public function filter_orders_by_external_order_id() {
		global $typenow;

		if ( 'shop_order' === $typenow ) {
			$action_name = $this->id . '_order_id_filter';
			?>
			<select name="<?php echo esc_attr( $action_name ); ?>" id="dropdown_<?php echo esc_attr( $action_name ); ?>">
				<option value=""><?php esc_html_e( 'Show all orders', 'konekt-standard-books' ); ?></option>

				<option value="1" <?php echo esc_attr( isset( $_GET[$action_name] ) ? selected( '1', $_GET[$action_name], false ) : '' ); ?>><?php esc_html_e( 'Standard Books: Submitted', 'konekt-standard-books' ) ?></option>
				<option value="0" <?php echo esc_attr( isset( $_GET[$action_name] ) ? selected( '0', $_GET[$action_name], false ) : '' ); ?>><?php esc_html_e( 'Standard Books: Not submitted', 'konekt-standard-books' ) ?></option>
			</select>
			<?php
		}
	}


	public function filter_orders_by_external_order_id_query( $vars ) {

		global $typenow;

		$action_name = $this->id . '_order_id_filter';

		if ( 'shop_order' == $typenow && isset( $_GET[$action_name] ) && is_numeric( $_GET[$action_name] ) ) {

			$vars['meta_key'] = $this->get_plugin()->get_order_meta_key( 'invoice_id' );

			if ( 1 === (int) $_GET[$action_name] ) {
				$vars['meta_compare'] = '!=';
				$vars['meta_value']   = '';
			} elseif ( 0 === (int) $_GET[$action_name] ) {
				$vars['meta_compare'] = 'NOT EXISTS';
			}
		}

		return $vars;
	}


	/**
	 * Gets the API handler instance.
	 *
	 * @since 1.0.0
	 *
	 * @return Konekt\WooCommerce\Standard_Books\API
	 */
	public function get_api() {

		if ( null === $this->api ) {
			$this->api = new API( $this );
		}

		return $this->api;
	}


	/**
	 * Checks if API username and password have been set
	 *
	 * @return bool
	 */
	private function have_api_credentials() {

		return $this->get_option( 'api_username' ) && $this->get_option( 'api_password' );
	}


	public function generate_tax_mapping_table_html( $key, $data ) {
		$field_key      = $this->get_field_key( $key );
		$default_args   = [
			'title'             => '',
			'disabled'          => false,
			'class'             => '',
			'css'               => '',
			'placeholder'       => '',
			'desc_tip'          => false,
			'description'       => '',
			'custom_attributes' => [],
		];
		$data           = wp_parse_args( $data, $default_args );
		$external_taxes = (array) $this->get_taxes();
		$row_counter    = 0;
		$wc_taxes       = $this->get_all_tax_rates();
		$values         = (array) $this->get_option( $key, array() );

		ob_start();
		?>

		<tr valign="top">
			<th scope="row" class="titledesc">
				<label for="<?php echo esc_attr( $field_key ); ?>"><?php echo wp_kses_post( $data['title'] ); ?> <?php echo $this->get_tooltip_html( $data ); // WPCS: XSS ok. ?></label>
			</th>
			<td class="forminp">

				<?php echo $this->get_description_html( $data ); // WPCS: XSS ok. ?>

				<table class="">

					<thead>
						<tr>
							<th>#</th>
							<th><?php esc_html_e( 'Tax ID', 'konekt-standard-books' ); ?></th>
							<th><?php esc_html_e( 'Comment', 'konekt-standard-books' ); ?></th>
							<th><?php esc_html_e( 'Matching tax', 'konekt-standard-books' ); ?></th>
						</tr>
					</thead>
					<tbody>

						<?php foreach ( $external_taxes as $external_tax_id => $tax_comment ) : ?>

							<?php
							$row_counter++;

							$value = $values[$external_tax_id] ?? false;
							?>

							<tr>
								<td><?php echo $row_counter; ?>.</td>
								<td><?php echo esc_html( $external_tax_id ); ?></td>
								<td><?php echo esc_html( $tax_comment ); ?></td>
								<td>
									<select name="<?php echo esc_attr( $field_key ); ?>[<?php echo esc_attr( $external_tax_id ); ?>]" class="select">
										<?php foreach ( $wc_taxes as $wc_tax_id => $wc_tax ) : ?>

											<option value="<?php echo esc_attr( $wc_tax_id ); ?>" <?php selected( $wc_tax_id, $value, true ); ?>><?php echo esc_html( $wc_tax['label'] ); ?></option>

										<?php endforeach; ?>
									</select>
								</td>
							</tr>
						<?php endforeach; ?>

					</tbody>

				</table>
			</td>
		</tr>

		<?php
		return ob_get_clean();
	}


	/**
	 * Validate tax mapping table field.
	 *
	 * @param  string $key Field key.
	 * @param  string $value Posted Value.
	 * @return string|array
	 */
	public function validate_tax_mapping_table_field( $key, $value ) {

		return $this->validate_multiselect_field( $key, $value );
	}


	public function get_all_tax_rates() {

		$rates = \WC_Tax::get_rates();

		foreach ( $rates as $rate_key => $rate ) {
			if ( 'yes' === $rate['shipping'] && ! isset( $rate['slug'] ) ) {
				$rates[ $rate_key ]['slug'] = get_option( 'woocommerce_shipping_tax_class' );
			}
		}

		foreach ( \WC_Tax::get_tax_class_slugs() as $tax_class ) {

			foreach ( \WC_Tax::get_rates_for_tax_class( $tax_class ) as $rate ) {

				$rates[ $rate->tax_rate_id ] = [
					'label' => $rate->tax_rate_name,
					'rate'  => $rate->tax_rate,
					'slug'  => $tax_class,
				];
			}
		}

		return $rates;
	}


	public function get_matching_tax_code( $wc_tax_class ) {
		$tax         = '';
		$tax_rate_id = '';

		foreach ( $this->get_all_tax_rates() as $rate_id => $rate ) {

			if ( $wc_tax_class == $rate['slug'] ) {
				$tax_rate_id = $rate_id;

				break;
			}
		}

		if ( $tax_rate_id ) {
			$current_taxes = (array) $this->get_option( 'taxes', [] );

			foreach ( $current_taxes as $tax_code => $wc_tax_id ) {
				if ( $tax_rate_id == $wc_tax_id ) {
					$tax = $tax_code;

					break;
				}
			}
		}

		return $tax;
	}


	public function add_order_view_action( $actions ) {
		// Add custom action
		$actions['wc_' . $this->get_plugin()->get_id() . '_submit_order_action'] = __( 'Submit order to Standard Books', 'konekt-standard-books' );

		return $actions;
	}


	public function process_order_submit_action( $order ) {
		if ( ! is_object( $order ) ) {
			$order = wc_get_order( $order );
		}

		// Submit manually
		$this->maybe_create_invoice( $order->get_id(), $this->get_option( 'invoice_sync_status', 'processing' ), $this->get_option( 'invoice_sync_status', 'processing' ), $order );
	}


	public function add_order_listing_columns( $columns ) {
		$new_columns = [];

		foreach ( $columns as $column_name => $column_info ) {

			if ( 'order_total' === $column_name ) {
				$new_columns[ $this->id ] = __( 'Standard Books', 'konekt-standard-books' );
			}

			$new_columns [ $column_name ] = $column_info;

		}

		return $new_columns;
	}


	public function show_order_listing_column( $column ) {
		global $post;

		if ( $this->id == $column ) {
			$order = wc_get_order( $post->ID );

			echo $this->get_plugin()->get_order_meta( $order, 'invoice_id' );
		}
	}


	/**
	 * Add custom button for manual product data update
	 *
	 * @return void
	 */
	public function add_product_update_data_field() {
		?>
		<div class="options_group options-group__<?php echo esc_attr( $this->id ) ?>">
			<p class="form-field">
				<label><?php echo esc_html( $this->get_method_title() ); ?></label>
				<a href="<?php echo esc_url( add_query_arg( 'update_source', $this->id ) ); ?>" class="button"><?php esc_html_e( 'Update data', 'konekt-standard-books' ); ?></a>
			</p>
		</div>
		<?php
	}


	public function add_coupon_giftcard_option() {
		woocommerce_wp_checkbox( array(
			'id'          => 'is_coupon_giftcard',
			'cbvalue'     => 'yes',
			'label'       => __( 'E-giftcard', 'konekt-standard-books' ),
			'description' => __( 'Giftcards change the way discounts are calculated on the invoice.', 'konekt-standard-books' ),
		) );
	}


	public function save_coupon_giftcard_option( $post_id, $coupon ) {
		if ( isset( $_POST['is_coupon_giftcard'] ) ) {
			$coupon->update_meta_data( 'is_coupon_giftcard', 'yes' );
		} else {
			$coupon->update_meta_data( 'is_coupon_giftcard', 'no' );
		}

		$coupon->save();
	}


	public function get_plugin() {
		return wc_konekt_woocommerce_standard_books();
	}


}