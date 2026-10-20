#!/usr/bin/env bash
set -euo pipefail
umask 0027
# Host prerequisites: training-center owns releases and belongs to www-data.
# FPM pool, Horizon, Pulse, cron and all Artisan commands run as www-data.
# shared/storage is provisioned www-data:www-data; shared/.env is deploy-owned
# and runtime-readable. Runtime cannot modify release source. Install GNU tools,
# php-fpm8.4, curl and narrowly scoped passwordless sudo for PHP as www-data and
# root systemctl reload php8.4-fpm.service / start the two application units.
# The caller supplies an approved exact-main SHA artifact; Task 3 proves its CI.
# REVISION is a root-level regular text file containing that lowercase SHA only.
# Host-only approval files contain the same SHA, never packaged in the archive:
# shared/migration-approvals/<SHA>, plus shared/first-install-approved on install.
# Approval records and their directory are root-owned and not group/world writable.
# Approval means old code tolerates the migration; incompatible changes use the
# separately reviewed maintenance-window procedure, never this online path.
root=/srv/training-center
switched=0
step=arguments
fail() { printf 'Release refused at %s: %s\n' "$step" "$1" >&2; exit 1; }
approval_is_safe() {
    [[ ! -L "$1" && "$(stat -c %u -- "$1")" == 0 ]] || return 1
    mode=$(stat -c %a -- "$1")
    (( (8#$mode & 0022) == 0 ))
}
on_failure() {
    code=$?
    if (( code != 0 )); then
        printf 'Release failed at %s; logs and staged code retained.\n' "$step" >&2
        if (( switched )); then
            printf 'operator-led recovery required: current remains on new code; assess schema compatibility before any code reversal. No database rollback was attempted.\n' >&2
        fi
    fi
}
trap on_failure EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
[[ $# == 2 && "$1" =~ ^[0-9a-f]{40}$ ]] || fail 'expected <40-hex-main-sha> <absolute-release-archive>'
sha=$1
[[ "$(id -u)" != 0 && "$(id -un)" == training-center ]] || fail 'run as the dedicated non-root training-center account'
[[ "$2" == /* && -f "$2" && ! -L "$2" ]] || fail 'archive must be an absolute regular file'
archive=$(realpath -e -- "$2")
[[ -d "$root" && ! -L "$root" && "$(realpath -e -- "$root")" == "$root" ]] || fail 'host root must be a physical directory'
for path in releases shared shared/storage; do
    [[ -d "$root/$path" && ! -L "$root/$path" && "$(realpath -e -- "$root/$path")" == "$root/$path" ]] || fail 'unsafe host directory'
done
[[ -f "$root/shared/.env" && ! -L "$root/shared/.env" ]] || fail 'shared .env missing or unsafe'
[[ ! -L "$root/shared/deploy.lock" ]] || fail 'unsafe lock path'
exec 9>"$root/shared/deploy.lock"
flock -n 9 || fail 'another release holds the lock'
step=layout
previous=
if [[ -L "$root/current" ]]; then
    previous=$(realpath -e -- "$root/current")
    [[ "$previous" =~ ^$root/releases/[0-9a-f]{40}$ && -d "$previous" ]] || fail 'current points outside a release'
elif [[ -e "$root/current" ]]; then
    fail 'current must be a symlink'
else
    [[ -f "$root/shared/first-install-approved" && "$(cat "$root/shared/first-install-approved")" == "$sha" ]] && approval_is_safe "$root/shared/first-install-approved" || fail 'first installation approval missing or unsafe'
fi
[[ -d "$root/shared/migration-approvals" && ! -L "$root/shared/migration-approvals" ]] || fail 'migration approval directory missing'
approval_is_safe "$root/shared/migration-approvals" || fail 'migration approval directory is unsafe'
approval="$root/shared/migration-approvals/$sha"
[[ -f "$approval" && "$(cat "$approval")" == "$sha" ]] && approval_is_safe "$approval" || fail 'reviewed compatibility approval missing or unsafe'
release="$root/releases/$sha"
[[ ! -e "$release" && ! -L "$release" ]] || fail 'release already staged; retain it and investigate before retry'
[[ ! -L "$root/shared/deploy-logs" ]] || fail 'unsafe log path'
mkdir -p -- "$root/shared/deploy-logs"
log="$root/shared/deploy-logs/$sha-$(date -u +%Y%m%dT%H%M%SZ)-$$.log"
exec 8>"$log"
printf 'Deployment %s; prior %s\n' "$sha" "${previous:-first-install}" >&8
step=privilege-preflight
# Match systemd/cron exactly: PATH may contain another PHP with a different ini.
php_binary=/usr/bin/php
systemctl_binary=$(command -v systemctl)
runtime_php() { sudo -n -u www-data "$php_binary" "$@"; }
runtime_php --version >> "$log" 2>&1
sudo -n -l -- "$systemctl_binary" reload php8.4-fpm.service >> "$log" 2>&1
sudo -n -l -- "$systemctl_binary" start training-center-horizon.service training-center-pulse.service >> "$log" 2>&1
step=archive-validation
# Reject traversal, links (including hard links), devices, secrets, persistent
# state, repository files and runner caches BEFORE extraction. No tar overwrite.
tar -tf "$archive" > "$root/shared/deploy-logs/$sha-$$.entries"
while IFS= read -r entry; do
    entry=${entry#./}
    [[ -z "$entry" ]] && continue
    [[ "$entry" != /* && "$entry" != *\\* && ! "$entry" =~ (^|/)\.\.?(/|$) && ! "$entry" =~ [[:cntrl:]] ]] || fail 'unsafe archive path'
    case "$entry" in .env|.env.*|.env/|.env/*|*/.env|*/.env.*|.git|.git/*|storage|storage/*|node_modules|node_modules/*|tests|tests/*|docs|docs/*|public/storage|public/storage/*) fail 'forbidden artifact member' ;; esac
    case "$entry" in bootstrap/cache/*) [[ "$entry" == bootstrap/cache/ || "$entry" == bootstrap/cache/.gitignore ]] || fail 'runner-generated Laravel cache is forbidden' ;; esac
done < "$root/shared/deploy-logs/$sha-$$.entries"
while IFS= read -r entry; do
    [[ "${entry:0:1}" == - || "${entry:0:1}" == d ]] || fail 'archive must contain regular files/directories only'
done < <(tar -tvf "$archive")
mkdir -- "$release"
tar --extract --file "$archive" --directory "$release" --no-same-owner --no-same-permissions >> "$log" 2>&1
[[ -f "$release/REVISION" && "$(cat "$release/REVISION")" == "$sha" ]] || fail 'REVISION manifest/SHA mismatch'
for file in artisan vendor/autoload.php public/index.php public/build/manifest.json; do [[ -s "$release/$file" ]] || fail 'missing runtime or Vite asset'; done
for pair in js:js css:css fonts:woff2; do
    directory=${pair%:*}; extension=${pair#*:}
    [[ -d "$release/public/$directory/filament" && -n "$(find "$release/public/$directory/filament" -type f -name "*.$extension" -print -quit)" ]] || fail 'missing published Filament assets'
done
step=shared-state
ln -s -- "$root/shared/.env" "$release/.env"
ln -s -- "$root/shared/storage" "$release/storage"
mkdir -p -- "$release/bootstrap/cache"
ln -s -- "$root/shared/storage/app/public" "$release/public/storage"
# Do not chmod/chgrp through archive symlinks; only known shared paths and new code.
chgrp -hR www-data "$release"
find "$release" -type d -exec chmod 2750 {} +
find "$release" -type f -perm /111 -exec chmod 0750 {} +
find "$release" -type f ! -perm /111 -exec chmod 0640 {} +
chmod 2770 "$release/bootstrap/cache"
chgrp www-data "$root/shared/.env"
chmod 0640 "$root/shared/.env"
cd -- "$release"
step=runtime-storage
runtime_php /dev/stdin "$root/shared/storage" <<'PHP' >> "$log" 2>&1
<?php
// TC_DEPLOY_STORAGE
try {
    $storage = $argv[1];
    if (is_link($storage) || realpath($storage) !== $storage || ! is_writable($storage)) { throw new RuntimeException(); }
    $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($storage, FilesystemIterator::SKIP_DOTS));
    foreach ($entries as $entry) { if ($entry->isLink()) { throw new RuntimeException(); } }
    foreach (['app/secure', 'app/public', 'framework/cache/data', 'framework/sessions', 'framework/views', 'logs'] as $path) {
        $directory = $storage;
        foreach (explode('/', $path) as $component) {
            $directory .= '/'.$component;
            if (! file_exists($directory) && ! mkdir($directory, 0770)) { throw new RuntimeException(); }
            if (is_link($directory) || realpath($directory) !== $directory || ! is_dir($directory) || ! is_writable($directory)) { throw new RuntimeException(); }
        }
    }
} catch (Throwable $exception) { fwrite(STDERR, "Runtime storage is unsafe or unavailable.\n"); exit(1); }
PHP
step=php-fpm-extensions
fpm_binary=$(command -v php-fpm8.4 || printf '/usr/sbin/php-fpm8.4')
"$fpm_binary" -m > "$root/shared/deploy-logs/$sha-$$.fpm-modules" 2>&1
for extension in redis pcntl posix pdo_mysql mbstring intl bcmath gd zip; do
    grep -Fxiq "$extension" "$root/shared/deploy-logs/$sha-$$.fpm-modules" || fail 'PHP-FPM extension missing'
done
step=production-preflight
# PHP executes from stdin: credentials never enter process arguments. Exceptions
# are reduced to a generic refusal; protected logs never echo application config.
host=$(runtime_php <<'PHP'
<?php
// TC_DEPLOY_PREFLIGHT
try {
    if (PHP_VERSION_ID < 80401) { throw new RuntimeException(); }
    foreach (['redis', 'pcntl', 'posix', 'pdo_mysql', 'mbstring', 'intl', 'bcmath', 'gd', 'zip'] as $extension) {
        if (! extension_loaded($extension)) { throw new RuntimeException(); }
    }
    require getcwd().'/vendor/autoload.php';
    $app = require getcwd().'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    // TC_DEPLOY_WORKER_MEMORY_BEGIN
    // Horizon children start fresh PHP without the master's systemd -d flag.
    // Use the runtime user's default CLI ini and the measured 24 MiB render gap.
    $rawLimit = trim((string) ini_get('memory_limit'));
    if (! preg_match('/^([1-9][0-9]*)([KMG]?)$/iD', $rawLimit, $quantity)) {
        throw new RuntimeException('Default CLI memory_limit must be finite and positive; configure the runtime PHP ini (not only the systemd master).');
    }
    $unit = match (strtoupper($quantity[2])) { 'K' => 1024, 'M' => 1048576, 'G' => 1073741824, default => 1 };
    $amount = filter_var($quantity[1], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => intdiv(PHP_INT_MAX, $unit)]]);
    if ($amount === false) { throw new RuntimeException('Default CLI memory_limit is invalid.'); }
    $limitBytes = $amount * $unit;
    $production = config('horizon.environments.production', []);
    if (! is_array($production) || $production === []) { throw new RuntimeException('Production Horizon supervisors are missing.'); }
    foreach ($production as $name => $overrides) {
        $options = array_replace(config('horizon.defaults.'.$name, []), $overrides);
        $ceiling = filter_var($options['memory'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => intdiv(PHP_INT_MAX, 1048576)]]);
        if ($ceiling === false || $limitBytes - $ceiling * 1048576 < 24 * 1048576) {
            throw new RuntimeException('Default CLI memory_limit '.$rawLimit.' must leave at least 24 MiB above each production Horizon worker memory ceiling. Configure the runtime PHP ini before deployment.');
        }
    }
    // TC_DEPLOY_WORKER_MEMORY_END
    $required = ['app.env' => 'production', 'app.debug' => false, 'database.default' => 'mysql', 'queue.default' => 'redis', 'cache.limiter' => 'redis', 'cache.default' => 'database', 'session.driver' => 'database'];
    foreach ($required as $key => $value) { if (config($key) !== $value) { throw new RuntimeException(); } }
    if (! config('app.key') || parse_url(config('app.url'), PHP_URL_SCHEME) !== 'https') { throw new RuntimeException(); }
    if (config('logging.default') !== 'stack' || ! in_array('daily', config('logging.channels.stack.channels', []), true)
        || (int) config('logging.channels.daily.days') < 1) { throw new RuntimeException(); }
    $host = parse_url(config('app.url'), PHP_URL_HOST);
    if (! is_string($host) || ! preg_match('/^[a-z0-9.-]+$/iD', $host)) { throw new RuntimeException(); }
    Illuminate\Support\Facades\DB::select('SELECT 1');
    $version = Illuminate\Support\Facades\DB::selectOne('SELECT VERSION() AS version')->version;
    if (! preg_match('/^8\./', $version)) { throw new RuntimeException(); }
    foreach (array_unique(['default', config('queue.connections.redis.connection'), config('cache.stores.redis.connection')]) as $name) {
        if (! config("database.redis.$name.password")) { throw new RuntimeException(); }
        $redis = Illuminate\Support\Facades\Redis::connection($name);
        if (! in_array($redis->ping(), [true, 'PONG', '+PONG'], true)) { throw new RuntimeException(); }
        $policy = $redis->command('config', ['GET', 'maxmemory-policy']);
        $memory = $redis->command('config', ['GET', 'maxmemory']);
        if (($policy['maxmemory-policy'] ?? $policy[1] ?? null) !== 'noeviction' || (int) ($memory['maxmemory'] ?? $memory[1] ?? 0) <= 0) { throw new RuntimeException(); }
    }
    App\Domain\Staff\Support\BackupConfiguration::assertReadyForProduction('production');
    App\Domain\Staff\Support\BackupConfiguration::assertDestinationReady();
    $disk = Illuminate\Support\Facades\Storage::disk(config('backup.destination_disk'));
    // Read-only connectivity/credential check; fresh backup later proves writes.
    iterator_to_array($disk->getDriver()->listContents('', false));
    $mailer = config('mail.mailers.'.config('mail.default'));
    // First launch is SMTP. A different delivering transport needs reviewed probes.
    if (($mailer['transport'] ?? null) !== 'smtp') { throw new RuntimeException(); }
    $url = isset($mailer['url']) ? parse_url($mailer['url']) : [];
    $smtpHost = $url['host'] ?? $mailer['host'];
    $smtpPort = (int) ($url['port'] ?? $mailer['port']);
    $tls = ($url['scheme'] ?? $mailer['scheme'] ?? null) === 'smtps' || $smtpPort === 465;
    $transport = new Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport($smtpHost, $smtpPort, $tls);
    $transport->setRequireTls(true);
    $transport->getStream()->setTimeout(10);
    // Deliberately no username/password: DNS/TCP/EHLO/TLS only, no AUTH or mail.
    $transport->start();
    $transport->stop();
    echo $host;
} catch (Throwable $exception) {
    // Memory-limit refusals contain only operator configuration, never secrets.
    if (str_starts_with($exception->getMessage(), 'Default CLI memory_limit')) { fwrite(STDERR, $exception->getMessage()."\n"); }
    fwrite(STDERR, "Production dependency/configuration preflight failed.\n");
    exit(1);
}
PHP
) || fail 'production dependency/configuration preflight failed'
step=cache-rebuild
for command in config:cache route:cache view:cache event:cache filament:optimize; do runtime_php artisan "$command" --no-interaction >> "$log" 2>&1; done
step=fresh-backup
if [[ -n "$previous" ]]; then
    # Current code backs up the prior schema and BOTH upload roots. Share the
    # scheduler's exact mutex so a nightly archive cannot race this fresh backup.
    (cd -- "$previous" && runtime_php <<'PHP'
<?php
// TC_DEPLOY_BACKUP
try {
    require getcwd().'/vendor/autoload.php';
    $app = require getcwd().'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $lock = Illuminate\Support\Facades\Cache::lock('framework/schedule-backup-pipeline', 10800);
    if (! $lock->get()) { throw new RuntimeException(); }
    try {
        $status = Illuminate\Support\Facades\Artisan::call('backup:run', ['--disable-notifications' => true, '--no-interaction' => true]);
        if ($status !== 0) { throw new RuntimeException(); }
    } finally { $lock->release(); }
} catch (Throwable $exception) { fwrite(STDERR, "Fresh backup failed or pipeline is occupied.\n"); exit(1); }
PHP
    ) >> "$log" 2>&1
else
    printf 'First installation: no prior production data; backup drill required before cutover.\n' >&8
fi
step=migration
runtime_php artisan migrate --force --no-interaction >> "$log" 2>&1
smoke() {
runtime_php <<'PHP' >> "$log" 2>&1
<?php
// TC_DEPLOY_POSTFLIGHT
try {
    require getcwd().'/vendor/autoload.php';
    $app = require getcwd().'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    Illuminate\Support\Facades\DB::select('SELECT 1');
    Illuminate\Support\Facades\Redis::connection()->ping();
    $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
    foreach (['/up', '/', '/admin/login', '/portal/login'] as $path) {
        $request = Illuminate\Http\Request::create(rtrim(config('app.url'), '/').$path);
        $response = $kernel->handle($request);
        if ($response->getStatusCode() !== 200) { throw new RuntimeException(); }
        $kernel->terminate($request, $response);
    }
} catch (Throwable $exception) { fwrite(STDERR, "Dependency/route smoke failed.\n"); exit(1); }
PHP
}
step=pre-switch-smoke
smoke
step=atomic-switch
next="$root/current-$sha"
[[ ! -e "$next" && ! -L "$next" ]] || fail 'temporary switch path exists'
ln -s -- "$release" "$next"
mv -Tf -- "$next" "$root/current"
switched=1
printf 'Switched current to %s; prior retained at %s\n' "$sha" "${previous:-none}" >&8
step=process-restart
cd -- "$root/current"
sudo -n "$systemctl_binary" reload php8.4-fpm.service >> "$log" 2>&1
runtime_php artisan horizon:terminate --no-interaction >> "$log" 2>&1
runtime_php artisan pulse:restart --no-interaction >> "$log" 2>&1
sudo -n "$systemctl_binary" start training-center-horizon.service training-center-pulse.service >> "$log" 2>&1
for service in horizon pulse; do
    ready=0
    for ((attempt=0; attempt<120; attempt++)); do
        if systemctl is-active --quiet "training-center-$service.service"; then
            pid=$(systemctl show --property=MainPID --value "training-center-$service.service")
            # /proc cwd access is restricted to the process's runtime identity.
            if [[ "$pid" =~ ^[1-9][0-9]*$ ]] && runtime_php /dev/stdin "$pid" "$release" <<'PHP' >> "$log" 2>&1
<?php
// TC_DEPLOY_PROCESS
exit(realpath('/proc/'.$argv[1].'/cwd') === $argv[2] ? 0 : 1);
PHP
            then ready=1; break; fi
        fi
        sleep 1
    done
    (( ready )) || fail 'supervised process did not restart on new release'
done
step=post-switch-dependencies
smoke
step=post-switch-http
# Hit this host's TLS Nginx, checking its real certificate; DNS cutover is not
# needed for first install. No --insecure and no external load balancer cache.
for path in /up / /admin/login /portal/login; do
    curl --fail --silent --show-error --max-time 15 --resolve "$host:443:127.0.0.1" "https://$host$path" -o /dev/null >> "$log" 2>&1
done
step=record-sha
[[ ! -L "$root/shared/deployed-sha" && ! -e "$root/shared/deployed-sha-$sha" ]] || fail 'unsafe SHA receipt path'
printf '%s\n' "$sha" > "$root/shared/deployed-sha-$sha"
mv -Tf -- "$root/shared/deployed-sha-$sha" "$root/shared/deployed-sha"
printf 'Release %s succeeded. Previous code and logs retained.\n' "$sha"
