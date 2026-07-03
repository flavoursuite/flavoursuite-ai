<?php
/**
 * FluentCRM integration: curates FluentCRM's OWN abilities onto our server.
 *
 * FluentCRM ≥ 3.0 registers ~22 `fluent-crm/*` abilities itself (and its own
 * MCP server at /wp-json/fluent-crm/mcp once an adapter is present — ours).
 * Registering duplicates would be noise; instead we re-expose a curated
 * read-only, non-PII subset behind our settings toggles and audit log.
 *
 * Contact-level tools (list-contacts, get-contact, …) are deliberately NOT
 * exposed here: they return personal data. Agents needing full CRM access
 * should connect to FluentCRM's dedicated server, which carries the full set.
 *
 * Their registrar maps annotations into meta.annotations, so these tools
 * carry readonly=true and follow our normal default-ON rule for readonly.
 *
 * @package FlavourSuite\Ai
 */

namespace FlavourSuite\Ai\Integrations\FluentCrm;

use FlavourSuite\Ai\Integrations\Contracts\IntegrationInterface;

defined( 'ABSPATH' ) || exit;

final class Integration implements IntegrationInterface {

	/**
	 * Verified read-only + non-PII by source review (FluentCRM 3.1.8):
	 * config, stats, campaign and automation summaries — no contact records.
	 */
	private const TOOLS = array(
		'fluent-crm/get-crm-context',
		'fluent-crm/list-campaigns',
		'fluent-crm/list-automations',
	);

	public function is_available(): bool {
		return defined( 'FLUENTCRM' );
	}

	public function register(): void {
		add_filter( 'flavoursuite/ai/mcp_tools', array( $this, 'expose_tools' ) );
	}

	/**
	 * Abilities only exist if FluentCRM's MCP module is enabled (their
	 * `mcp_enabled` option, default yes) — hence the per-name guard.
	 *
	 * @param list<string> $tools Ability names exposed on the MCP server.
	 * @return list<string>
	 */
	public function expose_tools( array $tools ): array {
		foreach ( self::TOOLS as $name ) {
			if ( wp_has_ability( $name ) ) {
				$tools[] = $name;
			}
		}
		return $tools;
	}
}
