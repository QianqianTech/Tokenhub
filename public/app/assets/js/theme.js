// Light / dark / follow-system theme, remembered per device.
const KEY = "tokenhub-app-theme";
const media = window.matchMedia("(prefers-color-scheme: dark)");

export function themePreference() {
  try {
    const value = window.localStorage.getItem(KEY);
    return value === "light" || value === "dark" ? value : "auto";
  } catch {
    return "auto";
  }
}

export function applyTheme(preference = themePreference(), persist = false) {
  const resolved = preference === "auto" ? (media.matches ? "dark" : "light") : preference;
  const shell = document.getElementById("app");
  shell?.classList.toggle("theme-dark", resolved === "dark");
  shell?.classList.toggle("theme-light", resolved === "light");
  document.querySelector('meta[name="theme-color"]')?.setAttribute("content", resolved === "dark" ? "#151A21" : "#ffffff");
  if (persist) {
    try {
      window.localStorage.setItem(KEY, preference);
    } catch {
      // private mode: the choice lasts for this visit only
    }
  }
}

media.addEventListener?.("change", () => applyTheme());
