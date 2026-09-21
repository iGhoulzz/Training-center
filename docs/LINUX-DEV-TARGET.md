# The Linux development target

A second place to run this project: Linux, in Docker, next to the Windows setup
everyone works in. Phase 3.5, Task 14.

**This is a test bench, not a deployment.** Nothing about how the application is
built, deployed or hosted changes because of it; system design §2 still states
capabilities rather than a vendor, and production is not required to use Docker.
Everyday work, the Git hooks and the pre-push gate stay on Windows. CI stays on
Ubuntu.

**This document was written from a run that happened**, on the owner's machine,
between 2026-09-18 and 2026-09-21 — including everything that failed on the way.
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

`-T` gives the command no terminal, which matches the recorded green run below.
Without it, `docker compose exec` supplies an interactive terminal; the one test
known to hang in that case is fixed (see the log), but the interactive form has
not itself been run end to end.

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

Pasted from `setup.sh` on the owner's machine, 2026-09-21 (the version current
then; it has since gained the load-run database and the `ref` argument):

```
==> Proving the four capabilities this target exists for
pcntl
posix
redis
redis: 1
{"platform":{"config":{"driver":"mysql","url":null,"host":"mysql","port":"3306","database":"training_center_linux", ...
```

| Capability | Evidence |
|---|---|
| PHP with `pcntl`, `posix` and `redis` | the three `php -m` lines above |
| A reachable Redis server | `redis-cli ping` in the Redis container answered `PONG` (2026-09-21); and `redis: 1` above is the same `PING` from PHP through phpredis, the path Horizon uses |
| `composer install` completes | step 4 ran to `==> Ready`, including the key generation after it |
| Its **own** test database | host `mysql`, database `training_center_linux` |

**The gate itself** — `composer verify` inside the target, on commit
`34f235c`, 2026-09-21:

```
START=2026-09-21T14:39:40Z
34f235c fix(tooling): three findings from the first Linux gate [P35-T14]
./composer.json is valid
  PASS   ......................................................... 591 files
 [OK] No errors
  - it treats a missing stop_hook_active as not active → Runs the real gate; covered by the manual obligation instead of slowing every suite run.
  Tests:    1 skipped, 2368 passed (7717 assertions)
  Duration: 2232.70s
EXIT=0
END=2026-09-21T15:17:41Z
```

Validation, Pint (591 files), PHPStan and the full suite, all green — about 37
minutes. The one skip is the same deliberate stop-hook skip every environment
reports. This run was started non-interactively inside the container
(`composer verify < /dev/null`), and the stdin fix below is what makes the
interactive `docker compose exec app composer verify` equivalent to it.

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
