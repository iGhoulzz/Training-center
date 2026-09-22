# The Linux development target

A second place to run this project: Linux, in Docker, next to the Windows setup
everyone works in. Phase 3.5, Task 14.

**This is a test bench, not a deployment.** Nothing about how the application is
built, deployed or hosted changes because of it; system design §2 still states
capabilities rather than a vendor, and production is not required to use Docker.
Everyday work, the Git hooks and the pre-push gate stay on Windows. CI stays on
Ubuntu.

**This document was written from a run that happened**, on the owner's machine,
between 2026-09-18 and 2026-09-22 — including everything that failed on the way.
The failures are the most useful part: the next person hits the same steps.

---

## Why it exists

Horizon needs two PHP extensions that have **no Windows build at all**: `pcntl`
and `posix`. It supervises its workers by forking and signalling them. The
third, `redis`, does have an official Windows build but is not installed on the
development machine. `php -m` on its PHP 8.4.13 (NTS, Visual C++ x64) lists none
of the three.

| Task | Needs the Linux target because |
|---|---|
| T10b Horizon | Horizon supervises workers by forking and signalling them (`pcntl`, `posix`) |
| T11 Load baseline | A baseline against the database queue driver measures a configuration production will not use |
| T12 SLO targets | Built from T11's numbers |
| T13 Stress cycle | Built on T11 and T12 |

A Redis container alone does not unblock Horizon: what is missing is PHP
running on Linux. For the load runs the reason is fidelity rather than
possibility — they should measure the queue driver and platform production will
run.

## What was actually used

| | |
|---|---|
| Host | Windows 10 Home, Docker Desktop, engine 29.8.0, Compose 5.5.1 |
| Image | `php:8.4-cli` — Debian GNU/Linux 13 (trixie), **PHP 8.4.25** |
| Extensions | `bcmath gd intl mbstring pcntl pdo_mysql posix redis zip` — CI's set plus the three |
| Composer | 2.10.3 |
| Database | `mysql:8.4` — **MySQL 8.4.7**, its own server, database `training_center_linux` |
| Queue | `redis:alpine` — **Redis 8.4.0** |

The files live in `docker/dev-linux/`: `Dockerfile`, `compose.yaml` and
`setup.sh`. Each explains its own decisions in comments.

---

## Running it

Everything below runs in **one** Windows PowerShell window. The `HOST_REPO`
line only lasts for the window it is typed in.

**0. Start Docker Desktop** and wait for **Engine running** in its bottom-left
corner. (The first attempt failed exactly here — see the log below.)

**1. Go to the Docker folder**

```powershell
cd C:\Users\User\Desktop\Training-center\docker\dev-linux
```

**2. Name the checkout the container copies from**

```powershell
$env:HOST_REPO = 'C:\Users\User\Desktop\Training-center'
```

From the main checkout this is already the default, and the line can be
skipped. It matters when compose is started from a **task worktree**: the
container copies from `HOST_REPO`, which must be a real checkout, because a
worktree's `.git` is a file holding a Windows path that does not exist inside
the container. `setup.sh` refuses a worktree with this exact instruction.

**3. Build and start the three containers**

```powershell
docker compose up -d --build
```

Only the first run builds. Afterwards `docker compose up -d` starts them in
seconds. The app container has no health check, so compose prints only the
database and Redis as `Healthy` — `docker compose ps` shows all three.

**4. Prepare the Linux working copy, and choose what it tests** (safe to re-run)

```powershell
docker compose exec app bash /bootstrap/setup.sh
```

The first run clones the checkout into a Linux volume, writes `.env`, installs
dependencies, creates the separate load-run database, and prints the proof
below. **A later run does not move the working copy on its own.** To test a
particular branch or commit, name it — as it exists in the `HOST_REPO`
checkout:

```powershell
docker compose exec app bash /bootstrap/setup.sh p35/t10b-horizon
```

Only **committed** work can be tested: the working copy is a clone, so edits not
yet committed on Windows are invisible to it. The script prints
`==> Code under test: <commit>` — check it before trusting a green result.
Dependencies are reinstalled every run, because another branch can carry a
different `composer.lock`.

**5. Run the gate inside Linux**

```powershell
docker compose exec -T app composer verify
```

