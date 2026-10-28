import { adminErrorMessage, api, configureApi, login, setCsrf } from "./api.js?v=20261028-admin-ops-v1";
import { $, button, clear, el, setText, statusMessage } from "./dom.js?v=20260722-modal-close-label-v1";
import { renderDashboard as renderDashboardPage } from "./dashboard.js?v=20261028-admin-ops-v1";
import { resources as resourceConfig, resourcesForUser, valueLabel } from "./resources.js?v=20261028-admin-ops-v1";
import { renderResourcePage, setAdminViewer } from "./table.js?v=20261028-admin-ops-v1";
import { domainsForUser, parseAdminHash, writeAdminHash } from "./domains.js?v=20261028-admin-ops-v1";

const adminState = {
  page: "dashboard",
  domain: "operations",
  filters: {},
  user: null
};

const adminSidebarState = {
  mode: "expanded",
  mobileOpen: false,
  openKeys: null,
  transientOpenKey: "",
  scrollTop: 0,
  recentPages: [],
  shellReady: false
};

const ADMIN_THEME_COOKIE = "tokenhub_admin_theme";
const ADMIN_THEMES = ["dark", "light", "auto"];
const ADMIN_THEME_MEDIA_QUERY = window.matchMedia("(prefers-color-scheme: dark)");
const NAV_OPEN_COOKIE = "tokenhub_admin_nav_open";
const ADMIN_SIDEBAR_MODE_COOKIE = "tokenhub_admin_sidebar_mode";
const ADMIN_NAV_RECENT_COOKIE = "tokenhub_admin_nav_recent";
const ADMIN_SPECIAL_DOMAIN_TABS = new Set(["operations-status", "rag-settings"]);
const ADMIN_DOMAIN_ICON_PATHS = {
  operations: ["M4 4h6v6H4z", "M14 4h6v6h-6z", "M4 14h6v6H4z", "M14 14h6v6h-6z"],
  organization: ["M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2", "M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8z", "M22 21v-2a4 4 0 0 0-3-3.87", "M16 3.13a4 4 0 0 1 0 7.75"],
  models: ["M12 2 3 7l9 5 9-5-9-5z", "M3 12l9 5 9-5", "M3 17l9 5 9-5"],
  access: ["M15.5 7.5A5 5 0 1 1 6 9.7a5 5 0 0 1 9.5-2.2z", "M14 10l8 8", "M19 15l-2 2", "M21 17l-2 2"],
  usage: ["M4 20V10", "M10 20V4", "M16 20v-7", "M22 20H2"],
  rag: ["M4 5c0-1.66 3.58-3 8-3s8 1.34 8 3-3.58 3-8 3-8-1.34-8-3z", "M4 5v6c0 1.66 3.58 3 8 3s8-1.34 8-3V5", "M4 11v6c0 1.66 3.58 3 8 3s8-1.34 8-3v-6"],
  security: ["M5 11h14v10H5z", "M8 11V7a4 4 0 0 1 8 0v4", "M12 15v2"],
  system: ["M4 21v-7", "M4 10V3", "M12 21v-9", "M12 8V3", "M20 21v-5", "M20 12V3", "M1 14h6", "M9 8h6", "M17 16h6"]
};
const ADMIN_BRAND_DEFAULTS = {
  product_name: "TokenHub",
  admin_title: "TokenHub 管理后台",
  logo_url: "/admin/assets/brand/tokenhub-app-icon.svg",
  favicon_url: "/admin/assets/brand/favicon.ico"
};

function normalizeAdminTheme(value) {
  if (value === "a" || value === "b") return "light";
  if (value === "c") return "dark";
  return ADMIN_THEMES.includes(value) ? value : "auto";
}

function readThemeCookie() {
  const prefix = `${ADMIN_THEME_COOKIE}=`;
  const item = document.cookie
    .split(";")
    .map((part) => part.trim())
    .find((part) => part.startsWith(prefix));
  if (!item) {
    return "auto";
  }
  try {
    return normalizeAdminTheme(decodeURIComponent(item.slice(prefix.length)));
  } catch {
    return "auto";
  }
}

function writeThemeCookie(theme) {
  document.cookie = `${ADMIN_THEME_COOKIE}=${encodeURIComponent(normalizeAdminTheme(theme))}; Max-Age=31536000; Path=/admin; SameSite=Lax`;
}

