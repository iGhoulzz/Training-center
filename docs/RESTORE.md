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

Archives are named `training-center-*` and live on the `backups` disk — an
S3-compatible bucket that is **not** on the application server. That is the
point: a backup on the same VPS as the application dies with it.

---

## Before you can restore anything

You need three things. Two live in `.env` on a server that may no longer exist,
so keep them somewhere else as well.

| What | Where it should also live |
|---|---|
| `BACKUP_ARCHIVE_PASSWORD` | A password manager. **Without it the archives are unreadable — there is no recovery path.** |
| `BACKUP_S3_*` credentials | A password manager, or the storage provider's own console. |
| Database credentials for the target | Wherever you keep the new server's secrets. |

The archive password is the one that ends recoveries. AES-256 has no back door
and neither do we: an archive whose password is lost is a file of random bytes.
Store it separately from the server, and confirm it is retrievable by somebody
other than whoever set the system up.

---

## Restoring

### 1. Get the archive

From the storage provider's console, or with any S3 client pointed at
`BACKUP_S3_ENDPOINT` and `BACKUP_S3_BUCKET`. Pick the newest
`training-center-*.zip` from before whatever went wrong — for a bad import or a
mistaken bulk edit, that is **not** last night's.

Retention is 30 days of every backup, then daily for 60 days, then monthly for a
year.

### 2. Unpack it

```bash
unzip -P "$BACKUP_ARCHIVE_PASSWORD" training-center-2026-07-28-01-30-00.zip -d restore/
```

You should get a `db-dumps/` directory containing the SQL dump, and the two
storage roots under their original paths.

If `unzip` reports an incorrect password, stop and check you are using the
password that was current **when that archive was written** — rotating it does
not re-encrypt existing archives.

### 3. Restore the database

Into an empty database, never over a live one you have not finished
investigating:

```bash
mysql -u USER -p TARGET_DATABASE < restore/db-dumps/mysql-training_center.sql
```

Then bring the schema up to the current release, in case the archive predates a
migration:

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

- **`BACKUP_S3_*` and `BACKUP_ARCHIVE_PASSWORD` must be set.**

- **`BACKUP_ALERT_EMAIL` must be a mailbox somebody reads**, and the application
  needs a real mailer. It cannot be blank: the package validates the address at
  boot and refuses a null one, so an empty value stops the application rather
  than merely disabling alerts.

- **Verify once, immediately after the first deploy:**

  ```bash
  php artisan backup:run
  ```

  Then confirm the file appears in the bucket. This is the only step that proves
  the credentials, the endpoint, `mysqldump` and the archive password all
  actually work together — the test suite deliberately never contacts external
  storage, so it cannot tell you this.

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