`-T` gives the command no terminal. This exact command, run from a
`docker/dev-linux` folder, is the green gate recorded below. Without `-T`, `docker compose exec` supplies
an interactive terminal. The one test known to hang in that case is fixed (see
the log), but the interactive form has not itself been run end to end.

**Load runs and Horizon use a different database.** `training_center_linux` is
rebuilt with `migrate:fresh` by every gate run, and the suite lock covers test
processes only, so anything that seeds data and expects to keep it must not
share it. `setup.sh` creates `training_center_performance` on the same server —
already on T05's allowlist — and each such command selects it:

```powershell
docker compose exec -e DB_DATABASE=training_center_performance app php artisan migrate --force
```

**Stopping:** `docker compose stop`. **Starting again later:** steps 0–2, then
`docker compose up -d`; the working copy and both databases survive, including
a container being recreated. **Throwing it all away:** `docker compose down -v`
— this deletes the working copy and every database in the target.

---

## The four capabilities, proved

Pasted from the owner's machine on 2026-09-21 and 22, all on commit `63f7c19`.
Every block below is command output, including the `###` lines the capturing
scripts printed to label each step. Where a block leaves anything out, the
sentence before it says what, and the block marks the place `[...]`.

**Step 4, `setup.sh p35/t14-linux-dev-target`, from an empty `vendor/`.** The
Linux working copy's `vendor/` was deleted first, so the install ran from
nothing rather than reporting "Nothing to install". Colour codes, blank lines
and trailing spaces are removed. Package rows, both progress bars, the
per-package discovery rows, the published-asset rows, most of the `db:show`
JSON and the closing "Ready" instructions are elided where marked. Every other
line is as printed, in order:

```
### date: 2026-09-21T17:22:49Z
### before: 63f7c19 fix(tooling): apply the pre-push review of the Linux target [P35-T14]
### rm -rf vendor  (so composer install below runs from nothing)
ls: cannot access 'vendor': No such file or directory
### bash /bootstrap/setup.sh p35/t14-linux-dev-target
==> Checking out p35/t14-linux-dev-target from the Windows checkout
==> Code under test: 63f7c19 fix(tooling): apply the pre-push review of the Linux target [P35-T14]
==> Installing PHP dependencies
Installing dependencies from lock file (including require-dev)
Verifying lock file contents can be installed on current platform.
Package operations: 189 installs, 0 updates, 0 removals
[... 186 "Downloading" rows, a progress bar, 189 "Installing" rows, a second progress bar ...]
Generating optimized autoload files
> Illuminate\Foundation\ComposerScripts::postAutoloadDump
> @php artisan package:discover --ansi
   INFO  Discovering packages.
[... one "DONE" row per discovered package ...]
> @php artisan filament:upgrade
[... one row per published Filament asset ...]
   INFO  Successfully published assets!
   INFO  Configuration cache cleared successfully.
   INFO  Route cache cleared successfully.
   INFO  Compiled views cleared successfully.
   INFO  Successfully upgraded!
119 packages you are using are looking for funding.
Use the `composer fund` command to find out more!
==> Ensuring the separate database for load runs exists
training_center_performance: present
==> Proving the capabilities this target exists for
pcntl
posix
redis
redis: 1
{"platform":{"config":{"driver":"mysql","url":null,"host":"mysql","port":"3306","database":"training_center_linux", [... rest elided; setup.sh itself prints only the first 400 bytes ...]
[... the "Ready" instructions ...]
### setup.sh exit: 0
### vendor/autoload.php present: yes
### php artisan --version: Laravel Framework 13.30.1
RUN_EXIT=0
```

Three packages have an "Installing" row and no "Downloading" row, apparently
because Composer's cache already held them.

**The Redis server, asked directly**, in its own container:

```
### docker exec training_center_dev_redis redis-cli ping
PONG
### docker exec training_center_dev_redis redis-server --version
Redis server v=8.4.0 sha=00000000:1 malloc=jemalloc-5.3.0 bits=64 build=66248de8ed1d9f64
```

**Horizon's platform requirements, on Linux, with no override.** Horizon is not
in `composer.lock` until T10b, so the install above cannot show them. A dry run
in the Linux working copy can. That copy's `composer.json` carries only the
existing `php` platform pin, not the `pcntl`/`posix` override recommended below
for Windows. The dry run's output went through `grep -vE '^\s*$' | tail -25`,
which removed blank lines. The tail cut nothing, because there were only 16
lines:

