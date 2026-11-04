"""Exercise the downloadable installers with real local Git and a fake Docker CLI.

No network, real Docker daemon, system package installation or repository writes.
"""
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
REPOSITORY = "https://github.com/QianqianTech/Tokenhub.git"


class BootstrapCases:
    powershell = False
    powershell_executable = None

    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="tokenhub-bootstrap-")
        self.addCleanup(self.temp.cleanup)
        self.base = Path(self.temp.name).resolve()
        self.source = self.base / "remote"
        self.source.mkdir()
        self.destination = self.base / "installed source"
        self.capture = self.base / "arguments.json"
        self.mock_bin = self.base / "bin"
        self.mock_bin.mkdir()
        config = self.base / "gitconfig"
        config.write_text('[url "' + self.source.as_uri() + '"]\n\tinsteadOf = ' + REPOSITORY + '\n[protocol "file"]\n\tallow = always\n', encoding="utf-8")
        self.env = {
            **os.environ, "GIT_CONFIG_GLOBAL": str(config), "GIT_CONFIG_NOSYSTEM": "1",
            "PATH": str(self.mock_bin) + os.pathsep + os.environ["PATH"],
            "BOOTSTRAP_CAPTURE": str(self.capture), "BOOTSTRAP_PYTHON": sys.executable,
            "DOCKER_CALLS": str(self.base / "docker-calls"),
        }
        for name in ("GIT_DIR", "GIT_WORK_TREE", "GIT_INDEX_FILE", "GIT_CONFIG_COUNT", "GIT_CONFIG_PARAMETERS"):
            self.env.pop(name, None)
        docker = self.mock_bin / "docker"
        docker.write_text("#!" + sys.executable + '\n' + '''import os, sys
from pathlib import Path
with open(os.environ['DOCKER_CALLS'], 'a') as f:
    f.write(' '.join(sys.argv[1:]) + '\\n')
if os.environ.get('DOCKER_DOWN'):
    sys.exit(1)
args = sys.argv[1:]
if args == ['info', '--format', '{{.OSType}}']:
    print(os.environ.get('DOCKER_OS', 'linux'))
elif args[:1] == ['version']:
    print(os.environ.get('ENGINE_VERSION', '27.5.1'))
elif args[:2] == ['compose', 'version']:
    print(os.environ.get('COMPOSE_VERSION', 'v2.32.1'))
elif args != ['info']:
    sys.exit('Unexpected Docker mutation: ' + repr(args))
''', encoding="utf-8")
        docker.chmod(0o755)
        if os.name == "nt":
            (self.mock_bin / "docker.cmd").write_text('@"' + sys.executable + '" "' + str(docker) + '" %*\r\n', encoding="utf-8")
        self.write("deploy/docker/install.sh", '''#!/usr/bin/env bash
set -eu
"$BOOTSTRAP_PYTHON" -c 'import json,os,sys; open(os.environ["BOOTSTRAP_CAPTURE"],"w").write(json.dumps(sys.argv[1:]))' "$@"
printf 'fixture-config' > "$(dirname "$0")/.env"
exit "${INSTALL_EXIT:-0}"
''')
        self.write("deploy/docker/install.ps1", '''param([switch]$Build, [string]$Tag, [string]$Site, [int]$HttpPort, [int]$HttpsPort,
    [string]$Bind, [string]$AdminUser, [string]$VectorStore, [switch]$NoStart, [switch]$Yes)
$PSBoundParameters | ConvertTo-Json | Set-Content -LiteralPath $env:BOOTSTRAP_CAPTURE
Set-Content -LiteralPath (Join-Path $PSScriptRoot '.env') -Value 'fixture-config'
if ($env:INSTALL_EXIT) { exit ([int]$env:INSTALL_EXIT) }
exit 0
''')
        self.write("deploy/docker/compose.yml", "services: {}\n")
        self.write("docker/release/php/Dockerfile", "FROM scratch\n")
        self.write(".env.example", "APP_KEY=change-me\n")
        self.git("init", "--quiet")
        self.git("checkout", "-b", "main")
        self.git("add", ".")
        self.git("-c", "user.name=Test", "-c", "user.email=test@example.invalid", "commit", "-qm", "fixture")
        self.commit = self.git("rev-parse", "HEAD").strip()
        self.git("tag", "v-test")
        self.git("branch", "feature/install")

    def write(self, name, content):
        path = self.source / name
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(content, encoding="utf-8")

    def git(self, *args):
        result = subprocess.run(["git", "-C", str(self.source), *args], env=self.env, capture_output=True, text=True, check=True)
        return result.stdout

    def run_installer(self, *args, **env):
        if self.powershell:
            executable = self.powershell_executable or shutil.which("pwsh") or shutil.which("powershell")
            command = [executable, "-NoProfile", "-ExecutionPolicy", "Bypass", "-File", str(ROOT / "install.ps1"), "-Directory", str(self.destination)]
        else:
            command = ["bash", str(ROOT / "install.sh"), "--dir", str(self.destination)]
        return subprocess.run([*command, *args], cwd=self.base, env={**self.env, **env}, text=True, capture_output=True, timeout=60)

    def option(self, bash, ps):
        return ps if self.powershell else bash

    def assert_ok(self, result):
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)

    def assert_no_download(self):
        self.assertFalse(self.destination.exists())
        self.assertEqual(list(self.base.glob(".tokenhub-download.*")), [])
        self.assertFalse(self.capture.exists())

    def test_help_needs_no_docker_or_download(self):
        self.assert_ok(self.run_installer(self.option("--help", "-Help"), DOCKER_DOWN="1"))
        self.assert_no_download()

    def test_check_does_not_create_destination(self):
        self.assert_ok(self.run_installer(self.option("--check", "-Check")))
        self.assert_no_download()

    def test_download_only_checks_out_commit_without_docker(self):
        result = self.run_installer(self.option("--download-only", "-DownloadOnly"), self.option("--ref", "-Ref"), self.commit, DOCKER_DOWN="1")
        self.assert_ok(result)
        self.assertIn(self.commit, result.stdout)
        self.assertTrue((self.destination / ".git").is_dir())
        self.assertFalse((self.destination / "deploy/docker/.env").exists())
        self.assertFalse((self.base / "docker-calls").exists())

    def test_tags_and_slash_branches(self):
        for ref in ("v-test", "feature/install"):
            with self.subTest(ref=ref):
                self.destination = self.base / ref.replace("/", "-")
                self.assert_ok(self.run_installer(self.option("--download-only", "-DownloadOnly"), self.option("--ref", "-Ref"), ref))

    def test_missing_ref_leaves_no_partial_installation(self):
        result = self.run_installer(self.option("--download-only", "-DownloadOnly"), self.option("--ref", "-Ref"), "missing-ref")
        self.assertNotEqual(result.returncode, 0)
        self.assert_no_download()

    def test_incompatible_source_ref_is_not_installed(self):
        self.git("rm", "deploy/docker/compose.yml")
        self.git("-c", "user.name=Test", "-c", "user.email=test@example.invalid", "commit", "-qm", "incompatible ref")
        result = self.run_installer(self.option("--download-only", "-DownloadOnly"))
        self.assertNotEqual(result.returncode, 0)
        self.assert_no_download()

    def test_rejects_option_injection_ref(self):
        result = self.run_installer(self.option("--ref", "-Ref"), "--upload-pack=unexpected")
        self.assertNotEqual(result.returncode, 0)
        self.assert_no_download()

    def test_existing_directory_and_config_are_preserved(self):
        self.destination.mkdir()
        sentinel = self.destination / ".env"
        sentinel.write_text("keep-this-config")
        result = self.run_installer(self.option("--download-only", "-DownloadOnly"))
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(sentinel.read_text(encoding="utf-8"), "keep-this-config")
        self.assertFalse(self.capture.exists())

    def test_stopped_docker_and_old_versions_stop_before_download(self):
        for env in ({"DOCKER_DOWN": "1"}, {"ENGINE_VERSION": "23.0.0"}, {"COMPOSE_VERSION": "v2.19.9"}, {"DOCKER_OS": "windows"}):
            with self.subTest(env=env):
                self.assertNotEqual(self.run_installer(**env).returncode, 0)
                self.assert_no_download()

    def test_default_installer_arguments_and_commit_tag(self):
        self.assert_ok(self.run_installer())
        args = json.loads(self.capture.read_text(encoding="utf-8-sig"))
        tag = "source-" + self.commit[:12]
        if self.powershell:
            self.assertEqual(args["Tag"], tag)
            self.assertNotIn("Site", args)
            self.assertIn("Build", args)
        else:
            self.assertEqual(args, ["--build", "--tag", tag])

    def test_forwards_arguments_with_spaces_and_switches(self):
        args = (["-Site", "one.example.test, https://10.0.0.8", "-HttpPort", "8080", "-HttpsPort", "8443", "-Bind", "127.0.0.1", "-AdminUser", "owner", "-VectorStore", "mysql_fallback", "-NoStart", "-Yes"] if self.powershell else ["--site", "one.example.test, https://10.0.0.8", "--http-port", "8080", "--https-port", "8443", "--bind", "127.0.0.1", "--admin-user", "owner", "--vector-store", "mysql_fallback", "--no-start", "--yes"])
        self.assert_ok(self.run_installer(*args))
        captured = json.loads(self.capture.read_text(encoding="utf-8-sig"))
        if self.powershell:
            self.assertEqual(captured["Site"], "one.example.test, https://10.0.0.8")
            self.assertEqual(captured["HttpPort"], 8080)
            self.assertEqual(captured["VectorStore"], "mysql_fallback")
            self.assertIn("NoStart", captured)
            self.assertIn("Yes", captured)
        else:
            self.assertEqual(captured[3:], args)

    def test_install_failure_preserves_source_and_config(self):
        result = self.run_installer(INSTALL_EXIT="7")
        self.assertNotEqual(result.returncode, 0)
        self.assertTrue((self.destination / ".git").is_dir())
        self.assertIn("fixture-config", (self.destination / "deploy/docker/.env").read_text(encoding="utf-8"))
        self.assertEqual(list(self.base.glob(".tokenhub-download.*")), [])

    def test_rejects_invalid_port_and_missing_option_value(self):
        for args in ([self.option("--http-port", "-HttpPort"), "65536"], [self.option("--site", "-Site")], [self.option("--unknown", "-Unknown")]):
            with self.subTest(args=args):
                self.assertNotEqual(self.run_installer(*args).returncode, 0)
                self.assert_no_download()

    def test_actual_installer_generates_config_without_starting(self):
        for name in ("deploy/docker/install.sh", "deploy/docker/install.ps1", "deploy/docker/lib.sh", ".env.example"):
            self.write(name, (ROOT / name).read_text(encoding="utf-8"))
        self.git("add", ".")
        self.git("-c", "user.name=Test", "-c", "user.email=test@example.invalid", "commit", "-qm", "actual installer")
        args = (["-Site", "http://localhost", "-HttpPort", "8080", "-NoStart", "-Yes"] if self.powershell else ["--site", "http://localhost", "--http-port", "8080", "--no-start", "--yes"])
        self.assert_ok(self.run_installer(*args))
        contents = (self.destination / "deploy/docker/.env").read_text(encoding="utf-8")
        # Never include a generated .env (and its random credentials) in failure logs.
        for expected in ("APP_URL=http://localhost:8080", "SESSION_SECURE=false", "TOKENHUB_IMAGE_TAG=source-", "DB_PASSWORD=", "TOKENHUB_ADMIN_PASSWORD=", "我的设备"):
            self.assertTrue(expected in contents, "Generated config lost required marker: " + expected)
        self.assertTrue("APP_KEY=change-me" not in contents, "Template APP_KEY was not replaced")
        self.assertLess(len(contents), len((ROOT / ".env.example").read_text(encoding="utf-8")) * 2, "UTF-8 comments expanded during repeated writes")
        calls = (self.base / "docker-calls").read_text(encoding="utf-8")
        self.assertNotIn("build", calls)
        self.assertNotIn(" up ", calls)


@unittest.skipIf(os.name == "nt", "Bash entry is for Linux/macOS/WSL")
class BashBootstrapTest(BootstrapCases, unittest.TestCase):
    pass


@unittest.skipUnless(shutil.which("pwsh") or shutil.which("powershell"), "PowerShell not installed")
class PowerShellBootstrapTest(BootstrapCases, unittest.TestCase):
    powershell = True


@unittest.skipUnless(os.name == "nt" and shutil.which("powershell"), "Windows PowerShell 5.1 requires Windows")
class WindowsPowerShellBootstrapTest(BootstrapCases, unittest.TestCase):
    powershell = True
    powershell_executable = shutil.which("powershell")


if __name__ == "__main__":
    unittest.main()
