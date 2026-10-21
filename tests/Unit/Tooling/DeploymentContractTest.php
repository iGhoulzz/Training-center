<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('checks the real child PHP limit against effective production worker memory plus render headroom', function (string $limit, bool $safe, ?int $productionCeiling = null): void {
    $root = dirname(__DIR__, 3);
    preg_match('/\/\/ TC_DEPLOY_WORKER_MEMORY_BEGIN\R(.*?)\/\/ TC_DEPLOY_WORKER_MEMORY_END/s', file_get_contents($root.'/deploy/release.sh'), $matches);
    $guard = $matches[1] ?? '';
    $program = '<?php require '.var_export($root.'/vendor/autoload.php', true).';'
        .'$container = new Illuminate\\Container\\Container; Illuminate\\Container\\Container::setInstance($container);'
        .'$horizon = require '.var_export($root.'/config/horizon.php', true).';'
        .'$container->instance("config", new Illuminate\\Config\\Repository(["horizon" => $horizon]));'
        .($productionCeiling === null ? '' : '$container->make("config")->set("horizon.environments.production.supervisor-1.memory", '.$productionCeiling.');')
        .'echo "actual memory_limit=".ini_get("memory_limit").PHP_EOL;'
        .'try {'.$guard.'} catch (Throwable $exception) { fwrite(STDERR, $exception->getMessage()); exit(1); }';
    $process = new Process([PHP_BINARY, '-d', 'memory_limit='.$limit], $root);
    $process->setInput($program)->run();

    expect($process->getOutput())->toContain('actual memory_limit='.$limit);
    expect($process->isSuccessful())->toBe($safe, $process->getOutput().$process->getErrorOutput());
    if (! $safe) {
        expect($process->getErrorOutput())->toContain('Default CLI memory_limit');
    }
})->with([
    'unsafe 64 MiB child, regardless of master -d' => ['64M', false],
    'unreachable 96 MiB recycle ceiling' => ['96M', false],
    'insufficient 16 MiB render headroom' => ['112M', false],
    'measured render plus margin' => ['120M', true],
    'usual CLI limit' => ['128M', true],
    'plain bytes' => ['134217728', true],
    'larger reviewed budget' => ['1G', true],
    'unlimited has no finite production budget' => ['-1', false],
    'invalid overflowing quantity' => ['999999999999999999999G', false],
    'production override needs its own headroom' => ['128M', false, 112],
    'production override with measured headroom' => ['136M', true, 112],
]);

it('contains failures before cutover and proves both supervised clean restarts', function (): void {
    $root = dirname(__DIR__, 3);
    $bash = PHP_OS_FAMILY === 'Windows' ? 'C:/Program Files/Git/bin/bash.exe' : 'bash';
    $process = new Process([$bash, 'deploy/tests/release-smoke.sh'], $root, ['MSYS_NO_PATHCONV' => '1']);
    $process->setTimeout(120);
    $process->run();

    expect($process->isSuccessful())->toBeTrue($process->getOutput().$process->getErrorOutput());
    expect($process->getOutput())->toContain('PASS: second release', 'PASS: post-switch recovery', 'PASS: clean worker restarts');
});

it('keeps host templates on public roots and persistent supervised processes', function (): void {
    $root = dirname(__DIR__, 3);
    expect(is_file($root.'/deploy/nginx/training-center.conf.example'))->toBeTrue();
    $nginx = file_get_contents($root.'/deploy/nginx/training-center.conf.example');

    expect($nginx)->toContain('root /srv/training-center/current/public;', 'listen 443 ssl;', '$realpath_root$fastcgi_script_name', 'fastcgi_param DOCUMENT_ROOT $realpath_root;', 'location ~ /\\.');
    expect($nginx)->not->toMatch('/^\s*include\s+(?:fastcgi\.conf|snippets\/fastcgi-php\.conf)/m');

    foreach (['horizon' => 'horizon', 'pulse' => 'pulse:check'] as $service => $command) {
        $unit = parse_ini_file($root.'/deploy/systemd/training-center-'.$service.'.service', true, INI_SCANNER_RAW);
        expect($unit['Service'])->toMatchArray([
            'WorkingDirectory' => '/srv/training-center/current',
            'ExecStart' => '/usr/bin/php -d memory_limit=128M /srv/training-center/current/artisan '.$command,
            'Restart' => 'always',
            'RestartSec' => '5s',
            'User' => 'www-data',
        ]);
    }

    expect(file_get_contents($root.'/deploy/redis/training-center.conf.example'))->toContain('bind 127.0.0.1 ::1', 'maxmemory-policy noeviction', 'appendonly yes', 'appendfsync everysec', 'include /etc/redis/training-center-secret.conf');
    expect(file_get_contents($root.'/deploy/cron/training-center.cron'))->toContain('* * * * * www-data', 'current/artisan schedule:run');
});

it('serves ACME challenges independently of redirects and TLS dotfile rules', function (): void {
    $nginx = file_get_contents(dirname(__DIR__, 3).'/deploy/nginx/training-center.conf.example');
    preg_match_all('/^server \{.*?^\}/ms', $nginx, $servers);
    expect($servers[0])->toHaveCount(2);

    foreach ($servers[0] as $server) {
        preg_match('/location \^~ \/\.well-known\/acme-challenge\/ \{([^{}]*)\}/s', $server, $challenge);
        expect($challenge)->not->toBeEmpty();
        expect($challenge[1])->toContain('root /var/www/letsencrypt;', 'default_type text/plain;', 'try_files $uri =404;');
    }

    $http = $servers[0][0];
    $withoutLocations = preg_replace('/location[^{}]*\{[^{}]*\}/s', '', $http);
    expect($withoutLocations)->not->toMatch('/\breturn\s+301\b/');
    expect($http)->toMatch('/location \/ \{\s*return 301 https:\/\/center\.example\$request_uri;\s*\}/s');
});

it('applies browser security headers on HTTPS responses without HSTS on HTTP', function (): void {
    $nginx = file_get_contents(dirname(__DIR__, 3).'/deploy/nginx/training-center.conf.example');
    preg_match_all('/^server \{.*?^\}/ms', $nginx, $servers);
    expect($servers[0][0])->not->toContain('Strict-Transport-Security');
    expect($servers[0][1])->toContain(
        'add_header Strict-Transport-Security "max-age=31536000" always;',
        'add_header X-Frame-Options SAMEORIGIN always;',
        'add_header Referrer-Policy strict-origin-when-cross-origin always;',
    );
});

it('returns maintenance before the first current entrypoint exists instead of passing it to FPM', function (): void {
    $nginx = file_get_contents(dirname(__DIR__, 3).'/deploy/nginx/training-center.conf.example');
    preg_match('/location = \/index\.php \{([^{}]*)\}/s', $nginx, $entrypoint);

    expect($entrypoint)->not->toBeEmpty();
    expect($entrypoint[1])->toMatch('/try_files \$uri =503;.*fastcgi_pass/s');
    expect($nginx)->toContain('root /srv/training-center/current/public;', 'location / { try_files $uri $uri/ /index.php?$query_string; }');
});
