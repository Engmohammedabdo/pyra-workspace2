<?php
/**
 * Pyra Workspace - Setup & Installation
 * Run once to create database tables and admin user
 * DELETE THIS FILE after setup is complete
 */

// Load config if exists
$configExists = file_exists(__DIR__ . '/includes/config.php');
if ($configExists) {
    require_once __DIR__ . '/includes/config.php';
}

$step = $_POST['step'] ?? ($_GET['step'] ?? 'check');
$message = '';
$messageType = '';

// === Helper: run SQL via Supabase REST ===
function runSQL(string $sql): array {
    $url = SUPABASE_URL . '/rest/v1/rpc/';

    // Use the pg_catalog approach - run raw SQL via PostgREST rpc if available
    // Fallback: use the /sql endpoint if available, or direct REST calls
    // For Supabase self-hosted, we use the /pg endpoint or direct table creation

    // Try creating tables via individual REST API calls
    return ['success' => true];
}

function dbRequest(string $method, string $endpoint, $body = null, array $extraHeaders = []): array {
    $url = SUPABASE_URL . '/rest/v1' . $endpoint;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $headers = [
        'Authorization: Bearer ' . SUPABASE_SERVICE_KEY,
        'apikey: ' . SUPABASE_SERVICE_KEY,
        'Content-Type: application/json',
    ];
    foreach ($extraHeaders as $h) {
        $headers[] = $h;
    }

    switch (strtoupper($method)) {
        case 'POST':
            curl_setopt($ch, CURLOPT_POST, true);
            if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
            break;
        case 'PATCH':
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
            if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
            break;
        case 'DELETE':
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
            break;
    }

    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        return ['error' => $error, 'httpCode' => 0, 'raw' => ''];
    }
    return ['data' => json_decode($response, true), 'httpCode' => $httpCode, 'raw' => $response];
}

function testConnection(): array {
    $url = SUPABASE_URL . '/rest/v1/';
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . SUPABASE_SERVICE_KEY,
        'apikey: ' . SUPABASE_SERVICE_KEY,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        return ['success' => false, 'error' => 'cURL error: ' . $error];
    }
    if ($httpCode >= 200 && $httpCode < 300) {
        return ['success' => true];
    }
    return ['success' => false, 'error' => 'HTTP ' . $httpCode . ' - check your SUPABASE_URL and SUPABASE_SERVICE_KEY'];
}

function checkTableExists(string $table): bool {
    $result = dbRequest('GET', '/' . $table . '?limit=0');
    return $result['httpCode'] === 200;
}

function checkAdminExists(): bool {
    $result = dbRequest('GET', '/pyra_users?role=eq.admin&limit=1');
    return $result['httpCode'] === 200 && is_array($result['data']) && count($result['data']) > 0;
}

// === Handle POST actions ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $configExists) {

    if ($step === 'test_connection') {
        $result = testConnection();
        if ($result['success']) {
            $message = 'Connection successful!';
            $messageType = 'success';
            $step = 'check_tables';
        } else {
            $message = 'Connection failed: ' . $result['error'];
            $messageType = 'error';
            $step = 'check';
        }
    }

    if ($step === 'check_tables') {
        $usersExist = checkTableExists('pyra_users');
        $reviewsExist = checkTableExists('pyra_reviews');

        if ($usersExist && $reviewsExist) {
            $message = 'Tables already exist.';
            $messageType = 'success';
            if (checkAdminExists()) {
                $step = 'done';
                $message = 'Setup is already complete! Tables exist and admin user found.';
            } else {
                $step = 'create_admin';
                $message = 'Tables exist but no admin user found. Create one below.';
                $messageType = 'warning';
            }
        } else {
            $step = 'show_sql';
            $message = '';
            if ($usersExist) $message .= 'pyra_users table exists. ';
            if ($reviewsExist) $message .= 'pyra_reviews table exists. ';
            if (!$usersExist) $message .= 'pyra_users table NOT found. ';
            if (!$reviewsExist) $message .= 'pyra_reviews table NOT found. ';
            $messageType = 'warning';
        }
    }

    if ($step === 'create_admin') {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $displayName = trim($_POST['display_name'] ?? '');

        if ($username && $password && $displayName) {
            if (strlen($password) < 6) {
                $message = 'Password must be at least 6 characters.';
                $messageType = 'error';
            } else {
                $data = [
                    'username' => $username,
                    'password_hash' => password_hash($password, PASSWORD_BCRYPT),
                    'role' => 'admin',
                    'display_name' => $displayName,
                    'permissions' => [
                        'allowed_paths' => ['*'],
                        'can_upload' => true,
                        'can_edit' => true,
                        'can_delete' => true,
                        'can_create_folder' => true,
                        'can_download' => true,
                        'can_review' => true
                    ]
                ];

                $result = dbRequest('POST', '/pyra_users', $data, ['Prefer: return=representation']);

                if ($result['httpCode'] === 201) {
                    $message = 'Admin user "' . htmlspecialchars($username) . '" created successfully!';
                    $messageType = 'success';
                    $step = 'done';
                } elseif ($result['httpCode'] === 409 || ($result['data']['code'] ?? '') === '23505') {
                    $message = 'Username "' . htmlspecialchars($username) . '" already exists.';
                    $messageType = 'error';
                } else {
                    $message = 'Failed to create admin. HTTP ' . $result['httpCode'];
                    if (isset($result['data']['message'])) {
                        $message .= ' - ' . $result['data']['message'];
                    }
                    if (isset($result['data']['hint'])) {
                        $message .= ' (Hint: ' . $result['data']['hint'] . ')';
                    }
                    $messageType = 'error';
                }
            }
        } else {
            $message = 'All fields are required.';
            $messageType = 'error';
        }
    }
}