```
### 63f7c19 fix(tooling): apply the pre-push review of the Linux target [P35-T14]
### config.platform in composer.json:
{"php":"8.4.1"}
### composer require laravel/horizon --dry-run --no-interaction
./composer.json has been updated
Running composer update laravel/horizon
Loading composer repositories with package information
Updating dependencies
Lock file operations: 2 installs, 0 updates, 0 removals
  - Locking laravel/horizon (v5.49.0)
  - Locking symfony/polyfill-php83 (v1.41.0)
Installing dependencies from lock file (including require-dev)
Package operations: 2 installs, 0 updates, 0 removals
  - Installing symfony/polyfill-php83 (v1.41.0)
  - Installing laravel/horizon (v5.49.0)
1 package suggestions were added by new dependencies, use `composer suggest` to see details.
119 packages you are using are looking for funding.
Use the `composer fund` command to find out more!
No security vulnerability advisories found.
Using version ^5.49 for laravel/horizon
### require exit: 0
### tracked files changed by the dry run (expect none):
### (end)
```

The last check there, `git status --short composer.json composer.lock`, covers
only those two files. Despite the "has been updated" line, the dry run changed
nothing git tracks in the working copy, and installed no `horizon` under
`vendor/laravel`. Afterwards, in the same working copy:

```
### 63f7c19 fix(tooling): apply the pre-push review of the Linux target [P35-T14]
### git status --short   (whole working copy, after the Horizon dry run)
### git status exit: 0
### ls vendor/laravel
agent-detector
boost
framework
mcp
pail
pao
pint
prompts
pulse
roster
sentinel
serializable-closure
tinker
```

**Its own test database, and the application connected to it.** On Windows no
real environment variable sets `DB_DATABASE`: the process, user and machine
scopes are all empty. So `phpunit.xml`'s value applies there. `.env` is read
later and does not override it. In the container, `compose.yaml` sets a real
environment variable, which PHPUnit's `<env>` does not override:

```
### grep DB_DATABASE phpunit.xml   (the Windows value: phpunit.xml applies it only where the variable is unset)
88:        <env name="DB_DATABASE" value="training_center_test"/>
### printenv DB_DATABASE   (this container, from compose.yaml; phpunit.xml does not override it)
training_center_linux
### the application connecting: php artisan tinker, one read-only query
{"db":"training_center_linux","version":"8.4.7","server":"cef9667b7a69"}
### (end)
```

| Capability | Evidence |
|---|---|
| PHP with `pcntl`, `posix` and `redis` | the three `php -m` lines in step 4's output |
| A reachable Redis server | `PONG` above; and `redis: 1` in step 4 is the same `PING` from PHP through phpredis, the path Horizon uses |
| `composer install` completes, with Horizon's platform requirements satisfied | from an empty `vendor/`: 189 installs, the autoloader generated, package discovery run, `setup.sh exit: 0`; and the Horizon dry run above resolving with `require exit: 0` and no `pcntl`/`posix` override |
| Its **own** test database, different from Windows' | `training_center_linux` against `phpunit.xml`'s `training_center_test`, with a live query answered by that database. Every gate run below also rebuilt it with `migrate:fresh` |

**The gate itself: step 5's command, exactly as written**, on `63f7c19`:
`docker compose exec -T app composer verify`, run from a `docker/dev-linux`
folder: here a task worktree's, with `HOST_REPO` set as in step 2. Its stdin
was an open pipe that nothing wrote to, standing in for a PowerShell window
where nobody types. What was observed is the container side: while it ran,
`composer verify`'s stdin read `pipe:[460]`. The `COMMAND`, `START`, `CODE`, `EXIT` and `END` lines and
the `---` separators come from the wrapper that launched it and captured its
two streams; everything between them is the command's own output. Colour
codes, blank lines and trailing spaces are removed, and two stretches are
elided where marked:

