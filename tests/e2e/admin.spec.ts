import { test, expect } from "@playwright/test";
import { fixtures, login, WP_BASE } from "./wp";

// Admin smoke tests. The valuable one is the editor check: Block API v3 renders the
// canvas in an IFRAME, and things that work fine outside it (Dashicons in icon-only
// buttons — ORBI-49) silently don't. A block that fails validation shows a warning in
// the editor while the front end still renders, so only the editor can catch it.

test("Soames admin pages load without PHP errors", async ({ page }) => {
  await login(page);

  for (const slug of ["soames-settings", "soames-site-assets"]) {
    const errors: string[] = [];
    page.on("pageerror", (e) => errors.push(e.message));

    await page.goto(`${WP_BASE}/wp-admin/admin.php?page=${slug}`);
    await expect(page.locator("#wpbody-content .wrap h1").first()).toBeVisible();

    // WP_DEBUG is on in .wp-env.json with display off, so notices land in the log
    // rather than the page — but a fatal or a stray notice that does print would
    // show up in the body text.
    const body = await page.locator("#wpbody-content").innerText();
    expect(body, `PHP notice on ${slug}`).not.toMatch(
      /(Fatal error|Parse error|Warning:|Notice:|Deprecated:)/
    );
    expect(errors, `JS errors on ${slug}`).toEqual([]);
  }
});

test("the Knowledge Base menu is nested under Soames", async ({ page }) => {
  // ORBI-36/49: the docs CPT submenu is injected by core before the plugin's
  // admin_menu callback, so it's re-sorted at a late priority. Pin the result.
  await login(page);
  await page.goto(`${WP_BASE}/wp-admin/`);

  const items = await page
    .locator("#toplevel_page_soames-settings .wp-submenu li a")
    .evaluateAll((els) => els.map((el) => (el.textContent ?? "").trim()));

  expect(items.length).toBeGreaterThanOrEqual(3);
  expect(items).toContain("Settings");
  expect(items).toContain("Site Assets");
  // Rebranded label; the post-type key and slug deliberately stay `docs`.
  expect(items.some((t) => /knowledge base/i.test(t))).toBe(true);
  // Deliberate, non-alphabetical order.
  expect(items.indexOf("Settings")).toBeLessThan(items.indexOf("Site Assets"));
});

test("the all-blocks post opens in the editor with no block validation warnings", async ({
  page,
}) => {
  const f = fixtures();
  await login(page);

  const jsErrors: string[] = [];
  page.on("pageerror", (e) => jsErrors.push(e.message));

  await page.goto(`${WP_BASE}/wp-admin/post.php?post=${f.blocksPostId}&action=edit`);

  // Dismiss the welcome modal if it appears, or it swallows clicks.
  const modalClose = page.getByRole("button", { name: /close dialog|close/i }).first();
  if (await modalClose.isVisible().catch(() => false)) {
    await modalClose.click().catch(() => {});
  }

  // The canvas is an iframe under API v3 — blocks live inside it.
  const canvas = page.frameLocator('iframe[name="editor-canvas"]');
  const blocks = canvas.locator('[data-type^="soames/"]');
  await expect(blocks.first()).toBeVisible({ timeout: 30_000 });

  // 11 seeded block instances (9 types + 2 legacy variants).
  expect(await blocks.count()).toBe(11);

  // The actual assertion: nothing failed to validate or crashed while rendering.
  await expect(canvas.locator(".block-editor-warning")).toHaveCount(0);
  await expect(canvas.getByText(/unexpected or invalid content/i)).toHaveCount(0);
  expect(jsErrors, `editor JS errors: ${jsErrors.join(" | ")}`).toEqual([]);
});

test("Soames blocks are available in the inserter under their own category", async ({
  page,
}) => {
  await login(page);
  await page.goto(`${WP_BASE}/wp-admin/post-new.php`);

  const modalClose = page.getByRole("button", { name: /close dialog|close/i }).first();
  if (await modalClose.isVisible().catch(() => false)) {
    await modalClose.click().catch(() => {});
  }

  // Ask the block registry directly rather than driving the inserter UI, which is
  // Gutenberg-version-sensitive and would make this test brittle for no gain.
  const registered = await page.evaluate(() =>
    (window as any).wp.blocks
      .getBlockTypes()
      .filter((b: any) => b.name.startsWith("soames/"))
      .map((b: any) => ({ name: b.name, category: b.category }))
  );

  expect(registered.length).toBe(9);
  for (const b of registered) {
    expect(b.category, `${b.name} category`).toBe("soames");
  }
});
