<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

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
