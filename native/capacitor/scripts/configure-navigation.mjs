// Writes server.allowNavigation in capacitor.config.json before `cap sync`.
//
//   TOKENHUB_ALLOWED_HOSTS="tokenhub.example.com,*.corp.example.com" npm run sync
//
// A company build lists its own TokenHub hosts: the shell then refuses to load
// any other site in its web view (Capacitor opens it in the system browser).
// Without the variable the generic build keeps "*" because users type in the
// address of their own self-hosted server; the /app/ page still sends every
// link that leaves the server to the system browser (native-bridge.js), and
// credentials stay keyed by the page's real origin in the native store.
import { readFileSync, writeFileSync } from "node:fs";
import { fileURLToPath } from "node:url";

const file = fileURLToPath(new URL("../capacitor.config.json", import.meta.url));
const config = JSON.parse(readFileSync(file, "utf8"));
const raw = String(process.env.TOKENHUB_ALLOWED_HOSTS || "").trim();
const hosts = raw === "" ? [] : raw.split(",").map((h) => h.trim().toLowerCase()).filter(Boolean);
for (const host of hosts) {
  if (!/^(\*\.)?[a-z0-9-]+(\.[a-z0-9-]+)*(:\d+)?$/.test(host)) {
    console.error(`Invalid host in TOKENHUB_ALLOWED_HOSTS: ${host}`);
    process.exit(1);
  }
}
config.server = config.server || {};
if (hosts.length > 0) {
  config.server.allowNavigation = hosts;
  console.log(`allowNavigation restricted to: ${hosts.join(", ")}`);
} else {
  config.server.allowNavigation = ["*"];
  console.warn("TOKENHUB_ALLOWED_HOSTS is not set: generic build, the shell may open any HTTPS TokenHub server.");
}
writeFileSync(file, JSON.stringify(config, null, 2) + "\n");
