import { test, expect } from "@playwright/test";
import { fixtures } from "./wp";

// THE contract test. The theme maps `wp-block-soames-*` class names to components and
// reads its data off `data-*` attributes (repeaters via a `data-items` JSON payload).
// If a render_callback changes shape, the editor still looks perfect and the front end
// silently renders nothing — the documented failure mode for this repo. So: render the
// seeded all-blocks post and assert the emitted markup, attribute by attribute.

// One table, mirroring includes/blocks.php. When a block gains an attribute, add it
// here and the assertion comes for free.
interface Contract {
  block: string;
  selector: string;
  attrs?: Record<string, string | RegExp>;
  // data-items entries must parse as JSON with these keys on every element.
  itemKeys?: string[];
  itemCount?: number;
  containsHtml?: string;
  text?: string;
}

const CONTRACTS: Contract[] = [
  {
    block: "title-bar",
    selector: ".wp-block-soames-title-bar",
    text: "Seeded Title Bar",
  },
  {
    block: "title-bar-lg",
    selector: ".wp-block-soames-title-bar-lg",
    attrs: {
      "data-title": "Big Title",
      "data-subtitle": "Sub, with comma",
      "data-background": "https://example.com/bg.jpg",
    },
  },
  {
    block: "icon-list",
    selector: '.wp-block-soames-icon-list[data-items]',
    attrs: { "data-size": "medium" },
    itemKeys: ["image", "label", "link", "css"],
    itemCount: 2,
  },
  {
    block: "gallery-menu",
    selector: ".wp-block-soames-gallery-menu",
    attrs: { "data-layout": "compact" },
    itemKeys: ["image", "label", "link", "css"],
    itemCount: 2,
  },
  {
    block: "feature",
    selector: ".wp-block-soames-feature",
    attrs: {
      "data-image": "https://example.com/f.jpg",
      "data-title": "Feature Title",
      "data-css": "feat",
    },
    // ORBI-43: content is inlined HTML inside the wrapper, not a data attribute.
    containsHtml: "<strong>markup</strong>",
  },
  {
    block: "video",
    selector: ".wp-block-soames-video",
    attrs: {
      "data-link": "https://www.youtube.com/watch?v=dQw4w9WgXcQ",
      "data-title": "Video Title",
    },
  },
  {
    block: "soundcloud",
    selector: ".wp-block-soames-soundcloud",
    attrs: {
      "data-band-name": "Band, The",
      "data-site-link": "https://example.com",
      "data-playlist-id": "123456",
      "data-album-link": "https://example.com/album",
      "data-album-name": "Album & Name",
    },
  },
  {
    block: "text-list",
    selector: ".wp-block-soames-text-list[data-items]",
    itemKeys: ["content"],
    itemCount: 2,
  },
];

test.beforeEach(async ({ page }) => {
  const f = fixtures();
  // Use the permalink WordPress itself generated, and CHECK THE STATUS. Composing the
  // URL by hand and not asserting the response is how a rewrite-rule problem shows up
  // as "expected 8 blocks, received 0" instead of "the page 404'd".
  const res = await page.goto(f.blocksUrl);
  expect(
    res?.status(),
    `all-blocks fixture did not render at ${f.blocksUrl} — rewrite rules or seed problem, not a block problem`
  ).toBe(200);
});

for (const c of CONTRACTS) {
  test(`soames/${c.block} renders its theme contract`, async ({ page }) => {
    const el = page.locator(c.selector).first();
    await expect(el, `${c.selector} missing from rendered post`).toHaveCount(1);

    for (const [attr, expected] of Object.entries(c.attrs ?? {})) {
      const actual = await el.getAttribute(attr);
      if (expected instanceof RegExp) {
        expect(actual ?? "", `${c.block} ${attr}`).toMatch(expected);
      } else {
        expect(actual, `${c.block} ${attr}`).toBe(expected);
      }
    }

    if (c.text) {
      expect((await el.innerText()).trim()).toBe(c.text);
    }

    if (c.containsHtml) {
      expect(await el.innerHTML()).toContain(c.containsHtml);
    }

    if (c.itemKeys) {
      const raw = await el.getAttribute("data-items");
      expect(raw, `${c.block} data-items missing`).toBeTruthy();

      // The whole point of data-items: labels contain commas, so the payload must
      // survive as JSON rather than a comma-split string.
      let items: unknown;
      expect(() => {
        items = JSON.parse(raw!);
      }, `${c.block} data-items is not valid JSON: ${raw}`).not.toThrow();

      expect(Array.isArray(items)).toBe(true);
      const arr = items as Array<Record<string, unknown>>;
      if (c.itemCount !== undefined) expect(arr.length).toBe(c.itemCount);
      for (const item of arr) {
        for (const key of c.itemKeys) {
          expect(item, `${c.block} item missing "${key}"`).toHaveProperty(key);
        }
      }
    }
  });
}

test("comma-bearing labels survive the data-items round trip", async ({ page }) => {
  // Regression guard for the reason data-items exists at all (ORBI-20/42).
  const raw = await page
    .locator(".wp-block-soames-icon-list[data-items]")
    .first()
    .getAttribute("data-items");
  const items = JSON.parse(raw!) as Array<{ label: string }>;
  expect(items.map((i) => i.label)).toEqual(["One, with a comma", "Two & three"]);
});

test("legacy comma-separated blocks still render", async ({ page }) => {
  // pre-ORBI-20/42 content keeps working: blocks.php has explicit fallbacks, and
  // nothing else covers them, so a "cleanup" could silently break old posts.
  const legacyIcons = page.locator(".wp-block-soames-icon-list[data-images]").first();
  await expect(legacyIcons).toHaveCount(1);
  expect(await legacyIcons.getAttribute("data-labels")).toBe("Legacy A,Legacy B");
  expect(await legacyIcons.getAttribute("data-css")).toBe("legacy");

  // Legacy text-list inlines its HTML instead of emitting data-items.
  const legacyText = page
    .locator(".wp-block-soames-text-list:not([data-items])")
    .first();
  await expect(legacyText).toHaveCount(1);
  expect(await legacyText.innerHTML()).toContain("Legacy text list body");
});

test("every registered Soames block is exercised by this suite", async ({ page }) => {
  // Guard against the suite silently falling behind the plugin: if a new block is
  // registered and nobody adds a contract, this fails.
  const rendered = await page.locator('[class*="wp-block-soames-"]').evaluateAll((els) =>
    Array.from(
      new Set(
        els.flatMap((el) =>
          Array.from(el.classList).filter((c) => c.startsWith("wp-block-soames-"))
        )
      )
    )
  );
  const covered = new Set(CONTRACTS.map((c) => `wp-block-soames-${c.block}`));
  const uncovered = rendered.filter((c) => !covered.has(c));
  expect(uncovered, `blocks rendered but not covered by CONTRACTS: ${uncovered}`).toEqual([]);
  expect(rendered.length).toBe(CONTRACTS.length);
});
