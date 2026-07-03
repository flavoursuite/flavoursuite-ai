=== FlavourSuite AI ===
Contributors: flavoursuite
Tags: mcp, ai, agents, claude, woocommerce
Requires at least: 6.9
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect AI agents (Claude, Cursor, ChatGPT) to your WordPress site over MCP. Off by default, read-only tools, every call permission-checked.

== Description ==

FlavourSuite AI makes your WordPress site **agent-ready**: it exposes a [Model Context Protocol](https://modelcontextprotocol.io/) (MCP) server so AI agents like Claude, Cursor, and ChatGPT can query your site through safe, structured tools instead of scraping pages or guessing at your REST API.

It is built on the WordPress core **Abilities API** (WordPress 6.9+) and the official **wordpress/mcp-adapter**, so it speaks the same standards WordPress itself is adopting — no proprietary protocol, no lock-in.

= Security first =

* **Off by default.** Installing the plugin exposes nothing. The MCP endpoint only exists after an administrator turns it on under *Settings → FlavourSuite AI*.
* **Every tool call is permission-checked.** Agents authenticate as a real WordPress user via an Application Password, and each tool enforces that user's capabilities (`manage_options`, `read`, `manage_woocommerce`, …). There is no anonymous access, ever.
* **Read-only by design.** Version 0.1 ships exclusively read-only tools. Per-tool switches let you disable any of them; future tools that write data will default to off.
* **Audit trail.** The settings screen shows who called which tool, when, and whether it succeeded. Arguments and results are never stored.
* **No data leaves your site.** The plugin makes no external requests and needs no API keys. Agents connect to *you*.

= Tools in this version =

* **Site overview** — WordPress/PHP versions, active plugins, content counts (administrators only).
* **List recent posts** — recent published entries of any public post type.
* **Search content** — full-text search across public content with snippets.
* **Site policies** — privacy, terms, returns, shipping, FAQ and contact pages with their text, so agents answer pre-sale and trust questions from your real policies.
* **WooCommerce store overview** — product and order counts by status, currency, WooCommerce version (shop managers only; no customer data). Registered automatically when WooCommerce is active. WooCommerce also supplies its configured terms and refund pages to the site-policies tool.
* **FluentCRM tools** — when FluentCRM 3.0+ is active, its own read-only CRM context, campaigns, and automations tools appear on this server (contact-level tools are deliberately excluded here; FluentCRM's dedicated MCP server carries the full set).

= Works with your stack =

Integrations are detected automatically: if WooCommerce is active its tools appear, if not they don't — no configuration, no errors. More integrations are on the way.

== Installation ==

1. Install and activate the plugin (WordPress 6.9+, PHP 8.1+).
2. Go to *Settings → FlavourSuite AI* and enable the MCP server.
3. Create an Application Password for your user (*Users → Profile → Application Passwords*).
4. Copy the connection snippet from the settings page into your MCP client (Claude Code, Claude Desktop, Cursor, …).

For example, with Claude Code: `claude mcp add --transport http flavoursuite https://example.com/wp-json/flavoursuite-ai/mcp`

== Frequently Asked Questions ==

= Is this safe to install on a production site? =

Installing it changes nothing until you opt in: the MCP endpoint is not even registered while the master switch is off. When enabled, every request must authenticate as a WordPress user and every tool re-checks that user's capabilities. All tools in this version are read-only.

= Does this send my site's data to an AI company? =

No. The plugin contains no API keys and makes no outbound requests. It's a server: AI agents you control connect to your site, authenticated as a user you created, seeing only what that user may see.

= How do agents authenticate? =

Via WordPress Application Passwords over HTTPS (HTTP Basic auth). Revoke the password and access ends immediately.

= What is MCP? =

The Model Context Protocol is an open standard that lets AI assistants use external tools. Claude, Cursor, ChatGPT, and a growing list of clients support it natively.

= Does it work without WooCommerce? =

Yes. The core site tools work on any WordPress site. WooCommerce tools appear only when WooCommerce is active.

= Which FluentCRM versions are supported? =

FluentCRM 3.0 or newer — that's where FluentCRM introduced its own abilities, which this plugin curates. With an older FluentCRM the CRM tools simply don't appear; nothing breaks.

= Can agents modify my site? =

Not in this version — every shipped tool is read-only. If future versions add write tools, they will be disabled by default and clearly marked.

== Changelog ==

= 0.1.0 =
* Initial release.
* MCP server (Streamable HTTP) built on the core Abilities API and the official wordpress/mcp-adapter.
* Tools: site overview, list recent posts, search content, site policies, WooCommerce store overview (auto-detected), FluentCRM 3.0+ tool curation (auto-detected).
* Settings screen: master switch (off by default), per-tool toggles, connection snippet.
* Audit log of tool calls (user, tool, status, duration — never arguments or results).

== Upgrade Notice ==

= 0.1.0 =
Initial release.
