# Cross-platform LAN access Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (- [ ]) syntax for tracking.

**Goal:** Add safe, one-command LAN access setup and cleanup for TokenHub on Windows, macOS, and Ubuntu.

**Architecture:** Docker Compose and Caddy stay unchanged. Windows uses named Windows Firewall rules. macOS uses a marked PF anchor. Ubuntu uses one tagged Docker DOCKER-USER rule. Scripts derive the active default-route address and expose only TCP 8080 to the local subnet.

**Tech Stack:** PowerShell, POSIX shell, Docker Compose, Windows Firewall, PF, iptables, TokenHub PHP test runner.

---

### Task 1: Add a failing deployment-contract test

**Files:**
- Create: tests/Console/LanAccessScriptsTest.php

- [ ] **Step 1: Add the failing contract**

~~~php
<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap.php';

$root = dirname(__DIR__, 2);
foreach ([
  'scripts/start-lan.ps1', 'scripts/stop-lan.ps1',
  'scripts/start-lan.sh', 'scripts/stop-lan.sh',
  'docs/deployment/lan-access.md',
] as $file) {
  assert_true(is_file($root . '/' . $file), $file . ' should exist.');
}

$windows = file_get_contents($root . '/scripts/start-lan.ps1');
foreach (['TokenHub LAN HTTP (8080)', 'LocalSubnet', 'New-NetFirewallRule', 'docker compose up -d --build', '/health', '/admin'] as $text) {
  assert_true(str_contains($windows, $text), 'Windows launcher should contain ' . $text . '.');
}

$unix = file_get_contents($root . '/scripts/start-lan.sh');
foreach (['Darwin', 'Ubuntu', 'DOCKER-USER', 'com.tokenhub/lan', '8080', '/health', '/admin'] as $text) {
  assert_true(str_contains($unix, $text), 'POSIX launcher should contain ' . $text . '.');
}
~~~

- [ ] **Step 2: Prove it fails**

Run: docker compose exec -T php php tests/Console/LanAccessScriptsTest.php

Expected: missing launcher files assertion.

### Task 2: Implement Windows scripts

**Files:**
- Create: scripts/start-lan.ps1
- Create: scripts/stop-lan.ps1
- Test: tests/Console/LanAccessScriptsTest.php

- [ ] **Step 1: Implement privilege, route, and firewall handling**

Use the following required operations in start-lan.ps1:

~~~powershell
$RuleName = 'TokenHub LAN HTTP (8080)'
$Port = 8080
$principal = [Security.Principal.WindowsPrincipal] [Security.Principal.WindowsIdentity]::GetCurrent()
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
  $arguments = @('-ExecutionPolicy', 'Bypass', '-File', $PSCommandPath)
  Start-Process -FilePath 'powershell.exe' -Verb RunAs -Wait -ArgumentList $arguments
  exit $LASTEXITCODE
}

$route = Get-NetRoute -AddressFamily IPv4 -DestinationPrefix '0.0.0.0/0' | Sort-Object RouteMetric | Select-Object -First 1
$address = Get-NetIPAddress -AddressFamily IPv4 -InterfaceIndex $route.InterfaceIndex | Where-Object { $_.AddressState -eq 'Preferred' -and $_.IPAddress -notlike '169.254.*' } | Select-Object -First 1
if ($null -eq $address) { throw 'No active IPv4 LAN address was found.' }

Get-NetFirewallRule -DisplayName $RuleName -ErrorAction SilentlyContinue | Remove-NetFirewallRule
New-NetFirewallRule -DisplayName $RuleName -Direction Inbound -Action Allow -Protocol TCP -LocalPort $Port -RemoteAddress LocalSubnet -Profile Private,Public -EdgeTraversalPolicy Block | Out-Null
docker compose up -d --build
~~~

Retry the local health endpoint for 60 seconds and print the address URL at http://<LAN-IP>:8080/admin.

- [ ] **Step 2: Implement narrow cleanup**

~~~powershell
Remove-NetFirewallRule -DisplayName 'TokenHub LAN HTTP (8080)' -ErrorAction SilentlyContinue
Write-Host 'Removed TokenHub LAN firewall rule.'
~~~

Use the same elevation guard. Do not stop Docker or change another rule.

- [ ] **Step 3: Parse scripts**

