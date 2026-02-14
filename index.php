<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
$publicSettings = function_exists('getPublicSettings') ? getPublicSettings() : ['app_name' => 'Pyra Workspace', 'app_logo_url' => '', 'primary_color' => '#8b5cf6'];

$isLoggedIn = isLoggedIn();
$userData = $isLoggedIn ? sessionUserInfo() : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($publicSettings['app_name'] ?? 'Pyra Workspace') ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;450;500;600;700&family=Cairo:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&family=Noto+Sans+Arabic:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>📁</text></svg>">
    <?php if (!empty($publicSettings['primary_color']) && $publicSettings['primary_color'] !== '#8b5cf6'): ?>
    <style>:root { --primary: <?= htmlspecialchars($publicSettings['primary_color']) ?>; --primary-hover: <?= htmlspecialchars($publicSettings['primary_color']) ?>dd; }</style>
    <?php endif; ?>
    <!-- Alpine.js — lightweight reactive UI framework -->
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <!-- Lucide Icons — modern icon set -->
    <script src="https://unpkg.com/lucide@latest/dist/umd/lucide.min.js" defer></script>
    <!-- mammoth.js for DOCX preview -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/mammoth/1.8.0/mammoth.browser.min.js" crossorigin="anonymous" defer></script>
</head>
<body id="bodyEl">

<?php if (!$isLoggedIn): ?>
<!-- Login Screen -->
<div class="login-screen" id="loginScreen">
    <div class="login-particles">
        <span class="particle"></span><span class="particle"></span><span class="particle"></span><span class="particle"></span>
        <span class="particle"></span><span class="particle"></span><span class="particle"></span><span class="particle"></span>
    </div>
    <div class="login-card">
        <div class="login-logo">
            <span class="login-logo-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>
                </svg>
            </span>
            <span class="login-logo-text"><?= htmlspecialchars($publicSettings['app_name'] ?? 'Pyra Workspace') ?></span>
        </div>
        <form id="loginForm" onsubmit="return App.handleLogin(event)">
            <div class="login-field">
                <label for="loginUsername">Username</label>
                <input type="text" id="loginUsername" name="username" autocomplete="username" required autofocus>
            </div>
            <div class="login-field">
                <label for="loginPassword">Password</label>
                <input type="password" id="loginPassword" name="password" autocomplete="current-password" required>
            </div>
            <label class="login-remember">
                <input type="checkbox" id="rememberMe"> Remember me
            </label>
            <div class="login-error" id="loginError"></div>
            <button type="submit" class="btn btn-primary login-btn" id="loginBtn">Sign In</button>
        </form>
    </div>
