import { test, expect } from "@playwright/test";
import { fixtures, wpCli, wpEval, WP_BASE } from "./wp";

// ORBI-82 — the edge bypass (includes/edge.php) and the opt-in 301 for front-end redirection.
//
// A static front end's edge fetches WordPress pages server-side to build their heads. With a
// Frontend Site URL set, every one of those pages redirects to the front end, so the edge needs a
// way through — and that way through must not become a way for anyone else to see the WordPress
// copy. These assert the whole contract: the right secret gets the page; no secret, a wrong one,
// or a header with no secret configured all get the ordinary redirect; the secret is in no public
// payload.
//
// Mutates site-wide options; relies on the suite's workers: 1.

const FRONTEND = "https://frontend.example";
const SECRET = "e2e-edge-secret-0123456789abcdefghijklmnop"; // ≥ 32 chars

async function get(request: any, url: string, edge?: string) {
  return request.get(url, {
    maxRedirects: 0,
    headers: edge === undefined ? {} : { "X-Soames-Edge": edge },
  });
}

test.beforeAll(() => {
  wpCli(["option", "update", "soames_frontend_url", FRONTEND]);
  wpCli(["option", "update", "soames_frontend_redirect", "1"]);
  wpCli(["option", "update", "soames_edge_secret", SECRET]);
});

test.afterAll(() => {
  wpCli(["option", "delete", "soames_frontend_url"]);
  wpCli(["option", "delete", "soames_frontend_redirect"]);
  wpCli(["option", "delete", "soames_frontend_redirect_status"]);
  wpCli(["option", "delete", "soames_edge_secret"]);
});

test("the right secret gets the page itself, sent no-cache and noindex", async ({ request }) => {
  const res = await get(request, fixtures().plainPostUrl, SECRET);
  expect(res.status()).toBe(200);
  const h = res.headers();
  expect(h["x-robots-tag"]).toContain("noindex");
  expect(h["cache-control"]).toContain("no-cache");
  expect(await res.text()).toContain("<title>");
});

test("no header gets the ordinary redirect", async ({ request }) => {
  const res = await get(request, fixtures().plainPostUrl);
  expect(res.status()).toBe(302);
  expect(res.headers()["location"]).toContain(FRONTEND);
  // And none of the bypass's headers leak onto ordinary responses.
  expect(res.headers()["x-robots-tag"] ?? "").not.toContain("noindex");
});

test("a wrong secret is treated exactly like no header", async ({ request }) => {
  for (const wrong of ["", "nope", SECRET.slice(0, -1), SECRET + "x", SECRET.toUpperCase()]) {
    const res = await get(request, fixtures().plainPostUrl, wrong);
    expect(res.status(), `secret ${JSON.stringify(wrong)}`).toBe(302);
  }
});

test("with no secret configured, the header is ignored — even an empty one", async ({ request }) => {
  wpCli(["option", "delete", "soames_edge_secret"]);
  try {
    for (const sent of ["", SECRET]) {
      const res = await get(request, fixtures().plainPostUrl, sent);
      expect(res.status(), `sent ${JSON.stringify(sent)}`).toBe(302);
    }
  } finally {
    wpCli(["option", "update", "soames_edge_secret", SECRET]);
  }
});

test("the edge also skips WordPress's own canonical redirect", async ({ request }) => {
  // ?p=<id> on a published post is the textbook redirect_canonical case: a 301 to the pretty
  // permalink, which runs before Soames's redirect. On a site whose Home is the front end, that
  // 301 would send the edge back to the front end.
  wpCli(["option", "update", "soames_frontend_redirect", "0"]);
  try {
    const url = `${WP_BASE}/?p=${fixtures().plainPostId}`;
    expect((await get(request, url)).status()).toBe(301);
    expect((await get(request, url, SECRET)).status()).toBe(200);
  } finally {
    wpCli(["option", "update", "soames_frontend_redirect", "1"]);
  }
});

test("the secret is in no public payload", async ({ request }) => {
  const settings = await (await request.get(`${WP_BASE}/wp-json/soames/v1/settings`)).text();
  expect(settings).not.toContain(SECRET);
  // Nor in core's settings endpoint (show_in_rest: false); unauthenticated it's a 401 anyway,
  // so assert on the body whatever the status.
  const core = await (await request.get(`${WP_BASE}/wp-json/wp/v2/settings`)).text();
  expect(core).not.toContain(SECRET);
});

test("a short or whitespace secret is rejected and the old one kept", () => {
  const out = wpEval(`echo wp_json_encode([
    soames_sanitize_edge_secret(''),
    soames_sanitize_edge_secret('short'),
    soames_sanitize_edge_secret('has a space in it but is otherwise long enough'),
    soames_sanitize_edge_secret('  ${SECRET}  '),
  ]);`);
  expect(JSON.parse(out)).toEqual(["", SECRET, SECRET, SECRET]);
});

test("redirect type: 302 by default, 301 when opted in, anything else is 302", async ({ request }) => {
  const url = fixtures().plainPostUrl;
  wpCli(["option", "delete", "soames_frontend_redirect_status"]);
  expect((await get(request, url)).status()).toBe(302);
  try {
    wpCli(["option", "update", "soames_frontend_redirect_status", "301"]);
    const res = await get(request, url);
    expect(res.status()).toBe(301);
    expect(res.headers()["location"]).toContain(FRONTEND);

    wpCli(["option", "update", "soames_frontend_redirect_status", "308"]);
    expect((await get(request, url)).status()).toBe(302);
  } finally {
    wpCli(["option", "delete", "soames_frontend_redirect_status"]);
  }
});
