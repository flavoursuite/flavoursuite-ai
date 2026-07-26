<?php
/**
 * Connection recipes for MCP clients.
 *
 * MCP is model-agnostic: whether an agent is driven by Claude, GPT, Gemini,
 * DeepSeek, Qwen, Kimi, GLM, Llama or anything served through OpenRouter makes
 * no difference to this server. What actually differs between agents is
 *
 *   1. the shape of their config file, and
 *   2. whether they can send an Authorization header at all.
 *
 * So this registry is keyed by CLIENT, never by model. Three auth styles cover
 * the whole ecosystem:
 *
 *   header — client speaks Streamable HTTP and lets you set headers. Most do.
 *   oauth  — cloud client that cannot take a header, but discovers the site via
 *            /.well-known and runs the consent flow (see OAuth\Discovery).
 *   bridge — client only speaks stdio; mcp-remote proxies it over HTTP. This is
 *            the universal fallback and works for agents not listed here.
 *
 * @package FlavourSuite\Ai
 */

namespace FlavourSuite\Ai;

defined( 'ABSPATH' ) || exit;

final class ClientProfiles {

	/**
	 * __URL__ and __AUTH__ are substituted in the browser by assets/js/settings.js
	 * so the credential never round-trips through the server.
	 *
	 * @return list<array{id:string,label:string,group:string,auth:string,file:string,template:string,note:string}>
	 */
	public static function all(): array {
		$profiles = array(

			// ------------------------------------------------ Command line.
			array(
				'id'       => 'claude-code',
				'label'    => __( 'Claude Code', 'flavoursuite-ai' ),
				'group'    => __( 'Command line', 'flavoursuite-ai' ),
				'auth'     => 'header',
				'file'     => '',
				'note'     => __( 'Run this once in any project; the server is then available to every session.', 'flavoursuite-ai' ),
				'template' => 'claude mcp add --transport http flavoursuite __URL__ \\' . "\n" . '  --header "Authorization: __AUTH__"',
			),
			array(
				'id'       => 'codex',
				'label'    => __( 'Codex CLI', 'flavoursuite-ai' ),
				'group'    => __( 'Command line', 'flavoursuite-ai' ),
				'auth'     => 'header',
				'file'     => '~/.codex/config.toml',
				'note'     => __( 'Requires a Codex build with Streamable HTTP support. If yours is stdio-only, use the “Any other agent” recipe instead.', 'flavoursuite-ai' ),
				'template' => "[mcp_servers.flavoursuite]\nurl = \"__URL__\"\n\n[mcp_servers.flavoursuite.http_headers]\nAuthorization = \"__AUTH__\"",
			),
			array(
				'id'       => 'opencode',
				'label'    => __( 'OpenCode', 'flavoursuite-ai' ),
				'group'    => __( 'Command line', 'flavoursuite-ai' ),
				'auth'     => 'header',
				'file'     => 'opencode.json',
				'note'     => __( 'Works with any model OpenCode is pointed at, including OpenRouter and local models.', 'flavoursuite-ai' ),
				'template' => "{\n  \"\$schema\": \"https://opencode.ai/config.json\",\n  \"mcp\": {\n    \"flavoursuite\": {\n      \"type\": \"remote\",\n      \"url\": \"__URL__\",\n      \"enabled\": true,\n      \"headers\": {\n        \"Authorization\": \"__AUTH__\"\n      }\n    }\n  }\n}",
			),

			// ------------------------------------------------------- Editors.
			array(
				'id'       => 'cursor',
				'label'    => __( 'Cursor', 'flavoursuite-ai' ),
				'group'    => __( 'Editors', 'flavoursuite-ai' ),
				'auth'     => 'header',
				'file'     => '~/.cursor/mcp.json',
				'note'     => __( 'Use .cursor/mcp.json inside a project to scope the server to that project only.', 'flavoursuite-ai' ),
				'template' => "{\n  \"mcpServers\": {\n    \"flavoursuite\": {\n      \"url\": \"__URL__\",\n      \"headers\": {\n        \"Authorization\": \"__AUTH__\"\n      }\n    }\n  }\n}",
			),
			array(
				'id'       => 'vscode',
				'label'    => __( 'VS Code (Copilot)', 'flavoursuite-ai' ),
				'group'    => __( 'Editors', 'flavoursuite-ai' ),
				'auth'     => 'header',
				'file'     => '.vscode/mcp.json',
				'note'     => __( 'VS Code nests servers under "servers", not "mcpServers".', 'flavoursuite-ai' ),
				'template' => "{\n  \"servers\": {\n    \"flavoursuite\": {\n      \"type\": \"http\",\n      \"url\": \"__URL__\",\n      \"headers\": {\n        \"Authorization\": \"__AUTH__\"\n      }\n    }\n  }\n}",
			),
			array(
				'id'       => 'cline',
				'label'    => __( 'Cline / Roo Code', 'flavoursuite-ai' ),
				'group'    => __( 'Editors', 'flavoursuite-ai' ),
				'auth'     => 'header',
				'file'     => 'cline_mcp_settings.json',
				'note'     => __( 'Open the MCP Servers panel → Configure. Model choice is irrelevant here — OpenRouter, DeepSeek, Qwen and local models all work.', 'flavoursuite-ai' ),
				'template' => "{\n  \"mcpServers\": {\n    \"flavoursuite\": {\n      \"type\": \"streamableHttp\",\n      \"url\": \"__URL__\",\n      \"headers\": {\n        \"Authorization\": \"__AUTH__\"\n      }\n    }\n  }\n}",
			),
			array(
				'id'       => 'windsurf',
				'label'    => __( 'Windsurf', 'flavoursuite-ai' ),
				'group'    => __( 'Editors', 'flavoursuite-ai' ),
				'auth'     => 'header',
				'file'     => '~/.codeium/windsurf/mcp_config.json',
				'note'     => '',
				'template' => "{\n  \"mcpServers\": {\n    \"flavoursuite\": {\n      \"serverUrl\": \"__URL__\",\n      \"headers\": {\n        \"Authorization\": \"__AUTH__\"\n      }\n    }\n  }\n}",
			),

			// --------------------------------------------- Cloud (OAuth only).
			array(
				'id'       => 'claude-web',
				'label'    => __( 'Claude (web & desktop connectors)', 'flavoursuite-ai' ),
				'group'    => __( 'Cloud clients', 'flavoursuite-ai' ),
				'auth'     => 'oauth',
				'file'     => '',
				'note'     => __( 'Settings → Connectors → Add custom connector, then paste the URL below. Claude discovers this site automatically and asks you to approve the connection in wp-admin — no password to copy.', 'flavoursuite-ai' ),
				'template' => '__URL__',
			),
			array(
				'id'       => 'chatgpt',
				'label'    => __( 'ChatGPT', 'flavoursuite-ai' ),
				'group'    => __( 'Cloud clients', 'flavoursuite-ai' ),
				'auth'     => 'oauth',
				'file'     => '',
				'note'     => __( 'Settings → Connectors → Add. Paste the URL below and approve the connection when prompted.', 'flavoursuite-ai' ),
				'template' => '__URL__',
			),

			// ----------------------------------------------- GUI / workflow.
			array(
				'id'       => 'gui',
				'label'    => __( 'Cherry Studio, ChatWise, 5ire, LibreChat, Dify, n8n…', 'flavoursuite-ai' ),
				'group'    => __( 'Apps with a settings screen', 'flavoursuite-ai' ),
				'auth'     => 'header',
				'file'     => '',
				'note'     => __( 'Add a server of type “Streamable HTTP” (sometimes called HTTP or remote), then paste the URL and the single header below into the app’s own fields.', 'flavoursuite-ai' ),
				'template' => "URL     __URL__\nHeader  Authorization: __AUTH__",
			),

			// ------------------------------------------- Universal fallback.
			array(
				'id'       => 'bridge',
				'label'    => __( 'Any other agent (stdio bridge)', 'flavoursuite-ai' ),
				'group'    => __( 'Everything else', 'flavoursuite-ai' ),
				'auth'     => 'bridge',
				'file'     => '',
				'note'     => __( 'For agents that only speak stdio — LM Studio, Goose, older Claude Desktop, and most self-hosted or open-source clients. mcp-remote proxies this server over stdio and needs only Node.js. If an agent takes an "mcpServers" block at all, this works.', 'flavoursuite-ai' ),
				'template' => "{\n  \"mcpServers\": {\n    \"flavoursuite\": {\n      \"command\": \"npx\",\n      \"args\": [\n        \"-y\",\n        \"mcp-remote\",\n        \"__URL__\",\n        \"--header\",\n        \"Authorization: __AUTH__\"\n      ]\n    }\n  }\n}",
			),
		);

		/**
		 * Filters the connection recipes offered on the settings screen.
		 *
		 * @param list<array> $profiles Client profiles.
		 */
		return (array) apply_filters( 'flavoursuite/ai/client_profiles', $profiles );
	}
}
