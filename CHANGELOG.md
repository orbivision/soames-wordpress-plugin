# Changelog

All notable changes to the Soames WordPress plugin are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project follows
[Semantic Versioning](https://semver.org/spec/v2.0.0.html) as defined in the README.

The version of record is the `Version:` header in `soames-wordpress-plugin.php`. Each release
is a git tag `vX.Y.Z` whose GitHub Release carries the installable zip.

## [Unreleased]

## [0.9.0] — 2026-08-01

**First versioned release.** Everything below shipped continuously to a single production
install before this plugin had a version line at all — the header read `1.0.0` throughout,
across roughly twenty merged projects, so it told you nothing. This release restarts the line
honestly at `0.9.0` and reserves `1.0.0` for the first public download (ORBI-57).

Entries are grouped by area and tagged with the ORBI project that introduced them; they are
not in release order, because there were no releases.

### Added

- **Gutenberg blocks** — the `soames/*` block family, all dynamic (`save: null` +
  `render_callback`), emitting `wp-block-soames-*` markup the Astro theme maps to components
  (ORBI-17). Migrated to Block API v3 (ORBI-28).
  - Icon List: grouped editor UI with a Media Library picker (ORBI-20), size setting
    small/medium/large (ORBI-49).
  - Gallery Menu: shared grouped editor (ORBI-20), compact view setting (ORBI-44).
  - Text List: refactored into a dynamic item repeater (ORBI-42).
  - Feature: Media Library image picker and an HTML body textarea (ORBI-43).
  - Hero Header: dedicated title and caption settings, with HTML allowed in the title
    (ORBI-52).
- **Knowledge Base (`docs`) custom post type**, self-registered in the plugin after weDocs was
  evaluated and dropped for breaking the block editor (ORBI-27). Rebranded in the admin as
  "Knowledge Base"/"Article" and nested under the Soames menu, with the post-type key, slugs,
  and GraphQL fields unchanged (ORBI-36, ORBI-37). Drag-to-reorder in wp-admin (ORBI-38).
- **Site Assets settings** — logo, favicon, contact blurb, company name and a show-in-header
  toggle, exposed over a REST endpoint the theme reads (ORBI-12).
- **Documentation page setting** driving the `/docs/` hero (ORBI-31).
- **Dedicated hero background image** per page, with single posts and docs inheriting their
  parent's hero (ORBI-41).
- **Author profile pictures** — a local avatar picker overriding Gravatar via
  `pre_get_avatar_data`, which also covers WPGraphQL's `avatar { url }` (ORBI-53).
- **Automatic rebuilds** — POST to a user-configured build hook URL on content publish
  (ORBI-32).
- **Header and footer nav menu locations**, moved here from the companion theme (ORBI-14).
- **Custom admin menu icon** — monochrome Soames mark (ORBI-46).
- **End-to-end test suite** — the repo's first CI. Contract tests over block markup, WPGraphQL
  fields, the settings REST shape, avatar resolution, and admin pages, against a real
  WordPress in Docker via `@wordpress/env` (ORBI-54).
- **`bin/build-zip.sh`** — allowlist-based installable zip, with a guard that fails if
  dev-only paths leak in (ORBI-54). Now also ships the `LICENSE` (ORBI-57).
- **`LICENSE`** (GPL-2.0-or-later) — the plugin had been distributed without one (ORBI-57).

### Changed

- Plugin header completed for distribution: `Requires at least`, `Tested up to`,
  `Requires Plugins: wp-graphql`, `License`, `License URI`, `Text Domain`, `Author URI`
  (ORBI-57).
- Asset cache-busting now derives from the plugin version via `soames_asset_version()`
  instead of three hardcoded `'1.0.0'` literals and one `filemtime()` call. mtime is still
  used under `WP_DEBUG` for local iteration, but never in a released install, where it would
  differ per install and change on every redeploy (ORBI-57).
- All Soames functionality migrated out of the companion WordPress theme's `functions.php`
  into this plugin, leaving the theme with theme-support declarations and its redirect
  (ORBI-12).

### Fixed

- `add_post_type_support( 'page', 'excerpt' )` moved out of the `init` hook so WPGraphQL picks
  it up regardless of hook order.
- Block category registration corrected and `useBlockProps` removed (ORBI-17).
- Icon List reorder/remove controls made usable in the Block API v3 iframed editor, which
  doesn't load Dashicons into the canvas and rendered icon-only buttons invisible (ORBI-49).
- Feature block content wrapper is a `<div>`, not a `<p>` — the block inlines HTML, which is
  invalid inside a paragraph (ORBI-43).
- Soames admin submenu order (ORBI-37).

[Unreleased]: https://github.com/orbivision/soames-wordpress-plugin/compare/v0.9.0...HEAD
[0.9.0]: https://github.com/orbivision/soames-wordpress-plugin/releases/tag/v0.9.0
