#!/usr/bin/env bash
# Prepares an existing GreenPoints Laravel project for Oracle 19c.
# Run it INSIDE the rebuilt devcontainer, from the project root:   bash scripts/oracle-port.sh
set -uo pipefail
cd "$(dirname "$0")/.." || exit 1

echo "== 1/4  PHP OCI8 extension and the Laravel driver"
if ! php -m | grep -qi '^oci8$'; then
  echo "ERROR: the PHP OCI8 extension is missing. Rebuild the devcontainer (its Dockerfile installs it)."
  exit 1
fi
major=$(php artisan --version | grep -oE '[0-9]+\.[0-9]+' | head -1 | cut -d. -f1)
if [ -z "$major" ]; then echo "ERROR: could not read the Laravel version"; exit 1; fi
echo "Laravel $major -> installing yajra/laravel-oci8:^$major"
composer require "yajra/laravel-oci8:^$major" --no-interaction || { echo "composer failed (see above)"; exit 1; }
php artisan vendor:publish --tag=oracle --force >/dev/null 2>&1 || true

echo
echo "== 2/4  Oracle-proofing the migrations"
shopt -s nullglob
for f in database/migrations/*.php; do
  # Oracle has no "ON DELETE RESTRICT" (ORA-00905); NO ACTION (the default) blocks deletes the same way.
  # MySQL's "DROP CHECK" is "DROP CONSTRAINT" in Oracle.
  sed -i 's/->restrictOnDelete()//g; s/DROP CHECK /DROP CONSTRAINT /g' "$f"
done
if grep -rn "restrictOnDelete" database/migrations >/dev/null 2>&1; then
  echo "WARNING: restrictOnDelete is still present in:"; grep -rln "restrictOnDelete" database/migrations
else
  echo "ok: no restrictOnDelete left"
fi

echo
echo "== 3/4  .env"
setenv() {
  if grep -q "^$1=" .env 2>/dev/null; then sed -i "s|^$1=.*|$1=$2|" .env; else echo "$1=$2" >> .env; fi
}
[ -f .env ] || cp .env.example .env
setenv DB_CONNECTION oracle
setenv DB_HOST oracle
setenv DB_PORT 1521
setenv DB_SERVICE_NAME ORCLPDB1
setenv DB_DATABASE ORCLPDB1
setenv DB_USERNAME gp_owner
setenv DB_PASSWORD GpOwner_Dev1
setenv DB_CHARSET AL32UTF8
setenv DB_SERVER_VERSION 19c
setenv ORA_MAX_NAME_LEN 128
echo ".env updated (values match .devcontainer/docker-compose.yml)"

echo
echo "== 4/4  Next steps (run these yourself, in order)"
cat << 'NEXT'
  # a) create the tablespaces + schema owner (needs the Oracle container to be healthy: docker compose ps)
  sqlplus sys/Oracle_Dev_1@//oracle:1521/ORCLPDB1 as sysdba @sql/01_create_greenpoints_user.sql /opt/oracle/oradata/ORCLCDB/ORCLPDB1 GpOwner_Dev1
  # b) build the schema and the demo data
  composer dump-autoload && php artisan config:clear && php artisan migrate:fresh --seed
  # c) place tables/indexes in the right tablespaces (the Database Plan's storage map)
  sqlplus gp_owner/GpOwner_Dev1@//oracle:1521/ORCLPDB1 @sql/02_place_segments.sql
  # d) check the API
  PHP_CLI_SERVER_WORKERS=4 php artisan serve --host=0.0.0.0     # leave running, then in another terminal:
  bash scripts/smoke-all.sh
NEXT
