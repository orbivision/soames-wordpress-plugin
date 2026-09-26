import { test, expect } from "@playwright/test";
import { wpCli, wpEval, login, WP_BASE } from "./wp";

// ORBI-82. soames/v1/settings carries `optimaExpress` — the Kestrel config and the virtual-
// page URL patterns a static site needs to build IDX shells — and only when Optima Express
// is active AND registered on the site. It's network-activated on the production multisite,
// so "active" alone is true everywhere; the registration half is what keeps the payload off
// the subsites that don't use it.
//
// Optima Express is installed here from wordpress.org (the public release — never anything
// else) and removed afterwards, so no other spec runs with it active.
//
// WHAT THIS CAN AND CANNOT PROVE: there is no real activation key in CI, so "registered" is
// simulated with a dummy ihf_authentication_token. That exercises the real gate —
// isActivated() only checks the token is non-empty — but no Kestrel permission can be
// fetched with a dummy token, so `mode` is never 'kestrel' here. A green run proves the gate,
// the payload shape and the rewrite translation, never that Kestrel renders.

const OE_VERSION = "8.7.7";
const SETTINGS_URL = `${WP_BASE}/wp-json/soames/v1/settings`;
const LISTING_DETAIL = "/homes-for-sale-details/:listingAddress/:listingNumber/:boardId";

test.describe.configure({ mode: "serial" });

function register(on: boolean): void {
  wpEval(on
    ? `update_option('ihf_authentication_token', 'e2e-dummy-auth'); update_option('ihf_activation_token', 'e2e-dummy-activation');`
    : `delete_option('ihf_authentication_token'); delete_option('ihf_activation_token');`);
}

test.beforeAll(() => {
  wpCli(["plugin", "install", "optima-express", `--version=${OE_VERSION}`, "--activate", "--force"]);
  // Optima Express adds its rules on init; the stored rewrite table only has them after a flush.
  wpCli(["rewrite", "flush"]);
});

test.afterAll(() => {
  register(false);
  wpCli(["plugin", "uninstall", "optima-express", "--deactivate"]);
  wpCli(["rewrite", "flush"]);
});

test("active but not registered: optimaExpress is null", async ({ request }) => {
  register(false);
  const body = await (await request.get(SETTINGS_URL)).json();
  expect(body).toHaveProperty("optimaExpress");
  expect(body.optimaExpress).toBeNull();
});

test("active and registered: payload carries the Kestrel config and the routes", async ({ request }) => {
  register(true);
  try {
    const oe = (await (await request.get(SETTINGS_URL)).json()).optimaExpress;
    expect(oe).not.toBeNull();

    expect(["kestrel", "kestrel-detail", "legacy", "unknown"]).toContain(oe.mode);
    expect(oe.kestrel).toEqual({ activationToken: "e2e-dummy-activation", platform: "wordpress" });
    // The authentication token is a server-side secret and must never be in the payload.
    expect(JSON.stringify(oe)).not.toContain("e2e-dummy-auth");

    expect(typeof oe.skippedRules).toBe("number");
    expect(oe.routes.length, "no virtual-page routes translated").toBeGreaterThan(20);
    for (const r of oe.routes) {
      expect(r.path, `${r.type} path`).toMatch(/^(\/([A-Za-z0-9_-]+|:[A-Za-z][A-Za-z0-9_]*))+$/);
      expect(r.type).not.toBe("");
    }
    // The route everything else in ORBI-82 hangs off, with its query-var names intact.
    expect(oe.routes.map((r: { path: string }) => r.path)).toContain(LISTING_DETAIL);
  } finally {
    register(false);
  }
});

test("an admin's customised slug flows through, because routes come from the rewrite table", async ({ request }) => {
  register(true);
  wpEval(`update_option('ihf-virtual-page-permalink-text-detail', 'e2e-listing');`);
  wpCli(["rewrite", "flush"]);
  try {
    const paths = (await (await request.get(SETTINGS_URL)).json()).optimaExpress.routes.map((r: { path: string }) => r.path);
    expect(paths).toContain("/e2e-listing/:listingAddress/:listingNumber/:boardId");
    expect(paths).not.toContain(LISTING_DETAIL);
  } finally {
    wpEval(`delete_option('ihf-virtual-page-permalink-text-detail');`);
    wpCli(["rewrite", "flush"]);
    register(false);
  }
});

test("rules outside the segment grammar are skipped, not mistranslated", () => {
  const out = wpEval(`echo wp_json_encode([
    soames_oe_translate_rule('^a/([^/]+)$', 'index.php?ihf-type=t&x=$matches[1]'),
    soames_oe_translate_rule('^a/([0-9]+)$', 'index.php?ihf-type=t&x=$matches[1]'),
    soames_oe_translate_rule('^a.b/([^/]+)$', 'index.php?ihf-type=t&x=$matches[1]'),
    soames_oe_translate_rule('^a/([^/]+)$', 'index.php?ihf-type=t'),
    soames_oe_translate_rule('^a$', 'index.php?pagename=x'),
  ]);`);
  expect(JSON.parse(out)).toEqual([{ type: "t", path: "/a/:x" }, null, null, null, null]);
});

test("registered but not in Kestrel mode warns the admin", async ({ page }) => {
  register(true);
  try {
    // A dummy token can't fetch Kestrel permissions, so the mode here is not 'kestrel'.
    expect(wpEval(`echo soames_oe_mode();`)).not.toBe("kestrel");
    await login(page);
    await page.goto(`${WP_BASE}/wp-admin/`);
    await expect(page.locator(".notice-warning", { hasText: "not in Kestrel mode" })).toBeVisible();
  } finally {
    register(false);
  }
});

// Front-end redirection (ORBI-58) on a virtual page. WordPress resolves these to Optima
// Express's placeholder post, whose permalink is meaningless — before this fix a listing URL
// redirected to <frontend>/listingaddress/ (seen live on orbivision.net, 2026-09-24).
test.describe("front-end redirect of a virtual page", () => {
  const FRONTEND = "https://frontend.example";
  const LISTING = "/homes-for-sale-details/3917-CANYON-GLEN-CIRCLE-AUSTIN-TX-78732/1449859/27/";

  test.beforeAll(() => {
    wpCli(["option", "update", "soames_frontend_url", FRONTEND]);
    wpCli(["option", "update", "soames_frontend_redirect", "1"]);
  });
  test.afterAll(() => {
    wpCli(["option", "delete", "soames_frontend_url"]);
    wpCli(["option", "delete", "soames_frontend_redirect"]);
  });

  test("registered: keeps the request's own path and query", async ({ request }) => {
    register(true);
    try {
      const res = await request.get(`${WP_BASE}${LISTING}?boardId=27`, { maxRedirects: 0 });
      expect(res.status()).toBe(302);
      expect(res.headers()["location"]).toBe(`${FRONTEND}${LISTING}?boardId=27`);
    } finally {
      register(false);
    }
  });

  test("not registered: goes to the front-end home page, not a placeholder slug", async ({ request }) => {
    register(false);
    const res = await request.get(`${WP_BASE}${LISTING}`, { maxRedirects: 0 });
    expect(res.status()).toBe(302);
    expect(res.headers()["location"]).toBe(`${FRONTEND}/`);
  });
});
