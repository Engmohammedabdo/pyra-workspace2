<?php
/**
 * Client Portal API — portal/index.php
 * Separate auth, separate session keys, separate security boundary
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');

$action = $_GET['action'] ?? '';

// Public actions (no auth needed)
$publicActions = ['client_login', 'client_logout', 'client_session', 'client_forgot_password', 'client_reset_password', 'getPublicSettings'];

if ($action && !in_array($action, $publicActions)) {
    $client = requireClientAuth();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        validateClientCsrf();
    }
} else {
    $client = null;
}

if ($action) {
    header('Content-Type: application/json; charset=utf-8');

    switch ($action) {

    // ============================================
    // AUTH
    // ============================================

    case 'client_login':
        $input = json_decode(file_get_contents('php://input'), true);
        $email = trim($input['email'] ?? '');
        $password = $input['password'] ?? '';

        if (!$email || !$password) {
            echo json_encode(['error' => 'البريد الإلكتروني وكلمة المرور مطلوبان']);
            break;
        }

        usleep(200000);

        $lockout = isClientAccountLocked($email);
        if ($lockout['locked']) {
            echo json_encode(['error' => 'الحساب مقفل. حاول بعد ' . $lockout['remaining_minutes'] . ' دقيقة']);
            break;
        }

        $ip = $_SERVER['REMOTE_ADDR'] ?? '';

        $result = dbRequest('GET', '/pyra_clients?email=eq.' . rawurlencode($email) . '&limit=1');
        if ($result['httpCode'] !== 200 || !is_array($result['data']) || empty($result['data'])) {
            recordClientLoginAttempt($email, $ip, false);
            echo json_encode(['error' => 'بيانات الدخول غير صحيحة']);
            break;
        }

        $clientRow = $result['data'][0];

        if (!password_verify($password, $clientRow['password_hash'])) {
            recordClientLoginAttempt($email, $ip, false);
            echo json_encode(['error' => 'بيانات الدخول غير صحيحة']);
            break;
        }

        if (($clientRow['status'] ?? '') !== 'active') {
            recordClientLoginAttempt($email, $ip, false);
            echo json_encode(['error' => 'الحساب معلّق. تواصل مع الإدارة']);
            break;
        }

        session_regenerate_id(true);

        $_SESSION['client_id'] = $clientRow['id'];
        $_SESSION['client_email'] = $clientRow['email'];
        $_SESSION['client_name'] = $clientRow['name'];
        $_SESSION['client_company'] = $clientRow['company'];
        $_SESSION['client_role'] = $clientRow['role'];
        $_SESSION['client_csrf_token'] = bin2hex(random_bytes(32));

        dbRequest('PATCH', '/pyra_clients?id=eq.' . rawurlencode($clientRow['id']), [
            'last_login_at' => date('c')
        ]);

        recordClientLoginAttempt($email, $ip, true);

        echo json_encode([
            'success' => true,
            'client' => [
                'id' => $clientRow['id'],
                'name' => $clientRow['name'],
                'email' => $clientRow['email'],
                'company' => $clientRow['company'],
                'role' => $clientRow['role']
            ]
        ]);
        break;

    case 'client_logout':
        unset(
            $_SESSION['client_id'],
            $_SESSION['client_email'],
            $_SESSION['client_name'],
            $_SESSION['client_company'],
            $_SESSION['client_role'],
            $_SESSION['client_csrf_token']
        );
        echo json_encode(['success' => true]);
        break;

    case 'client_session':
        if (isClientLoggedIn()) {
            $cData = getClientData();
            if ($cData) {
                echo json_encode([
                    'authenticated' => true,
                    'client' => $cData,
                    'csrf_token' => $_SESSION['client_csrf_token'] ?? ''
                ]);
            } else {
                echo json_encode(['authenticated' => false]);
            }
        } else {
            echo json_encode(['authenticated' => false]);
        }
        break;

    case 'client_forgot_password':
        $input = json_decode(file_get_contents('php://input'), true);
        $email = trim($input['email'] ?? '');

        if (!$email) {
            echo json_encode(['error' => 'البريد الإلكتروني مطلوب']);
            break;
        }

        $result = dbRequest('GET', '/pyra_clients?email=eq.' . rawurlencode($email) . '&limit=1');
        if ($result['httpCode'] !== 200 || empty($result['data'])) {
            echo json_encode(['success' => true, 'message' => 'إذا كان البريد مسجّل، ستصلك رسالة لإعادة تعيين كلمة المرور']);
            break;
        }

        $clientRow = $result['data'][0];
        $token = bin2hex(random_bytes(64));
        $expiresAt = date('c', strtotime('+1 hour'));

        dbRequest('POST', '/pyra_client_password_resets', [
            'id' => generatePortalId('cpr'),
            'client_id' => $clientRow['id'],
            'token' => $token,
            'expires_at' => $expiresAt
        ], ['Prefer: return=representation']);

        $resetUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
            . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
            . dirname($_SERVER['SCRIPT_NAME']) . '/?reset_token=' . $token;

        sendClientEmail(
            $clientRow['email'],
            'إعادة تعيين كلمة المرور — Pyra Workspace',
            '<div dir="rtl" style="font-family:Cairo,Arial,sans-serif;max-width:500px;margin:0 auto;padding:20px">'
            . '<h2>إعادة تعيين كلمة المرور</h2>'
            . '<p>مرحباً ' . htmlspecialchars($clientRow['name']) . '،</p>'
            . '<p>اضغط على الرابط لإعادة تعيين كلمة المرور:</p>'
            . '<p><a href="' . htmlspecialchars($resetUrl) . '" style="background:#8b5cf6;color:#fff;padding:10px 24px;border-radius:8px;text-decoration:none;display:inline-block">إعادة التعيين</a></p>'
            . '<p style="color:#888;font-size:13px">ينتهي هذا الرابط بعد ساعة واحدة.</p>'
            . '</div>'
        );

        echo json_encode(['success' => true, 'message' => 'إذا كان البريد مسجّل، ستصلك رسالة لإعادة تعيين كلمة المرور']);
        break;

    case 'client_reset_password':
        $input = json_decode(file_get_contents('php://input'), true);
        $token = trim($input['token'] ?? '');
        $newPassword = $input['new_password'] ?? '';

        if (!$token) {
            echo json_encode(['error' => 'الرابط غير صحيح']);
            break;
        }
        if (strlen($newPassword) < 8) {
            echo json_encode(['error' => 'كلمة المرور الجديدة يجب أن تكون 8 حروف على الأقل']);
            break;
        }

        $result = dbRequest('GET', '/pyra_client_password_resets?token=eq.' . rawurlencode($token)
            . '&used=eq.false&expires_at=gte.' . rawurlencode(date('c'))
            . '&limit=1');

        if ($result['httpCode'] !== 200 || empty($result['data'])) {
            echo json_encode(['error' => 'الرابط غير صحيح أو منتهي الصلاحية']);
            break;
        }

        $resetRecord = $result['data'][0];
        $hash = password_hash($newPassword, PASSWORD_BCRYPT);

        dbRequest('PATCH', '/pyra_clients?id=eq.' . rawurlencode($resetRecord['client_id']), [
            'password_hash' => $hash,
            'updated_at' => date('c')
        ]);

        dbRequest('PATCH', '/pyra_client_password_resets?id=eq.' . rawurlencode($resetRecord['id']), [
            'used' => true
        ]);

        echo json_encode(['success' => true, 'message' => 'تم تحديث كلمة المرور بنجاح']);
        break;

    // ============================================
    // DASHBOARD
    // ============================================

    case 'client_dashboard':
        $company = $client['company'];
        $clientId = $client['id'];

        // Active projects
        $projects = dbRequest('GET', '/pyra_projects?client_company=eq.' . rawurlencode($company)
            . '&status=not.in.(draft,archived)&order=updated_at.desc&limit=5');
        $projectList = ($projects['httpCode'] === 200 && is_array($projects['data'])) ? $projects['data'] : [];

        // Pending approvals
        $pending = dbRequest('GET', '/v_pending_approvals?client_company=eq.' . rawurlencode($company) . '&limit=10');
        $pendingList = ($pending['httpCode'] === 200 && is_array($pending['data'])) ? $pending['data'] : [];

        // Recent files across all company projects
        $projectIds = array_column($projectList, 'id');
        $recentFiles = [];
        if (!empty($projectIds)) {
            $idFilter = implode(',', array_map('rawurlencode', $projectIds));
            $files = dbRequest('GET', '/pyra_project_files?project_id=in.(' . $idFilter . ')&order=created_at.desc&limit=10');
            $recentFiles = ($files['httpCode'] === 200 && is_array($files['data'])) ? $files['data'] : [];
        }

        // Unread notifications
        $unread = dbRequest('GET', '/pyra_client_notifications?client_id=eq.' . rawurlencode($clientId)
            . '&is_read=eq.false&select=id');
        $unreadCount = ($unread['httpCode'] === 200 && is_array($unread['data'])) ? count($unread['data']) : 0;

        // Unread comments for client
        $unreadComments = dbRequest('GET', '/pyra_client_comments?is_read_by_client=eq.false'
            . '&author_type=eq.team&select=id');
        $unreadCommentsCount = 0;
        if ($unreadComments['httpCode'] === 200 && is_array($unreadComments['data'])) {
            // Filter to only comments on client's projects
            $unreadCommentsCount = 0;
            if (!empty($projectIds)) {
                $commentCheck = dbRequest('GET', '/pyra_client_comments?is_read_by_client=eq.false'
                    . '&author_type=eq.team&project_id=in.(' . $idFilter . ')&select=id');
                $unreadCommentsCount = ($commentCheck['httpCode'] === 200 && is_array($commentCheck['data'])) ? count($commentCheck['data']) : 0;
            }
        }

        // Recent notifications
        $recentNotifs = dbRequest('GET', '/pyra_client_notifications?client_id=eq.' . rawurlencode($clientId)
            . '&order=created_at.desc&limit=5');
        $recentNotifList = ($recentNotifs['httpCode'] === 200 && is_array($recentNotifs['data'])) ? $recentNotifs['data'] : [];

        // Count all active projects
        $allProjects = dbRequest('GET', '/pyra_projects?client_company=eq.' . rawurlencode($company)
            . '&status=not.in.(draft,archived)&select=id');
        $totalActive = ($allProjects['httpCode'] === 200 && is_array($allProjects['data'])) ? count($allProjects['data']) : 0;

        echo json_encode([
            'success' => true,
            'dashboard' => [
                'client' => [
                    'name' => $client['name'],
                    'company' => $company,
                    'last_login' => null
                ],
                'projects' => [
                    'total_active' => $totalActive,
                    'list' => $projectList
                ],
                'pending_approvals' => [
                    'total' => count($pendingList),
                    'list' => $pendingList
                ],
                'recent_files' => [
                    'total' => count($recentFiles),
                    'list' => $recentFiles
                ],
                'unread_notifications' => $unreadCount,
                'unread_comments' => $unreadCommentsCount,
                'recent_notifications' => $recentNotifList
            ]
        ]);
        break;

    // ============================================
    // PROJECTS
    // ============================================

    case 'client_projects':
        $company = $client['company'];
        $status = $_GET['status'] ?? 'all';
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = 20;
        $offset = ($page - 1) * $perPage;

        $endpoint = '/pyra_projects?client_company=eq.' . rawurlencode($company)
            . '&status=not.in.(draft,archived)'
            . '&order=updated_at.desc&limit=' . $perPage . '&offset=' . $offset;

        if ($status !== 'all' && in_array($status, ['active', 'in_progress', 'review', 'completed'])) {
            $endpoint = '/pyra_projects?client_company=eq.' . rawurlencode($company)
                . '&status=eq.' . rawurlencode($status)
                . '&order=updated_at.desc&limit=' . $perPage . '&offset=' . $offset;
        }

        $result = dbRequest('GET', $endpoint);
        $list = ($result['httpCode'] === 200 && is_array($result['data'])) ? $result['data'] : [];

        // Total count
        $countEndpoint = '/pyra_projects?client_company=eq.' . rawurlencode($company)
            . '&status=not.in.(draft,archived)&select=id';
        if ($status !== 'all' && in_array($status, ['active', 'in_progress', 'review', 'completed'])) {
            $countEndpoint = '/pyra_projects?client_company=eq.' . rawurlencode($company)
                . '&status=eq.' . rawurlencode($status) . '&select=id';
        }
        $countResult = dbRequest('GET', $countEndpoint);
        $total = ($countResult['httpCode'] === 200 && is_array($countResult['data'])) ? count($countResult['data']) : 0;

        echo json_encode([
            'success' => true,
            'projects' => $list,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage
        ]);
        break;

    case 'client_project_detail':
        $company = $client['company'];
        $projectId = $_GET['project_id'] ?? '';
        $fileCategory = $_GET['file_category'] ?? 'all';
        $approvalFilter = $_GET['approval_filter'] ?? 'all';
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = 20;
        $offset = ($page - 1) * $perPage;

        if (!$projectId) {
            echo json_encode(['error' => 'معرّف المشروع مطلوب']);
            break;
        }

        // Verify project belongs to client's company
        $proj = dbRequest('GET', '/pyra_projects?id=eq.' . rawurlencode($projectId)
            . '&client_company=eq.' . rawurlencode($company) . '&limit=1');

        if ($proj['httpCode'] !== 200 || empty($proj['data'])) {
            echo json_encode(['error' => 'المشروع غير موجود']);
            break;
        }

        $project = $proj['data'][0];

        // Get files with filters
        $fileEndpoint = '/pyra_project_files?project_id=eq.' . rawurlencode($projectId)
            . '&order=created_at.desc&limit=' . $perPage . '&offset=' . $offset;

        if ($fileCategory !== 'all' && in_array($fileCategory, ['general', 'design', 'video', 'document', 'audio', 'other'])) {
            $fileEndpoint .= '&category=eq.' . rawurlencode($fileCategory);
        }

        $filesResult = dbRequest('GET', $fileEndpoint);
        $files = ($filesResult['httpCode'] === 200 && is_array($filesResult['data'])) ? $filesResult['data'] : [];

        // Get approval status for each file
        $clientId = $client['id'];
        foreach ($files as &$file) {
            $approval = dbRequest('GET', '/pyra_file_approvals?file_id=eq.' . rawurlencode($file['id'])
                . '&client_id=eq.' . rawurlencode($clientId) . '&limit=1');
            if ($approval['httpCode'] === 200 && !empty($approval['data'])) {
                $file['approval_status'] = $approval['data'][0]['status'];
                $file['approval_comment'] = $approval['data'][0]['comment'];
            } else {
                $file['approval_status'] = null;
                $file['approval_comment'] = null;
            }
        }
        unset($file);

        // Filter by approval status if requested
        if ($approvalFilter !== 'all' && in_array($approvalFilter, ['pending', 'approved', 'revision_requested'])) {
            $files = array_values(array_filter($files, function($f) use ($approvalFilter) {
                return ($f['approval_status'] ?? '') === $approvalFilter;
            }));
        }

        // Aggregate counts
        $allFiles = dbRequest('GET', '/pyra_project_files?project_id=eq.' . rawurlencode($projectId) . '&select=id');
        $totalFiles = ($allFiles['httpCode'] === 200 && is_array($allFiles['data'])) ? count($allFiles['data']) : 0;

        $summary = dbRequest('GET', '/v_project_summary?id=eq.' . rawurlencode($projectId) . '&limit=1');
        $approved = 0; $pending = 0; $revision = 0;
        if ($summary['httpCode'] === 200 && !empty($summary['data'])) {
            $s = $summary['data'][0];
            $approved = (int)($s['approved_count'] ?? 0);
            $pending = (int)($s['pending_count'] ?? 0);
            $revision = (int)($s['revision_count'] ?? 0);
        }

        echo json_encode([
            'success' => true,
            'project' => [
                'id' => $project['id'],
                'name' => $project['name'],
                'description' => $project['description'],
                'status' => $project['status'],
                'start_date' => $project['start_date'],
                'deadline' => $project['deadline'],
                'cover_image' => $project['cover_image'],
                'total_files' => $totalFiles,
                'approved_files' => $approved,
                'pending_files' => $pending,
                'revision_files' => $revision
            ],
            'files' => $files,
            'total_files' => $totalFiles,
            'page' => $page,
            'per_page' => $perPage
        ]);
        break;

    // ============================================
    // FILES
    // ============================================

    case 'client_file_preview':
        $company = $client['company'];
        $fileId = $_GET['file_id'] ?? '';

        if (!$fileId) {
            echo json_encode(['error' => 'معرّف الملف مطلوب']);
            break;
        }

        // Get file
        $fileResult = dbRequest('GET', '/pyra_project_files?id=eq.' . rawurlencode($fileId) . '&limit=1');
        if ($fileResult['httpCode'] !== 200 || empty($fileResult['data'])) {
            echo json_encode(['error' => 'الملف غير موجود']);
            break;
        }

        $file = $fileResult['data'][0];

        // Verify project belongs to client's company
        $proj = dbRequest('GET', '/pyra_projects?id=eq.' . rawurlencode($file['project_id'])
            . '&client_company=eq.' . rawurlencode($company) . '&limit=1');
        if ($proj['httpCode'] !== 200 || empty($proj['data'])) {
            echo json_encode(['error' => 'الملف غير موجود']);
            break;
        }

        $project = $proj['data'][0];

        // Get approval status
        $approval = dbRequest('GET', '/pyra_file_approvals?file_id=eq.' . rawurlencode($fileId)
            . '&client_id=eq.' . rawurlencode($client['id']) . '&limit=1');
        $approvalData = null;
        if ($approval['httpCode'] === 200 && !empty($approval['data'])) {
            $a = $approval['data'][0];
            $approvalData = [
                'id' => $a['id'],
                'status' => $a['status'],
                'comment' => $a['comment'],
                'updated_at' => $a['updated_at']
            ];
        }

        $publicUrl = SUPABASE_URL . '/storage/v1/object/public/' . SUPABASE_BUCKET . '/' . $file['file_path'];

        echo json_encode([
            'success' => true,
            'file' => [
                'id' => $file['id'],
                'file_name' => $file['file_name'],
                'file_path' => $file['file_path'],
                'file_size' => (int)$file['file_size'],
                'mime_type' => $file['mime_type'],
                'category' => $file['category'],
                'version' => (int)$file['version'],
                'needs_approval' => (bool)$file['needs_approval'],
                'uploaded_by' => $file['uploaded_by'],
                'created_at' => $file['created_at'],
                'public_url' => $publicUrl,
                'project' => [
                    'id' => $project['id'],
                    'name' => $project['name']
                ],
                'approval' => $approvalData
            ]
        ]);
        break;

    case 'client_download':
        $company = $client['company'];
        $fileId = $_GET['file_id'] ?? '';

        if (!$fileId) {
            header('Content-Type: application/json');
            echo json_encode(['error' => 'معرّف الملف مطلوب']);
            break;
        }

        $fileResult = dbRequest('GET', '/pyra_project_files?id=eq.' . rawurlencode($fileId) . '&limit=1');
        if ($fileResult['httpCode'] !== 200 || empty($fileResult['data'])) {
            header('Content-Type: application/json');
            echo json_encode(['error' => 'الملف غير موجود']);
            break;
        }

        $file = $fileResult['data'][0];

        $proj = dbRequest('GET', '/pyra_projects?id=eq.' . rawurlencode($file['project_id'])
            . '&client_company=eq.' . rawurlencode($company) . '&limit=1');
        if ($proj['httpCode'] !== 200 || empty($proj['data'])) {
            header('Content-Type: application/json');
            echo json_encode(['error' => 'الملف غير موجود']);
            break;
        }

        $publicUrl = SUPABASE_URL . '/storage/v1/object/public/' . SUPABASE_BUCKET . '/' . $file['file_path'];
        header('Location: ' . $publicUrl);
        exit;

    // ============================================
    // APPROVALS
    // ============================================

    case 'client_approve_file':
        if ($client['role'] !== 'primary') {
            http_response_code(403);
            echo json_encode(['error' => 'ليس لديك صلاحية الموافقة']);
            break;
        }

        $input = json_decode(file_get_contents('php://input'), true);
        $fileId = trim($input['file_id'] ?? '');

        if (!$fileId) {
            echo json_encode(['error' => 'معرّف الملف مطلوب']);
            break;
        }

        // Verify file exists and belongs to client's company project
        $fileResult = dbRequest('GET', '/pyra_project_files?id=eq.' . rawurlencode($fileId) . '&limit=1');
        if ($fileResult['httpCode'] !== 200 || empty($fileResult['data'])) {
            echo json_encode(['error' => 'الملف غير موجود']);
            break;
        }
        $file = $fileResult['data'][0];

        $proj = dbRequest('GET', '/pyra_projects?id=eq.' . rawurlencode($file['project_id'])
            . '&client_company=eq.' . rawurlencode($client['company']) . '&limit=1');
        if ($proj['httpCode'] !== 200 || empty($proj['data'])) {
            echo json_encode(['error' => 'الملف غير موجود']);
            break;
        }

        // Upsert approval
        $existing = dbRequest('GET', '/pyra_file_approvals?file_id=eq.' . rawurlencode($fileId)
            . '&client_id=eq.' . rawurlencode($client['id']) . '&limit=1');

        if ($existing['httpCode'] === 200 && !empty($existing['data'])) {
            dbRequest('PATCH', '/pyra_file_approvals?id=eq.' . rawurlencode($existing['data'][0]['id']), [
                'status' => 'approved',
                'comment' => null,
                'updated_at' => date('c')
            ]);
        } else {
            dbRequest('POST', '/pyra_file_approvals', [
                'id' => generatePortalId('fa'),
                'file_id' => $fileId,
                'client_id' => $client['id'],
                'status' => 'approved'
            ], ['Prefer: return=representation']);
        }

        // Notify team
        $admins = dbRequest('GET', '/pyra_users?role=eq.admin&select=username');
        if ($admins['httpCode'] === 200 && is_array($admins['data'])) {
            foreach ($admins['data'] as $admin) {
                createNotification(
                    $admin['username'],
                    'review',
                    'العميل ' . htmlspecialchars($client['name']) . ' وافق على ' . htmlspecialchars($file['file_name']),
                    'تمت الموافقة على الملف في مشروع ' . htmlspecialchars($proj['data'][0]['name']),
                    $file['file_path']
                );
            }
        }

        echo json_encode(['success' => true, 'status' => 'approved']);
        break;

    case 'client_request_revision':
        if ($client['role'] !== 'primary') {
            http_response_code(403);
            echo json_encode(['error' => 'ليس لديك صلاحية طلب التعديل']);
            break;
        }

        $input = json_decode(file_get_contents('php://input'), true);
        $fileId = trim($input['file_id'] ?? '');
        $comment = trim($input['comment'] ?? '');

        if (!$fileId) {
            echo json_encode(['error' => 'معرّف الملف مطلوب']);
            break;
        }
        if (mb_strlen($comment) < 10) {
            echo json_encode(['error' => 'التعليق يجب أن يكون 10 حروف على الأقل']);
            break;
        }

        $fileResult = dbRequest('GET', '/pyra_project_files?id=eq.' . rawurlencode($fileId) . '&limit=1');
        if ($fileResult['httpCode'] !== 200 || empty($fileResult['data'])) {
            echo json_encode(['error' => 'الملف غير موجود']);
            break;
        }
        $file = $fileResult['data'][0];

        $proj = dbRequest('GET', '/pyra_projects?id=eq.' . rawurlencode($file['project_id'])
            . '&client_company=eq.' . rawurlencode($client['company']) . '&limit=1');
        if ($proj['httpCode'] !== 200 || empty($proj['data'])) {
            echo json_encode(['error' => 'الملف غير موجود']);
            break;
        }

        // Upsert approval
        $existing = dbRequest('GET', '/pyra_file_approvals?file_id=eq.' . rawurlencode($fileId)
            . '&client_id=eq.' . rawurlencode($client['id']) . '&limit=1');

        $sanitizedComment = htmlspecialchars($comment);

        if ($existing['httpCode'] === 200 && !empty($existing['data'])) {
            dbRequest('PATCH', '/pyra_file_approvals?id=eq.' . rawurlencode($existing['data'][0]['id']), [
                'status' => 'revision_requested',
                'comment' => $sanitizedComment,
                'updated_at' => date('c')
            ]);
        } else {
            dbRequest('POST', '/pyra_file_approvals', [
                'id' => generatePortalId('fa'),
                'file_id' => $fileId,
                'client_id' => $client['id'],
                'status' => 'revision_requested',
                'comment' => $sanitizedComment
            ], ['Prefer: return=representation']);
        }

        // Notify team
        $admins = dbRequest('GET', '/pyra_users?role=eq.admin&select=username');
        if ($admins['httpCode'] === 200 && is_array($admins['data'])) {
            foreach ($admins['data'] as $admin) {
                createNotification(
                    $admin['username'],
                    'review',
                    'العميل ' . htmlspecialchars($client['name']) . ' طلب تعديل على ' . htmlspecialchars($file['file_name']),
                    $sanitizedComment,
                    $file['file_path']
                );
            }
        }

        echo json_encode(['success' => true, 'status' => 'revision_requested']);
        break;

    // ============================================
    // COMMENTS
    // ============================================

    case 'client_get_comments':
        $company = $client['company'];
        $projectId = $_GET['project_id'] ?? '';
        $fileId = $_GET['file_id'] ?? '';

        if (!$projectId) {
            echo json_encode(['error' => 'معرّف المشروع مطلوب']);
            break;
        }

        // Verify project
        $proj = dbRequest('GET', '/pyra_projects?id=eq.' . rawurlencode($projectId)
            . '&client_company=eq.' . rawurlencode($company) . '&limit=1');
        if ($proj['httpCode'] !== 200 || empty($proj['data'])) {
            echo json_encode(['error' => 'المشروع غير موجود']);
            break;
        }

        $endpoint = '/pyra_client_comments?project_id=eq.' . rawurlencode($projectId)
            . '&order=created_at.asc';
        if ($fileId) {
            $endpoint .= '&file_id=eq.' . rawurlencode($fileId);
        }

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

        // Mark team comments as read by client
        dbRequest('PATCH', '/pyra_client_comments?project_id=eq.' . rawurlencode($projectId)
            . '&author_type=eq.team&is_read_by_client=eq.false', [
            'is_read_by_client' => true
        ]);

        echo json_encode(['success' => true, 'comments' => $threaded]);
        break;

    case 'client_add_comment':
        $input = json_decode(file_get_contents('php://input'), true);
        $projectId = trim($input['project_id'] ?? '');
        $fileId = trim($input['file_id'] ?? '') ?: null;
        $text = trim($input['text'] ?? '');
        $parentId = trim($input['parent_id'] ?? '') ?: null;

        if (!$projectId) {
            echo json_encode(['error' => 'معرّف المشروع مطلوب']);
            break;
        }
        if (mb_strlen($text) < 3) {
            echo json_encode(['error' => 'التعليق يجب أن يكون 3 حروف على الأقل']);
            break;
        }

        // Verify project
        $proj = dbRequest('GET', '/pyra_projects?id=eq.' . rawurlencode($projectId)
            . '&client_company=eq.' . rawurlencode($client['company']) . '&limit=1');
        if ($proj['httpCode'] !== 200 || empty($proj['data'])) {
            echo json_encode(['error' => 'المشروع غير موجود']);
            break;
        }

        $commentId = generatePortalId('cc');
        $sanitizedText = htmlspecialchars($text);

        $record = [
            'id' => $commentId,
            'project_id' => $projectId,
            'file_id' => $fileId,
            'author_type' => 'client',
            'author_id' => $client['id'],
            'author_name' => $client['name'],
            'text' => $sanitizedText,
            'parent_id' => $parentId,
            'is_read_by_client' => true,
            'is_read_by_team' => false
        ];

        $result = dbRequest('POST', '/pyra_client_comments', $record, ['Prefer: return=representation']);

        if ($result['httpCode'] === 201) {
            // Notify team
            $admins = dbRequest('GET', '/pyra_users?role=eq.admin&select=username');
            if ($admins['httpCode'] === 200 && is_array($admins['data'])) {
                foreach ($admins['data'] as $admin) {
                    createNotification(
                        $admin['username'],
                        'review',
                        'تعليق جديد من العميل ' . htmlspecialchars($client['name']),
                        mb_substr($sanitizedText, 0, 100),
                        ''
                    );
                }
            }

            $comment = (is_array($result['data']) && !empty($result['data'])) ? $result['data'][0] : $record;
            echo json_encode(['success' => true, 'comment' => $comment]);
        } else {
            echo json_encode(['error' => 'فشل في إضافة التعليق']);
        }
        break;

    // ============================================
    // NOTIFICATIONS
    // ============================================

    case 'client_unread_count':
        $clientId = $client['id'];
        $result = dbRequest('GET', '/pyra_client_notifications?client_id=eq.' . rawurlencode($clientId)
            . '&is_read=eq.false&select=id');
        $count = ($result['httpCode'] === 200 && is_array($result['data'])) ? count($result['data']) : 0;
        echo json_encode(['count' => $count]);
        break;

    case 'client_notifications':
        $clientId = $client['id'];
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = 20;
        $offset = ($page - 1) * $perPage;

        $result = dbRequest('GET', '/pyra_client_notifications?client_id=eq.' . rawurlencode($clientId)
            . '&order=created_at.desc&limit=' . $perPage . '&offset=' . $offset);
        $list = ($result['httpCode'] === 200 && is_array($result['data'])) ? $result['data'] : [];

        $countResult = dbRequest('GET', '/pyra_client_notifications?client_id=eq.' . rawurlencode($clientId) . '&select=id');
        $total = ($countResult['httpCode'] === 200 && is_array($countResult['data'])) ? count($countResult['data']) : 0;

        echo json_encode([
            'success' => true,
            'notifications' => $list,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage
        ]);
        break;

    case 'client_mark_notif_read':
        $input = json_decode(file_get_contents('php://input'), true);
        $notifId = trim($input['notification_id'] ?? '');

        if (!$notifId) {
            echo json_encode(['error' => 'معرّف الإشعار مطلوب']);
            break;
        }

        // Verify it belongs to this client
        dbRequest('PATCH', '/pyra_client_notifications?id=eq.' . rawurlencode($notifId)
            . '&client_id=eq.' . rawurlencode($client['id']), [
            'is_read' => true
        ]);

        echo json_encode(['success' => true]);
        break;

    case 'client_mark_all_read':
        dbRequest('PATCH', '/pyra_client_notifications?client_id=eq.' . rawurlencode($client['id'])
            . '&is_read=eq.false', [
            'is_read' => true
        ]);

        echo json_encode(['success' => true]);
        break;

    // ============================================
    // PROFILE
    // ============================================

    case 'client_profile':
        echo json_encode([
            'success' => true,
            'client' => [
                'id' => $client['id'],
                'name' => $client['name'],
                'email' => $client['email'],
                'phone' => $client['phone'],
                'company' => $client['company'],
                'role' => $client['role'],
                'avatar_url' => $client['avatar_url'],
                'language' => $client['language']
            ]
        ]);
        break;

    case 'client_update_profile':
        $input = json_decode(file_get_contents('php://input'), true);
        $update = [];

        if (isset($input['name'])) {
            $name = trim($input['name']);
            if (mb_strlen($name) < 2 || mb_strlen($name) > 100) {
                echo json_encode(['error' => 'الاسم يجب أن يكون بين 2 و100 حرف']);
                break;
            }
            $update['name'] = htmlspecialchars($name);
        }

        if (isset($input['phone'])) {
            $phone = trim($input['phone']);
            if (mb_strlen($phone) > 30) {
                echo json_encode(['error' => 'رقم الهاتف طويل جداً']);
                break;
            }
            $update['phone'] = htmlspecialchars($phone);
        }

        if (isset($input['language'])) {
            $lang = trim($input['language']);
            if (!in_array($lang, ['ar', 'en'])) {
                echo json_encode(['error' => 'اللغة غير مدعومة']);
                break;
            }
            $update['language'] = $lang;
        }

        if (empty($update)) {
            echo json_encode(['error' => 'لا توجد بيانات للتحديث']);
            break;
        }

        $update['updated_at'] = date('c');

        $result = dbRequest('PATCH', '/pyra_clients?id=eq.' . rawurlencode($client['id']), $update);
        if ($result['httpCode'] === 200 || $result['httpCode'] === 204) {
            if (isset($update['name'])) $_SESSION['client_name'] = $update['name'];
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['error' => 'فشل في تحديث البيانات']);
        }
        break;

    case 'client_change_password':
        $input = json_decode(file_get_contents('php://input'), true);
        $currentPassword = $input['current_password'] ?? '';
        $newPassword = $input['new_password'] ?? '';

        if (!$currentPassword || !$newPassword) {
            echo json_encode(['error' => 'كلمة المرور الحالية والجديدة مطلوبتان']);
            break;
        }
        if (strlen($newPassword) < 8) {
            echo json_encode(['error' => 'كلمة المرور الجديدة يجب أن تكون 8 حروف على الأقل']);
            break;
        }

        // Get current hash
        $clientRow = dbRequest('GET', '/pyra_clients?id=eq.' . rawurlencode($client['id']) . '&select=password_hash&limit=1');
        if ($clientRow['httpCode'] !== 200 || empty($clientRow['data'])) {
            echo json_encode(['error' => 'خطأ في النظام']);
            break;
        }

        if (!password_verify($currentPassword, $clientRow['data'][0]['password_hash'])) {
            echo json_encode(['error' => 'كلمة المرور الحالية غير صحيحة']);
            break;
        }

        $hash = password_hash($newPassword, PASSWORD_BCRYPT);
        dbRequest('PATCH', '/pyra_clients?id=eq.' . rawurlencode($client['id']), [
            'password_hash' => $hash,
            'updated_at' => date('c')
        ]);

        echo json_encode(['success' => true]);
        break;

    // ============================================
    // SETTINGS (public)
    // ============================================

    case 'getPublicSettings':
        echo json_encode(getPublicSettings());
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'عملية غير معروفة']);
        break;
    }
    exit;
}

// If no action, serve the portal HTML page (Phase 3)
$publicSettings = function_exists('getPublicSettings') ? getPublicSettings() : ['app_name' => 'Pyra Workspace', 'app_logo_url' => '', 'primary_color' => '#8b5cf6'];
$isClient = isClientLoggedIn();
$clientData = $isClient ? getClientData() : null;
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($publicSettings['app_name'] ?? 'Pyra Workspace') ?> — بوابة العميل</title>
</head>
<body>
    <h1>بوابة العميل — قيد الإنشاء (Phase 3)</h1>
    <p>Portal API is active. Use <code>?action=client_session</code> to test.</p>
    <script>
        window.PORTAL_CONFIG = {
            auth: <?= $isClient ? 'true' : 'false' ?>,
            client: <?= $isClient ? json_encode($clientData) : 'null' ?>,
            csrf_token: '<?= $_SESSION['client_csrf_token'] ?? '' ?>',
            settings: <?= json_encode($publicSettings) ?>
        };
    </script>
</body>
</html>
