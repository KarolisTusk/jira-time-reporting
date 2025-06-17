-- PostgreSQL Data Import Script
-- Generated from SQLite backup for JIRA Time Reporting Application
-- Migration Date: June 12, 2025
-- Version 6.6: SQLite to PostgreSQL Migration
-- 
-- This script was used to migrate data from SQLite to PostgreSQL.
-- It includes user accounts, JIRA settings, projects, and app users.
-- All data was successfully imported and verified.

-- Import Users
INSERT INTO users (id, name, email, email_verified_at, password, remember_token, created_at, updated_at) VALUES 
(1, 'Test User', 'test@example.com', NULL, '$2y$12$PUQ.SZ5apMi8YIa2QTUjDuRR.iSDIEqdyBP9wVgCxyH7YSKJvZRZm', NULL, '2025-06-12 05:53:10', '2025-06-12 05:53:10');

-- Import JIRA Settings
INSERT INTO jira_settings (id, jira_host, api_token, project_keys, created_at, updated_at, jira_email) VALUES 
(1, 'https://gosyqor.atlassian.net', 'eyJpdiI6Imk0aUk1a3E2dC9JZ25oZjhmNnU2MUE9PSIsInZhbHVlIjoieXR3ZEFYWWhpbGd3YTVJUUllZG5VSUI1Y2VEUzBoOEpGTlVuQzFLZ29La1pkemRPRVdvdDdjZ3o2UnJ2d3FBRmJ3RWZCZXE1OGtCYWFtelFPM0xxUjV5ZTlJcUZTS1lESThSMXdMZHhOanJ3cFBPZnNkZUIxTi90QWg5VVNnTWRLcWIyQlZCSUN6SmdzT0dEcStLelM0ZHAySUsyVWVjLzlsUDdJODlsWWZDWEI2RGNjSGFjclEzZzNjNExUbUo2dlFzb0YwVFlHanZOODdDTUlHSVlVYUhsSnQ0Z3BIVkVLYlN4YS84YWhDK1dzRXhNQ1JkdGFxQjM2Q1dJVU03N2s0eWxnVlFTQWRJRmlFTitGdmlQVUE9PSIsIm1hYyI6ImI3OTI4NmFjZGQ3ZjM5NTY5Mzk2YTE5ZjlmOGE3YmJlYzc3ODFkNTcwYzAyMGQwZDAyN2JmN2Q1NmU2MDE1NTUiLCJ0YWciOiIifQ==', '["JFOC"]', '2025-06-12 08:31:59', '2025-06-12 17:34:37', 'karolis@syqor.com');

-- Import JIRA Projects
INSERT INTO jira_projects (id, jira_id, project_key, name, created_at, updated_at) VALUES 
(1, '10047', 'JFOC', '58Facettes', '2025-06-12 17:40:29', '2025-06-12 17:40:29');

-- Import JIRA App Users
INSERT INTO jira_app_users (id, jira_account_id, display_name, email_address, created_at, updated_at) VALUES 
(1, '6225d6e7f1e55c0070f0d304', 'Dmytro Koval', '', '2025-06-12 17:47:52', '2025-06-12 17:47:52'),
(2, '5efdb4076fddfb0bb405f476', 'Vlad Chubuk', '', '2025-06-12 17:47:52', '2025-06-12 17:47:52'),
(3, '557058:a28e88fe-c409-410b-aec9-e688b1eaffba', 'Ivan Tyshchenko', '', '2025-06-12 17:47:52', '2025-06-12 17:47:52');

-- Reset sequences to ensure proper auto-increment behavior
SELECT setval('users_id_seq', (SELECT MAX(id) FROM users));
SELECT setval('jira_settings_id_seq', (SELECT MAX(id) FROM jira_settings));
SELECT setval('jira_projects_id_seq', (SELECT MAX(id) FROM jira_projects));
SELECT setval('jira_app_users_id_seq', (SELECT MAX(id) FROM jira_app_users));