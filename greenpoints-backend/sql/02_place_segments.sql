-- STEP 3 (after `php artisan migrate`, as GP_OWNER): put every table and index where the Database Plan says.
-- Laravel creates everything in the user's default tablespace (GP_TXN); this moves
--   reference tables -> GP_MASTER   and   all indexes -> GP_IDX.   The growing tables stay in GP_TXN.
-- Safe to re-run after later migrations.
--
--   sqlplus gp_owner/GpOwner_Dev1@//oracle:1521/ORCLPDB1 @sql/02_place_segments.sql

SET SERVEROUTPUT ON
SET LINESIZE 120
SET PAGESIZE 100
COLUMN table_name FORMAT A28
COLUMN tablespace_name FORMAT A14

DECLARE
  n_tables PLS_INTEGER := 0;
  n_idx    PLS_INTEGER := 0;
BEGIN
  FOR t IN (SELECT table_name FROM user_tables
             WHERE table_name IN ('USERS','CLASS_GROUPS','WASTE_CATEGORIES','REWARDS','MIGRATIONS',
                                  'PASSWORD_RESET_TOKENS','CACHE','CACHE_LOCKS','JOBS','JOB_BATCHES',
                                  'FAILED_JOBS','SESSIONS')
               AND tablespace_name <> 'GP_MASTER') LOOP
    EXECUTE IMMEDIATE 'ALTER TABLE "' || t.table_name || '" MOVE TABLESPACE gp_master';
    n_tables := n_tables + 1;
  END LOOP;

  -- moving a table leaves its indexes UNUSABLE, so every index is rebuilt (into GP_IDX)
  FOR i IN (SELECT index_name FROM user_indexes
             WHERE index_type = 'NORMAL' AND (tablespace_name <> 'GP_IDX' OR status <> 'VALID')) LOOP
    EXECUTE IMMEDIATE 'ALTER INDEX "' || i.index_name || '" REBUILD TABLESPACE gp_idx';
    n_idx := n_idx + 1;
  END LOOP;

  DBMS_OUTPUT.PUT_LINE('Moved ' || n_tables || ' table(s) to GP_MASTER, rebuilt ' || n_idx || ' index(es) into GP_IDX.');
END;
/

PROMPT Tables by tablespace:
SELECT tablespace_name, table_name FROM user_tables ORDER BY tablespace_name, table_name;
PROMPT Indexes by tablespace:
SELECT tablespace_name, COUNT(*) AS indexes, SUM(CASE WHEN status <> 'VALID' THEN 1 ELSE 0 END) AS not_valid
  FROM user_indexes GROUP BY tablespace_name;
EXIT
