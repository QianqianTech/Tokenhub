// Visibility-aware polling: runs while the APP is in the foreground, pauses in
// the background, refreshes at once when it comes back or the network
// returns, and backs off after failures (interval × 2, 4, 8).
const active = new Set();

export function createPoller(task, { interval = 30_000, maxInterval = interval * 8, skip = () => false } = {}) {
  let timer = 0;
  let failures = 0;
  let stopped = false;
  let running = null;

  const schedule = () => {
    window.clearTimeout(timer);
    if (stopped) {
      return;
    }
    const delay = Math.min(maxInterval, interval * 2 ** Math.min(failures, 3));
    timer = window.setTimeout(run, delay);
  };
  const run = async () => {
    if (stopped || running) {
      return running;
    }
    if (document.visibilityState === "hidden" || skip()) {
      schedule();
      return undefined;
    }
    running = (async () => {
      try {
        await task();
        failures = 0;
      } catch {
        failures += 1;
      } finally {
        running = null;
        schedule();
      }
    })();
    return running;
  };
  const wake = () => {
    if (document.visibilityState === "visible") {
      window.clearTimeout(timer);
      void run();
    }
  };
  document.addEventListener("visibilitychange", wake);
  window.addEventListener("online", wake);
  const poller = {
    refresh: () => {
      window.clearTimeout(timer);
      return run();
    },
    stop: () => {
      stopped = true;
      window.clearTimeout(timer);
      document.removeEventListener("visibilitychange", wake);
      window.removeEventListener("online", wake);
      active.delete(poller);
    },
    get failures() {
      return failures;
    }
  };
  active.add(poller);
  void run();
  return poller;
}

/** Stops every poller (called when leaving a page). */
export function stopAllPollers() {
  for (const poller of [...active]) {
    poller.stop();
  }
}
