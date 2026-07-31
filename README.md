# Soames WordPress plugin

Site configuration, Gutenberg blocks, the Knowledge Base (`docs`) post type, author
profile pictures, preview support, and WPGraphQL extensions for the
[Soames Astro theme](https://github.com/orbivision/soames-astro-theme).

The plugin itself is **plain PHP** — `soames-wordpress-plugin.php` plus `includes/`
and `assets/`. Everything else in this repo (`package.json`, `tests/`, `.wp-env.json`)
is development tooling added in ORBI-54 and **does not ship**.

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
