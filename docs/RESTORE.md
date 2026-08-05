# Restoring from a backup

What the nightly backup contains, how to get it back, and what has to be true
before any of that works.

A backup nobody has ever restored is a hypothesis. Walk through the drill at the
end of this document once, on a scratch environment, before you need it.

---

## What is in a backup

One encrypted zip per night, holding two things:

1. **A MySQL dump** — every student, enrolment, staff account, role, permission
   and activity-log entry.
2. **The upload roots** — `storage/app/secure` (staff certificates and profile
   photos) and `storage/app/public`.

Both halves are required. The database stores only the **paths** to uploaded
files, so restoring the database alone gives you rows pointing at certificates
that no longer exist, and no way to reconstruct them.

Archives are named `<BACKUP_ARCHIVE_NAME>-*` — `training-center-*` with the
default configuration — and live on whichever destination `BACKUP_DISK` names:

| `BACKUP_DISK` | Where the archives are |
|---|---|
| `backups_local` (default) | The removable drive mounted at `BACKUP_LOCAL_PATH` |
| `backups_s3` | An S3-compatible bucket |

**The invariant is a separate failure domain, not a particular technology.** A
backup that shares a fate with the thing it protects is not a backup: an archive
on the application's own disk dies with that disk. A rotated external drive and
an off-site bucket both satisfy this; a folder on the server does not, and the
application refuses to boot if `BACKUP_LOCAL_PATH` points inside itself.

A drive that never leaves the building survives a dead server and not a fire.
Rotate two, and keep one elsewhere.

`BACKUP_ARCHIVE_NAME` also names the directory the archives sit in on whichever
destination is in use. If this deployment sets it to something else, substitute
that value everywhere this document writes `training-center-`.

---

## Before you can restore anything

You need three things. Two live in `.env` on a server that may no longer exist,
so keep them somewhere else as well.

| What | Where it should also live |
|---|---|
| `BACKUP_ARCHIVE_PASSWORD` | A password manager. **Without it the archives are unreadable — there is no recovery path.** |
| The backup destination | Either the removable drive named by `BACKUP_LOCAL_PATH`, or the `BACKUP_S3_*` credentials from a password manager or the provider's console. |
| Database credentials for the target | Wherever you keep the new server's secrets. |

The archive password is the one that ends recoveries. AES-256 has no back door
and neither do we: an archive whose password is lost is a file of random bytes.
Store it separately from the server, and confirm it is retrievable by somebody
other than whoever set the system up.

---

## Restoring

### 1. Get the archive

Where the archives are depends on `BACKUP_DISK`.

**`backups_local` (a removable drive, the default).** Mount the drive and look
under `BACKUP_LOCAL_PATH` — `/mnt/backups` unless it was changed. The archives
sit in a directory named after `BACKUP_ARCHIVE_NAME`.

**`backups_s3`.** From the storage provider's console, or with any S3 client
pointed at `BACKUP_S3_ENDPOINT` and `BACKUP_S3_BUCKET`.

Either way, pick the newest `<BACKUP_ARCHIVE_NAME>-*.zip` — `training-center-*.zip`
with the default configuration — from before whatever went wrong. For a bad
import or a mistaken bulk edit, that is **not** last night's.

> **If the drive is empty, check whether the archives went to the mount point
> instead.** `backup:run`, `backup:monitor` and `backup:clean` refuse to touch a
> destination that is on the same filesystem as the application or is missing
> its volume marker, so an unplugged drive fails that night's run and mails
> `BACKUP_ALERT_EMAIL` rather than writing to the server. On an install
> predating that check, or one where `BACKUP_VOLUME_MARKER` was switched off,
> look under the same path with the drive unmounted — if files are there, that
> is where the last nights went.
>
> `php artisan backup:list` prints the destination and warns you when it cannot
> be trusted. Read that warning: a bare "Reachable ✅" only means the directory
> could be listed, which an empty mount point on the server satisfies.

**Retention goes back further than most people assume, so look before concluding
an archive is gone.** The tiers are additive:

| Tier | Kept |
|---|---|
| Every backup | 30 days |
| One per day | 60 days |
| One per week | 8 weeks |
| One per month | 12 months |
| One per year | 2 years |

