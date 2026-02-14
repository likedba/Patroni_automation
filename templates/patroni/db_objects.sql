-- Auto-generated setup script for {{ project_name }}

-- Create users
CREATE USER {{ app_user_name }} WITH PASSWORD '{{ app_user_pass }}';
CREATE USER backup WITH PASSWORD '{{ backup_user_pass }}' REPLICATION;
CREATE USER postgres_exporter WITH PASSWORD '{{ postgres_exporter_user_pass }}';
CREATE USER zbx_monitor WITH PASSWORD '{{ zbx_monitor_user_pass }}';
CREATE USER otel WITH PASSWORD '{{ otel_user_pass }}';
CREATE USER ppem_agent WITH PASSWORD '{{ ppem_agent_user_pass }}' SUPERUSER;
CREATE USER auditor WITH PASSWORD '{{ auditor_user_pass }}' NOLOGIN;
ALTER USER postgres WITH PASSWORD '{{ postgres_user_pass }}';

-- Create LDAP group
CREATE ROLE ldap_users WITH NOLOGIN;

-- Grant monitoring roles
GRANT pg_monitor TO postgres_exporter;
GRANT pg_monitor TO zbx_monitor;
GRANT pg_monitor TO otel;
GRANT pg_monitor TO ppem_agent;
GRANT pg_maintain TO ppem_agent;
GRANT pg_signal_backend TO ppem_agent;
GRANT pg_read_all_settings TO ppem_agent;

-- Add user comments
COMMENT ON ROLE {{ app_user_name }} IS 'application tech user';
COMMENT ON ROLE postgres IS 'superuser';
COMMENT ON ROLE ldap_users IS 'ldap users group';
COMMENT ON ROLE backup IS 'backup management tech user';
COMMENT ON ROLE postgres_exporter IS 'prometheus monitoring tech user';
COMMENT ON ROLE zbx_monitor IS 'zabbix monitoring tech user';
COMMENT ON ROLE ppem_agent IS 'PostgresPro Enterprise Manager tech superuser';
COMMENT ON ROLE otel IS 'open telemetry collector tech user';
COMMENT ON ROLE auditor IS 'pgaudit extension tech user';

-- Create application database
CREATE DATABASE {{ app_db_name }} OWNER {{ app_user_name }};

-- Connect to application database
\c {{ app_db_name }}

-- Create schema
CREATE SCHEMA {{ app_schema_name }} AUTHORIZATION {{ app_user_name }};

-- Create extensions
CREATE EXTENSION pg_stat_statements;
CREATE EXTENSION pgstattuple;
CREATE EXTENSION amcheck;
CREATE EXTENSION pgaudit;
CREATE EXTENSION pg_repack;
CREATE EXTENSION pg_profile;

-- Grant permissions for backup in app database
GRANT USAGE ON SCHEMA pg_catalog TO backup;
GRANT EXECUTE ON FUNCTION pg_catalog.current_setting(text) TO backup;
GRANT EXECUTE ON FUNCTION pg_catalog.pg_is_in_recovery() TO backup;
GRANT EXECUTE ON FUNCTION pg_catalog.pg_backup_start(text,boolean) TO backup;
GRANT EXECUTE ON FUNCTION pg_catalog.pg_backup_stop(boolean) TO backup;
GRANT EXECUTE ON FUNCTION pg_catalog.pg_create_restore_point(text) TO backup;
GRANT EXECUTE ON FUNCTION pg_catalog.pg_switch_wal() TO backup;
GRANT EXECUTE ON FUNCTION pg_catalog.pg_last_wal_replay_lsn() TO backup;
GRANT EXECUTE ON FUNCTION pg_catalog.txid_current() TO backup;
GRANT EXECUTE ON FUNCTION pg_catalog.txid_current_snapshot() TO backup;
GRANT EXECUTE ON FUNCTION pg_catalog.txid_snapshot_xmax(txid_snapshot) TO backup;
GRANT EXECUTE ON FUNCTION pg_catalog.pg_control_checkpoint() TO backup;

