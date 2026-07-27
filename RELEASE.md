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

## Blockers before publishing

### 1. Banner and icon — needs a design decision

`.wordpress-org/` has screenshots only, so the plugin page and search results
render a generated placeholder next to five competitors with real artwork. This
is the single highest-leverage cosmetic fix on install rate.

Required files, per the [asset guidelines](https://developer.wordpress.org/plugins/wordpress-org/plugin-assets/):

| File | Size | Notes |
| --- | --- | --- |
| `banner-772x250.png` | 772 × 250 | required; max 4 MB |
| `banner-1544x500.png` | 1544 × 500 | retina |
| `icon-256x256.png` | 256 × 256 | retina; max 1 MB |
| `icon-128x128.png` | 128 × 128 | standard |

`icon.svg` may replace both PNG icons. All of these go in the **SVN `assets/`
directory**, never in `trunk/` — they are not part of the plugin.

### 2. Refresh screenshot 1

The settings screen gained the agent picker, the rate-limit field, and the
connected-agents table. `screenshot-1.png` predates all three, and its caption in
`readme.txt` has already been updated to describe the new UI — so the image and
the caption currently disagree.

Capture at 1200 px wide against the local install, logged in as an admin, at
`/site/wp-admin/options-general.php?page=flavoursuite-ai`.

### 3. Confirm the Codex CLI recipe

`ClientProfiles.php` uses an `[mcp_servers.flavoursuite.http_headers]` table for
Codex. That key is the one entry in the registry not verified against a running
client. Either confirm it against current Codex docs, or drop the profile and let
Codex users take the stdio-bridge recipe, which is known to work.

Everything else in the registry is either a format I verified parses, or
(for the cloud connectors) just the endpoint URL.

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

- **Run `composer dump-autoload` after adding any class under `includes/`.**
  The Jetpack Autoloader classmap is static; a new file that is not in it throws
  a fatal on boot. This bites the symlinked dev tree only — release ZIPs
  regenerate the map during the build.
- The dev tree is symlinked into `/var/www/html/site/wp-content/plugins/`, so
  edits are live immediately. Apache and MariaDB must be running.
- `wp eval` with `wp_set_current_user()` renders admin screens headlessly, which
  is much faster than driving a browser for smoke tests.
- WordPress auth cookies are pipe-delimited (`user|expiry|token|hmac`) — never
  split shell output on `|` when handling them.
- Run Plugin Check against the **built ZIP**, not the working tree, or it flags
  `dist/`, `bin/` and the dotfiles that `.distignore` already strips.
