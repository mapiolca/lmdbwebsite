import { access, readFile } from "node:fs/promises";
import { spawnSync } from "node:child_process";
import path from "node:path";
import { fileURLToPath } from "node:url";

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");

JSON.parse(await readFile(path.join(root, "content/site.json"), "utf8"));

const build = spawnSync(process.execPath, ["scripts/build-site.mjs"], {
  cwd: root,
  encoding: "utf8"
});

if (build.status !== 0) {
  process.stderr.write(build.stdout);
  process.stderr.write(build.stderr);
  process.exit(build.status ?? 1);
}

for (const file of [
  "dist/site/index.html",
  "dist/site/tarifs/index.html",
  "dist/site/sitemap.xml",
  "dist/dolibarr-website/pages/home.html",
  "resources/dolibarr-website/pages/home.html"
]) {
  await access(path.join(root, file));
}

const legacyModuleName = "lmdb" + "subscription";
const grep = spawnSync("rg", [legacyModuleName, ".", "--glob", "!dist/**"], {
  cwd: root,
  encoding: "utf8"
});

if (grep.status === 0) {
  process.stderr.write(grep.stdout);
  process.stderr.write(`Unexpected legacy module name ${legacyModuleName} found.\n`);
  process.exit(1);
}

console.log("Checks passed.");
