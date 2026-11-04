// Operations screen (public/screen) in Chromium against screen-mock.mjs:
// share-login -> panels render -> session loss re-logs in once -> 401 returns to login.
import { test, before, after, beforeEach } from "node:test";
import assert from "node:assert/strict";
import { chromium } from "playwright";
import { startScreenMock, GOOD_CODE, LIMITED_CODE } from "./screen-mock.mjs";

let mock;
let browser;

before(async () => {
  mock = await startScreenMock(0);
  browser = await chromium.launch({ executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || undefined });
});

after(async () => {
  await browser?.close();
  await mock?.close();
});

beforeEach(() => mock.reset());

// The browser runs in UTC; the screen must still count "today" in the backend's zone.
async function openScreen(path, { timezoneId = "UTC" } = {}) {
  const context = await browser.newContext({ viewport: { width: 1920, height: 1080 }, timezoneId });
  const page = await context.newPage();
  const errors = [];
  page.on("pageerror", (error) => errors.push(error.message));
  page.on("console", (message) => {
    if (message.type() === "error" && !/Failed to load resource/.test(message.text())) errors.push(message.text());
  });
  await page.goto(`${mock.baseUrl}${path}`);
  return { context, page, errors };
}

async function waitForPanels(page) {
  await page.locator("#screen").waitFor({ state: "visible" });
  await page.waitForFunction(() => document.querySelector("#screen-status")?.textContent === "已更新");
}

const count = (prefix) => mock.requestLog.filter((line) => line.startsWith(prefix)).length;

// Wakes the poller at once instead of waiting for the 10 s interval.
async function pokeRefresh(page) {
  await page.evaluate(() => window.dispatchEvent(new Event("online")));
}

test("share-login renders the five overview panels, the live panel and the status line", async () => {
  const { context, page, errors } = await openScreen(`/screen/${GOOD_CODE}`);
  try {
    await waitForPanels(page);
    assert.equal(await page.title(), "TokenHub 运营大屏");
    assert.match(await page.locator("#token-summary").innerText(), /7,000,000/);
    assert.equal(await page.locator("#model-share-chart svg").count(), 1);
    assert.match(await page.locator("#overview-rankings").innerText(), /办公室/);
    assert.match(await page.locator("#overview-performance").innerText(), /3\.00 s/);
    assert.match(await page.locator("#overview-security").innerText(), /9/);
    assert.match(await page.locator("#live-generations").innerText(), /办公室/);
    // Trend: Shanghai-local today = the 00:00 and 01:00 buckets (11 + 22), even in a UTC browser.
    const trendLabel = await page.locator("#overview-trend svg").getAttribute("aria-label");
    assert.match(trendLabel, /今日累计调用 33 次/);
    assert.match(await page.locator("#screen-updated").innerText(), /^数据更新于 \d{2}:\d{2}:\d{2}$/);
    assert.equal(await page.locator("#screen-health").getAttribute("class"), "screen-health is-warn");
    assert.match(await page.locator("#screen-health").getAttribute("title"), /有渠道熔断/);
    const live = await page.locator("#live-metrics").innerText();
    assert.match(live, /QPS/);
    assert.match(live, /待网关记录/);
    assert.match(await page.locator("#provider-health").innerText(), /智谱/);
    assert.equal(count("POST /screen-api/auth/share-login"), 1);
    assert.deepEqual(errors, []);
  } finally {
    await context.close();
  }
});

test("parallel wake-ups share one in-flight refresh", async () => {
  const { context, page } = await openScreen(`/screen/${GOOD_CODE}`);
  try {
    await waitForPanels(page);
    const before = count("GET /screen-api/dashboard/overview");
    await Promise.all([pokeRefresh(page), pokeRefresh(page), pokeRefresh(page)]);
    await page.waitForTimeout(400);
    assert.ok(count("GET /screen-api/dashboard/overview") - before <= 1, "parallel wake-ups share one refresh");
  } finally {
    await context.close();
  }
});

test("an expired session re-logs in once with the share code, then a failing re-login returns to the login page", async () => {
  const { context, page, errors } = await openScreen(`/screen/${GOOD_CODE}`);
  try {
    await waitForPanels(page);
    mock.expireSessions();
    await pokeRefresh(page);
    await page.waitForFunction(() => document.querySelector("#screen-status")?.textContent === "已更新");
    await page.waitForTimeout(200);
    assert.equal(count("POST /screen-api/auth/share-login"), 2, "one silent re-login");
    assert.equal(await page.locator("#screen").isVisible(), true);

    mock.expireSessions();
    mock.setShareLogin(false);
    await pokeRefresh(page);
    await page.locator("#login").waitFor({ state: "visible" });
    assert.equal(await page.locator("#error").innerText(), "大屏链接无效或会话已失效");
    assert.equal(count("POST /screen-api/auth/share-login"), 3, "re-login is tried only once");
    assert.deepEqual(errors, []);
  } finally {
    await context.close();
  }
});

test("rate-limited share-login shows a clear message", async () => {
  const { context, page } = await openScreen(`/screen/${LIMITED_CODE}`);
  try {
    await page.locator("#error").filter({ hasText: "尝试过于频繁" }).waitFor();
    assert.equal(await page.locator("#screen").isVisible(), false);
  } finally {
    await context.close();
  }
});

test("credential login without a share code", async () => {
  const { context, page } = await openScreen("/screen/");
  try {
    await page.locator("#credential-fields").waitFor({ state: "visible" });
    await page.locator("#username").fill("lobby");
    await page.locator("#password").fill("wrong");
    await page.locator("#login-button").click();
    await page.locator("#error").filter({ hasText: "大屏链接无效或会话已失效" }).waitFor();
    await page.locator("#password").fill("lobby-pass");
    await page.locator("#login-button").click();
    await waitForPanels(page);
    await page.locator("#logout").click();
    await page.locator("#login").waitFor({ state: "visible" });
  } finally {
    await context.close();
  }
});