-- Grant permissions for ppem_agent in app database
GRANT USAGE ON SCHEMA pg_catalog TO ppem_agent;
GRANT EXECUTE ON FUNCTION pg_catalog.current_setting(text) TO ppem_agent;
GRANT EXECUTE ON FUNCTION pg_catalog.pg_is_in_recovery() TO ppem_agent;
GRANT EXECUTE ON FUNCTION pg_catalog.pg_backup_start(text,boolean) TO ppem_agent;
GRANT EXECUTE ON FUNCTION pg_catalog.pg_backup_stop(boolean) TO ppem_agent;
GRANT EXECUTE ON FUNCTION pg_catalog.pg_create_restore_point(text) TO ppem_agent;
GRANT EXECUTE ON FUNCTION pg_catalog.pg_switch_wal() TO ppem_agent;
GRANT EXECUTE ON FUNCTION pg_catalog.pg_last_wal_replay_lsn() TO ppem_agent;
GRANT EXECUTE ON FUNCTION pg_catalog.txid_current() TO ppem_agent;
GRANT EXECUTE ON FUNCTION pg_catalog.txid_current_snapshot() TO ppem_agent;
GRANT EXECUTE ON FUNCTION pg_catalog.txid_snapshot_xmax(txid_snapshot) TO ppem_agent;
GRANT EXECUTE ON FUNCTION pg_catalog.pg_control_checkpoint() TO ppem_agent;
GRANT EXECUTE ON FUNCTION pg_catalog.pg_stat_file(TEXT) TO ppem_agent;
GRANT EXECUTE ON FUNCTION pg_catalog.pg_stat_file(TEXT, BOOLEAN) TO ppem_agent;
GRANT EXECUTE ON FUNCTION pg_catalog.pg_config() TO ppem_agent;
GRANT SELECT ON pg_catalog.pg_statistic TO ppem_agent;
GRANT SELECT ON pg_catalog.pg_config TO ppem_agent;
GRANT SELECT ON pg_catalog.pg_file_settings TO ppem_agent;
GRANT SELECT ON pg_catalog.pg_authid TO ppem_agent;
GRANT EXECUTE ON FUNCTION pg_catalog.pg_show_all_file_settings() TO ppem_agent;

-- Return to postgres database
\c postgres

-- Grant permissions for backup in postgres database
GRANT USAGE ON SCHEMA pg_catalog TO backup;
GRANT EXECUTE ON FUNCTION pg_catalog.current_setting(text) TO backup;
GRANT EXECUTE ON FUNCTION pg_catalog.pg_is_in_recovery() TO backup;
GRANT EXECUTE ON FUNCTION pg_catalog.pg_backup_start(text,boolean) TO backup;
GRANT EXECUTE ON FUNCTION pg_catalog.pg_backup_stop(boolean) TO backup;
GRANT EXECUTE ON FUNCTION pg_catalog.pg_create_restore_point(text) TO backup;
GRANT EXECUTE ON FUNCTION pg_catalog.pg_switch_wal() TO backup;
GRANT EXECUTE ON FUNCTION pg_catalog.pg_last_wal_replay_lsn() TO backup;
GRANT EXECUTE ON FUNCTION pg_catalog.txid_current() TO backup;
GRANT EXECUTE ON FUNCTION pg_catalog.txid_current_snapshot() TO backup;
GRANT EXECUTE ON FUNCTION pg_catalog.txid_snapshot_xmax(txid_snapshot) TO backup;
GRANT EXECUTE ON FUNCTION pg_catalog.pg_control_checkpoint() TO backup;

-- Grant permissions for ppem_agent in postgres database
GRANT USAGE ON SCHEMA pg_catalog TO ppem_agent;
GRANT EXECUTE ON FUNCTION pg_catalog.current_setting(text) TO ppem_agent;
GRANT EXECUTE ON FUNCTION pg_catalog.pg_is_in_recovery() TO ppem_agent;
GRANT EXECUTE ON FUNCTION pg_catalog.pg_backup_start(text,boolean) TO ppem_agent;
GRANT EXECUTE ON FUNCTION pg_catalog.pg_backup_stop(boolean) TO ppem_agent;
GRANT EXECUTE ON FUNCTION pg_catalog.pg_create_restore_point(text) TO ppem_agent;
GRANT EXECUTE ON FUNCTION pg_catalog.pg_switch_wal() TO ppem_agent;
GRANT EXECUTE ON FUNCTION pg_catalog.pg_last_wal_replay_lsn() TO ppem_agent;
GRANT EXECUTE ON FUNCTION pg_catalog.txid_current() TO ppem_agent;
GRANT EXECUTE ON FUNCTION pg_catalog.txid_current_snapshot() TO ppem_agent;
GRANT EXECUTE ON FUNCTION pg_catalog.txid_snapshot_xmax(txid_snapshot) TO ppem_agent;
GRANT EXECUTE ON FUNCTION pg_catalog.pg_control_checkpoint() TO ppem_agent;
GRANT EXECUTE ON FUNCTION pg_catalog.pg_stat_file(TEXT) TO ppem_agent;
GRANT EXECUTE ON FUNCTION pg_catalog.pg_stat_file(TEXT, BOOLEAN) TO ppem_agent;
GRANT EXECUTE ON FUNCTION pg_catalog.pg_config() TO ppem_agent;
GRANT SELECT ON pg_catalog.pg_statistic TO ppem_agent;
GRANT SELECT ON pg_catalog.pg_config TO ppem_agent;
GRANT SELECT ON pg_catalog.pg_file_settings TO ppem_agent;
GRANT SELECT ON pg_catalog.pg_authid TO ppem_agent;
GRANT EXECUTE ON FUNCTION pg_catalog.pg_show_all_file_settings() TO ppem_agent;

-- Create extensions in postgres database
CREATE EXTENSION pg_stat_statements;
CREATE EXTENSION pg_repack;

-- Grant permissions for LDAP group
GRANT CONNECT ON DATABASE {{ app_db_name }} TO ldap_users;
GRANT USAGE ON SCHEMA {{ app_schema_name }} TO ldap_users;