</div>
<?php endif; ?>

    <div class="app-container" <?php if (!$isLoggedIn): ?>style="display:none"<?php endif; ?>>
        <!-- Top Bar -->
        <div class="top-bar">
            <div class="logo">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>
                </svg>
                <?= htmlspecialchars($publicSettings['app_name'] ?? 'Pyra Workspace') ?>
            </div>
            <div class="breadcrumb" id="breadcrumb"></div>
            <?php if ($isLoggedIn): ?>
            <div class="user-menu" id="userMenu">
                <button class="btn btn-ghost btn-sm btn-icon notif-bell" id="notifBell" onclick="App.showNotificationsPanel()" title="Notifications">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
                        <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
                    </svg>
                    <span class="notif-badge" id="notifBadge" style="display:none">0</span>
                </button>
                <div class="theme-toggle" id="themeToggle" onclick="App.toggleTheme()" title="Switch Theme">
                    <span class="theme-toggle-icon purple">
                        <svg viewBox="0 0 24 24" fill="currentColor" width="12" height="12"><circle cx="12" cy="12" r="5"/></svg>
                    </span>
                    <span class="theme-toggle-icon orange">
                        <svg viewBox="0 0 24 24" fill="currentColor" width="12" height="12"><circle cx="12" cy="12" r="5"/></svg>
                    </span>
                    <span class="theme-toggle-knob"></span>
                </div>
                <span class="user-badge <?= htmlspecialchars($userData['role']) ?>"><?= htmlspecialchars($userData['role']) ?></span>
                <div class="user-avatar" id="userAvatar"><?= strtoupper(substr($userData['display_name'] ?? 'U', 0, 2)) ?></div>
                <span class="user-name"><?= htmlspecialchars($userData['display_name']) ?></span>
                <button class="btn btn-ghost btn-sm btn-icon" onclick="App.handleLogout()" title="Sign Out">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                </button>
            </div>
            <?php endif; ?>
        </div>

        <!-- Toolbar -->
        <div class="toolbar">
            <div class="toolbar-group">
                <button class="btn" onclick="App.showDashboard()" title="Dashboard">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                    Dashboard
                </button>
                <button class="btn" onclick="App.goBack()" title="Go Back (Alt+Left)">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
                    Back
                </button>
            </div>

            <div class="toolbar-sep"></div>

            <div class="toolbar-group">
                <?php if ($isLoggedIn && ($userData['permissions']['can_upload'] ?? false)): ?>
                <button class="btn btn-primary" onclick="App.triggerUpload()">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                    Upload
                </button>
                <?php endif; ?>
                <?php if ($isLoggedIn && ($userData['permissions']['can_create_folder'] ?? false)): ?>
                <button class="btn" onclick="App.showNewFolderModal()">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/><line x1="12" y1="11" x2="12" y2="17"/><line x1="9" y1="14" x2="15" y2="14"/></svg>
                    New Folder
                </button>
                <?php endif; ?>
                <?php if ($isLoggedIn && ($userData['role'] ?? '') === 'admin'): ?>
                <button class="btn" onclick="App.showUsersPanel()">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    Users
                </button>
                <button class="btn" onclick="App.showTeamsPanel()">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2z"/><path d="M8 14s1.5 2 4 2 4-2 4-2"/><circle cx="9" cy="9" r="1.5"/><circle cx="15" cy="9" r="1.5"/></svg>
                    Teams
                </button>
                <?php endif; ?>
                <?php if ($isLoggedIn && ($userData['role'] ?? '') === 'admin'): ?>
                <button class="btn" onclick="App.showTrashPanel()">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="3 6 5 6 21 6"/>
                        <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                    </svg>
                    Trash
                </button>
                <?php endif; ?>
                <?php if ($isLoggedIn && ($userData['role'] ?? '') === 'admin'): ?>
                <button class="btn" onclick="App.showActivityPanel()">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>
                    </svg>
                    Activity
                </button>
                <?php endif; ?>
                <?php if ($isLoggedIn && ($userData['role'] ?? '') === 'admin'): ?>
                <button class="btn" onclick="App.showSettingsPanel()">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                    Settings
                </button>
                <?php endif; ?>
            </div>

            <div class="toolbar-sep"></div>

            <div class="toolbar-group">
                <button class="btn btn-ghost btn-icon" onclick="App.loadFiles()" title="Refresh">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
                </button>
                <div class="view-toggle" id="viewToggle">
                    <button class="view-toggle-btn active" data-view="list" onclick="App.setView('list')" title="List View">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
                    </button>
                    <button class="view-toggle-btn" data-view="grid" onclick="App.setView('grid')" title="Grid View">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                    </button>
                </div>
            </div>

            <?php if ($isLoggedIn): ?>
            <button class="btn btn-ghost btn-sm btn-icon" onclick="App.showDeepSearchModal()" title="Search All Folders (Ctrl+Shift+F)">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="11" cy="11" r="8"/>
                    <line x1="21" y1="21" x2="16.65" y2="16.65"/>
                </svg>
            </button>
            <?php endif; ?>
            <div class="search-box">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input type="text" id="searchInput" placeholder="Search files...">
            </div>
        </div>

        <!-- Info Bar -->
        <div class="info-bar" id="infoBar"></div>

        <!-- Main Content -->
        <div class="main-content">
            <!-- File List -->
            <div class="file-panel" style="position:relative">
                <!-- Column Headers -->
                <div class="file-list-header">
                    <span></span>
                    <span class="sortable" onclick="App.toggleSort('name')">Name <span id="sortIndicatorName"></span></span>
                    <span></span>
                    <span class="sortable" style="text-align:right" onclick="App.toggleSort('size')">Size <span id="sortIndicatorSize"></span></span>
                    <span class="sortable col-date" style="text-align:right" onclick="App.toggleSort('date')">Modified <span id="sortIndicatorDate"></span></span>
                    <span></span>
                </div>
                <div class="file-grid" id="fileGrid"></div>
                <div class="loading-overlay" id="loadingOverlay" style="display:none">
                    <div class="spinner"></div>
                </div>
            </div>

            <!-- Preview Panel -->
            <div class="preview-panel" id="previewPanel">
                <div class="preview-header">
                    <div class="preview-title" id="previewTitle"></div>
                    <div class="preview-actions" id="previewActions"></div>
                </div>
                <div class="preview-file-info" id="previewFileInfo"></div>
                <div class="preview-body" id="previewBody"></div>
            </div>
        </div>
    </div>

    <!-- Drop Zone -->
    <div class="drop-zone" id="dropZone">
        <div class="drop-zone-content">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
            <p>Drop files to upload</p>
            <p class="hint">Files will be uploaded to the current folder</p>
        </div>
    </div>

    <!-- Context Menu -->
    <div class="context-menu" id="contextMenu"></div>

    <!-- Modal -->
    <div class="modal-overlay" id="modalOverlay">
        <div class="modal" id="modalContent">
            <div class="modal-title" id="modalTitle"></div>
            <div class="modal-body" id="modalBody">
                <input type="text" class="modal-input" id="modalInput" placeholder="Enter name...">
            </div>
            <div class="modal-actions" id="modalActions">
                <button class="btn" id="modalCancel">Cancel</button>
                <button class="btn btn-primary" id="modalConfirm">Confirm</button>
            </div>
        </div>
    </div>

    <!-- Upload Progress -->
    <div class="upload-progress" id="uploadProgress">
        <div class="upload-progress-title">Uploading files...</div>
        <div class="upload-progress-bar">
            <div class="upload-progress-fill" id="uploadProgressFill"></div>
        </div>
        <div class="upload-progress-text" id="uploadProgressText"></div>
    </div>

    <!-- Toast -->
    <div class="toast-container" id="toastContainer"></div>

    <script>
        window.PYRA_CONFIG = {
            supabaseUrl: '<?= defined("SUPABASE_URL") ? SUPABASE_URL : "" ?>',
            bucket: '<?= defined("SUPABASE_BUCKET") ? SUPABASE_BUCKET : "" ?>',
            maxUploadSize: <?= defined("MAX_UPLOAD_SIZE") ? MAX_UPLOAD_SIZE : 524288000 ?>,
            auth: <?= $isLoggedIn ? 'true' : 'false' ?>,
            user: <?= $isLoggedIn ? json_encode($userData) : 'null' ?>,
            csrf_token: '<?= $_SESSION['csrf_token'] ?? '' ?>',
            settings: <?= json_encode($publicSettings) ?>
        };
    </script>
    <script src="js/app.js"></script>
</body>
</html>
