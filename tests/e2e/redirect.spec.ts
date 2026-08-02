import { test, expect } from "@playwright/test";
import { fixtures, wpCli, wpEval, GRAPHQL_URL, WP_BASE } from "./wp";

// ORBI-58 — front-end redirection, moved out of the companion theme into the plugin.
//
// These matter more than most of this suite. As a theme, the redirect was scoped by being
// a theme: it only ran once WordPress rendered a template with that theme active. From a
// plugin it runs on EVERY front-end request on EVERY install, so the bail-outs in
// includes/frontend-redirect.php are the only thing standing between this feature and
// breaking GraphQL, previews, or wp-admin. Each one is asserted here.
//
// The suite runs workers: 1 (ORBI-57), which these rely on: they mutate site-wide options
// (soames_frontend_url, page_for_posts, soames_frontend_redirect) and restore them.

const FRONTEND = "https://frontend.example";

/** Fetch without following redirects, so we can assert on the 302 itself. */
async function raw(request: any, url: string) {
  return request.get(url, { maxRedirects: 0 });
}

test.beforeAll(() => {
  wpCli(["option", "update", "soames_frontend_url", FRONTEND]);
  wpCli(["option", "update", "soames_frontend_redirect", "1"]);
});

test.afterAll(() => {
  // The rest of the suite assumes no front-end redirection: blocks.spec fetches rendered
  // post HTML directly from WordPress and would get a 302 instead.
  wpCli(["option", "delete", "soames_frontend_url"]);
  wpCli(["option", "delete", "soames_frontend_redirect"]);
});

test("a single post redirects under the blog base", async ({ request }) => {
  const f = fixtures();
  const res = await raw(request, f.plainPostUrl);
  expect(res.status()).toBe(302);
  const loc = res.headers()["location"];
  const path = new URL(f.plainPostUrl).pathname;
  expect(loc).toBe(`${FRONTEND}/blog${path}`);
});

test("the blog base follows the Posts page slug, not a hardcoded /blog", async ({
  request,
}) => {
  // THE REGRESSION THIS PROJECT FIXES. The theme hardcoded '/blog' while the Astro side
  // derives the base from WordPress's "Posts page" setting, so any install using a
  // different slug redirected posts to a URL the front end never generates.
  const f = fixtures();
  const newsId = Number(
    wpEval(
      `$p = wp_insert_post(['post_type'=>'page','post_title'=>'News','post_name'=>'soames-e2e-news','post_status'=>'publish']); echo $p;`
    ).trim()
  );
  const previous = wpEval(`echo (int) get_option('page_for_posts');`).trim();
  try {
    wpCli(["option", "update", "page_for_posts", String(newsId)]);

    const res = await raw(request, f.plainPostUrl);
    const loc = res.headers()["location"];
    const path = new URL(f.plainPostUrl).pathname;
    expect(loc).toBe(`${FRONTEND}/soames-e2e-news${path}`);
    expect(loc).not.toContain("/blog/");
  } finally {
    wpCli(["option", "update", "page_for_posts", previous || "0"]);
    wpEval(`wp_delete_post(${newsId}, true);`);
  }
});

test("a page keeps its own path", async ({ request }) => {
  const f = fixtures();
  const res = await raw(request, f.heroPageUrl);
  expect(res.status()).toBe(302);
  expect(res.headers()["location"]).toBe(
    `${FRONTEND}${new URL(f.heroPageUrl).pathname}`
  );
});

test("a Knowledge Base article keeps its path instead of landing on the home page", async ({
  request,
}) => {
  // The theme only handled is_singular('post') and is_page(); a docs article fell through
  // to the catch-all and was sent to the site root, losing the article entirely.
  const f = fixtures();
  const res = await raw(request, f.docsUrl);
  expect(res.status()).toBe(302);
  const path = new URL(f.docsUrl).pathname;
  expect(path).toContain("/docs/");
  expect(res.headers()["location"]).toBe(`${FRONTEND}${path}`);
});