Run: powershell -NoProfile -Command "$null = [System.Management.Automation.PSParser]::Tokenize((Get-Content -Raw scripts/start-lan.ps1), [ref]$null); $null = [System.Management.Automation.PSParser]::Tokenize((Get-Content -Raw scripts/stop-lan.ps1), [ref]$null)"

Expected: exit 0.

### Task 3: Implement POSIX platform adapters

**Files:**
- Create: scripts/start-lan.sh
- Create: scripts/stop-lan.sh
- Test: tests/Console/LanAccessScriptsTest.php

- [ ] **Step 1: Implement shared launch behavior**

Use set -eu. Resolve the repository root from the script directory, derive default-route interface/IP/CIDR, run docker compose up -d --build, then run:

~~~sh
for attempt in $(seq 1 30); do
  if curl --fail --silent --show-error http://127.0.0.1:8080/health >/dev/null; then
    printf 'TokenHub LAN URL: http://%s:8080/admin\n' "$LAN_IP"
    exit 0
  fi
  sleep 2
done
printf '%s\n' 'TokenHub did not become healthy on port 8080 within 60 seconds.' >&2
exit 1
~~~

- [ ] **Step 2: Implement macOS PF**

For Darwin, prompt with sudo and manage only a marked anchor named com.tokenhub/lan plus a marked /etc/pf.conf include. Generate a rule set that passes LAN_CIDR to TCP 8080 on LAN_INTERFACE then blocks other source addresses to that port. Validate with sudo pfctl -nf /etc/pf.conf before sudo pfctl -f /etc/pf.conf. Cleanup removes only TokenHub markers and the TokenHub anchor.

- [ ] **Step 3: Implement Ubuntu Docker filtering**

For Ubuntu only, verify iptables -S DOCKER-USER succeeds, then create only this tagged rule if absent:

~~~sh
sudo iptables -C DOCKER-USER -i "$LAN_INTERFACE" -p tcp --dport 8080 ! -s "$LAN_CIDR" -m comment --comment tokenhub-lan-8080 -j DROP 2>/dev/null || \
  sudo iptables -I DOCKER-USER 1 -i "$LAN_INTERFACE" -p tcp --dport 8080 ! -s "$LAN_CIDR" -m comment --comment tokenhub-lan-8080 -j DROP
~~~

Cleanup must delete only the identical tagged rule. Unsupported Linux distributions must fail before modifying host state.

- [ ] **Step 4: Parse scripts**

Run: bash -n scripts/start-lan.sh scripts/stop-lan.sh

Expected: exit 0.

### Task 4: Document and verify

**Files:**
- Create: docs/deployment/lan-access.md
- Modify: README.md
- Test: tests/Console/LanAccessScriptsTest.php

- [ ] **Step 1: Write documentation**

Document the start and cleanup commands, privilege prompts, generated admin URL, 8080-only local-subnet scope, no TLS endpoint, and platform mechanisms. State that databases and caches remain out of scope.

- [ ] **Step 2: Add the README section**

~~~markdown
## LAN access

For a host-firewall-aware LAN launch flow, see [LAN access deployment](docs/deployment/lan-access.md).
~~~

- [ ] **Step 3: Run verification**

Run:

~~~powershell
powershell -NoProfile -Command "$null = [System.Management.Automation.PSParser]::Tokenize((Get-Content -Raw scripts/start-lan.ps1), [ref]$null); $null = [System.Management.Automation.PSParser]::Tokenize((Get-Content -Raw scripts/stop-lan.ps1), [ref]$null)"
bash -n scripts/start-lan.sh scripts/stop-lan.sh
docker compose exec -T php php tests/Console/LanAccessScriptsTest.php
docker compose exec -T php php bin/run-tests.php
~~~

Expected: syntax and contract checks pass; Docker suite prints All tests passed. Manual smoke testing requires each platform plus a second LAN device and privilege approval.

- [ ] **Step 4: Commit only where Git is available**

Run:

~~~sh
git add README.md docs/deployment/lan-access.md scripts/start-lan.ps1 scripts/stop-lan.ps1 scripts/start-lan.sh scripts/stop-lan.sh tests/Console/LanAccessScriptsTest.php
git commit -m "feat: add cross-platform LAN launcher"
~~~

This checkout has no Git metadata, so report changed files instead.

