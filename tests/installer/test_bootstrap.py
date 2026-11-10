"""Exercise the downloadable installers with real local Git and a fake Docker CLI.

No network, real Docker daemon, system package installation or repository writes.
"""
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tarfile
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
calls = Path(os.environ['DOCKER_CALLS'])
with calls.open('a') as f:
    f.write(' '.join(sys.argv[1:]) + '\\n')
if os.environ.get('DOCKER_DOWN'):
    sys.exit(1)
args = sys.argv[1:]
if args == ['info', '--format', '{{.OSType}}']:
    print(os.environ.get('DOCKER_OS', 'linux'))
elif args[:1] == ['version']:
    if '{{.Server.Arch}}' in args:
        print(os.environ.get('DOCKER_ARCH', 'amd64'))
    else:
        print(os.environ.get('ENGINE_VERSION', '27.5.1'))
elif args[:2] == ['compose', 'version']:
    if os.environ.get('COMPOSE_DOWN'):
        sys.exit(1)
    print(os.environ.get('COMPOSE_VERSION', 'v2.32.1'))
elif args == ['buildx', 'version']:
    sys.exit(1 if os.environ.get('BUILDX_DOWN') else 0)
elif args[:2] == ['image', 'inspect'] and '--format' in args:
    if not os.environ.get('LOCAL_IMAGE_ARCH'):
        sys.exit(1)
    print(os.environ['LOCAL_IMAGE_ARCH'])
elif args[:2] == ['ps', '-aq']:
    print(os.environ.get('EXISTING_CONTAINERS', ''))
elif args[:3] == ['volume', 'ls', '-q']:
    print(os.environ.get('EXISTING_VOLUMES', ''))
