// Optional APP lock: after the APP was in the background longer than the chosen
// time, the PIN is verified by the server before the APP is shown again.
import { requestPin } from "./stepup.js";

const KEY = "tokenhub-app-lock";
let hiddenAt = 0;
let locking = false;

export function lockSettings() {
  try {
    const value = JSON.parse(window.localStorage.getItem(KEY) || "{}");
    return { enabled: value.enabled === true, minutes: [1, 5, 15, 60].includes(Number(value.minutes)) ? Number(value.minutes) : 5 };
  } catch {
    return { enabled: false, minutes: 5 };
  }
}

export function saveLockSettings(settings) {
  try {
    window.localStorage.setItem(KEY, JSON.stringify(settings));
  } catch {
    // ignore
  }
}

async function lockNow(onFailed) {
  if (locking) {
    return;
  }
  locking = true;
  const shell = document.getElementById("app");
  shell?.classList.add("is-locked");
  try {
    const ok = await requestPin({ title: "解锁 TokenHub", pinSet: true, dismissible: false });
    if (!ok) {
      onFailed?.();
    }
  } finally {
    shell?.classList.remove("is-locked");
    locking = false;
  }
}

/** Installs the lock; isSignedIn tells whether there is anything to protect. */
export function installLock({ isSignedIn, onFailed }) {
  document.addEventListener("visibilitychange", () => {
    if (document.visibilityState === "hidden") {
      hiddenAt = Date.now();
      return;
    }
    const settings = lockSettings();
    if (settings.enabled && isSignedIn() && hiddenAt && Date.now() - hiddenAt >= settings.minutes * 60_000) {
      void lockNow(onFailed);
    }
  });
  return {
    // Cold start counts as "returning": the APP may have been killed in the background.
    onColdStart() {
      if (lockSettings().enabled && isSignedIn()) {
        void lockNow(onFailed);
      }
    }
  };
}
