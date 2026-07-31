import { test, expect } from "@playwright/test";
import { fixtures, graphql, WP_BASE } from "./wp";

// The WPGraphQL surface the theme queries. A field that silently disappears (a typo in
// graphql_register_types, a plugin load-order change) breaks the site BUILD, which is
// the loudest possible failure and the slowest to diagnose from Netlify logs.

test("hero fields are registered on Page and Post", async ({ request }) => {
  const data = await graphql<{ page: any; post: any }>(
    request,
    `{
      page: __type(name: "Page") { fields { name } }
      post: __type(name: "Post") { fields { name } }
    }`
  );
  for (const [type, t] of [["Page", data.page], ["Post", data.post]] as const) {
    const names = t.fields.map((f: any) => f.name);
    for (const field of ["heroTitle", "heroCaption", "overlayOpacity", "heroBackgroundImage"]) {
      expect(names, `${type}.${field}`).toContain(field);
    }
  }
});

test("hero fields return the values saved in post meta", async ({ request }) => {
  const f = fixtures();
  const data = await graphql<any>(
    request,
    `{ page(id: ${f.heroPageId}, idType: DATABASE_ID) {
        heroTitle heroCaption overlayOpacity heroBackgroundImage } }`
  );
  const p = data.page;

  // ORBI-52: HTML is allowed in the hero title (wp_kses_post, not
  // sanitize_text_field — the latter silently ate a <br> once).
  expect(p.heroTitle).toBe("Seeded Hero <br>Title");
  expect(p.heroCaption).toBe("<em>Seeded</em> caption");
  expect(p.overlayOpacity).toBe("0.35");
  // Resolved from the attachment ID to a full-size URL.
  expect(p.heroBackgroundImage).toMatch(/\/wp-content\/uploads\/.+\.png$/);
});

test("unset hero fields follow the documented null/default contract", async ({ request }) => {
  const f = fixtures();
  const data = await graphql<any>(
    request,
    `{ page(id: ${f.barePageId}, idType: DATABASE_ID) {
        heroTitle heroCaption overlayOpacity heroBackgroundImage } }`
  );
  const p = data.page;

  // The theme owns the fallback chain, so unset must arrive as null — NOT as "".
  expect(p.heroTitle).toBeNull();
  expect(p.heroCaption).toBeNull();
  expect(p.heroBackgroundImage).toBeNull();
  // overlayOpacity is the one field with a plugin-side default.
  expect(p.overlayOpacity).toBe("0.6");
});

test("the author fragment the theme queries resolves", async ({ request }) => {
  const f = fixtures();
  const data = await graphql<any>(
    request,
    `{ post(id: ${f.blocksPostId}, idType: DATABASE_ID) {
        author { node { firstName name description avatar { url } } } } }`
  );
  const a = data.post.author.node;

  // ORBI-53: `name` is display_name and is what the byline shows; firstName differs
  // on purpose in the fixture so a regression to the old behaviour is visible.
  expect(a.name).toBe("Ada Lovelace");
  expect(a.firstName).toBe("Ada");
  expect(a.description).toContain("headless WordPress");
  expect(a.avatar.url).toMatch(/\/wp-content\/uploads\/.+\.png$/);
});

test("docs carry the menuOrder the theme sorts by", async ({ request }) => {
  const f = fixtures();
  // Exactly the query shape the theme's getDocs() uses — the `documents` root field
  // (the CPT's graphql_plural_name), sorted client-side by menuOrder.
  const data = await graphql<any>(
    request,
    `{ wpDocs: documents(first: 200) {
        nodes { databaseId title menuOrder parentDatabaseId } } }`
  );
  const children = (data.wpDocs.nodes as Array<any>)
    .filter((n) => n.parentDatabaseId === f.docsParentId)
    .sort((a, b) => a.menuOrder - b.menuOrder);

  expect(children.length).toBe(3);
  // Seeded reverse-alphabetically with menu_order 1,2,3, so anything that sorted by
  // title instead would come back in a different order.
  expect(children.map((n) => n.title)).toEqual(["Zulu Child", "Alpha Child", "Mike Child"]);
  expect(children.map((n) => n.menuOrder)).toEqual([1, 2, 3]);
  // parentDatabaseId is what the theme walks to build breadcrumbs (ORBI-40).
  expect(children.every((n) => n.parentDatabaseId === f.docsParentId)).toBe(true);
});

test("the docs post type keeps its key and GraphQL names", async ({ request }) => {
  // ORBI-36 rebranded the admin labels to "Knowledge Base"/"Article" but deliberately
  // did NOT change the post-type key, rewrite slug, or GraphQL names. This pins that:
  // the theme queries `documents`, so renaming these silently empties the whole KB.
  const data = await graphql<any>(
    request,
    `{ contentType(id: "docs", idType: NAME) { name graphqlSingleName graphqlPluralName } }`
  );
  expect(data.contentType.name).toBe("docs");
  expect(data.contentType.graphqlSingleName).toBe("Document");
  expect(data.contentType.graphqlPluralName).toBe("Documents");
});

test("docs are served under the /docs/ rewrite slug", async ({ request }) => {
  const f = fixtures();
  const res = await request.get(`${WP_BASE}/docs/soames-e2e-guide/`, {
    failOnStatusCode: false,
  });
  expect(res.status(), "/docs/ rewrite slug changed").toBe(200);
  expect(f.docsParentId).toBeGreaterThan(0);
});