test("everything else goes to the front-end home page, as a 302", async ({ request }) => {
  const res = await raw(request, `${WP_BASE}/?s=nothing-matches-this`);
  expect(res.status()).toBe(302); // deliberately not 301: the target is user-configurable
  expect(res.headers()["location"]).toBe(`${FRONTEND}/`);
});

test("GraphQL is never redirected", async ({ request }) => {
  // The single most important assertion here. A Soames install whose /graphql endpoint
  // redirects is a total outage of the build pipeline, and it presents as a broken SITE
  // rather than a broken WordPress, so it would be diagnosed in the wrong repo.
  const res = await request.post(GRAPHQL_URL, {
    headers: { "Content-Type": "application/json" },
    data: { query: "{ generalSettings { url } }" },
    maxRedirects: 0,
  });
  expect(res.status()).toBe(200);
  expect((await res.json()).data.generalSettings).toBeTruthy();
});

test("the REST API is never redirected", async ({ request }) => {
  const res = await raw(request, `${WP_BASE}/wp-json/soames/v1/settings`);
  expect(res.status()).toBe(200);
});

test("a draft preview still goes to the front-end preview route", async ({ request }) => {
  // preview.php owns previews on this same hook; frontend-redirect must not swallow them.
  //
  // A DRAFT on purpose. WordPress's redirect_canonical rewrites `?p=<id>` on a *published*
  // post to its pretty permalink with a 301 before either handler runs, so the ?p= form
  // only survives for unpublished posts — which is also the real use case for preview.
  const draftId = Number(
    wpEval(
      `echo wp_insert_post(['post_title'=>'soames-e2e-draft','post_name'=>'soames-e2e-draft','post_status'=>'draft','post_content'=>'draft body']);`
    ).trim()
  );
  try {
    const res = await raw(request, `${WP_BASE}/?p=${draftId}&preview=true`);
    expect(res.status()).toBe(302);
    expect(res.headers()["location"]).toContain(`${FRONTEND}/preview/?token=`);
  } finally {
    wpEval(`wp_delete_post(${draftId}, true);`);
  }
});

test("?preview=true on a published permalink is not swallowed by the redirect", async ({
  request,
}) => {
  // This shape (WordPress's own get_preview_post_link output for a published post) carries
  // no `p` param, so preview.php bails — and the new rule must then leave it alone rather
  // than treating it as an ordinary visit and bouncing it to the front end.
  const f = fixtures();
  const res = await raw(request, `${f.plainPostUrl}?preview=true`);
  expect(res.status()).toBe(200);
});

test("wp-admin is never redirected", async ({ request }) => {
  const res = await raw(request, `${WP_BASE}/wp-admin/`);
  // Not logged in here, so WordPress sends its own redirect to wp-login.php — the point
  // is that it's WordPress's redirect and not ours.
  const loc = res.headers()["location"] ?? "";
  expect(loc).not.toContain(FRONTEND);
});

test("robots.txt is served, not redirected", async ({ request }) => {
  const res = await raw(request, `${WP_BASE}/robots.txt`);
  expect(res.status()).toBe(200);
  expect(await res.text()).toContain("User-agent");
});

test("turning the setting off restores normal WordPress rendering", async ({ request }) => {
  const f = fixtures();
  wpCli(["option", "update", "soames_frontend_redirect", "0"]);
  try {
    const res = await raw(request, f.plainPostUrl);
    expect(res.status()).toBe(200);
  } finally {
    wpCli(["option", "update", "soames_frontend_redirect", "1"]);
  }
});

test("no frontend URL means no redirect, whatever the setting says", async ({ request }) => {
  const f = fixtures();
  wpCli(["option", "delete", "soames_frontend_url"]);
  try {
    const res = await raw(request, f.plainPostUrl);
    expect(res.status()).toBe(200);
  } finally {
    wpCli(["option", "update", "soames_frontend_url", FRONTEND]);
  }
});
