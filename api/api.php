<?php
/**
 * API Handler for Supabase Storage operations
 * With Authentication, RBAC, Reviews, Trash, Activity Log, Notifications, Deep Search, Share Links
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');

function sanitizePath(string $path): string {
    $path = str_replace("\0", '', $path);
    $path = str_replace('\\', '/', $path);
    $parts = explode('/', $path);
    $safe = [];
    foreach ($parts as $part) {
        if ($part === '' || $part === '.' || $part === '..') continue;
        $safe[] = $part;
    }
    return implode('/', $safe);
}

function supabaseRequest(string $method, string $endpoint, ?array $body = null, bool $isRaw = false): array {
    $url = SUPABASE_URL . '/storage/v1' . $endpoint;

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);

    $headers = [
        'Authorization: Bearer ' . SUPABASE_SERVICE_KEY,
    ];

    if (!$isRaw) {
        $headers[] = 'Content-Type: application/json';
    }

    switch (strtoupper($method)) {
        case 'POST':
            curl_setopt($ch, CURLOPT_POST, true);
            if ($body !== null) {
                if ($isRaw) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
                } else {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
                }
            }
            break;
        case 'PUT':
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            if ($body !== null) {
                if ($isRaw) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
                } else {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
                }
            }
            break;
        case 'DELETE':
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
            }
            break;
        case 'MOVE':
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
            }
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

function storageRequest(string $method, string $filePath, $body = null, array $options = []): array {
    $encodedPath = implode('/', array_map('rawurlencode', explode('/', $filePath)));
    $url = SUPABASE_URL . '/storage/v1/object/' . SUPABASE_BUCKET . '/' . $encodedPath;

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $options['timeout'] ?? 120);

    $headers = ['Authorization: Bearer ' . SUPABASE_SERVICE_KEY];
    if (isset($options['contentType'])) {
        $headers[] = 'Content-Type: ' . $options['contentType'];
    }
    if (!empty($options['extraHeaders'])) {
        $headers = array_merge($headers, $options['extraHeaders']);
    }

    switch (strtoupper($method)) {
        case 'POST':
            curl_setopt($ch, CURLOPT_POST, true);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
            break;
        case 'PUT':
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
            break;
        case 'DELETE':
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
            break;
    }

    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $error = curl_error($ch);
    curl_close($ch);

    return [
        'success' => ($httpCode >= 200 && $httpCode < 300),
        'data'    => $response,
        'httpCode' => $httpCode,
        'contentType' => $contentType,
        'error'  => $error ?: null,
    ];
}

function listFiles(string $prefix = ''): array {
    $body = [
        'prefix' => $prefix,
        'limit' => 1000,
        'offset' => 0,
        'sortBy' => ['column' => 'name', 'order' => 'asc']
    ];

    $result = supabaseRequest('POST', '/object/list/' . SUPABASE_BUCKET, $body);

    if ($result['httpCode'] === 200) {
        $items = $result['data'];
        $folders = [];
        $files = [];

        foreach ($items as $item) {
            if ($item['id'] === null && $item['metadata'] === null) {
                $folders[] = [
                    'name' => $item['name'],
                    'type' => 'folder',
                    'path' => $prefix ? $prefix . '/' . $item['name'] : $item['name']
                ];
            } else {
                $files[] = [
                    'name' => $item['name'],
                    'type' => 'file',
                    'path' => $prefix ? $prefix . '/' . $item['name'] : $item['name'],
                    'id' => $item['id'],
                    'size' => $item['metadata']['size'] ?? 0,
                    'mimetype' => $item['metadata']['mimetype'] ?? 'application/octet-stream',
                    'updated_at' => $item['updated_at'] ?? '',
                    'created_at' => $item['created_at'] ?? ''
                ];
            }
        }

        return ['success' => true, 'folders' => $folders, 'files' => $files, 'prefix' => $prefix];
    }

    return ['success' => false, 'error' => $result['data']['message'] ?? 'Failed to list files'];
}

function createFileVersion(string $filePath): bool {
    // Check if file exists in storage
    $result = storageRequest('GET', $filePath, null, ['timeout' => 60]);
    $fileContent = $result['data'];
    $httpCode = $result['httpCode'];
    $contentType = $result['contentType'];

    if ($httpCode !== 200 || empty($fileContent)) return false;

    // Check max versions setting
    $maxVersions = (int)getSetting('max_versions_per_file', '10');
    $existingVersions = getFileVersions($filePath);

    // If at max, delete oldest
    if (count($existingVersions) >= $maxVersions && $maxVersions > 0) {
        $oldest = end($existingVersions);
        if ($oldest) {
            // Delete from storage
            storageRequest('DELETE', $oldest['version_path']);
            deleteFileVersionRecord($oldest['id']);
        }
    }

    // Copy to .versions/
    $timestamp = date('Ymd_His');
    $fileName = basename($filePath);
    $versionPath = '.versions/' . $filePath . '/' . $timestamp . '_' . $fileName;

    $uploadResult = storageRequest('POST', $versionPath, $fileContent, [
        'contentType' => $contentType ?: 'application/octet-stream',
        'extraHeaders' => ['x-upsert: true'],
    ]);
    $vHttpCode = $uploadResult['httpCode'];

    if ($vHttpCode === 200 || $vHttpCode === 201) {
        $versionNum = getNextVersionNumber($filePath);
        createFileVersionRecord([
            'file_path' => $filePath,
            'version_path' => $versionPath,
            'version_number' => $versionNum,
            'file_size' => strlen($fileContent),
            'mime_type' => $contentType ?: 'application/octet-stream'
        ]);
        return true;
    }
    return false;
}

function sanitizeFileName(string $name): string {
    // Replace Arabic/Unicode spaces with underscore, keep extension
    $ext = pathinfo($name, PATHINFO_EXTENSION);
    $base = pathinfo($name, PATHINFO_FILENAME);
    // Transliterate: replace non-ASCII chars with hex-encoded short hash
    if (preg_match('/[^\x20-\x7E]/', $base)) {
        // Keep original name readable: replace non-ASCII with underscore-separated hex
        $safe = preg_replace('/[^\w\-.]/', '_', $base);
        // If entire name became underscores, use a hash of the original
        $safe = trim($safe, '_');
        if (empty($safe) || preg_match('/^_+$/', $safe)) {
            $safe = 'file_' . substr(md5($base), 0, 10);
        }
        // Append short hash of original to ensure uniqueness
        $safe .= '_' . substr(md5($base), 0, 6);
        return $ext ? $safe . '.' . $ext : $safe;
    }
    return $name;
}

function uploadFile(string $prefix, array $file): array {
    // Sanitize filename for Supabase Storage compatibility (no Arabic/Unicode chars in key)
    $originalName = $file['name'];
    $safeName = sanitizeFileName($originalName);
    $filePath = $prefix ? $prefix . '/' . $safeName : $safeName;

    // Auto-versioning: check if file exists and create version before overwrite
    $autoVersion = getSetting('auto_version_on_upload', 'true') === 'true';
    if ($autoVersion) {
        createFileVersion($filePath);
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    $fileContent = file_get_contents($file['tmp_name']);

    $result = storageRequest('POST', $filePath, $fileContent, [
        'timeout' => 300,
        'contentType' => $mimeType,
        'extraHeaders' => ['x-upsert: true', 'Cache-Control: max-age=3600'],
    ]);
    $httpCode = $result['httpCode'];
    $error = $result['error'];
    $response = $result['data'];

    if ($error) {
        return ['success' => false, 'error' => $error];
    }

    if ($httpCode === 200 || $httpCode === 201) {
        // Update file search index
        indexFile([
            'file_path' => $filePath,
            'file_size' => strlen($fileContent),
            'mime_type' => $mimeType,
            'original_name' => ($originalName !== $safeName) ? $originalName : null
        ]);
        return ['success' => true, 'path' => $filePath, 'original_name' => $originalName, 'safe_name' => $safeName];
    }

    $data = json_decode($response, true);
    $errMsg = $data['message'] ?? $data['error'] ?? 'Upload failed (HTTP ' . $httpCode . ')';
    return ['success' => false, 'error' => $errMsg, 'original_name' => $originalName];
}

function deleteFile(string $filePath): array {
    $result = storageRequest('DELETE', $filePath);
    $httpCode = $result['httpCode'];
    $response = $result['data'];

    if ($httpCode === 200) {
        removeFileIndex($filePath);
        return ['success' => true];
    }

    $data = json_decode($response, true);
    return ['success' => false, 'error' => $data['message'] ?? 'Delete failed'];
}

function moveToTrash(string $filePath, int $fileSize = 0, string $mimeType = 'application/octet-stream'): array {
    $fileName = basename($filePath);
    $trashPath = '.trash/' . time() . '_' . bin2hex(random_bytes(3)) . '_' . $fileName;

    $body = [
        'bucketId' => SUPABASE_BUCKET,
        'sourceKey' => $filePath,
        'destinationKey' => $trashPath
    ];
    $result = supabaseRequest('POST', '/object/move', $body);

    if ($result['httpCode'] === 200) {
        addTrashRecord([
            'original_path' => $filePath,
            'trash_path' => $trashPath,
            'file_name' => $fileName,
            'file_size' => $fileSize,
            'mime_type' => $mimeType
        ]);
        removeFileIndex($filePath);
        return ['success' => true];
    }
    return ['success' => false, 'error' => $result['data']['message'] ?? 'Failed to move to trash'];
}

function renameFile(string $oldPath, string $newPath): array {
    $body = [
        'bucketId' => SUPABASE_BUCKET,
        'sourceKey' => $oldPath,
        'destinationKey' => $newPath
    ];

    $result = supabaseRequest('POST', '/object/move', $body);

    if ($result['httpCode'] === 200) {
        updateReviewPaths($oldPath, $newPath);
        updateFileIndexPath($oldPath, $newPath);
        return ['success' => true];
    }

    return ['success' => false, 'error' => $result['data']['message'] ?? 'Rename failed'];
}

function getFileContent(string $filePath): array {
    $result = storageRequest('GET', $filePath);
    $response = $result['data'];
    $httpCode = $result['httpCode'];
    $contentType = $result['contentType'];

    if ($httpCode === 200) {
        return ['success' => true, 'content' => $response, 'contentType' => $contentType];
    }

    return ['success' => false, 'error' => 'Failed to get file content'];
}

function saveFileContent(string $filePath, string $content, string $mimeType = 'text/plain'): array {
    $result = storageRequest('PUT', $filePath, $content, [
        'contentType' => $mimeType,
        'extraHeaders' => ['x-upsert: true', 'Cache-Control: max-age=3600'],
    ]);
    $httpCode = $result['httpCode'];
    $response = $result['data'];

    if ($httpCode === 200 || $httpCode === 201) {
        return ['success' => true];
    }

    $data = json_decode($response, true);
    return ['success' => false, 'error' => $data['message'] ?? 'Save failed (HTTP ' . $httpCode . ')'];
}

function createFolder(string $prefix, string $folderName): array {
    $path = $prefix ? $prefix . '/' . $folderName . '/.keep' : $folderName . '/.keep';
    $result = storageRequest('POST', $path, '', [
        'contentType' => 'text/plain',
        'extraHeaders' => ['x-upsert: true'],
    ]);
    $httpCode = $result['httpCode'];
    $response = $result['data'];

    if ($httpCode === 200 || $httpCode === 201) {
        return ['success' => true];
    }

    $data = json_decode($response, true);
    return ['success' => false, 'error' => $data['message'] ?? 'Create folder failed'];
}

function getPublicUrl(string $filePath): string {
    return SUPABASE_URL . '/storage/v1/object/public/' . SUPABASE_BUCKET . '/' . $filePath;
}

function getSignedUrl(string $filePath): string {
    $body = [
        'expiresIn' => 3600
    ];
    $result = supabaseRequest('POST', '/object/sign/' . SUPABASE_BUCKET . '/' . $filePath, $body);
    if ($result['httpCode'] === 200 && isset($result['data']['signedURL'])) {
        return SUPABASE_URL . '/storage/v1' . $result['data']['signedURL'];
    }
    return getPublicUrl($filePath);
}

function recursiveListFiles(string $prefix = '', int $maxDepth = 10, int $currentDepth = 0): array {
    if ($currentDepth >= $maxDepth) return [];

    $allFiles = [];
    $body = [
        'prefix' => $prefix,
        'limit' => 1000,
        'offset' => 0,
        'sortBy' => ['column' => 'name', 'order' => 'asc']
    ];

    $result = supabaseRequest('POST', '/object/list/' . SUPABASE_BUCKET, $body);

    if ($result['httpCode'] === 200 && is_array($result['data'])) {
        foreach ($result['data'] as $item) {
            $itemPath = $prefix ? $prefix . '/' . $item['name'] : $item['name'];

            if ($item['name'] === '.trash' || $item['name'] === '.keep') continue;

            if ($item['id'] === null && $item['metadata'] === null) {
                $subFiles = recursiveListFiles($itemPath, $maxDepth, $currentDepth + 1);
                $allFiles = array_merge($allFiles, $subFiles);
            } else {
                $allFiles[] = [
                    'name' => $item['name'],
                    'path' => $itemPath,
                    'size' => $item['metadata']['size'] ?? 0,
                    'mimetype' => $item['metadata']['mimetype'] ?? 'application/octet-stream',
                    'updated_at' => $item['updated_at'] ?? '',
                    'created_at' => $item['created_at'] ?? ''
                ];
            }
        }
    }

    return $allFiles;
}

// Route API actions
$rawBody = file_get_contents('php://input');
$jsonBody = json_decode($rawBody, true);
if (!is_array($jsonBody)) $jsonBody = [];

$action = $_GET['action'] ?? $_POST['action'] ?? ($jsonBody['action'] ?? '');

// CSRF protection for state-changing requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $action !== 'login' && $action !== 'getPublicSettings') {
    if (isLoggedIn()) {
        $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $jsonBody['_csrf'] ?? '';
        if (!validateCsrfToken($csrfToken)) {
            echo json_encode(['success' => false, 'error' => 'Invalid security token. Please refresh the page.']);
            exit;
        }
    }
}

// Track session activity
if (isLoggedIn()) {
    trackSession();
}

// Actions that don't require authentication
$publicActions = ['login', 'logout', 'session', 'shareAccess', 'getPublicSettings'];

if (!in_array($action, $publicActions)) {
    requireAuth();
}

switch ($action) {

    // === Authentication ===

    case 'login':
        $input = $jsonBody;
        $username = trim($input['username'] ?? '');
        $password = $input['password'] ?? '';
        if (!$username || !$password) {
            echo json_encode(['success' => false, 'error' => 'Username and password required']);
            break;
        }
        usleep(200000);
        $result = attemptLogin($username, $password);
        if ($result['success']) {
            logActivity('login', '', ['username' => $username]);
        }
        echo json_encode($result);
        break;

    case 'logout':
        logActivity('logout');
        logout();
        echo json_encode(['success' => true]);
        break;

    case 'session':
        if (isLoggedIn()) {
            echo json_encode(['success' => true, 'authenticated' => true, 'user' => sessionUserInfo()]);
        } else {
            echo json_encode(['success' => true, 'authenticated' => false]);
        }
        break;

    // === File Operations ===

    case 'list':
        $prefix = sanitizePath($_GET['prefix'] ?? '');
        if (!canAccessPathEnhanced($prefix)) {
            echo json_encode(['success' => false, 'error' => 'Access denied']);
            break;
        }
        $result = listFiles($prefix);
        if ($result['success']) {
            // Hide .trash and .versions folders from normal listing
            $result['folders'] = array_values(array_filter($result['folders'], function($f) {
                return $f['name'] !== '.trash' && $f['name'] !== '.versions';
            }));
            $result['files'] = array_values(array_filter($result['files'], function($f) {
                return strpos($f['path'], '.trash/') !== 0 && strpos($f['path'], '.versions/') !== 0;
            }));
            // Filter for non-admin users
            if (!isAdmin()) {
                $result['folders'] = array_values(array_filter($result['folders'], function($f) {
                    return canAccessPathEnhanced($f['path']);
                }));
                $result['files'] = array_values(array_filter($result['files'], function($f) {
                    return isPathDirectlyAllowed($f['path']);
                }));
            }
            // Enrich files with original names from index
            if (!empty($result['files'])) {
                $paths = array_map(function($f) { return $f['path']; }, $result['files']);
                $originalNames = getOriginalNames($paths);
                foreach ($result['files'] as &$f) {
                    if (isset($originalNames[$f['path']])) {
                        $f['display_name'] = $originalNames[$f['path']];
                    }
                }
                unset($f);
            }
            // Enrich with favorite state
            $favPaths = getUserFavoritePaths($_SESSION['user']);
            foreach ($result['files'] as &$f) {
                $f['is_favorite'] = in_array($f['path'], $favPaths);
            }
            unset($f);
            foreach ($result['folders'] as &$f) {
                $f['is_favorite'] = in_array($f['path'], $favPaths);
            }
            unset($f);
        }
        echo json_encode($result);
        break;

    case 'upload':
        if (!isset($_FILES['file'])) {
            echo json_encode(['success' => false, 'error' => 'No file provided']);
            break;
        }
        $prefix = sanitizePath($_POST['prefix'] ?? '');
        if (!canWritePath($prefix)) {
            echo json_encode(['success' => false, 'error' => 'Access denied to this path']);
            break;
        }
        if (!hasPathPermission('can_upload', $prefix)) {
            echo json_encode(['success' => false, 'error' => 'Upload not permitted for this folder']);
            break;
        }

        if (is_array($_FILES['file']['name'])) {
            $results = [];
            for ($i = 0; $i < count($_FILES['file']['name']); $i++) {
                $file = [
                    'name' => $_FILES['file']['name'][$i],
                    'tmp_name' => $_FILES['file']['tmp_name'][$i],
                    'type' => $_FILES['file']['type'][$i],
                    'size' => $_FILES['file']['size'][$i]
                ];
                $uploadResult = uploadFile($prefix, $file);
                if ($uploadResult['success']) {
                    logActivity('upload', $uploadResult['path'], ['file_name' => $file['name'], 'size' => $file['size']]);
                    // Notify users with folder access
                    $usersWithAccess = findUsersWithPathAccess($prefix);
                    foreach ($usersWithAccess as $recipient) {
                        createNotification($recipient, 'upload', 'New file uploaded: ' . $file['name'], '', $uploadResult['path']);
                    }
                }
                $results[] = $uploadResult;
            }
            echo json_encode(['success' => true, 'results' => $results]);
        } else {
            $uploadResult = uploadFile($prefix, $_FILES['file']);
            if ($uploadResult['success']) {
                logActivity('upload', $uploadResult['path'], ['file_name' => $_FILES['file']['name'], 'size' => $_FILES['file']['size']]);
                $usersWithAccess = findUsersWithPathAccess($prefix);
                foreach ($usersWithAccess as $recipient) {
                    createNotification($recipient, 'upload', 'New file uploaded: ' . $_FILES['file']['name'], '', $uploadResult['path']);
                }
            }
            echo json_encode($uploadResult);
        }
        break;

    case 'delete':
        $path = sanitizePath($_POST['path'] ?? '');
        $fileSize = (int)($_POST['fileSize'] ?? 0);
        $mimeType = $_POST['mimeType'] ?? 'application/octet-stream';
        if (!$path) {
            echo json_encode(['success' => false, 'error' => 'No path provided']);
            break;
        }
        if (!canWritePath($path)) {
            echo json_encode(['success' => false, 'error' => 'Access denied']);
            break;
        }
        if (!hasPathPermission('can_delete', $path)) {
            echo json_encode(['success' => false, 'error' => 'Delete not permitted for this folder']);
            break;
        }
        $result = moveToTrash($path, $fileSize, $mimeType);
        if ($result['success']) {
            logActivity('delete', $path, ['moved_to_trash' => true]);
            cleanupDeletedFavorites($path);
        }
        echo json_encode($result);
        break;

    case 'rename':
        $oldPath = sanitizePath($_POST['oldPath'] ?? '');
        $newPath = sanitizePath($_POST['newPath'] ?? '');
        if (!$oldPath || !$newPath) {
            echo json_encode(['success' => false, 'error' => 'Paths required']);
            break;
        }
        if (!canWritePath($oldPath) || !canWritePath($newPath)) {
            echo json_encode(['success' => false, 'error' => 'Access denied']);
            break;
        }
        if (!hasPathPermission('can_edit', $oldPath)) {
            echo json_encode(['success' => false, 'error' => 'Edit not permitted for this folder']);
            break;
        }
        $result = renameFile($oldPath, $newPath);
        if ($result['success']) {
            logActivity('rename', $oldPath, ['new_path' => $newPath]);
            // Update favorite paths
            dbRequest('PATCH', '/pyra_favorites?file_path=eq.' . rawurlencode($oldPath), ['file_path' => $newPath]);
        }
        echo json_encode($result);
        break;

    case 'content':
        $path = sanitizePath($_GET['path'] ?? '');
        if (!$path) {
            echo json_encode(['success' => false, 'error' => 'No path provided']);
            break;
        }
        if (!canAccessPathEnhanced($path)) {
            echo json_encode(['success' => false, 'error' => 'Access denied']);
            break;
        }
        $result = getFileContent($path);
        if ($result['success']) {
            echo json_encode(['success' => true, 'content' => $result['content'], 'contentType' => $result['contentType']]);
        } else {
            echo json_encode($result);
        }
        break;

    case 'save':
        $path = sanitizePath($_POST['path'] ?? '');
        $content = $_POST['content'] ?? '';
        $mimeType = $_POST['mimeType'] ?? 'text/plain';
        if (!$path) {
            echo json_encode(['success' => false, 'error' => 'No path provided']);
            break;
        }
        if (!canWritePath($path)) {
            echo json_encode(['success' => false, 'error' => 'Access denied']);
            break;
        }
        if (!hasPathPermission('can_edit', $path)) {
            echo json_encode(['success' => false, 'error' => 'Edit not permitted for this folder']);
            break;
        }
        createFileVersion($path);
        $result = saveFileContent($path, $content, $mimeType);
        if ($result['success']) {
            logActivity('save_file', $path);
        }
        echo json_encode($result);
        break;

    case 'createFolder':
        $prefix = sanitizePath($_POST['prefix'] ?? '');
        $folderName = sanitizePath($_POST['folderName'] ?? '');
        if (!$folderName) {
            echo json_encode(['success' => false, 'error' => 'Folder name required']);
            break;
        }
        if (!canWritePath($prefix)) {
            echo json_encode(['success' => false, 'error' => 'Access denied']);
            break;
        }
        if (!hasPathPermission('can_create_folder', $prefix)) {
            echo json_encode(['success' => false, 'error' => 'Folder creation not permitted for this path']);
            break;
        }
        $result = createFolder($prefix, $folderName);
        if ($result['success']) {
            logActivity('create_folder', $prefix ? $prefix . '/' . $folderName : $folderName);
        }
        echo json_encode($result);
        break;

    case 'proxy':
        $path = sanitizePath($_GET['path'] ?? '');
        if (!$path) {
            http_response_code(400);
            echo 'No path provided';
            break;
        }
        if (!canAccessPathEnhanced($path)) {
            http_response_code(403);
            echo 'Access denied';
            exit;
        }
        $result = getFileContent($path);
        if ($result['success']) {
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $mimeMap = [
                'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'doc' => 'application/msword',
                'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            ];
            $mime = $mimeMap[$ext] ?? ($result['contentType'] ?? 'application/octet-stream');
            header_remove('Content-Type');
            header('Content-Type: ' . $mime);
            header('Content-Length: ' . strlen($result['content']));
            echo $result['content'];
        } else {
            http_response_code(404);
            echo 'File not found';
        }
        exit;

    case 'download':
        $path = sanitizePath($_GET['path'] ?? '');
        if (!$path) {
            http_response_code(400);
            echo 'No path provided';
            break;
        }
        if (!canAccessPathEnhanced($path)) {
            http_response_code(403);
            echo 'Access denied';
            exit;
        }
        if (!hasPathPermission('can_download', $path)) {
            http_response_code(403);
            echo 'Download not permitted for this folder';
            exit;
        }
        $result = getFileContent($path);
        if ($result['success']) {
            $filename = basename($path);
            $safeName = preg_replace('/[^\w\-. ]/', '_', $filename);
            header_remove('Content-Type');
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . $safeName . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
            header('Content-Length: ' . strlen($result['content']));
            echo $result['content'];
        } else {
            http_response_code(404);
            echo 'File not found';
        }
        exit;

    case 'publicUrl':
        $path = sanitizePath($_GET['path'] ?? '');
        if (!canAccessPathEnhanced($path)) {
            echo json_encode(['success' => false, 'error' => 'Access denied']);
            break;
        }
        echo json_encode(['success' => true, 'url' => getPublicUrl($path)]);
        break;

    case 'deleteBatch':
        $paths = json_decode($_POST['paths'] ?? '[]', true);
        if (!is_array($paths) || count($paths) === 0) {
            echo json_encode(['success' => false, 'error' => 'No paths provided']);
            break;
        }
        $results = [];
        foreach ($paths as $p) {
            $safePath = sanitizePath($p);
            if ($safePath && canWritePath($safePath) && hasPathPermission('can_delete', $safePath)) {
                $trashResult = moveToTrash($safePath);
                if ($trashResult['success']) {
                    logActivity('delete', $safePath, ['moved_to_trash' => true, 'batch' => true]);
                }
                $results[] = array_merge($trashResult, ['path' => $safePath]);
            }
        }
        echo json_encode(['success' => true, 'results' => $results]);
        break;

    // === Reviews ===

    case 'getReviews':
        $path = sanitizePath($_GET['path'] ?? '');
        if (!$path || !canAccessPathEnhanced($path)) {
            echo json_encode(['success' => false, 'error' => 'Access denied']);
            break;
        }
        $reviews = getFileReviews($path);
        echo json_encode(['success' => true, 'reviews' => $reviews]);
        break;

    case 'addReview':
        $input = $jsonBody;
        $path = sanitizePath($input['path'] ?? '');
        $type = $input['type'] ?? 'comment';
        $text = trim($input['text'] ?? '');
        $parentId = $input['parent_id'] ?? null;

        if (!$path || !canAccessPathEnhanced($path)) {
            echo json_encode(['success' => false, 'error' => 'Access denied']);
            break;
        }
        if (!hasPathPermission('can_review', $path) && !isAdmin()) {
            echo json_encode(['success' => false, 'error' => 'Review not permitted for this folder']);
            break;
        }
        if ($type === 'comment' && $text === '') {
            echo json_encode(['success' => false, 'error' => 'Comment text required']);
            break;
        }
        if (!in_array($type, ['comment', 'approval'])) {
            echo json_encode(['success' => false, 'error' => 'Invalid review type']);
            break;
        }

        $result = addReview(['file_path' => $path, 'type' => $type, 'text' => $text, 'parent_id' => $parentId]);
        if ($result['success']) {
            logActivity('review_added', $path, ['type' => $type]);
            $fileName = basename($path);

            if ($parentId) {
                // Get parent review to find author
                $parentReview = dbRequest('GET', '/pyra_reviews?id=eq.' . rawurlencode($parentId) . '&limit=1');
                if ($parentReview['httpCode'] === 200 && !empty($parentReview['data'])) {
                    $parentAuthor = $parentReview['data'][0]['username'];
                    $displayName = $_SESSION['display_name'] ?? $_SESSION['user'];
                    createNotification($parentAuthor, 'reply', $displayName . ' replied to your comment on ' . $fileName, $text, $path);
                }
            }

            // Parse @mentions and send dedicated notifications
            $mentionedUsers = parseMentions($text);
            $alreadyNotified = [];
            if (!empty($mentionedUsers)) {
                $mDisplayName = $_SESSION['display_name'] ?? $_SESSION['user'];
                foreach ($mentionedUsers as $mu) {
                    createNotification($mu, 'mention', $mDisplayName . ' mentioned you in a comment on ' . $fileName, $text, $path);
                    $alreadyNotified[] = $mu;
                }
            }
            // Track parent reply author to avoid duplicate
            if ($parentId && isset($parentReview) && !empty($parentReview['data'])) {
                $alreadyNotified[] = $parentReview['data'][0]['username'];
            }

            $notifTitle = $type === 'approval' ? 'File approved: ' . $fileName : 'New comment on ' . $fileName;
            $usersWithAccess = findUsersWithPathAccess(dirname($path));
            foreach ($usersWithAccess as $recipient) {
                if (!in_array($recipient, $alreadyNotified)) {
                    createNotification($recipient, $type, $notifTitle, $text, $path);
                }
            }
        }
        echo json_encode($result);
        break;

    case 'resolveReview':
        if (!isAdmin()) {
            echo json_encode(['success' => false, 'error' => 'Admin only']);
            break;
        }
        $input = $jsonBody;
        $reviewId = $input['id'] ?? '';
        if (!$reviewId) {
            echo json_encode(['success' => false, 'error' => 'Review ID required']);
            break;
        }
        echo json_encode(toggleResolveReview($reviewId));
        break;

    case 'deleteReview':
        if (!isAdmin()) {
            echo json_encode(['success' => false, 'error' => 'Admin only']);
            break;
        }
        $input = $jsonBody;
        $reviewId = $input['id'] ?? '';
        if (!$reviewId) {
            echo json_encode(['success' => false, 'error' => 'Review ID required']);
            break;
        }
        $result = deleteReview($reviewId);
        if ($result['success']) {
            logActivity('review_deleted', '', ['review_id' => $reviewId]);
        }
        echo json_encode($result);
        break;

    // === Trash Management (Admin only) ===

    case 'listTrash':
        if (!isAdmin()) {
            echo json_encode(['success' => false, 'error' => 'Admin only']);
            break;
        }
        echo json_encode(['success' => true, 'items' => getTrashItems()]);
        break;

    case 'restoreTrash':
        if (!isAdmin()) {
            echo json_encode(['success' => false, 'error' => 'Admin only']);
            break;
        }
        $input = $jsonBody;
        $trashId = $input['id'] ?? '';
        if (!$trashId) {
            echo json_encode(['success' => false, 'error' => 'Trash item ID required']);
            break;
        }
        $trashRecord = getTrashRecord($trashId);
        if (!$trashRecord) {
            echo json_encode(['success' => false, 'error' => 'Trash item not found']);
            break;
        }
        $moveResult = supabaseRequest('POST', '/object/move', [
            'bucketId' => SUPABASE_BUCKET,
            'sourceKey' => $trashRecord['trash_path'],
            'destinationKey' => $trashRecord['original_path']
        ]);
        if ($moveResult['httpCode'] === 200) {
            deleteTrashRecord($trashId);
            indexFile(['file_path' => $trashRecord['original_path'], 'file_size' => 0, 'mime_type' => '']);
            logActivity('trash_restore', $trashRecord['original_path'], ['file_name' => $trashRecord['file_name']]);
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => $moveResult['data']['message'] ?? 'Failed to restore file']);
        }
        break;

    case 'permanentDelete':
        if (!isAdmin()) {
            echo json_encode(['success' => false, 'error' => 'Admin only']);
            break;
        }
        $input = $jsonBody;
        $trashId = $input['id'] ?? '';
        if (!$trashId) {
            echo json_encode(['success' => false, 'error' => 'Trash item ID required']);
            break;
        }
        $trashRecord = getTrashRecord($trashId);
        if (!$trashRecord) {
            echo json_encode(['success' => false, 'error' => 'Trash item not found']);
            break;
        }
        deleteFile($trashRecord['trash_path']);
        deleteTrashRecord($trashId);
        logActivity('trash_purge', $trashRecord['original_path'], ['file_name' => $trashRecord['file_name'], 'permanent' => true]);
        echo json_encode(['success' => true]);
        break;

    case 'emptyTrash':
        if (!isAdmin()) {
            echo json_encode(['success' => false, 'error' => 'Admin only']);
            break;
        }
        $items = getTrashItems();
        $deleted = 0;
        foreach ($items as $item) {
            deleteFile($item['trash_path']);
            deleteTrashRecord($item['id']);
            $deleted++;
        }
        logActivity('trash_purge', '', ['count' => $deleted, 'empty_all' => true]);
        echo json_encode(['success' => true, 'deleted' => $deleted]);
        break;

    case 'purgeExpired':
        if (!isAdmin()) {
            echo json_encode(['success' => false, 'error' => 'Admin only']);
            break;
        }
        $expired = getExpiredTrashItems();
        $purged = 0;
        foreach ($expired as $item) {
            deleteFile($item['trash_path']);
            deleteTrashRecord($item['id']);
            $purged++;
        }
        logActivity('trash_purge', '', ['count' => $purged, 'expired' => true]);
        echo json_encode(['success' => true, 'purged' => $purged]);
        break;

    // === Activity Log (Admin only) ===

    case 'getActivityLog':
        if (!isAdmin()) {
            echo json_encode(['success' => false, 'error' => 'Admin only']);
            break;
        }
        $limit = min((int)($_GET['limit'] ?? 100), 500);
        $offset = (int)($_GET['offset'] ?? 0);
        $filterUser = $_GET['user'] ?? null;
        $filterAction = $_GET['actionType'] ?? null;
        $filterDateFrom = $_GET['dateFrom'] ?? null;
        $filterDateTo = $_GET['dateTo'] ?? null;
        $logs = getActivityLog($limit, $offset, $filterUser, $filterAction, $filterDateFrom, $filterDateTo);
        echo json_encode(['success' => true, 'logs' => $logs]);
        break;

    // === Notifications ===

    case 'getNotifications':
        $limit = min((int)($_GET['limit'] ?? 50), 200);
        $unreadOnly = ($_GET['unreadOnly'] ?? 'false') === 'true';
        $notifs = getNotifications($_SESSION['user'], $limit, $unreadOnly);
        echo json_encode(['success' => true, 'notifications' => $notifs]);
        break;

    case 'getUnreadCount':
        $count = getUnreadNotificationCount($_SESSION['user']);
        echo json_encode(['success' => true, 'count' => $count]);
        break;

    case 'markNotifRead':
        $input = $jsonBody;
        $notifId = $input['id'] ?? '';
        if (!$notifId) {
            echo json_encode(['success' => false, 'error' => 'Notification ID required']);
            break;
        }
        echo json_encode(markNotificationRead($notifId));
        break;

    case 'markAllNotifsRead':
        echo json_encode(markAllNotificationsRead($_SESSION['user']));
        break;

    // === Deep Search ===

    case 'deepSearch':
        $query = trim($_GET['query'] ?? '');
        if (strlen($query) < 2) {
            echo json_encode(['success' => false, 'error' => 'Search query must be at least 2 characters']);
            break;
        }

        // Use file index for fast search
        $allowedPaths = null;
        if (!isAdmin()) {
            $perms = $_SESSION['permissions'] ?? [];
            $allowedPaths = $perms['allowed_paths'] ?? [];
        }

        $matched = searchFileIndex($query, $allowedPaths);

        // Fallback to recursive if index is empty (not yet built)
        if (empty($matched)) {
            $allFiles = recursiveListFiles('');
            $queryLower = strtolower($query);
            $matched = array_filter($allFiles, function($file) use ($queryLower) {
                return strpos(strtolower($file['name']), $queryLower) !== false
                    || strpos(strtolower($file['path']), $queryLower) !== false;
            });
            if (!isAdmin()) {
                $matched = array_filter($matched, function($file) {
                    return isPathDirectlyAllowed($file['path']);
                });
            }
            $matched = array_values($matched);
            $matched = array_slice($matched, 0, 200);
        }

        echo json_encode(['success' => true, 'results' => $matched, 'total' => count($matched)]);
        break;

    // === Share Links ===

    case 'createShareLink':
        $input = $jsonBody;
        $path = sanitizePath($input['path'] ?? '');
        $fileName = $input['fileName'] ?? basename($path);
        $expiresInHours = (int)($input['expiresInHours'] ?? 24);
        $maxAccess = (int)($input['maxAccess'] ?? 0);
        if (!$path) {
            echo json_encode(['success' => false, 'error' => 'Path required']);
            break;
        }
        if (!canWritePath($path)) {
            echo json_encode(['success' => false, 'error' => 'Access denied']);
            break;
        }
        if (!isAdmin() && !hasPathPermission('can_download', $path)) {
            echo json_encode(['success' => false, 'error' => 'Not permitted for this folder']);
            break;
        }
        if ($expiresInHours < 1 || $expiresInHours > 720) {
            echo json_encode(['success' => false, 'error' => 'Expiry must be 1-720 hours']);
            break;
        }
        $result = createShareLink($path, $fileName, $expiresInHours, $maxAccess);
        if ($result['success']) {
            logActivity('share_created', $path, ['expires_in_hours' => $expiresInHours]);
        }
        echo json_encode($result);
        break;

    case 'getShareLinks':
        $path = sanitizePath($_GET['path'] ?? '');
        if (!$path || !canAccessPathEnhanced($path)) {
            echo json_encode(['success' => false, 'error' => 'Access denied']);
            break;
        }
        echo json_encode(['success' => true, 'links' => getShareLinksForFile($path)]);
        break;

    case 'deactivateShareLink':
        $input = $jsonBody;
        $shareId = $input['id'] ?? '';
        if (!$shareId) {
            echo json_encode(['success' => false, 'error' => 'Share link ID required']);
            break;
        }
        if (!isAdmin()) {
            $linkResult = dbRequest('GET', '/pyra_share_links?id=eq.' . rawurlencode($shareId) . '&limit=1');
            if (!$linkResult || $linkResult['httpCode'] !== 200 || empty($linkResult['data']) || $linkResult['data'][0]['created_by'] !== $_SESSION['user']) {
                echo json_encode(['success' => false, 'error' => 'Not permitted']);
                break;
            }
        }
        echo json_encode(deactivateShareLink($shareId));
        break;

    case 'shareAccess':
        $token = $_GET['token'] ?? '';
        if (!$token) {
            http_response_code(400);
            header_remove('Content-Type');
            header('Content-Type: text/html; charset=utf-8');
            echo '<h2>Invalid share link</h2>';
            exit;
        }
        $shareLink = getShareLinkByToken($token);
        if (!$shareLink) {
            http_response_code(404);
            header_remove('Content-Type');
            header('Content-Type: text/html; charset=utf-8');
            echo '<h2>Share link not found</h2>';
            exit;
        }
        if (!$shareLink['is_active']) {
            http_response_code(410);
            header_remove('Content-Type');
            header('Content-Type: text/html; charset=utf-8');
            echo '<h2>This share link has been deactivated</h2>';
            exit;
        }
        $expiresAt = new DateTime($shareLink['expires_at']);
        if ($expiresAt < new DateTime()) {
            http_response_code(410);
            header_remove('Content-Type');
            header('Content-Type: text/html; charset=utf-8');
            echo '<h2>This share link has expired</h2>';
            exit;
        }
        if ($shareLink['max_access'] > 0 && $shareLink['access_count'] >= $shareLink['max_access']) {
            http_response_code(410);
            header_remove('Content-Type');
            header('Content-Type: text/html; charset=utf-8');
            echo '<h2>This share link has reached its access limit</h2>';
            exit;
        }

        $result = getFileContent($shareLink['file_path']);
        if ($result['success']) {
            incrementShareAccess($shareLink['id']);
            $filename = $shareLink['file_name'];
            $safeName = preg_replace('/[^\w\-. ]/', '_', $filename);
            header_remove('Content-Type');
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . $safeName . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
            header('Content-Length: ' . strlen($result['content']));
            echo $result['content'];
        } else {
            http_response_code(404);
            header_remove('Content-Type');
            header('Content-Type: text/html; charset=utf-8');
            echo '<h2>File not found</h2>';
        }
        exit;

    // === User Management (Admin only) ===

    case 'getUsers':
        if (!isAdmin()) {
            echo json_encode(['success' => false, 'error' => 'Admin only']);
            break;
        }
        echo json_encode(['success' => true, 'users' => getAllUsers()]);
        break;

    case 'getUsersLite':
        echo json_encode(['success' => true, 'users' => getAllUsersLite()]);
        break;

    case 'addUser':
        if (!isAdmin()) {
            echo json_encode(['success' => false, 'error' => 'Admin only']);
            break;
        }
        $input = $jsonBody;
        $username = trim($input['username'] ?? '');
        $password = $input['password'] ?? '';
        $role = $input['role'] ?? 'client';
        $displayName = trim($input['display_name'] ?? '');
        $permissions = $input['permissions'] ?? [];

        if (!$username || !$password || !$displayName) {
            echo json_encode(['success' => false, 'error' => 'Username, password, and display name required']);
            break;
        }
        if (!in_array($role, ['admin', 'employee', 'client'])) {
            echo json_encode(['success' => false, 'error' => 'Invalid role']);
            break;
        }
        $result = createUser($username, $password, $role, $displayName, $permissions);
        if ($result['success']) {
            logActivity('user_created', '', ['target_user' => $username, 'role' => $role]);
        }
        echo json_encode($result);
        break;

    case 'updateUser':
        if (!isAdmin()) {
            echo json_encode(['success' => false, 'error' => 'Admin only']);
            break;
        }
        $input = $jsonBody;
        $username = trim($input['username'] ?? '');
        if (!$username) {
            echo json_encode(['success' => false, 'error' => 'Username required']);
            break;
        }
        $result = updateUser($username, $input);
        if ($result['success']) {
            logActivity('user_updated', '', ['target_user' => $username]);
        }
        echo json_encode($result);
        break;

    case 'deleteUser':
        if (!isAdmin()) {
            echo json_encode(['success' => false, 'error' => 'Admin only']);
            break;
        }
        $input = $jsonBody;
        $username = trim($input['username'] ?? '');
        if (!$username) {
            echo json_encode(['success' => false, 'error' => 'Username required']);
            break;
        }
        $result = deleteUser($username);
        if ($result['success']) {
            logActivity('user_deleted', '', ['target_user' => $username]);
        }
        echo json_encode($result);
        break;

    case 'changePassword':
        if (!isAdmin()) {
            echo json_encode(['success' => false, 'error' => 'Admin only']);
            break;
        }
        $input = $jsonBody;
        $username = trim($input['username'] ?? '');
        $newPassword = $input['password'] ?? '';
        if (!$username || !$newPassword) {
            echo json_encode(['success' => false, 'error' => 'Username and password required']);
            break;
        }
        $result = changeUserPassword($username, $newPassword);
        if ($result['success']) {
            logActivity('password_changed', '', ['target_user' => $username]);
        }
        echo json_encode($result);
        break;

    // === Teams / Groups (Admin only) ===

    case 'getTeams':
        if (!isAdmin()) {
            echo json_encode(['success' => false, 'error' => 'Admin only']);
            break;
        }
        $teams = getAllTeams();
        // Attach member counts
        foreach ($teams as &$t) {
            $members = getTeamMembers($t['id']);
            $t['member_count'] = count($members);
            $t['members'] = $members;
        }
        echo json_encode(['success' => true, 'teams' => $teams]);
        break;

    case 'createTeam':
        if (!isAdmin()) {
            echo json_encode(['success' => false, 'error' => 'Admin only']);
            break;
        }
        $input = $jsonBody;
        $name = trim($input['name'] ?? '');
        $description = trim($input['description'] ?? '');
        $permissions = $input['permissions'] ?? [];

        if (!$name) {
            echo json_encode(['success' => false, 'error' => 'Team name is required']);
            break;
        }
        $result = createTeam($name, $description, $permissions);
        if ($result['success']) {
            logActivity('team_created', '', ['team_name' => $name]);
        }
        echo json_encode($result);
        break;

    case 'updateTeam':
        if (!isAdmin()) {
            echo json_encode(['success' => false, 'error' => 'Admin only']);
            break;
        }
        $input = $jsonBody;
        $teamId = trim($input['team_id'] ?? '');
        if (!$teamId) {
            echo json_encode(['success' => false, 'error' => 'Team ID required']);
            break;
        }
        $result = updateTeam($teamId, $input);
        if ($result['success']) {
            logActivity('team_updated', '', ['team_id' => $teamId]);
        }
        echo json_encode($result);
        break;

    case 'deleteTeam':
        if (!isAdmin()) {
            echo json_encode(['success' => false, 'error' => 'Admin only']);
            break;
        }
        $input = $jsonBody;
        $teamId = trim($input['team_id'] ?? '');
        if (!$teamId) {
            echo json_encode(['success' => false, 'error' => 'Team ID required']);
            break;
        }
        $result = deleteTeam($teamId);
        if ($result['success']) {
            logActivity('team_deleted', '', ['team_id' => $teamId]);
        }
        echo json_encode($result);
        break;

    case 'addTeamMember':
        if (!isAdmin()) {
            echo json_encode(['success' => false, 'error' => 'Admin only']);
            break;
        }
        $input = $jsonBody;
        $teamId = trim($input['team_id'] ?? '');
        $username = trim($input['username'] ?? '');
        if (!$teamId || !$username) {
            echo json_encode(['success' => false, 'error' => 'Team ID and username required']);
            break;
        }
        // Verify user exists
        $userCheck = findUser($username);
        if (!$userCheck) {
            echo json_encode(['success' => false, 'error' => 'User not found']);
            break;
        }
        $result = addTeamMember($teamId, $username);
        if ($result['success']) {
            logActivity('team_member_added', '', ['team_id' => $teamId, 'member' => $username]);
            $team = getTeam($teamId);
            createNotification($username, 'team', 'Added to Team', 'You were added to team "' . ($team['name'] ?? '') . '"');
        }
        echo json_encode($result);
        break;

    case 'removeTeamMember':
        if (!isAdmin()) {
            echo json_encode(['success' => false, 'error' => 'Admin only']);
            break;
        }
        $input = $jsonBody;
        $teamId = trim($input['team_id'] ?? '');
        $username = trim($input['username'] ?? '');
        if (!$teamId || !$username) {
            echo json_encode(['success' => false, 'error' => 'Team ID and username required']);
            break;
        }
        $result = removeTeamMember($teamId, $username);
        if ($result['success']) {
            logActivity('team_member_removed', '', ['team_id' => $teamId, 'member' => $username]);
        }
        echo json_encode($result);
        break;

    // === File-Level Permissions (Admin only) ===

    case 'setFilePermission':
        if (!isAdmin()) {
            echo json_encode(['success' => false, 'error' => 'Admin only']);
            break;
        }
        $input = $jsonBody;
        $filePath = $input['file_path'] ?? '';
        $targetType = $input['target_type'] ?? '';
        $targetId = $input['target_id'] ?? '';
        $permissions = $input['permissions'] ?? [];
        $expiresAt = $input['expires_at'] ?? null;

        if (!$filePath || !$targetType || !$targetId) {
            echo json_encode(['success' => false, 'error' => 'file_path, target_type, and target_id required']);
            break;
        }
        if (!in_array($targetType, ['user', 'team'])) {
            echo json_encode(['success' => false, 'error' => 'target_type must be user or team']);
            break;
        }
        $result = setFilePermission($filePath, $targetType, $targetId, $permissions, $expiresAt);
        if ($result['success']) {
            logActivity('file_permission_set', $filePath, ['target_type' => $targetType, 'target_id' => $targetId, 'expires_at' => $expiresAt]);
            if ($targetType === 'user') {
                createNotification($targetId, 'permission', 'Access Granted', 'You were granted access to: ' . basename($filePath), $filePath);
            }
        }
        echo json_encode($result);
        break;

    case 'getFilePermissions':
        if (!isAdmin()) {
            echo json_encode(['success' => false, 'error' => 'Admin only']);
            break;
        }
        $filePath = $_GET['file_path'] ?? ($jsonBody['file_path'] ?? '');
        if (!$filePath) {
            echo json_encode(['success' => false, 'error' => 'file_path required']);
            break;
        }
        echo json_encode(['success' => true, 'permissions' => getFilePermissions($filePath)]);
        break;

    case 'removeFilePermission':
        if (!isAdmin()) {
            echo json_encode(['success' => false, 'error' => 'Admin only']);
            break;
        }
        $input = $jsonBody;
        $permId = trim($input['perm_id'] ?? '');
        if (!$permId) {
            echo json_encode(['success' => false, 'error' => 'Permission ID required']);
            break;
        }
        $result = removeFilePermission($permId);
        if ($result['success']) {
            logActivity('file_permission_removed', '', ['perm_id' => $permId]);
        }
        echo json_encode($result);
        break;

    case 'cleanExpiredPermissions':
        if (!isAdmin()) {
            echo json_encode(['success' => false, 'error' => 'Admin only']);
            break;
        }
        $count = cleanExpiredFilePermissions();
        echo json_encode(['success' => true, 'cleaned' => $count]);
        break;

    // === Dashboard ===
    case 'getDashboard':
        requireAuth();
        $role = $_SESSION['role'] ?? 'client';
        $dashData = ['role' => $role];
        $dashData['favorites'] = getUserFavorites($_SESSION['user']);

        if ($role === 'admin') {
            // User count
            $usersResult = dbRequest('GET', '/pyra_users?select=id&limit=1000');
            $dashData['user_count'] = ($usersResult['httpCode'] === 200 && is_array($usersResult['data'])) ? count($usersResult['data']) : 0;

            // Recent activity
            $actResult = dbRequest('GET', '/pyra_activity_log?order=created_at.desc&limit=10');
            $dashData['recent_activity'] = ($actResult['httpCode'] === 200 && is_array($actResult['data'])) ? $actResult['data'] : [];

            // Pending reviews count
            $revResult = dbRequest('GET', '/pyra_reviews?resolved=eq.false&select=id&limit=1000');
            $dashData['pending_reviews'] = ($revResult['httpCode'] === 200 && is_array($revResult['data'])) ? count($revResult['data']) : 0;

            // File count from index
            $fileResult = dbRequest('GET', '/pyra_file_index?select=id&limit=10000');
            $dashData['file_count'] = ($fileResult['httpCode'] === 200 && is_array($fileResult['data'])) ? count($fileResult['data']) : 0;

        } elseif ($role === 'employee') {
            $perms = $_SESSION['permissions'] ?? [];
            $allowedPaths = $perms['allowed_paths'] ?? ['*'];
            $dashData['my_folders'] = $allowedPaths;

            // My reviews
            $username = $_SESSION['user'];
            $revResult = dbRequest('GET', '/pyra_reviews?username=eq.' . rawurlencode($username) . '&order=created_at.desc&limit=10');
            $dashData['my_reviews'] = ($revResult['httpCode'] === 200 && is_array($revResult['data'])) ? $revResult['data'] : [];

            // Recent activity in my folders
            $actResult = dbRequest('GET', '/pyra_activity_log?order=created_at.desc&limit=10');
            $dashData['recent_activity'] = ($actResult['httpCode'] === 200 && is_array($actResult['data'])) ? $actResult['data'] : [];

            // Notification count
            $notifResult = dbRequest('GET', '/pyra_notifications?recipient_username=eq.' . rawurlencode($username) . '&is_read=eq.false&select=id&limit=100');
            $dashData['unread_notif_count'] = ($notifResult['httpCode'] === 200 && is_array($notifResult['data'])) ? count($notifResult['data']) : 0;

        } else { // client
            $perms = $_SESSION['permissions'] ?? [];
            $allowedPaths = $perms['allowed_paths'] ?? [];
            $dashData['my_folders'] = $allowedPaths;

            // Folder stats
            $folderStats = [];
            foreach ($allowedPaths as $fp) {
                if ($fp === '*') continue;
                $body = ['prefix' => $fp, 'limit' => 1000, 'offset' => 0, 'sortBy' => ['column' => 'name', 'order' => 'asc']];
                $listResult = supabaseRequest('POST', '/object/list/' . SUPABASE_BUCKET, $body);
                $count = 0;
                $lastMod = '';
                if ($listResult['httpCode'] === 200 && is_array($listResult['data'])) {
                    foreach ($listResult['data'] as $item) {
                        if ($item['id'] !== null || $item['metadata'] !== null) {
                            $count++;
                            if (!empty($item['updated_at']) && $item['updated_at'] > $lastMod) {
                                $lastMod = $item['updated_at'];
                            }
                        }
                    }
                }
                $folderStats[] = ['path' => $fp, 'file_count' => $count, 'last_modified' => $lastMod];
            }
            $dashData['folder_stats'] = $folderStats;

            // My reviews
            $username = $_SESSION['user'];
            $revResult = dbRequest('GET', '/pyra_reviews?username=eq.' . rawurlencode($username) . '&order=created_at.desc&limit=10');
            $dashData['my_reviews'] = ($revResult['httpCode'] === 200 && is_array($revResult['data'])) ? $revResult['data'] : [];

            // Recent activity on my files
            $actResult = dbRequest('GET', '/pyra_activity_log?order=created_at.desc&limit=10');
            $activities = ($actResult['httpCode'] === 200 && is_array($actResult['data'])) ? $actResult['data'] : [];
            // Filter to paths accessible to client
            $dashData['recent_activity'] = array_values(array_filter($activities, function($a) use ($allowedPaths) {
                $tp = $a['target_path'] ?? '';
                foreach ($allowedPaths as $prefix) {
                    if ($prefix === '*') return true;
                    if (strpos($tp, rtrim($prefix, '/')) === 0) return true;
                }
                return false;
            }));
        }

        echo json_encode(['success' => true, 'data' => $dashData]);
        break;

    // === File Versions ===
    case 'getFileVersions':
        requireAuth();
        $path = sanitizePath($_GET['path'] ?? '');
        if (!$path || !canAccessPathEnhanced($path)) {
            echo json_encode(['success' => false, 'error' => 'Access denied']);
            break;
        }
        $versions = getFileVersions($path);
        echo json_encode(['success' => true, 'versions' => $versions]);
        break;

    case 'restoreVersion':
        requireAuth();
        $input = $jsonBody;
        $versionId = $input['version_id'] ?? '';
        if (!$versionId) {
            echo json_encode(['success' => false, 'error' => 'Version ID required']);
            break;
        }
        // Get version record
        $verResult = dbRequest('GET', '/pyra_file_versions?id=eq.' . rawurlencode($versionId) . '&limit=1');
        if ($verResult['httpCode'] !== 200 || empty($verResult['data'])) {
            echo json_encode(['success' => false, 'error' => 'Version not found']);
            break;
        }
        $ver = $verResult['data'][0];
        $originalPath = $ver['file_path'];
        $versionPath = $ver['version_path'];

        if (!canWritePath($originalPath)) {
            echo json_encode(['success' => false, 'error' => 'Access denied']);
            break;
        }

        // Create version of current file before restoring
        createFileVersion($originalPath);

        // Download version file
        $dlResult = storageRequest('GET', $versionPath);
        $vContent = $dlResult['data'];
        $vHttp = $dlResult['httpCode'];

        if ($vHttp !== 200 || empty($vContent)) {
            echo json_encode(['success' => false, 'error' => 'Failed to download version file']);
            break;
        }

        // Upload to original path
        $restoreResult = storageRequest('POST', $originalPath, $vContent, [
            'contentType' => $ver['mime_type'] ?: 'application/octet-stream',
            'extraHeaders' => ['x-upsert: true'],
        ]);
        $rHttp = $restoreResult['httpCode'];

        if ($rHttp === 200 || $rHttp === 201) {
            logActivity('version_restored', $originalPath, ['version_id' => $versionId, 'version_number' => $ver['version_number']]);
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to restore file']);
        }
        break;

    case 'deleteVersion':
        requireAuth();
        if (!isAdmin()) {
            echo json_encode(['success' => false, 'error' => 'Admin only']);
            break;
        }
        $input = $jsonBody;
        $versionId = $input['version_id'] ?? '';
        if (!$versionId) {
            echo json_encode(['success' => false, 'error' => 'Version ID required']);
            break;
        }
        $verResult = dbRequest('GET', '/pyra_file_versions?id=eq.' . rawurlencode($versionId) . '&limit=1');
        if ($verResult['httpCode'] !== 200 || empty($verResult['data'])) {
            echo json_encode(['success' => false, 'error' => 'Version not found']);
            break;
        }
        $ver = $verResult['data'][0];
        // Delete version file from storage
        storageRequest('DELETE', $ver['version_path']);
        // Delete record
        $delResult = deleteFileVersionRecord($versionId);
        echo json_encode($delResult);
        break;

    // === System Settings ===
    case 'getSettings':
        requireAuth();
        if (!isAdmin()) {
            echo json_encode(['success' => false, 'error' => 'Admin only']);
            break;
        }
        echo json_encode(['success' => true, 'settings' => getSettings()]);
        break;

    case 'updateSettings':
        requireAuth();
        if (!isAdmin()) {
            echo json_encode(['success' => false, 'error' => 'Admin only']);
            break;
        }
        $input = $jsonBody;
        $settings = $input['settings'] ?? [];
        $updated = 0;
        foreach ($settings as $key => $value) {
            if (updateSetting($key, (string)$value)) $updated++;
        }
        logActivity('settings_updated', '', ['count' => $updated]);
        echo json_encode(['success' => true, 'updated' => $updated]);
        break;

    case 'getPublicSettings':
        echo json_encode(['success' => true, 'settings' => getPublicSettings()]);
        break;

    // === Session Management ===
    case 'getSessions':
        requireAuth();
        $targetUser = $_GET['username'] ?? $_SESSION['user'];
        if ($targetUser !== $_SESSION['user'] && !isAdmin()) {
            echo json_encode(['success' => false, 'error' => 'Access denied']);
            break;
        }
        echo json_encode(['success' => true, 'sessions' => getUserSessions($targetUser)]);
        break;

    case 'terminateSession':
        requireAuth();
        $input = $jsonBody;
        $targetSessionId = $input['session_id'] ?? '';
        if (!$targetSessionId) {
            echo json_encode(['success' => false, 'error' => 'Session ID required']);
            break;
        }
        // Verify ownership or admin
        if (!isAdmin()) {
            $sessResult = dbRequest('GET', '/pyra_sessions?id=eq.' . rawurlencode($targetSessionId) . '&limit=1');
            if ($sessResult['httpCode'] !== 200 || empty($sessResult['data']) || $sessResult['data'][0]['username'] !== $_SESSION['user']) {
                echo json_encode(['success' => false, 'error' => 'Access denied']);
                break;
            }
        }
        $success = terminateSession($targetSessionId);
        echo json_encode(['success' => $success]);
        break;

    case 'terminateAllSessions':
        requireAuth();
        $input = $jsonBody;
        $targetUser = $input['username'] ?? $_SESSION['user'];
        if ($targetUser !== $_SESSION['user'] && !isAdmin()) {
            echo json_encode(['success' => false, 'error' => 'Access denied']);
            break;
        }
        terminateAllSessions($targetUser, session_id());
        echo json_encode(['success' => true]);
        break;

    case 'getLoginHistory':
        requireAuth();
        if (!isAdmin()) {
            echo json_encode(['success' => false, 'error' => 'Admin only']);
            break;
        }
        echo json_encode(['success' => true, 'history' => getLoginHistory()]);
        break;

    // === File Index ===
    case 'rebuildIndex':
        requireAuth();
        if (!isAdmin()) {
            echo json_encode(['success' => false, 'error' => 'Admin only']);
            break;
        }
        // Clear existing index
        dbRequest('DELETE', '/pyra_file_index?id=neq.none');
        // Walk entire storage
        $allFiles = recursiveListFiles('');
        $indexed = 0;
        foreach ($allFiles as $f) {
            indexFile([
                'file_path' => $f['path'],
                'file_size' => $f['size'] ?? 0,
                'mime_type' => $f['mimetype'] ?? ''
            ]);
            $indexed++;
        }
        logActivity('index_rebuilt', '', ['file_count' => $indexed]);
        echo json_encode(['success' => true, 'indexed' => $indexed]);
        break;

    // === Favorites ===

    case 'getFavorites':
        $favorites = getUserFavorites($_SESSION['user']);
        echo json_encode(['success' => true, 'favorites' => $favorites]);
        break;

    case 'addFavorite':
        $input = $jsonBody;
        $filePath = sanitizePath($input['file_path'] ?? '');
        $itemType = $input['item_type'] ?? 'file';
        $displayName = $input['display_name'] ?? '';
        if (!$filePath) {
            echo json_encode(['success' => false, 'error' => 'File path required']);
            break;
        }
        if (!in_array($itemType, ['file', 'folder'])) {
            echo json_encode(['success' => false, 'error' => 'Invalid item type']);
            break;
        }
        $result = addFavoriteItem($_SESSION['user'], $filePath, $itemType, $displayName);
        echo json_encode($result);
        break;

    case 'removeFavorite':
        $input = $jsonBody;
        $filePath = sanitizePath($input['file_path'] ?? '');
        if (!$filePath) {
            echo json_encode(['success' => false, 'error' => 'File path required']);
            break;
        }
        $result = removeFavoriteItem($_SESSION['user'], $filePath);
        echo json_encode($result);
        break;

    // ============================================
    // CLIENT PORTAL — Team Management Endpoints
    // ============================================

    case 'manage_clients':
        if (!isAdmin()) {
            echo json_encode(['success' => false, 'error' => 'Admin access required']);
            break;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $result = dbRequest('GET', '/pyra_clients?select=id,name,email,company,phone,role,status,language,last_login_at,created_at,created_by&order=created_at.desc');
            echo json_encode(['success' => true, 'clients' => $result['data'] ?? []]);
            break;
        }

        $input = $jsonBody;
        $sub = $input['sub_action'] ?? '';

        switch ($sub) {
            case 'create':
                $name = trim($input['name'] ?? '');
                $email = trim($input['email'] ?? '');
                $company = trim($input['company'] ?? '');
                $password = $input['password'] ?? '';
                $role = $input['role'] ?? 'primary';
                $phone = trim($input['phone'] ?? '');
                $language = $input['language'] ?? 'ar';

                if (!$name || !$email || !$company || strlen($password) < 8) {
                    echo json_encode(['success' => false, 'error' => 'بيانات ناقصة أو كلمة المرور أقل من 8 حروف']);
                    break;
                }
                if (!in_array($role, ['primary', 'billing', 'viewer'])) {
                    echo json_encode(['success' => false, 'error' => 'نوع الصلاحية غير صالح']);
                    break;
                }
                if (!in_array($language, ['ar', 'en'])) $language = 'ar';

                $exists = dbRequest('GET', '/pyra_clients?email=eq.' . rawurlencode($email) . '&limit=1');
                if (!empty($exists['data'])) {
                    echo json_encode(['success' => false, 'error' => 'الإيميل موجود مسبقاً']);
                    break;
                }

                $id = generateClientId();
                $data = [
                    'id' => $id,
                    'name' => htmlspecialchars($name),
                    'email' => $email,
                    'company' => htmlspecialchars($company),
                    'phone' => htmlspecialchars($phone),
                    'password_hash' => password_hash($password, PASSWORD_BCRYPT),
                    'role' => $role,
                    'status' => 'active',
                    'language' => $language,
                    'created_by' => $_SESSION['user'] ?? 'admin'
                ];

                $result = dbRequest('POST', '/pyra_clients', $data, ['Prefer: return=representation']);
                if ($result['httpCode'] === 201) {
                    logActivity('create_client', '', ['client_email' => $email, 'company' => $company]);

                    // Send welcome notification
                    dbRequest('POST', '/pyra_client_notifications', [
                        'id' => generatePortalId('cn'),
                        'client_id' => $id,
                        'type' => 'welcome',
                        'title' => 'مرحباً بك في Pyra Workspace',
                        'message' => 'تم إنشاء حسابك بنجاح. يمكنك الآن تصفح مشاريعك ومتابعة الملفات.'
                    ]);

                    $clientData = $result['data'][0] ?? $data;
                    unset($clientData['password_hash']);
                    echo json_encode(['success' => true, 'client' => $clientData]);
                } else {
                    echo json_encode(['success' => false, 'error' => $result['data']['message'] ?? 'فشل في إنشاء العميل']);
                }
                break;

            case 'update':
                $clientId = trim($input['client_id'] ?? '');
                if (!$clientId) {
                    echo json_encode(['success' => false, 'error' => 'معرّف العميل مطلوب']);
                    break;
                }

                $update = [];
                if (isset($input['name'])) $update['name'] = htmlspecialchars(trim($input['name']));
                if (isset($input['phone'])) $update['phone'] = htmlspecialchars(trim($input['phone']));
                if (isset($input['role']) && in_array($input['role'], ['primary', 'billing', 'viewer'])) $update['role'] = $input['role'];
                if (isset($input['status']) && in_array($input['status'], ['active', 'inactive', 'suspended'])) $update['status'] = $input['status'];
                if (isset($input['language']) && in_array($input['language'], ['ar', 'en'])) $update['language'] = $input['language'];
                if (!empty($input['password']) && strlen($input['password']) >= 8) {
                    $update['password_hash'] = password_hash($input['password'], PASSWORD_BCRYPT);
                }

                if (empty($update)) {
                    echo json_encode(['success' => false, 'error' => 'لا توجد بيانات للتحديث']);
                    break;
                }

                $update['updated_at'] = date('c');
                $result = dbRequest('PATCH', '/pyra_clients?id=eq.' . rawurlencode($clientId), $update);
                if ($result['httpCode'] === 200 || $result['httpCode'] === 204) {
                    logActivity('update_client', '', ['client_id' => $clientId]);
                    echo json_encode(['success' => true]);
                } else {
                    echo json_encode(['success' => false, 'error' => 'فشل في تحديث العميل']);
                }
                break;

            case 'delete':
                $clientId = trim($input['client_id'] ?? '');
                if (!$clientId) {
                    echo json_encode(['success' => false, 'error' => 'معرّف العميل مطلوب']);
                    break;
                }

                $result = dbRequest('DELETE', '/pyra_clients?id=eq.' . rawurlencode($clientId));
                if ($result['httpCode'] === 200 || $result['httpCode'] === 204) {
                    logActivity('delete_client', '', ['client_id' => $clientId]);
                    echo json_encode(['success' => true]);
                } else {
                    echo json_encode(['success' => false, 'error' => 'فشل في حذف العميل']);
                }
                break;

            default:
                echo json_encode(['success' => false, 'error' => 'عملية فرعية غير معروفة']);
        }
        break;

    case 'manage_projects':
        if (!isAdmin()) {
            echo json_encode(['success' => false, 'error' => 'Admin access required']);
            break;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $result = dbRequest('GET', '/pyra_projects?order=updated_at.desc');
            echo json_encode(['success' => true, 'projects' => $result['data'] ?? []]);
            break;
        }

        $input = $jsonBody;
        $sub = $input['sub_action'] ?? '';

        switch ($sub) {
            case 'create':
                $name = trim($input['name'] ?? '');
                $company = trim($input['company'] ?? '');
                $storagePath = trim($input['storage_path'] ?? '');

                if (!$name || !$company || !$storagePath) {
                    echo json_encode(['success' => false, 'error' => 'اسم المشروع والشركة ومسار التخزين مطلوبون']);
                    break;
                }

                $id = generatePortalId('prj');
                $data = [
                    'id' => $id,
                    'name' => htmlspecialchars($name),
                    'description' => htmlspecialchars(trim($input['description'] ?? '')),
                    'client_company' => htmlspecialchars($company),
                    'status' => $input['status'] ?? 'active',
                    'start_date' => $input['start_date'] ?? null,
                    'deadline' => $input['deadline'] ?? null,
                    'storage_path' => sanitizePath($storagePath),
                    'cover_image' => $input['cover_image'] ?? null,
                    'created_by' => $_SESSION['user'] ?? 'admin'
                ];

                $result = dbRequest('POST', '/pyra_projects', $data, ['Prefer: return=representation']);
                if ($result['httpCode'] === 201) {
                    logActivity('create_project', $storagePath, ['project_name' => $name, 'company' => $company]);
                    echo json_encode(['success' => true, 'project' => $result['data'][0] ?? $data]);
                } else {
                    echo json_encode(['success' => false, 'error' => $result['data']['message'] ?? 'فشل في إنشاء المشروع']);
                }
                break;

            case 'update':
                $projectId = trim($input['project_id'] ?? '');
                if (!$projectId) {
                    echo json_encode(['success' => false, 'error' => 'معرّف المشروع مطلوب']);
                    break;
                }

                $update = [];
                if (isset($input['name'])) $update['name'] = htmlspecialchars(trim($input['name']));
                if (isset($input['description'])) $update['description'] = htmlspecialchars(trim($input['description']));
                if (isset($input['status']) && in_array($input['status'], ['draft', 'active', 'in_progress', 'review', 'completed', 'archived'])) {
                    $update['status'] = $input['status'];
                }
                if (isset($input['start_date'])) $update['start_date'] = $input['start_date'];
                if (isset($input['deadline'])) $update['deadline'] = $input['deadline'];
                if (isset($input['cover_image'])) $update['cover_image'] = $input['cover_image'];

                if (empty($update)) {
                    echo json_encode(['success' => false, 'error' => 'لا توجد بيانات للتحديث']);
                    break;
                }

                $update['updated_at'] = date('c');
                $result = dbRequest('PATCH', '/pyra_projects?id=eq.' . rawurlencode($projectId), $update);
                if ($result['httpCode'] === 200 || $result['httpCode'] === 204) {
                    logActivity('update_project', '', ['project_id' => $projectId]);

                    // Notify clients if status changed
                    if (isset($update['status'])) {
                        $proj = dbRequest('GET', '/pyra_projects?id=eq.' . rawurlencode($projectId) . '&limit=1');
                        if (!empty($proj['data'])) {
                            $p = $proj['data'][0];
                            $clients = dbRequest('GET', '/pyra_clients?company=eq.' . rawurlencode($p['client_company']) . '&status=eq.active&select=id');
                            if (!empty($clients['data'])) {
                                foreach ($clients['data'] as $c) {
                                    dbRequest('POST', '/pyra_client_notifications', [
                                        'id' => generatePortalId('cn'),
                                        'client_id' => $c['id'],
                                        'type' => 'project_status',
                                        'title' => 'تحديث حالة المشروع: ' . htmlspecialchars($p['name']),
                                        'message' => 'الحالة الجديدة: ' . $update['status'],
                                        'target_project_id' => $projectId
                                    ]);
                                }
                            }
                        }
                    }

                    echo json_encode(['success' => true]);
                } else {
                    echo json_encode(['success' => false, 'error' => 'فشل في تحديث المشروع']);
                }
                break;

            case 'delete':
                $projectId = trim($input['project_id'] ?? '');
                if (!$projectId) {
                    echo json_encode(['success' => false, 'error' => 'معرّف المشروع مطلوب']);
                    break;
                }

                $result = dbRequest('DELETE', '/pyra_projects?id=eq.' . rawurlencode($projectId));
                if ($result['httpCode'] === 200 || $result['httpCode'] === 204) {
                    logActivity('delete_project', '', ['project_id' => $projectId]);
                    echo json_encode(['success' => true]);
                } else {
                    echo json_encode(['success' => false, 'error' => 'فشل في حذف المشروع']);
                }
                break;

            default:
                echo json_encode(['success' => false, 'error' => 'عملية فرعية غير معروفة']);
        }
        break;

    case 'manage_project_files':
        if (!isAdmin()) {
            echo json_encode(['success' => false, 'error' => 'Admin access required']);
            break;
        }

        $input = $jsonBody;
        $projectId = trim($input['project_id'] ?? '');
        $fileName = trim($input['file_name'] ?? '');
        $filePath = trim($input['file_path'] ?? '');

        if (!$projectId || !$fileName || !$filePath) {
            echo json_encode(['success' => false, 'error' => 'بيانات الملف ناقصة']);
            break;
        }

        // Verify project exists
        $proj = dbRequest('GET', '/pyra_projects?id=eq.' . rawurlencode($projectId) . '&limit=1');
        if ($proj['httpCode'] !== 200 || empty($proj['data'])) {
            echo json_encode(['success' => false, 'error' => 'المشروع غير موجود']);
            break;
        }

        $project = $proj['data'][0];
        $needsApproval = !empty($input['needs_approval']);

        $fileId = generatePortalId('pf');
        $fileData = [
            'id' => $fileId,
            'project_id' => $projectId,
            'file_name' => htmlspecialchars($fileName),
            'file_path' => sanitizePath($filePath),
            'file_size' => (int)($input['file_size'] ?? 0),
            'mime_type' => $input['mime_type'] ?? null,
            'category' => in_array($input['category'] ?? '', ['general', 'design', 'video', 'document', 'audio', 'other']) ? $input['category'] : 'general',
            'version' => (int)($input['version'] ?? 1),
            'needs_approval' => $needsApproval,
            'uploaded_by' => $_SESSION['user'] ?? 'admin'
        ];

        $result = dbRequest('POST', '/pyra_project_files', $fileData, ['Prefer: return=representation']);
        if ($result['httpCode'] !== 201) {
            echo json_encode(['success' => false, 'error' => $result['data']['message'] ?? 'فشل في إضافة الملف']);
            break;
        }

        logActivity('add_project_file', $filePath, ['project_id' => $projectId, 'file_name' => $fileName]);

        // If needs_approval, create approval records for all primary clients of this company
        if ($needsApproval) {
            $clients = dbRequest('GET', '/pyra_clients?company=eq.' . rawurlencode($project['client_company'])
                . '&role=eq.primary&status=eq.active&select=id');
            if ($clients['httpCode'] === 200 && !empty($clients['data'])) {
                foreach ($clients['data'] as $c) {
                    dbRequest('POST', '/pyra_file_approvals', [
                        'id' => generatePortalId('fa'),
                        'file_id' => $fileId,
                        'client_id' => $c['id'],
                        'status' => 'pending'
                    ]);
                }
            }
        }

        // Notify all active clients of this company
        $allClients = dbRequest('GET', '/pyra_clients?company=eq.' . rawurlencode($project['client_company'])
            . '&status=eq.active&select=id');
        if ($allClients['httpCode'] === 200 && !empty($allClients['data'])) {
            foreach ($allClients['data'] as $c) {
                dbRequest('POST', '/pyra_client_notifications', [
                    'id' => generatePortalId('cn'),
                    'client_id' => $c['id'],
                    'type' => 'new_file',
                    'title' => 'ملف جديد: ' . htmlspecialchars($fileName),
                    'message' => 'تم إضافة ملف جديد في مشروع ' . htmlspecialchars($project['name']),
                    'target_project_id' => $projectId,
                    'target_file_id' => $fileId
                ]);
            }
        }

        echo json_encode(['success' => true, 'file' => $result['data'][0] ?? $fileData]);
        break;

    case 'team_reply_to_client':
        if (!isLoggedIn()) {
            echo json_encode(['success' => false, 'error' => 'Not authenticated']);
            break;
        }

        $input = $jsonBody;
        $projectId = trim($input['project_id'] ?? '');
        $text = trim($input['text'] ?? '');
        $fileId = trim($input['file_id'] ?? '') ?: null;
        $parentId = trim($input['parent_id'] ?? '') ?: null;

        if (!$projectId || mb_strlen($text) < 3) {
            echo json_encode(['success' => false, 'error' => 'معرّف المشروع والنص (3 حروف على الأقل) مطلوبان']);
            break;
        }

        $commentId = generatePortalId('cc');
        $sanitizedText = htmlspecialchars($text);
        $displayName = $_SESSION['display_name'] ?? $_SESSION['user'] ?? 'Team';

        $record = [
            'id' => $commentId,
            'project_id' => $projectId,
            'file_id' => $fileId,
            'author_type' => 'team',
            'author_id' => $_SESSION['user'] ?? 'admin',
            'author_name' => $displayName,
            'text' => $sanitizedText,
            'parent_id' => $parentId,
            'is_read_by_client' => false,
            'is_read_by_team' => true
        ];

        $result = dbRequest('POST', '/pyra_client_comments', $record, ['Prefer: return=representation']);
        if ($result['httpCode'] === 201) {
            logActivity('team_reply_comment', '', ['project_id' => $projectId]);

            // Notify clients of this company
            $proj = dbRequest('GET', '/pyra_projects?id=eq.' . rawurlencode($projectId) . '&limit=1');
            if (!empty($proj['data'])) {
                $p = $proj['data'][0];
                $clients = dbRequest('GET', '/pyra_clients?company=eq.' . rawurlencode($p['client_company']) . '&status=eq.active&select=id');
                if (!empty($clients['data'])) {
                    foreach ($clients['data'] as $c) {
                        dbRequest('POST', '/pyra_client_notifications', [
                            'id' => generatePortalId('cn'),
                            'client_id' => $c['id'],
                            'type' => 'comment_reply',
                            'title' => 'رد جديد من الفريق',
                            'message' => mb_substr($sanitizedText, 0, 100),
                            'target_project_id' => $projectId
                        ]);
                    }
                }
            }

            $comment = (is_array($result['data']) && !empty($result['data'])) ? $result['data'][0] : $record;
            echo json_encode(['success' => true, 'comment' => $comment]);
        } else {
            echo json_encode(['success' => false, 'error' => 'فشل في إضافة الرد']);
        }
        break;

    case 'getClientComments':
        if (!isLoggedIn()) {
            echo json_encode(['success' => false, 'error' => 'Not authenticated']);
            break;
        }

        $projectId = $_GET['project_id'] ?? '';
        if (!$projectId) {
            echo json_encode(['success' => false, 'error' => 'معرّف المشروع مطلوب']);
            break;
        }

        $endpoint = '/pyra_client_comments?project_id=eq.' . rawurlencode($projectId) . '&order=created_at.asc';
        $result = dbRequest('GET', $endpoint);
        $comments = ($result['httpCode'] === 200 && is_array($result['data'])) ? $result['data'] : [];

        // Build threaded structure
        $threaded = [];
        $byId = [];
        foreach ($comments as $c) {
            $c['replies'] = [];
            $byId[$c['id']] = $c;
        }
        foreach ($byId as $id => $c) {
            if (!empty($c['parent_id']) && isset($byId[$c['parent_id']])) {
                $byId[$c['parent_id']]['replies'][] = &$byId[$id];
            } else {
                $threaded[] = &$byId[$id];
            }
        }

        // Mark client comments as read by team
        dbRequest('PATCH', '/pyra_client_comments?project_id=eq.' . rawurlencode($projectId)
            . '&author_type=eq.client&is_read_by_team=eq.false', [
            'is_read_by_team' => true
        ]);

        echo json_encode(['success' => true, 'comments' => $threaded]);
        break;

    default:
        echo json_encode(['error' => 'Invalid action']);
        break;
}
