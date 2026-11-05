"""Run both upgrade entry points against a local Git remote and an isolated fake deployment."""

import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import unittest


ROOT = Path(__file__).resolve().parents[2]


class UpgradeCases:
    powershell = None

    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="tokenhub-upgrade-")
        self.addCleanup(self.temp.cleanup)
        self.base = Path(self.temp.name).resolve()
        self.remote = self.base / "remote"
        self.remote.mkdir()
        self.root = self.base / "existing deployment"
        self.events = self.base / "events"
        fake_bin = self.base / "bin"
        fake_bin.mkdir()
        fake_docker = fake_bin / "docker"
        fake_docker.write_text("#!" + sys.executable + "\n" + '''import sys
args = sys.argv[1:]
if args[0] == 'version':
    print('linux/arm64')
elif args == ['compose', 'version', '--short']:
    print('v2.30.0')
elif args[0] != 'compose' or args[-2:] != ['config', '-q']:
    sys.exit('Unexpected Docker call: ' + repr(args))
''', encoding="utf-8")
        fake_docker.chmod(0o755)
        if os.name == "nt":
            (fake_bin / "docker.cmd").write_text(f'@"{sys.executable}" "{fake_docker}" %*\n', encoding="utf-8")
        gitconfig = self.base / "gitconfig"
        gitconfig.write_text('[url "' + self.remote.as_uri() + '"]\n'
                             '\tinsteadOf = https://github.com/QianqianTech/Tokenhub.git\n'
                             '[protocol "file"]\n\tallow = always\n', encoding="utf-8")
        self.env = {**os.environ, "PATH": str(fake_bin) + os.pathsep + os.environ["PATH"],
                    "GIT_CONFIG_GLOBAL": str(gitconfig), "GIT_CONFIG_NOSYSTEM": "1", "EVENTS": str(self.events)}
        for key in ("GIT_DIR", "GIT_WORK_TREE", "GIT_INDEX_FILE", "GIT_CONFIG_COUNT", "GIT_CONFIG_PARAMETERS",
                    "COMPOSE_PROJECT_NAME", "TOKENHUB_IMAGE_PREFIX", "TOKENHUB_IMAGE_TAG", "TOKENHUB_BUILD"):
            self.env.pop(key, None)
        self.git(self.remote, "init", "--quiet")
        self.put(".gitignore", "deploy/docker/.env\n")
        for name in ("compose.yml", "compose.build.yml"):
            self.put("deploy/docker/" + name, "services: {}\n")
        self.put("VERSION", "1.0.0\n")
        self.put("deploy/docker/backup.sh", '''#!/usr/bin/env bash
printf 'backup:%s\\n' "$(cat "$(dirname "$0")/../../VERSION")" >> "$EVENTS"
exit "${BACKUP_EXIT:-0}"
''')
        self.put("deploy/docker/upgrade.sh", '''#!/usr/bin/env bash
printf 'upgrade:%s\\n' "$*" >> "$EVENTS"
''')
        self.put("deploy/docker/verify.sh", '''#!/usr/bin/env bash
printf 'verify\\n' >> "$EVENTS"
exit "${VERIFY_EXIT:-0}"
''')
        self.put("deploy/docker/backup.ps1", '''param([int]$Keep)
$version = (Get-Content -LiteralPath (Join-Path $PSScriptRoot '../../VERSION')).Trim()
Add-Content -LiteralPath $env:EVENTS "backup:$version"
exit ([int]$env:BACKUP_EXIT)
''')
        self.put("deploy/docker/upgrade.ps1", '''param([string]$Tag, [switch]$Build, [switch]$SkipBackup)
$arguments = if ($Build -and $SkipBackup) { "--build --tag $Tag --skip-backup" } else { "--tag $Tag" }
Add-Content -LiteralPath $env:EVENTS "upgrade:$arguments"
exit 0
''')
        self.put("deploy/docker/verify.ps1", '''Add-Content -LiteralPath $env:EVENTS 'verify'
exit ([int]$env:VERIFY_EXIT)
''')
        self.commit("installed version")
        self.git(self.base, "clone", "--quiet", str(self.remote), str(self.root))
        self.previous = self.git(self.root, "rev-parse", "HEAD")
        self.put("VERSION", "1.0.1\n")
        self.commit("target version")
        self.target = self.git(self.remote, "rev-parse", "HEAD")
        self.git(self.remote, "tag", "v1.0.1")
        self.env_file = self.root / "deploy/docker/.env"
        self.write_config()

    def write_config(self, prefix=""):
        self.config = f'TOKENHUB_IMAGE_TAG=1.0.0\nTOKENHUB_IMAGE_PREFIX={prefix}\n# 保留原有配置\n'.encode()
        self.env_file.write_bytes(self.config)

    def git(self, path, *args):
        return subprocess.run(["git", "-C", str(path), *args], env=self.env, check=True,
                              capture_output=True, text=True).stdout.strip()

    def put(self, path, contents):
        target = self.remote / path
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text(contents, encoding="utf-8")

    def commit(self, message):
        self.git(self.remote, "add", ".")
        self.git(self.remote, "-c", "user.name=Test", "-c", "user.email=test@example.invalid", "commit", "-qm", message)

    def upgrade(self, mode, target, *, check=False, yes=True, **env):
        if self.powershell:
            cmd = [self.powershell, "-NoProfile", "-ExecutionPolicy", "Bypass", "-File", str(ROOT / "upgrade.ps1"),
                   "-Directory", str(self.root), "-" + mode.capitalize(), target]
            cmd += ["-Check"] if check else ["-Yes"] if yes else []
        else:
            cmd = ["bash", str(ROOT / "upgrade.sh"), "--dir", str(self.root), "--" + mode, target]
            cmd += ["--check"] if check else ["--yes"] if yes else []
        return subprocess.run(cmd, cwd=self.base, env={**self.env, **env}, input="", text=True, capture_output=True, timeout=30)

    def assert_ok(self, result):
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)

    def test_registry_preflight_and_confirmation_do_not_upgrade(self):
        self.write_config("docker.io/leofan2022/")
        self.assert_ok(self.upgrade("tag", "1.0.1", check=True))
        self.assertNotEqual(self.upgrade("tag", "1.0.1", yes=False).returncode, 0)
        self.assertFalse(self.events.exists())
        self.assertEqual(self.env_file.read_bytes(), self.config)

    def test_registry_upgrade_propagates_failed_verification(self):
        self.write_config("docker.io/leofan2022/")
        result = self.upgrade("tag", "1.0.1", VERIFY_EXIT="1")
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("verification failed", result.stderr)
        self.assertEqual(self.events.read_text().splitlines(), ["upgrade:--tag 1.0.1", "verify"])
        self.assertEqual(self.git(self.root, "rev-parse", "HEAD"), self.previous)

    def test_source_backs_up_old_release_then_builds_new_tag(self):
        self.assert_ok(self.upgrade("ref", "v1.0.1"))
        self.assertEqual(self.events.read_text().splitlines(), ["backup:1.0.0", f"upgrade:--build --tag source-{self.target[:12]} --skip-backup", "verify"])
        self.assertEqual(self.git(self.root, "rev-parse", "HEAD"), self.target)
        self.assertEqual(self.env_file.read_bytes(), self.config)

    def test_source_backup_failure_keeps_original_checkout(self):
        result = self.upgrade("ref", "v1.0.1", BACKUP_EXIT="1")
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("Backup failed", result.stderr)
        self.assertEqual(self.events.read_text().splitlines(), ["backup:1.0.0"])
        self.assertEqual(self.git(self.root, "rev-parse", "HEAD"), self.previous)

    def test_source_dirty_checkout_and_wrong_mode_do_not_run_backup(self):
        (self.root / "VERSION").write_text("local change\n", encoding="utf-8")
        result = self.upgrade("ref", "v1.0.1")
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("local changes", result.stderr)
        self.assertNotEqual(self.upgrade("tag", "1.0.1").returncode, 0)
        self.assertFalse(self.events.exists())


@unittest.skipIf(os.name == "nt", "Bash entry runs on Linux, macOS or WSL")
class BashUpgradeTest(UpgradeCases, unittest.TestCase):
    pass


@unittest.skipUnless(shutil.which("pwsh") or shutil.which("powershell"), "PowerShell not installed")
class PowerShellUpgradeTest(UpgradeCases, unittest.TestCase):
    powershell = shutil.which("pwsh") or shutil.which("powershell")
