# The Linux development target (P35-T14).
#
# WHAT THIS IS FOR, AND WHAT IT IS NOT
# ------------------------------------
# This is a test bench, not a deployment artifact. The application is NOT
# dockerised: nothing in the code, the spec or the hosting decision depends on
# this file. It exists because Horizon needs pcntl and posix, which have no
# Windows build at all — it supervises workers by forking and signalling them.
# The third extension, redis, does have a Windows build but is not installed on
# the development machine; the load baseline wants Linux for a different reason,
# to measure the queue driver and platform production will actually run.
#
# Everyday work, and the pre-push gate, stay on Windows. CI stays on Ubuntu.
#
# WHY php:8.4-cli AND NOT THE PRODUCTION SHAPE
# --------------------------------------------
# There is no Nginx, no FPM and no OPcache tuning here, because nothing serves
# traffic from this image. It runs `composer verify`, `php artisan horizon` and
# the load-test tooling, which is all a CLI image needs.
#
# GIT IS NOT OPTIONAL
# -------------------
# tests/bootstrap.php serialises suites with a lock whose key comes from
# `git rev-parse --path-format=absolute --git-common-dir` (scripts/Tooling/Repo.php).
# Without git installed, every test run here dies before the first test.
FROM php:8.4-cli

# Build inputs for the PHP extensions below, plus git and unzip for Composer.
RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libicu-dev \
        libonig-dev \
        libpng-dev \
        libzip-dev \
    && rm -rf /var/lib/apt/lists/*

# The CI extension set (.github/workflows/ci.yml), plus the three Windows
# cannot provide. posix is compiled in by default on this image; it is named
# explicitly so that a future base-image change cannot drop it silently.
RUN docker-php-ext-install -j"$(nproc)" \
        bcmath \
        gd \
        intl \
        mbstring \
        pcntl \
        pdo_mysql \
        posix \
        zip \
    && pecl install redis \
    && docker-php-ext-enable redis

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# The repository is CLONED into this volume rather than bind-mounted from the
# Windows drive: a bind mount crosses the VM boundary on every file read, and
# this suite is 2,300+ tests. See setup.sh.
WORKDIR /workspace

# A shell that stays up, so `docker compose exec app …` is the working idiom.
CMD ["sleep", "infinity"]
