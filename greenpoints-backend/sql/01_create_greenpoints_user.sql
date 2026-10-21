-- STEP 1 (run once, as SYS, against the PDB service): tablespaces from the Database Plan + the schema owner.
--
--   sqlplus sys/Oracle_Dev_1@//oracle:1521/ORCLPDB1 as sysdba @sql/01_create_greenpoints_user.sql \
--           /opt/oracle/oradata/ORCLCDB/ORCLPDB1 GpOwner_Dev1
--
-- &1 = folder for the datafiles (container path above; the plan's production layout would be /u02/oradata/GPCDB/GPPROD)
-- &2 = password for GP_OWNER (letters, digits and _ only)
-- To start over:  DROP USER gp_owner CASCADE;  then drop the three tablespaces INCLUDING CONTENTS AND DATAFILES.

WHENEVER SQLERROR EXIT SQL.SQLCODE
SET VERIFY OFF
DEFINE dfdir = '&1'
DEFINE ownerpw = '&2'

CREATE TABLESPACE gp_master DATAFILE '&dfdir/gp_master01.dbf' SIZE 64M  AUTOEXTEND ON NEXT 16M MAXSIZE 1G;
CREATE TABLESPACE gp_txn    DATAFILE '&dfdir/gp_txn01.dbf'    SIZE 256M AUTOEXTEND ON NEXT 64M MAXSIZE 4G;
CREATE TABLESPACE gp_idx    DATAFILE '&dfdir/gp_idx01.dbf'    SIZE 128M AUTOEXTEND ON NEXT 32M MAXSIZE 2G;

CREATE USER gp_owner IDENTIFIED BY "&ownerpw"
  DEFAULT TABLESPACE gp_txn TEMPORARY TABLESPACE temp
  QUOTA UNLIMITED ON gp_master QUOTA UNLIMITED ON gp_txn QUOTA UNLIMITED ON gp_idx;

GRANT CREATE SESSION, CREATE TABLE, CREATE SEQUENCE, CREATE VIEW,
      CREATE TRIGGER, CREATE PROCEDURE, CREATE SYNONYM TO gp_owner;

PROMPT GP_OWNER and tablespaces GP_MASTER / GP_TXN / GP_IDX are ready. Next: php artisan migrate --seed
EXIT