// Build SQL for display
$sqlScript = <<<'SQL'
-- =============================================
-- Pyra Workspace: Database Setup
-- Run this in Supabase SQL Editor
-- =============================================

-- 1. Create Users table
CREATE TABLE IF NOT EXISTS pyra_users (
    id SERIAL PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(20) NOT NULL DEFAULT 'client' CHECK (role IN ('admin', 'client')),
    display_name VARCHAR(100) NOT NULL,
    permissions JSONB NOT NULL DEFAULT '{}',
    created_at TIMESTAMPTZ DEFAULT NOW()
);

-- 2. Create Reviews table
CREATE TABLE IF NOT EXISTS pyra_reviews (
    id VARCHAR(20) PRIMARY KEY,
    file_path TEXT NOT NULL,
    username VARCHAR(50) NOT NULL,
    display_name VARCHAR(100) NOT NULL,
    type VARCHAR(20) NOT NULL CHECK (type IN ('comment', 'approval')),
    text TEXT DEFAULT '',
    resolved BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMPTZ DEFAULT NOW()
);

-- 3. Create indexes
CREATE INDEX IF NOT EXISTS idx_reviews_file_path ON pyra_reviews(file_path);
CREATE INDEX IF NOT EXISTS idx_reviews_username ON pyra_reviews(username);
CREATE INDEX IF NOT EXISTS idx_users_username ON pyra_users(username);

-- 4. Disable RLS (app uses service_role key for all access)
ALTER TABLE pyra_users DISABLE ROW LEVEL SECURITY;
ALTER TABLE pyra_reviews DISABLE ROW LEVEL SECURITY;
SQL;

