import { test, expect } from "@playwright/test";
import { login, wpEval, WP_BASE } from "./wp";

// ORBI-60 — the Knowledge Base panel on Appearance → Menus.
//
// WordPress hides every custom post type's panel the first time a user opens the Menus
// screen: wp_initial_nav_menu_meta_boxes() keeps a hardcoded four (page, post, custom links,
// category) and writes everything else into the user's metaboxhidden_nav-menus meta. There's
// no filter on that list, so `docs` disappears despite show_in_nav_menus => true, and it reads
// to the user as "I can't add a Knowledge Base article to a menu any more".
//
// The plugin corrects it ONCE per user. These tests cover both halves of that: the correction
// happens, and it doesn't keep happening — a plugin that re-ticked the box on every page load
// would make it impossible to switch off.
//
// The suite is workers: 1 (ORBI-57), which these rely on: they write user meta directly.

const PANEL = "#add-post-type-docs";
const CHECKBOX = "#add-post-type-docs-hide";

/** Put the user back in the state core leaves them in on a first visit. */
function forceCoreHiddenState(): void {
  wpEval(
    `$u = get_user_by('login','admin')->ID;` +
      `update_user_option($u,'metaboxhidden_nav-menus',array('add-post-type-docs'));` +
      `delete_user_option($u,'soames_navmenu_docs_unhidden');`
  );
}

function hiddenMeta(): string {
  return wpEval(
    `$u = get_user_by('login','admin')->ID;` +
      `echo wp_json_encode(get_user_option('metaboxhidden_nav-menus',$u));`
  ).trim();
}

test("the Knowledge Base panel is available on the Menus screen", async ({ page }) => {
  forceCoreHiddenState();
  await login(page);
  await page.goto(`${WP_BASE}/wp-admin/nav-menus.php`);

  // Present in the DOM and offered in Screen Options — the two things core took away.
  await expect(page.locator(PANEL)).toHaveCount(1);
  await expect(page.locator(CHECKBOX)).toBeChecked();

  // And it actually lists articles to add. The panel renders collapsed (an accordion
  // section), so assert on the DOM rather than visibility — expanding it is the user's click.
  const items = await page.locator(`${PANEL} .tabs-panel input[type=checkbox]`).count();
  expect(items, "Knowledge Base panel lists no articles to add").toBeGreaterThan(0);
});

test("a Knowledge Base article can be added to a menu and saved", async ({ page }) => {
  const menuId = Number(
    wpEval(
      `$m = wp_create_nav_menu('soames-e2e-nav-' . wp_rand()); echo is_wp_error($m) ? 0 : $m;`
    ).trim()
  );
  expect(menuId).toBeGreaterThan(0);

  try {
    forceCoreHiddenState();
    await login(page);
    await page.goto(`${WP_BASE}/wp-admin/nav-menus.php?action=edit&menu=${menuId}`);

    await page.locator(`${PANEL} .accordion-section-title`).click();
    await page.locator(`${PANEL} .tabs-panel-active input[type=checkbox]`).first().check();
    await page.locator(`${PANEL} .submit-add-to-menu`).click();
    await page.waitForSelector("#menu-to-edit li.menu-item");

    // Submit the form rather than clicking "Save Menu": the header button is off-screen and
    // the footer one doesn't reliably submit under headless Chrome. Same request either way.
    await Promise.all([
      page.waitForNavigation({ waitUntil: "load" }),
      page.evaluate(() => (document.getElementById("update-nav-menu") as HTMLFormElement).submit()),
    ]);

    // The database is the assertion, not the screen.
    const saved = wpEval(
      `$items = wp_get_nav_menu_items(${menuId});` +
        `echo wp_json_encode(array_values(array_map(fn($i) => $i->object, $items ?: [])));`
    ).trim();
    expect(saved, `menu ${menuId} should contain a docs item`).toContain('"docs"');
  } finally {
    wpEval(`wp_delete_nav_menu(${menuId});`);
  }
});

test("the panel is un-hidden once, not forced on every load", async ({ page }) => {
  forceCoreHiddenState();
  await login(page);

  // First visit performs the correction.
  await page.goto(`${WP_BASE}/wp-admin/nav-menus.php`);
  expect(hiddenMeta()).not.toContain("add-post-type-docs");

  // The user then deliberately hides it again via Screen Options.
  wpEval(
    `$u = get_user_by('login','admin')->ID;` +
      `update_user_option($u,'metaboxhidden_nav-menus',array('add-post-type-docs'));`
  );

  // That choice must survive: we already recorded the one-time correction for this user.
  await page.goto(`${WP_BASE}/wp-admin/nav-menus.php`);
  expect(
    hiddenMeta(),
    "the plugin re-ticked a box the user had deliberately hidden"
  ).toContain("add-post-type-docs");

  // Leave the account usable rather than hidden, since this is a shared fixture user.
  wpEval(
    `$u = get_user_by('login','admin')->ID;` +
      `update_user_option($u,'metaboxhidden_nav-menus',array());`
  );
});
