# Release plan — 0.3.0

**Status: PUBLISHED 2026-07-27.** `r3624889` (trunk + assets), `r3624893`
(`tags/0.3.0`). Live and downloadable; `tags/` now holds `0.2.1` and `0.3.0`, and
`assets/` holds the banner pair, the icon pair, and four screenshots.

Verified after publishing: the tag's `Stable tag` and plugin header both read
`0.3.0`, `includes/ConnectionTokens.php` is present, and no `.playwright-mcp`
artefacts made it in. No credential was cached — `~/.subversion/auth/svn.simple/`
is still empty.

> Working document. Not shipped to users — excluded via `.distignore`.
> Strategy and market position live in [ROADMAP.md](ROADMAP.md).

---

## Verified live on 2026-07-27

Tested against `http://localhost/site` — usefully a **subdirectory install**, which
exercises the awkward path cases rather than the easy ones.

| Area | Result |
| --- | --- |
| `/.well-known/oauth-protected-resource` | 200, correct JSON, resolves under `/site` |
| `/.well-known/oauth-authorization-server` | 200, correct issuer and endpoints |
| `/.well-known/openid-configuration` | 404 — unrelated paths are not hijacked |
| MCP route unauthenticated | 401 with `WWW-Authenticate` pointing at resource metadata |
| Rate limiter, anonymous | 10 × 201 then 429 with `Retry-After: 29` |
| Settings page render | 28 KB, all controls present, 11 profiles valid JSON after `esc_attr()` |
| Revoke | client removed and its tokens invalidated in the same request |
| CSV export, authenticated | 200, `text/csv`, RFC 4180 body with header row |
| CSV export, anonymous | 400 at the WordPress routing layer — no `admin_post_nopriv_` handler is registered, so the request never reaches our code |
| OAuth: register → code → token (PKCE S256) | access + refresh tokens issued |
| OAuth: wrong PKCE verifier | `invalid_grant`, correctly rejected |
| OAuth token → MCP `initialize` → `tools/list` | 12 tools returned over bearer auth |

## Connection tokens, verified 2026-07-27

| Area | Result |
| --- | --- |
| Create → `initialize` → `tools/list` → `tools/call` | 12 tools, `flavoursuite-site-overview` executed, audit log attributes it to `mego` |
| **Same token on `/wp/v2/users?context=edit`** | **401** |
| **Same token on `/wp/v2/settings`** | **401 `rest_forbidden`** |
| Never overrides an earlier auth filter | passing `99` in returns `99` |
| Forged, truncated, and `Basic` headers | all rejected |
| Expired token | rejected; flipping `expires` back to 0 restores it |
| Revoke mid-session | next request on the *same* `Mcp-Session-Id` returns 401 |
| Plaintext in the database | absent — only the SHA-256 hash |
| Plaintext in the redirect URL | absent — handed over in a 60-second per-user transient |
| One-shot display | replaying `?fs-token=created` does not resurrect it |
| Option autoload | `off` |
| Nonce actions | both forms validate against their handler's action; no `admin_post_nopriv_` registered |
| XSS via token label | `<script>` in a label is escaped in every output position |
| Recipe builder, both modes | Bearer and Basic headers built correctly; switching mode clears the other's header |
| PHP 7.4 compatibility | clean (PHPCompatibilityWP) |
| Browser console | clean |

**One real bug found and fixed by this pass.** The dev tree fataled with
`Class "FlavourSuite\Ai\OAuth\Discovery" not found`. The Jetpack Autoloader
compiles a *static classmap at composer time*; the three new classes were created
afterwards, so they were absent from it. Lint, the ZIP build, and every isolated
test passed regardless — only booting WordPress caught it.

Release ZIPs were never affected: `bin/build-zip.sh` runs a fresh
`composer install --no-dev` in its staging directory, and the shipped classmap was
confirmed to contain all three classes.

---

## Blockers before publishing — all cleared

### 1. Banner and icon — DONE

Generated 2026-07-27 into `.wordpress-org/`: `banner-772x250.png`,
`banner-1544x500.png`, `icon-256x256.png`, `icon-128x128.png`. Sources are SVG in
`.wordpress-org/src/`, rendered with `rsvg-convert`, so any edit is a one-line
re-render rather than a redraw:

```bash
cd .wordpress-org
rsvg-convert -w 772  -h 250 src/banner.svg -o banner-772x250.png
rsvg-convert -w 1544 -h 500 src/banner.svg -o banner-1544x500.png
rsvg-convert -w 256  -h 256 src/icon.svg   -o icon-256x256.png
rsvg-convert -w 128  -h 128 src/icon.svg   -o icon-128x128.png
```

Design is a mint-to-cyan check inside a rounded diamond on a deep indigo
gradient — the approval gate, which is the product's actual differentiator —
with the wordmark and "Agents propose. You approve."

These go in the **SVN `assets/` directory**, never in `trunk/`; they are page
assets, not plugin files, and `.distignore` keeps them out of the ZIP.

### 2. Screenshots — DONE

Recaptured 2026-07-27 at 1100 × 800 from the live install via Playwright:

- `screenshot-1.png` — settings top: master switch, the twelve tool toggles with
  write tools flagged red, and the rate limit.
