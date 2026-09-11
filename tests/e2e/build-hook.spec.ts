import { test, expect } from "@playwright/test";
import { wpCli, wpEval } from "./wp";

// ORBI-77. The build hook does NOT post to Netlify on save — it schedules a single
// deferred wp-cron event (SOAMES_BUILD_DELAY, 30s) so a burst of edits coalesces into
// one build that runs after the edits settle (ORBI-32).
//
// WHAT THIS CAN AND CANNOT PROVE. It pins the *scheduling* contract: that a publish
// schedules exactly one event at the right offset, that a second publish inside the
// window does not stack a duplicate, and that an unset hook URL schedules nothing.
//
// It CANNOT reproduce the bug in issue #6. That failure is the event never FIRING,
// because wp-cron only runs when a request arrives and the production WordPress is
// headless — no traffic, so nothing notices the event is due. wp-env always has
// traffic (these tests are the traffic), so the failing condition does not exist here.
// The fix for #6 is external cron on the host; this file exists so that if the design
// ever moves away from deferral, the change is visible rather than silent.

const EVENT = "soames_build_site";
const DELAY = 30; // must match SOAMES_BUILD_DELAY in includes/build-hook.php

// Deliberately unroutable: TEST-NET-1 (RFC 5737) on the discard port. If anything ever
// does fire the hook during a test run, it must not reach a real Netlify build.
const BLACKHOLE = "http://192.0.2.1:9/build";

/** Timestamps of every pending `soames_build_site` event, oldest first. */
function scheduled(): number[] {
  const out = wpEval(
    `$c = _get_cron_array() ?: [];
     $t = [];
     foreach ($c as $ts => $hooks) { if (isset($hooks['${EVENT}'])) { $t[] = (int) $ts; } }
     sort($t);
     echo implode(',', $t);`
  ).trim();
  return out === "" ? [] : out.split(",").map(Number);
}

function clearSchedule(): void {
  wpEval(`wp_clear_scheduled_hook('${EVENT}');`);
}

function now(): number {
  return Number(wpEval("echo time();").trim());
}

function publishPost(title: string): string {
  return wpCli(["post", "create", "--post_type=post", "--post_status=publish",
    `--post_title=${title}`, "--porcelain"]).trim();
}

test.describe("build hook scheduling (ORBI-77)", () => {
  const created: string[] = [];

  test.beforeEach(() => {
    wpEval(`update_option('soames_build_hook_url', '${BLACKHOLE}');`);
    clearSchedule();
  });

  test.afterAll(() => {
    clearSchedule();
    // Leave no hook URL behind: a stray cron run in a reused container must not post.
    wpEval(`delete_option('soames_build_hook_url');`);
    for (const id of created) wpCli(["post", "delete", id, "--force"]);
  });

  test("publishing schedules exactly one build, ~30s out", () => {
    const t0 = now();
    created.push(publishPost("ORBI-77 schedule one"));

    const events = scheduled();
    expect(events).toHaveLength(1);
    // Allow slack for Docker round-trips between reading time() and the schedule call.
    expect(events[0]).toBeGreaterThanOrEqual(t0 + DELAY - 5);
    expect(events[0]).toBeLessThanOrEqual(t0 + DELAY + 5);
  });

  test("a second publish inside the window coalesces — no duplicate stacked", () => {
    created.push(publishPost("ORBI-77 coalesce A"));
    const first = scheduled();
    expect(first).toHaveLength(1);

    created.push(publishPost("ORBI-77 coalesce B"));
    const second = scheduled();

    // This is the whole point of ORBI-32: one build for a burst, and it must not be
    // pushed further out by each edit either, or a steady stream of saves would
    // starve the build indefinitely.
    expect(second).toHaveLength(1);
    expect(second[0]).toBe(first[0]);
  });

  test("no hook URL configured schedules nothing", () => {
    wpEval(`delete_option('soames_build_hook_url');`);
    clearSchedule();

    created.push(publishPost("ORBI-77 no url"));

    expect(scheduled()).toHaveLength(0);
  });

  test("unpublishing also schedules a build", () => {
    const id = publishPost("ORBI-77 unpublish");
    created.push(id);
    clearSchedule();

    wpCli(["post", "update", id, "--post_status=draft"]);

    // Leaving `publish` changes the live site just as much as entering it — the page
    // has to disappear from the static build.
    expect(scheduled()).toHaveLength(1);
  });
});
