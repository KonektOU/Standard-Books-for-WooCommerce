<?php
/**
 * Plugin base class
 *
 * @package Standard Books for WooCommerce
 * @author Konekt
 */

namespace Konekt\WooCommerce\Standard_Books;

defined( 'ABSPATH' ) or exit;

use SkyVerge\WooCommerce\PluginFramework\v5_6_1 as Framework;

/**
 * @since 1.0.0
 */
class Plugin extends Framework\SV_WC_Plugin {


	/** @var Plugin */
	protected static $instance;

	/** plugin version number */
	const VERSION = '1.0.6';

	/** plugin id */
	const PLUGIN_ID = 'wc-standard-books';

	/** @var string the integration class name */
	const INTEGRATION_CLASS = '\\Konekt\\WooCommerce\\Standard_Books\\Integration';

	/** @var string the data store class name */
	const DATASTORE_CLASS = '\\Konekt\\WooCommerce\\Standard_Books\\Product_Data_Store';

	/** @var \Konekt\WooCommerce\Standard_Books\Integration the integration class instance */
	private $integration;

	/** @var string cache transient prefix */
	private $cache_prefix;


	/**
	 * Constructs the plugin.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {

		$this->cache_prefix = 'wc_' . self::PLUGIN_ID . '_cache_';

		parent::__construct(
			self::PLUGIN_ID,
			self::VERSION,
			array(
				'text_domain' => 'konekt-standard-books',
			)
		);
	}


	/**
	 * Initializes the plugin.
	 *
	 * @since 1.0.0
	 */
	public function init_plugin() {

		// Add integration
		$this->load_integration();

		add_filter( 'woocommerce_integrations', array( $this, 'load_integration' ) );

		// Add custom data store
		add_filter( 'woocommerce_data_stores', array( $this, 'load_product_data_store' ) );
	}


	/**
	 * Loads integration
	 *
	 * @param array $integrations
	 *
	 * @return array
	 */
	public function load_integration( $integrations = [] ) {

		if ( ! class_exists( self::INTEGRATION_CLASS ) ) {
			require_once( $this->get_plugin_path() . '/includes/Integration.php' );
		}

		if ( ! in_array( self::INTEGRATION_CLASS, $integrations, true ) ) {
			$integrations[ self::PLUGIN_ID ] = self::INTEGRATION_CLASS;
		}

		return $integrations;
	}


	public function load_product_data_store( $stores = [] ) {

		if ( ! class_exists( self::DATASTORE_CLASS ) ) {
			require_once( $this->get_plugin_path() . '/includes/Product_Data_Store.php' );
			require_once( $this->get_plugin_path() . '/includes/Data_Stores/Product.php' );
			require_once( $this->get_plugin_path() . '/includes/Data_Stores/Product_Variable.php' );
			require_once( $this->get_plugin_path() . '/includes/Data_Stores/Product_Variation.php' );
		}

		$base_store = self::DATASTORE_CLASS;

		$stores['product']           = new Data_Stores\Product( new $base_store ) ;
		$stores['product-variable']  = new Data_Stores\Product_Variable( new $base_store ) ;
		$stores['product-variation'] = new Data_Stores\Product_Variation( new $base_store ) ;

		return $stores;
	}


	/**
	 * Returns the integration class instance.
	 *
	 * @since 1.0.0
	 *
	 * @return \Konekt\WooCommerce\Standard_Books\Integration the integration class instance
	 */
	public function get_integration() {

		if ( null === $this->integration ) {

			$integrations = null === WC()->integrations ? [] : WC()->integrations->get_integrations();
			$integration  = self::INTEGRATION_CLASS;

			if ( isset( $integrations[ self::PLUGIN_ID ] ) && $integrations[ self::PLUGIN_ID ] instanceof $integration ) {

				$this->integration = $integrations[ self::PLUGIN_ID ];

			} else {

				$this->load_integration();

				$this->integration = new $integration();
			}
		}

		return $this->integration;
	}


	/**
	 * Get prefixed meta key for order meta
	 *
	 * @param string $meta_key
	 *
	 * @return string
	 */
	public function get_order_meta_key( $meta_key ) {
		return '_wc_' . $this->get_id() . '_' . $meta_key;
	}


	/**
	 * Add order meta
	 *
	 * @param \WC_Order $order
	 * @param string|array $meta
	 * @param string $value
	 *
	 * @return void
	 */
	public function add_order_meta( \WC_Order $order, $meta, $value = null ) {

		if ( is_array( $meta ) ) {
			foreach ( $meta as $key => $value ) {
				$order->update_meta_data( $this->get_order_meta_key( $key ), $value );
			}

		} else {
			$order->update_meta_data( $this->get_order_meta_key( $meta ), $value );
		}

		$order->save_meta_data();
	}


	public function get_order_meta( \WC_Order $order, $key ) {
		return $order->get_meta( $this->get_order_meta_key( $key ), true );
	}


	/**
	 * Add note to order
	 *
	 * @param \WC_Order $order
	 * @param string $message
	 *
	 * @return void
	 */
	public function add_order_note( \WC_Order $order, $message ) {

		$order->add_order_note( sprintf( '%s: %s', $this->get_integration()->get_method_title(), $message ) );
	}


	public function get_stock_cache_key( $product_sku ) {
		return 'article_stock_' . $product_sku;
	}


	public function get_article_cache_key( $product_sku ) {
		return 'article_' . $product_sku;
	}


	public function get_cache( $cache_key ) {
		return get_transient( $this->cache_prefix . $cache_key );
	}


	public function set_cache( $cache_key, $data, $expiration ) {
		return set_transient( $this->cache_prefix . $cache_key, $data, $expiration );
	}


	public function delete_cache( $cache_key ) {
		return delete_transient( $this->cache_prefix . $cache_key );
	}


	/**
	 * Gets the URL to the settings page.
	 *
	 * @since 1.0.0
	 *
	 * @see Framework\SV_WC_Plugin::is_plugin_settings()
	 * @param string $_ unused
	 * @return string URL to the settings page
	 */
	public function get_settings_url( $_ = '' ) {

		return admin_url( 'admin.php?page=wc-settings&tab=integration&section=standard_books' );
	}


	/**
	 * Get documentation URL
	 *
	 * @return void
	 */
	public function get_documentation_url() {

		return 'https://konekt.ee/woocommerce/standard-books';
	}


	/**
	 * Gets the plugin full name including "WooCommerce"
	 *
	 * @since 1.0.0
	 *
	 * @return string plugin name
	 */
	public function get_plugin_name() {

		return __( 'Standard Books for WooCommerce', 'konekt-standard-books' );
	}


	/**
	 * Gets the full path and filename of the plugin file.
	 *
	 * @since 1.0.0
	 *
	 * @return string the full path and filename of the plugin file
	 */
	protected function get_file() {

		return __DIR__;
	}


	/**
	 * Gets the main instance of Framework Plugin instance.
	 *
	 * Ensures only one instance is/can be loaded.
	 *
	 * @since 1.0.0
	 *
	 * @return Plugin
	 */
	public static function instance() {

		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}


}