-- Pyra Workspace: Database Schema
-- Run this SQL in your Supabase SQL Editor

-- ============================================
-- Core Tables
-- ============================================

-- Users table
CREATE TABLE IF NOT EXISTS pyra_users (
    id SERIAL PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(20) NOT NULL DEFAULT 'client' CHECK (role IN ('admin', 'employee', 'client')),
    display_name VARCHAR(100) NOT NULL,
    permissions JSONB NOT NULL DEFAULT '{}',
    created_at TIMESTAMPTZ DEFAULT NOW()
);

-- Reviews table (with threaded replies via parent_id)
CREATE TABLE IF NOT EXISTS pyra_reviews (
    id VARCHAR(20) PRIMARY KEY,
    file_path TEXT NOT NULL,
    username VARCHAR(50) NOT NULL,
    display_name VARCHAR(100) NOT NULL,
    type VARCHAR(20) NOT NULL CHECK (type IN ('comment', 'approval')),
    text TEXT DEFAULT '',
    resolved BOOLEAN DEFAULT FALSE,
    parent_id VARCHAR(20) DEFAULT NULL,
    created_at TIMESTAMPTZ DEFAULT NOW()
);

-- ============================================
-- Trash / Recycle Bin
-- ============================================

CREATE TABLE IF NOT EXISTS pyra_trash (
    id VARCHAR(30) PRIMARY KEY,
    original_path TEXT NOT NULL,
    trash_path TEXT NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_size BIGINT DEFAULT 0,
    mime_type VARCHAR(100) DEFAULT 'application/octet-stream',
    deleted_by VARCHAR(50) NOT NULL,
    deleted_by_display VARCHAR(100) NOT NULL,
    deleted_at TIMESTAMPTZ DEFAULT NOW(),
    auto_purge_at TIMESTAMPTZ DEFAULT (NOW() + INTERVAL '30 days')
);

-- ============================================
-- Activity Log / Audit Trail
-- ============================================

CREATE TABLE IF NOT EXISTS pyra_activity_log (
    id VARCHAR(30) PRIMARY KEY,
    action_type VARCHAR(30) NOT NULL,
    username VARCHAR(50) NOT NULL,
    display_name VARCHAR(100) NOT NULL,
    target_path TEXT DEFAULT '',
    details JSONB DEFAULT '{}',
    ip_address VARCHAR(45) DEFAULT '',
    created_at TIMESTAMPTZ DEFAULT NOW()
);

-- ============================================
-- Notifications
-- ============================================

CREATE TABLE IF NOT EXISTS pyra_notifications (
    id VARCHAR(30) PRIMARY KEY,
    recipient_username VARCHAR(50) NOT NULL,
    type VARCHAR(30) NOT NULL,
    title VARCHAR(200) NOT NULL,
    message TEXT DEFAULT '',
    source_username VARCHAR(50) DEFAULT '',
    source_display_name VARCHAR(100) DEFAULT '',
    target_path TEXT DEFAULT '',
    is_read BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMPTZ DEFAULT NOW()
);

-- ============================================
-- Temporary Share Links
-- ============================================

CREATE TABLE IF NOT EXISTS pyra_share_links (
    id VARCHAR(30) PRIMARY KEY,
    token VARCHAR(64) UNIQUE NOT NULL,
    file_path TEXT NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    created_by VARCHAR(50) NOT NULL,
    created_by_display VARCHAR(100) NOT NULL,
    expires_at TIMESTAMPTZ NOT NULL,
    access_count INT DEFAULT 0,
    max_access INT DEFAULT 0,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMPTZ DEFAULT NOW()
);

-- ============================================
-- Teams / Groups
-- ============================================

