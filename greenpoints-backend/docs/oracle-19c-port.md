# Moving GreenPoints to Oracle 19c

The API was built and smoke-tested on MySQL. This pack switches the environment to **Oracle Database 19c** and fixes the
places where Oracle behaves differently. **It has not been run against a real Oracle database yet** (I had no Oracle to test on),
so the four smoke scripts are your proof. Read "If something breaks" before you start.

## What you need on your computer (once)
1. Docker with the VS Code Dev Containers extension (you already have this).
2. An Oracle account and license acceptance for the 19c image: sign in at https://container-registry.oracle.com, open **Database > enterprise**, accept the terms, then run `docker login container-registry.oracle.com`.
   - Can't get the image? Build it from https://github.com/oracle/docker-images (put the 19c zip in `OracleDatabase/SingleInstance/dockerfiles/19.3.0`, run `buildContainerImage.sh -v 19.3.0 -e`) and change `image:` in `docker-compose.yml` to `oracle/database:19.3.0-ee`.
   - Does your school give you a 19c server? Skip the container: delete the `oracle` service and `depends_on` in `docker-compose.yml`, set the `DB_*` values to the server's, and ask the instructor for the PDB service name and a schema user.

## Steps, in order
1. Copy the zip's contents over your project (it replaces `.devcontainer/*` and 8 PHP files, details below). Commit your MySQL version first (`git commit`) so you can go back.
2. VS Code: **Dev Containers: Rebuild Container**. The first Oracle start creates the database: 10-45 minutes. Watch with `docker compose -f .devcontainer/docker-compose.yml logs -f oracle` until you see `DATABASE IS READY TO USE!`. `docker compose ps` should then show `healthy`. (Existing MySQL data is not carried over; Oracle starts empty and the seeders refill it.)
3. Inside the container, from the project root: `bash scripts/oracle-port.sh`. It installs the right `yajra/laravel-oci8` for your Laravel version, strips the Oracle-incompatible `restrictOnDelete()` from your migrations, and sets `.env`.
4. Run the commands the script prints at the end (create user and tablespaces, `migrate:fresh --seed`, place segments, then the smoke tests). `bash scripts/smoke-all.sh` is the full regression (about 2.5 minutes).

## What changed in the code
| File | Change | Why (Oracle difference) |
|---|---|---|
| `.devcontainer/*` | PHP OCI8 + Instant Client + SQL*Plus; Oracle 19c service; ports 1521/5500 | the driver needs the client libraries |
| migrations (by `oracle-port.sh`) | `->restrictOnDelete()` removed; `DROP CHECK` -> `DROP CONSTRAINT` | Oracle has no `ON DELETE RESTRICT` (ORA-00905); the default "no action" blocks deletes the same way |
| `.env` | `DB_SERVER_VERSION=19c`, `ORA_MAX_NAME_LEN=128` | enables identity columns and OFFSET/FETCH paging; Laravel's auto-generated index/foreign-key names are longer than the default 30-character limit |
| Models `User, WasteLog, Redemption, PointTransaction` | integer/boolean casts on ids, FKs, points, `is_active` | Oracle returns NUMBER and CHAR(1) as strings; my strict `===` checks (owner vs. viewer, staff vs. own submission) would silently break |
| `RedemptionService`, `Admin/UserController` | row locks use `->lockForUpdate()->get()` instead of `->first()` / `->find()` | Oracle rejects `FOR UPDATE` combined with a row limit (ORA-02014); the locks are what stop double-spending |
| `Admin/UserController` | search uses `UPPER(...) LIKE ... ESCAPE '\'` | Oracle's LIKE is case-sensitive |
| `RegisterRequest`, `LoginRequest`, `Admin/UserController` | e-mails lower-cased | Oracle compares strings case-sensitively, so `Ana@x.com` and `ana@x.com` would be different accounts |

Verified from the driver's source: identifiers are quoted in UPPER case, so the raw SQL already in the project (`CHECK (role IN ...)`,
`COUNT(DISTINCT u.id)`, aliases) resolves correctly. Booleans become `CHAR(1)` and Laravel sends `true/false` as `1/0`. Lock + limit
queries are handled by the driver without the `FETCH FIRST` hint, and I still avoid them for safety.

## The SQL scripts implement your Database Plan
| Script | Who runs it | What it builds |
|---|---|---|
| `sql/01_create_greenpoints_user.sql` | SYS, once | tablespaces GP_MASTER (64 MB), GP_TXN (256 MB), GP_IDX (128 MB) with autoextend, plus the owner GP_OWNER |
| `sql/02_place_segments.sql` | GP_OWNER, after migrating | moves reference tables to GP_MASTER and rebuilds all indexes into GP_IDX (Plan Section 5 storage map) |
| `sql/03_runtime_user.sql` | SYS, optional | GP_APP: DML-only runtime user through role GP_APP_ROLE and synonyms (Plan Section 4 users) |

Demo queries for the pitch: the three `CDB_`/`DBA_` queries in Plan Section 8 run on this container (use `sqlplus sys/Oracle_Dev_1@//oracle:1521/ORCLCDB as sysdba` for the CDB-wide view, `.../ORCLPDB1` for the PDB).
Note: the container's CDB/PDB are named ORCLCDB / ORCLPDB1; the Plan names (GPCDB, GPPROD, ...) are the production design, so say so in the defense.

## If something breaks
| Symptom | Fix |
|---|---|
| `pull access denied` / `unauthorized` on the Oracle image | accept the license on container-registry.oracle.com, then `docker login container-registry.oracle.com` |
| `ORA-12541`, `ORA-12514`, `ORA-12528` | the database is still starting; wait for `healthy` (first start up to 45 min) |
| `ORA-01017 invalid username/password` | step a) of the next-steps list was skipped, or the password differs from `.env` (`GpOwner_Dev1`) |
| `ORA-01950 no privileges on tablespace` | GP_OWNER has no quota: rerun `01_create_greenpoints_user.sql` on a clean PDB |
| `ORA-00905 missing keyword` during migrate | a `restrictOnDelete()` is left: `bash scripts/oracle-port.sh` again, then `grep -rn restrictOnDelete database/migrations` |
| `ORA-00972 identifier is too long` | `ORA_MAX_NAME_LEN=128` is missing from `.env` (or run `php artisan config:clear`) |
| `ORA-00942 table or view does not exist` in SQL*Plus | in SQL*Plus use **unquoted** table names (`SELECT * FROM users`); Laravel created them upper-case |
| `ORA-02014` | a `FOR UPDATE` query with a limit or aggregate: send me the SQL from `storage/logs/laravel.log` |
| `DPI-1047` / "Cannot locate Oracle Client library" | `LD_LIBRARY_PATH` unset: rebuild the container; `php -m` must list `oci8` |
| Version mismatch error on the client | pin an older Instant Client (19 or 21) in the Dockerfile |
| A smoke test fails on a number being a string | send me the `FAIL` line; it means one more cast is needed |
| Everything works but is slow on first request | normal: Oracle warms its cache; the second run is faster |

Go back to MySQL any time: `git checkout` the commit from step 1 and rebuild the container.