elif os.environ.get('DEPLOY_FIXTURE'):
    if args[0] == 'compose':
        if 'pull' in args:
            attempts = sum(' pull' in line for line in calls.read_text().splitlines())
            if attempts <= int(os.environ.get('PULL_FAILURES', '0')):
                sys.exit('registry-1.docker.io: i/o timeout')
        elif 'build' in args and os.environ.get('BUILD_DOWN'):
            sys.exit('failed to resolve source metadata')
        elif 'ps' in args:
            print(args[-1])
        elif not any(command in args for command in ('build', 'up', 'exec', 'logs')):
            sys.exit('Unexpected Compose command: ' + repr(args))
    elif args[0] == 'inspect':
        print('exited 0' if args[-1] == 'init' else 'running 0 healthy')
    elif args[:2] not in (['network', 'inspect'], ['image', 'inspect']) and args != ['info']:
        sys.exit('Unexpected Docker command: ' + repr(args))
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

    def run_deploy_installer(self, *args, **env):
        kit = self.source / "deploy/docker"
        if self.powershell:
            executable = self.powershell_executable or shutil.which("pwsh") or shutil.which("powershell")
            command = [executable, "-NoProfile", "-ExecutionPolicy", "Bypass", "-File", str(kit / "install.ps1")]
        else:
            command = ["bash", str(kit / "install.sh")]
        return subprocess.run([*command, *args], cwd=self.source, env={**self.env, **env}, text=True, capture_output=True, timeout=60)

    def use_actual_installer(self):
        for name in ("deploy/docker/install.sh", "deploy/docker/install.ps1", "deploy/docker/lib.sh", ".env.example"):
            self.write(name, (ROOT / name).read_text(encoding="utf-8"))
        self.git("add", ".")
        self.git("-c", "user.name=Test", "-c", "user.email=test@example.invalid", "commit", "-qm", "actual installer")

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

    def test_interrupted_git_fetch_retries_with_command_local_http11(self):
        real_git = shutil.which("git")
        self.assertIsNotNone(real_git)
        shim = self.mock_bin / "git"
        shim.write_text("#!" + sys.executable + "\n" + '''import os, subprocess, sys
args = sys.argv[1:]
if 'fetch' in args and 'http.version=HTTP/1.1' not in args:
    sys.exit('RPC failed; curl 92 HTTP/2 stream was not closed cleanly; fatal: early EOF')
sys.exit(subprocess.call([os.environ['BOOTSTRAP_REAL_GIT'], *args]))
''', encoding="utf-8")
        shim.chmod(0o755)
        if os.name == "nt":
            (self.mock_bin / "git.cmd").write_text('@"' + sys.executable + '" "' + str(shim) + '" %*\r\n', encoding="utf-8")
        config = Path(self.env["GIT_CONFIG_GLOBAL"])
        original_config = config.read_bytes()
        result = self.run_installer(self.option("--download-only", "-DownloadOnly"), BOOTSTRAP_REAL_GIT=real_git)
        self.assert_ok(result)
        self.assertIn("Retrying once over HTTP/1.1", result.stdout)
        self.assertIn(self.commit, result.stdout)
        self.assertEqual(config.read_bytes(), original_config)

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
        self.use_actual_installer()
        args = (["-Site", "http://localhost", "-HttpPort", "8080", "-NoStart", "-Yes"] if self.powershell else ["--site", "http://localhost", "--http-port", "8080", "--no-start", "--yes"])
        self.assert_ok(self.run_installer(*args, BUILDX_DOWN="1"))
        contents = (self.destination / "deploy/docker/.env").read_text(encoding="utf-8")
        # Never include a generated .env (and its random credentials) in failure logs.
        for expected in ("APP_URL=http://localhost:8080", "SESSION_SECURE=false", "TOKENHUB_IMAGE_TAG=source-", "DB_PASSWORD=", "TOKENHUB_ADMIN_PASSWORD=", "我的设备"):
            self.assertTrue(expected in contents, "Generated config lost required marker: " + expected)
        self.assertTrue("APP_KEY=change-me" not in contents, "Template APP_KEY was not replaced")
        self.assertLess(len(contents), len((ROOT / ".env.example").read_text(encoding="utf-8")) * 2, "UTF-8 comments expanded during repeated writes")
        calls = (self.base / "docker-calls").read_text(encoding="utf-8")
        self.assertNotIn("build", calls)
        self.assertNotIn(" up ", calls)

    def test_archive_preflight_does_not_write_config(self):
        self.use_actual_installer()
        args = [self.option("--check", "-Check"), self.option("--build", "-Build")]
        self.assert_ok(self.run_deploy_installer(*args))
        for settings, diagnostic in (({"COMPOSE_DOWN": "1"}, "Compose"), ({"BUILDX_DOWN": "1"}, "Buildx")):
            result = self.run_deploy_installer(*args, **settings)
            self.assertNotEqual(result.returncode, 0)
            self.assertIn(diagnostic, result.stdout + result.stderr)
        self.assertFalse((self.source / "deploy/docker/.env").exists())

    def test_https_site_port_is_published_without_changing_container_listener(self):
        self.use_actual_installer()
        args = [self.option("--site", "-Site"), "https://203.0.113.9:8443",
                self.option("--https-port", "-HttpsPort"), "8443", self.option("--no-start", "-NoStart")]
        self.assert_ok(self.run_deploy_installer(*args))
        config = (self.source / "deploy/docker/.env").read_text(encoding="utf-8").splitlines()
        for setting in ("APP_URL=https://203.0.113.9:8443", "TOKENHUB_SITE_ADDRESS=https://203.0.113.9",
                        "TOKENHUB_CADDY_TLS=tls internal", "TOKENHUB_HTTPS_PORT=8443", "SESSION_SECURE=true",
                        "TOKENHUB_HTTP_REDIRECT_URL=https://203.0.113.9:8443"):
            self.assertTrue(setting in config, "Missing site setting: " + setting)

    def test_docker_pull_and_build_failures_keep_config_for_retry(self):
        self.use_actual_installer()
        args = [self.option("--build", "-Build"), self.option("--site", "-Site"), "http://localhost"]
        calls = Path(self.env["DOCKER_CALLS"])
        config = self.source / "deploy/docker/.env"
        blocked = self.run_deploy_installer(*args, DEPLOY_FIXTURE="1", PULL_FAILURES="2")
        self.assertNotEqual(blocked.returncode, 0)
        self.assertIn("Image pull failed", blocked.stdout + blocked.stderr)
        first_calls = calls.read_text()
        self.assertEqual(first_calls.count(" pull mysql redis qdrant\n"), 2)
        self.assertNotIn(" build\n", first_calls)
        self.assertNotIn(" up ", first_calls)
        original_config = config.read_bytes()

        calls.unlink()
        blocked = self.run_deploy_installer(*args, DEPLOY_FIXTURE="1", PULL_FAILURES="1", BUILD_DOWN="1")
        self.assertNotEqual(blocked.returncode, 0)
        self.assertIn("Source build failed", blocked.stdout + blocked.stderr)
        retry_calls = calls.read_text()
        self.assertEqual(retry_calls.count(" pull mysql redis qdrant\n"), 2)
        self.assertLess(retry_calls.rindex(" pull mysql redis qdrant\n"), retry_calls.index(" build\n"))
        self.assertNotIn(" up ", retry_calls)
        self.assertTrue(config.read_bytes() == original_config, "A failed build changed the deployment .env")

        calls.unlink()
        self.assert_ok(self.run_deploy_installer(*args, DEPLOY_FIXTURE="1"))
        self.assertIn("up -d --remove-orphans --no-build --pull never", calls.read_text())
        self.assertTrue(config.read_bytes() == original_config, "Retry changed the deployment .env")

    def test_preloaded_images_skip_tokenhub_pulls_and_build(self):
        self.use_actual_installer()
        args = [self.option("--site", "-Site"), "http://localhost"]
        self.assert_ok(self.run_deploy_installer(*args, DEPLOY_FIXTURE="1"))
        calls = Path(self.env["DOCKER_CALLS"]).read_text()
        self.assertIn("image inspect tokenhub-php:local", calls)
        self.assertNotIn(" pull", calls)
        self.assertNotIn(" build\n", calls)
        self.assertIn("up -d --remove-orphans --no-build\n", calls)


