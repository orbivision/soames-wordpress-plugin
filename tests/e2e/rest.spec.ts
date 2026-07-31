import { test, expect } from "@playwright/test";
import { WP_BASE, wpCli } from "./wp";

// soames/v1/settings is consumed by the theme's getSoamesSettings() at BUILD time and
// destructured straight into the site chrome. A renamed key doesn't degrade — it
// breaks the build or silently blanks the header, so the shape is pinned here.
//
// Keys mirror the SoamesSettings interface in soames-astro-theme/src/lib/wp.ts.
const SETTINGS_URL = `${WP_BASE}/wp-json/soames/v1/settings`;

const NULLABLE_STRING_KEYS = [
  "logoUrl",
  "logoAlt",
  "faviconUrl",
  "contactBlurb",
  "companyName",
];

test("settings endpoint is public and returns the expected shape", async ({ request }) => {
  // permission_callback is __return_true: the static build fetches this unauthenticated.
  const res = await request.get(SETTINGS_URL);
  expect(res.status()).toBe(200);

  const body = await res.json();

  for (const key of [...NULLABLE_STRING_KEYS, "showCompanyName", "docsPageId"]) {
    expect(body, `settings.${key} missing`).toHaveProperty(key);
  }

  // Unset values must be null, never "" or 0 — the theme's fallbacks test for null.
  for (const key of NULLABLE_STRING_KEYS) {
    const v = body[key];
    expect(v === null || typeof v === "string", `settings.${key} = ${JSON.stringify(v)}`).toBe(true);
    if (typeof v === "string") expect(v).not.toBe("");
  }
  expect(typeof body.showCompanyName, "showCompanyName must be boolean").toBe("boolean");
  const docsPageId = body.docsPageId;
  expect(
    docsPageId === null || typeof docsPageId === "number",
    `docsPageId = ${JSON.stringify(docsPageId)}`
  ).toBe(true);
});

test("set options surface through the endpoint", async ({ request }) => {
  wpCli(["option", "update", "soames_company_name", "E2E Test Co"]);
  wpCli(["option", "update", "soames_contact_blurb", "Reach us at e2e@example.com"]);
  wpCli(["option", "update", "soames_show_company_name", "0"]);
  try {
    const body = await (await request.get(SETTINGS_URL)).json();
    expect(body.companyName).toBe("E2E Test Co");
    expect(body.contactBlurb).toContain("e2e@example.com");
    expect(body.showCompanyName).toBe(false);
  } finally {
    wpCli(["option", "delete", "soames_company_name"]);
    wpCli(["option", "delete", "soames_contact_blurb"]);
    wpCli(["option", "update", "soames_show_company_name", "1"]);
  }
});

test("empty options come back as null, not empty strings", async ({ request }) => {
  wpCli(["option", "delete", "soames_company_name"]);
  const body = await (await request.get(SETTINGS_URL)).json();
  expect(body.companyName).toBeNull();
});

test("the preview route is registered", async ({ request }) => {
  // Registered, and NOT open to the world: it should reject an unauthenticated
  // request rather than 404 (404 would mean the route vanished entirely).
  const res = await request.get(`${WP_BASE}/wp-json/soames/v1/preview`, {
    failOnStatusCode: false,
  });
  expect(res.status(), "preview route missing (404)").not.toBe(404);
});
