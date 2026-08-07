# Soames WordPress plugin

Site configuration, Gutenberg blocks, the Knowledge Base (`docs`) post type, author
profile pictures, preview support, and WPGraphQL extensions for the
[Soames Astro theme](https://github.com/orbivision/soames-astro-theme).

The plugin itself is **plain PHP** — `soames-wordpress-plugin.php` plus `includes/`
and `assets/`. Everything else in this repo (`package.json`, `tests/`, `.wp-env.json`)
is development tooling added in ORBI-54 and **does not ship**.

**Requires [WPGraphQL](https://wordpress.org/plugins/wp-graphql/)** — declared with the
`Requires Plugins` header, so WordPress 6.5+ enforces it at activation.

**No companion theme needed** as of 1.0.0. Keep whatever theme you like; it will never
render, because the plugin redirects front-end requests before template loading (ORBI-58).

## Front-end redirection (ORBI-58)

`includes/frontend-redirect.php` sends visitors who land on WordPress over to the published
Soames site. It used to be the companion theme's `index.php`.

| Request | Goes to |
|---|---|
| Single post | `<frontend>/<posts-page-slug>/<post-path>` |
| Page, Knowledge Base article, any other single item | `<frontend><path>` |
| Archives, search, 404, front page | `<frontend>/` |

All 302, deliberately — the destination is a user-configurable setting, and a 301 would be
cached hard by browsers and CDNs, stranding visitors if the Frontend Site URL ever changes.
The blog base comes from WordPress's "Posts page" setting, mirroring `integration.ts` in the
Astro theme (including its `blog` fallback) so the two sides can't disagree.

**Moving this out of a theme removed a natural boundary.** As a theme it only ran once
WordPress rendered a template; from a plugin it runs on every front-end request. The bail-outs
are therefore the whole safety story, and each is asserted in `tests/e2e/redirect.spec.ts`:
wp-admin, AJAX, cron, REST, XML-RPC, JSON requests, **WPGraphQL**, previews, `robots.txt`,
feeds, trackbacks, sitemaps and embeds. GraphQL is the one that matters most — an install
whose endpoint redirects is a total build outage that presents as a broken site rather than a
broken WordPress.

Two off-switches: the **Front-end redirection** checkbox under Soames → Settings, and the
`soames_frontend_redirect_target` filter for anything the exclusions don't anticipate (return
`''` to leave a request alone).

## Versioning (ORBI-57)

The version of record is the **`Version:` header** in `soames-wordpress-plugin.php`. That's
what WordPress reads, so it's the one value a release can't forget. Nothing else in the repo
is authoritative: `bin/build-zip.sh` parses it out of the header, `SOAMES_PLUGIN_VERSION` is
derived from it at runtime via `get_file_data()`, `package.json`'s version is a courtesy
mirror for humans, and the release workflow **fails if the git tag doesn't match it**.

Releases are git tags `vX.Y.Z`; each one's GitHub Release carries the installable zip. See
[CHANGELOG.md](CHANGELOG.md).

### What the numbers mean

Blocks are a two-sided contract — this plugin emits `wp-block-soames-*` markup carrying
`data-*` payloads, and `soames-astro-theme` components consume it. So SemVer here is defined
against **that contract**, not against how much code moved:

| Bump | Means | Examples |
|---|---|---|
| **MAJOR** | Breaking change to what the theme parses. An older theme renders wrongly or not at all. | Renaming/removing a block's `data-*` attribute or wrapper class; changing the shape of a `data-items` JSON payload; renaming a settings key, REST key, or GraphQL field. |
| **MINOR** | New capability, backward compatible. An older theme keeps working, it just won't use the new thing. | A new block; a new setting; a new GraphQL field or REST key; a new optional attribute on an existing block. |
| **PATCH** | No contract change. | Admin bug fixes, editor UX, PHP notices, wp-admin styling. |

So an admin-only change is a PATCH however large, and a one-line rename of a `data-`
attribute is a MAJOR. The e2e tests are the practical test of which one you're making:
**if an assertion in `blocks.spec.ts` or `graphql.spec.ts` had to change, it isn't a PATCH.**

### Compatibility with `soames-astro-theme`

| Plugin | Astro theme (npm) | Notes |
|---|---|---|
| `1.1.0` | `>= 0.1.21` | New Soames Icon Header block (ORBI-63). MINOR, but the pairing is real in one direction: an older theme has no renderer for `wp-block-soames-icon-header`, so the block emits its div and leaves an empty gap. Everything else keeps working. |
| `1.0.1` | `>= 0.1.19` | Admin-only fix (Knowledge Base panel on the Menus screen). Pairs with any 0.1.x theme; 0.1.19 is listed because it's current. |
| `1.0.0` | `>= 0.1.18` | Companion WordPress theme folded in — no longer required (ORBI-58). Front-end unchanged, so no Astro theme bump. |
| `0.9.0` | `>= 0.1.18` | First versioned release. Earlier theme versions predate this version line and were only ever paired by date. |

Update this table in the same PR as any contract change. Nothing enforces it: a stale plugin
against a fresh theme renders empty divs rather than erroring, which is precisely why the
pairing has to be written down.

## Deploying

**Deploy the plugin whole.** The editor JS in `assets/` and the PHP in `includes/`
must ship together — a stale `blocks.php` silently breaks block rendering on the
front end even when the editor still looks perfectly correct.

```bash
npm run zip     # → build/soames-wordpress-plugin.zip
```

Use that rather than zipping the working directory or downloading the repo archive
from GitHub: both would sweep up `tests/`, `.wp-env.json`, and potentially a ~200MB
`node_modules`. The script copies an explicit allowlist and fails if a dev-only path
leaks in.

## Tests (ORBI-54)

End-to-end tests against a real WordPress in Docker via
[`@wordpress/env`](https://www.npmjs.com/package/@wordpress/env).

```bash
npm install
npx playwright install chromium   # once

npm run test:e2e         # start WordPress + seed + run everything
npm run test:e2e:only    # skip the WordPress boot (it's already running)
npm run lint:php         # php -l over every file, in a php:8.2-cli container

npm run env:start        # http://localhost:8977 (admin / password)
npm run env:destroy      # tear it all down
```

**Plugin order in `.wp-env.json` matters.** wp-env activates that array in sequence, and
since ORBI-57 the plugin declares `Requires Plugins: wp-graphql`, so WordPress 6.5+ refuses
to activate Soames while WPGraphQL is inactive — `wp-env start` dies with *"Soames requires
1 plugin to be installed and activated: WPGraphQL"* and then *"No plugins activated"*.
WPGraphQL is listed first for that reason; don't reorder it. (wp-env also rejects unknown
keys, so that note can't live in the JSON as a comment.)

### What these assert, and why

They test the plugin's **contract with the theme**, not how the admin looks. The
recorded failure mode here is a stale or refactored `render_callback` that silently
stops emitting the markup the theme parses: the editor looks right, the front end
renders nothing, and no screenshot test would notice.

| Spec | Covers |
|---|---|
| `blocks.spec.ts` | Every `soames/*` block emits its `wp-block-soames-*` wrapper with the expected `data-*` attributes; `data-items` payloads parse as JSON; comma-bearing labels survive; legacy comma-separated attributes still render |
| `graphql.spec.ts` | `heroTitle` / `heroCaption` / `overlayOpacity` / `heroBackgroundImage` are registered and round-trip; unset means `null` (not `""`); the author fragment; docs `menuOrder` + `parentDatabaseId`; the `docs` key and `Document`/`Documents` GraphQL names |
| `rest.spec.ts` | `soames/v1/settings` keeps the shape the theme's `getSoamesSettings()` destructures — unset values `null`, `showCompanyName` boolean; `soames/v1/preview` still registered |
| `avatar.spec.ts` | ORBI-53 profile pictures: local avatar overrides Gravatar, users without one fall through, `force_default` bypasses, `show_avatars=0` nulls the GraphQL avatar, and the picker round-trips through the real profile form |
| `nav-menus.spec.ts` | ORBI-60: the Knowledge Base panel is present on Appearance → Menus and lists articles; an article can be added to a menu and survives a save; and the un-hide happens **once** rather than being forced on every load, so a user who deliberately hides the panel keeps it hidden |
| `redirect.spec.ts` | ORBI-58 front-end redirection: the post/page/docs mapping, the blog base following the Posts page slug, and every exclusion — **GraphQL**, REST, wp-admin, previews, `robots.txt` — plus both off-switches. Sets a frontend URL in `beforeAll` and clears it in `afterAll`, since the rest of the suite fetches rendered HTML from WordPress directly and would otherwise get 302s |
| `admin.spec.ts` | Soames admin pages load without PHP notices; the Knowledge Base submenu stays nested and ordered; **the all-blocks post opens in the editor with no block-validation warnings** (the Block API v3 iframe regression class — ORBI-49) |

### Fixtures

`tests/fixtures/seed.php` creates everything the specs assert against: an author with
a local avatar (and a second author without one), a post containing every Soames block
plus legacy variants, a page with all hero fields set (and a bare one), and a docs tree
with deliberate `menu_order`. Playwright's `globalSetup` runs it before every run and
writes the resulting IDs to `tests/e2e/.fixtures.json`, so no spec hard-codes an ID.

Two things to know if you extend it:

- **It purges and recreates** anything whose slug starts with `soames-e2e-`. Upserting
  can't be made reliable: when a slug collides WordPress appends `-2`, `-3`, … so a
  duplicate is no longer findable by the slug the seeder knows.
- **Pretty permalinks need `got_rewrite` forced.** WPGraphQL's `uri` (how the theme
  routes everything) comes from the permalink structure, but from WP-CLI
  `save_mod_rewrite_rules()` checks `got_mod_rewrite()` → `apache_get_modules()`, which
  doesn't exist in the CLI SAPI. So it writes a `.htaccess` containing the WordPress
  markers and **no rules**, Apache 404s every pretty URL, and `wp option get
  permalink_structure` still reports the correct value. The seed forces the filter,
  calls `$wp_rewrite->init()` *after* `update_option()`, and flushes hard.
- **Serialize dynamic blocks self-closing**, e.g. `<!-- wp:soames/feature {…} /-->`.
  Every Soames block is `save: null`, so the editor writes no inner HTML. Giving one
  inner content makes the editor flag "unexpected or invalid content" — and for the
  Feature block, whose body lives in a `content` *attribute*, the front end renders an
  empty body. Both were caught by these tests on their first run.

**Seed fidelity is the main limitation.** If the fixture drifts from what the block
editor actually produces, the contract tests can pass while production breaks. If that
starts to bite, generate the seed from real exported post content.
