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
// Each assertion was checked by breaking the plugin deliberately: changing the delay
// fails the offset test, and removing the empty-URL guard fails the URL test. Removing
// the plugin's own coalescing guard does NOT fail anything — see the note in that test.
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

    // One build for a burst, and the timestamp must not be pushed further out by each
    // edit either, or a steady stream of saves would starve the build indefinitely.
    //
    // HONEST SCOPE (measured, ORBI-77): this pins OBSERVABLE behaviour, not the plugin's
    // guard. WordPress core already refuses a duplicate — wp_schedule_single_event()
    // rejects the same hook+args within a 10-minute window. Verified directly:
    // two calls 1s apart returned true then FALSE, with 1 event scheduled. So deleting
    // the plugin's own `wp_next_scheduled()` check does NOT fail this test (that was
    // tried). The plugin's guard is belt-and-braces on top of core.
    //
    // The test still earns its place: it fails if the design moves to immediate-fire,
    // or stops scheduling at all. It just is not evidence that the guard is present.
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
