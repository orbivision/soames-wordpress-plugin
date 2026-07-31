import { test, expect } from "@playwright/test";
import { fixtures, graphql, login, wpCli, wpEval, WP_BASE } from "./wp";

// ORBI-53 — local profile pictures. WordPress core has no avatar upload, so the plugin
// adds a media picker to the user profile and overrides Gravatar via
// pre_get_avatar_data. These are the ad-hoc checks from that project, kept.

test("a local avatar overrides Gravatar", async () => {
  const f = fixtures();
  const url = wpEval(`echo get_avatar_url(${f.authorId});`);
  expect(url).toMatch(/\/wp-content\/uploads\/.+\.png$/);
  expect(url).not.toContain("gravatar.com");
});

test("a user without a local avatar still falls through to Gravatar", async () => {
  const f = fixtures();
  const url = wpEval(`echo get_avatar_url(${f.plainId});`);
  expect(url).toContain("gravatar.com");
});

test("force_default bypasses the local avatar", async () => {
  const f = fixtures();
  const url = wpEval(
    `echo get_avatar_url(${f.authorId}, array('force_default' => true));`
  );
  expect(url).toContain("gravatar.com");
});

test("get_avatar() HTML uses the local image", async () => {
  const f = fixtures();
  const html = wpEval(`echo get_avatar(${f.authorId}, 56);`);
  expect(html).toContain("/wp-content/uploads/");
  expect(html).toMatch(/class='[^']*avatar-56/);
});

test("the theme's GraphQL avatar field serves the local image", async ({ request }) => {
  const f = fixtures();
  const data = await graphql<any>(
    request,
    `{ user(id: ${f.authorId}, idType: DATABASE_ID) { name avatar { url } } }`
  );
  expect(data.user.avatar.url).toMatch(/\/wp-content\/uploads\/.+\.png$/);
});

test("show_avatars=0 makes WPGraphQL return a null avatar", async ({ request }) => {
  // The silent kill switch: WPGraphQL's Avatar model reports the avatar private when
  // this option is off, so `avatar { url }` resolves to null and the theme's byline
  // image disappears even with a picture set. get_avatar_data() itself ignores the
  // option — only WPGraphQL gates on it. This is why the profile field warns.
  const f = fixtures();
  wpCli(["option", "update", "show_avatars", "0"]);
  try {
    const off = await graphql<any>(
      request,
      `{ user(id: ${f.authorId}, idType: DATABASE_ID) { avatar { url } } }`
    );
    expect(off.user.avatar).toBeNull();
  } finally {
    wpCli(["option", "update", "show_avatars", "1"]);
  }

  const on = await graphql<any>(
    request,
    `{ user(id: ${f.authorId}, idType: DATABASE_ID) { avatar { url } } }`
  );
  expect(on.user.avatar.url).toBeTruthy();
});

test("the profile field renders with the media picker wired up", async ({ page }) => {
  const f = fixtures();
  await login(page);
  await page.goto(`${WP_BASE}/wp-admin/user-edit.php?user_id=${f.authorId}`);

  await expect(page.getByRole("heading", { name: "Soames Profile Picture" })).toBeVisible();
  await expect(page.locator("#soames_user_avatar_id")).toHaveCount(1);
  await expect(page.locator("#soames_user_avatar_nonce")).toHaveCount(1);
  // Reuses the Site Assets picker, so the button must keep the data-target contract
  // that assets/admin.js binds to.
  await expect(
    page.locator('.soames-media-upload[data-target="soames_user_avatar"]')
  ).toBeVisible();
  // wp.media must actually be available on this screen, or the picker is inert.
  expect(await page.evaluate(() => typeof (window as any).wp?.media)).toBe("function");
});

test("the picker round-trips through the real profile form", async ({ page }) => {
  const f = fixtures();
  await login(page);
  const url = `${WP_BASE}/wp-admin/user-edit.php?user_id=${f.authorId}`;

  // Clear via the Remove button, then save the real form.
  await page.goto(url);
  await page.locator('.soames-media-clear[data-target="soames_user_avatar"]').click();
  await expect(page.locator("#soames_user_avatar_id")).toHaveValue("");
  await page.locator("#submit").click();
  await page.waitForURL(/user-edit\.php/);

  expect(wpEval(`echo get_avatar_url(${f.authorId});`)).toContain("gravatar.com");

  // Set it back by writing the attachment ID the way the media modal does.
  await page.goto(url);
  await page.locator("#soames_user_avatar_id").evaluate((el, id) => {
    (el as HTMLInputElement).value = String(id);
  }, f.avatarId);
  await page.locator("#submit").click();
  await page.waitForURL(/user-edit\.php/);

  const restored = wpEval(`echo get_avatar_url(${f.authorId});`);
  expect(restored).toMatch(/\/wp-content\/uploads\/.+\.png$/);
});

test("the stored URL is a sized rendition, not the full-size original", async () => {
  // Deliberate: one URL is stored (multisite usermeta is global while the media
  // library is per-site), and it's the 150x150 thumbnail so a multi-megabyte
  // original never ends up in a 56px byline slot.
  const f = fixtures();
  const stored = wpEval(`echo get_user_meta(${f.authorId}, 'soames_avatar_url', true);`);
  expect(stored).toMatch(/-150x150\.png$/);
});
