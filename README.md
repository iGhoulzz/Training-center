# Training Center

A management system for a single training center: students, courses and
batches, enrollments, staff accounts, finances, and a student portal with
certificates.

Laravel 13 and Filament 5 on PHP 8.4, with MySQL 8.4 and Redis queues. It is
built for a root Linux VPS; the hosting provider is not chosen yet (system
design §2). The staff panel is at `/admin` and the student portal at `/portal`.
The public site planned for `/` is not built yet: `/` still serves Laravel's
placeholder page, and certificate verification at `/verify/certificates` is the
only public feature so far.

## Documentation

| Document | What it holds |
|---|---|
| [System design](docs/superpowers/specs/2026-07-20-training-center-dashboard-design.md) | Scope, data model and permissions. For phase 2, the [finance design](docs/superpowers/specs/2026-08-09-phase-2-financials-design.md) supersedes it where the two disagree. All designs are in [`docs/superpowers/specs/`](docs/superpowers/specs/) |
| [`docs/ENGINEERING.md`](docs/ENGINEERING.md) | How code is written here |
| [`docs/WORKFLOW.md`](docs/WORKFLOW.md) | How Claude and Codex divide, isolate and cross-review work |
| [`docs/superpowers/plans/`](docs/superpowers/plans/) | One plan per milestone; the newest is the current one |
| [`docs/CHANGELOG.md`](docs/CHANGELOG.md) | Release notes, currently for phases 1 and 2 |
| [`docs/RESTORE.md`](docs/RESTORE.md) | Restoring from a backup |
| [`CLAUDE.md`](CLAUDE.md), [`AGENTS.md`](AGENTS.md) | Instructions for the two coding agents |

## Local setup

You need PHP 8.4, Composer, Node and a MySQL server. `.env.example` expects a
database and a user both named `training_center`; create them first, or copy
`.env.example` to `.env` and set your own `DB_*` values before running setup.

```bash
composer setup
```

In that order, that creates `.env` from `.env.example` if it is missing,
installs the PHP dependencies, generates the application key, migrates, and
then installs the Node dependencies and builds the frontend. It copies `.env`
**before** installing, deliberately: the install boots the application through
package discovery, and with no `.env` the environment falls back to
`production`, where the backup guard refuses to boot.

It is a fresh-install command. Running it again regenerates `APP_KEY`, which
invalidates existing sessions and anything else encrypted with the old key.

For a local database, seed the roles and one super admin account:

```bash
php artisan db:seed
```

The account is in `database/seeders/UserSeeder.php` and must change its password
at first sign-in. Do not seed a production database with it.

Queued work — receipts and report PDFs — goes to Redis, so a local machine needs
Redis and the `phpredis` extension. Without them, set `QUEUE_CONNECTION=database`
in `.env`, which `.env.example` allows for development and never for production.
`composer dev` runs the development server, a queue worker and the log viewer
together.

The suite needs a second database, `training_center_test`, reachable by the same
user. See the gate below.

## The gate

```bash
composer verify
```

This runs Composer validation, formatting, static analysis and the full test
suite. The suite uses its own database, `training_center_test`, which is set in
`phpunit.xml`. Every worktree of one clone shares a lock, so two runs from the
same clone wait for each other rather than collide. A second clone on the same
machine gets its own lock but the same database, so do not run two clones'
suites at once.

Enable the Git hooks once per clone:

```bash
git config core.hooksPath .githooks
```

After that, `pre-push` runs `composer verify`, and CI runs the same command.
