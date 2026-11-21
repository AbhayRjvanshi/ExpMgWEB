-- ==============================================================================
-- ExpMgWEB Phase 3.1: Database Role Bootstrap Script
-- ==============================================================================
-- PURPOSE:
-- Idempotently creates and configures the least-privilege application runtime
-- role 'expmg_app' for Supabase PostgreSQL.
--
-- EXECUTION INSTRUCTIONS:
-- 1. Open the Supabase Dashboard for your development project.
-- 2. Navigate to: SQL Editor -> New Query.
-- 3. Replace 'REPLACE_WITH_YOUR_EXPMG_APP_PASSWORD' with your chosen password.
-- 4. Execute this script as the administrative user (postgres).
-- 5. Copy the chosen password into .env.phase3_1 as SUPABASE_DB_PASSWORD and
--    SUPABASE_POOLER_PASSWORD.
--
-- SECURITY CONSTRAINTS ENFORCED:
-- - expmg_app is NON-SUPERUSER (rolsuper = false).
-- - expmg_app has LOGIN enabled (canlogin = true).
-- - expmg_app has USAGE on schema public (can access tables/types).
-- - expmg_app is strictly BLOCKED from DDL (CREATE ON SCHEMA public revoked).
-- - expmg_app has NO access to auth schema or internal administrative tables.
-- ==============================================================================

-- 1. Create or alter the runtime role idempotently
DO $$
BEGIN
    IF NOT EXISTS (SELECT FROM pg_catalog.pg_roles WHERE rolname = 'expmg_app') THEN
        CREATE ROLE expmg_app WITH LOGIN PASSWORD 'REPLACE_WITH_YOUR_EXPMG_APP_PASSWORD';
        RAISE NOTICE 'Role expmg_app created successfully.';
    ELSE
        ALTER ROLE expmg_app WITH LOGIN PASSWORD 'REPLACE_WITH_YOUR_EXPMG_APP_PASSWORD';
        RAISE NOTICE 'Role expmg_app password updated.';
    END IF;
END
$$;

-- 2. Grant connection privilege on the database
GRANT CONNECT ON DATABASE postgres TO expmg_app;

-- 3. Grant schema usage (access to public objects)
GRANT USAGE ON SCHEMA public TO expmg_app;

-- 4. Explicitly prevent DDL operations (cannot CREATE, ALTER, or DROP tables/types)
REVOKE CREATE ON SCHEMA public FROM expmg_app;

-- 5. Isolate sensitive auth schema (prevent direct access to auth.users)
REVOKE ALL ON SCHEMA auth FROM expmg_app;

-- 6. Configure default privileges for future tables created by migrations (admin/postgres)
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO expmg_app;
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO expmg_app;

-- 7. Grant DML on Phase 3.1 disposable test tables (if already present or upon creation)
DO $$
BEGIN
    IF EXISTS (SELECT FROM information_schema.tables WHERE table_schema = 'public' AND table_name = '_phase31_poc_test') THEN
        GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE public._phase31_poc_test TO expmg_app;
    END IF;
    IF EXISTS (SELECT FROM information_schema.tables WHERE table_schema = 'public' AND table_name = '_phase31_pooler_test') THEN
        GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE public._phase31_pooler_test TO expmg_app;
    END IF;
END
$$;

-- 8. Verification query to confirm role privileges
SELECT r.rolname,
       r.rolsuper,
       r.rolcreaterole,
       r.rolcanlogin,
       has_schema_privilege('expmg_app', 'public', 'USAGE') AS public_usage,
       has_schema_privilege('expmg_app', 'public', 'CREATE') AS public_create,
       has_schema_privilege('expmg_app', 'auth', 'USAGE') AS auth_usage
FROM pg_roles r
WHERE r.rolname = 'expmg_app';