So a mistake discovered a year later still has an archive from before it, and the
oldest recoverable point is roughly **three years back**, not one. An earlier
version of this section listed only the first, second and fourth rows and
understated that by about two and a half years — which matters because the
instruction above is to pick an archive from *before* whatever went wrong, and
somebody who believes retention stops at a year will not go looking for the one
that exists.

Nothing is ever deleted to stay under a size limit; see `config/backup.php`.

### 2. Unpack it

**`unzip` will probably not work.** The archives are AES-256, and the `unzip`
shipped by most Linux distributions only understands the legacy ZipCrypto
scheme. It typically reports `unsupported compression method 99` or simply an
incorrect password, which is misleading — the password is fine, the tool is not.

Use 7-Zip — the filename below is an example under the default
`BACKUP_ARCHIVE_NAME`; use whatever you actually downloaded in step 1:

```bash
7z x -p"$BACKUP_ARCHIVE_PASSWORD" training-center-2026-07-28-01-30-00.zip -orestore/
```

Or, if 7-Zip is not available, PHP's own `ZipArchive` — which is what wrote the
file, so it can always read it:

```bash
php -r '
$zip = new ZipArchive;
$zip->open("training-center-2026-07-28-01-30-00.zip");
$zip->setPassword(getenv("BACKUP_ARCHIVE_PASSWORD"));
$zip->extractTo("restore/");
$zip->close();
'
```

You should get a `db-dumps/` directory containing the SQL dump, and the two
storage roots under `restore/storage/app/…`. Paths inside the archive are
relative to the project root — `relative_path` is set to `base_path()` — so they
unpack the same way regardless of which server produced them.

If the password is genuinely rejected, check you are using the one that was
current **when that archive was written**: rotating it does not re-encrypt
existing archives.

### 3. Restore the database

Into an empty database, never over a live one you have not finished
investigating:

```bash
mysql -u USER -p TARGET_DATABASE < restore/db-dumps/mysql-training_center.sql
```

Then bring the schema up to the current release, in case the archive predates a
migration.

> **Set the backup configuration in `.env` before you run this, or it will
> refuse to start.** The application checks its backup *configuration* as the
> first thing it does on boot, and that check runs for *every* artisan command —
> including the one below. On a rebuilt server it fails with:
>
> ```
> Backups are not configured for production
> ```
>
> That is the guard working, not a broken restore. What it needs depends on
> `BACKUP_DISK`: `BACKUP_LOCAL_PATH` for a drive, or the `BACKUP_S3_*` values
> for a bucket — plus `BACKUP_ARCHIVE_PASSWORD` either way. The same applies to
> `php artisan tinker` and anything else you reach for while investigating.
>
> **It does not check whether the drive is plugged in.** That is deliberate: a
> missing drive must never stop the centre from working. Whether the drive is
> mounted is checked by `backup:run`, `backup:monitor` and `backup:clean`
> themselves — however they are started, by the scheduler or by hand — so an
> absent drive fails those commands and leaves `/admin` serving. Each one also
> sends its own failure notification to `BACKUP_ALERT_EMAIL`, so a drive left
> unplugged after a rotation is noticed the same night rather than at the next
> restore.
>
> `backup:list` remains usable during an incident. It always prints the table
> and exits successfully, but the guarded command adds a warning when the device
> or volume-marker checks fail. Do not treat Reachable as proof that the
> removable drive is mounted: by itself, it only means the configured directory
> could be listed.

```bash
php artisan migrate
```

### 4. Restore the files

The two roots go back exactly where they came from. Paths in the database are
relative to these, so moving them breaks every certificate link:

```bash
rsync -a restore/storage/app/secure/ /path/to/app/storage/app/secure/
rsync -a restore/storage/app/public/ /path/to/app/storage/app/public/
```

Check ownership afterwards — the web user must be able to read them.

### 5. Confirm it worked

```bash
php artisan tinker --execute="
  echo App\Domain\Enrollment\Models\Student::count().' students'.PHP_EOL;
  echo App\Domain\Staff\Models\StaffCertificate::count().' certificates'.PHP_EOL;
"
```

Then open a staff profile in the panel and download a certificate. Row counts
prove the dump restored; opening a file proves the two halves match, which is
the failure this whole arrangement exists to prevent.

