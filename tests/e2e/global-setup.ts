import { execFileSync } from "node:child_process";
import { writeFileSync } from "node:fs";
import path from "node:path";
import { FIXTURES_FILE } from "./wp";

// Re-seed WordPress before every run and hand the resulting IDs to the specs.
//
// Seeding here rather than only in the npm script means `npx playwright test` on its
// own is still correct — a half-stale database is the classic way an e2e suite starts
// lying. The seeder is idempotent, so this is cheap to repeat.
export default async function globalSetup() {
  const repoRoot = path.join(__dirname, "..", "..");
  const seedPath =
    "wp-content/plugins/soames-wordpress-plugin/tests/fixtures/seed.php";

  let out: string;
  try {
    out = execFileSync(
      "npx",
      ["wp-env", "run", "cli", "--env-cwd=/var/www/html", "wp", "eval-file", seedPath],
      { cwd: repoRoot, encoding: "utf8", stdio: ["ignore", "pipe", "pipe"] }
    );
  } catch (err: any) {
    throw new Error(
      "Failed to seed WordPress. Is wp-env running? Try `npm run env:start`.\n" +
        (err.stderr || err.message)
    );
  }

  // wp-env prefixes its own chatter, so take the last JSON-looking line.
  const line = out
    .split("\n")
    .map((l) => l.trim())
    .filter((l) => l.startsWith("{") && l.endsWith("}"))
    .pop();
  if (!line) throw new Error(`Seeder produced no fixture JSON. Output:\n${out}`);

  writeFileSync(FIXTURES_FILE, line + "\n");
  console.log(`[e2e] seeded fixtures → ${path.basename(FIXTURES_FILE)}`);
}
