-- ============================================
-- Pyra Client Portal: Database Schema
-- 7 new tables + indexes + views
-- Run this in your Supabase SQL Editor
-- DO NOT modify existing tables or schema.sql
-- ============================================

-- ============================================
-- 1. Client Accounts
-- ============================================

CREATE TABLE IF NOT EXISTS pyra_clients (
    id VARCHAR(20) PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    company VARCHAR(150) NOT NULL,
    phone VARCHAR(30) DEFAULT NULL,
    avatar_url TEXT DEFAULT NULL,
    role VARCHAR(20) DEFAULT 'primary' CHECK (role IN ('primary', 'billing', 'viewer')),
    status VARCHAR(20) DEFAULT 'active' CHECK (status IN ('active', 'inactive', 'suspended')),
    language VARCHAR(5) DEFAULT 'ar',
    last_login_at TIMESTAMPTZ DEFAULT NULL,
    created_by VARCHAR(50) NOT NULL,
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_clients_email ON pyra_clients(email);
CREATE INDEX IF NOT EXISTS idx_clients_company ON pyra_clients(company);
CREATE INDEX IF NOT EXISTS idx_clients_status ON pyra_clients(status);

-- ============================================
-- 2. Projects
-- ============================================

CREATE TABLE IF NOT EXISTS pyra_projects (
    id VARCHAR(20) PRIMARY KEY,
    name VARCHAR(200) NOT NULL,
    description TEXT DEFAULT NULL,
    client_company VARCHAR(150) NOT NULL,
    status VARCHAR(20) DEFAULT 'active' CHECK (status IN ('draft', 'active', 'in_progress', 'review', 'completed', 'archived')),
    start_date DATE DEFAULT NULL,
    deadline DATE DEFAULT NULL,
    storage_path TEXT NOT NULL,
    cover_image TEXT DEFAULT NULL,
    created_by VARCHAR(50) NOT NULL,
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_projects_company ON pyra_projects(client_company);
CREATE INDEX IF NOT EXISTS idx_projects_status ON pyra_projects(status);

-- ============================================
-- 3. Project Files
-- ============================================

CREATE TABLE IF NOT EXISTS pyra_project_files (
    id VARCHAR(20) PRIMARY KEY,
    project_id VARCHAR(20) NOT NULL REFERENCES pyra_projects(id) ON DELETE CASCADE,
    file_name VARCHAR(255) NOT NULL,
    file_path TEXT NOT NULL,
    file_size BIGINT DEFAULT 0,
    mime_type VARCHAR(100) DEFAULT NULL,
    category VARCHAR(50) DEFAULT 'general' CHECK (category IN ('general', 'design', 'video', 'document', 'audio', 'other')),
    version INT DEFAULT 1,
    needs_approval BOOLEAN DEFAULT FALSE,
    uploaded_by VARCHAR(50) NOT NULL,
    created_at TIMESTAMPTZ DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_pf_project ON pyra_project_files(project_id);
CREATE INDEX IF NOT EXISTS idx_pf_approval ON pyra_project_files(needs_approval) WHERE needs_approval = TRUE;

-- ============================================
-- 4. File Approvals
-- ============================================

CREATE TABLE IF NOT EXISTS pyra_file_approvals (
    id VARCHAR(20) PRIMARY KEY,
    file_id VARCHAR(20) NOT NULL REFERENCES pyra_project_files(id) ON DELETE CASCADE,
    client_id VARCHAR(20) NOT NULL REFERENCES pyra_clients(id) ON DELETE CASCADE,
    status VARCHAR(20) DEFAULT 'pending' CHECK (status IN ('pending', 'approved', 'revision_requested')),
    comment TEXT DEFAULT NULL,
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW(),
    UNIQUE(file_id, client_id)
);

CREATE INDEX IF NOT EXISTS idx_fa_file ON pyra_file_approvals(file_id);
CREATE INDEX IF NOT EXISTS idx_fa_client ON pyra_file_approvals(client_id);
CREATE INDEX IF NOT EXISTS idx_fa_status ON pyra_file_approvals(status);

-- ============================================
-- 5. Client Comments (threaded)
-- ============================================

CREATE TABLE IF NOT EXISTS pyra_client_comments (
    id VARCHAR(20) PRIMARY KEY,
    project_id VARCHAR(20) NOT NULL REFERENCES pyra_projects(id) ON DELETE CASCADE,
    file_id VARCHAR(20) DEFAULT NULL REFERENCES pyra_project_files(id) ON DELETE SET NULL,
    author_type VARCHAR(10) NOT NULL CHECK (author_type IN ('client', 'team')),
    author_id VARCHAR(50) NOT NULL,
    author_name VARCHAR(100) NOT NULL,
    text TEXT NOT NULL,
    parent_id VARCHAR(20) DEFAULT NULL REFERENCES pyra_client_comments(id) ON DELETE CASCADE,
    is_read_by_client BOOLEAN DEFAULT FALSE,
    is_read_by_team BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMPTZ DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_cc_project ON pyra_client_comments(project_id);
CREATE INDEX IF NOT EXISTS idx_cc_file ON pyra_client_comments(file_id);
CREATE INDEX IF NOT EXISTS idx_cc_parent ON pyra_client_comments(parent_id);

-- ============================================
-- 6. Client Notifications
-- ============================================

CREATE TABLE IF NOT EXISTS pyra_client_notifications (
    id VARCHAR(20) PRIMARY KEY,
    client_id VARCHAR(20) NOT NULL REFERENCES pyra_clients(id) ON DELETE CASCADE,
    type VARCHAR(30) NOT NULL CHECK (type IN ('new_file', 'file_updated', 'project_status', 'comment_reply', 'approval_reset', 'welcome')),
    title VARCHAR(200) NOT NULL,
    message TEXT DEFAULT NULL,
    target_project_id VARCHAR(20) DEFAULT NULL,
    target_file_id VARCHAR(20) DEFAULT NULL,
    is_read BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMPTZ DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_cn_client ON pyra_client_notifications(client_id);
CREATE INDEX IF NOT EXISTS idx_cn_unread ON pyra_client_notifications(client_id, is_read) WHERE is_read = FALSE;

-- ============================================
-- 7. Password Reset Tokens
-- ============================================

CREATE TABLE IF NOT EXISTS pyra_client_password_resets (
    id VARCHAR(20) PRIMARY KEY,
    client_id VARCHAR(20) NOT NULL REFERENCES pyra_clients(id) ON DELETE CASCADE,
    token VARCHAR(128) NOT NULL UNIQUE,
    expires_at TIMESTAMPTZ NOT NULL,
    used BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMPTZ DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_cpr_token ON pyra_client_password_resets(token);

-- ============================================
-- Disable RLS (app uses service_role key)
-- ============================================

ALTER TABLE pyra_clients DISABLE ROW LEVEL SECURITY;
ALTER TABLE pyra_projects DISABLE ROW LEVEL SECURITY;
ALTER TABLE pyra_project_files DISABLE ROW LEVEL SECURITY;
ALTER TABLE pyra_file_approvals DISABLE ROW LEVEL SECURITY;
ALTER TABLE pyra_client_comments DISABLE ROW LEVEL SECURITY;
ALTER TABLE pyra_client_notifications DISABLE ROW LEVEL SECURITY;
ALTER TABLE pyra_client_password_resets DISABLE ROW LEVEL SECURITY;

-- ============================================
-- Helper Views
-- ============================================

DROP VIEW IF EXISTS v_project_summary CASCADE;
CREATE VIEW v_project_summary AS
SELECT
    p.id,
    p.name,
    p.client_company,
    p.status,
    p.start_date,
    p.deadline,
    p.storage_path,
    p.cover_image,
    p.created_by,
    p.created_at,
    p.updated_at,
    COUNT(pf.id) AS file_count,
    COUNT(fa.id) FILTER (WHERE fa.status = 'approved') AS approved_count,
    COUNT(fa.id) FILTER (WHERE fa.status = 'pending') AS pending_count,
    COUNT(fa.id) FILTER (WHERE fa.status = 'revision_requested') AS revision_count
FROM pyra_projects p
LEFT JOIN pyra_project_files pf ON pf.project_id = p.id
LEFT JOIN pyra_file_approvals fa ON fa.file_id = pf.id
GROUP BY p.id, p.name, p.client_company, p.status, p.start_date,
         p.deadline, p.storage_path, p.cover_image, p.created_by,
         p.created_at, p.updated_at;

DROP VIEW IF EXISTS v_pending_approvals CASCADE;
CREATE VIEW v_pending_approvals AS
SELECT
    fa.id AS approval_id,
    fa.file_id,
    fa.client_id,
    fa.status AS approval_status,
    fa.comment,
    fa.created_at AS approval_created_at,
    fa.updated_at AS approval_updated_at,
    pf.file_name,
    pf.file_path,
    pf.file_size,
    pf.mime_type,
    pf.category,
    pf.version AS file_version,
    pf.project_id,
    p.name AS project_name,
    p.client_company,
    p.status AS project_status
FROM pyra_file_approvals fa
JOIN pyra_project_files pf ON pf.id = fa.file_id
JOIN pyra_projects p ON p.id = pf.project_id
WHERE fa.status = 'pending';
