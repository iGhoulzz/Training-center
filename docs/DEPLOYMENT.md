# Deployment runbook: the production VPS

How Training Center is installed on its production host, released, recovered and
looked after. Every step names who does it, the exact command, and the evidence
to keep.

This document describes the host contract implemented by `deploy/release.sh`,
`deploy/receive.sh` and `.github/workflows/deploy.yml`. Where a step quotes a
refusal, a path or a step name, it comes from those files. If the files change,
change this document in the same pull request.

---

## 0. Read this first

### The decisions in force

| Decision | Choice | Decided |
|---|---|---|
| Host | A Libyan Spider root Linux VPS, Ubuntu 24.04 LTS, 2 vCPU / 4 GB RAM / 40 GB disk | Owner, 2026-10-20 |
| Stack | Native: Nginx, PHP-FPM 8.4, MySQL 8.4 LTS, Redis, systemd, cron. No Docker, no management panel | Plan, decision 1 |
| Where HTTPS ends | **At the VPS's own Nginx.** No upstream proxy or CDN. `TRUSTED_PROXIES` stays empty | Plan, global constraints |
| Releases | Manual, approval-gated GitHub workflow, one green `main` SHA at a time | Plan, decision 2 |
| Mail | Brevo SMTP (`smtp-relay.brevo.com`, port 587, STARTTLS). Credentials are typed on the host only | Owner, 2026-10-20 |
| Backups | **Temporary, on the server**, for the first one to two weeks after launch. Then an off-server bucket. See section F | Owner, 2026-10-20 |
| Administrators | Two, created together by `staff:bootstrap-production-owner` after the first successful release | Plan, Task 1 |

If the TLS decision ever changes to an upstream terminator, stop. That needs the
upstream's real addresses in `TRUSTED_PROXIES`, a firewall that admits only that
upstream to the origin, and a signed-link and client-IP test before cutover (see
`config/proxies.php`). It is not covered here.

### Who does what

| Role | Is | Does |
|---|---|---|
| **Owner** | The centre's owner | Holds the accounts (registrar, GitHub, Brevo, the password manager), approves launch, takes the secrets that must live off the host |
| **Operator** | Whoever has root on the VPS | Runs the host commands in this document |
| **Reviewer** | An owner other than the one who dispatched the deploy | Reviews migration compatibility and approves the deploy in GitHub |

One person may hold two roles, except that the reviewer who approves a deploy in
GitHub should not be the person who dispatched it (section B6 turns that on).

### Ground rules

1. **This repository is public.** Nothing real goes into it: not the domain, not
   the server's address, not a secret. This document uses `center.example`,
   `203.0.113.10`, `ops@center.example` and `<SECRET>` as placeholders.
