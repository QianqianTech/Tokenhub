import { existsSync } from "node:fs";
import { join } from "node:path";
import { spawnSync } from "node:child_process";
import { fileURLToPath } from "node:url";

const root = fileURLToPath(new URL("..", import.meta.url));
const platform = process.argv[2];
if (!["ios", "android"].includes(platform)) throw new Error("Expected ios or android");
let status = 0;
if (!existsSync(join(root, platform))) {
  const added = spawnSync(process.execPath, [join(root, "node_modules/@capacitor/cli/bin/capacitor"), "add", platform], { cwd: root, stdio: "inherit" });
  if (added.error) throw added.error;
  status = added.status ?? 1;
  if (!existsSync(join(root, platform))) process.exit(status || 1);
}
const installed = spawnSync(process.execPath, [join(root, "scripts/install-plugin.mjs"), platform], { cwd: root, stdio: "inherit" });
if (installed.error) throw installed.error;
process.exit(installed.status || status);
