<?php
/**
 * Phase 4: Seed Test Data for Client Portal
 * Run once: php portal/seed-test-data.php  OR  visit /portal/seed-test-data.php
 *
 * Creates:
 * - 1 test client (demo@pyramedia.com / password123)
 * - 3 projects (active, in_progress, review)
 * - 12 files across projects (image, pdf, video, audio, document)
 * - 5 approval records (pending, approved, revision_requested)
 * - 5 notifications (different types)
 * - 3 comments (mix of team and client)
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: text/html; charset=utf-8');
echo '<pre style="font-family:monospace;background:#111;color:#0f0;padding:20px;direction:ltr">';
echo "🌱 Seeding Portal Test Data...\n\n";

$company = 'Pyramedia Productions';
$now = date('c');
$errors = [];
$success = [];

// ============================================
// 1. Test Client
// ============================================
echo "── 1. Client ──\n";

$clientId = 'c_' . time() . '_a1b2';
$clientEmail = 'demo@pyramedia.com';

// Check if client exists
$existing = dbRequest('GET', '/pyra_clients?email=eq.' . rawurlencode($clientEmail) . '&limit=1');
if ($existing['httpCode'] === 200 && !empty($existing['data'])) {
    $clientId = $existing['data'][0]['id'];
    echo "✓ Client already exists: {$clientId}\n";
    $success[] = 'Client exists';
} else {
    $hash = password_hash('password123', PASSWORD_BCRYPT);
    $result = dbRequest('POST', '/pyra_clients', [
        'id' => $clientId,
        'name' => 'أحمد الخالدي',
        'email' => $clientEmail,
        'password_hash' => $hash,
        'company' => $company,
        'phone' => '+966501234567',
        'role' => 'primary',
        'status' => 'active',
        'language' => 'ar',
        'created_by' => 'admin',
        'last_login_at' => date('c', strtotime('-3 days'))
    ], ['Prefer: return=representation']);

    if ($result['httpCode'] === 201) {
        echo "✓ Client created: {$clientId} (demo@pyramedia.com / password123)\n";
        $success[] = 'Client created';
    } else {
        echo "✗ Failed to create client: " . json_encode($result['data']) . "\n";
        $errors[] = 'Client creation failed';
    }
}

// ============================================
// 2. Projects (3 different statuses)
// ============================================
echo "\n── 2. Projects ──\n";

$projects = [
    [
        'id' => 'prj_' . time() . '_sm01',
        'name' => 'Social Media Campaign Q1',
        'description' => 'حملة سوشل ميديا شاملة للربع الأول 2026 — تشمل تصاميم إنستغرام وتويتر وفيسبوك مع فيديوهات ريلز وستوريز',
        'status' => 'active',
        'start_date' => '2026-01-01',
        'deadline' => '2026-03-31',
        'storage_path' => 'projects/pyramedia/social-campaign-q1'
    ],
    [
        'id' => 'prj_' . (time() + 1) . '_bi02',
        'name' => 'Brand Identity Redesign',
        'description' => 'إعادة تصميم الهوية البصرية الكاملة — لوجو جديد، ألوان، خطوط، بطاقات أعمال، ورق رسمي',
        'status' => 'in_progress',
        'start_date' => '2026-01-15',
        'deadline' => '2026-04-15',
        'storage_path' => 'projects/pyramedia/brand-identity'
    ],
    [
        'id' => 'prj_' . (time() + 2) . '_wr03',
        'name' => 'Website Redesign',
        'description' => 'إعادة تصميم الموقع الرسمي — واجهة مستخدم حديثة مع تجربة مستخدم محسّنة وتصميم متجاوب',
        'status' => 'review',
        'start_date' => '2025-12-01',
        'deadline' => '2026-02-28',
        'storage_path' => 'projects/pyramedia/website-redesign'
    ]
];

$projectIds = [];
foreach ($projects as $p) {
    // Check if project exists
    $existing = dbRequest('GET', '/pyra_projects?name=eq.' . rawurlencode($p['name']) . '&client_company=eq.' . rawurlencode($company) . '&limit=1');
    if ($existing['httpCode'] === 200 && !empty($existing['data'])) {
        $projectIds[] = $existing['data'][0]['id'];
        echo "✓ Project exists: {$existing['data'][0]['id']} ({$p['name']})\n";
        continue;
    }

    $result = dbRequest('POST', '/pyra_projects', array_merge($p, [
        'client_company' => $company,
        'created_by' => 'admin',
        'created_at' => $now,
        'updated_at' => $now
    ]), ['Prefer: return=representation']);

    if ($result['httpCode'] === 201) {
        $projectIds[] = $p['id'];
        echo "✓ Project created: {$p['id']} ({$p['name']}) [{$p['status']}]\n";
        $success[] = "Project: {$p['name']}";
    } else {
        echo "✗ Failed: {$p['name']} — " . json_encode($result['data']) . "\n";
        $errors[] = "Project: {$p['name']}";
    }
}

// Re-fetch project IDs in case they already existed
$allProjects = dbRequest('GET', '/pyra_projects?client_company=eq.' . rawurlencode($company) . '&status=not.in.(draft,archived)&order=created_at.desc&limit=5');
$projectIds = [];
if ($allProjects['httpCode'] === 200 && is_array($allProjects['data'])) {
    $projectIds = array_column($allProjects['data'], 'id');
}

// ============================================
// 3. Files (12 across projects)
// ============================================
echo "\n── 3. Files ──\n";

$files = [
    // Project 1: Social Media Campaign
    ['project_idx' => 0, 'file_name' => 'instagram-post-01.png',   'mime_type' => 'image/png',      'file_size' => 2400000,  'category' => 'design',   'needs_approval' => true],
    ['project_idx' => 0, 'file_name' => 'twitter-banner.jpg',      'mime_type' => 'image/jpeg',     'file_size' => 1800000,  'category' => 'design',   'needs_approval' => true],
    ['project_idx' => 0, 'file_name' => 'reels-intro.mp4',         'mime_type' => 'video/mp4',      'file_size' => 25600000, 'category' => 'video',    'needs_approval' => true],
    ['project_idx' => 0, 'file_name' => 'content-calendar.pdf',    'mime_type' => 'application/pdf','file_size' => 1200000,  'category' => 'document', 'needs_approval' => false],
    ['project_idx' => 0, 'file_name' => 'story-template.psd',      'mime_type' => 'image/vnd.adobe.photoshop', 'file_size' => 45000000, 'category' => 'design', 'needs_approval' => false],

    // Project 2: Brand Identity
    ['project_idx' => 1, 'file_name' => 'logo-final-v3.png',       'mime_type' => 'image/png',      'file_size' => 850000,   'category' => 'design',   'needs_approval' => true],
    ['project_idx' => 1, 'file_name' => 'brand-guidelines.pdf',    'mime_type' => 'application/pdf','file_size' => 5100000,  'category' => 'document', 'needs_approval' => true],
    ['project_idx' => 1, 'file_name' => 'business-card-mockup.png','mime_type' => 'image/png',      'file_size' => 3200000,  'category' => 'design',   'needs_approval' => false],
    ['project_idx' => 1, 'file_name' => 'audio-jingle.mp3',        'mime_type' => 'audio/mpeg',     'file_size' => 1800000,  'category' => 'audio',    'needs_approval' => true],

    // Project 3: Website Redesign
    ['project_idx' => 2, 'file_name' => 'homepage-wireframe.pdf',  'mime_type' => 'application/pdf','file_size' => 4200000,  'category' => 'document', 'needs_approval' => false],
    ['project_idx' => 2, 'file_name' => 'hero-banner.jpg',         'mime_type' => 'image/jpeg',     'file_size' => 2300000,  'category' => 'design',   'needs_approval' => true],
    ['project_idx' => 2, 'file_name' => 'promo-video.mp4',         'mime_type' => 'video/mp4',      'file_size' => 52000000, 'category' => 'video',    'needs_approval' => false],
];

$fileIds = [];
$fileIdxCounter = 0;
foreach ($files as $f) {
    if (!isset($projectIds[$f['project_idx']])) continue;
    $projectId = $projectIds[$f['project_idx']];

    // Check if file exists
    $existing = dbRequest('GET', '/pyra_project_files?file_name=eq.' . rawurlencode($f['file_name']) . '&project_id=eq.' . rawurlencode($projectId) . '&limit=1');
    if ($existing['httpCode'] === 200 && !empty($existing['data'])) {
        $fileIds[] = $existing['data'][0]['id'];
        echo "✓ File exists: {$f['file_name']}\n";
        $fileIdxCounter++;
        continue;
    }

    $fileId = 'pf_' . (time() + $fileIdxCounter) . '_' . substr(bin2hex(random_bytes(2)), 0, 4);
    $storagePath = "projects/pyramedia/test/{$f['file_name']}";

    $result = dbRequest('POST', '/pyra_project_files', [
        'id' => $fileId,
        'project_id' => $projectId,
        'file_name' => $f['file_name'],
        'file_path' => $storagePath,
        'file_size' => $f['file_size'],
        'mime_type' => $f['mime_type'],
        'category' => $f['category'],
        'version' => 1,
        'needs_approval' => $f['needs_approval'],
        'uploaded_by' => 'admin',
        'created_at' => date('c', time() - rand(86400, 604800))
    ], ['Prefer: return=representation']);

    if ($result['httpCode'] === 201) {
        $fileIds[] = $fileId;
        echo "✓ File created: {$f['file_name']} ({$f['category']}, " . round($f['file_size']/1024/1024, 1) . " MB)\n";
        $success[] = "File: {$f['file_name']}";
    } else {
        echo "✗ Failed: {$f['file_name']} — " . json_encode($result['data']) . "\n";
        $errors[] = "File: {$f['file_name']}";
    }
    $fileIdxCounter++;
}

// Re-fetch file IDs for approvals
$allFileIds = [];
foreach ($projectIds as $pid) {
    $filesResult = dbRequest('GET', '/pyra_project_files?project_id=eq.' . rawurlencode($pid) . '&needs_approval=eq.true&select=id,file_name');
    if ($filesResult['httpCode'] === 200 && is_array($filesResult['data'])) {
        foreach ($filesResult['data'] as $fRow) {
            $allFileIds[] = $fRow;
        }
    }
}

// ============================================
// 4. Approvals (5 records)
// ============================================
echo "\n── 4. Approvals ──\n";

$approvalStatuses = ['pending', 'approved', 'approved', 'revision_requested', 'pending'];
$approvalComments = [
    null,
    null,
    null,
    'النص أصغر من اللازم — يرجى تكبير حجم الخط وتعديل الألوان',
    null
];

for ($i = 0; $i < min(5, count($allFileIds)); $i++) {
    $fRow = $allFileIds[$i];

    // Check if approval exists
    $existing = dbRequest('GET', '/pyra_file_approvals?file_id=eq.' . rawurlencode($fRow['id']) . '&client_id=eq.' . rawurlencode($clientId) . '&limit=1');
    if ($existing['httpCode'] === 200 && !empty($existing['data'])) {
        echo "✓ Approval exists for: {$fRow['file_name']}\n";
        continue;
    }

    $approvalId = 'fa_' . (time() + $i) . '_' . substr(bin2hex(random_bytes(2)), 0, 4);
    $status = $approvalStatuses[$i] ?? 'pending';
    $comment = $approvalComments[$i] ?? null;

    $result = dbRequest('POST', '/pyra_file_approvals', [
        'id' => $approvalId,
        'file_id' => $fRow['id'],
        'client_id' => $clientId,
        'status' => $status,
        'comment' => $comment,
        'created_at' => date('c', time() - rand(3600, 259200)),
        'updated_at' => date('c', time() - rand(0, 3600))
    ], ['Prefer: return=representation']);

    if ($result['httpCode'] === 201) {
        echo "✓ Approval created: {$fRow['file_name']} [{$status}]\n";
        $success[] = "Approval: {$fRow['file_name']}";
    } else {
        echo "✗ Failed: {$fRow['file_name']} — " . json_encode($result['data']) . "\n";
        $errors[] = "Approval: {$fRow['file_name']}";
    }
}

// ============================================
// 5. Notifications (5 different types)
// ============================================
echo "\n── 5. Notifications ──\n";

$notifications = [
    ['type' => 'new_file',       'title' => 'ملف جديد: reels-intro.mp4',              'message' => 'تم رفع ملف فيديو جديد في مشروع Social Media Campaign'],
    ['type' => 'status_change',  'title' => 'تحديث حالة: Website Redesign',            'message' => 'مشروعك "Website Redesign" انتقل إلى مرحلة المراجعة'],
    ['type' => 'new_file',       'title' => 'ملف جديد: logo-final-v3.png',             'message' => 'تم رفع تصميم اللوجو النهائي في مشروع Brand Identity'],
    ['type' => 'approval_reset', 'title' => 'تحديث ملف: brand-guidelines.pdf',         'message' => 'تم تحديث ملف دليل الهوية — يرجى المراجعة والموافقة'],
    ['type' => 'comment',        'title' => 'رد جديد من الفريق',                       'message' => 'فريق العمل رد على تعليقك في مشروع Brand Identity'],
];

// Check existing notifications count
$existingNotifs = dbRequest('GET', '/pyra_client_notifications?client_id=eq.' . rawurlencode($clientId) . '&select=id&limit=5');
$existingCount = ($existingNotifs['httpCode'] === 200 && is_array($existingNotifs['data'])) ? count($existingNotifs['data']) : 0;

if ($existingCount >= 5) {
    echo "✓ Already have {$existingCount} notifications\n";
} else {
    foreach ($notifications as $idx => $n) {
        $notifId = 'cn_' . (time() + $idx) . '_' . substr(bin2hex(random_bytes(2)), 0, 4);

        $result = dbRequest('POST', '/pyra_client_notifications', [
            'id' => $notifId,
            'client_id' => $clientId,
            'type' => $n['type'],
            'title' => $n['title'],
            'message' => $n['message'],
            'is_read' => ($idx >= 3),
            'created_at' => date('c', time() - ($idx * 43200))
        ], ['Prefer: return=representation']);

        if ($result['httpCode'] === 201) {
            echo "✓ Notification: {$n['title']} [" . ($idx >= 3 ? 'read' : 'unread') . "]\n";
            $success[] = "Notification: {$n['type']}";
        } else {
            echo "✗ Failed: {$n['title']} — " . json_encode($result['data']) . "\n";
            $errors[] = "Notification: {$n['type']}";
        }
    }
}

// ============================================
// 6. Comments (3 — mix of team and client)
// ============================================
echo "\n── 6. Comments ──\n";

$firstProjectId = $projectIds[0] ?? null;
if ($firstProjectId) {
    $existingComments = dbRequest('GET', '/pyra_client_comments?project_id=eq.' . rawurlencode($firstProjectId) . '&select=id&limit=3');
    $existingCommentsCount = ($existingComments['httpCode'] === 200 && is_array($existingComments['data'])) ? count($existingComments['data']) : 0;

    if ($existingCommentsCount >= 3) {
        echo "✓ Already have {$existingCommentsCount} comments\n";
    } else {
        $comments = [
            [
                'author_type' => 'team',
                'author_id' => 'admin',
                'author_name' => 'سارة — مديرة المشاريع',
                'text' => 'مرحباً أحمد! تم رفع التصاميم الأولية لحملة السوشل ميديا. يرجى مراجعتها وإبداء ملاحظاتك',
                'is_read_by_client' => false,
                'is_read_by_team' => true
            ],
            [
                'author_type' => 'client',
                'author_id' => $clientId,
                'author_name' => 'أحمد الخالدي',
                'text' => 'شكراً سارة! التصاميم ممتازة. عندي ملاحظة بسيطة على ألوان البوست الأول — ممكن نجرب اللون الأزرق بدل الأخضر؟',
                'is_read_by_client' => true,
                'is_read_by_team' => false
            ],
            [
                'author_type' => 'team',
                'author_id' => 'admin',
                'author_name' => 'سارة — مديرة المشاريع',
                'text' => 'تم التعديل! راح نرفع النسخة المعدلة خلال ساعة',
                'is_read_by_client' => false,
                'is_read_by_team' => true
            ]
        ];

        foreach ($comments as $idx => $c) {
            $commentId = 'cc_' . (time() + $idx) . '_' . substr(bin2hex(random_bytes(2)), 0, 4);

            $result = dbRequest('POST', '/pyra_client_comments', array_merge($c, [
                'id' => $commentId,
                'project_id' => $firstProjectId,
                'created_at' => date('c', time() - (($idx + 1) * 7200))
            ]), ['Prefer: return=representation']);

            if ($result['httpCode'] === 201) {
                echo "✓ Comment ({$c['author_type']}): " . mb_substr($c['text'], 0, 50) . "...\n";
                $success[] = "Comment by {$c['author_type']}";
            } else {
                echo "✗ Failed comment — " . json_encode($result['data']) . "\n";
                $errors[] = "Comment by {$c['author_type']}";
            }
        }
    }
}

// ============================================
// Summary
// ============================================
echo "\n══════════════════════════════════\n";
echo "✅ Success: " . count($success) . "\n";
echo "❌ Errors: " . count($errors) . "\n";
if (!empty($errors)) {
    echo "\nFailed items:\n";
    foreach ($errors as $e) echo "  • {$e}\n";
}
echo "\n🔑 Login credentials:\n";
echo "   Email: demo@pyramedia.com\n";
echo "   Password: password123\n";
echo "\n══════════════════════════════════\n";
echo '</pre>';
