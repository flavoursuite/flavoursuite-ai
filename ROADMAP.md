# FlavourSuite AI — Roadmap & Market Position

**Last reviewed:** 2026-07-27 · **Current release:** 0.2.1 · **Status:** live on WordPress.org

> Working document. Not shipped to users — excluded via `.distignore`.

---

## Where we stand

Approved by the WordPress.org plugins team on 2026-07-27 after a three-week review
(first submission 2026-07-05). Published to SVN as r3623835 (trunk + assets) and
r3623836 (`tags/0.2.1`). Live at <https://wordpress.org/plugins/flavoursuite-ai>.

Nine tools, Application Password auth, and an approvals flow (propose → diff →
approve/reject → rollback) with staleness protection.

---

## The ecosystem: what WordPress core actually shipped

Researched 2026-07-27 from make.wordpress.org/ai and wordpress.org/plugins.

- **Abilities API is in core as of WordPress 6.9.** Our architectural bet is
  validated — this is the standard the project chose, and three competing plugins
  now require 6.9 for the same reason.
- **`wordpress/mcp-adapter` is at v0.5.0** (released 2026-04-14; 255k installs,
  887 GitHub stars). We vendor exactly this, so we are current. The AI team has
  "multiple improvements awaiting release" and is moving to a weekly/biweekly
  cadence — **we need a standing habit of pulling adapter updates.**
- **The official AI plugin** (40,000+ installs, 4.6★, maintained by wordpress.org;
  1.2.0 shipped 2026-07-14) is *editor-side* AI: alt text, title generation,
  summarisation, comment moderation, type-ahead. It **does not include an MCP
  server** — its docs list MCP as "coming soon."
- **WordPress 7.1's AI focus** is embeddings and the PHP AI Client, not a core MCP
  endpoint.

**Verdict: core is not building our product.** Core is making *WordPress use AI*;
we make *AI use WordPress*. The two are orthogonal today.

**The opening.** The AI team's own status reads: *"Abilities API: read-oriented
foundations established; management capabilities under discussion for future
phases."* Core has shipped read abilities and has **not yet decided how write
abilities should be governed** — which is exactly what `includes/Approvals/`
already does. We are ahead of core on the question core is currently stuck on.
Worth engaging upstream: influence plus credibility.

---

## Competitive landscape (as of 2026-07-27)

| Plugin | Installs | Tools | OAuth | Rate limit | Approval gate |
| --- | ---: | ---: | --- | --- | --- |
| Royal MCP | 9,000+ | 129 | DCR + PKCE | 60/min | none |
| Easy MCP AI | 5,000+ | 242 | 2.0 / 2.1 | 60/min | "force draft" only |
| WPVibe | 5,000+ | — | — | — | none |
| StifLi Flex MCP | 1,000+ | 122+ | 2.1 + PKCE | — | undo + client-side confirm |
| **FlavourSuite AI** | **0 at launch** | **9** | DCR + PKCE | 60/min | **full diff + approve + rollback** |

Notes:

- **Royal MCP** launched 2026-01-14, is updated near-daily, free with no pro tier.
  Strongest all-round competitor.
- **Easy MCP AI** already auto-discovers plugin Abilities on 6.9+ — it *exposes*
  them without governing them.
- **StifLi Flex MCP** monetises via add-ons (Copilot, Chat Agent, Automations),
  supports WP 5.9+, and is the closest to our safety positioning.

### Honest assessment

We are last to market and have 9 tools against 122–242. The two capability gaps
against every competitor — OAuth discovery and rate limiting — closed in 0.3.0;
tool count is the remaining one, and is deliberately not the thing to fix.

But **no competitor has a real pre-execution approval queue.** StifLi's undo is
post-hoc; its "Ask User" is a client-side confirm inside the agent's own session.
Easy MCP's "force draft" is one blunt setting. Our model — a proposal that
persists in wp-admin, reviewed by *a different human than the one driving the
agent*, with staleness refusal — is unique. That is the moat.

**Do not compete on tool count.** That race is unwinnable against a plugin adding
tools daily. Compete on being the only safe way to run anyone's tools.

---

## Plan

### Shipped in 0.3.0 (2026-07-27, not yet released)

- **OAuth discovery.** `Server.php` already implemented the RFC 8414/9728
  documents but served them only under the REST namespace, where no client can
  find them. `OAuth/Discovery.php` now serves both from `/.well-known/`, which is
  what actually unblocks the claude.ai and ChatGPT connectors. Covered by a
  path-matching test including subdirectory installs and the RFC 8414
  issuer-suffix form.
- **Connected agents + revoke.** `Store.php` told users to "revoke unused clients
  in FlavourSuite AI settings" and that screen did not exist. It does now, and
  `find_token()` treats the client registration as source of truth so revocation
  is immediate rather than waiting for token expiry.
- **Rate limiting.** `RateLimit.php`, 60/min per user by default. The real driver
  was not competitor parity: `/register` is unauthenticated by RFC 7591 and
  `Store::MAX_CLIENTS` caps the registry at 50, so anyone could have filled it
  and permanently denied new agents. Anonymous callers get a tighter per-IP
  budget.