function resolveAdminTheme(theme) {
  const selected = normalizeAdminTheme(theme);
  return selected === "auto" ? (ADMIN_THEME_MEDIA_QUERY.matches ? "dark" : "light") : selected;
}

function applyAdminTheme(theme, persist = false) {
  const selected = normalizeAdminTheme(theme);
  const resolved = resolveAdminTheme(selected);
  const shell = $("app");
  if (shell) {
    shell.classList.toggle("theme-dark", resolved === "dark");
    shell.classList.toggle("theme-light", resolved === "light");
  }
  document.querySelectorAll("[data-theme-choice]").forEach((control) => {
    const active = control.dataset.themeChoice === selected;
    control.classList.toggle("active", active);
    control.setAttribute("aria-pressed", active ? "true" : "false");
  });
  if (persist) {
    writeThemeCookie(selected);
  }
  return selected;
}

function adminInitThemeSwitcher() {
  let selected = applyAdminTheme(readThemeCookie());
  document.querySelectorAll("[data-theme-choice]").forEach((control) => {
    control.addEventListener("click", () => {
      selected = applyAdminTheme(control.dataset.themeChoice, true);
    });
  });
  ADMIN_THEME_MEDIA_QUERY.addEventListener("change", () => {
    if (selected === "auto") {
      applyAdminTheme(selected);
    }
  });
}

function adminSafeLocalAssetUrl(value) {
  const url = String(value || "").trim();
  if (url === "") {
    return "";
  }
  if (url.startsWith("/") && !url.startsWith("//")) {
    return url;
  }
  if (url.startsWith("data:image/") && url.includes(";base64,")) {
    return url;
  }
  return "";
}

function adminApplyBrandIdentity(settings = {}) {
  const productName = String(settings.product_name || ADMIN_BRAND_DEFAULTS.product_name).trim() || ADMIN_BRAND_DEFAULTS.product_name;
  const adminTitle = String(settings.admin_title || ADMIN_BRAND_DEFAULTS.admin_title).trim() || ADMIN_BRAND_DEFAULTS.admin_title;
  const logoUrl = adminSafeLocalAssetUrl(settings.logo_url || ADMIN_BRAND_DEFAULTS.logo_url);
  const faviconUrl = adminSafeLocalAssetUrl(settings.favicon_url || ADMIN_BRAND_DEFAULTS.favicon_url);

  document.title = adminTitle;
  const brand = document.querySelector(".brand");
  if (brand) {
    adminRenderBrandNode(brand, productName, logoUrl);
  }
  const loginBrand = $("login-brand");
  if (loginBrand) {
    adminRenderBrandNode(loginBrand, productName, logoUrl);
  }
  const loginTitle = $("login-title");
  if (loginTitle) {
    setText(loginTitle, adminTitle);
  }

  if (faviconUrl !== "") {
    let icon = document.querySelector('link[rel="icon"]');
    if (!icon) {
      icon = el("link", { rel: "icon" });
      document.head.append(icon);
    }
    icon.href = faviconUrl;
  }
}

function adminRenderBrandNode(node, productName, logoUrl) {
  clear(node);
  if (logoUrl !== "") {
    node.append(el("img", { className: "brand-logo", src: logoUrl, alt: "" }));
  }
  node.append(el("span", { text: productName }));
}

async function adminRefreshBrandIdentity() {
  try {
    const result = await api("/admin-api/system/settings-center");
    adminApplyBrandIdentity(result.data?.sections?.brand_identity || {});
  } catch {
    adminApplyBrandIdentity();
  }
}

async function adminRefreshPublicBrandIdentity() {
  try {
    const result = await api("/admin-api/system/brand-identity");
    adminApplyBrandIdentity(result.data || {});
  } catch {
    adminApplyBrandIdentity();
  }
}

function adminReadCookie(name) {
  const prefix = `${name}=`;
  const item = document.cookie
    .split(";")
    .map((part) => part.trim())
    .find((part) => part.startsWith(prefix));
  if (!item) {
    return null;
  }
  try {
    return decodeURIComponent(item.slice(prefix.length));
  } catch {
    return null;
  }
}

function adminWriteCookie(name, value) {
  try {
    document.cookie = `${name}=${encodeURIComponent(value)}; Max-Age=31536000; Path=/admin; SameSite=Lax`;
    return true;
  } catch {
    return false;
  }
}

function readNavOpenCookie() {
  const value = adminReadCookie(NAV_OPEN_COOKIE);
  if (value === null) {
    return null;
  }
  return new Set(value.split(",").map((key) => key.trim()).filter(Boolean));
}

