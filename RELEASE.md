# Release plan — 0.3.0

**Status:** code complete and verified against a live WordPress 7.0.2 install.
Committed to git (`1ebdade`), **not yet published to WordPress.org.**

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
- `screenshot-3.png` — the connect section with the agent picker on Cursor,
  showing the file hint and the generated JSON.

`screenshot-2.png` (approvals diff) is unchanged and still accurate, though it is
1535 × 730 and so does not match the other two. Worth reshooting for consistency
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
  is much faster than driving a browser for smoke tests.
- WordPress auth cookies are pipe-delimited (`user|expiry|token|hmac`) — never
  split shell output on `|` when handling them.
- Run Plugin Check against the **built ZIP**, not the working tree, or it flags
  `dist/`, `bin/` and the dotfiles that `.distignore` already strips.
