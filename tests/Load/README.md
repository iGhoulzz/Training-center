# Phase 3.5 load baseline

These four [k6](https://grafana.com/docs/k6/latest/) HTTP protocol scripts exercise the staff flows through Filament's Livewire update endpoint. They do not launch browsers. Filament and Livewire snapshot, action, and update-route changes can break this harness; after either package changes, smoke-run every script and inspect its `flow_errors` and post-run facts before comparing numbers.

The target is the disposable Linux container in `docker/dev-linux/`, with its own `training_center_performance` database. Never point this at a developer or production database. Enrol-and-collect appends finance and activity facts. The load run is a measurement, not a build threshold or a production latency promise. Each script uses **20 VUs for 5 minutes**. Login is intentionally outside the measured flows because Filament limits it to five attempts per minute. A separate, explicitly throttled login scenario can be added later.

## Prepare the target

Use PowerShell from this task worktree's `docker/dev-linux/`, so the script mount below resolves to this branch. Set `HOST_REPO` to the main checkout containing the committed `p35/t11-load-baseline` branch, as `docs/LINUX-DEV-TARGET.md` explains; a worktree's `.git` file cannot be cloned inside the container. Run `docker compose up -d`, then:

```powershell
docker compose exec -T app bash /bootstrap/setup.sh p35/t11-load-baseline
docker compose exec -T -e DB_DATABASE=training_center_performance app php artisan seed:performance-dataset --profile=medium --confirm-database=training_center_performance
```

The seed command rebuilds **only** the explicitly confirmed, allowlisted performance database and loads 4,000 charges with 8,000 allocations. It is destructive. Re-run it before another baseline cycle. Do not use `training_center_linux`, which the test suite rebuilds independently.

The seeded owner starts with a forced password change. Complete that once through the normal staff-panel password-change flow on this disposable target, or select another active staff-panel account. Use an account with English locale: the bill and payment-method assertions match rendered English labels. The account must have `view_any_student`, `create_enrollment`, `create_payment`, `view_financial_report`, and `view_payment`; the enrol-and-collect script also uses the desk's quick-create student action and therefore needs `create_student`. The mint command checks the first five permissions, panel access, active state, and that no password change is pending. Permissions, rather than role names, determine eligibility.

From the target, record the actual selected queue, cache, and session drivers; do not infer them from `.env.example`:

```powershell
docker compose exec -T -e DB_DATABASE=training_center_performance app php artisan config:show queue.default
docker compose exec -T -e DB_DATABASE=training_center_performance app php artisan config:show cache.default
docker compose exec -T -e DB_DATABASE=training_center_performance -e SESSION_LIFETIME=480 app php artisan config:show session.driver
docker compose exec -T -e DB_DATABASE=training_center_performance -e SESSION_LIFETIME=480 app php artisan config:show session.lifetime
```

Use Redis for the queue, a database session driver, and `SESSION_LIFETIME=480` for the eight-hour idle expiry fallback. The command refuses other session drivers or lifetimes. Start a temporary app listener in a separate terminal; this CLI image publishes no HTTP port. `PHP_CLI_SERVER_WORKERS=8` gives PHP's development server eight workers, avoiding a single-worker ceiling. Record the actual worker count and that this is a development server in the results. Keep that terminal open for the run:

```powershell
docker compose exec -T -e DB_DATABASE=training_center_performance -e SESSION_LIFETIME=480 -e PHP_CLI_SERVER_WORKERS=8 app php artisan serve --no-reload --host=0.0.0.0 --port=8000
```

The app container is reachable as `http://app:8000` from an ephemeral k6 container on `dev-linux_default`. Queue work, including receipt generation, needs a running Redis worker. Start the target's normal worker or Horizon in another terminal before enrol-and-collect. Confirm that `QUEUE_CONNECTION=redis` is the **running** configuration.

## Mint and run

Create an absolute path **outside `/workspace`**, for example `/tmp/t11-load-sessions.json`. Do not place this file in either checkout, paste it into chat, or print its contents. Mint prints its path only. Replace `<staff-user-id>` with the eligible account's numeric ID:

```powershell
docker compose exec -T -e DB_DATABASE=training_center_performance -e SESSION_LIFETIME=480 app php artisan load:mint-sessions --user=<staff-user-id> --count=20 --confirm-database=training_center_performance --output=/tmp/t11-load-sessions.json
```

The manifest contains 20 independent encrypted session-cookie values and matching CSRF tokens. It is created atomically with owner-only mode `0600`, signed, and never overwritten. The k6 scripts read it through `K6_SESSION_FILE`. `grafana/k6:1.4.0` is the pinned image; `/bin/sh` was checked in that image. The following PowerShell command streams the manifest directly between containers, leaving no copy on the Windows host. Run it once for each script, in this order, replacing `<script>` with `student-search`, `reports`, `enrol-and-collect`, then `receipt-download`:

```powershell
$scriptPath = (Resolve-Path ../../tests/Load/k6).Path
docker compose exec -T app cat /tmp/t11-load-sessions.json | docker run --rm -i --network dev-linux_default --entrypoint /bin/sh -v "${scriptPath}:/scripts:ro" -e K6_BASE_URL=http://app:8000 grafana/k6:1.4.0 -c 'umask 077; cat > /tmp/sessions.json; K6_SESSION_FILE=/tmp/sessions.json k6 run /scripts/<script>.js'
```

The k6 container's temporary filesystem is discarded after each run. Its shell writes the streamed manifest with owner-only permissions. No credential is passed as a command argument or environment value.

The first two scripts read the untouched medium fixture. `student-search` searches for and requires the rendered `PERF-000100` student. If overriding `K6_STUDENT_SEARCH`, set `K6_STUDENT_HIT` to a matching seeded code the response must display. `reports` rotates revenue, payment-method, outstanding-aged, and student-payment-history reports against dates containing seeded payments; every iteration checks the applied filters and report-specific seeded row markers. `enrol-and-collect` uses the real quick-create action and appends new students, enrolments, bills, payments, and queued receipt jobs. The medium fixture already enrolled every seeded student into batch 1, so selecting one again is a duplicate; quick-create is necessary. It verifies the new bill's allocation after payment. The bill amount is `1000.000 LYD` for the seeded batch. Set `K6_BATCH_ID` if the fixture's batch differs.

The seeded 8,000 payments have no PDF files, so `receipt-download` must use payment IDs whose receipt jobs actually completed after the collection run. Inspect the performance database read-only for payments with a canonical `receipt_path` and an existing file. Wait for the Redis worker to finish these jobs before starting the download run. In the command above, use `-e K6_RECEIPT_IDS=<id1>,<id2>` alongside `-e K6_BASE_URL=...` for this fourth script. Do not use an ID with no rendered PDF: a 404 would measure a missing document.

Receipt delivery is throttled to 60 requests per minute per user. All 20 sessions belong to one user, so this script sleeps 25 seconds per VU after **every** attempt: at most about 48 downloads per minute in steady state. `flow_duration` records the request before the sleep, and failed attempts still increment `flow_errors`. Record this pacing with the receipt throughput; it measures successful PDF delivery within the limiter, not the unthrottled capacity of the endpoint.

Each k6 summary includes `flow_duration` p95, `flow_errors` rate, and `flow_completed` count. Throughput is `flow_completed / 300` successful flows per second. Also record k6's `http_req_duration` p95 and `http_req_failed` rate, plus the actual queue/cache/session drivers, host/container versions, PHP server worker count, commit, fixture counts, and date in `docs/reviews/2026-09-28-phase-3.5-load-baseline.md`. Validate post-run finance row deltas against successful enrol flows. If a script fails, record the failure; do not substitute estimates or zeroes for unmeasured figures.

Failed iterations pause for two seconds before retrying. This keeps a lost target from turning 20 VUs into a tight request-failure loop; the pause is outside `flow_duration`.

## Revoke (always the final step)

After all runs, including a failed or interrupted run, revoke using the original manifest inside the app container:

```powershell
docker compose exec -T -e DB_DATABASE=training_center_performance -e SESSION_LIFETIME=480 app php artisan load:revoke-sessions --manifest=/tmp/t11-load-sessions.json --confirm-database=training_center_performance
```

Revocation verifies the signed manifest and deletes **only** its listed session IDs, then removes the file. Check that command's exit code before stopping the target. If revocation cannot run, the database-backed sessions expire after eight hours of inactivity when the target uses `SESSION_LIFETIME=480`; do not rely on expiry as the routine cleanup path.