```
COMMAND=docker compose exec -T app composer verify   (cwd C:\Users\User\Desktop\Training-center-worktrees\P35-T14\docker\dev-linux, stdin: open silent pipe)
START=2026-09-22T16:40:27Z
CODE=63f7c19 fix(tooling): apply the pre-push review of the Linux target [P35-T14]
--- stdout ---
./composer.json is valid
[... eight rows of Pint's progress dots ...]
  ──────────────────────────────────────────────────────────────────── Laravel
    PASS   ......................................................... 594 files
 [OK] No errors
   INFO  Configuration cache cleared successfully.
[... the test-by-test listing, file by file ...]
  Tests:    1 skipped, 2374 passed (7746 assertions)
  Duration: 2622.97s
--- stderr ---
Note: Using configuration file /workspace/phpstan.neon.
EXIT=0
END=2026-09-22T17:24:17Z
```

Validation, Pint, PHPStan and the full suite, all green. The one skip is the
deliberate stop-hook skip every environment reports, printed as:

```
   WARN  Tests\Unit\Tooling\AgentStopHookTest
  ✓ it exits immediately when a stop has already been continued          0.08s
  - it treats a missing stop_hook_active as not active → Runs the real…  0.03s
  ✓ it never writes to stdout                                            0.04s
  ✓ it survives a malformed payload without crashing                     0.17s
```

**Every completed Linux gate run.** Two more did not complete: the first
interactive run, which hung (failure 3 below), and a run of step 5's command
lost when the laptop powered off (failure 6). The first three here were
launched detached, as
`docker exec -d training_center_dev_app sh -c 'cd /workspace && { …; composer verify < /dev/null; …; } > /tmp/<log> 2>&1'`:

| Commit | Launched as | Result | Duration |
|---|---|---|---|
| before the fixes in the log below | detached, stdin `/dev/null` | `EXIT=2`: failures 4 and 5 below | 2164.12 s |
| `34f235c` | detached, stdin `/dev/null` | 2368 passed, 1 skipped, `EXIT=0` | 2232.70 s |
| `63f7c19` | detached, stdin `/dev/null`, **while the Windows pre-push gate ran on the same laptop** | 2374 passed, 1 skipped, `EXIT=0` | 4294.56 s |
| `63f7c19` | step 5's command, above, on 2026-09-22 | 2374 passed, 1 skipped, `EXIT=0` | 2622.97 s |

**Expect 35 to 45 minutes**, which is what the other three runs took. The one 72-minute run shared the laptop with the Windows gate. The two suites do not share a lock, so nothing stops that, and
each slows the other.

**The interactive form**, `docker compose exec app composer verify` without
`-T`, has not been run end to end. The one test known to hang under it is fixed
(failure 3 below), and that fix was proved under the same interactive
condition.

---

## Two environments, two databases — the rule that matters most

The Windows suite and the Linux suite must **never share a database**.

`tests/bootstrap.php` serialises suites with a machine-wide lock, and that lock
**does not cross the OS boundary, twice over**: its file lives in
`sys_get_temp_dir()` (`%TEMP%` on Windows, `/tmp` in Linux), and its key hashes
`git rev-parse --git-common-dir`, spelled differently on each side. A Windows
run and a Linux run therefore never see each other. Pointed at one schema, they
would `migrate:fresh` it underneath each other, and the failures would read as
flakiness for days.

Two independent measures keep them apart:

- **A separate server.** The Linux MySQL is inside the compose project and
  publishes no port. **Never set `DB_HOST` to `host.docker.internal`** — it is
  the one configuration that would undo this.
- **A distinct name.** `training_center_linux`, alongside `training_center_test`
  on Windows and `training_center_ci` in CI.

---

## What failed, and what changed

In the order it happened. Each is fixed in the files above, or recorded here
because it will happen again.

**1. `docker compose up` could not reach Docker.**
`open //./pipe/dockerDesktopLinuxEngine: The system cannot find the file specified.`
Docker Desktop was not running after a reboot. Start it and wait for **Engine
running**. It also stopped once mid-session (a Docker Desktop restart); the same
fix, and nothing on disk was lost. Optional: *Settings → General → Start Docker
Desktop when you sign in*.

**2. The first build was slow — 34 minutes.** The 129 MB `php:8.4-cli` base
layer arrived at roughly 60 KB/s. It is cached afterwards; later
`docker compose up -d` takes seconds. `setup.sh`'s Composer install took about
four minutes on a later day.

