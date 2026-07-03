<?php
/**
 * Contract for third-party plugin integrations.
 *
 * @package FlavourSuite\Ai
 */

namespace FlavourSuite\Ai\Integrations\Contracts;

defined( 'ABSPATH' ) || exit;

interface IntegrationInterface {

	/**
	 * Whether the third-party plugin this adapter targets is present.
	 * Prefer class_exists()/function_exists() over is_plugin_active()
	 * (see docs/07-integrations.md).
	 */
	public function is_available(): bool;

	/**
	 * Hook into the vendor plugin. Called only when is_available() is true,
	 * on plugins_loaded priority 20 (all plugins loaded).
	 */
	public function register(): void;
}
