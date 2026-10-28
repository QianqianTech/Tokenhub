# Cross-platform LAN access design

## Goal

Provide a supported local-network launch path for TokenHub on Windows, macOS,
and Ubuntu. A user should be able to run one platform-appropriate launcher,
approve the operating system privilege prompt when required, and receive the
TokenHub admin URL on the active LAN address.

The feature exposes only TokenHub HTTP on TCP port `8080`. It does not expose
MySQL, Redis, Qdrant, Docker's API, or a TLS endpoint. It leaves
`docker-compose.yml`, Caddy configuration, and `.env` unchanged.

## Existing behaviour and boundary

Docker Compose already publishes Caddy port 80 as host port `8080`. The Caddy
site serves the admin interface at `/admin`; `/` intentionally returns 404.
The validated LAN-form URL is therefore:

```text
http://<active-lan-ip>:8080/admin
```

Publishing a Docker port is not enough to make it reachable: each host OS can
block the inbound packet before it reaches Docker. The launchers own that host
firewall step; browser/PHP code does not attempt to bypass it.

## User-facing commands

The repository will provide matching start and cleanup commands:

| Platform | Start | Remove managed access |
| --- | --- | --- |
| Windows | `scripts/start-lan.ps1` | `scripts/stop-lan.ps1` |
| macOS / Ubuntu | `scripts/start-lan.sh` | `scripts/stop-lan.sh` |

The start commands are idempotent. They detect the active IPv4 interface from
the default route, derive its CIDR, configure the platform adapter, invoke
`docker compose up -d --build`, wait for `http://127.0.0.1:8080/health`, and
print the LAN admin URL. They fail clearly when no active private IPv4 route is
available or Docker is unavailable.

The cleanup commands remove only rules/files created with the TokenHub-managed
name. They do not disable a host firewall or remove unrelated rules.

## Platform adapters

### Windows

`start-lan.ps1` relaunches itself elevated using UAC when necessary. It creates
or reconciles a named Windows Firewall inbound rule for TCP `8080`, scoped to
`LocalSubnet` and the Private/Public profiles. The firewall rule is the only
persistent Windows state. `stop-lan.ps1` removes that named rule.

### macOS

The shell launcher requests `sudo` and installs a TokenHub-owned PF anchor and
a clearly delimited include block in `/etc/pf.conf`. The anchor permits the
detected LAN CIDR to reach TCP `8080` and blocks other inbound sources on that
interface/port. The launcher validates the PF configuration before loading it;
it never replaces the whole PF ruleset or disables PF. Cleanup removes only the
marked include block and anchor file, then reloads the validated configuration.

### Ubuntu

The shell launcher requests `sudo` and uses Docker's `DOCKER-USER` chain,
which is evaluated before Docker's published-port accept rules. It inserts a
uniquely commented rule that drops TCP `8080` traffic arriving on the selected
LAN interface from outside the detected CIDR. Local-subnet traffic continues to
Docker's normal port-publish rule. It verifies that the Docker chain exists and
fails rather than falling back to a broad UFW rule. Cleanup finds and removes
only the uniquely tagged TokenHub rule.

## Safety and error handling

- The launchers require explicit OS privilege approval; they never silently
  weaken the firewall.
- The allowed source is the active interface's CIDR, not `0.0.0.0/0`.
- The scripts use managed names/comments and query-before-create behaviour, so
  repeated starts do not accumulate rules.
- If a privilege request, firewall validation, Docker start, or health check
  fails, the script reports the failed phase and does not claim the service is
  LAN reachable.
- The HTTPS host port `8443` is intentionally excluded: the current Docker
  Caddy configuration has no TLS listener.

## Verification

Automated checks will cover script syntax and the platform-specific command
construction without executing privileged firewall writes. Focused tests will
verify: route/CIDR selection, idempotent managed-rule detection, command scope
to TCP `8080`, cleanup targeting, and generated LAN URL. A manual smoke path
will run a launcher with privilege approval and request `/admin` from a second
device on the same subnet.