- `screenshot-3.png` — the connect section on Claude Code, with the connection
  token selected and the generated `--header "Authorization: Bearer fsai_…"`.
  The token shown is illustrative, not a real credential.
- `screenshot-4.png` — the connection tokens table: three agents, mixed expiry
  states, last-used column, per-row Revoke.

`screenshot-2.png` (approvals diff) is unchanged and still accurate, though it is
1535 × 730 and so does not match the other three. Worth reshooting for consistency
next time there is a pending change request in the dev database.

### 3. Codex CLI recipe — DONE

Verified against the Codex docs. `url` and `http_headers` were the right keys,
but the documented form is an inline table, so the template now reads
`http_headers = { "Authorization" = "…" }` rather than a sub-section. Both are
valid TOML; matching the docs avoids confusing users. Streamable HTTP needs no
experimental flag. The profile note now also mentions `auth = "oauth"` as an
alternative to carrying a header.

---

## Release procedure

Prerequisites: SVN password from
<https://profiles.wordpress.org/me/profile/edit/group/3/?screen=svn-password>.
Never store it on disk; always pass `--no-auth-cache`.

```bash
# 1. Build. Refuses if the plugin header Version and readme Stable tag disagree.
./bin/build-zip.sh

# 2. Check out the wp.org repo into a throwaway directory.
svn co https://plugins.svn.wordpress.org/flavoursuite-ai svn-wporg

# 3. Replace trunk from the ZIP. The ZIP's top-level folder must be stripped —
#    trunk holds the plugin files directly.
unzip -q dist/flavoursuite-ai-0.3.0.zip -d /tmp/fs-extract
rsync -a --delete /tmp/fs-extract/flavoursuite-ai/ svn-wporg/trunk/

# 4. Page assets (banners, icons, screenshots) live outside trunk.
cp .wordpress-org/*.png svn-wporg/assets/

# 5. Stage and commit.
svn add --force svn-wporg
svn ci svn-wporg -m "Release 0.3.0" \
  --username masrawy2025 --no-auth-cache --non-interactive --password '…'

# 6. Tag with a server-side copy — O(1) metadata, no working copy needed.
svn cp https://plugins.svn.wordpress.org/flavoursuite-ai/trunk \
       https://plugins.svn.wordpress.org/flavoursuite-ai/tags/0.3.0 \
  -m "Tagging version 0.3.0" \
  --username masrawy2025 --no-auth-cache --non-interactive --password '…'
```

Step 3 uses `--delete` because trunk already holds 0.2.1; without it, files
removed in 0.3.0 would linger. Watch for `svn st` reporting `!` (missing) entries
after the rsync and `svn rm` them before committing.

wp.org serves users whatever `tags/<Stable tag>/` contains, so the tag directory
name must equal the readme `Stable tag:` value exactly.

---

## Dev environment notes

- **Adding a class under `includes/` needs no build step.** `Lifecycle` prepends
  a PSR-4 loader for `FlavourSuite\Ai\`, so our own classes resolve straight from
  disk. Before that existed, a new file missing from the compiled Jetpack
  classmap fataled on boot while lint and the ZIP build both passed.
- **The Jetpack Autoloader still handles `vendor/`, and must.** WooCommerce ships
  its own copy of `wordpress/mcp-adapter`; the adapter has global hook names and a
  singleton, so exactly one copy must win site-wide and it must be the newest.
  Jetpack is what arbitrates that. Do not replace it with a plain Composer
  autoloader, and do not scope the adapter with PHP-Scoper or Mozart — prefixing
  it would create a second, isolated MCP singleton competing with WooCommerce's.
- The dev tree is symlinked into `/var/www/html/site/wp-content/plugins/`, so
  edits are live immediately. Apache and MariaDB must be running.
- `wp eval` with `wp_set_current_user()` renders admin screens headlessly, which
  is much faster than driving a browser for smoke tests. **`wp eval-file` needs a
  leading `<?php`** — it `include`s the file, so without the tag the whole script
  is echoed as text and appears to "run" silently.
- **Editing an asset without bumping the version serves a stale file in dev.**
  Scripts are enqueued with `FLAVOURSUITE_AI_VERSION` as the cache buster, so two
  edits inside one version are invisible to a browser that already cached the
  first. Real users are unaffected — they only ever see a released version — but
  in dev, force revalidation (`fetch(src, {cache: 'reload'})`, then reload) before
  concluding that a JS change does not work.
- Rendering `Settings::render_page()` twice in one process is a test artifact, not
  a real request. State that must not survive a render therefore belongs in a
  local, not a static property — `take_new_token()` exists for exactly that
  reason.
- WordPress auth cookies are pipe-delimited (`user|expiry|token|hmac`) — never
  split shell output on `|` when handling them.
- Run Plugin Check against the **built ZIP**, not the working tree, or it flags
  `dist/`, `bin/` and the dotfiles that `.distignore` already strips.
- **`.distignore` is a denylist and fails open.** Anything new in the working tree
  ships by default. `bin/build-zip.sh` now refuses to build when a staged file
  outside `vendor/` is untracked by git, which inverts that to an allowlist. Added
  after `.playwright-mcp/` page snapshots — containing live connection tokens —
  reached the 0.3.0 ZIP and were caught by eye while staging the SVN commit, not
  by any check. Always read `svn st` before committing; it is the last gate.