**3. The first `composer verify` hung forever, in `GitHookTest`.**
The pre-push hook starts with `tuples=$(cat)`, reading stdin to EOF, and the
test gave it no stdin of its own. `docker compose exec` supplies an
interactive terminal, so `cat` waited for keystrokes that never came. Seen
directly: the process list showed `/bin/sh .githooks/pre-push` and `cat` alive
and idle.
*Fixed* in `tests/Unit/Tooling/GitHookTest.php`: the hook now receives an empty,
closed pipe. Proved in the target under the same condition — an interactive,
silent terminal:

| Version | Result |
|---|---|
| Original (`c73ddda`) | hung: no output at all in 45 s, killed by the timeout |
| Fixed (`34f235c`) | `2 passed (4 assertions)` in 0.10 s |

Two earlier proof attempts were discarded because their control also passed —
a held-open pipe and a terminal with its input closed are *not* the failing
condition, because `php artisan test` passes stdin through only to a live
terminal.

**4. With the hang fixed, 157 tests failed — none of them for a reason in the
application.** The compose file had set `APP_ENV=local` and
`QUEUE_CONNECTION=redis` as container variables. PHPUnit's `<env>` never
overrides a variable that already exists — the same rule the database name
relies on — so the whole suite ran outside the `testing` environment: CSRF
enforced (HTTP 419), jobs sent to Redis instead of run. Removing those two
variables took one sample file from 4 failed to 9 passed, and re-running all 40
failing files that way left 5 failures of 632 tests — the 157 minus the 152 this
cause accounted for.
*Fixed* in `compose.yaml`: it now sets **nothing `phpunit.xml` pins except
`DB_DATABASE`**, and says why. The Redis queue this target exists for is set in
the container's `.env`, which PHPUnit's `<env>` does outrank.

**5. The last five failures: T05's performance allowlist.**
`Connected database [training_center_linux] is not allowlisted for performance tooling.`
*Fixed*, with the owner's approval: `training_center_linux` joins the literal
list in `config/performance.php`, for the reason `training_center_ci` did — a
disposable test database on its own server.

**6. The laptop powered off in the middle of a gate run.** On 2026-09-21, 30
minutes into the first run of step 5's command, the laptop powered off
unexpectedly and stayed off until the next day. That run was lost and had to be
started again from the beginning. Nothing on disk was lost: the working copy,
`vendor/` and both databases survived. After Docker Desktop was started again,
`docker compose up -d` brought the database and Redis back to healthy and the
app container running. Nothing in the target can prevent this. Keep the
machine on for the length of a run.

---

## The Composer question: adding Horizon without breaking Windows

Once `laravel/horizon` is in `composer.lock`, it requires `ext-pcntl` and
`ext-posix`, and every Windows `composer install` would fail — for both agents
and the owner, in every worktree. Settled before T10b, on a throwaway branch
(`spike/t14-composer-platform`, never pushed or merged), from `main` at
`6e33d45`:

| Step | Result on Windows |
|---|---|
| `composer require laravel/horizon`, no override | **Refused**: `requires ext-pcntl * -> it is missing from your system` |
| `composer config platform.ext-pcntl 8.4.1` and `platform.ext-posix 8.4.1` | added beside the existing `platform.php` |
| `composer require laravel/horizon` | installs v5.49 |
| `composer validate --strict` | passes |
| `php artisan --version` | `Laravel Framework 13.30.1`; the `horizon*` commands are registered |
| `vendor/composer/platform_check.php` | no `pcntl`/`posix` check, so boot is not blocked |
| Fresh install, `vendor/` deleted first | installs and boots |
| `composer check-platform-reqs` | still reports both **missing**, honestly |

**Recommendation for T10b: use the override.** Day-to-day work stays on Windows.
`php artisan horizon` itself runs only where the extensions really exist — this
target and production.

**Its one cost:** the override tells Composer the extensions exist everywhere,
so Composer alone will not catch a production server that genuinely lacks them.
T10b's plan already requires CI to assert all three extensions before Composer
runs; the same check belongs in the production deploy
(`php -m` or `composer check-platform-reqs`), because that is the one place a
missing extension would otherwise surface only when the queue stops.
