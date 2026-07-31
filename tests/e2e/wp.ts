import { execFileSync } from "node:child_process";
import { readFileSync } from "node:fs";
import path from "node:path";
import type { Page, APIRequestContext } from "@playwright/test";

// Shared plumbing for the plugin e2e suite.

export const WP_BASE = "http://localhost:8977";
export const GRAPHQL_URL = `${WP_BASE}/graphql`;
// wp-env's fixed development credentials.
export const ADMIN_USER = "admin";
export const ADMIN_PASS = "password";

export const FIXTURES_FILE = path.join(__dirname, ".fixtures.json");

export interface Fixtures {
  authorId: number;
  plainId: number;
  avatarId: number;
  heroImageId: number;
  blocksPostId: number;
  plainPostId: number;
  heroPageId: number;
  barePageId: number;
  docsParentId: number;
  docsChildren: Record<string, number>;
  blocksSlug: string;
  heroSlug: string;
}

export function fixtures(): Fixtures {
  return JSON.parse(readFileSync(FIXTURES_FILE, "utf8")) as Fixtures;
}

// Run a wp-cli command in the wp-env container. Synchronous on purpose: these are
// setup/teardown steps where ordering matters more than speed, and each costs ~1s of
// Docker overhead.
export function wpCli(args: string[]): string {
  // NOTE: `wp-env run` takes the container as its first positional and has no
  // --quiet flag; passing one makes it parse the flag as the container name.
  const out = execFileSync("npx", ["wp-env", "run", "cli", "wp", ...args], {
    cwd: path.join(__dirname, "..", ".."),
    encoding: "utf8",
    stdio: ["ignore", "pipe", "pipe"],
  });
  // wp-env wraps command output in its own progress chatter ("ℹ Starting …",
  // "✔ Ran … (in 0s)"). Strip it so callers get just what wp printed.
  return out
    .split("\n")
    .filter((l) => !/^\s*[ℹ✔✖⚠]/.test(l))
    .join("\n")
    .trim();
}

/** Evaluate PHP inside WordPress and return stdout. */
export function wpEval(php: string): string {
  return wpCli(["eval", php]);
}

export async function graphql<T = any>(
  request: APIRequestContext,
  query: string
): Promise<T> {
  const res = await request.post(GRAPHQL_URL, {
    headers: { "Content-Type": "application/json" },
    data: { query },
  });
  if (!res.ok()) throw new Error(`GraphQL HTTP ${res.status()}: ${await res.text()}`);
  const body = await res.json();
  if (body.errors) throw new Error(`GraphQL errors: ${JSON.stringify(body.errors)}`);
  return body.data as T;
}

/** Log into wp-admin through the real login form. */
export async function login(page: Page): Promise<void> {
  await page.goto(`${WP_BASE}/wp-login.php`);
  await page.locator("#user_login").fill(ADMIN_USER);
  await page.locator("#user_pass").fill(ADMIN_PASS);
  await page.locator("#wp-submit").click();
  await page.waitForURL(/wp-admin/);
}