CREATE TABLE IF NOT EXISTS pyra_teams (
    id VARCHAR(30) PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT DEFAULT '',
    permissions JSONB NOT NULL DEFAULT '{}',
    created_by VARCHAR(50) NOT NULL,
    created_at TIMESTAMPTZ DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS pyra_team_members (
    id VARCHAR(30) PRIMARY KEY,
    team_id VARCHAR(30) NOT NULL REFERENCES pyra_teams(id) ON DELETE CASCADE,
    username VARCHAR(50) NOT NULL,
    added_by VARCHAR(50) NOT NULL,
    added_at TIMESTAMPTZ DEFAULT NOW(),
    UNIQUE(team_id, username)
);

-- ============================================
-- File-Level Permissions
-- ============================================

CREATE TABLE IF NOT EXISTS pyra_file_permissions (
    id VARCHAR(30) PRIMARY KEY,
    file_path TEXT NOT NULL,
    target_type VARCHAR(10) NOT NULL CHECK (target_type IN ('user', 'team')),
    target_id VARCHAR(100) NOT NULL,
    permissions JSONB NOT NULL DEFAULT '{}',
    expires_at TIMESTAMPTZ DEFAULT NULL,
    created_by VARCHAR(50) NOT NULL,
    created_at TIMESTAMPTZ DEFAULT NOW()
);

-- ============================================
-- File Version History
-- ============================================

CREATE TABLE IF NOT EXISTS pyra_file_versions (
    id VARCHAR(30) PRIMARY KEY,
    file_path TEXT NOT NULL,
    version_path TEXT NOT NULL,
    version_number INT NOT NULL DEFAULT 1,
    file_size BIGINT DEFAULT 0,
    mime_type VARCHAR(100) DEFAULT '',
    created_by VARCHAR(50) NOT NULL,
    created_by_display VARCHAR(100) NOT NULL,
    comment TEXT DEFAULT '',
    created_at TIMESTAMPTZ DEFAULT NOW()
);

-- ============================================
-- File Search Index
-- ============================================

CREATE TABLE IF NOT EXISTS pyra_file_index (
    id VARCHAR(30) PRIMARY KEY,
    file_path TEXT NOT NULL UNIQUE,
    file_name VARCHAR(500) NOT NULL,
    file_name_lower VARCHAR(500) NOT NULL,
    folder_path TEXT NOT NULL DEFAULT '',
    file_size BIGINT DEFAULT 0,
    mime_type VARCHAR(100) DEFAULT '',
    original_name VARCHAR(500) DEFAULT NULL,
    updated_at TIMESTAMPTZ DEFAULT NOW(),
    indexed_at TIMESTAMPTZ DEFAULT NOW()
);

-- ============================================
-- System Settings
-- ============================================

CREATE TABLE IF NOT EXISTS pyra_settings (
    key VARCHAR(100) PRIMARY KEY,
    value TEXT NOT NULL DEFAULT '',
    updated_by VARCHAR(50) DEFAULT '',
    updated_at TIMESTAMPTZ DEFAULT NOW()
);

-- Default settings
INSERT INTO pyra_settings (key, value) VALUES
    ('app_name', 'Pyra Workspace'),
    ('app_logo_url', ''),
    ('primary_color', '#8b5cf6'),
    ('max_upload_size', '524288000'),
    ('allow_public_shares', 'true'),
    ('share_default_expiry_hours', '24'),
    ('session_timeout_minutes', '480'),
    ('max_failed_logins', '5'),
    ('lockout_duration_minutes', '15'),
    ('auto_version_on_upload', 'true'),
    ('max_versions_per_file', '10'),
    ('trash_auto_purge_days', '30')
ON CONFLICT (key) DO NOTHING;

-- ============================================
-- Session Management
-- ============================================

CREATE TABLE IF NOT EXISTS pyra_sessions (
    id VARCHAR(128) PRIMARY KEY,
    username VARCHAR(50) NOT NULL,
    ip_address VARCHAR(45) DEFAULT '',
    user_agent TEXT DEFAULT '',
    last_activity TIMESTAMPTZ DEFAULT NOW(),
    created_at TIMESTAMPTZ DEFAULT NOW()
);

-- ============================================
-- Login Attempts (Rate Limiting)
-- ============================================

CREATE TABLE IF NOT EXISTS pyra_login_attempts (
    id SERIAL PRIMARY KEY,
    username VARCHAR(50) NOT NULL,
    ip_address VARCHAR(45) DEFAULT '',
    success BOOLEAN DEFAULT FALSE,
    attempted_at TIMESTAMPTZ DEFAULT NOW()
);

-- ============================================
-- Indexes
-- ============================================

-- Core indexes
CREATE INDEX IF NOT EXISTS idx_reviews_file_path ON pyra_reviews(file_path);
CREATE INDEX IF NOT EXISTS idx_reviews_username ON pyra_reviews(username);
CREATE INDEX IF NOT EXISTS idx_reviews_parent ON pyra_reviews(parent_id);
CREATE INDEX IF NOT EXISTS idx_users_username ON pyra_users(username);

-- Trash indexes
CREATE INDEX IF NOT EXISTS idx_trash_deleted_by ON pyra_trash(deleted_by);
CREATE INDEX IF NOT EXISTS idx_trash_original_path ON pyra_trash(original_path);
CREATE INDEX IF NOT EXISTS idx_trash_auto_purge ON pyra_trash(auto_purge_at);

-- Activity log indexes
CREATE INDEX IF NOT EXISTS idx_activity_action ON pyra_activity_log(action_type);
CREATE INDEX IF NOT EXISTS idx_activity_username ON pyra_activity_log(username);
CREATE INDEX IF NOT EXISTS idx_activity_created ON pyra_activity_log(created_at DESC);

-- Notification indexes
CREATE INDEX IF NOT EXISTS idx_notif_recipient ON pyra_notifications(recipient_username);
CREATE INDEX IF NOT EXISTS idx_notif_read ON pyra_notifications(recipient_username, is_read);
CREATE INDEX IF NOT EXISTS idx_notif_created ON pyra_notifications(created_at DESC);

-- Share link indexes
CREATE INDEX IF NOT EXISTS idx_share_token ON pyra_share_links(token);
CREATE INDEX IF NOT EXISTS idx_share_path ON pyra_share_links(file_path);
CREATE INDEX IF NOT EXISTS idx_share_expires ON pyra_share_links(expires_at);

-- Team indexes
CREATE INDEX IF NOT EXISTS idx_teams_name ON pyra_teams(name);
CREATE INDEX IF NOT EXISTS idx_team_members_team ON pyra_team_members(team_id);
CREATE INDEX IF NOT EXISTS idx_team_members_user ON pyra_team_members(username);

-- File permission indexes
CREATE INDEX IF NOT EXISTS idx_fileperm_path ON pyra_file_permissions(file_path);
CREATE INDEX IF NOT EXISTS idx_fileperm_target ON pyra_file_permissions(target_type, target_id);
CREATE INDEX IF NOT EXISTS idx_fileperm_expires ON pyra_file_permissions(expires_at);

-- File version indexes
CREATE INDEX IF NOT EXISTS idx_versions_path ON pyra_file_versions(file_path);
CREATE INDEX IF NOT EXISTS idx_versions_created ON pyra_file_versions(created_at DESC);

-- File search index indexes
CREATE INDEX IF NOT EXISTS idx_fileindex_name ON pyra_file_index(file_name_lower);
CREATE INDEX IF NOT EXISTS idx_fileindex_path ON pyra_file_index(file_path);
CREATE INDEX IF NOT EXISTS idx_fileindex_folder ON pyra_file_index(folder_path);

-- Session indexes
CREATE INDEX IF NOT EXISTS idx_sessions_user ON pyra_sessions(username);
CREATE INDEX IF NOT EXISTS idx_sessions_activity ON pyra_sessions(last_activity);

-- Login attempt indexes
CREATE INDEX IF NOT EXISTS idx_login_attempts_user ON pyra_login_attempts(username);
CREATE INDEX IF NOT EXISTS idx_login_attempts_time ON pyra_login_attempts(attempted_at);

-- Favorites
CREATE TABLE IF NOT EXISTS pyra_favorites (
    id VARCHAR(30) PRIMARY KEY,
    username VARCHAR(50) NOT NULL,
    file_path TEXT NOT NULL,
    item_type VARCHAR(10) NOT NULL DEFAULT 'file' CHECK (item_type IN ('file', 'folder')),
    display_name VARCHAR(255) DEFAULT '',
    created_at TIMESTAMPTZ DEFAULT NOW(),
    UNIQUE(username, file_path)
);
CREATE INDEX IF NOT EXISTS idx_favorites_user ON pyra_favorites(username);
CREATE INDEX IF NOT EXISTS idx_favorites_path ON pyra_favorites(file_path);

-- ============================================
-- Disable RLS (app uses service_role key)
-- ============================================

ALTER TABLE pyra_users DISABLE ROW LEVEL SECURITY;
ALTER TABLE pyra_reviews DISABLE ROW LEVEL SECURITY;
ALTER TABLE pyra_trash DISABLE ROW LEVEL SECURITY;
ALTER TABLE pyra_activity_log DISABLE ROW LEVEL SECURITY;
ALTER TABLE pyra_notifications DISABLE ROW LEVEL SECURITY;
ALTER TABLE pyra_share_links DISABLE ROW LEVEL SECURITY;
ALTER TABLE pyra_teams DISABLE ROW LEVEL SECURITY;
ALTER TABLE pyra_team_members DISABLE ROW LEVEL SECURITY;
ALTER TABLE pyra_file_permissions DISABLE ROW LEVEL SECURITY;
ALTER TABLE pyra_file_versions DISABLE ROW LEVEL SECURITY;
ALTER TABLE pyra_file_index DISABLE ROW LEVEL SECURITY;
ALTER TABLE pyra_settings DISABLE ROW LEVEL SECURITY;
ALTER TABLE pyra_sessions DISABLE ROW LEVEL SECURITY;
ALTER TABLE pyra_login_attempts DISABLE ROW LEVEL SECURITY;
ALTER TABLE pyra_favorites DISABLE ROW LEVEL SECURITY;
