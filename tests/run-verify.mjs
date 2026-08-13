#!/usr/bin/env node
/**
 * Entry point for `pnpm wp:verify`.
 *
 * tests/verify.php runs INSIDE the wp-env container, so it cannot report on the
 * things most likely to be wrong before it ever starts: Docker not running, or
 * the environment never having been booted. Both surface from wp-env as stack
 * traces or compose errors that say nothing about what to do next. This checks
 * for them first and says the one sentence that helps.
 *
 * Exits non-zero on any failure so it works as a gate, not just a thing to read.
 */
import { spawnSync } from "node:child_process";

const PLUGIN_SLUG = "wordpress-plugin"; // how wp-env mounts this directory
const SCRIPT = `wp-content/plugins/${PLUGIN_SLUG}/tests/verify.php`;

/** Run a command, capturing output. Never throws on a non-zero exit. */
function run(cmd, args, opts = {}) {
  return spawnSync(cmd, args, {
    encoding: "utf8",
    shell: process.platform === "win32",
    ...opts,
  });
}

function die(message, hint) {
  console.error(`\n  ${message}`);
  if (hint) {
    console.error(`  ${hint}\n`);
  }
  process.exit(1);
}

// 1. Docker daemon. `docker info` fails fast when Desktop is not running,
//    whereas `docker --version` succeeds even then - it only reads the client.
const docker = run("docker", ["info", "--format", "{{.ServerVersion}}"]);
if (docker.status !== 0) {
  die(
    "Docker is not running.",
    "Start Docker Desktop, then: pnpm --filter @snowseo/wordpress-plugin wp:start"
  );
}

// 2. The environment itself. A booted wp-env answers `wp --version` in about a
//    second; a missing one fails here rather than midway through the suite.
const alive = run("npx", ["wp-env", "run", "cli", "wp", "--version"]);
if (alive.status !== 0) {
  const detail = `${alive.stderr || ""}${alive.stdout || ""}`.trim();
  die(
    "The wp-env environment is not running.",
    `Run: pnpm --filter @snowseo/wordpress-plugin wp:start${
      detail ? `\n\n  wp-env said:\n  ${detail.split("\n").slice(-3).join("\n  ")}` : ""
    }`
  );
}

// 3. The suite. Inherit stdio so results stream as they happen.
const result = spawnSync("npx", ["wp-env", "run", "cli", "wp", "eval-file", SCRIPT], {
  stdio: "inherit",
  shell: process.platform === "win32",
});

process.exit(result.status ?? 1);