function writeNavOpenCookie(openKeys) {
  adminWriteCookie(NAV_OPEN_COOKIE, [...openKeys].sort().join(","));
}

function adminDomainTabLabel(tabKey) {
  return resourceConfig[tabKey]?.label || {
    "operations-status": "运行状态",
    "rag-settings": "RAG 设置"
  }[tabKey] || tabKey;
}

function adminSvgElement(tag, attrs = {}) {
  const node = document.createElementNS("http://www.w3.org/2000/svg", tag);
  for (const [key, value] of Object.entries(attrs)) {
    node.setAttribute(key, String(value));
  }
  return node;
}

function adminCreateDomainIcon(domainKey) {
  const svg = adminSvgElement("svg", {
    class: "nav-domain-icon",
    viewBox: "0 0 24 24",
    fill: "none",
    stroke: "currentColor",
    "stroke-width": "1.8",
    "stroke-linecap": "round",
    "stroke-linejoin": "round",
    "aria-hidden": "true",
    focusable: "false"
  });
  for (const d of ADMIN_DOMAIN_ICON_PATHS[domainKey] || ADMIN_DOMAIN_ICON_PATHS.operations) {
    svg.append(adminSvgElement("path", { d }));
  }
  return svg;
}

function adminVisibleNavSections() {
  const allowedResources = new Set(resourcesForUser(adminState.user).map(([key]) => key));
  return domainsForUser(adminState.user).map((domain) => ({
    key: domain.key,
    label: domain.label,
    defaultOpen: domain.key === "operations",
    items: domain.tabs
      .filter((key) => allowedResources.has(key) || ADMIN_SPECIAL_DOMAIN_TABS.has(key))
      .map((key) => [key, resourceConfig[key] || { label: adminDomainTabLabel(key), group: domain.label }])
  })).filter((section) => section.items.length > 0);
}

function adminPrimarySectionKeyForPage(sections, page) {
  return sections.find((section) => section.items.some(([key]) => key === page))?.key || "";
}

function adminAllowedRecentPages(pages, sections = adminVisibleNavSections()) {
  const allowed = new Set(sections.flatMap((section) => section.items.map(([key]) => key)));
  return [...new Set(pages.map(String))].filter((key) => allowed.has(key)).slice(0, 5);
}

function adminReadSidebarPreferences() {
  const sections = adminVisibleNavSections();
  const sectionKeys = new Set(sections.map((section) => section.key));
  const openKeys = readNavOpenCookie();
  adminSidebarState.openKeys = openKeys === null
    ? null
    : new Set([...openKeys].filter((key) => sectionKeys.has(key)));
  adminSidebarState.recentPages = adminAllowedRecentPages((adminReadCookie(ADMIN_NAV_RECENT_COOKIE) || "").split(",").filter(Boolean), sections);
  adminSidebarState.transientOpenKey = adminPrimarySectionKeyForPage(sections, adminState.page);
  adminApplySidebarMode(adminReadCookie(ADMIN_SIDEBAR_MODE_COOKIE) === "compact" ? "compact" : "expanded");
}

function adminUpdateSidebarModeToggle() {
  const toggle = $("sidebar-mode-toggle");
  if (!toggle) {
    return;
  }
  const mobileClose = window.innerWidth <= 820 && adminSidebarState.mobileOpen;
  const compact = adminSidebarState.mode === "compact";
  toggle.textContent = mobileClose ? "×" : compact ? "›" : "‹";
  toggle.setAttribute("aria-label", mobileClose ? "关闭菜单" : compact ? "展开菜单" : "收起菜单");
  toggle.setAttribute("aria-pressed", compact ? "true" : "false");
}

function adminApplySidebarMode(mode, persist = false) {
  const selected = mode === "compact" ? "compact" : "expanded";
  adminSidebarState.mode = selected;
  const shell = $("app");
  shell?.classList.toggle("sidebar-compact", selected === "compact");
  shell?.classList.toggle("sidebar-expanded", selected === "expanded");
  adminUpdateSidebarModeToggle();
  if (persist) {
    adminWriteCookie(ADMIN_SIDEBAR_MODE_COOKIE, selected);
  }
}

function adminSectionIsOpen(section) {
  if (adminSidebarState.transientOpenKey === section.key) {
    return true;
  }
  const openKeys = adminSidebarState.openKeys;
  if (openKeys === null) {
    return section.defaultOpen === true;
  }
  return openKeys.has(section.key);
}