- **Audit log CSV export.**
- **Connection tokens.** `ConnectionTokens.php` — named, per-agent, revocable
  bearer tokens with optional expiry, stored only as SHA-256 hashes and shown
  once at creation. The one line that carries the entire security argument is the
  route check in `authenticate()`: the token resolves to a user only when the
  request URI contains `flavoursuite-ai/mcp`, so an agent config that leaks buys
  the tool list and nothing else. Verified over HTTP: the same token that
  completes an MCP `tools/list` returns 401 on `/wp/v2/users` and
  `/wp/v2/settings`. Application Passwords still work, demoted to a second option
  in the recipe builder.
- **Connection recipes for every major agent.** `ClientProfiles.php` — 11 recipes
  across command line, editors, cloud connectors, GUI apps, and an `mcp-remote`
  stdio bridge as the universal fallback. Keyed by *client*, never by model:
  MCP is model-agnostic, so DeepSeek/Qwen/Kimi/GLM/Llama/OpenRouter all work
  through whichever agent the user runs. All JSON templates are verified to parse
  after substitution.

### Now — post-launch hygiene

1. ~~**Banner + icon.**~~ Done — rendered from SVG in `.wordpress-org/src/`, so a
   revision is a one-line `rsvg-convert` rather than a redraw.
2. ~~**Refresh screenshots.**~~ Done — 1 (settings), 3 (connect, now showing the
   token recipe) and 4 (connection tokens) are current. Screenshot 2 (approvals
   diff) is still accurate but was shot at 1535 × 730 and does not match the
   others' 1100 × 800; worth reshooting next time the dev database has a pending
   change request.
3. **Publish 0.3.0 to wp.org.** Still serving 0.2.1; `tags/` contains only
   `0.2.1`. Procedure is in [RELEASE.md](RELEASE.md).
4. **Rotate the SVN password.** Credentials are never stored on disk; use
   `--no-auth-cache` on every commit.
5. **Watch reviews and the support forum.** Competitors sit at 5★ on 4–7 reviews;
   at this sample size the first few reviews move the needle enormously.

### Next

6. **Per-agent tool allowlists.** Connection tokens shipped with a `user` field
   and nothing else; the natural next field is a tool allowlist, so a token can
   be narrower than the account it acts as. Today every agent sees whatever the
   global tool switches expose, which means "read-only agent" is not expressible
   without a second WordPress user. Storage and UI are already in place — this is
   an extra column on the create form plus a check in `Mcp`.

### Strategic — 0.4.0+

7. **Become the governance layer for any registered ability.** Rather than
   hand-writing tools 10 through 100, let admins opt any registered ability
   (official AI plugin, WooCommerce, ACF, FluentCRM, anything) into our server,
   and **automatically route every write-classified ability through the approval
   queue.** `includes/Integrations/Contracts/` is already built for pluggability;
   this is its generalisation.

   This converts a competitor's tool count from a threat into our addressable
   surface: they add tools, we govern them.

---

## Feature backlog

Ranked by unmet need × fit with the approvals moat.

- **Multi-user approvals with notification** — editor's agent proposes, admin is
  pinged by email/Slack, approves in wp-admin. No competitor models *two
  different humans* at all.
- **Per-agent tokens** with tool allowlists and expiry, distinct from per-user.
  Today an Application Password inherits everything its user can do.
- **Rejection reasons fed back to the agent** — `list-change-requests` exists;
  adding *why* closes the loop so agents retry correctly. Nobody does this.
- **WooCommerce write proposals** (price, stock, description) with diffs. The
  highest-stakes writes on any site, and Royal exposes 26 Woo tools with no gate.
- **Block-level content proposals** instead of whole-post replacement — better
  diffs, far fewer staleness refusals.
- **Policy rules** — "auto-approve CSS under 50 lines, always queue post content,
  never allow user changes."
- **Bulk approvals** for high-volume sites.

---

## Monetisation

Royal and Easy are both free with no pro tier, so "free and comprehensive" is a
race to zero. StifLi's add-on model is the sane precedent.

Our natural paid tier is the governance stack: multi-user approvals, Slack and
webhooks, audit export, multisite, policy rules. Agencies and anyone with a
compliance requirement pay for that. Hobbyists never will — and they are not the
buyer.

---

## Open questions

- **Is `Requires at least: 6.9` too aggressive?** It excludes a large installed
  base; StifLi supports 5.9+ and degrades gracefully. Investigate soft-requiring
  the Abilities API instead of hard-requiring 6.9 — but get real adoption numbers
  before spending effort.
- **Should the approvals pattern be proposed upstream** to the WordPress AI team
  while management abilities are still under discussion?

---

## Sources

- <https://make.wordpress.org/ai/>
- <https://wordpress.org/plugins/ai/>
- <https://wordpress.org/plugins/royal-mcp/>
- <https://wordpress.org/plugins/easy-mcp-ai/>
- <https://wordpress.org/plugins/stifli-flex-mcp/>
- <https://packagist.org/packages/wordpress/mcp-adapter>
