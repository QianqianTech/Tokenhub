import { chromium } from "playwright";
import { startMockServer } from "./mock-server.mjs";

export async function setup() {
  const mock = await startMockServer(0);
  const browser = await chromium.launch();
  return {
    mock,
    browser,
    async close() {
      await browser.close();
      await new Promise((resolve) => mock.server.close(resolve));
    }
  };
}

// Opens a page that records console errors and uncaught exceptions.
const inflight = new WeakMap();

function trackRequests(page) {
  const state = { count: 0, lastChange: Date.now() };
  inflight.set(page, state);
  const begin = () => { state.count += 1; state.lastChange = Date.now(); };
  const end = () => { state.count = Math.max(0, state.count - 1); state.lastChange = Date.now(); };
  page.on("request", begin);
  page.on("requestfinished", end);
  page.on("requestfailed", end);
}

export async function openPage(env, { viewport = { width: 1440, height: 900 }, colorScheme = "light" } = {}) {
  const context = await env.browser.newContext({ viewport, colorScheme });
  const page = await context.newPage();
  trackRequests(page);
  const errors = [];
  page.on("pageerror", (error) => errors.push(`pageerror: ${error.message}`));
  page.on("console", (message) => {
    if (message.type() === "error" && !/Failed to load resource/.test(message.text())) {
      errors.push(`console: ${message.text()}`);
    }
  });
  return { context, page, errors };
}

export async function login(page, baseUrl, { username = "admin", password = "admin-pass", hash = "" } = {}) {
  await page.goto(`${baseUrl}/admin/${hash}`);
  await page.locator("#username").fill(username);
  await page.locator("#password").fill(password);
  await page.locator("#login-button").click();
  await page.locator("#content").waitFor({ state: "visible" });
  await settle(page);
}

// Waits until the SPA has had no in-flight requests for a quiet period and no
// loading indicator is shown. (waitForLoadState("networkidle") resolves at once
// after in-page hash navigation, so it cannot be used here.)
export async function settle(page, { quietMs = 250, timeout = 8000 } = {}) {
  const state = inflight.get(page);
  const deadline = Date.now() + timeout;
  await page.waitForTimeout(50);
  while (Date.now() < deadline) {
    if (!state || (state.count === 0 && Date.now() - state.lastChange >= quietMs)) {
      break;
    }
    await page.waitForTimeout(50);
  }
  await page.waitForFunction(() => !document.querySelector("[data-loading='true']"), null, { timeout: 5000 }).catch(() => {});
}

export async function visibleTabs(page) {
  return page.evaluate(async () => {
    const domains = await import("/admin/assets/js/domains.js");
    const me = await fetch("/admin-api/me", { credentials: "same-origin" }).then((response) => response.json());
    return domains.domainsForUser(me.data.user).flatMap((domain) => domain.tabs.map((tab) => [domain.key, tab]));
  });
}
