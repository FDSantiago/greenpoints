-- OPTIONAL STEP 4 (as SYS): the least-privilege runtime account from the Database Plan.
--   GP_OWNER owns the tables (migrations run as GP_OWNER); GP_APP is what the API connects as in production:
--   it can only SELECT/INSERT/UPDATE/DELETE, through the role GP_APP_ROLE and synonyms.
-- Re-run it after every migration that adds tables (it is idempotent).
--
--   sqlplus sys/Oracle_Dev_1@//oracle:1521/ORCLPDB1 as sysdba @sql/03_runtime_user.sql GpApp_Dev1
--   then set DB_USERNAME=gp_app / DB_PASSWORD=GpApp_Dev1 in .env (migrations still use gp_owner).

SET VERIFY OFF
SET SERVEROUTPUT ON
DEFINE apppw = '&1'

BEGIN
  BEGIN EXECUTE IMMEDIATE 'CREATE ROLE gp_app_role';
  EXCEPTION WHEN OTHERS THEN IF SQLCODE != -1921 THEN RAISE; END IF; END;   -- ORA-01921: role already exists
  BEGIN EXECUTE IMMEDIATE 'CREATE USER gp_app IDENTIFIED BY "&apppw" DEFAULT TABLESPACE gp_master TEMPORARY TABLESPACE temp';
  EXCEPTION WHEN OTHERS THEN IF SQLCODE != -1920 THEN RAISE; END IF; END;   -- ORA-01920: user already exists
  EXECUTE IMMEDIATE 'GRANT CREATE SESSION TO gp_app';

  FOR t IN (SELECT table_name FROM dba_tables WHERE owner = 'GP_OWNER') LOOP
    EXECUTE IMMEDIATE 'GRANT SELECT, INSERT, UPDATE, DELETE ON gp_owner."' || t.table_name || '" TO gp_app_role';
    EXECUTE IMMEDIATE 'CREATE OR REPLACE SYNONYM gp_app."' || t.table_name || '" FOR gp_owner."' || t.table_name || '"';
  END LOOP;

  EXECUTE IMMEDIATE 'GRANT gp_app_role TO gp_app';
  DBMS_OUTPUT.PUT_LINE('GP_APP ready: DML only, via role GP_APP_ROLE and synonyms.');
END;
/
EXIT