?>
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pyra Workspace - Setup</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', -apple-system, sans-serif;
            background: #0d1017;
            color: #e6eaf0;
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: flex-start;
            padding: 40px 16px;
        }
        .container { max-width: 620px; width: 100%; }
        .logo {
            text-align: center;
            margin-bottom: 32px;
            font-size: 24px;
            font-weight: 700;
            color: #7c6fff;
        }
        .logo svg { width: 32px; height: 32px; vertical-align: middle; margin-right: 8px; stroke: #7c6fff; }
        .card {
            background: #151a23;
            border: 1px solid #232d42;
            border-radius: 14px;
            padding: 28px;
            margin-bottom: 20px;
        }
        .card h2 {
            font-size: 18px;
            margin-bottom: 6px;
            color: #e6eaf0;
        }
        .card p {
            color: #8892a5;
            font-size: 14px;
            margin-bottom: 20px;
            line-height: 1.6;
        }
        .step-badge {
            display: inline-block;
            background: #7c6fff22;
            color: #7c6fff;
            font-size: 12px;
            font-weight: 600;
            padding: 3px 10px;
            border-radius: 20px;
            margin-bottom: 12px;
        }
        .msg {
            padding: 12px 16px;
            border-radius: 8px;
            font-size: 14px;
            margin-bottom: 20px;
            line-height: 1.5;
        }
        .msg.success { background: #0d2818; border: 1px solid #1a4d2e; color: #4ade80; }
        .msg.error { background: #2d0f0f; border: 1px solid #5c1a1a; color: #f87171; }
        .msg.warning { background: #2d2400; border: 1px solid #5c4a00; color: #fbbf24; }
        .msg.info { background: #0d1a2d; border: 1px solid #1a3a5c; color: #60a5fa; }
        label {
            display: block;
            font-size: 13px;
            font-weight: 500;
            color: #8892a5;
            margin-bottom: 6px;
        }
        input[type="text"], input[type="password"] {
            width: 100%;
            padding: 10px 14px;
            background: #0d1017;
            border: 1px solid #232d42;
            border-radius: 8px;
            color: #e6eaf0;
            font-size: 14px;
            margin-bottom: 16px;
            outline: none;
            transition: border-color 0.2s;
        }
        input:focus { border-color: #7c6fff; }
        .btn {
            display: inline-block;
            padding: 10px 24px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
        }
        .btn-primary { background: #7c6fff; color: #fff; }
        .btn-primary:hover { background: #6958e0; }
        .btn-secondary { background: #232d42; color: #e6eaf0; }
        .btn-secondary:hover { background: #2a3650; }
        .btn-block { width: 100%; text-align: center; }
        .sql-box {
            background: #0d1017;
            border: 1px solid #232d42;
            border-radius: 8px;
            padding: 16px;
            font-family: 'JetBrains Mono', 'Consolas', monospace;
            font-size: 12.5px;
            line-height: 1.7;
            color: #a0aab8;
            white-space: pre-wrap;
            word-break: break-all;
            max-height: 400px;
            overflow-y: auto;
            margin-bottom: 16px;
            position: relative;
        }
        .copy-btn {
            position: absolute;
            top: 8px;
            right: 8px;
            background: #232d42;
            color: #8892a5;
            border: none;
            padding: 5px 12px;
            border-radius: 6px;
            font-size: 12px;
            cursor: pointer;
        }
        .copy-btn:hover { background: #2a3650; color: #e6eaf0; }
        .field-hint { font-size: 12px; color: #5a6478; margin-top: -12px; margin-bottom: 16px; }
        .check-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 0;
            border-bottom: 1px solid #1a2233;
            font-size: 14px;
        }
        .check-item:last-child { border-bottom: none; }
        .check-icon { width: 22px; height: 22px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 12px; flex-shrink: 0; }
        .check-ok { background: #0d2818; color: #4ade80; }
        .check-fail { background: #2d0f0f; color: #f87171; }
        .check-warn { background: #2d2400; color: #fbbf24; }
        .actions { display: flex; gap: 10px; margin-top: 20px; }
        .actions form { flex: 1; }
        .done-box { text-align: center; padding: 20px 0; }
        .done-box svg { width: 48px; height: 48px; stroke: #4ade80; margin-bottom: 16px; }
        .warning-box {
            background: #1a1400;
            border: 1px solid #4a3a00;
            border-radius: 8px;
            padding: 14px 16px;
            margin-top: 16px;
            font-size: 13px;
            color: #fbbf24;
            line-height: 1.6;
        }
    </style>
</head>
<body>
<div class="container">
    <div class="logo">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>
        </svg>
        Pyra Workspace Setup
    </div>

    <?php if ($message): ?>
        <div class="msg <?= $messageType ?>"><?= $message ?></div>
    <?php endif; ?>

    <?php if (!$configExists): ?>
    <!-- STEP 0: No config.php -->
    <div class="card">
        <span class="step-badge">Step 1</span>
        <h2>Create Configuration File</h2>
        <p>Copy <code>config.example.php</code> to <code>config.php</code> and fill in your Supabase credentials, then refresh this page.</p>
        <div class="sql-box" style="position:relative">
            <button class="copy-btn" onclick="copyText(this, 'configCode')">Copy</button>
            <code id="configCode">&lt;?php
define('SUPABASE_URL', 'https://your-supabase-instance.com');
define('SUPABASE_SERVICE_KEY', 'your-service-role-key-here');
define('SUPABASE_BUCKET', 'your-bucket-name');
define('MAX_UPLOAD_SIZE', 524288000); // 500MB</code>
        </div>
        <a href="setup.php" class="btn btn-primary btn-block">I've created config.php - Refresh</a>
    </div>

    <?php elseif ($step === 'check'): ?>
    <!-- STEP 1: Test connection -->
    <div class="card">
        <span class="step-badge">Step 1</span>
        <h2>Test Database Connection</h2>
        <p>Verify your Supabase connection before proceeding.</p>

        <div class="check-item">
            <span class="check-icon check-ok">&#10003;</span>
            <span><strong>config.php</strong> found</span>
        </div>
        <div class="check-item">
            <span class="check-icon <?= defined('SUPABASE_URL') && SUPABASE_URL !== 'https://your-supabase-instance.com' ? 'check-ok' : 'check-fail' ?>">
                <?= defined('SUPABASE_URL') && SUPABASE_URL !== 'https://your-supabase-instance.com' ? '&#10003;' : '&#10007;' ?>
            </span>
            <span><strong>SUPABASE_URL</strong> = <?= defined('SUPABASE_URL') ? htmlspecialchars(substr(SUPABASE_URL, 0, 50)) . (strlen(SUPABASE_URL) > 50 ? '...' : '') : '<em>not set</em>' ?></span>
        </div>
        <div class="check-item">
            <span class="check-icon <?= defined('SUPABASE_SERVICE_KEY') && SUPABASE_SERVICE_KEY !== 'your-service-role-key-here' ? 'check-ok' : 'check-fail' ?>">
                <?= defined('SUPABASE_SERVICE_KEY') && SUPABASE_SERVICE_KEY !== 'your-service-role-key-here' ? '&#10003;' : '&#10007;' ?>
            </span>
            <span><strong>SUPABASE_SERVICE_KEY</strong> = <?= defined('SUPABASE_SERVICE_KEY') && SUPABASE_SERVICE_KEY !== 'your-service-role-key-here' ? htmlspecialchars(substr(SUPABASE_SERVICE_KEY, 0, 20)) . '...' : '<em>not set</em>' ?></span>
        </div>
        <div class="check-item">
            <span class="check-icon check-ok">&#10003;</span>
            <span><strong>SUPABASE_BUCKET</strong> = <?= defined('SUPABASE_BUCKET') ? htmlspecialchars(SUPABASE_BUCKET) : '<em>not set</em>' ?></span>
        </div>

        <form method="post" style="margin-top: 20px">
            <input type="hidden" name="step" value="test_connection">
            <button type="submit" class="btn btn-primary btn-block">Test Connection</button>
        </form>
    </div>

    <?php elseif ($step === 'show_sql'): ?>
    <!-- STEP 2: Show SQL to run -->
    <div class="card">
        <span class="step-badge">Step 2</span>
        <h2>Create Database Tables</h2>
        <p>Copy the SQL below and run it in your <strong>Supabase SQL Editor</strong>, then click "I've run the SQL".</p>

        <div class="sql-box" style="position:relative">
            <button class="copy-btn" onclick="copyText(this, 'sqlCode')">Copy</button>
            <code id="sqlCode"><?= htmlspecialchars($sqlScript) ?></code>
        </div>

        <div class="actions">
            <form method="post">
                <input type="hidden" name="step" value="check_tables">
                <button type="submit" class="btn btn-primary btn-block">I've run the SQL - Verify Tables</button>
            </form>
        </div>
    </div>

    <?php elseif ($step === 'create_admin'): ?>
    <!-- STEP 3: Create admin user -->
    <div class="card">
        <span class="step-badge">Step 3</span>
        <h2>Create Admin User</h2>
        <p>Create the first admin account to access Pyra Workspace.</p>

        <form method="post">
            <input type="hidden" name="step" value="create_admin">

            <label for="display_name">Display Name</label>
            <input type="text" id="display_name" name="display_name" placeholder="e.g. Mohammed" required value="<?= htmlspecialchars($_POST['display_name'] ?? '') ?>">

            <label for="username">Username</label>
            <input type="text" id="username" name="username" placeholder="e.g. admin" required autocomplete="off" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">

            <label for="password">Password</label>
            <input type="password" id="password" name="password" placeholder="Minimum 6 characters" required autocomplete="new-password">
            <div class="field-hint">Password will be hashed with bcrypt before saving.</div>

            <button type="submit" class="btn btn-primary btn-block">Create Admin User</button>
        </form>
    </div>

    <?php elseif ($step === 'done'): ?>
    <!-- DONE -->
    <div class="card">
        <div class="done-box">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                <polyline points="22 4 12 14.01 9 11.01"/>
            </svg>
            <h2>Setup Complete!</h2>
            <p style="color:#8892a5; margin-top:8px">Pyra Workspace is ready to use.</p>
        </div>

        <a href="index.php" class="btn btn-primary btn-block" style="margin-top:12px">Open Pyra Workspace</a>

        <div class="warning-box">
            <strong>Security Reminder:</strong> Delete <code>setup.php</code> from your server now to prevent unauthorized access.
        </div>
    </div>
    <?php endif; ?>

</div>

<script>
function copyText(btn, id) {
    const el = document.getElementById(id);
    const text = el.textContent;
    navigator.clipboard.writeText(text).then(() => {
        btn.textContent = 'Copied!';
        setTimeout(() => btn.textContent = 'Copy', 2000);
    });
}
</script>
</body>
</html>