@unittest.skipIf(os.name == "nt", "Bash entry is for Linux/macOS/WSL")
class BashBootstrapTest(BootstrapCases, unittest.TestCase):
    def use_source_archive(self):
        self.write("VERSION", "1.0.4\n")
        for name in ("lib.sh", "README.md", "verify.sh", "verify.ps1", "backup.sh", "backup.ps1",
                     "restore.sh", "upgrade.sh", "upgrade.ps1"):
            self.write("deploy/docker/" + name, "# deployment fixture\n")
        archive = self.base / "source.tar.gz"
        with tarfile.open(archive, "w:gz") as bundle:
            for name in ("deploy", "docker", ".env.example", "VERSION"):
                bundle.add(self.source / name, arcname="Tokenhub-main/" + name)
        self.env["INSTALL_ARCHIVE_FIXTURE"] = str(archive)
        self.env["INSTALL_FILES_FIXTURE"] = str(self.source)
        curl = self.mock_bin / "curl"
        curl.write_text('''#!/usr/bin/env bash
set -eu
while [ "$#" -gt 0 ]; do
    if [ "$1" != --output ]; then shift; continue; fi
    case "$3" in
      https://codeload.github.com/QianqianTech/Tokenhub/tar.gz/*)
        cp "$INSTALL_ARCHIVE_FIXTURE" "$2" ;;
      https://raw.githubusercontent.com/QianqianTech/Tokenhub/main/*)
        file="${3#https://raw.githubusercontent.com/QianqianTech/Tokenhub/main/}"
        cp "$INSTALL_FILES_FIXTURE/$file" "$2" ;;
      *) exit 2 ;;
    esac
    shift 3
done
''', encoding="utf-8")
        curl.chmod(0o755)
        git = self.mock_bin / "git"
        git.write_text("#!/bin/sh\nexit 97\n", encoding="utf-8")
        git.chmod(0o755)

    def test_quick_download_uses_no_git_or_docker(self):
        self.use_source_archive()
        self.assert_ok(self.run_installer("--quick", "--download-only", DOCKER_DOWN="1"))
        self.assertFalse((self.destination / ".git").exists())
        self.assertFalse((self.base / "docker-calls").exists())
        self.assertTrue((self.destination / "deploy/docker/install.sh").is_file())

    def test_quick_uses_native_local_images_and_defaults_to_loopback(self):
        self.use_source_archive()
        self.assert_ok(self.run_installer("--quick", LOCAL_IMAGE_ARCH="amd64", BUILDX_DOWN="1"))
        args = json.loads(self.capture.read_text())
        self.assertEqual(args, ["--tag", "1.0.4", "--site", "http://localhost", "--bind", "127.0.0.1",
                                "--http-port", "8080", "--https-port", "8443"])
        self.assertFalse((self.destination / "docker/release/php/Dockerfile").exists())
        self.assertTrue(os.access(self.destination / "deploy/docker/verify.sh", os.X_OK))

    def test_quick_uses_published_images_on_arm64(self):
        self.use_source_archive()
        self.assert_ok(self.run_installer("--quick", "--public-ip", DOCKER_ARCH="arm64", BUILDX_DOWN="1"))
        self.assertEqual(json.loads(self.capture.read_text()),
                         ["--image-prefix", "docker.io/leofan2022/", "--tag", "1.0.4", "--https-port", "8443", "--public-ip"])

    def test_quick_builds_on_amd64_without_native_images(self):
        self.use_source_archive()
        self.assert_ok(self.run_installer("--quick", LOCAL_IMAGE_ARCH="arm64"))
        args = json.loads(self.capture.read_text())
        self.assertEqual(args[:2], ["--build", "--tag"])
        self.assertRegex(args[2], r"^source-[a-f0-9]{12}$")
        self.assertTrue((self.destination / "docker/release/php/Dockerfile").is_file())

    def test_quick_protects_an_existing_project_even_in_a_new_directory(self):
        self.use_source_archive()
        result = self.run_installer("--quick", EXISTING_VOLUMES="tokenhub_mysql_97_data")
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("existing TokenHub project or data volume", result.stderr)
        self.assert_no_download()

    def test_public_ip_opt_in_configures_https_and_preserves_default_bind(self):
        self.use_actual_installer()
        ip = self.mock_bin / "ip"
        ip.write_text('#!/bin/sh\nprintf "1.1.1.1 dev eth0 src %s\\n" "${PUBLIC_IP_ROUTE_FIXTURE:-10.0.0.8}"\n', encoding="utf-8")
        ip.chmod(0o755)
        curl = self.mock_bin / "curl"
        curl.write_text('#!/usr/bin/env bash\nprintf "%s\\n" "$PUBLIC_IP_FIXTURE"\n', encoding="utf-8")
        curl.chmod(0o755)
        invalid = self.run_deploy_installer("--public-ip", "--no-start", PUBLIC_IP_FIXTURE="999.0.0.1")
        self.assertNotEqual(invalid.returncode, 0)
        self.assertFalse((self.source / "deploy/docker/.env").exists())
        self.assert_ok(self.run_installer("--public-ip", "--https-port", "8443", "--no-start",
                                          PUBLIC_IP_ROUTE_FIXTURE="203.0.113.9", PUBLIC_IP_FIXTURE="invalid"))
        config_file = self.destination / "deploy/docker/.env"
        config = config_file.read_text(encoding="utf-8").splitlines()
        for setting in ("APP_URL=https://203.0.113.9:8443", "TOKENHUB_SITE_ADDRESS=https://203.0.113.9",
                        "TOKENHUB_BIND_ADDRESS=0.0.0.0", "TOKENHUB_CADDY_GLOBAL=default_sni 203.0.113.9"):
            self.assertTrue(setting in config, "Missing public IP setting: " + setting)
        self.assertTrue(any(line.startswith("TOKENHUB_CADDY_TLS=") and "profile shortlived" in line
                            and "issuer internal" in line for line in config), "Missing public IP certificate policy")
        original_config = config_file.read_bytes()
        self.source = self.destination
        self.assert_ok(self.run_deploy_installer("--public-ip", "--no-start", PUBLIC_IP_FIXTURE="invalid"))
        self.assertTrue(config_file.read_bytes() == original_config, "Retry changed the detected site")


@unittest.skipUnless(shutil.which("pwsh") or shutil.which("powershell"), "PowerShell not installed")
class PowerShellBootstrapTest(BootstrapCases, unittest.TestCase):
    powershell = True


@unittest.skipUnless(os.name == "nt" and shutil.which("powershell"), "Windows PowerShell 5.1 requires Windows")
class WindowsPowerShellBootstrapTest(BootstrapCases, unittest.TestCase):
    powershell = True
    powershell_executable = shutil.which("powershell")


if __name__ == "__main__":
    unittest.main()
