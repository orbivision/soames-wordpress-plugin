import { test, expect } from "@playwright/test";
import { wpCli, wpEval, login, WP_BASE } from "./wp";

// ORBI-82. A headless site sets WordPress's home ("Site Address") to the static front end and
// keeps siteurl on the WordPress host — the split that makes Optima Express register the front
// end. WordPress builds rest_url() from home, so without includes/rest-url.php the block
// editor's REST root points at the front end and it can neither load nor save.
//
// wp-env pins WP_HOME as a constant in wp-config.php, so `wp option update home` does nothing;
// these tests set the constant and always put it back. siteurl here is http://localhost:8977.

test.describe.configure({ mode: "serial" });

function setHome(url: string): void {
  wpCli(["config", "set", "WP_HOME", url]);
}
const restUrl = () => wpEval(`echo rest_url();`).trim();

test.afterAll(() => setHome(WP_BASE));

test("hosts match: rest_url is left exactly as WordPress built it", () => {
  setHome(WP_BASE);
  expect(restUrl()).toBe(`${WP_BASE}/wp-json/`);
});

test("hosts differ: rest_url is rebuilt on the WordPress host", () => {
  setHome("https://frontend.example");
  try {
    expect(wpEval(`echo home_url();`).trim()).toBe("https://frontend.example");
    expect(restUrl()).toBe(`${WP_BASE}/wp-json/`);
    expect(wpEval(`echo rest_url('wp/v2/posts');`).trim()).toBe(`${WP_BASE}/wp-json/wp/v2/posts`);
  } finally {
    setHome(WP_BASE);
  }
});

test("a scheme-only mismatch on the same host is NOT rewritten", () => {
  // siteurl http, home https, same host. Rewriting this would push the editor to http and
  // give it mixed content — the reason the filter compares hosts, not whole URLs.
  setHome("https://localhost:8977");
  try {
    expect(restUrl()).toBe("https://localhost:8977/wp-json/");
  } finally {
    setHome(WP_BASE);
  }
});

test("a home with a path loses that path, not just its host", () => {
  setHome("https://frontend.example/site");
  try {
    expect(restUrl()).toBe(`${WP_BASE}/wp-json/`);
  } finally {
    setHome(WP_BASE);
  }
});

test("hosts differ: the block editor can still load and save through REST", async ({ page }) => {
  const id = wpEval(`echo wp_insert_post(['post_title' => 'split-home probe', 'post_status' => 'draft']);`).trim();
  setHome("https://frontend.example");
  try {
    await login(page);
    await page.goto(`${WP_BASE}/wp-admin/post.php?post=${id}&action=edit`);
    await page.waitForFunction(() => !!(window as any).wp?.apiFetch);
    const result = await page.evaluate(async (postId) => {
      const w = window as any;
      const saved = await w.wp.apiFetch({
        path: `/wp/v2/posts/${postId}`,
        method: "POST",
        data: { excerpt: "saved under a split home" },
      });
      return { root: w.wpApiSettings.root, excerpt: saved.excerpt.raw };
    }, id);
    expect(result.root).toBe(`${WP_BASE}/wp-json/`);
    expect(result.excerpt).toBe("saved under a split home");
  } finally {
    setHome(WP_BASE);
    wpCli(["post", "delete", id, "--force"]);
  }
});