function adminToggleNavSection(sectionKey) {
  const sections = adminVisibleNavSections();
  const storedOpenKeys = readNavOpenCookie() ?? new Set();
  const openKeys = adminSidebarState.openKeys === null
    ? new Set(sections.filter((section) => section.defaultOpen === true).map((section) => section.key))
    : new Set(storedOpenKeys);
  const isOpen = openKeys.has(sectionKey) || adminSidebarState.transientOpenKey === sectionKey;
  if (isOpen) {
    openKeys.delete(sectionKey);
    if (adminSidebarState.transientOpenKey === sectionKey) {
      adminSidebarState.transientOpenKey = "";
    }
  } else {
    openKeys.add(sectionKey);
  }
  adminSidebarState.openKeys = openKeys;
  writeNavOpenCookie(openKeys);
  adminRenderNavList();
}

function adminEnsureSidebarShell() {
  if (adminSidebarState.shellReady && $("nav-list-host")) {
    return;
  }
  const nav = $("nav");
  clear(nav);
  nav.append(
    el("div", { id: "nav-recent", className: "nav-recent" }),
    el("div", { id: "nav-list-host", className: "nav-list-host", "aria-live": "polite" })
  );
  $("nav-list-host").addEventListener("scroll", adminCaptureNavScroll, { passive: true });
  adminSidebarState.shellReady = true;
}

function adminCaptureNavScroll() {
  adminSidebarState.scrollTop = $("nav-list-host")?.scrollTop || 0;
}

function adminRestoreNavScroll() {
  const list = $("nav-list-host");
  if (list) {
    list.scrollTop = adminSidebarState.scrollTop;
  }
}

function adminNavItemIsActive(section, item, activeSectionKey) {
  return section.key === activeSectionKey && adminState.page === item.key;
}

function adminRenderRecentNav() {
  const host = $("nav-recent");
  if (!host) {
    return;
  }
  clear(host);
  const recentKey = adminAllowedRecentPages(adminSidebarState.recentPages).find((key) => key !== adminState.page);
  if (!recentKey) {
    host.hidden = true;
    return;
  }
  const config = resourceConfig[recentKey];
  host.hidden = false;
  host.append(el("button", {
    type: "button",
    className: "nav-recent-button",
    title: `最近访问：${config?.label || recentKey}`,
    onClick: () => adminNavigate(recentKey)
  }, [
    el("span", { className: "nav-recent-kicker", text: "最近访问" }),
    el("span", { className: "nav-recent-label", text: config?.label || recentKey })
  ]));
}

function adminRenderNavList() {
  const host = $("nav-list-host");
  if (!host) {
    return;
  }
  adminCaptureNavScroll();
  clear(host);
  const sections = adminVisibleNavSections();
  const activeSectionKey = adminPrimarySectionKeyForPage(sections, adminState.page);
  for (const domain of sections) {
    const visibleItems = domain.items.map(([key, config]) => {
      const label = config.label || adminDomainTabLabel(key);
      return { key, config, label };
    });
    const isOpen = adminSectionIsOpen(domain);
    const active = domain.key === activeSectionKey;
    const listId = `nav-domain-${domain.key}`;
    const section = el("section", {
      className: `nav-section ${active ? "active" : ""} ${isOpen ? "open" : "collapsed"}`.trim(),
      dataset: { navSection: domain.key }
    });
    const toggle = el("button", {
      type: "button",
      className: "nav-section-toggle",
      title: domain.label,
      "aria-label": `${domain.label}，${isOpen ? "已展开" : "已收起"}`,
      "aria-expanded": isOpen ? "true" : "false",
      "aria-controls": listId,
      onClick: () => {
        if (adminSidebarState.mode === "compact" && window.innerWidth > 820) {
          adminApplySidebarMode("expanded");
          return;
        }
        adminToggleNavSection(domain.key);
      }
    }, [
      el("span", { className: "nav-section-symbol", "aria-hidden": "true" }, [adminCreateDomainIcon(domain.key)]),
      el("span", { className: "nav-section-label", text: domain.label }),
      el("span", { className: "nav-count", text: visibleItems.length, "aria-hidden": "true" }),
      el("span", { className: "nav-caret", text: isOpen ? "−" : "+", "aria-hidden": "true" })
    ]);
    const list = el("div", { id: listId, className: "nav-list" });
    for (const item of visibleItems) {
      const itemActive = item.key === adminState.page;
      list.append(el("button", {
        type: "button",
        className: `nav-button ${itemActive ? "active" : ""}`.trim(),
        text: item.label,
        "aria-current": itemActive ? "page" : null,
        onClick: () => adminNavigateDomain(domain.key, item.key)
      }));
    }
    section.append(toggle, list);
    host.append(section);
  }
  adminRestoreNavScroll();
}

