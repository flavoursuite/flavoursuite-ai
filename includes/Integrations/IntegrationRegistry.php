<?php
/**
 * Boots only the integrations whose vendor plugin is present.
 *
 * @package FlavourSuite\Ai
 */

namespace FlavourSuite\Ai\Integrations;

use FlavourSuite\Ai\Integrations\Contracts\IntegrationInterface;

defined( 'ABSPATH' ) || exit;

final class IntegrationRegistry {

	public static function boot(): void {
		foreach ( self::integrations() as $integration ) {
			if ( $integration->is_available() ) {
				$integration->register();
			}
		}
	}

	/**
	 * One adapter class per third-party plugin. New integration = one new entry.
	 *
	 * @return list<IntegrationInterface>
	 */
	private static function integrations(): array {
		return array(
			new WooCommerce\Integration(),
			new FluentCrm\Integration(),
		);
	}
}