---

## Deployment requirements

Backups do not run unless all of this is true. The application **refuses to boot
in production** when the credentials or the archive password are missing, which
is deliberate — see `BackupConfiguration`.

- **The scheduler must be running.** One cron entry:

  ```
  * * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
  ```

  Without it nothing is scheduled, no exception is raised, and the failure is
  completely silent. This is the most common way backups stop.

- **A backup destination must be configured, and `BACKUP_ARCHIVE_PASSWORD`
  must be set.** For `BACKUP_DISK=backups_local`, that means `BACKUP_LOCAL_PATH`
  pointing at a mounted removable drive, outside the project directory. For
  `backups_s3`, the `BACKUP_S3_*` values including an explicit endpoint.

- **Prepare each drive once**, so a drive that was never intended for these
  backups cannot silently start receiving them:

  ```bash
  touch /mnt/backups/.training-center-backup-volume
  ```

  The nightly run refuses a drive without that marker. Set
  `BACKUP_VOLUME_MARKER=` empty to switch the check off.

- **`BACKUP_ALERT_EMAIL` must be a mailbox somebody reads**, and the application
  needs a real mailer. It cannot be blank: the package validates the address at
  boot and refuses a null one, so an empty value stops the application rather
  than merely disabling alerts.

- **Verify once, immediately after the first deploy:**

  ```bash
  php artisan backup:run
  ```

  Then confirm the file appears where you expect it — on the drive under
  `BACKUP_LOCAL_PATH`, or in the bucket. This is the only step that proves the
  destination, `mysqldump` and the archive password all actually work together;
  the test suite deliberately never touches real storage, so it cannot tell you
  this.

  With a drive, unmount it and run `backup:run` again. **It must FAIL**, print
  a line beginning `The backup destination is not ready:`, exit non-zero, and
  send a backup-failure mail to `BACKUP_ALERT_EMAIL`. If it succeeds instead,
  the archives are going to the server's own disk through an empty mount point,
  and every one of them dies with the machine.

  Check the mail arrived, not just the exit code. A refusal nobody is told about
  is how a centre finds out during a restore that the drive has been unplugged
  since March.

- **`mysqldump` must be on the PATH** of the user the scheduler runs as. If it
  is not, set `dump.dump_binary_path` on the `mysql` connection in
  `config/database.php`.

---

## When something is wrong

`backup:monitor` runs nightly and notifies if the newest backup is older than a
day or the destination has grown past its ceiling. That is what catches a backup
that **stopped happening** — a failed run throws and notifies on its own, but a
run that never starts raises nothing at all.

To check by hand at any time:

```bash
php artisan backup:list
```

If the newest entry is not from last night, the scheduler, the credentials or
the database user's grants are the places to look. `--no-tablespaces` is already
set for the last of those; a dump that starts failing after a grants change is
usually asking for a privilege the application user should not have.

---

## The drill

Do this once on a scratch environment, and again whenever the archive password
or the storage provider changes. It takes about twenty minutes and it is the
only thing that turns this document from a plan into a procedure.

1. **Take a backup on purpose.**

   ```bash
   php artisan backup:run
   ```

2. **Take it from the real destination** — the removable drive, or the bucket
   via the provider's console or an S3 client. Do not copy it off the
   application server: the drill is worthless if it only proves the copy you
   already had is readable.

3. **Extract it with the tool you would actually reach for**, per step 2 above.
   If `unzip` fails here, that is the finding: note which tool works on your
   machines and tell whoever else might do this.

4. **Restore into a throwaway database.** Never the live one.

   ```bash
   mysql -u USER -p scratch_restore < restore/db-dumps/*.sql
   ```

5. **Point a checkout at it**, run `php artisan migrate`, copy the two storage
   roots into place, and sign in.

6. **Open a staff profile and download a certificate.** This is the step that
   matters. Row counts prove the dump restored; opening a file proves the
   database and the uploads came from the same night and still agree with each
   other. That is the failure this entire feature exists to prevent, and it is
   the only one you cannot detect any other way.

7. **Write down how long it took.** When this is needed for real, somebody will
   ask, and "about twenty minutes" is a much better answer than a guess.

If any step surprises you, fix this document rather than remembering the
workaround.