function adminDomainForTab(tabKey) {
  return domainsForUser(adminState.user).find((domain) => domain.tabs.includes(tabKey));
}

async function adminNavigateDomain(domainKey, tabKey, fromHash = false) {
  const domain = domainsForUser(adminState.user).find((item) => item.key === domainKey);
  if (!domain || !domain.tabs.includes(tabKey)) return;
  if (tabKey !== adminState.page) {
    adminRecordRecentPage(adminState.page);
  }
  adminSidebarState.transientOpenKey = domain.key;
  adminCaptureNavScroll();
  adminState.domain = domain.key;
  adminState.page = tabKey;
  adminState.filters = {};
  if (!fromHash) writeAdminHash(domain.key, tabKey);
  if (adminSidebarState.mobileOpen) {
    adminCloseMobileNav({ restoreFocus: false });
  }
  await adminRender();
}

async function renderDomainPage(root) {
  clear(root);
  const domain = adminDomainForTab(adminState.page) || domainsForUser(adminState.user)[0];
  adminState.domain = domain?.key || "operations";
  const tabs = el("div", { className: "domain-tabs", role: "tablist" });
  const panel = el("section", { className: "domain-page" });
  for (const tabKey of domain?.tabs || []) {
    const label = adminDomainTabLabel(tabKey);
    tabs.append(el("button", { type: "button", className: `domain-tab ${tabKey === adminState.page ? "active" : ""}`, text: label, onClick: () => adminNavigateDomain(domain.key, tabKey) }));
  }
  root.append(tabs, panel);
  const content = panel;
  setAdminViewer(adminState.user);
  if (adminState.page === "dashboard") await renderDashboardPage(content, adminNavigate);
  else await renderResourcePage(content, adminState.page, adminState.filters);
}

function adminRenderNav() {
  adminEnsureSidebarShell();
  adminRenderRecentNav();
  adminRenderNavList();
}

function adminRecordRecentPage(page) {
  if (!page) {
    return;
  }
  adminSidebarState.recentPages = adminAllowedRecentPages([page, ...adminSidebarState.recentPages]);
  adminWriteCookie(ADMIN_NAV_RECENT_COOKIE, adminSidebarState.recentPages.join(","));
}

function adminOpenMobileNav() {
  adminSidebarState.mobileOpen = true;
  $("app")?.classList.add("sidebar-mobile-open");
  const backdrop = $("sidebar-backdrop");
  if (backdrop) {
    backdrop.hidden = false;
  }
  $("sidebar-toggle")?.setAttribute("aria-expanded", "true");
  document.body.classList.add("sidebar-lock-scroll");
  adminUpdateSidebarModeToggle();
}

function adminCloseMobileNav({ restoreFocus = true } = {}) {
  if (!adminSidebarState.mobileOpen && !document.body.classList.contains("sidebar-lock-scroll")) {
    return;
  }
  adminSidebarState.mobileOpen = false;
  $("app")?.classList.remove("sidebar-mobile-open");
  const backdrop = $("sidebar-backdrop");
  if (backdrop) {
    backdrop.hidden = true;
  }
  const toggle = $("sidebar-toggle");
  toggle?.setAttribute("aria-expanded", "false");
  document.body.classList.remove("sidebar-lock-scroll");
  adminUpdateSidebarModeToggle();
  if (restoreFocus) {
    toggle?.focus();
  }
}

function adminHandleSidebarKeydown(event) {
  if (event.key === "Escape" && adminSidebarState.mobileOpen) {
    adminCloseMobileNav();
  }
}

