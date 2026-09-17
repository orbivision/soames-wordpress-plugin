import { test, expect } from "@playwright/test";
import { wpEval, login, WP_BASE } from "./wp";

// ORBI-80. The build hook records how late it actually fired, and warns in wp-admin
// when that exceeds five minutes.
//
// WHAT THIS CAN AND CANNOT PROVE — the same honesty as build-hook.spec.ts, and for the
// same reason. These tests exercise the INSTRUMENT: that lateness is recorded at fire
// time, that the notice renders on the recorded values, and that it stays quiet when it
// should. They do NOT reproduce the production condition — wp-env always has traffic
// (these tests are the traffic), so wp-cron here is never starved. A green run is
// evidence the detector works, never evidence that publishing on soames.app does.
//
// Every assertion below was checked by breaking the plugin deliberately; where an
// assertion turned out NOT to be load-bearing, that is recorded on the test itself.

const EVENT = "soames_build_site";
const LATE_AFTER = 300; // must match SOAMES_BUILD_LATE_AFTER in includes/build-hook.php
const BLACKHOLE = "http://192.0.2.1:9/build"; // TEST-NET-1, discard port

function setHook(url: string | null): void {
  wpEval(url === null
    ? `delete_option('soames_build_hook_url');`
    : `update_option('soames_build_hook_url', '${url}');`);
}

function opt(name: string): string {
  return wpEval(`$v = get_option('${name}'); echo is_array($v) ? wp_json_encode($v) : (string) $v;`).trim();
}

function lastRun(): { fired_at: number; scheduled_at: number; lateness: number | null } | null {
  const raw = opt("soames_build_last_run");
  return raw === "" ? null : JSON.parse(raw);
}

function reset(): void {
  wpEval(`wp_clear_scheduled_hook('${EVENT}');
          delete_option('soames_build_scheduled_at');
          delete_option('soames_build_last_run');`);
}

/** Run the cron callback directly, as wp-cron would. */
function runScheduledBuild(): void {
  wpEval(`do_action('${EVENT}');`);
}

async function noticeText(page: any): Promise<string> {
  await login(page);
  await page.goto(`${WP_BASE}/wp-admin/index.php`);
  const n = page.locator(".notice-warning", { hasText: "Soames:" });
  return (await n.count()) === 0 ? "" : (await n.first().innerText());
}

test.describe("build health instrument (ORBI-80)", () => {
  test.beforeEach(() => {
    reset();
    setHook(BLACKHOLE);
  });

  test.afterAll(() => {
    reset();
    setHook(null);
  });

  test("firing records when it was due, when it ran, and how late", () => {
    // Backdate the due time by 10 minutes, then fire: lateness must be measured against
    // the recorded schedule, not invented at read time.
    const due = Math.floor(Date.now() / 1000) - 600;
    wpEval(`update_option('soames_build_scheduled_at', ${due}, false);`);

    runScheduledBuild();

    const run = lastRun();
    expect(run).not.toBeNull();
    expect(run!.scheduled_at).toBe(due);
    expect(run!.lateness).toBeGreaterThanOrEqual(600);
    // and the pending marker is cleared, so (a) can't double-report what (b) now covers
    expect(opt("soames_build_scheduled_at")).toBe("");
  });

  test("an on-time build records no meaningful lateness and clears the warning", async ({ page }) => {
    wpEval(`update_option('soames_build_last_run', ['fired_at' => time(), 'scheduled_at' => time() - 900, 'lateness' => 900], false);`);
    expect(await noticeText(page)).toContain("later than scheduled");

    // A subsequent punctual build is what proves health — only the LAST run is kept, so
    // it clears the warning by itself with no expiry logic.
    const due = Math.floor(Date.now() / 1000);
    wpEval(`update_option('soames_build_scheduled_at', ${due}, false);`);
    runScheduledBuild();

    expect(await noticeText(page)).toBe("");
  });

  test("a build waiting past the threshold warns even though it never fired", async ({ page }) => {
    // The case fire-time measurement structurally cannot catch: a build that never runs
    // records nothing, so there is no lateness to report.
    wpEval(`update_option('soames_build_scheduled_at', ${Math.floor(Date.now() / 1000) - (LATE_AFTER + 60)}, false);`);

    expect(await noticeText(page)).toContain("has not run");
  });

  test("normal timing is silent", async ({ page }) => {
    // Worst healthy case: a 30s defer plus up to 60s of cron granularity.
    wpEval(`update_option('soames_build_last_run', ['fired_at' => time(), 'scheduled_at' => time() - 90, 'lateness' => 90], false);`);
    wpEval(`update_option('soames_build_scheduled_at', ${Math.floor(Date.now() / 1000) - 45}, false);`);

    expect(await noticeText(page)).toBe("");
  });

  test("a site with no build hook never warns", async ({ page }) => {
    // Two of the four subsites on the production network have no hook and, by ORBI-77
    // decision A, no cron entry either. On them "wp-cron is not prompt" is true and
    // expected; warning there would be permanent noise about an unused feature.
    wpEval(`update_option('soames_build_last_run', ['fired_at' => time(), 'scheduled_at' => time() - 3600, 'lateness' => 3600], false);`);
    wpEval(`update_option('soames_build_scheduled_at', ${Math.floor(Date.now() / 1000) - 3600}, false);`);
    setHook(null);

    expect(await noticeText(page)).toBe("");
  });

  test("a stale late build stops nagging", async ({ page }) => {
    // Eight days old: real, but nobody can act on it any more, and a warning that cannot
    // be cleared is one that gets dismissed permanently.
    wpEval(`update_option('soames_build_last_run', ['fired_at' => time() - 691200, 'scheduled_at' => time() - 692100, 'lateness' => 900], false);`);

    expect(await noticeText(page)).toBe("");
  });

  test("'Deploy now' does not record timing or clear a pending build", () => {
    // THE SUBTLE ONE. "Deploy now" fires immediately, bypassing cron — it already masks
    // this class of fault for the user (ORBI-77: never test publishing with it). If the
    // measurement lived in soames_fire_build(), a manual deploy would also invent a bogus
    // lateness against whatever was last scheduled AND clear a genuinely pending build,
    // masking the fault for the detector too. Only the cron path measures.
    const due = Math.floor(Date.now() / 1000) - 600;
    wpEval(`update_option('soames_build_scheduled_at', ${due}, false);`);

    wpEval(`soames_fire_build();`); // what admin_post_soames_deploy_now calls

    expect(lastRun()).toBeNull();
    expect(opt("soames_build_scheduled_at")).toBe(String(due));
  });

  test("a coalesced second edit does not push the lateness reference forward", () => {
    // Scheduling records the due time only when it actually schedules. If a coalesced
    // second publish overwrote it, a build that fired an hour late after a burst of edits
    // would measure as punctual — the failure would erase its own evidence.
    const id = wpEval(`$p = wp_insert_post(['post_title' => 'ORBI-80 coalesce', 'post_type' => 'post', 'post_status' => 'publish']); echo $p;`).trim();
    const first = opt("soames_build_scheduled_at");
    expect(first).not.toBe("");

    wpEval(`wp_update_post(['ID' => ${id}, 'post_title' => 'ORBI-80 coalesce again']);`);
    expect(opt("soames_build_scheduled_at")).toBe(first);

    wpEval(`wp_delete_post(${id}, true);`);
  });
});
