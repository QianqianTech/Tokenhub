// The base shell uses only Pods shipped in the installed Capacitor npm packages.
// An empty local specs repository avoids downloading an unused global CDN index.
// This mode intentionally fails if a future plugin needs a registry-hosted Pod.
import { existsSync, mkdirSync, readFileSync, writeFileSync } from "node:fs";
import { join } from "node:path";
import { spawnSync } from "node:child_process";
import { fileURLToPath } from "node:url";

const root = fileURLToPath(new URL("..", import.meta.url));
const project = join(root, "ios/App");
const podfile = join(project, "Podfile");
if (!existsSync(podfile)) throw new Error("Generate the iOS project with npm run add:ios first");
const text = readFileSync(podfile, "utf8");
const dependencies = text.split("\n").filter((line) => /^\s*pod\s+['"]/.test(line));
if (!dependencies.length || dependencies.some((line) => !line.includes(":path"))) {
  throw new Error("Local sync requires every Pod to have a local :path dependency");
}
function run(command, args, options = {}) {
  const result = spawnSync(command, args, { cwd: root, stdio: "inherit", ...options });
  if (result.error) throw result.error;
  if (result.status !== 0) process.exit(result.status ?? 1);
}
const specs = join(root, ".podspecs/tokenhub-local-specs");
if (!existsSync(join(specs, ".git"))) {
  mkdirSync(specs, { recursive: true });
  writeFileSync(join(specs, "README.md"), "Local-only TokenHub specs index. All base Pods come from node_modules.\n");
  run("git", ["init", "--quiet", specs]);
  run("git", ["-C", specs, "add", "README.md"]);
  run("git", ["-C", specs, "-c", "user.name=TokenHub Build", "-c", "user.email=build@tokenhub.invalid", "commit", "--quiet", "-m", "Initialize local-only specs index"]);
}
const source = 'source "file://#{File.expand_path(\'../../.podspecs/tokenhub-local-specs\', __dir__)}" if ENV["TOKENHUB_PODS_LOCAL_ONLY"] == "1"';
if (!text.includes("TOKENHUB_PODS_LOCAL_ONLY")) writeFileSync(podfile, source + "\n" + text);
run(process.execPath, [join(root, "scripts/install-plugin.mjs"), "ios"]);
run(process.execPath, [join(root, "node_modules/@capacitor/cli/bin/capacitor"), "sync", "ios"], {
  env: {
    ...process.env,
    TOKENHUB_PODS_LOCAL_ONLY: "1",
    CP_HOME_DIR: join(root, ".podspecs/cocoapods-home"),
    COCOAPODS_DISABLE_STATS: "true",
    COCOAPODS_SKIP_UPDATE_MESSAGE: "true",
  },
});
