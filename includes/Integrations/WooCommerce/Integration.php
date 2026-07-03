<?php
/**
 * WooCommerce integration: read-only store abilities.
 *
 * Vendor functions are only called inside execute callbacks, which run
 * exclusively when WooCommerce is present (is_available() gate).
 *
 * @package FlavourSuite\Ai
 */

namespace FlavourSuite\Ai\Integrations\WooCommerce;

use FlavourSuite\Ai\Integrations\Contracts\IntegrationInterface;

defined( 'ABSPATH' ) || exit;

final class Integration implements IntegrationInterface {

	public function is_available(): bool {
		return class_exists( 'WooCommerce' );
	}

	public function register(): void {
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
		add_filter( 'flavoursuite/ai/mcp_tools', array( $this, 'expose_tools' ) );
	}

	/**
	 * @param list<string> $tools Ability names exposed on the MCP server.
	 * @return list<string>
	 */
	public function expose_tools( array $tools ): array {
		$tools[] = 'flavoursuite/woo-store-overview';
		return $tools;
	}

	public function register_abilities(): void {
		$ability = wp_register_ability(
			'flavoursuite/woo-store-overview',
			array(
				'label'               => __( 'WooCommerce store overview', 'flavoursuite-ai' ),
				'description'         => 'Read-only WooCommerce snapshot: product counts, order counts by status, store currency, and WooCommerce version. Contains no customer data.',
				'category'            => 'flavoursuite',
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'store'    => array( 'type' => 'object' ),
						'products' => array( 'type' => 'object' ),
						'orders'   => array( 'type' => 'object' ),
					),
				),
				'permission_callback' => static fn ( $input = null ): bool => current_user_can( 'manage_woocommerce' ),
				'execute_callback'    => array( $this, 'store_overview' ),
				'meta'                => array(
					'annotations' => array(
						'readonly'   => true,
						'idempotent' => true,
					),
				),
			)
		);

		if ( null === $ability ) {
			error_log( 'FlavourSuite AI: failed to register flavoursuite/woo-store-overview ability.' );
		}
	}

	public function store_overview(): array {
		$product_counts = (array) wp_count_posts( 'product' );

		$orders = array();
		foreach ( wc_get_order_statuses() as $status => $label ) {
			// wc_orders_count() expects the status without the 'wc-' prefix.
			$orders[ $status ] = (int) wc_orders_count( str_replace( 'wc-', '', $status ) );
		}

		return array(
			'store'    => array(
				'currency'            => get_woocommerce_currency(),
				'woocommerce_version' => defined( 'WC_VERSION' ) ? WC_VERSION : null,
			),
			'products' => array(
				'published' => (int) ( $product_counts['publish'] ?? 0 ),
				'draft'     => (int) ( $product_counts['draft'] ?? 0 ),
			),
			'orders'   => $orders,
		);
	}
}
