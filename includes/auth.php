<?php
/**
 * Authentication & Authorization for Pyra Workspace
 * Uses Supabase PostgreSQL via REST API (PostgREST)
 */

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_samesite', 'Strict');
    ini_set('session.cookie_secure', isset($_SERVER['HTTPS']) ? 1 : 0);
    ini_set('session.use_strict_mode', 1);
    ini_set('session.gc_maxlifetime', 28800);
    session_start();
    // CSRF token
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
}

// === Database (Supabase REST API) ===

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
        return ['error' => $error, 'httpCode' => 0];
    }

    return ['data' => json_decode($response, true), 'httpCode' => $httpCode, 'raw' => $response];
}

// === User Functions ===

function findUser(string $username): ?array {
    $result = dbRequest('GET', '/pyra_users?username=eq.' . rawurlencode($username) . '&limit=1');
    if ($result['httpCode'] === 200 && is_array($result['data']) && count($result['data']) > 0) {
        $user = $result['data'][0];
        if (is_string($user['permissions'])) {
            $user['permissions'] = json_decode($user['permissions'], true);
        }
        return $user;
    }
    return null;
}

function getAllUsers(): array {
    $result = dbRequest('GET', '/pyra_users?order=created_at.asc', null, ['Prefer: return=representation']);
    if ($result['httpCode'] === 200 && is_array($result['data'])) {
        return array_map(function($user) {
            if (is_string($user['permissions'])) {
                $user['permissions'] = json_decode($user['permissions'], true);
            }
            unset($user['password_hash']);
            return $user;
        }, $result['data']);
    }
    return [];
}

function getAllUsersLite(): array {
    $result = dbRequest('GET', '/pyra_users?select=username,display_name&order=display_name.asc');
    return ($result['httpCode'] === 200 && is_array($result['data'])) ? $result['data'] : [];
}

function parseMentions(string $text): array {
    $mentions = [];
    if (preg_match_all('/@([a-zA-Z0-9_]+)/', $text, $matches)) {
        foreach (array_unique($matches[1]) as $username) {
            if (findUser($username)) {
                $mentions[] = $username;
            }
        }
    }
    return $mentions;
}

function createUser(string $username, string $password, string $role, string $displayName, array $permissions): array {
    $existing = findUser($username);
    if ($existing) {
        return ['success' => false, 'error' => 'Username already exists'];
    }

    $data = [
        'username' => $username,
        'password_hash' => password_hash($password, PASSWORD_BCRYPT),
        'role' => $role,
        'display_name' => $displayName,
        'permissions' => $permissions
    ];

    $result = dbRequest('POST', '/pyra_users', $data, ['Prefer: return=representation']);
    if ($result['httpCode'] === 201) {
        return ['success' => true];
    }
    return ['success' => false, 'error' => $result['data']['message'] ?? 'Failed to create user'];
}

function updateUser(string $username, array $fields): array {
    $allowed = ['display_name', 'role', 'permissions'];
    $update = [];
    foreach ($allowed as $key) {
        if (array_key_exists($key, $fields)) {
            $update[$key] = $fields[$key];
        }
    }
    if (empty($update)) {
        return ['success' => false, 'error' => 'No valid fields to update'];
    }

    $result = dbRequest('PATCH', '/pyra_users?username=eq.' . rawurlencode($username), $update);
    if ($result['httpCode'] === 200 || $result['httpCode'] === 204) {
        // Update session if the current user was modified
        if (isset($_SESSION['user']) && $_SESSION['user'] === $username) {
            if (isset($update['display_name'])) $_SESSION['display_name'] = $update['display_name'];
            if (isset($update['role'])) $_SESSION['role'] = $update['role'];
            if (isset($update['permissions'])) $_SESSION['permissions'] = $update['permissions'];
        }
        return ['success' => true];
    }
    return ['success' => false, 'error' => 'Failed to update user'];
}

function changeUserPassword(string $username, string $newPassword): array {
    $hash = password_hash($newPassword, PASSWORD_BCRYPT);
    $result = dbRequest('PATCH', '/pyra_users?username=eq.' . rawurlencode($username), ['password_hash' => $hash]);
    if ($result['httpCode'] === 200 || $result['httpCode'] === 204) {
        return ['success' => true];
    }
    return ['success' => false, 'error' => 'Failed to change password'];
}

function deleteUser(string $username): array {
    if (isset($_SESSION['user']) && $_SESSION['user'] === $username) {
        return ['success' => false, 'error' => 'Cannot delete yourself'];
    }
    $result = dbRequest('DELETE', '/pyra_users?username=eq.' . rawurlencode($username));
    if ($result['httpCode'] === 200 || $result['httpCode'] === 204) {
        return ['success' => true];
    }
    return ['success' => false, 'error' => 'Failed to delete user'];
}

// === Authentication ===