function adminInitSidebarControls() {
  $("sidebar-toggle")?.addEventListener("click", adminOpenMobileNav);
  $("sidebar-backdrop")?.addEventListener("click", () => adminCloseMobileNav());
  $("sidebar-mode-toggle")?.addEventListener("click", () => {
    if (window.innerWidth <= 820) {
      adminCloseMobileNav();
      return;
    }
    adminApplySidebarMode(adminSidebarState.mode === "compact" ? "expanded" : "compact", true);
  });
  document.addEventListener("keydown", adminHandleSidebarKeydown);
  window.addEventListener("resize", () => {
    if (window.innerWidth > 820 && adminSidebarState.mobileOpen) {
      adminCloseMobileNav({ restoreFocus: false });
    }
    adminUpdateSidebarModeToggle();
  });
  window.addEventListener("tokenhub-admin-navigate", (event) => {
    const detail = event instanceof CustomEvent ? event.detail || {} : {};
    if (typeof detail.page === "string" && resourceConfig[detail.page]) {
      adminNavigate(detail.page, detail.filters || {}).catch((error) => {
        setText($("global-error"), adminErrorMessage(error));
      });
    }
  });
}

async function adminNavigate(page, filters = {}) {
  if (page !== adminState.page) {
    adminRecordRecentPage(adminState.page);
  }
  const sections = adminVisibleNavSections();
  adminSidebarState.transientOpenKey = adminPrimarySectionKeyForPage(sections, page);
  adminCaptureNavScroll();
  adminState.page = page;
  adminState.domain = adminDomainForTab(page)?.key || adminState.domain;
  adminState.filters = filters;
  if (adminState.domain) writeAdminHash(adminState.domain, page);
  if (adminSidebarState.mobileOpen) {
    adminCloseMobileNav({ restoreFocus: false });
  }
  await adminRender();
}

async function adminRender() {
  adminRenderNav();
  const content = $("content");
  const domain = adminDomainForTab(adminState.page);
  setText($("page-title"), domain?.label || resourceConfig[adminState.page]?.label || "TokenHub");
  clear($("global-error"));
  await renderDomainPage(content);
}

function adminShowLogin() {
  adminSetLoginShell(true);
  $("login").hidden = false;
  $("content").hidden = true;
  $("logout").hidden = true;
  clear($("nav"));
  adminSidebarState.shellReady = false;
  adminCloseMobileNav({ restoreFocus: false });
}

function adminShowApp() {
  adminSetLoginShell(false);
  $("login").hidden = true;
  $("content").hidden = false;
  $("logout").hidden = false;
  setText($("current-user"), adminState.user ? `${adminState.user.username} / ${valueLabel("role", adminState.user.role)}` : "");
}

function adminSetLoginShell(isLogin) {
  const shell = $("app");
  if (!shell) {
    return;
  }
  shell.classList.toggle("is-login", isLogin);
  shell.classList.toggle("is-authenticated", !isLogin);
}

function adminRestoreHashRoute() {
  const route = parseAdminHash(adminState.user);
  adminState.domain = route.domainKey;
  adminState.page = route.tabKey;
  adminState.filters = {};
}

async function adminBoot() {
  adminInitSidebarControls();
  adminInitThemeSwitcher();
  adminApplyBrandIdentity();
  configureApi({
    onAuthRequired: () => adminShowLogin(),
    onError: (error) => {
      clear($("global-error")).append(statusMessage(adminErrorMessage(error), "error"));
    }
  });
  await adminRefreshPublicBrandIdentity();
  $("login-button").addEventListener("click", async () => {
    try {
      const result = await login($("username").value, $("password").value);
      adminState.user = result.data.user;
      adminRestoreHashRoute();
      adminReadSidebarPreferences();
      await adminRefreshBrandIdentity();
      adminShowApp();
      await adminRender();
    } catch (error) {
      setText($("login-error"), adminErrorMessage(error, "登录失败，请检查账号或密码"));
    }
  });
  $("logout").addEventListener("click", async () => {
    await api("/admin-api/logout", { method: "POST" }).catch(() => null);
    adminState.user = null;
    setCsrf("");
    adminShowLogin();
  });
  try {
    const csrf = await api("/admin-api/csrf");
    setCsrf(csrf.data.csrf_token);
    const me = await api("/admin-api/me");
    adminState.user = me.data.user;
    adminRestoreHashRoute();
    adminReadSidebarPreferences();
    await adminRefreshBrandIdentity();
    adminShowApp();
    await adminRender();
  } catch {
    adminShowLogin();
  }
}

window.addEventListener("hashchange", () => {
  if (!adminState.user) return;
  const route = parseAdminHash(adminState.user);
  adminNavigateDomain(route.domainKey, route.tabKey, true).catch((error) => setText($("global-error"), adminErrorMessage(error)));
});

adminBoot();
