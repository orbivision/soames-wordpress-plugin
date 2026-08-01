import { defineConfig, devices } from "@playwright/test";

// ORBI-54 — e2e tests for the Soames plugin against a real WordPress (@wordpress/env).
//
// These assert the plugin's CONTRACT WITH THE THEME, not its appearance: the markup
// blocks render, the WPGraphQL fields, the settings REST shape, avatar resolution.
// The recorded failure mode in this repo is a stale blocks.php that silently breaks
// rendering while the editor still looks perfect — invisible to screenshots, obvious
// to these.
//
// No webServer here: WordPress is Docker, started by `npm run env:start`. globalSetup
// seeds fixtures on every run, so a stale database can't quietly change results.
const PORT = 8977;

export default defineConfig({
  testDir: "./tests/e2e",
  globalSetup: "./tests/e2e/global-setup.ts",

  // Tests within a file run serially: several of them toggle GLOBAL WordPress state
  // (show_avatars, for one), and parallel tests in the same file would race on it.
  fullyParallel: false,

  // ORBI-57: ...and one worker, because "files still run in parallel across workers"
  // left that same hazard wide open ACROSS files. There is a single WordPress instance
  // behind every spec, so global state has no file scope:
  //   - avatar.spec toggles show_avatars off site-wide, during which any other spec
  //     reading `avatar { url }` gets null;
  //   - the profile round-trip clears a user's avatar and puts it back.
  // The second one is what actually failed — graphql.spec read Ada's avatar inside the
  // cleared window and got a `d=mm` Gravatar URL, in CI only, on a commit that passed
  // locally. That spec now mutates its own dedicated user, but isolation-by-fixture
  // can't cover a site-wide option, so the suite is serial.
  //
  // The cost is small and worth naming: this suite is ~35 tests that spend most of
  // their time shelling out to Docker, so serializing trades tens of seconds for a
  // class of failure that only ever shows up in CI and reads as a real regression.
  workers: 1,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  reporter: process.env.CI ? [["html", { open: "never" }], ["list"]] : "list",
  // wp-cli calls shell out to Docker and take ~1s each, so give tests room.
  timeout: 60_000,

  use: {
    baseURL: `http://localhost:${PORT}`,
    trace: "on-first-retry",
  },

  projects: [{ name: "chromium", use: { ...devices["Desktop Chrome"] } }],
});