2. **Keep an evidence log outside the repository** (the owner's private notes).
   Each step below says what to paste into it. Redact secrets before pasting.
3. **Run every `artisan` command as `www-data`, from `current`.** Never as root
   and never as `training-center`: the files they would create (logs, caches,
   uploads) would not be writable by PHP-FPM afterwards. The form is always:

   ```bash
   cd /srv/training-center/current && sudo -u www-data /usr/bin/php artisan <command>
   ```

4. **Never run** `composer setup`, a bare `db:seed`, `DatabaseSeeder`,
   `UserSeeder` or `PerformanceDatasetSeeder` on production. They create the
   development owner account and synthetic data. Section B10 runs
   `RolePermissionSeeder` only.
5. **No password goes into a command line.** Arguments are visible to every user
   on the host through the process list. Secrets are read from files or typed at
   a hidden prompt.
6. **`/up` is a boot check, not a dependency check.** It returns 200 whenever the
   framework boots (`bootstrap/app.php` registers it as the health route). It
   does not touch MySQL, Redis, mail, the queue or the backups. A green `/up`
   proves none of them. What proves them is the release's own preflight and
   smoke steps, the Horizon and Pulse dashboards, and the checks in section E.
7. **A code rollback is not a database rollback.** Reversing the `current`
   symlink changes which code runs, never which schema or data exists. Nothing
   in this system reverses a migration or restores a database over newer
   writes automatically, and nobody should do either without the owner's
   decision (section D).

### Prerequisites and unknowns

**The first deploy needs PR #129 merged.** It changes `release.sh` to check
`pcntl` on the CLI only, because Ubuntu's PHP packages compile `pcntl` into the
CLI and not into PHP-FPM. Install the PHP packages normally (A3); do not work
around the check.

**Libyan Spider unknowns. The Operator checks these first (A1), before anything
is installed, and reports the answers to the Owner:**

| Unknown | Why it matters | Check (A1) |
|---|---|---|
| Loop devices allowed | The temporary on-server backup volume (A12) is a loop-mounted image | `systemd-detect-virt` is not `openvz`/`lxc`, and `losetup -f` works |
| IPv6 | Decides whether an AAAA record may be published (B2) | `ip -6 addr show scope global` |
| Outbound port 587 | Brevo SMTP; the release preflight and all alert mail need it | `timeout 10 bash -c '</dev/tcp/smtp-relay.brevo.com/587' && echo open` |

Two properties of the code the runbook is built around:

- The backup destination check (`BackupConfiguration::assertDestinationReady`)
  refuses a `backups_local` path on the **same filesystem** as the application,
  because that is what an unmounted drive looks like. A plain folder on a
  one-disk VPS fails the release preflight and `backup:run`. A12 therefore mounts
  a dedicated filesystem image there.
- `release.sh` requires the default **CLI** `memory_limit` to be finite and at
  least 24 MiB above the Horizon worker ceiling (96 MiB, so 120 MiB). Ubuntu's
  CLI `php.ini` ships `memory_limit = -1`; A10 sets 128M.

---

## A. Provisioning and hardening

Goal: a host that runs the stack, accepts only SSH, HTTP and HTTPS from the
Internet, and has nothing in place that a deploy could not safely overwrite.

Do this over a root SSH session. **Keep one session open throughout** and test
each access change from a second one before you close the first.

### A1. Preflight the purchased VPS

**Who:** Operator.

```bash
grep -E '^(PRETTY_NAME|VERSION_ID)=' /etc/os-release
nproc; free -h; df -h /
systemd-detect-virt
ip -br addr
losetup -f                                    # prints a free loop device (needed by A12)
ip -6 addr show scope global                  # empty output: no public IPv6
timeout 10 bash -c '</dev/tcp/smtp-relay.brevo.com/587' && echo "587 open"
```

Stop and tell the owner, before installing anything, if:

- it is not Ubuntu 24.04;
- RAM or disk is below the purchased 4 GB / 40 GB;
- `systemd-detect-virt` prints `openvz` or `lxc`, or `losetup -f` fails. The loop
  mount in A12 will not work; ask the provider for an attached volume instead;
- outbound port 587 is not open (no mail, so no release).

No public IPv6 is fine, but then publish **no** AAAA record in B2.

PHP 8.4.1 or newer must be installable (checked again in A3). If it cannot be,
**stop**: the locked dependencies require it and nothing else here matters.

Evidence: the output above, plus the date and the provider's plan name.

### A2. Update the base system

**Who:** Operator.

```bash
apt-get update && apt-get -y full-upgrade
[ -f /var/run/reboot-required ] && echo "reboot needed"
```

Reboot if told to, then open a new session. Leave the system timezone at UTC:
the nightly backup schedule names its own timezone (section E).

### A3. Install the packages

**Who:** Operator.

Ubuntu 24.04 ships PHP 8.3, so PHP 8.4 comes from the `ondrej/php` PPA. Redis
comes from Ubuntu. MySQL 8.4 LTS comes from MySQL's own repository, installed in
A8, so production runs the same MySQL series that CI and the Docker dev target
test. PHP-FPM and the CLI get their extensions from the packages below; install
them normally (see the PR #129 note in section 0).

```bash
apt-get install -y software-properties-common ca-certificates curl ufw \
    unattended-upgrades nginx redis-server certbot gnupg wget lsb-release
add-apt-repository -y ppa:ondrej/php
apt-get update
apt-get install -y php8.4-fpm php8.4-cli php8.4-mysql php8.4-mbstring php8.4-intl \
    php8.4-bcmath php8.4-gd php8.4-zip php8.4-redis php8.4-xml php8.4-curl
```

Check that `/usr/bin/php` is 8.4. `release.sh` and both systemd units use that
exact path.

```bash
/usr/bin/php -v | head -1        # PHP 8.4.x, x >= 1
update-alternatives --display php | head -3
```

If it is not 8.4: `update-alternatives --set php /usr/bin/php8.4`.

Evidence: the `php -v` line, `redis-server --version`, `nginx -v`.

### A3a. Copy and verify the reviewed host files

**Who:** Operator, from their own machine with the repository checked out at the
reviewed commit. The host has no checkout, and the release archive excludes
`deploy/`, so these files travel separately.

```bash
git rev-parse HEAD                        # note this SHA in the evidence log
(cd deploy && find . -type f -not -path './tests/*' | sort | xargs sha256sum) > deploy.sha256
ssh root@203.0.113.10 'mkdir -p /root/tc-deploy-files'
scp -r deploy root@203.0.113.10:/root/tc-deploy-files/
scp deploy.sha256 root@203.0.113.10:/root/tc-deploy-files/
```

On the host, check every file against the checksums taken from the commit:

```bash
cd /root/tc-deploy-files/deploy && find . -type f -not -path './tests/*' | sort | xargs sha256sum | diff - ../deploy.sha256 && echo MATCH
```

Evidence: the reviewed commit SHA and the `MATCH` line.

### A4. Create the deploy account

**Who:** Operator.

```bash
useradd --create-home --shell /bin/bash --groups www-data training-center
passwd -S training-center        # second field must be L (locked: no password)
```

The account has **no password** and a real shell. The shell has to be real:
sshd runs a key's forced command through the account's shell, so `nologin`
would stop every deploy. The key installed in A14 can run exactly one command
and nothing else.

Membership of `www-data` lets the deploy account read and group-own what PHP
needs to read. PHP-FPM, Horizon, Pulse, cron and every `artisan` command run as
`www-data`; the deploy account owns only the release code.

### A5. Harden SSH

**Who:** Operator. **Why:** the host is on the public Internet from the first
minute. Password guessing is the commonest attack, and a password is the one
credential that is never needed here.

```bash
cat > /etc/ssh/sshd_config.d/00-training-center.conf <<'EOF'
PasswordAuthentication no
KbdInteractiveAuthentication no
PubkeyAuthentication yes
PermitRootLogin prohibit-password
EOF
sshd -t && sshd -T | grep -E '^(passwordauthentication|kbdinteractiveauthentication|pubkeyauthentication|permitrootlogin) '
systemctl reload ssh
```

The file is named `00-` on purpose. sshd keeps the **first** value it reads, and
cloud images often ship a later `50-cloud-init.conf` that turns passwords back
on. `sshd -T` prints the effective result; read it, not the file.

Now, **from a new terminal on your machine**, confirm key login still works
before you close the old session:

```bash
ssh root@203.0.113.10 'echo ok'
ssh -o PubkeyAuthentication=no root@203.0.113.10 'echo should-fail'   # must be refused
```

Root can still log in with a **key**, which is how the operator administers the
host. Nobody, root included, can log in with a password. Do not change the SSH
port: on Ubuntu 24.04 sshd is socket-activated and a port change needs extra
care. If you must, change it deliberately and use that port in A6 and in the
`PROD_SSH_PORT` secret.

Evidence: the `sshd -T` lines and the two test results.

### A6. Firewall

**Who:** Operator. **Why:** MySQL and Redis must not be reachable from the
Internet even by mistake.

```bash
ufw default deny incoming
ufw default allow outgoing
ufw allow 22/tcp
ufw allow 80/tcp
ufw allow 443/tcp
ufw --force enable
ufw status verbose
```

Do not restrict SSH to particular source addresses and do not add `ufw limit`:
GitHub's runners connect from addresses that are not published as a short,
stable list.

Evidence: `ufw status verbose`, and an external port scan result from the
owner's machine showing only 22, 80 and 443 open (for example `nmap -Pn
203.0.113.10`).

### A7. Automatic security updates

**Who:** Operator.

```bash
cat > /etc/apt/apt.conf.d/20auto-upgrades <<'EOF'
APT::Periodic::Update-Package-Lists "1";
APT::Periodic::Unattended-Upgrade "1";
EOF
unattended-upgrade --dry-run --debug 2>&1 | tail -5
```

Two limits to know about:

- Unattended upgrades cover Ubuntu's own security pocket. **PHP packages from
  the `ondrej/php` PPA and MySQL packages from MySQL's repository are not
  upgraded automatically.** Section E makes that a
  monthly manual task.
- The host does not reboot by itself. A new kernel waits for a deliberate reboot
  (section E).

### A8. MySQL 8.4 LTS

**Who:** Operator.

**Install it from MySQL's official APT repository, not Ubuntu's `mysql-server`
(8.0).** CI and the dev target test 8.4; production must match.

1. Open <https://dev.mysql.com/downloads/repo/apt/> and download the current
   `mysql-apt-config_<version>_all.deb`. Check the checksum the page publishes
   (`md5sum` the download); the file name carries a version this runbook cannot
   fix in advance.
2. Preselect the 8.4 LTS series so the installer does not prompt, install the
   repository package, and install the server:

   ```bash
   echo "mysql-apt-config mysql-apt-config/select-server select mysql-8.4-lts" | debconf-set-selections
   DEBIAN_FRONTEND=noninteractive dpkg -i mysql-apt-config_*_all.deb
   apt-get update
   apt-cache policy mysql-server | head -4           # candidate must be 8.4.x from repo.mysql.com
   DEBIAN_FRONTEND=noninteractive apt-get install -y mysql-server
   ```

   If the candidate is not 8.4, stop and fix the repository selection
   (`dpkg-reconfigure mysql-apt-config`) before installing. If the installer
   asks for a root password, accept the default (socket authentication as OS
   root); the application never uses root.
3. Verify the series before creating anything:

   ```bash
   mysql -N -e 'SELECT VERSION();'        # must start with 8.4.
   ```

   If it does not start with `8.4.`, stop: the release preflight accepts any
   `8.x` and would not catch the mismatch.

MySQL's repository is a third-party source like the PPA, and unattended upgrades
do not cover it (see A7 and section E).

Then make a private place for generated secrets. It is deleted at the end of
B5, after the secrets are in the password manager and in `.env`.

```bash
umask 077
install -d -m 0700 /root/tc-secrets
openssl rand -hex 24 > /root/tc-secrets/db_password
openssl rand -hex 32 > /root/tc-secrets/redis_password
echo "base64:$(openssl rand -base64 32)" > /root/tc-secrets/app_key
```

Create the database and a user that can reach only that database:

```bash
mysql <<SQL
CREATE DATABASE training_center CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'training_center'@'localhost' IDENTIFIED BY '$(cat /root/tc-secrets/db_password)';
GRANT ALL PRIVILEGES ON training_center.* TO 'training_center'@'localhost';
SQL
```

The password reaches `mysql` on standard input, not in its arguments. The grant
is scoped to one schema and carries none of the global privileges (such as
`PROCESS`) that the backup is deliberately written not to need.

Check it:

```bash
mysql --version                                    # 8.4.x
ss -ltnp | grep -E ':(3306|33060) '                # 127.0.0.1 only
MYSQL_PWD="$(cat /root/tc-secrets/db_password)" mysql -h 127.0.0.1 -u training_center training_center -e 'SELECT VERSION();'
```

Evidence: `SELECT VERSION();` (starts with `8.4.`), and the `ss` output showing loopback only. Never paste
the password.

### A9. Redis

**Who:** Operator. **Why:** Redis is on the request path (the rate limiter) and
holds the queue. It must be authenticated, loopback-only, size-limited, and it
must **refuse writes when full** (`noeviction`) rather than silently discard
queued jobs.

The reviewed templates were copied to the host in A3a. Then:

```bash
install -o root -g root -m 0644 /root/tc-deploy-files/deploy/redis/training-center.conf.example /etc/redis/training-center.conf
printf 'requirepass %s\n' "$(cat /root/tc-secrets/redis_password)" > /etc/redis/training-center-secret.conf
chown root:redis /etc/redis/training-center-secret.conf
chmod 0640 /etc/redis/training-center-secret.conf
echo 'include /etc/redis/training-center.conf' >> /etc/redis/redis.conf
systemctl restart redis-server
```

The include goes at the **end** of `redis.conf` so its settings override the
package defaults. The secret lives in a separate root-and-redis-only file that
the template includes. The template starts `maxmemory` at 256 MiB; revisit it
from measurements (section E).

Verify, with the password passed through the environment rather than an
argument:

```bash
export REDISCLI_AUTH="$(cat /root/tc-secrets/redis_password)"
redis-cli ping                          # PONG
redis-cli config get maxmemory-policy   # noeviction
redis-cli config get maxmemory          # 268435456
redis-cli config get appendonly         # yes
unset REDISCLI_AUTH
redis-cli ping                          # NOAUTH Authentication required.
ss -ltnp | grep ':6379 '                # 127.0.0.1 and ::1 only
```

If Redis refuses to start because it cannot bind `::1`, report it; do not widen
the bind address.

Evidence: the output above (no password in it).

### A10. PHP configuration and extensions

**Who:** Operator.

```bash
cat > /etc/php/8.4/cli/conf.d/99-training-center.ini <<'EOF'
; release.sh needs a finite limit at least 24 MiB above the Horizon worker ceiling (96 MiB).
memory_limit = 128M
EOF
cat > /etc/php/8.4/fpm/conf.d/99-training-center.ini <<'EOF'
; The largest upload the application accepts is 10 MiB; Nginx allows 20 MiB.
upload_max_filesize = 12M
post_max_size = 20M
EOF
systemctl restart php8.4-fpm
```

Why 128M for the CLI: Horizon's own master gets `-d memory_limit=128M` from its
systemd unit, but its worker children start fresh PHP processes with the default
CLI ini. The ceiling has to be below the limit those children really run under
(`docs/superpowers/specs/2026-07-20-training-center-dashboard-design.md` §11).

Why the upload limits: PHP's default `upload_max_filesize` is 2M, which would
silently refuse the 10 MiB certificate and article uploads that the application
accepts.

Check the runtime user sees the same limit, and that the extensions
`release.sh` demands are present:

```bash
sudo -u www-data /usr/bin/php -r 'echo ini_get("memory_limit"), PHP_EOL;'    # 128M
for e in redis pcntl posix pdo_mysql mbstring intl bcmath gd zip; do
  printf '%-10s cli=%s fpm=%s\n' $e "$(php -m | grep -Fxic $e)" "$(php-fpm8.4 -m | grep -Fxic $e)"
done
```

Every row must read `cli=1`. Every row except `pcntl` must also read `fpm=1`;
`pcntl` is CLI-only in Ubuntu's PHP packages, so `fpm=0` is expected there. That
is why the first deploy needs PR #129 (section 0).

Evidence: the table above.

### A11. The directory layout

**Who:** Operator.

```bash
install -d -o training-center -g training-center -m 0755 /srv/training-center
install -d -o training-center -g www-data       -m 0750 /srv/training-center/releases
install -d -o training-center -g www-data       -m 0750 /srv/training-center/shared
install -d -o www-data        -g www-data       -m 2770 /srv/training-center/shared/storage
install -d -o training-center -g training-center -m 0700 /srv/training-center/shared/incoming
install -d -o root            -g root           -m 0755 /srv/training-center/shared/migration-approvals
install -d -m 0755 /var/www/letsencrypt /var/www/maintenance
```

What each is for:

| Path | Owner | Why |
|---|---|---|
| `releases/` | `training-center:www-data` | Where each release is staged under its 40-hex SHA. PHP can read, only the deploy account writes |
| `shared/` | `training-center:www-data` | Holds the one `.env`, the uploads, the logs and the locks. PHP can traverse it |
| `shared/storage/` | `www-data:www-data` | Uploads, sessions, cache, logs. Only PHP writes the contents |
| `shared/incoming/` | `training-center`, mode 0700 | `receive.sh` writes the uploaded archive here. It never creates this directory, so it must exist |
| `shared/migration-approvals/` | **root**, not group- or world-writable | The release refuses an approval directory or file that the deploy account could have written (`approval_is_safe`) |
| `/var/www/letsencrypt` | root | Certificate challenge files, outside every release and every upload path |

`release.sh` additionally creates `shared/deploy-logs/` and `shared/deploy.lock`
and, on success, `shared/deployed-sha`.

### A12. The backup volume (temporary, on the server)

**Who:** Operator. **Why this exists and what it does not do:** read section F
before doing this step.

The backup path must be outside `/srv/training-center` and on a different
filesystem, and `www-data` must be able to write to it. On a one-disk VPS the way
to get a different filesystem is a loop-mounted image:

```bash
fallocate -l 10G /var/lib/training-center-backup.img
chmod 0600 /var/lib/training-center-backup.img
mkfs.ext4 -q -L tc-backups /var/lib/training-center-backup.img
install -d -m 0755 /var/backups/training-center
echo '/var/lib/training-center-backup.img /var/backups/training-center ext4 loop,nofail,noatime 0 2' >> /etc/fstab
mount /var/backups/training-center
chown www-data:www-data /var/backups/training-center
chmod 0750 /var/backups/training-center
sudo -u www-data touch /var/backups/training-center/.training-center-backup-volume
```

The image is a fixed-size, fully allocated file (10 GiB by default; the VPS has
40 GB), and the `/etc/fstab` line remounts it at every boot. `nofail` keeps a
failed mount from blocking boot. If the mount is ever missing,
`/var/backups/training-center` becomes an ordinary folder on the root
filesystem, and the guard refuses to back up into it (exactly the case it was
written for). Ten GiB is the default for one to two weeks; the archives start
small. If `fallocate` is refused, use `dd if=/dev/zero of=... bs=1M count=10240`.

Check that the filesystem really differs, and the marker exists:

```bash
stat -c '%d  %n' /var/backups/training-center /srv/training-center     # two different numbers
findmnt /var/backups/training-center
ls -la /var/backups/training-center/.training-center-backup-volume
```

Evidence: the three outputs, and the date. **This is the same physical disk as
the application. It is a temporary exception, accepted by the owner for the
first one to two weeks after launch, and it guards against mistakes and bad
releases, not against losing the server.**

### A13. Install the reviewed host files

**Who:** Operator. The files were copied and checked in A3a.

`deploy/` is deliberately **not** in the release archive: the host runs its own
installed copies, so a commit can never rewrite the code that validates it.
Updating them is a deliberate host step (C6).

```bash
cd /root/tc-deploy-files
install -o root -g root -m 0755 deploy/release.sh /usr/local/bin/training-center-release
install -o root -g root -m 0755 deploy/receive.sh /usr/local/bin/training-center-receive
install -o root -g root -m 0644 deploy/systemd/training-center-horizon.service /etc/systemd/system/
install -o root -g root -m 0644 deploy/systemd/training-center-pulse.service   /etc/systemd/system/
systemctl daemon-reload
```

Install the narrowly scoped sudo rules the release script needs:

```bash
cat > /etc/sudoers.d/training-center <<'EOF'
training-center ALL=(www-data) NOPASSWD: /usr/bin/php
training-center ALL=(root) NOPASSWD: /usr/bin/systemctl reload php8.4-fpm.service, /usr/bin/systemctl start training-center-horizon.service training-center-pulse.service
EOF
chmod 0440 /etc/sudoers.d/training-center
visudo -cf /etc/sudoers.d/training-center
```

The first rule lets the deploy account run PHP **as `www-data`** (the same
identity the application already runs as); it grants nothing as root. The second
admits exactly one reload and one start, with those exact arguments. Test them
the way `release.sh` does:

```bash
sudo -u training-center sudo -n -u www-data /usr/bin/php --version | head -1
sudo -u training-center sudo -n -l -- /usr/bin/systemctl reload php8.4-fpm.service
sudo -u training-center sudo -n -l -- /usr/bin/systemctl start training-center-horizon.service training-center-pulse.service
```

Do **not** `enable` or `start` the two units yet: there is no `current` for them
to run (B10 does it).

Evidence: the three sudo results.

### A14. The deploy key

**Who:** Operator, on their own machine. **Why a new key:** this key is stored as
a GitHub secret. It must not be anyone's personal key, and it must be able to do
one thing.

```bash
ssh-keygen -t ed25519 -N '' -C 'training-center-deploy' -f ./tc-deploy
```

It has no passphrase because the workflow uses it unattended; the GitHub
environment's reviewers are the control. Install the **public** half on the host
with a forced command and `restrict`. The file is root-owned so that the deploy
account cannot rewrite its own restrictions:

```bash
install -d -o root -g root -m 0755 /home/training-center/.ssh
cat > /home/training-center/.ssh/authorized_keys <<'EOF'
command="/usr/local/bin/training-center-receive",restrict ssh-ed25519 AAAA...paste-the-contents-of-tc-deploy.pub... training-center-deploy
EOF
chown root:root /home/training-center/.ssh/authorized_keys
chmod 0644 /home/training-center/.ssh/authorized_keys
```

`restrict` disables port forwarding, agent forwarding, X11 and tty allocation.
`receive.sh` accepts one thing: a 40-hex SHA as the "command", with the archive
on standard input. A leaked GitHub secret therefore yields no shell, no
forwarding and no file access, and still cannot deploy without passing the
workflow's gates, because the host has its own approval files (section C).

Test from the operator's machine. Both must be **refused by the script**, which
proves the forced command is in force:

```bash
ssh -i ./tc-deploy -o IdentitiesOnly=yes training-center@203.0.113.10
#   Receive refused: no command: this key only deploys a release (expected a 40-hex SHA)
ssh -i ./tc-deploy -o IdentitiesOnly=yes training-center@203.0.113.10 id
#   Receive refused: expected exactly one 40-hex lowercase SHA
```

Keep `tc-deploy` (the private key) only until B6 has stored it in GitHub, then
delete it: `shred -u ./tc-deploy` (and keep nothing else but the public half).

Evidence: both refusal messages. Do not keep the private key.

---

## B. DNS and certificate bootstrap, then the first install

The order matters. The certificate needs DNS pointing at the host. DNS pointing
at the host must **not** mean the application is live. So the host serves a
maintenance page first, then a certificate is issued, then HTTPS maintenance,
then the application's TLS site (which answers 503 until the first release),
then the release.

**DNS pointing at the VPS is not launch approval.** Launch is declared only at
B11.

### B1. A default site that answers nothing, and the HTTP-only bootstrap site

**Who:** Operator.

A request for the server's bare address, or for any hostname that is not ours,
must not reach the application. Remove Ubuntu's default site and add a catch-all:

```bash
rm -f /etc/nginx/sites-enabled/default
cat > /etc/nginx/conf.d/00-default-reject.conf <<'EOF'
server {
    listen 80 default_server;
    listen [::]:80 default_server;
    server_name _;
    return 444;
}
server {
    listen 443 ssl default_server;
    listen [::]:443 ssl default_server;
    server_name _;
    ssl_reject_handshake on;
}
EOF
```

Then the HTTP-only bootstrap site. Set `DOMAIN` and the same variable is used in
every Nginx and `.env` command below. **Set it again at the start of every new
shell.**

```bash
DOMAIN=center.example     # the real domain, typed on the host only

cat > /var/www/maintenance/maintenance.html <<'EOF'
<!doctype html>
<meta charset="utf-8">
<title>Back soon</title>
<p>We are carrying out maintenance and will be back soon.</p>
EOF

cat > /etc/nginx/sites-available/training-center-maintenance-http.conf <<'EOF'
server {
    listen 80;
    listen [::]:80;
    server_name center.example;
    location ^~ /.well-known/acme-challenge/ {
        root /var/www/letsencrypt;
        default_type text/plain;
        try_files $uri =404;
    }
    location / { return 503; }
    error_page 503 /maintenance.html;
    location = /maintenance.html { root /var/www/maintenance; internal; }
}
EOF
sed -i "s/center\.example/$DOMAIN/g" /etc/nginx/sites-available/training-center-maintenance-http.conf
ln -sfn ../sites-available/training-center-maintenance-http.conf /etc/nginx/sites-enabled/training-center.conf
nginx -t && systemctl reload nginx
```

Everything is served from `/var/www/letsencrypt` (challenge files) and
`/var/www/maintenance`. Nothing here is under `/srv/training-center`, so a
certificate challenge can never expose a release or an upload.

Verify on the host itself:

```bash
curl -s -o /dev/null -w '%{http_code}\n' -H "Host: $DOMAIN" http://127.0.0.1/       # 503
curl -s -o /dev/null -w '%{http_code}\n' http://127.0.0.1/                          # curl: (52) Empty reply from server (444 closes the connection)
```

Evidence: both results and `nginx -t` output.

### B2. Point DNS at the host

**Who:** Owner (the DNS account), Operator verifies.

1. At the DNS provider, create an **A** record for the domain pointing at
   `203.0.113.10`, with a short TTL (300 seconds) for the first days.
2. Create an **AAAA** record **only if** the VPS has a working public IPv6
   address (`ip -6 addr`) and you have confirmed `ufw` admits it. Let's
   Encrypt prefers IPv6 when it exists: an AAAA record to an address that does
   not answer fails certificate issuance.
3. Verify from a machine **outside** the VPS, once for each family you published:

   ```bash
   dig +short A    center.example
   dig +short AAAA center.example
   curl -4 -sI http://center.example/ | head -1      # HTTP/1.1 503 Service Temporarily Unavailable
   curl -6 -sI http://center.example/ | head -1      # only if an AAAA exists
   ```

Evidence: the `dig` and `curl` outputs, the TTL, the date and time DNS went live.

### B3. Issue the certificate (HTTP-01)

**Who:** Operator.

```bash
certbot certonly --webroot -w /var/www/letsencrypt -d "$DOMAIN" \
    --email ops@center.example --agree-tos --no-eff-email \
    --deploy-hook 'systemctl reload nginx'
certbot certificates
```

The deploy hook is stored in the renewal configuration, so every renewal
reloads Nginx on its own. Renewal itself is run by the `certbot.timer` unit that
the Ubuntu package installs. Check both:

```bash
systemctl list-timers certbot.timer
certbot renew --dry-run
```

Evidence: `certbot certificates` (domain, expiry, paths), the timer line and the
dry-run result.

### B4. HTTPS-capable maintenance

**Who:** Operator. **Why:** the application's vhost sends `Strict-Transport-Security`
even on its own 503. From the moment a browser sees that header, plain HTTP is
no longer a safe fallback for it. So the host needs a maintenance site that
speaks valid HTTPS, ready *before* the application vhost goes live, and kept for
any first-install failure.

```bash
cat > /etc/nginx/sites-available/training-center-maintenance.conf <<'EOF'
server {
    listen 80;
    listen [::]:80;
    server_name center.example;
    location ^~ /.well-known/acme-challenge/ {
        root /var/www/letsencrypt;
        default_type text/plain;
        try_files $uri =404;
    }
    location / { return 301 https://center.example$request_uri; }
}
server {
    listen 443 ssl;
    listen [::]:443 ssl;
    server_name center.example;
    ssl_certificate /etc/letsencrypt/live/center.example/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/center.example/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    add_header Strict-Transport-Security "max-age=31536000" always;
    location ^~ /.well-known/acme-challenge/ {
        root /var/www/letsencrypt;
        default_type text/plain;
        try_files $uri =404;
    }
    location / { return 503; }
    error_page 503 /maintenance.html;
    location = /maintenance.html { root /var/www/maintenance; internal; }
}
EOF
sed -i "s/center\.example/$DOMAIN/g" /etc/nginx/sites-available/training-center-maintenance.conf
ln -sfn ../sites-available/training-center-maintenance.conf /etc/nginx/sites-enabled/training-center.conf
nginx -t && systemctl reload nginx
```

Verify from outside the VPS, **without** `-k`: a certificate error here is a
failure, not a nuisance.

```bash
curl -sI https://center.example/ | grep -iE '^(HTTP|strict-transport-security)'    # 503 + the HSTS header
curl -sI http://center.example/  | grep -iE '^(HTTP|location)'                     # 301 to https
mkdir -p /var/www/letsencrypt/.well-known/acme-challenge && echo ok > /var/www/letsencrypt/.well-known/acme-challenge/probe
curl -s http://center.example/.well-known/acme-challenge/probe                     # ok  (renewal path still reachable over HTTP)
rm /var/www/letsencrypt/.well-known/acme-challenge/probe
```

Evidence: all four outputs, and the certificate's validity dates
(`echo | openssl s_client -connect center.example:443 -servername center.example 2>/dev/null | openssl x509 -noout -dates`).

### B5. The production `.env`

**Who:** Operator. The owner supplies the human-chosen values (Brevo credentials,
alert mailbox, archive password) at the keyboard on the host, never in chat or
email.

Build the file from the generated secrets and fill the remaining values by
editing it. Quoted `'EOF'` is not used here on purpose, so the generated secrets
are read from their files; no other dollar signs appear.

```bash
umask 027
cat > /srv/training-center/shared/.env <<EOF
APP_NAME="A.S.C.L.S.T"
APP_ENV=production
APP_KEY=$(cat /root/tc-secrets/app_key)
APP_DEBUG=false
APP_URL=https://$DOMAIN
APP_LOCALE=en
APP_FALLBACK_LOCALE=en
APP_MAINTENANCE_DRIVER=file

LOG_CHANNEL=stack
LOG_STACK=daily
LOG_DAILY_DAYS=14
LOG_LEVEL=info

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=training_center
DB_USERNAME=training_center
DB_PASSWORD=$(cat /root/tc-secrets/db_password)

SESSION_DRIVER=database
SESSION_LIFETIME=480
SESSION_ENCRYPT=false
SESSION_SECURE_COOKIE=true
BCRYPT_ROUNDS=12
BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local

QUEUE_CONNECTION=redis
CACHE_STORE=database
CACHE_LIMITER=redis
REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=$(cat /root/tc-secrets/redis_password)

MAIL_MAILER=smtp
MAIL_HOST=smtp-relay.brevo.com
MAIL_PORT=587
MAIL_USERNAME=<SECRET>
MAIL_PASSWORD=<SECRET>
MAIL_FROM_ADDRESS=<SECRET>
MAIL_FROM_NAME="A.S.C.L.S.T"
HORIZON_ALERT_EMAIL=<SECRET>

BACKUP_DISK=backups_local
BACKUP_LOCAL_PATH=/var/backups/training-center
BACKUP_VOLUME_MARKER=.training-center-backup-volume
BACKUP_ARCHIVE_PASSWORD=<SECRET>
BACKUP_ARCHIVE_NAME=training-center
BACKUP_EXPECTED_ARCHIVE_MB=250
BACKUP_ALERT_EMAIL=<SECRET>
EOF
chown training-center:www-data /srv/training-center/shared/.env
chmod 0640 /srv/training-center/shared/.env
```

Replace the six `<SECRET>` placeholders by editing the file with
`nano` or `vi` as root:

| Variable | Value | Note |
|---|---|---|
| `MAIL_USERNAME`, `MAIL_PASSWORD` | The Brevo SMTP login and SMTP key | From the Brevo console. Never reuse the Brevo account password |
| `MAIL_FROM_ADDRESS` | A sender address Brevo has verified (the domain's SPF/DKIM done in Brevo by the owner) | An unverified sender is rejected by Brevo, which the deploy preflight cannot see (it never authenticates) |
| `HORIZON_ALERT_EMAIL`, `BACKUP_ALERT_EMAIL` | A mailbox people actually read | Production refuses to boot on an absent or invalid Horizon address |
| `BACKUP_ARCHIVE_PASSWORD` | A long random password the owner generates **and also stores in a password manager** | Losing it makes every archive unreadable, with no recovery path |

`LOG_LEVEL=info` and `SESSION_SECURE_COOKIE=true` are proposals, not requirements
of the release script: `info` keeps useful diagnostics without `debug` noise,
and the site is HTTPS-only. The Owner may change either.

Leave `MAIL_SCHEME` unset. Port 587 negotiates STARTTLS; the release preflight
requires it.

Check nothing was left unfilled, then take the secrets off the host's scratch
area. **Before deleting `/root/tc-secrets`, the owner copies `app_key`,
`db_password` and `redis_password` into the password manager**, together with the
Brevo credentials and `BACKUP_ARCHIVE_PASSWORD`. `APP_KEY` in particular: if it
is lost, encrypted data and signed links cannot be recovered.

```bash
grep -n '<SECRET>' /srv/training-center/shared/.env && echo "STILL UNFILLED" || echo "all filled"
stat -c '%U:%G %a' /srv/training-center/shared/.env          # training-center:www-data 640
# after the password manager holds them:
shred -u /root/tc-secrets/* && rmdir /root/tc-secrets
```

Variables that must not be set to anything else: `APP_ENV=production`,
`APP_DEBUG=false`, an `https://` `APP_URL`, `QUEUE_CONNECTION=redis`,
`CACHE_LIMITER=redis`, `CACHE_STORE=database`, `SESSION_DRIVER=database`,
`DB_CONNECTION=mysql`, `LOG_CHANNEL=stack` with `daily` in `LOG_STACK` and
`LOG_DAILY_DAYS` at least 1. The release preflight checks each one and refuses
the release otherwise. `TRUSTED_PROXIES` is absent on purpose (TLS ends here).
Do not change `APP_NAME` or `BACKUP_ARCHIVE_NAME` after launch without reading
the comments in `.env.example`: they move cookie names, cache prefixes and the
backup directory.

Evidence: the `all filled` line and the permissions line. Never the file.

### B6. GitHub: the `production` environment

**Who:** Owner. **Why:** these settings are the approval gate. They live in
GitHub, not in the repository.

In the repository: *Settings → Environments → New environment*, named exactly
`production`.

1. **Required reviewers:** add both owners. Turn **Prevent self-review** on, so
   whoever dispatches a deploy cannot also approve it. (With a single reviewer
   this would deadlock; keep both owners as reviewers.)
2. **Deployment branches and tags:** *Selected branches and tags* → add the
   rule `main`.
3. **Environment secrets** (not repository secrets):

   | Name | Value |
   |---|---|
   | `PROD_SSH_USER` | `training-center` |
   | `PROD_SSH_HOST` | `203.0.113.10` (the address, so the deploy does not depend on DNS) |
   | `PROD_SSH_PORT` | `22` |
   | `PROD_SSH_KEY` | The whole private key file from A14, including the `BEGIN` and `END` lines |

4. **Environment variable** (a variable, not a secret): `PROD_SSH_KNOWN_HOSTS`,
   the host's public keys, one per line, with the host written **exactly as
   `PROD_SSH_HOST` is**. Produce it on the VPS:

   ```bash
   for f in /etc/ssh/ssh_host_*_key.pub; do awk -v h="203.0.113.10" '{print h, $1, $2}' "$f"; done
   ```

   Each line is `203.0.113.10 ssh-ed25519 AAAA...`. If the port is not 22, the
   host is written `[203.0.113.10]:2222` instead. Compare one fingerprint with
   what you saw at the first login (`ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub`).

   The workflow connects with `StrictHostKeyChecking=yes` against this file
   only. It never uses `ssh-keyscan`, so an impostor host fails closed.

5. Add nothing else. In particular **no `.env` content, no database or Redis
   password, no mail credential** goes into GitHub. Actions logs on a public
   repository are public; the workflow prints step names and the SHA, not
   secrets.

Then delete the private key from the operator's machine (`shred -u ./tc-deploy`).

Evidence: screenshots of the environment's settings page (reviewers, branch rule,
secret *names*, variable name; never values), and the date.

### B7. Approvals and the application vhost

**Who:** Operator, with the owner choosing the SHA.

1. **Decide the SHA.** It is the tip of `main` that has a green `verify` run in
   CI. The workflow dispatched in B8 deploys the tip of `main` *at the moment it
   is dispatched*. If `main` moves afterwards, the SHA changes and the approval
   files below no longer match; make new ones.
2. **Record the host-side approvals**, as root, with that SHA. Both files contain
   the SHA and nothing else, and are root-owned and not group- or world-writable:

   ```bash
   sha=<the 40-hex SHA>
   printf '%s\n' "$sha" > /srv/training-center/shared/first-install-approved
   printf '%s\n' "$sha" > /srv/training-center/shared/migration-approvals/$sha
   chown root:root /srv/training-center/shared/first-install-approved /srv/training-center/shared/migration-approvals/$sha
   chmod 0644      /srv/training-center/shared/first-install-approved /srv/training-center/shared/migration-approvals/$sha
   ls -l /srv/training-center/shared/first-install-approved /srv/training-center/shared/migration-approvals/
   ```

   The first-install file says "this exact commit may create the very first
   release". The migration file says "a reviewer has confirmed this commit's
   migrations are safe". On a first install there is no earlier code to be
   incompatible with, so the review is confirming that the migrations are the
   reviewed ones. **These two files are independent of the GitHub approval in B8.
   The GitHub approval does not create or replace either.**
3. **Switch Nginx to the application's TLS site** (it answers a built-in 503
   until `current` exists):

   ```bash
   sed "s/center\.example/$DOMAIN/g" /root/tc-deploy-files/deploy/nginx/training-center.conf.example \
       > /etc/nginx/sites-available/training-center-app.conf
   ln -sfn ../sites-available/training-center-app.conf /etc/nginx/sites-enabled/training-center.conf
   nginx -t && systemctl reload nginx
   ```

   Keep `training-center-maintenance.conf` in `sites-available`: it is the
   recovery site for B9.

   Verify, from outside, with no `-k`:

   ```bash
   curl -sI https://center.example/ | grep -iE '^(HTTP|strict-transport-security)'    # 503 + HSTS
   curl -s -o /dev/null -w '%{http_code}\n' https://center.example/.env              # 403 or 404, never 200
   ```

   The 503 comes from the template's `try_files $uri =503` on `/index.php`: with
   no `current/public/index.php` the request never reaches PHP-FPM.

Evidence: the `ls -l` of the approval files, the approved SHA, and the two curl
results.

### B8. Dispatch and approve the first release

**Who:** Owner dispatches; Reviewer approves.

1. *Actions → Deploy → Run workflow*, branch **main** (or
   `gh workflow run deploy.yml --ref main`).
2. The `guard` job refuses any other ref and requires `ci.yml`'s `verify` job to
   have succeeded for exactly that SHA on `main`. A missing, running, failed or
   cancelled CI run stops here, before anything is built.
3. The `build` job builds that SHA without secrets and without a production
   `.env`.
4. The `deploy` job waits for the `production` environment. The Reviewer opens
   the run, checks the SHA matches the one in B7, and chooses *Review
   deployments → Approve*.
5. The job re-checks that `main` still points at the SHA, then makes one SSH
   call that streams the archive. `receive.sh` hands it to
   `/usr/local/bin/training-center-release`.

Watch the host while it runs:

```bash
tail -F "$(ls -t /srv/training-center/shared/deploy-logs/*.log | head -1)"
```

A successful run ends with `Release <sha> succeeded. Previous code and logs
retained.` and `shared/deployed-sha` holds the SHA.

**Brief public exposure is accepted by the owner.** After the atomic switch the
application is visible on the public address while the post-switch HTTPS checks
run. There is no internal-only probe and no disabled certificate verification:
the release checks the real certificate over loopback (`curl --resolve
$host:443:127.0.0.1`). Launch is not declared until B11.

Evidence: the Actions run URL, the approver's name and time, the SHA, and the
final lines of the deploy log.

### B9. If the first install fails

**Who:** Operator, immediately. The owner is told.

There is no automatic rollback. The first install has no earlier release to go
back to, and the application's vhost sends HSTS even on its 503, so **do not
fall back to the HTTP-only bootstrap site**. Go back to HTTPS-capable
maintenance:

```bash
ln -sfn ../sites-available/training-center-maintenance.conf /etc/nginx/sites-enabled/training-center.conf
nginx -t && systemctl reload nginx
curl -sI https://center.example/ | grep -iE '^(HTTP|strict-transport-security)'    # 503 + HSTS, valid certificate
systemctl stop training-center-horizon training-center-pulse 2>/dev/null || true
```

Then preserve everything: the failed release stays in
`/srv/training-center/releases/<sha>` and its logs stay in
`/srv/training-center/shared/deploy-logs/`. Investigate with section D. To retry
the same SHA, follow D5 and read the extra first-install note there.

Evidence: the maintenance verification above, the failed step name (printed as
`Release failed at <step>`), and the log file name.

### B10. After the first successful release

**Who:** Operator, with both owners present for the second command.

Confirm what is live:

```bash
cat /srv/training-center/shared/deployed-sha
readlink -f /srv/training-center/current
systemctl is-active training-center-horizon training-center-pulse     # active, active
cd /srv/training-center/current && sudo -u www-data /usr/bin/php artisan horizon:status
```

Make both units start at boot, and install the scheduler's cron entry:

```bash
systemctl enable training-center-horizon training-center-pulse
install -o root -g root -m 0644 /root/tc-deploy-files/deploy/cron/training-center.cron /etc/cron.d/training-center
```

Seed roles and permissions. **Only this seeder**, never `DatabaseSeeder`:

```bash
cd /srv/training-center/current && sudo -u www-data /usr/bin/php artisan db:seed --class=RolePermissionSeeder --force
```

Create the two administrators. The command is interactive and production-only,
and it refuses to run if any user already exists. **Each owner types their own
name, email and password at the hidden prompt, each followed by a hidden
confirmation.** No password goes on the command line, and nothing is echoed.

```bash
cd /srv/training-center/current && sudo -u www-data /usr/bin/php artisan staff:bootstrap-production-owner
```

It prints `Production administrators created.` on success. If it refuses (a
user exists, the role is missing, a password is too weak or a confirmation does
not match) it creates **neither** account. Passwords need at least eight
characters including a capital letter and a symbol.

Evidence: the success line. Never the passwords.

### B11. Acceptance, and launch

**Who:** as shown. The milestone is **not complete** until every row passes and
the owner explicitly approves launch.

| # | Check | Who | How | Pass when |
|---|---|---|---|---|
| 1 | Both owners can sign in, with no forced password change | Owners | `https://center.example/admin/login` | Each lands in the dashboard directly |
| 2 | HTTPS smoke from outside | Operator | `for p in /up / /admin/login /portal/login; do curl -fsS -o /dev/null -w "%{http_code} $p\n" https://center.example$p; done` | Four `200`s, no `-k` needed |
| 3 | Sensitive paths closed | Operator | `curl -s -o /dev/null -w '%{http_code}\n' https://center.example/.env` and `/storage/app/secure/x` and `/composer.json` | None is `200` |
| 4 | Both supervised processes run | Operator | `systemctl is-active training-center-horizon training-center-pulse`; `horizon:status`; the owner opens `/horizon` and `/pulse` | `active`, "running", and Pulse's servers card shows live CPU/memory/disk (that data only exists if `pulse:check` is running) |
| 5 | The one-minute scheduler runs | Operator | wait two minutes, then `ls -l --time-style=full-iso /srv/training-center/shared/storage/logs/scheduler-*.log` and `tail` it; `schedule:list` | The file's timestamp is within the last minute |
| 6 | Mail delivery works | Operator + Owner | See B11a | A real message arrives in the alert mailbox |
| 7 | A backup runs and the destination guard works | Operator | See B11a | The archive exists; the unmounted run fails loudly |
| 8 | A restore works (on-server drill) | Operator | See B11b | Counts and file lists match the live system |
| 9 | Owner approval | Owner | A written "launch approved" with date, in the evidence log | Present |

**B11a. Mail and backup.** The Brevo credentials have never been used so far; the
release preflight only checks that the SMTP port answers and negotiates TLS. This
step proves authentication and delivery with the application's own alert path.
The backup package is configured to mail both failures and successes.

```bash
cd /srv/training-center/current
sudo -u www-data /usr/bin/php artisan backup:run
sudo -u www-data /usr/bin/php artisan backup:monitor
sudo -u www-data /usr/bin/php artisan backup:list
ls -la /var/backups/training-center/training-center/
```

Expect an archive `training-center-<timestamp>.zip` and a notification mail in the
alert mailbox (allow a couple of minutes; if none arrives, check `horizon:status`,
`artisan queue:failed`, and Brevo's activity log). Then prove the guard, as
`docs/RESTORE.md` requires:

```bash
umount /var/backups/training-center
cd /srv/training-center/current && sudo -u www-data /usr/bin/php artisan backup:run; echo "exit=$?"
mount /var/backups/training-center
```

It must **fail**, print a line beginning `The backup destination is not ready:`,
exit non-zero, and send a backup-failure mail. Check the mail arrived, not just
the exit code. If it *succeeds*, archives are going to the server's own disk
through an empty folder: stop and fix A12.

**B11b. On-server restore drill.** This restores into a scratch database and
directory. It proves the archive, its password and the dump are usable; it does
not prove survival of losing the server (section F does that later).

```bash
mkdir -m 0700 /root/restore-drill && cd /root/restore-drill
archive=$(ls -t /var/backups/training-center/training-center/training-center-*.zip | head -1)
read -rsp 'Archive password: ' BACKUP_ARCHIVE_PASSWORD; echo; export BACKUP_ARCHIVE_PASSWORD
php -r '$z=new ZipArchive; $z->open($argv[1]); $z->setPassword(getenv("BACKUP_ARCHIVE_PASSWORD")); var_dump($z->extractTo("restore/")); $z->close();' "$archive"
unset BACKUP_ARCHIVE_PASSWORD
ls restore/db-dumps restore/storage/app
mysql -e "CREATE DATABASE restore_drill CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
mysql restore_drill < restore/db-dumps/*.sql
mysql -N -e "SELECT COUNT(*) FROM restore_drill.users"            # 2
mysql -N -e "SELECT COUNT(*) FROM training_center.users"          # 2
mysql -N -e "SELECT COUNT(*) FROM restore_drill.migrations"; mysql -N -e "SELECT COUNT(*) FROM training_center.migrations"
find restore/storage/app/secure restore/storage/app/public -type f | wc -l
find /srv/training-center/shared/storage/app/secure /srv/training-center/shared/storage/app/public -type f | wc -l
```

Use `unzip` only if it works; as `docs/RESTORE.md` warns, most distributions' `unzip`
cannot read AES-256 archives, which is why PHP's `ZipArchive` is used here. Clean
up:

```bash
mysql -e "DROP DATABASE restore_drill"
cd / && rm -rf /root/restore-drill
```

The scratch directory holds the whole database and every uploaded document in
the clear; delete it the same session.

Evidence for all of B11: the outputs (no secrets), the arrival times of the two
mails, the drill's duration, and the owner's written launch approval.

---

## C. Each update

Every update repeats the same gates. **Two independent approvals, on the same
SHA, are required for every deploy, including a retry:**

1. **Host side:** a root-owned file `shared/migration-approvals/<SHA>` containing
   exactly that SHA, created by the Operator after the Reviewer has checked
   migration compatibility.
2. **GitHub side:** the Reviewer's approval of the run in the protected
   `production` environment.

The environment approval does not create the host file, and the host file does
not approve the run. A deploy missing either does not happen.

### C1. Choose the SHA

**Who:** Owner. A merged commit on `main` whose `verify` run in CI is green. Note
the currently deployed SHA:

```bash
cat /srv/training-center/shared/deployed-sha
```

### C2. Review migration compatibility

**Who:** Reviewer. **Why:** the new code goes live after the migration has
already changed the schema, and for a moment both old and new code may be
running (old FPM workers finishing requests, old Horizon workers finishing
jobs). A code symlink reversal cannot undo a schema change.

```bash
git diff --stat <deployed-sha>..<new-sha> -- database/migrations
git diff        <deployed-sha>..<new-sha> -- database/migrations
```

The migrations are compatible only if the **previous** release still works
against the **migrated** schema. In practice: additive changes (new tables, new
nullable or defaulted columns, new indexes). Not compatible: dropping or
renaming a column or table the old code reads, adding a NOT NULL column without a
default, changing a type, or a data change old code would mis-read.

If every migration is compatible, write one line in the evidence log
("`<new-sha>` reviewed by `<name>`: compatible because ...") and continue.

If any is **not** compatible, **stop**. Do not create the approval file. An
incompatible change needs its own reviewed maintenance-window procedure, written
before the release, covering at least: who announces the window, `php artisan down
--with-secret` (the bypass URL is printed to the operator's terminal only and
must never enter a CI log or this repository), a fresh `backup:run`, the exact
deploy, post-checks, and `php artisan up` only when they pass. This runbook does
not contain that procedure.

### C3. Create the host approval

**Who:** Operator, as root.

```bash
sha=<the 40-hex SHA>
printf '%s\n' "$sha" > /srv/training-center/shared/migration-approvals/$sha
chown root:root /srv/training-center/shared/migration-approvals/$sha
chmod 0644      /srv/training-center/shared/migration-approvals/$sha
cat /srv/training-center/shared/migration-approvals/$sha
stat -c '%U %a %n' /srv/training-center/shared/migration-approvals/$sha
```

`release.sh` refuses a file that is a symlink, not root-owned, group- or
world-writable, or whose content is not exactly the SHA.

### C4. Dispatch and approve

**Who:** Owner dispatches (*Actions → Deploy → Run workflow* on `main`); the
other owner approves in GitHub after checking the SHA on the approval page equals
the SHA in the host file name. Only one deploy runs at a time; a second dispatch
waits.

### C5. Verify

**Who:** Operator.

```bash
cat /srv/training-center/shared/deployed-sha                    # the new SHA
readlink -f /srv/training-center/current                        # .../releases/<new SHA>
systemctl is-active training-center-horizon training-center-pulse
cd /srv/training-center/current && sudo -u www-data /usr/bin/php artisan horizon:status
for p in /up / /admin/login /portal/login; do curl -fsS -o /dev/null -w "%{http_code} $p\n" https://center.example$p; done
```

The release itself already did, in order: archive and path validation, shared-state
links, runtime-storage check, FPM extension check, a full production preflight
(PHP, `.env` values, MySQL, Redis password and `noeviction`, backup destination,
mail host reachable over STARTTLS, Horizon memory ceiling), cache rebuild, a
fresh backup of the *previous* code's database and uploads, the migration,
smoke checks before and after the switch, process restarts verified by their
working directory, and real HTTPS requests to the host's own Nginx.

The cache rebuild it ran was exactly these, as `www-data` from the staged
release: `config:cache`, `route:cache`, `view:cache`, `event:cache`,
`filament:optimize`. After the atomic switch it reloaded PHP-FPM and ran
`horizon:terminate` and `pulse:restart`; both units have `Restart=always` and
`RestartSec=5s`, so each returns on the new code after a clean exit.

Evidence: the Actions run URL, the approver, the two SHAs, the log file name, and
the output above.

### C6. When a deploy file changed

**Who:** Operator, with Reviewer. `deploy/release.sh`, `deploy/receive.sh`, the
systemd units, the Nginx and Redis templates and the cron file are not part of the
release archive. When a merged change touches them, repeat the relevant part of
A13 *after* the reviewed commit is merged and *before* the next deploy: verify
checksums against the reviewed commit, `install` the file, `systemctl
daemon-reload` for units, `nginx -t && systemctl reload nginx` for Nginx. Record
the commit SHA that each installed file came from.

### C7. Changing `.env` after launch

**Who:** Operator, with the owner's approval. A deploy re-links `.env` but a
changed `.env` is not a deploy, so it has no approval gate of its own; record who
changed what and why in the evidence log (variable names, not values).

```bash
nano /srv/training-center/shared/.env
cd /srv/training-center/current
for c in config:cache route:cache view:cache event:cache filament:optimize; do sudo -u www-data /usr/bin/php artisan $c --no-interaction; done
systemctl reload php8.4-fpm
sudo -u www-data /usr/bin/php artisan horizon:terminate
sudo -u www-data /usr/bin/php artisan pulse:restart
```

The caches hold the old values and the two long-lived processes read config
once, so all of those steps are needed.

---

## D. A failed release, and recovery

### D1. Read what failed

**Who:** Operator.

The workflow's job log shows the script's last lines. The host keeps the full
log, and **the failed staged code is kept**:

```bash
ls -lt /srv/training-center/shared/deploy-logs/ | head
head -1 "$(ls -t /srv/training-center/shared/deploy-logs/*.log | head -1)"      # "Deployment <sha>; prior <sha>"
tail -40 "$(ls -t /srv/training-center/shared/deploy-logs/*.log | head -1)"
```

`Receive refused: ...` (exit 2) came from `receive.sh` before the release script
started: a malformed SHA, a missing `shared/incoming`, an oversized archive (the
cap is 300 MiB), or the release script not being installed. `Release refused at
<step>: ...` or `Release failed at <step>` (exit 1) came from `release.sh`. The
step tells you whether `current` moved:

| Step | `current` | Notes |
|---|---|---|
| `arguments`, `layout`, `privilege-preflight` | Untouched | Account, sudo rules, approvals, or a path problem. `release already staged` means a previous attempt's directory exists (D5) |
| `archive-validation`, `shared-state`, `runtime-storage` | Untouched | The archive broke a rule, or `shared/storage` is unsafe or not writable by `www-data` |
| `php-fpm-extensions`, `production-preflight` | Untouched | Missing extension, bad `.env` value, MySQL/Redis/backup/mail/memory-limit refusal |
| `cache-rebuild`, `fresh-backup` | Untouched | `fresh-backup` also fails if the nightly pipeline holds the shared lock |
| `migration` | Untouched, **schema may have changed** | MySQL does not roll back DDL. Treat a failure here like a post-switch failure for the schema question |
| `pre-switch-smoke` | Untouched, **schema has changed** | Same |
| `atomic-switch` | Moved only if it printed `Switched current` | A `temporary switch path exists` refusal means `/srv/training-center/current-<sha>` was left by a crash; remove that symlink (only that) |
| `process-restart`, `post-switch-dependencies`, `post-switch-http`, `record-sha` | **Moved to the new code** | The script prints `operator-led recovery required`. Go to D3 |

### D2. A failure before the switch

`current` is untouched and the site is still on the last-known-good code. Fix the
cause (the log names the step), then retry as in D5. If the failure was at
`migration` or `pre-switch-smoke`, first answer the schema question in D3.

### D3. A failure after the switch: decide before acting

**Who:** Operator and Owner together. Do not reverse the symlink reflexively.

1. **Is the site serving?**
   `curl -fsS -o /dev/null -w '%{http_code}\n' https://center.example/up` (remember
   this proves only that the framework boots).
2. **Did the schema change?** In this deploy's log:

   ```bash
   grep -n 'Nothing to migrate' "$(ls -t /srv/training-center/shared/deploy-logs/*.log | head -1)"
   ```

   `Nothing to migrate` means the schema did not change.
3. **Choose:**

   - **Schema unchanged, or changed compatibly (C2 confirmed the old code works
     against it):** a code-only reversal is safe. Find the last-known-good SHA
     (`prior` in the first line of the log, or `shared/deployed-sha` if the
     receipt was never rewritten) and:

     ```bash
     prior=<40-hex last-known-good SHA>
     sudo -u training-center ln -s -- "/srv/training-center/releases/$prior" /srv/training-center/current-revert
     sudo -u training-center mv -Tf -- /srv/training-center/current-revert /srv/training-center/current
     cd /srv/training-center/current
     for c in config:cache route:cache view:cache event:cache filament:optimize; do sudo -u www-data /usr/bin/php artisan $c --no-interaction; done
     systemctl reload php8.4-fpm
     sudo -u www-data /usr/bin/php artisan horizon:terminate
     sudo -u www-data /usr/bin/php artisan pulse:restart
     ```

     Wait for the units to return, then confirm they run the old release:

     ```bash
     for u in horizon pulse; do readlink "/proc/$(systemctl show -p MainPID --value training-center-$u.service)/cwd"; done
     ```

     Then repeat the smoke requests from C5.

   - **Schema changed and the old code cannot run on it:** keep the site down.
     Switch Nginx to the HTTPS maintenance site (B9) and stop the workers
     (`systemctl stop training-center-horizon training-center-pulse`). Then the
     **Owner** decides between a forward-fix (a corrected SHA deployed through
     the normal gates) and a restore (D4). Do not reverse migrations by hand.

Evidence: the decision, who made it, the log excerpt that justified it, and the
commands run.

### D4. Restoring data

**Who:** Owner decides; Operator carries out. Follow `docs/RESTORE.md`. Know what
you are trading:

- The release took a fresh backup of the **previous** code's database and uploads
  just before migrating. It is the newest archive in
  `/var/backups/training-center/training-center/` (`ls -lt`). Anything written
  after it exists only in the live database.
- Restore into an **empty database**, never over a live one you have not finished
  investigating (`docs/RESTORE.md`, step 3). Writes made after the backup are lost
  if you then switch the application to the restored database; the owner accepts
  that loss, in writing, before it happens.
- While the backups live on the same server (section F), a failed disk loses the
  archives too.

### D5. Retry the same SHA

**Who:** Operator. An existing `releases/<sha>` is deliberately refused rather
than overwritten (`release already staged; retain it and investigate before
retry`). A retry therefore needs exactly the failed staging directory removed and
nothing else. **Never remove `shared`, the active release, or the last-known-good
release.**

1. Keep the evidence: the logs stay in `shared/deploy-logs/`. Copy off the host
   any log you want to keep beyond the next prune.
2. Verify the directory, and remove it only if every check passes:

   ```bash
   sha=<40-hex SHA of the failed staged release>
   root=/srv/training-center
   dir=$root/releases/$sha
   find "$dir" -maxdepth 2 -type l -printf '%p -> %l\n'      # only .env, storage, public/storage, all pointing into shared
   [[ $sha =~ ^[0-9a-f]{40}$ ]] && [[ -d $dir && ! -L $dir ]] \
     && [[ "$(realpath -e -- "$dir")" == "$dir" ]] \
     && [[ "$(realpath -e -- "$root/current")" != "$dir" ]] \
     && [[ "$(cat "$root/shared/deployed-sha")" != "$sha" ]] \
     && rm -rf -- "$dir" && echo "removed $dir" || echo "NOT removed: a check failed"
   rm -f -- "$root/current-$sha"      # only if a crash left it; a plain symlink
   ```

   `rm -r` removes symbolic links without following them, so `.env`,
   `storage` and `public/storage` inside the release are unlinked, not their
   targets in `shared`.
3. **Recheck both approvals** (C3 and the GitHub environment): the host file must
   still exist for this SHA, and the new workflow run needs the Reviewer's
   approval again, because approvals belong to one run.
4. Dispatch again (C4). Retrying a SHA that failed after migrating is safe for the
   schema: Laravel skips migrations that already ran.

**First install that failed after the switch.** If the script printed `Switched
current to ...`, then `current` is a symlink to the failed release and the
`current` check in step 2 will correctly refuse to delete it. Only after the
Operator has put HTTPS maintenance up (B9) and the **Owner** has agreed to retry:
remove the symlink only (`rm -- /srv/training-center/current`; no `-r`), so that
the script takes its first-install path again, confirm
`shared/first-install-approved` still holds the same SHA, then follow steps 2 to 4.

### D6. Incident contacts

**Who:** Owner fills this in, privately, before launch (not in the repository):

| Role | Name | Phone / channel |
|---|---|---|
| Owner 1 | | |
| Owner 2 | | |
| Operator | | |
| Libyan Spider support | | |
| Brevo support | | |
| Domain registrar | | |

---

## E. Recurring operations and monitoring

**Nothing in the application alerts on disk space, Redis memory or a stopped
service.** The application mails on: a queue that has waited 60 seconds
(`HORIZON_ALERT_EMAIL`), and backup events (`BACKUP_ALERT_EMAIL`). Everything
else below is a check a person makes, or an external monitor the owner
subscribes to. Recommended: an external uptime monitor on
`https://center.example/admin/login` (and `/up`, remembering what `/up` means),
alerting the owners' phones.

### E1. The scheduler's timetable

The scheduler runs once a minute from `/etc/cron.d/training-center`. Do not add
another cron line for any of these:

| Command | When |
|---|---|
| `backup:run` | 01:30 daily, `Africa/Tripoli` (UTC+02:00) |
| `backup:monitor` | 02:30 daily, `Africa/Tripoli` |
| `backup:clean` | 03:00 daily, `Africa/Tripoli` |
| `files:sweep-pending-deletions` | hourly |
| `receipts:reconcile-pending` | every five minutes |
| `horizon:snapshot` | every five minutes |

The three backup commands share one lock, so they never overlap.

### E2. The checks

For the Redis rows, read the password from `.env` into the environment of the
current shell only:

```bash
export REDISCLI_AUTH="$(grep '^REDIS_PASSWORD=' /srv/training-center/shared/.env | cut -d= -f2-)"
```


| What | Command | Healthy | Act when | Who / how often |
|---|---|---|---|---|
| Disk | `df -h / /var/backups/training-center`; `du -sh /srv/training-center/shared/storage/{app,logs} /srv/training-center/releases /var/backups/training-center` | Below 70% used | **Warning 70%, critical 85%** (proposed; the owner confirms these and who is told). Prune old releases, check logs and the backup volume | Operator, weekly. Pulse's servers card shows disk too |
| Redis memory | `redis-cli info memory \| grep -E 'used_memory_human\|maxmemory_human'`; `info stats \| grep evicted_keys` | Used well below `maxmemory` (256 MiB); `evicted_keys:0` | Used above 70%: investigate the queue. At the ceiling Redis **refuses writes** (`noeviction`); the application logs `OOM command not allowed`. Raise `maxmemory` only after checking RAM | Operator, weekly |
| Redis persistence | `redis-cli info persistence \| grep -E 'aof_last_write_status\|rdb_last_bgsave_status'` | Both `ok` | Any `err` | Operator, weekly |
| Horizon | `horizon:status`; `/horizon` dashboard; `artisan queue:failed` | Running; no growing failed list | Not running, or jobs failing | Operator, daily at first, then weekly |
| Pulse | `/pulse`; `systemctl is-active training-center-pulse` | Servers card has fresh data | Card stale, unit down | Operator, weekly |
| Scheduler | `ls -l --time-style=full-iso /srv/training-center/shared/storage/logs/scheduler-*.log` | Modified in the last minute | Older: cron is not running or `current` is broken | Operator, daily at first |
| Backups | `artisan backup:list`; the nightly mail | A new archive every night | No archive, or a failure/unhealthy mail | Operator, daily; read the mail |
| Application logs | `tail /srv/training-center/shared/storage/logs/laravel-$(date -u +%F).log` | No repeated errors | Repeated errors | Operator, weekly |
| Certificate | `certbot certificates`; `systemctl list-timers certbot.timer` | More than 30 days left, timer scheduled | Under 30 days: run `certbot renew` and read the error | Operator, monthly |
| OS updates | `apt list --upgradable`; `ls /var/run/reboot-required` | Nothing pending | See below | Operator, monthly |

**Logs and retention.**

- Laravel's `daily` channel keeps `LOG_DAILY_DAYS=14` days.
- The scheduler's own output (`scheduler-YYYY-MM-DD.log`, UTC dates) is deleted
  by the cron line after 14 days.
- `shared/deploy-logs/` has **no automatic cleanup**. Prune files older than 90
  days by hand, quarterly.
- Nginx and system logs rotate through the packages' `logrotate` rules.

**Monthly OS and PHP updates.** Unattended upgrades cover Ubuntu security
updates only. The `ondrej/php` PHP packages need `apt-get install --only-upgrade
php8.4-*` by hand, and MySQL needs `apt-get install --only-upgrade mysql-server`
(read the changelog first; restart it in a quiet hour), followed by `systemctl restart php8.4-fpm` and the C7 restart
of Horizon and Pulse. A new kernel needs a deliberate reboot in a quiet hour:
tell the owners first; afterwards check `findmnt /var/backups/training-center`,
`systemctl is-active` for `nginx php8.4-fpm mysql redis-server
training-center-horizon training-center-pulse`, the `current` link, and the C5
requests.

**Pruning old releases.** Releases accumulate; nothing prunes them. Keep
`current`, the last-known-good release and the three newest others. Remove an older
one only with the checks in D5 (it is not `current` and not `deployed-sha`).

**Measurements after launch.** For the first week, record peak resource use so
the starting numbers can be replaced by measured ones:

```bash
ps -eo rss,cmd --sort=-rss | grep -E 'php-fpm|artisan horizon|horizon:work|artisan pulse' | head -20
free -m
redis-cli info memory | grep used_memory_peak_human
```

Compare with the Horizon ceiling (96 MiB per worker, up to ten workers in
production) and the FPM pool size, and adjust only from what you see. Set latency
and queue-wait objectives from a baseline measured on this VPS, **not** from the
Docker development benchmark in `docs/LINUX-DEV-TARGET.md` or
`docs/reviews/2026-09-28-phase-3.5-load-baseline.md`.

---

## F. Backups: temporary on the server, then off it

**Owner decision, 2026-10-20:** for the first one to two weeks after launch the
backups are written to this server (`backups_local` on the loop-mounted volume
from A12). The nightly pipeline, the encryption and the restore drill all run.

**What this protects against:** a bad release, a wrong bulk edit, an accidental
deletion, a corrupted table. **What it does not protect against:** losing the
server, its disk, the provider account, or a compromise of the host. In those
cases the backups go with the server. `docs/RESTORE.md` and the design
(§11) both state that a backup must be in a separate failure domain; this is a
deliberate, time-boxed exception, and **the off-server restore drill is deferred
until the switch below**.

**Dated follow-up.** Write the launch date and a deadline of *launch + 14 days*
in the evidence log, and put the deadline in the owners' calendars. The exception
ends at the deadline whether or not anyone remembers it.

### F1. Switch to an off-server bucket

**Who:** Owner provides the bucket and credentials; Operator applies them.

1. **Choose a bucket outside this provider's failure domain** (any S3-compatible
   service not run by Libyan Spider; the choice of vendor is the owner's). Create
   a private bucket and a key limited to that bucket. Record the endpoint,
   region, bucket name, key and secret in the password manager.
2. **Edit `.env`** (C7), changing and adding:

   ```text
   BACKUP_DISK=backups_s3
   BACKUP_S3_KEY=<SECRET>
   BACKUP_S3_SECRET=<SECRET>
   BACKUP_S3_REGION=<region>
   BACKUP_S3_BUCKET=<bucket>
   BACKUP_S3_ENDPOINT=https://<the provider's endpoint>
   BACKUP_S3_PATH_STYLE=true
   ```

   The endpoint is required even on AWS: the application refuses to boot without
   it. Leave `BACKUP_ARCHIVE_PASSWORD` and `BACKUP_ARCHIVE_NAME` **unchanged**:
   changing the name starts a new directory and orphans the old archives.
3. **Apply it:** the cache and restart sequence in C7.
4. **Prove it:**

   ```bash
   cd /srv/training-center/current
   sudo -u www-data /usr/bin/php artisan backup:run
   sudo -u www-data /usr/bin/php artisan backup:monitor
   sudo -u www-data /usr/bin/php artisan backup:list
   ```

   The archive must appear in the **bucket** (check the provider's console, not
   only the command's output), and the success mail must arrive.
5. **Run the off-server restore drill.** From a different machine that is not this
   server, download the newest archive from the bucket and follow `docs/RESTORE.md`
   ("The drill") into a scratch database. Use the archive password from the
   password manager, not from the host. Open a staff profile and download a
   certificate. Note how long it took.
6. **Only then retire the local volume:**

   ```bash
   umount /var/backups/training-center
   sed -i '\#/var/lib/training-center-backup.img#d' /etc/fstab
   rm /var/lib/training-center-backup.img
   ```

   Keep the local archives until step 5 has passed. The next release's preflight
   reads the bucket, not the local path.

Evidence: the date of the switch, the bucket archive listing, the mail, the
off-server restore drill (date, duration, who did it), and the removal of the
loop volume. The milestone's last open item ("a real off-server backup has been
restored on a separate scratch target") closes here.

---

## Appendix: the evidence log

A private document the owners keep outside the repository. One section per
event, never any secret value:

- Provisioning (A): OS and package versions, `sshd -T` lines, firewall status,
  external port scan, the reviewed commit SHA the host files came from and their
  checksum match.
- DNS and certificate (B2 to B4): record contents (not credentials), `certbot
  certificates`, the HTTPS-maintenance verification.
- Configuration (B5 to B7): the `.env` completeness check, GitHub environment
  screenshots, approval-file listings.
- Each release (C, B8): date, SHA, reviewer, Actions run URL, deploy-log file
  name, compatibility note, post-deploy checks.
- Each incident (D): the failed step, the decision and who took it, the commands
  run.
- Acceptance (B11): every row, the two mails, the restore drill, the owner's
  launch approval.
- Backups (F): the launch date, the deadline, the switch and the off-server
  drill.
