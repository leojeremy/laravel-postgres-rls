-- The application role: NOSUPERUSER and NOBYPASSRLS so policies apply to
-- it, and the owner of every table so FORCE ROW LEVEL SECURITY is what
-- makes them apply. CREATEDB only so test tooling can create databases.
-- Local development credentials only.
CREATE ROLE rls_app LOGIN PASSWORD 'rls_app' NOSUPERUSER NOBYPASSRLS NOCREATEROLE CREATEDB;

CREATE DATABASE rls_demo OWNER rls_app;
CREATE DATABASE rls_test OWNER rls_app;
