=== FlavourSuite AI ===
Contributors: masrawy2025
Tags: mcp, ai, agents, claude, woocommerce
Requires at least: 6.9
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect AI agents (Claude, Cursor, ChatGPT) to your WordPress site over MCP. Agents read directly but can only propose changes — you approve.

== Description ==

FlavourSuite AI makes your WordPress site **agent-ready**: it exposes a [Model Context Protocol](https://modelcontextprotocol.io/) (MCP) server so AI agents like Claude, Cursor, and ChatGPT can query your site through safe, structured tools instead of scraping pages or guessing at your REST API.

It is built on the WordPress core **Abilities API** (WordPress 6.9+) and the official **wordpress/mcp-adapter**, so it speaks the same standards WordPress itself is adopting — no proprietary protocol, no lock-in.

= Security first =

* **Off by default.** Installing the plugin exposes nothing. The MCP endpoint only exists after an administrator turns it on under *Settings → FlavourSuite AI*.
* **Every tool call is permission-checked.** Agents authenticate as a real WordPress user — via an Application Password, or via OAuth 2.1 with PKCE for cloud clients — and each tool enforces that user's capabilities (`manage_options`, `read`, `manage_woocommerce`, …). There is no anonymous access, ever. Connected agents are listed in settings and can be revoked in one click.
* **Rate limited.** Tool calls are capped per user per minute so a runaway agent gets a 429 instead of exhausting your server, and the public OAuth endpoints are capped more tightly per IP.
* **Agents propose, you approve.** No tool writes to your live site. Agents can *propose* changes (Additional CSS, post/page content), which land as pending change requests under *Tools → Agent Changes* — you review a before/after diff and approve, reject, or later roll back each one. Propose tools are additionally **off by default**, and read-only tools have per-tool switches too.
* **Audit trail.** The settings screen shows who called which tool, when, and whether it succeeded, and exports to CSV. Arguments and results are never stored.
* **No data leaves your site.** The plugin makes no external requests and needs no API keys. Agents connect to *you*.

= Tools in this version =

* **Site overview** — WordPress/PHP versions, active plugins, content counts (administrators only).
* **List recent posts** — recent published entries of any public post type.
* **Search content** — full-text search across public content with snippets.
* **Site policies** — privacy, terms, returns, shipping, FAQ and contact pages with their text, so agents answer pre-sale and trust questions from your real policies.
* **WooCommerce store overview** — product and order counts by status, currency, WooCommerce version (shop managers only; no customer data). Registered automatically when WooCommerce is active. WooCommerce also supplies its configured terms and refund pages to the site-policies tool.
* **FluentCRM tools** — when FluentCRM 3.0+ is active, its own read-only CRM context, campaigns, and automations tools appear on this server (contact-level tools are deliberately excluded here; FluentCRM's dedicated MCP server carries the full set).
* **Get custom CSS** — read the current Additional CSS so proposals start from reality.
* **Propose custom CSS change** *(off by default)* — stage a full replacement of the Additional CSS as a pending change request with a reviewable diff.
* **Propose content edit** *(off by default)* — stage a new title and/or content for a post or page, same review flow.
* **List change requests** — agents can check whether their proposals were approved, rejected, applied, or rolled back.

= Agents propose, you approve =

The propose tools never touch your live site. Each proposal stores the *current* state alongside the desired state, and applying it re-checks both the reviewer's capabilities and staleness — if a human edited the CSS or the post after the proposal was made, the apply is refused instead of overwriting that work. Applied changes keep their before-state for one-click rollback, and content edits are additionally covered by core revisions.

= Works with any agent =

MCP is model-agnostic, so the model behind your agent is irrelevant to this plugin — Claude, GPT, Gemini, DeepSeek, Qwen, Kimi, GLM, Llama, Mistral, anything routed through OpenRouter, or a model running locally on your own machine. What matters is the client, and the settings screen generates a ready-to-paste recipe for each one:

* **Command line** — Claude Code, Codex CLI, OpenCode.
* **Editors** — Cursor, VS Code (Copilot), Cline, Roo Code, Windsurf.
* **Cloud clients** — Claude (web and desktop connectors) and ChatGPT connect over OAuth 2.1: they discover the site automatically and you approve the connection in wp-admin, with no password to copy anywhere.
* **Apps with a settings screen** — Cherry Studio, ChatWise, 5ire, LibreChat, Dify, n8n and similar: paste the endpoint and one header.
* **Everything else** — a stdio bridge recipe (`mcp-remote`) covers agents that cannot speak HTTP directly, including LM Studio, Goose and most self-hosted or open-source clients.

= Works with your stack =

Integrations are detected automatically: if WooCommerce is active its tools appear, if not they don't — no configuration, no errors. More integrations are on the way.

== Installation ==

1. Install and activate the plugin (WordPress 6.9+, PHP 7.4+).
2. Go to *Settings → FlavourSuite AI* and enable the MCP server.
3. Create an Application Password for your user (*Users → Profile → Application Passwords*).
4. On the settings page, choose your agent from the list and paste the Application Password into the recipe builder — it produces the exact configuration block for that client. The token is computed in your browser and never stored. Cloud clients such as Claude and ChatGPT skip this step entirely: point them at the endpoint and approve the connection when prompted.

For example, with Claude Code: `claude mcp add --transport http flavoursuite https://example.com/wp-json/flavoursuite-ai/mcp`

Full documentation — including per-client connection guides (Claude Code, Claude Desktop, Cursor, ChatGPT), the Approvals walkthrough, and troubleshooting: [flavoursuite.github.io/docs](https://flavoursuite.github.io/docs/)

== Frequently Asked Questions ==

= Is this safe to install on a production site? =

Installing it changes nothing until you opt in: the MCP endpoint is not even registered while the master switch is off. When enabled, every request must authenticate as a WordPress user and every tool re-checks that user's capabilities. Tools that read are read-only; tools that would change anything only create proposals for you to review.

= Does this send my site's data to an AI company? =

No. The plugin contains no API keys and makes no outbound requests. It's a server: AI agents you control connect to your site, authenticated as a user you created, seeing only what that user may see.

= How do agents authenticate? =

Two ways. Desktop and command-line clients use WordPress Application Passwords over HTTPS (HTTP Basic auth) — revoke the password and access ends immediately. Cloud clients such as claude.ai and ChatGPT use OAuth 2.1 with PKCE: they discover the site automatically, you approve the connection on a consent screen in wp-admin, and you can revoke that agent at any time under Settings → FlavourSuite AI. Either way the agent acts as one WordPress user and is limited to that user's capabilities.

= Which AI agents and models does this work with? =

Any MCP client, and any model. This plugin is an MCP *server*, so the model your agent runs — Claude, GPT, Gemini, DeepSeek, Qwen, Kimi, GLM, Llama, Mistral, an OpenRouter route, or something local — never touches this plugin and makes no difference to it. The settings screen generates the right config for Claude Code, Codex CLI, OpenCode, Cursor, VS Code, Cline, Roo Code, Windsurf, Claude and ChatGPT cloud connectors, and GUI apps like Cherry Studio, ChatWise, 5ire, LibreChat, Dify and n8n. For anything not listed, the `mcp-remote` stdio bridge recipe works with any agent that accepts an `mcpServers` block.

= What is MCP? =

The Model Context Protocol is an open standard that lets AI assistants use external tools. Claude, Cursor, ChatGPT, and a growing list of clients support it natively.

= Does it work without WooCommerce? =

Yes. The core site tools work on any WordPress site. WooCommerce tools appear only when WooCommerce is active.

= Which FluentCRM versions are supported? =

FluentCRM 3.0 or newer — that's where FluentCRM introduced its own abilities, which this plugin curates. With an older FluentCRM the CRM tools simply don't appear; nothing breaks.

= Can agents modify my site? =

Only through you. Agents can *propose* changes (Additional CSS, post/page content), which appear as pending change requests under *Tools → Agent Changes* with a before/after diff. Nothing touches the live site until an administrator approves it there, and applied changes can be rolled back. The propose tools are also disabled by default — you switch them on per tool.

== Screenshots ==

1. Settings → FlavourSuite AI: master switch, per-tool toggles (write tools flagged and off by default), rate limit, and the per-agent connection recipe builder.
2. Tools → Agent Changes: a pending agent proposal shown as a before/after diff with Approve & apply, Reject, and rollback for applied changes.

== Changelog ==

= 0.3.0 =
* OAuth discovery: the authorization-server and protected-resource documents are now served from `/.well-known/`, so cloud MCP clients (claude.ai, ChatGPT) can find and connect to the site without an Application Password.
* Connected agents: OAuth clients are now listed under Settings → FlavourSuite AI with a Revoke action that removes the registration and immediately invalidates every token issued to it.
* Rate limiting: authenticated tool calls are capped per user per minute (60 by default, configurable, 0 to disable) and the public OAuth endpoints are capped per IP. Over-budget requests get a 429 with Retry-After.
* Audit log export: download the recorded tool calls as CSV.
* Connection recipes for every major agent: pick your client on the settings screen and get the exact config block for it — Claude Code, Codex CLI, OpenCode, Cursor, VS Code, Cline, Roo Code, Windsurf, Claude and ChatGPT cloud connectors, GUI apps such as Cherry Studio and LibreChat, plus an `mcp-remote` stdio bridge that covers any other agent. Works regardless of which model the agent runs.

= 0.2.1 =
* Proposed CSS is now validated with WordPress core's own Customizer CSS validation (the same checks as Appearance → Additional CSS), both when an agent proposes it and again before it is applied.
* List change requests now only returns requests the current user authored or has the capability to decide, instead of all requests.
* Updated the jetpack-autoloader library to 5.0.21.

= 0.2.0 =
* Approvals: agents propose, humans approve. New change-request flow with before/after diffs, approve/reject, and rollback under Tools → Agent Changes.
* New tools: get custom CSS, propose custom CSS change, propose content edit, list change requests. Propose tools are off by default.
* Staleness protection: a proposal is refused at apply time if the CSS or post changed after it was made; rollback is refused if the target changed after apply.
* Lowered the PHP requirement to 7.4.

= 0.1.0 =
* Initial release.
* MCP server (Streamable HTTP) built on the core Abilities API and the official wordpress/mcp-adapter.
* Tools: site overview, list recent posts, search content, site policies, WooCommerce store overview (auto-detected), FluentCRM 3.0+ tool curation (auto-detected).
* Settings screen: master switch (off by default), per-tool toggles, connection snippet.
* Audit log of tool calls (user, tool, status, duration — never arguments or results).

== Upgrade Notice ==

= 0.3.0 =
Adds OAuth discovery for cloud clients, connection recipes for every major agent, a Connected agents list with one-click revoke, per-user rate limiting, and CSV export of the audit log.

= 0.2.0 =
Adds the Approvals flow: agents can now propose CSS and content changes that you review and apply in wp-admin. Propose tools are off by default — nothing changes unless you enable them.

= 0.1.0 =
Initial release.
