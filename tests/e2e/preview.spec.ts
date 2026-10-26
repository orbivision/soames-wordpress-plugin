import { test, expect } from "@playwright/test";
import { login, wpCli, WP_BASE } from "./wp";

// ORBI-85 — preview tokens (includes/preview.php).
//
// `?p=<id>&preview=true` mints a token and sends the browser to the front end's /preview/,
// which reads the draft back from the public REST route with that token. The token is the
// only credential, so it must only ever be minted for someone who can edit the post. Before
// ORBI-85 an anonymous request for any draft ID got one — and with it the draft's content.
//
// Mutates site-wide options; relies on the suite's workers: 1.

const FRONTEND = "https://frontend.example";
const SUB_USER = "e2e-preview-subscriber";
const SUB_PASS = "e2e-preview-subscriber-pass";
const SECRET_BODY = "ORBI-85 unpublished draft body";

let draftId: number;
let draftPageId: number;

test.beforeAll(() => {
  wpCli(["option", "update", "soames_frontend_url", FRONTEND]);
  draftId = Number(wpCli(["post", "create", "--post_status=draft", "--post_title=ORBI-85 draft",
    `--post_content=<p>${SECRET_BODY}</p>`, "--porcelain"]));
  draftPageId = Number(wpCli(["post", "create", "--post_type=page", "--post_status=draft",
    "--post_title=ORBI-85 draft page", "--porcelain"]));
  wpCli(["user", "create", SUB_USER, `${SUB_USER}@example.test`, "--role=subscriber",
    `--user_pass=${SUB_PASS}`]);
});

test.afterAll(() => {
  wpCli(["post", "delete", String(draftId), String(draftPageId), "--force"]);
  wpCli(["user", "delete", SUB_USER, "--yes"]);
  wpCli(["option", "delete", "soames_frontend_url"]);
});

const previewUrl = (q: string) => `${WP_BASE}/?${q}&preview=true`;
const tokenOf = (location: string) => new URL(location).searchParams.get("token");

test("an anonymous request for a draft is sent to log in, and gets no token", async ({ request }) => {
  const res = await request.get(previewUrl(`p=${draftId}`), { maxRedirects: 0 });
  expect(res.status()).toBe(302);
  const location = res.headers()["location"];
  expect(location).toContain("/wp-login.php");
  expect(location).not.toContain(FRONTEND);
});

test("a logged-in user who cannot edit the draft is refused", async ({ page }) => {
  await page.goto(`${WP_BASE}/wp-login.php`);
  await page.locator("#user_login").fill(SUB_USER);
  await page.locator("#user_pass").fill(SUB_PASS);
  await page.locator("#wp-submit").click();
  await page.waitForLoadState("networkidle");

  const res = await page.request.get(previewUrl(`p=${draftId}`), { maxRedirects: 0 });
  expect(res.status()).toBe(403);
});

test("an editor gets a token, and the token reads the draft back", async ({ page }) => {
  await login(page);

  const res = await page.request.get(previewUrl(`p=${draftId}`), { maxRedirects: 0 });
  expect(res.status()).toBe(302);
  const location = res.headers()["location"];
  expect(location.startsWith(`${FRONTEND}/preview/?token=`)).toBe(true);

  const data = await (await page.request.get(
    `${WP_BASE}/?rest_route=/soames/v1/preview&token=${tokenOf(location)}`
  )).json();
  expect(data.type).toBe("post");
  expect(data.title).toBe("ORBI-85 draft");
  expect(data.content).toContain(SECRET_BODY);
});

test("an editor previewing a draft page gets a page token", async ({ page }) => {
  await login(page);

  const res = await page.request.get(previewUrl(`page_id=${draftPageId}`), { maxRedirects: 0 });
  expect(res.status()).toBe(302);
  const data = await (await page.request.get(
    `${WP_BASE}/?rest_route=/soames/v1/preview&token=${tokenOf(res.headers()["location"])}`
  )).json();
  expect(data.type).toBe("page");
});

test("an invalid token is refused without content", async ({ request }) => {
  const res = await request.get(`${WP_BASE}/?rest_route=/soames/v1/preview&token=not-a-token`);
  expect(res.status()).toBe(403);
  expect((await res.json()).code).toBe("expired");
});