function attemptLogin(string $username, string $password): array {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';

    // Check account lockout
    $lockout = isAccountLocked($username);
    if ($lockout['locked']) {
        return ['success' => false, 'error' => 'Account temporarily locked. Try again in ' . $lockout['remaining_minutes'] . ' minute(s).', 'locked' => true];
    }

    $user = findUser($username);
    if (!$user) {
        recordLoginAttempt($username, $ip, false);
        return ['success' => false, 'error' => 'Invalid credentials'];
    }
    if (!password_verify($password, $user['password_hash'])) {
        recordLoginAttempt($username, $ip, false);
        return ['success' => false, 'error' => 'Invalid credentials'];
    }

    // Regenerate session ID for security
    session_regenerate_id(true);

    $_SESSION['user'] = $user['username'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['display_name'] = $user['display_name'];
    $_SESSION['permissions'] = $user['permissions'];
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    // Record successful login and create session
    recordLoginAttempt($username, $ip, true);
    createSessionRecord();

    return ['success' => true, 'user' => sessionUserInfo()];
}

function logout(): void {
    $sessionId = session_id();
    if ($sessionId) {
        terminateSession($sessionId);
    }
    session_unset();
    session_destroy();
}

function isLoggedIn(): bool {
    return isset($_SESSION['user']);
}

function sessionUserInfo(): array {
    return [
        'username' => $_SESSION['user'] ?? '',
        'role' => $_SESSION['role'] ?? '',
        'display_name' => $_SESSION['display_name'] ?? '',
        'permissions' => $_SESSION['permissions'] ?? [],
        'csrf_token' => $_SESSION['csrf_token'] ?? ''
    ];
}

function requireAuth(): void {
    if (!isLoggedIn()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Not authenticated']);
        exit;
    }
}

// === Authorization ===

/**
 * Check if user can access a path (navigate/browse).
 * Parent paths of allowed folders are browsable but NOT writable.
 * Use isPathDirectlyAllowed() for write operations.
 */
function canAccessPath(string $path): bool {
    $permissions = $_SESSION['permissions'] ?? [];
    $allowed = $permissions['allowed_paths'] ?? [];

    if (in_array('*', $allowed)) return true;

    $pathNorm = rtrim($path, '/');

    foreach ($allowed as $prefix) {
        $prefixNorm = rtrim($prefix, '/');

        // Direct match
        if ($pathNorm === $prefixNorm) return true;

        // Path is inside allowed prefix
        if ($pathNorm !== '' && strpos($pathNorm . '/', $prefixNorm . '/') === 0) return true;

        // Path is a parent of an allowed prefix (browse-only, for navigation)
        if ($pathNorm === '' || strpos($prefixNorm . '/', $pathNorm . '/') === 0) return true;
    }
    return false;
}

/**
 * Check if user has DIRECT access to a path (not just browse-through).
 * Returns true only if path IS or is INSIDE an allowed path.
 * Use this for write operations (upload, edit, delete, create folder).
 */
function isPathDirectlyAllowed(string $path): bool {
    if (isAdmin()) return true;
    $permissions = $_SESSION['permissions'] ?? [];
    $allowed = $permissions['allowed_paths'] ?? [];

    if (in_array('*', $allowed)) return true;

    $pathNorm = rtrim($path, '/');

    foreach ($allowed as $prefix) {
        $prefixNorm = rtrim($prefix, '/');

        // Direct match
        if ($pathNorm === $prefixNorm) return true;

        // Path is inside allowed prefix
        if ($pathNorm !== '' && strpos($pathNorm . '/', $prefixNorm . '/') === 0) return true;
    }
    return false;
}

function hasPermission(string $perm): bool {
    if (isAdmin()) return true;
    $permissions = $_SESSION['permissions'] ?? [];
    return !empty($permissions[$perm]);
}

function isAdmin(): bool {
    return ($_SESSION['role'] ?? '') === 'admin';
}

/**
 * Get effective permissions for a specific path, considering per-folder overrides.
 * Checks per_folder_perms first (exact match then parent match), falls back to global.
 */
function getEffectivePermissions(string $path): array {
    if (isAdmin()) {
        return ['can_upload'=>true,'can_edit'=>true,'can_delete'=>true,'can_download'=>true,'can_create_folder'=>true,'can_review'=>true];
    }
    $permissions = $_SESSION['permissions'] ?? [];
    $perFolderPerms = $permissions['per_folder_perms'] ?? [];

    if (!empty($perFolderPerms) && is_array($perFolderPerms)) {
        $pathNorm = rtrim($path, '/');
        // Find the most specific matching folder (longest prefix)
        $bestMatch = null;
        $bestLen = -1;
        foreach ($perFolderPerms as $folderPath => $folderPerms) {
            $folderNorm = rtrim($folderPath, '/');
            if ($pathNorm === $folderNorm || ($pathNorm !== '' && strpos($pathNorm . '/', $folderNorm . '/') === 0)) {
                if (strlen($folderNorm) > $bestLen) {
                    $bestMatch = $folderPerms;
                    $bestLen = strlen($folderNorm);
                }
            }
        }
        if ($bestMatch !== null && is_array($bestMatch)) {
            return [
                'can_upload' => !empty($bestMatch['can_upload']),
                'can_edit' => !empty($bestMatch['can_edit']),
                'can_delete' => !empty($bestMatch['can_delete']),
                'can_download' => !empty($bestMatch['can_download']),
                'can_create_folder' => !empty($bestMatch['can_create_folder']),
                'can_review' => !empty($bestMatch['can_review']),
            ];
        }
    }

    // Fall back to global permissions
    return [
        'can_upload' => !empty($permissions['can_upload']),
        'can_edit' => !empty($permissions['can_edit']),
        'can_delete' => !empty($permissions['can_delete']),
        'can_download' => !empty($permissions['can_download']),
        'can_create_folder' => !empty($permissions['can_create_folder']),
        'can_review' => !empty($permissions['can_review']),
    ];
}

/**
 * Check a specific permission for a given path (per-folder aware).
 */
function hasPathPermission(string $perm, string $path): bool {
    if (isAdmin()) return true;

    // 1. Check user-level effective permissions (includes per_folder_perms)
    $effective = getEffectivePermissions($path);
    if (!empty($effective[$perm])) return true;

    // 2. Check team permissions
    $username = $_SESSION['user'] ?? '';
    if ($username) {
        $userTeams = getUserTeams($username);
        foreach ($userTeams as $team) {
            $teamPerms = $team['permissions'] ?? [];
            if (!empty($teamPerms[$perm])) return true;
            // Also check team per_folder_perms
            $teamFolderPerms = $teamPerms['per_folder_perms'] ?? [];
            if (!empty($teamFolderPerms)) {
                $pathNorm = rtrim($path, '/');
                foreach ($teamFolderPerms as $folderPath => $folderPerms) {
                    $folderNorm = rtrim($folderPath, '/');
                    if ($pathNorm === $folderNorm || ($pathNorm !== '' && strpos($pathNorm . '/', $folderNorm . '/') === 0)) {
                        if (!empty($folderPerms[$perm])) return true;
                    }
                }
            }
        }
    }

    // 3. Check file-level permissions
    if ($username && $path) {
        $filePerm = getEffectiveFilePermissions($path, $username);
        if ($filePerm && !empty($filePerm[$perm])) return true;
    }

    return false;
}

// === Reviews ===

function getFileReviews(string $filePath): array {
    $result = dbRequest('GET', '/pyra_reviews?file_path=eq.' . rawurlencode($filePath) . '&order=created_at.desc');
    if ($result['httpCode'] === 200 && is_array($result['data'])) {
        return $result['data'];
    }
    return [];
}

function addReview(array $data): array {
    $review = [
        'id' => generateReviewId(),
        'file_path' => $data['file_path'],
        'username' => $_SESSION['user'],
        'display_name' => $_SESSION['display_name'] ?? $_SESSION['user'],
        'type' => $data['type'],
        'text' => $data['text'] ?? '',
        'resolved' => false,
        'parent_id' => $data['parent_id'] ?? null
    ];

    $result = dbRequest('POST', '/pyra_reviews', $review, ['Prefer: return=representation']);
    if ($result['httpCode'] === 201) {
        return ['success' => true, 'review' => is_array($result['data']) && count($result['data']) > 0 ? $result['data'][0] : $review];
    }
    return ['success' => false, 'error' => $result['data']['message'] ?? 'Failed to add review'];
}

function toggleResolveReview(string $reviewId): array {
    // First get current state
    $result = dbRequest('GET', '/pyra_reviews?id=eq.' . rawurlencode($reviewId) . '&limit=1');
    if ($result['httpCode'] !== 200 || !is_array($result['data']) || count($result['data']) === 0) {
        return ['success' => false, 'error' => 'Review not found'];
    }
    $current = $result['data'][0];
    $newState = !$current['resolved'];

    $result = dbRequest('PATCH', '/pyra_reviews?id=eq.' . rawurlencode($reviewId), ['resolved' => $newState]);
    if ($result['httpCode'] === 200 || $result['httpCode'] === 204) {
        return ['success' => true, 'resolved' => $newState];
    }
    return ['success' => false, 'error' => 'Failed to update review'];
}

function deleteReview(string $reviewId): array {
    $result = dbRequest('DELETE', '/pyra_reviews?id=eq.' . rawurlencode($reviewId));
    if ($result['httpCode'] === 200 || $result['httpCode'] === 204) {
        return ['success' => true];
    }
    return ['success' => false, 'error' => 'Failed to delete review'];
}

function updateReviewPaths(string $oldPath, string $newPath): void {
    $reviews = dbRequest('GET', '/pyra_reviews?file_path=like.' . rawurlencode($oldPath . '%'));
    if ($reviews['httpCode'] === 200 && is_array($reviews['data'])) {
        foreach ($reviews['data'] as $review) {
            $newFilePath = $newPath . substr($review['file_path'], strlen($oldPath));
            dbRequest('PATCH', '/pyra_reviews?id=eq.' . rawurlencode($review['id']), ['file_path' => $newFilePath]);
        }
    }
}

function generateReviewId(): string {
    return 'r_' . time() . '_' . substr(bin2hex(random_bytes(3)), 0, 5);
}

// === File Versioning ===

function getFileVersions(string $filePath): array {
    $result = dbRequest('GET', '/pyra_file_versions?file_path=eq.' . rawurlencode($filePath) . '&order=created_at.desc');
    if ($result['httpCode'] === 200 && is_array($result['data'])) {
        return $result['data'];
    }
    return [];
}

function createFileVersionRecord(array $data): array {
    $record = [
        'id' => 'ver_' . bin2hex(random_bytes(12)),
        'file_path' => $data['file_path'],
        'version_path' => $data['version_path'],
        'version_number' => $data['version_number'] ?? 1,
        'file_size' => $data['file_size'] ?? 0,
        'mime_type' => $data['mime_type'] ?? '',
        'created_by' => $_SESSION['user'] ?? 'system',
        'created_by_display' => $_SESSION['display_name'] ?? $_SESSION['user'] ?? 'System',
        'comment' => $data['comment'] ?? ''
    ];
    $result = dbRequest('POST', '/pyra_file_versions', $record, ['Prefer: return=representation']);
    if ($result['httpCode'] === 201) {
        return ['success' => true, 'version' => is_array($result['data']) && count($result['data']) > 0 ? $result['data'][0] : $record];
    }
    return ['success' => false, 'error' => $result['data']['message'] ?? 'Failed to create version record'];
}

function deleteFileVersionRecord(string $id): array {
    $result = dbRequest('DELETE', '/pyra_file_versions?id=eq.' . rawurlencode($id));
    if ($result['httpCode'] === 200 || $result['httpCode'] === 204) {
        return ['success' => true];
    }
    return ['success' => false, 'error' => 'Failed to delete version record'];
}

function getNextVersionNumber(string $filePath): int {
    $result = dbRequest('GET', '/pyra_file_versions?file_path=eq.' . rawurlencode($filePath) . '&order=version_number.desc&limit=1');
    if ($result['httpCode'] === 200 && is_array($result['data']) && count($result['data']) > 0) {
        return (int)$result['data'][0]['version_number'] + 1;
    }
    return 1;
}

// === File Search Index ===

function indexFile(array $fileData): void {
    $id = 'fi_' . bin2hex(random_bytes(12));
    $filePath = $fileData['file_path'];
    $fileName = basename($filePath);
    $folderPath = dirname($filePath);
    if ($folderPath === '.') $folderPath = '';
    $originalName = $fileData['original_name'] ?? null;

    $record = [
        'id' => $id,
        'file_path' => $filePath,
        'file_name' => $fileName,
        'file_name_lower' => strtolower($originalName ?: $fileName),
        'folder_path' => $folderPath,
        'file_size' => $fileData['file_size'] ?? 0,
        'mime_type' => $fileData['mime_type'] ?? '',
        'original_name' => $originalName,
        'updated_at' => date('c')
    ];

    // Try update first, then insert (upsert)
    $existing = dbRequest('GET', '/pyra_file_index?file_path=eq.' . rawurlencode($filePath) . '&limit=1');
    if ($existing['httpCode'] === 200 && is_array($existing['data']) && count($existing['data']) > 0) {
        unset($record['id']);
        dbRequest('PATCH', '/pyra_file_index?file_path=eq.' . rawurlencode($filePath), $record);
    } else {
        dbRequest('POST', '/pyra_file_index', $record);
    }
}

function getOriginalNames(array $paths): array {
    $map = [];
    if (empty($paths)) return $map;
    // Query file index for original names (batch)
    $orFilter = implode(',', array_map(function($p) { return 'file_path.eq.' . rawurlencode($p); }, $paths));
    $result = dbRequest('GET', '/pyra_file_index?or=(' . $orFilter . ')&select=file_path,original_name&original_name=not.is.null');
    if ($result['httpCode'] === 200 && is_array($result['data'])) {
        foreach ($result['data'] as $row) {
            if (!empty($row['original_name'])) {
                $map[$row['file_path']] = $row['original_name'];
            }
        }
    }
    return $map;
}

function removeFileIndex(string $filePath): void {
    dbRequest('DELETE', '/pyra_file_index?file_path=eq.' . rawurlencode($filePath));
}

function updateFileIndexPath(string $oldPath, string $newPath): void {
    $fileName = basename($newPath);
    $folderPath = dirname($newPath);
    if ($folderPath === '.') $folderPath = '';
    dbRequest('PATCH', '/pyra_file_index?file_path=eq.' . rawurlencode($oldPath), [
        'file_path' => $newPath,
        'file_name' => $fileName,
        'file_name_lower' => strtolower($fileName),
        'folder_path' => $folderPath,
        'updated_at' => date('c')
    ]);
}

function searchFileIndex(string $query, ?array $allowedPaths = null, int $limit = 200): array {
    $queryLower = strtolower(trim($query));
    $filter = 'file_name_lower=ilike.*' . rawurlencode($queryLower) . '*&limit=' . $limit . '&order=updated_at.desc';
    $result = dbRequest('GET', '/pyra_file_index?' . $filter);
    if ($result['httpCode'] !== 200 || !is_array($result['data'])) return [];

    $files = $result['data'];

    // Filter by allowed paths if not admin
    if ($allowedPaths !== null && !in_array('*', $allowedPaths)) {
        $files = array_filter($files, function($f) use ($allowedPaths) {
            $path = $f['file_path'];
            foreach ($allowedPaths as $prefix) {
                $prefixNorm = rtrim($prefix, '/');
                $pathDir = dirname($path);
                if ($pathDir === '.') $pathDir = '';
                if ($prefixNorm === '' || $prefixNorm === $pathDir || strpos($pathDir . '/', $prefixNorm . '/') === 0) {
                    return true;
                }
            }
            return false;
        });
        $files = array_values($files);
    }

    return array_map(function($f) {
        return [
            'name' => $f['file_name'],
            'path' => $f['file_path'],
            'size' => (int)$f['file_size'],
            'mimetype' => $f['mime_type'],
            'updated_at' => $f['updated_at']
        ];
    }, $files);
}

// === System Settings ===

function getSettings(): array {
    if (isset($_SESSION['_settings_cache'])) {
        return $_SESSION['_settings_cache'];
    }
    $result = dbRequest('GET', '/pyra_settings?order=key.asc');
    $settings = [];
    if ($result['httpCode'] === 200 && is_array($result['data'])) {
        foreach ($result['data'] as $row) {
            $settings[$row['key']] = $row['value'];
        }
    }
    $_SESSION['_settings_cache'] = $settings;
    return $settings;
}

function getSetting(string $key, string $default = ''): string {
    $settings = getSettings();
    return $settings[$key] ?? $default;
}

function updateSetting(string $key, string $value): bool {
    $result = dbRequest('PATCH', '/pyra_settings?key=eq.' . rawurlencode($key), [
        'value' => $value,
        'updated_by' => $_SESSION['user'] ?? '',
        'updated_at' => date('c')
    ]);
    if ($result['httpCode'] === 200 || $result['httpCode'] === 204) {
        unset($_SESSION['_settings_cache']);
        return true;
    }
    // If not found, try insert
    $result = dbRequest('POST', '/pyra_settings', [
        'key' => $key,
        'value' => $value,
        'updated_by' => $_SESSION['user'] ?? '',
        'updated_at' => date('c')
    ]);
    if ($result['httpCode'] === 201) {
        unset($_SESSION['_settings_cache']);
        return true;
    }
    return false;
}

function getPublicSettings(): array {
    $result = dbRequest('GET', '/pyra_settings?key=in.(app_name,app_logo_url,primary_color)');
    $settings = [
        'app_name' => 'Pyra Workspace',
        'app_logo_url' => '',
        'primary_color' => '#8b5cf6'
    ];
    if ($result['httpCode'] === 200 && is_array($result['data'])) {
        foreach ($result['data'] as $row) {
            $settings[$row['key']] = $row['value'];
        }
    }
    return $settings;
}

// === Session Management & Security ===

function recordLoginAttempt(string $username, string $ip, bool $success): void {
    dbRequest('POST', '/pyra_login_attempts', [
        'username' => $username,
        'ip_address' => $ip,
        'success' => $success
    ]);
}

function isAccountLocked(string $username): array {
    $maxFailed = (int)getSetting('max_failed_logins', '5');
    $lockoutMinutes = (int)getSetting('lockout_duration_minutes', '15');
    $since = date('c', time() - ($lockoutMinutes * 60));

    $result = dbRequest('GET', '/pyra_login_attempts?username=eq.' . rawurlencode($username)
        . '&success=eq.false&attempted_at=gte.' . rawurlencode($since)
        . '&order=attempted_at.desc&limit=' . $maxFailed);

    if ($result['httpCode'] === 200 && is_array($result['data']) && count($result['data']) >= $maxFailed) {
        $lastAttempt = strtotime($result['data'][0]['attempted_at']);
        $unlockAt = $lastAttempt + ($lockoutMinutes * 60);
        $remainingSec = $unlockAt - time();
        if ($remainingSec > 0) {
            return ['locked' => true, 'remaining_seconds' => $remainingSec, 'remaining_minutes' => ceil($remainingSec / 60)];
        }
    }
    return ['locked' => false];
}

function createSessionRecord(): void {
    $sessionId = session_id();
    if (!$sessionId) return;
    $username = $_SESSION['user'] ?? '';
    if (!$username) return;

    dbRequest('POST', '/pyra_sessions', [
        'id' => $sessionId,
        'username' => $username,
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
        'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
        'last_activity' => date('c')
    ]);
}

function trackSession(): void {
    $sessionId = session_id();
    if (!$sessionId || !isLoggedIn()) return;

    // Update last_activity every 5 minutes to avoid excessive DB writes
    $lastTrack = $_SESSION['_last_track'] ?? 0;
    if (time() - $lastTrack < 300) return;

    dbRequest('PATCH', '/pyra_sessions?id=eq.' . rawurlencode($sessionId), [
        'last_activity' => date('c')
    ]);
    $_SESSION['_last_track'] = time();
}

function getUserSessions(string $username): array {
    $result = dbRequest('GET', '/pyra_sessions?username=eq.' . rawurlencode($username) . '&order=last_activity.desc');
    if ($result['httpCode'] === 200 && is_array($result['data'])) {
        return $result['data'];
    }
    return [];
}

function terminateSession(string $sessionId): bool {
    $result = dbRequest('DELETE', '/pyra_sessions?id=eq.' . rawurlencode($sessionId));
    return ($result['httpCode'] === 200 || $result['httpCode'] === 204);
}

function terminateAllSessions(string $username, ?string $exceptSessionId = null): int {
    $filter = 'username=eq.' . rawurlencode($username);
    if ($exceptSessionId) {
        $filter .= '&id=neq.' . rawurlencode($exceptSessionId);
    }
    $result = dbRequest('DELETE', '/pyra_sessions?' . $filter);
    return ($result['httpCode'] === 200 || $result['httpCode'] === 204) ? 1 : 0;
}

function generateCsrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validateCsrfToken(string $token): bool {
    if (empty($_SESSION['csrf_token'])) return false;
    return hash_equals($_SESSION['csrf_token'], $token);
}

function getLoginHistory(int $limit = 50): array {
    $result = dbRequest('GET', '/pyra_login_attempts?order=attempted_at.desc&limit=' . $limit);
    if ($result['httpCode'] === 200 && is_array($result['data'])) {
        return $result['data'];
    }
    return [];
}

// === Activity Log ===

function generateLogId(): string {
    return 'l_' . time() . '_' . substr(bin2hex(random_bytes(3)), 0, 5);
}

function logActivity(string $actionType, string $targetPath = '', array $details = []): void {
    $record = [
        'id' => generateLogId(),
        'action_type' => $actionType,
        'username' => $_SESSION['user'] ?? 'system',
        'display_name' => $_SESSION['display_name'] ?? $_SESSION['user'] ?? 'system',
        'target_path' => $targetPath,
        'details' => $details,
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? ''
    ];
    dbRequest('POST', '/pyra_activity_log', $record);
}

function getActivityLog(int $limit = 100, int $offset = 0, ?string $filterUser = null, ?string $filterAction = null, ?string $filterDateFrom = null, ?string $filterDateTo = null): array {
    $endpoint = '/pyra_activity_log?order=created_at.desc&limit=' . $limit . '&offset=' . $offset;
    if ($filterUser) {
        $endpoint .= '&username=eq.' . rawurlencode($filterUser);
    }
    if ($filterAction) {
        $endpoint .= '&action_type=eq.' . rawurlencode($filterAction);
    }
    if ($filterDateFrom) {
        $endpoint .= '&created_at=gte.' . rawurlencode($filterDateFrom);
    }
    if ($filterDateTo) {
        $endpoint .= '&created_at=lte.' . rawurlencode($filterDateTo);
    }
    $result = dbRequest('GET', $endpoint);
    if ($result['httpCode'] === 200 && is_array($result['data'])) {
        return $result['data'];
    }
    return [];
}

// === Trash / Recycle Bin ===

function generateTrashId(): string {
    return 't_' . time() . '_' . substr(bin2hex(random_bytes(3)), 0, 5);
}

function addTrashRecord(array $data): array {
    $record = [
        'id' => generateTrashId(),
        'original_path' => $data['original_path'],
        'trash_path' => $data['trash_path'],
        'file_name' => $data['file_name'],
        'file_size' => $data['file_size'] ?? 0,
        'mime_type' => $data['mime_type'] ?? 'application/octet-stream',
        'deleted_by' => $_SESSION['user'],
        'deleted_by_display' => $_SESSION['display_name'] ?? $_SESSION['user']
    ];
    $result = dbRequest('POST', '/pyra_trash', $record, ['Prefer: return=representation']);
    if ($result['httpCode'] === 201) {
        return ['success' => true, 'record' => is_array($result['data']) && count($result['data']) > 0 ? $result['data'][0] : $record];
    }
    return ['success' => false, 'error' => $result['data']['message'] ?? 'Failed to add trash record'];
}

function getTrashItems(): array {
    $result = dbRequest('GET', '/pyra_trash?order=deleted_at.desc');
    if ($result['httpCode'] === 200 && is_array($result['data'])) {
        return $result['data'];
    }
    return [];
}

function getTrashRecord(string $trashId): ?array {
    $result = dbRequest('GET', '/pyra_trash?id=eq.' . rawurlencode($trashId) . '&limit=1');
    if ($result['httpCode'] === 200 && is_array($result['data']) && count($result['data']) > 0) {
        return $result['data'][0];
    }
    return null;
}

function deleteTrashRecord(string $trashId): array {
    $result = dbRequest('DELETE', '/pyra_trash?id=eq.' . rawurlencode($trashId));
    if ($result['httpCode'] === 200 || $result['httpCode'] === 204) {
        return ['success' => true];
    }
    return ['success' => false, 'error' => 'Failed to delete trash record'];
}

function getExpiredTrashItems(): array {
    $now = date('c');
    $result = dbRequest('GET', '/pyra_trash?auto_purge_at=lte.' . rawurlencode($now));
    if ($result['httpCode'] === 200 && is_array($result['data'])) {
        return $result['data'];
    }
    return [];
}

// === Notifications ===

function generateNotifId(): string {
    return 'n_' . time() . '_' . substr(bin2hex(random_bytes(3)), 0, 5);
}

function createNotification(string $recipientUsername, string $type, string $title, string $message = '', string $targetPath = ''): void {
    if ($recipientUsername === ($_SESSION['user'] ?? '')) return;
    $record = [
        'id' => generateNotifId(),
        'recipient_username' => $recipientUsername,
        'type' => $type,
        'title' => $title,
        'message' => $message,
        'source_username' => $_SESSION['user'] ?? '',
        'source_display_name' => $_SESSION['display_name'] ?? $_SESSION['user'] ?? '',
        'target_path' => $targetPath
    ];
    dbRequest('POST', '/pyra_notifications', $record);
}

function getNotifications(string $username, int $limit = 50, bool $unreadOnly = false): array {
    $endpoint = '/pyra_notifications?recipient_username=eq.' . rawurlencode($username) . '&order=created_at.desc&limit=' . $limit;
    if ($unreadOnly) {
        $endpoint .= '&is_read=eq.false';
    }
    $result = dbRequest('GET', $endpoint);
    if ($result['httpCode'] === 200 && is_array($result['data'])) {
        return $result['data'];
    }
    return [];
}

function getUnreadNotificationCount(string $username): int {
    $result = dbRequest('GET', '/pyra_notifications?recipient_username=eq.' . rawurlencode($username) . '&is_read=eq.false&select=id');
    if ($result['httpCode'] === 200 && is_array($result['data'])) {
        return count($result['data']);
    }
    return 0;
}

function markNotificationRead(string $notifId): array {
    $result = dbRequest('PATCH', '/pyra_notifications?id=eq.' . rawurlencode($notifId), ['is_read' => true]);
    if ($result['httpCode'] === 200 || $result['httpCode'] === 204) {
        return ['success' => true];
    }
    return ['success' => false, 'error' => 'Failed to mark notification as read'];
}

function markAllNotificationsRead(string $username): array {
    $result = dbRequest('PATCH', '/pyra_notifications?recipient_username=eq.' . rawurlencode($username) . '&is_read=eq.false', ['is_read' => true]);
    if ($result['httpCode'] === 200 || $result['httpCode'] === 204) {
        return ['success' => true];
    }
    return ['success' => false, 'error' => 'Failed to mark all as read'];
}

function findUsersWithPathAccess(string $path): array {
    $allUsers = getAllUsers();
    $matchingUsers = [];
    $pathNorm = rtrim($path, '/');
    foreach ($allUsers as $user) {
        // Admins always get notified regardless of allowed_paths
        if (($user['role'] ?? '') === 'admin') {
            $matchingUsers[] = $user['username'];
            continue;
        }
        $perms = $user['permissions'] ?? [];
        $allowed = $perms['allowed_paths'] ?? [];
        if (in_array('*', $allowed)) {
            $matchingUsers[] = $user['username'];
            continue;
        }
        foreach ($allowed as $prefix) {
            $prefixNorm = rtrim($prefix, '/');
            if ($pathNorm === $prefixNorm || strpos($pathNorm . '/', $prefixNorm . '/') === 0) {
                $matchingUsers[] = $user['username'];
                break;
            }
        }
    }
    return $matchingUsers;
}

// === Share Links ===

function generateShareId(): string {
    return 's_' . time() . '_' . substr(bin2hex(random_bytes(3)), 0, 5);
}

function generateShareToken(): string {
    return bin2hex(random_bytes(32));
}

function createShareLink(string $filePath, string $fileName, int $expiresInHours = 24, int $maxAccess = 0): array {
    $token = generateShareToken();
    $record = [
        'id' => generateShareId(),
        'token' => $token,
        'file_path' => $filePath,
        'file_name' => $fileName,
        'created_by' => $_SESSION['user'],
        'created_by_display' => $_SESSION['display_name'] ?? $_SESSION['user'],
        'expires_at' => date('c', time() + ($expiresInHours * 3600)),
        'max_access' => $maxAccess
    ];
    $result = dbRequest('POST', '/pyra_share_links', $record, ['Prefer: return=representation']);
    if ($result['httpCode'] === 201) {
        return ['success' => true, 'token' => $token, 'link' => is_array($result['data']) && count($result['data']) > 0 ? $result['data'][0] : $record];
    }
    return ['success' => false, 'error' => $result['data']['message'] ?? 'Failed to create share link'];
}

function getShareLinkByToken(string $token): ?array {
    $result = dbRequest('GET', '/pyra_share_links?token=eq.' . rawurlencode($token) . '&limit=1');
    if ($result['httpCode'] === 200 && is_array($result['data']) && count($result['data']) > 0) {
        return $result['data'][0];
    }
    return null;
}

function incrementShareAccess(string $shareId): void {
    $result = dbRequest('GET', '/pyra_share_links?id=eq.' . rawurlencode($shareId) . '&select=access_count');
    if ($result['httpCode'] === 200 && is_array($result['data']) && count($result['data']) > 0) {
        $newCount = ($result['data'][0]['access_count'] ?? 0) + 1;
        dbRequest('PATCH', '/pyra_share_links?id=eq.' . rawurlencode($shareId), ['access_count' => $newCount]);
    }
}

function getShareLinksForFile(string $filePath): array {
    $result = dbRequest('GET', '/pyra_share_links?file_path=eq.' . rawurlencode($filePath) . '&is_active=eq.true&order=created_at.desc');
    if ($result['httpCode'] === 200 && is_array($result['data'])) {
        return $result['data'];
    }
    return [];
}

function deactivateShareLink(string $shareId): array {
    $result = dbRequest('PATCH', '/pyra_share_links?id=eq.' . rawurlencode($shareId), ['is_active' => false]);
    if ($result['httpCode'] === 200 || $result['httpCode'] === 204) {
        return ['success' => true];
    }
    return ['success' => false, 'error' => 'Failed to deactivate share link'];
}

// === Teams / Groups ===

function generateTeamId(): string {
    return 'tm_' . time() . '_' . substr(bin2hex(random_bytes(3)), 0, 5);
}

function generateTeamMemberId(): string {
    return 'tmm_' . time() . '_' . substr(bin2hex(random_bytes(3)), 0, 5);
}

function createTeam(string $name, string $description, array $permissions): array {
    $record = [
        'id' => generateTeamId(),
        'name' => $name,
        'description' => $description,
        'permissions' => $permissions,
        'created_by' => $_SESSION['user'] ?? ''
    ];
    $result = dbRequest('POST', '/pyra_teams', $record, ['Prefer: return=representation']);
    if ($result['httpCode'] === 201) {
        return ['success' => true, 'team' => is_array($result['data']) && count($result['data']) > 0 ? $result['data'][0] : $record];
    }
    return ['success' => false, 'error' => $result['data']['message'] ?? 'Failed to create team'];
}

function getAllTeams(): array {
    $result = dbRequest('GET', '/pyra_teams?order=created_at.asc');
    if ($result['httpCode'] === 200 && is_array($result['data'])) {
        return array_map(function($team) {
            if (is_string($team['permissions'])) {
                $team['permissions'] = json_decode($team['permissions'], true);
            }
            return $team;
        }, $result['data']);
    }
    return [];
}

function getTeam(string $teamId): ?array {
    $result = dbRequest('GET', '/pyra_teams?id=eq.' . rawurlencode($teamId) . '&limit=1');
    if ($result['httpCode'] === 200 && is_array($result['data']) && count($result['data']) > 0) {
        $team = $result['data'][0];
        if (is_string($team['permissions'])) {
            $team['permissions'] = json_decode($team['permissions'], true);
        }
        return $team;
    }
    return null;
}

function updateTeam(string $teamId, array $fields): array {
    $allowed = ['name', 'description', 'permissions'];
    $update = [];
    foreach ($allowed as $key) {
        if (array_key_exists($key, $fields)) {
            $update[$key] = $fields[$key];
        }
    }
    if (empty($update)) {
        return ['success' => false, 'error' => 'No valid fields to update'];
    }
    $result = dbRequest('PATCH', '/pyra_teams?id=eq.' . rawurlencode($teamId), $update);
    if ($result['httpCode'] === 200 || $result['httpCode'] === 204) {
        return ['success' => true];
    }
    return ['success' => false, 'error' => 'Failed to update team'];
}

function deleteTeam(string $teamId): array {
    $result = dbRequest('DELETE', '/pyra_teams?id=eq.' . rawurlencode($teamId));
    if ($result['httpCode'] === 200 || $result['httpCode'] === 204) {
        return ['success' => true];
    }
    return ['success' => false, 'error' => 'Failed to delete team'];
}

function getTeamMembers(string $teamId): array {
    $result = dbRequest('GET', '/pyra_team_members?team_id=eq.' . rawurlencode($teamId) . '&order=added_at.asc');
    if ($result['httpCode'] === 200 && is_array($result['data'])) {
        return $result['data'];
    }
    return [];
}

function addTeamMember(string $teamId, string $username): array {
    $record = [
        'id' => generateTeamMemberId(),
        'team_id' => $teamId,
        'username' => $username,
        'added_by' => $_SESSION['user'] ?? ''
    ];
    $result = dbRequest('POST', '/pyra_team_members', $record, ['Prefer: return=representation']);
    if ($result['httpCode'] === 201) {
        return ['success' => true];
    }
    $err = $result['data']['message'] ?? '';
    if (strpos($err, 'duplicate') !== false || strpos($err, 'unique') !== false || $result['httpCode'] === 409) {
        return ['success' => false, 'error' => 'User already in team'];
    }
    return ['success' => false, 'error' => $err ?: 'Failed to add member'];
}

function removeTeamMember(string $teamId, string $username): array {
    $result = dbRequest('DELETE', '/pyra_team_members?team_id=eq.' . rawurlencode($teamId) . '&username=eq.' . rawurlencode($username));
    if ($result['httpCode'] === 200 || $result['httpCode'] === 204) {
        return ['success' => true];
    }
    return ['success' => false, 'error' => 'Failed to remove member'];
}

function getUserTeams(string $username): array {
    $result = dbRequest('GET', '/pyra_team_members?username=eq.' . rawurlencode($username) . '&select=team_id');
    if ($result['httpCode'] === 200 && is_array($result['data'])) {
        $teamIds = array_column($result['data'], 'team_id');
        if (empty($teamIds)) return [];
        $teams = [];
        foreach ($teamIds as $tid) {
            $team = getTeam($tid);
            if ($team) $teams[] = $team;
        }
        return $teams;
    }
    return [];
}

// === File-Level Permissions ===

function generateFilePermId(): string {
    return 'fp_' . time() . '_' . substr(bin2hex(random_bytes(3)), 0, 5);
}

function setFilePermission(string $filePath, string $targetType, string $targetId, array $permissions, ?string $expiresAt = null): array {
    // Remove existing permission for this target on this path
    dbRequest('DELETE', '/pyra_file_permissions?file_path=eq.' . rawurlencode($filePath) . '&target_type=eq.' . rawurlencode($targetType) . '&target_id=eq.' . rawurlencode($targetId));

    $record = [
        'id' => generateFilePermId(),
        'file_path' => $filePath,
        'target_type' => $targetType,
        'target_id' => $targetId,
        'permissions' => $permissions,
        'expires_at' => $expiresAt,
        'created_by' => $_SESSION['user'] ?? ''
    ];
    $result = dbRequest('POST', '/pyra_file_permissions', $record, ['Prefer: return=representation']);
    if ($result['httpCode'] === 201) {
        return ['success' => true, 'permission' => is_array($result['data']) && count($result['data']) > 0 ? $result['data'][0] : $record];
    }
    return ['success' => false, 'error' => $result['data']['message'] ?? 'Failed to set permission'];
}

function getFilePermissions(string $filePath): array {
    $result = dbRequest('GET', '/pyra_file_permissions?file_path=eq.' . rawurlencode($filePath) . '&order=created_at.desc');
    if ($result['httpCode'] === 200 && is_array($result['data'])) {
        return array_map(function($fp) {
            if (is_string($fp['permissions'])) {
                $fp['permissions'] = json_decode($fp['permissions'], true);
            }
            return $fp;
        }, $result['data']);
    }
    return [];
}

function removeFilePermission(string $permId): array {
    $result = dbRequest('DELETE', '/pyra_file_permissions?id=eq.' . rawurlencode($permId));
    if ($result['httpCode'] === 200 || $result['httpCode'] === 204) {
        return ['success' => true];
    }
    return ['success' => false, 'error' => 'Failed to remove permission'];
}

function getEffectiveFilePermissions(string $filePath, string $username): ?array {
    // 1. Check direct user file permissions
    $now = date('c');
    $result = dbRequest('GET', '/pyra_file_permissions?file_path=eq.' . rawurlencode($filePath) . '&target_type=eq.user&target_id=eq.' . rawurlencode($username));
    if ($result['httpCode'] === 200 && is_array($result['data'])) {
        foreach ($result['data'] as $fp) {
            if (!empty($fp['expires_at']) && $fp['expires_at'] < $now) continue;
            $perms = is_string($fp['permissions']) ? json_decode($fp['permissions'], true) : $fp['permissions'];
            return $perms;
        }
    }

    // 2. Check team-based file permissions
    $userTeams = getUserTeams($username);
    foreach ($userTeams as $team) {
        $result = dbRequest('GET', '/pyra_file_permissions?file_path=eq.' . rawurlencode($filePath) . '&target_type=eq.team&target_id=eq.' . rawurlencode($team['id']));
        if ($result['httpCode'] === 200 && is_array($result['data'])) {
            foreach ($result['data'] as $fp) {
                if (!empty($fp['expires_at']) && $fp['expires_at'] < $now) continue;
                $perms = is_string($fp['permissions']) ? json_decode($fp['permissions'], true) : $fp['permissions'];
                return $perms;
            }
        }
    }

    return null;
}

function cleanExpiredFilePermissions(): int {
    $now = date('c');
    $result = dbRequest('GET', '/pyra_file_permissions?expires_at=lt.' . rawurlencode($now) . '&expires_at=not.is.null&select=id');
    if ($result['httpCode'] === 200 && is_array($result['data'])) {
        $count = count($result['data']);
        if ($count > 0) {
            dbRequest('DELETE', '/pyra_file_permissions?expires_at=lt.' . rawurlencode($now) . '&expires_at=not.is.null');
        }
        return $count;
    }
    return 0;
}

/**
 * Enhanced path access check (browse + navigate).
 * Includes parent-path browse-through for navigation.
 */
function canAccessPathEnhanced(string $path): bool {
    if (isAdmin()) return true;
    if (canAccessPath($path)) return true;

    $username = $_SESSION['user'] ?? '';
    if (!$username) return false;

    // Check team permissions (with browse-through for parent paths)
    $userTeams = getUserTeams($username);
    foreach ($userTeams as $team) {
        $teamPerms = $team['permissions'] ?? [];
        $teamPaths = $teamPerms['allowed_paths'] ?? [];
        if (in_array('*', $teamPaths)) return true;
        $pathNorm = rtrim($path, '/');
        foreach ($teamPaths as $prefix) {
            $prefixNorm = rtrim($prefix, '/');
            if ($pathNorm === $prefixNorm) return true;
            if ($pathNorm !== '' && strpos($pathNorm . '/', $prefixNorm . '/') === 0) return true;
            if ($pathNorm === '' || strpos($prefixNorm . '/', $pathNorm . '/') === 0) return true;
        }
    }

    // Check file-level permissions
    $filePerm = getEffectiveFilePermissions($path, $username);
    if ($filePerm !== null) return true;

    return false;
}

/**
 * STRICT path access check for write operations (upload, edit, delete, create).
 * Does NOT allow parent browse-through. Path must be AT or INSIDE allowed path.
 */
function canWritePath(string $path): bool {
    if (isAdmin()) return true;

    // 1. User direct access
    if (isPathDirectlyAllowed($path)) return true;

    $username = $_SESSION['user'] ?? '';
    if (!$username) return false;

    $pathNorm = rtrim($path, '/');

    // 2. Team direct access (NO parent browse-through)
    $userTeams = getUserTeams($username);
    foreach ($userTeams as $team) {
        $teamPerms = $team['permissions'] ?? [];
        $teamPaths = $teamPerms['allowed_paths'] ?? [];
        if (in_array('*', $teamPaths)) return true;
        foreach ($teamPaths as $prefix) {
            $prefixNorm = rtrim($prefix, '/');
            if ($pathNorm === $prefixNorm) return true;
            if ($pathNorm !== '' && strpos($pathNorm . '/', $prefixNorm . '/') === 0) return true;
        }
    }

    // 3. File-level permissions
    $filePerm = getEffectiveFilePermissions($path, $username);
    if ($filePerm !== null) return true;

    return false;
}

function hasPermissionEnhanced(string $perm, string $filePath = ''): bool {
    if (isAdmin()) return true;

    // 1. Standard user permission
    if (hasPermission($perm)) return true;

    $username = $_SESSION['user'] ?? '';
    if (!$username) return false;

    // 2. Team permissions
    $userTeams = getUserTeams($username);
    foreach ($userTeams as $team) {
        $teamPerms = $team['permissions'] ?? [];
        if (!empty($teamPerms[$perm])) return true;
    }

    // 3. File-level permissions
    if ($filePath) {
        $filePerm = getEffectiveFilePermissions($filePath, $username);
        if ($filePerm && !empty($filePerm[$perm])) return true;
    }

    return false;
}

// === Favorites ===

function generateFavoriteId(): string {
    return 'fav_' . time() . '_' . substr(bin2hex(random_bytes(3)), 0, 5);
}

function addFavoriteItem(string $username, string $filePath, string $itemType = 'file', string $displayName = ''): array {
    $record = [
        'id' => generateFavoriteId(),
        'username' => $username,
        'file_path' => $filePath,
        'item_type' => $itemType,
        'display_name' => $displayName
    ];
    $result = dbRequest('POST', '/pyra_favorites', $record, ['Prefer: return=representation']);
    if ($result['httpCode'] === 201) {
        return ['success' => true];
    }
    $err = is_array($result['data']) ? ($result['data']['message'] ?? '') : '';
    if (strpos($err, 'duplicate') !== false || strpos($err, 'unique') !== false || $result['httpCode'] === 409) {
        return ['success' => false, 'error' => 'Already in favorites'];
    }
    return ['success' => false, 'error' => $err ?: 'Failed to add favorite'];
}

function removeFavoriteItem(string $username, string $filePath): array {
    $result = dbRequest('DELETE', '/pyra_favorites?username=eq.' . rawurlencode($username) . '&file_path=eq.' . rawurlencode($filePath));
    if ($result['httpCode'] === 200 || $result['httpCode'] === 204) {
        return ['success' => true];
    }
    return ['success' => false, 'error' => 'Failed to remove favorite'];
}

function getUserFavorites(string $username): array {
    $result = dbRequest('GET', '/pyra_favorites?username=eq.' . rawurlencode($username) . '&order=created_at.desc');
    if ($result['httpCode'] === 200 && is_array($result['data'])) {
        return $result['data'];
    }
    return [];
}

function getUserFavoritePaths(string $username): array {
    $result = dbRequest('GET', '/pyra_favorites?username=eq.' . rawurlencode($username) . '&select=file_path');
    if ($result['httpCode'] === 200 && is_array($result['data'])) {
        return array_column($result['data'], 'file_path');
    }
    return [];
}

function cleanupDeletedFavorites(string $filePath): void {
    dbRequest('DELETE', '/pyra_favorites?file_path=eq.' . rawurlencode($filePath));
}
