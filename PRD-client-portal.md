# PRD — Client Portal for Pyra Workspace

> **Version:** 1.0  
> **Date:** 2026-02-14  
> **Author:** Bayra AI (Software Architect)  
> **Target:** Claude Code (AI Developer)  
> **Status:** Ready for Implementation

---

## 1. ملخص تنفيذي (Executive Summary)

### إيش نبني؟
بوابة عميل (Client Portal) كاملة لـ Pyra Workspace — نظام يسمح لعملاء Pyramedia بالدخول على بوابتهم الخاصة، مشاهدة مشاريعهم، استعراض الملفات، الموافقة عليها أو طلب تعديلات، والتواصل مع الفريق.

### ليش؟
حالياً العملاء يستقبلون الملفات عبر روابط مشاركة (share links) بدون context. البوابة توفر تجربة احترافية: dashboard مخصص، نظام موافقات رسمي، تواصل مباشر، وإشعارات بريدية.

### لمين؟
- **العملاء:** يدخلون يشوفون مشاريعهم ويتفاعلون مع الملفات
- **فريق Pyramedia:** يديرون المشاريع والملفات ويستقبلون feedback من العملاء
- **Admin:** يدير حسابات العملاء والصلاحيات

### المدة المتوقعة
- **Phase 1 (MVP):** 3-4 أيام — Database + Auth + Dashboard + Projects + Files
- **Phase 2:** 2-3 أيام — Approvals + Comments + Notifications
- **Phase 3:** 1-2 أيام — Email + Profile + Polish

### المخاطر
| المخاطرة | الاحتمال | الحل |
|----------|---------|------|
| تعارض sessions بين الفريق والعملاء | متوسط | session keys مختلفة (`client_id` vs `user`) |
| ثقل api.php مع endpoints جديدة | منخفض | الـ switch-case pattern يتحمل |
| أمان — عميل يشوف بيانات عميل ثاني | عالي | كل query يفلتر بـ `client_id` |

---

## 2. المتطلبات الوظيفية (Functional Requirements)

### 2.1 Client Authentication

#### 2.1.1 جدول جديد: `pyra_clients`

```sql
CREATE TABLE pyra_clients (
    id VARCHAR(20) PRIMARY KEY,                    -- مثال: c_1707926400_a3f2
    name VARCHAR(100) NOT NULL,                     -- اسم جهة الاتصال
    email VARCHAR(150) NOT NULL UNIQUE,             -- البريد الإلكتروني (login identifier)
    password_hash VARCHAR(255) NOT NULL,            -- bcrypt hash
    company VARCHAR(150) NOT NULL,                  -- اسم الشركة/العميل
    phone VARCHAR(30) DEFAULT NULL,                 -- رقم الهاتف
    avatar_url TEXT DEFAULT NULL,                   -- رابط صورة من Supabase Storage
    role VARCHAR(20) DEFAULT 'primary' CHECK (role IN ('primary', 'billing', 'viewer')),
    status VARCHAR(20) DEFAULT 'active' CHECK (status IN ('active', 'inactive', 'suspended')),
    language VARCHAR(5) DEFAULT 'ar',               -- ar أو en
    last_login_at TIMESTAMPTZ DEFAULT NULL,
    created_by VARCHAR(50) NOT NULL,                -- username الأدمن اللي أنشأ الحساب
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW()
);

CREATE INDEX idx_pyra_clients_email ON pyra_clients(email);
CREATE INDEX idx_pyra_clients_company ON pyra_clients(company);
CREATE INDEX idx_pyra_clients_status ON pyra_clients(status);
```

**ملاحظات:**
- `id` يُولّد بنفس نمط `pyra_reviews`: `'c_' . time() . '_' . substr(bin2hex(random_bytes(4)), 0, 4)`
- `role` يحدد مستوى الوصول داخل بوابة العميل:
  - `primary` — وصول كامل (مشاريع + ملفات + موافقات + تعليقات)
  - `billing` — يشوف المشاريع والملفات فقط (بدون موافقات)
  - `viewer` — يشوف فقط (read-only)
- **لا يوجد self-registration** — الأدمن ينشئ حساب العميل يدوياً
- `company` يربط مجموعة عملاء (جهات اتصال متعددة لنفس الشركة)

#### 2.1.2 صفحة Login

**URL:** `/portal/` (ملف `portal/index.php`)

**سبب اختيار مجلد `/portal/`:**
- فصل كامل عن واجهة الفريق (`index.php`)
- URL نظيف ومهني يُعطى للعميل
- سهل الحماية بقواعد خادم ويب

**هيكل الصفحة:**
```
portal/
├── index.php          ← Login + App shell (نفس نمط index.php الرئيسي)
├── portal-app.js      ← Frontend controller للبوابة
└── portal-style.css   ← Styles إضافية خاصة بالبوابة (يستورد ../style.css)
```

**Login Flow:**
1. العميل يفتح `/portal/`
2. PHP يفحص `isClientLoggedIn()` (دالة جديدة في auth.php)
3. لو مو مسجل → يعرض Login Screen (نفس تصميم Login الحالي مع لوجو Pyramedia)
4. العميل يدخل email + password
5. JS يرسل `POST /portal/index.php?action=client_login` (JSON body)
6. PHP: `usleep(200000)` → فحص القفل → بحث بالإيميل → `password_verify()`
7. لو نجح:
   - `session_regenerate_id(true)`
   - `$_SESSION['client_id'] = $client['id']`
   - `$_SESSION['client_email'] = $client['email']`
   - `$_SESSION['client_name'] = $client['name']`
   - `$_SESSION['client_company'] = $client['company']`
   - `$_SESSION['client_role'] = $client['role']`
   - `$_SESSION['client_csrf_token'] = bin2hex(random_bytes(32))`
   - تحديث `last_login_at`
   - تسجيل في `pyra_login_attempts`
   - Return `{ success: true, client: { id, name, email, company, role } }`
8. JS: `location.reload()` → PHP يرسم Dashboard

#### 2.1.3 Session Management — الفصل بين الفريق والعميل

**القاعدة الذهبية:** Session keys مختلفة تماماً.

| الجلسة | Session Keys | ملف الفحص |
|--------|-------------|-----------|
| **فريق** | `$_SESSION['user']`, `$_SESSION['role']`, `$_SESSION['csrf_token']` | `auth.php` → `isLoggedIn()` |
| **عميل** | `$_SESSION['client_id']`, `$_SESSION['client_role']`, `$_SESSION['client_csrf_token']` | `auth.php` → `isClientLoggedIn()` |

**ما يمكن أن يكون شخص مسجل كفريق وعميل في نفس الوقت** — لكن الـ session keys لا تتعارض.

**Functions جديدة في auth.php:**
```php
function isClientLoggedIn(): bool {
    return isset($_SESSION['client_id']) && !empty($_SESSION['client_id']);
}

function getClientData(): ?array {
    if (!isClientLoggedIn()) return null;
    return [
        'id' => $_SESSION['client_id'],
        'name' => $_SESSION['client_name'],
        'email' => $_SESSION['client_email'],
        'company' => $_SESSION['client_company'],
        'role' => $_SESSION['client_role'],
        'csrf_token' => $_SESSION['client_csrf_token']
    ];
}

function requireClientAuth(): array {
    if (!isClientLoggedIn()) {
        http_response_code(401);
        echo json_encode(['error' => 'Not authenticated']);
        exit;
    }
    return getClientData();
}

function validateClientCsrf(): void {
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!$token || $token !== ($_SESSION['client_csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid CSRF token']);
        exit;
    }
}
```

#### 2.1.4 Password Reset Flow

1. العميل يضغط "نسيت كلمة المرور" في صفحة Login
2. يدخل إيميله
3. `POST /portal/index.php?action=client_forgot_password`
4. PHP يتحقق أن الإيميل موجود في `pyra_clients`
5. يولّد token (64 حرف hex) + expiry (ساعة واحدة)
6. يحفظ في جدول `pyra_client_password_resets`
7. يرسل إيميل بالرابط (لو Email مفعّل، وإلا يرجع الـ token مباشرة)
8. العميل يضغط الرابط → صفحة إعادة التعيين
9. يدخل كلمة مرور جديدة
10. `POST /portal/index.php?action=client_reset_password` مع token + password
11. PHP يتحقق من Token → يحدّث `password_hash` → يحذف الـ token

**جدول:**
```sql
CREATE TABLE pyra_client_password_resets (
    id VARCHAR(20) PRIMARY KEY,
    client_id VARCHAR(20) NOT NULL REFERENCES pyra_clients(id) ON DELETE CASCADE,
    token VARCHAR(128) NOT NULL UNIQUE,
    expires_at TIMESTAMPTZ NOT NULL,
    used BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMPTZ DEFAULT NOW()
);

CREATE INDEX idx_pyra_client_pw_reset_token ON pyra_client_password_resets(token);
```

#### 2.1.5 Brute Force Protection

نفس نمط النظام الحالي:
- `usleep(200000)` — تأخير 200ms على كل محاولة login
- بعد 5 محاولات فاشلة → قفل 15 دقيقة
- تُسجّل في `pyra_login_attempts` مع `username = 'client:' . $email`
- دالة `isClientAccountLocked($email)` تفحص المحاولات الأخيرة

#### 2.1.6 CSRF Protection

نفس النمط الحالي:
- كل POST request (عدا login) يتطلب header `X-CSRF-Token`
- Token يُمرر للـ frontend عبر `window.PORTAL_CONFIG.csrf_token`
- `validateClientCsrf()` تُستدعى في بداية كل POST action

---

### 2.2 Client Dashboard

**URL:** `/portal/` (بعد Login — نفس الملف `portal/index.php`)

**API Endpoint:** `GET /portal/index.php?action=client_dashboard`

#### الـ Dashboard يحتوي:

**Card 1: مرحباً + معلومات الشركة**
```
┌─────────────────────────────────┐
│ 👋 مرحباً، أحمد                │
│ شركة: Pyramedia Productions    │
│ آخر دخول: قبل 3 أيام          │
└─────────────────────────────────┘
```

**Card 2: المشاريع النشطة**
```
┌─────────────────────────────────┐
│ 📁 المشاريع النشطة    [3]      │
│ ─────────────────────────────── │
│ • Social Media Campaign  🟢 Active │
│ • Brand Identity         🟡 Review │
│ • Website Redesign       🔵 In Progress │
│ [عرض الكل →]                    │
└─────────────────────────────────┘
```

**Card 3: ملفات تحتاج موافقة**
```
┌─────────────────────────────────┐
│ ⏳ بانتظار موافقتك     [5]     │
│ ─────────────────────────────── │
│ • logo-final-v3.png     📅 اليوم │
│ • brochure-draft.pdf    📅 أمس   │
│ • video-intro.mp4       📅 قبل 3 أيام │
│ [عرض الكل →]                    │
└─────────────────────────────────┘
```

**Card 4: آخر الملفات**
```
┌─────────────────────────────────┐
│ 📄 آخر الملفات          [12]   │
│ ─────────────────────────────── │
│ • hero-banner.jpg     2.3 MB    │
│ • pitch-deck.pdf      5.1 MB    │
│ • audio-jingle.mp3    1.8 MB    │
│ [عرض الكل →]                    │
└─────────────────────────────────┘
```

**Card 5: الرسائل / التعليقات**
```
┌─────────────────────────────────┐
│ 💬 رسائل جديدة          [2]    │
│ ─────────────────────────────── │
│ • رد من أحمد على logo-v3       │
│ • تعليق جديد على brochure      │
│ [عرض الكل →]                    │
└─────────────────────────────────┘
```

**Card 6: الإشعارات الأخيرة**
```
┌─────────────────────────────────┐
│ 🔔 الإشعارات             [4]   │
│ ─────────────────────────────── │
│ • ملف جديد: video-final.mp4    │
│ • مشروعك "Brand" انتقل لـ Review │
│ [عرض الكل →]                    │
└─────────────────────────────────┘
```

#### SQL Queries للـ Dashboard:

```sql
-- Active projects count + list (last 5)
SELECT p.*, 
    (SELECT COUNT(*) FROM pyra_project_files pf WHERE pf.project_id = p.id) as file_count,
    (SELECT COUNT(*) FROM pyra_file_approvals fa 
     JOIN pyra_project_files pf2 ON fa.file_id = pf2.id 
     WHERE pf2.project_id = p.id AND fa.status = 'pending') as pending_approvals
FROM pyra_projects p
WHERE p.client_company = $company
AND p.status IN ('active', 'in_progress', 'review')
ORDER BY p.updated_at DESC
LIMIT 5;

-- Pending approvals (files needing client action)
SELECT pf.*, fa.id as approval_id, fa.status as approval_status, fa.created_at as approval_date,
       pp.name as project_name
FROM pyra_file_approvals fa
JOIN pyra_project_files pf ON fa.file_id = pf.id
JOIN pyra_projects pp ON pf.project_id = pp.id
WHERE pp.client_company = $company
AND fa.status = 'pending'
ORDER BY fa.created_at DESC
LIMIT 5;

-- Recent files (last 5)
SELECT pf.*, pp.name as project_name
FROM pyra_project_files pf
JOIN pyra_projects pp ON pf.project_id = pp.id
WHERE pp.client_company = $company
ORDER BY pf.created_at DESC
LIMIT 5;

-- Unread notifications count
SELECT COUNT(*) as unread_count
FROM pyra_client_notifications
WHERE client_id = $client_id
AND is_read = FALSE;

-- Unread comments count
SELECT COUNT(*) as unread_comments
FROM pyra_client_comments cc
JOIN pyra_projects pp ON cc.project_id = pp.id
WHERE pp.client_company = $company
AND cc.is_read_by_client = FALSE
AND cc.author_type = 'team';

-- Recent notifications (last 5)
SELECT *
FROM pyra_client_notifications
WHERE client_id = $client_id
ORDER BY created_at DESC
LIMIT 5;
```

**API Response:**
```json
{
    "success": true,
    "dashboard": {
        "client": { "name": "أحمد", "company": "Pyramedia", "last_login": "2026-02-11T..." },
        "projects": {
            "total_active": 3,
            "list": [
                { "id": "prj_...", "name": "Social Media Campaign", "status": "active", "file_count": 12, "pending_approvals": 2 }
            ]
        },
        "pending_approvals": {
            "total": 5,
            "list": [
                { "file_id": "pf_...", "file_name": "logo-final-v3.png", "project_name": "Brand Identity", "created_at": "..." }
            ]
        },
        "recent_files": {
            "total": 12,
            "list": [
                { "id": "pf_...", "file_name": "hero-banner.jpg", "file_size": 2400000, "project_name": "Website" }
            ]
        },
        "unread_notifications": 4,
        "unread_comments": 2,
        "recent_notifications": [
            { "id": "cn_...", "type": "new_file", "message": "ملف جديد: video-final.mp4", "created_at": "..." }
        ]
    }
}
```

---

### 2.3 Projects

#### 2.3.1 جدول: `pyra_projects`

```sql
CREATE TABLE pyra_projects (
    id VARCHAR(20) PRIMARY KEY,                     -- prj_1707926400_a3f2
    name VARCHAR(200) NOT NULL,                     -- اسم المشروع
    description TEXT DEFAULT NULL,                  -- وصف المشروع
    client_company VARCHAR(150) NOT NULL,           -- اسم الشركة (يربط بـ pyra_clients.company)
    status VARCHAR(20) DEFAULT 'active' CHECK (status IN ('draft', 'active', 'in_progress', 'review', 'completed', 'archived')),
    start_date DATE DEFAULT NULL,
    deadline DATE DEFAULT NULL,
    storage_path TEXT NOT NULL,                     -- المسار في Supabase Storage (مثل: projects/pyramedia/social-campaign)
    cover_image TEXT DEFAULT NULL,                  -- رابط صورة الغلاف
    created_by VARCHAR(50) NOT NULL,                -- username الأدمن
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW()
);

CREATE INDEX idx_pyra_projects_company ON pyra_projects(client_company);
CREATE INDEX idx_pyra_projects_status ON pyra_projects(status);
CREATE INDEX idx_pyra_projects_deadline ON pyra_projects(deadline);
```

**حالات المشروع:**
| الحالة | اللون | الوصف |
|--------|-------|-------|
| `draft` | `#6b7280` (رمادي) | مسودة — العميل لا يراها |
| `active` | `#10b981` (أخضر) | نشط — قيد العمل |
| `in_progress` | `#3b82f6` (أزرق) | قيد التنفيذ |
| `review` | `#f59e0b` (أصفر) | بانتظار مراجعة العميل |
| `completed` | `#8b5cf6` (بنفسجي) | مكتمل |
| `archived` | `#374151` (رمادي داكن) | مؤرشف — العميل لا يراه |

**العميل يشوف فقط:** `active`, `in_progress`, `review`, `completed`

#### 2.3.2 جدول: `pyra_project_files`

```sql
CREATE TABLE pyra_project_files (
    id VARCHAR(20) PRIMARY KEY,                     -- pf_1707926400_a3f2
    project_id VARCHAR(20) NOT NULL REFERENCES pyra_projects(id) ON DELETE CASCADE,
    file_name VARCHAR(255) NOT NULL,                -- اسم الملف
    file_path TEXT NOT NULL,                        -- المسار الكامل في Supabase Storage
    file_size BIGINT DEFAULT 0,
    mime_type VARCHAR(100) DEFAULT NULL,
    category VARCHAR(50) DEFAULT 'general' CHECK (category IN ('general', 'design', 'video', 'document', 'audio', 'other')),
    version INT DEFAULT 1,                          -- رقم الإصدار
    needs_approval BOOLEAN DEFAULT FALSE,           -- هل يحتاج موافقة العميل؟
    uploaded_by VARCHAR(50) NOT NULL,               -- username اللي رفع
    created_at TIMESTAMPTZ DEFAULT NOW()
);

CREATE INDEX idx_pyra_pf_project ON pyra_project_files(project_id);
CREATE INDEX idx_pyra_pf_category ON pyra_project_files(category);
CREATE INDEX idx_pyra_pf_needs_approval ON pyra_project_files(needs_approval);
```

#### 2.3.3 Project List View (UI)

**URL:** `/portal/` → يضغط "عرض الكل" من card المشاريع، أو من الـ Sidebar

**API:** `GET /portal/index.php?action=client_projects&status=all&page=1`

**الـ UI: Cards Layout (Grid)**

```html
<div class="portal-projects-grid">
    <!-- لكل مشروع -->
    <div class="portal-project-card" data-id="prj_...">
        <div class="portal-project-cover">
            <img src="cover_image_url" alt="Project Name">
            <span class="portal-project-status status-review">مراجعة</span>
        </div>
        <div class="portal-project-info">
            <h3 class="portal-project-name">Social Media Campaign</h3>
            <p class="portal-project-desc">حملة سوشل ميديا لربع أول 2026</p>
            <div class="portal-project-meta">
                <span class="portal-meta-item">📁 12 ملف</span>
                <span class="portal-meta-item">⏳ 3 بانتظار الموافقة</span>
                <span class="portal-meta-item">📅 15 مارس 2026</span>
            </div>
            <div class="portal-project-progress">
                <div class="portal-progress-bar" style="width: 75%"></div>
            </div>
        </div>
    </div>
</div>
```

**Progress Bar حساب:**
```
progress = (approved_files / total_approval_files) * 100
```
لو ما في ملفات تحتاج موافقة: يعرض حالة المشروع بدون progress bar.

#### 2.3.4 Project Detail View

**API:** `GET /portal/index.php?action=client_project_detail&project_id=prj_...`

**الـ UI:**
```
┌──────────────────────────────────────────────────┐
│ ← رجوع                                          │
│                                                  │
│ Social Media Campaign                   🟡 مراجعة│
│ حملة سوشل ميديا لربع أول 2026                   │
│ 📅 بداية: 1 يناير │ 📅 موعد التسليم: 15 مارس    │
│ ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ 75% ━━━━━        │
│                                                  │
│ [كل الملفات] [بانتظار الموافقة] [تمت الموافقة]   │
│ [تصاميم] [فيديو] [مستندات]                       │
│                                                  │
│ ┌─────────────────────────────────────────┐      │
│ │ 🖼️ logo-final-v3.png  │ 2.3 MB │ اليوم │      │
│ │ ⏳ بانتظار الموافقة                     │      │
│ │ [معاينة] [تحميل] [موافقة ❌✅]           │      │
│ ├─────────────────────────────────────────┤      │
│ │ 📄 brochure-draft.pdf │ 5.1 MB │ أمس   │      │
│ │ ✅ تمت الموافقة                         │      │
│ │ [معاينة] [تحميل]                        │      │
│ └─────────────────────────────────────────┘      │
└──────────────────────────────────────────────────┘
```

**SQL:**
```sql
-- Project detail
SELECT p.*,
    (SELECT COUNT(*) FROM pyra_project_files WHERE project_id = p.id) as total_files,
    (SELECT COUNT(*) FROM pyra_project_files pf 
     JOIN pyra_file_approvals fa ON fa.file_id = pf.id 
     WHERE pf.project_id = p.id AND fa.status = 'approved') as approved_files,
    (SELECT COUNT(*) FROM pyra_project_files WHERE project_id = p.id AND needs_approval = TRUE) as approval_required_files
FROM pyra_projects p
WHERE p.id = $project_id
AND p.client_company = $company
AND p.status NOT IN ('draft', 'archived');

-- Project files with approval status
SELECT pf.*,
    fa.id as approval_id,
    fa.status as approval_status,
    fa.comment as approval_comment,
    fa.updated_at as approval_date
FROM pyra_project_files pf
LEFT JOIN pyra_file_approvals fa ON fa.file_id = pf.id AND fa.client_id = $client_id
WHERE pf.project_id = $project_id
ORDER BY pf.created_at DESC
LIMIT $limit OFFSET $offset;
```

---

### 2.4 File Viewing & Approval

#### 2.4.1 File Preview

**API:** `GET /portal/index.php?action=client_file_preview&file_id=pf_...`

**أنواع المعاينة:**
| نوع الملف | MIME Type Pattern | طريقة المعاينة |
|-----------|-------------------|---------------|
| صور | `image/*` | `<img src="public_url" class="portal-preview-image">` |
| PDF | `application/pdf` | `<iframe src="public_url" class="portal-preview-pdf"></iframe>` |
| فيديو | `video/*` | `<video controls src="public_url" class="portal-preview-video"></video>` |
| صوت | `audio/*` | `<audio controls src="public_url" class="portal-preview-audio"></audio>` |
| أخرى | `*` | أيقونة + معلومات الملف + زر تحميل فقط |

**PHP Logic لتوليد الرابط:**
```php
// نستخدم Supabase public URL (الملفات في bucket عام)
$publicUrl = SUPABASE_URL . '/storage/v1/object/public/' . BUCKET . '/' . $filePath;

// أو signed URL لو نحتاج حماية أكثر (ينتهي بعد ساعة)
$signedUrl = supabaseRequest('POST', '/storage/v1/object/sign/' . BUCKET . '/' . $filePath, [
    'expiresIn' => 3600
]);
```

**⚠️ قرار: نستخدم public URL** (نفس النمط الحالي في Pyra Workspace) — لأن الملفات بالأصل عامة والحماية عبر عدم كشف المسار.

#### 2.4.2 File Download

**API:** `GET /portal/index.php?action=client_download&file_id=pf_...`

**Flow:**
1. `requireClientAuth()`
2. جلب الملف من `pyra_project_files`
3. التحقق أن المشروع يخص `client_company` العميل
4. جلب الملف من Supabase Storage
5. `Content-Disposition: attachment; filename="original_name"`
6. Stream المحتوى

#### 2.4.3 نظام الموافقة

**جدول: `pyra_file_approvals`**

```sql
CREATE TABLE pyra_file_approvals (
    id VARCHAR(20) PRIMARY KEY,                     -- fa_1707926400_a3f2
    file_id VARCHAR(20) NOT NULL REFERENCES pyra_project_files(id) ON DELETE CASCADE,
    client_id VARCHAR(20) NOT NULL REFERENCES pyra_clients(id) ON DELETE CASCADE,
    status VARCHAR(20) DEFAULT 'pending' CHECK (status IN ('pending', 'approved', 'revision_requested')),
    comment TEXT DEFAULT NULL,                       -- تعليق العميل (خاصة مع revision_requested)
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW(),
    UNIQUE(file_id, client_id)                      -- كل عميل يوافق مرة واحدة على كل ملف
);

CREATE INDEX idx_pyra_fa_file ON pyra_file_approvals(file_id);
CREATE INDEX idx_pyra_fa_client ON pyra_file_approvals(client_id);
CREATE INDEX idx_pyra_fa_status ON pyra_file_approvals(status);
```

**Approval Flow:**
```
1. الأدمن يرفع ملف ويحدد needs_approval = true
2. النظام يُنشئ record في pyra_file_approvals لكل عميل primary في الشركة
   مع status = 'pending'
3. العميل يفتح الملف ويشوف:
   - زر ✅ "موافقة" (Approve)
   - زر ❌ "طلب تعديل" (Request Revision) + حقل تعليق
4. العميل يختار:
   a. Approve → status = 'approved', إشعار للفريق
   b. Request Revision → status = 'revision_requested' + comment، إشعار للفريق
5. الفريق يعدل الملف ويرفع نسخة جديدة → status يرجع 'pending'
```

**UI الموافقة:**
```html
<div class="portal-approval-section" data-file-id="pf_...">
    <!-- لو pending -->
    <div class="portal-approval-pending">
        <p class="portal-approval-label">⏳ هذا الملف بانتظار موافقتك</p>
        <div class="portal-approval-actions">
            <button class="portal-btn portal-btn-approve" onclick="PortalApp.approveFile('pf_...')">
                ✅ موافقة
            </button>
            <button class="portal-btn portal-btn-revision" onclick="PortalApp.showRevisionForm('pf_...')">
                ❌ طلب تعديل
            </button>
        </div>
        <div class="portal-revision-form" id="revision-form-pf_..." style="display:none">
            <textarea class="portal-input" placeholder="وصف التعديلات المطلوبة..." id="revision-comment-pf_..."></textarea>
            <button class="portal-btn portal-btn-submit" onclick="PortalApp.requestRevision('pf_...')">
                إرسال طلب التعديل
            </button>
        </div>
    </div>
    
    <!-- لو approved -->
    <div class="portal-approval-approved" style="display:none">
        <p class="portal-approval-label">✅ تمت الموافقة</p>
        <small class="portal-approval-date">بتاريخ ...</small>
    </div>
    
    <!-- لو revision_requested -->
    <div class="portal-approval-revision" style="display:none">
        <p class="portal-approval-label">❌ طُلب تعديل</p>
        <p class="portal-approval-comment">"التعليق..."</p>
        <small class="portal-approval-date">بتاريخ ...</small>
    </div>
</div>
```

**API Endpoints:**
```
POST /portal/index.php?action=client_approve_file
Request: { file_id: "pf_..." }
Response: { success: true, status: "approved" }

POST /portal/index.php?action=client_request_revision
Request: { file_id: "pf_...", comment: "النص أصغر من اللازم" }
Response: { success: true, status: "revision_requested" }
```

**الإشعارات عند الموافقة/الرفض:**
- يُنشأ record في `pyra_notifications` لكل admin
- `type = 'client_approval'` أو `'client_revision_request'`
- `title = 'العميل وافق على logo-v3.png'` أو `'العميل طلب تعديل على logo-v3.png'`
- `target_path` = مسار الملف في Storage

---

### 2.5 Messaging / Comments

#### 2.5.1 جدول: `pyra_client_comments`

```sql
CREATE TABLE pyra_client_comments (
    id VARCHAR(20) PRIMARY KEY,                     -- cc_1707926400_a3f2
    project_id VARCHAR(20) NOT NULL REFERENCES pyra_projects(id) ON DELETE CASCADE,
    file_id VARCHAR(20) DEFAULT NULL REFERENCES pyra_project_files(id) ON DELETE SET NULL,
    author_type VARCHAR(10) NOT NULL CHECK (author_type IN ('client', 'team')),
    author_id VARCHAR(50) NOT NULL,                 -- client_id أو username
    author_name VARCHAR(100) NOT NULL,              -- اسم العرض
    text TEXT NOT NULL,
    parent_id VARCHAR(20) DEFAULT NULL REFERENCES pyra_client_comments(id) ON DELETE CASCADE,
    is_read_by_client BOOLEAN DEFAULT FALSE,        -- هل العميل قرأه (للرسائل من الفريق)
    is_read_by_team BOOLEAN DEFAULT FALSE,          -- هل الفريق قرأه (للرسائل من العميل)
    created_at TIMESTAMPTZ DEFAULT NOW()
);

CREATE INDEX idx_pyra_cc_project ON pyra_client_comments(project_id);
CREATE INDEX idx_pyra_cc_file ON pyra_client_comments(file_id);
CREATE INDEX idx_pyra_cc_parent ON pyra_client_comments(parent_id);
CREATE INDEX idx_pyra_cc_read_client ON pyra_client_comments(is_read_by_client) WHERE is_read_by_client = FALSE;
CREATE INDEX idx_pyra_cc_read_team ON pyra_client_comments(is_read_by_team) WHERE is_read_by_team = FALSE;
```

**لماذا جدول جديد بدل `pyra_reviews`؟**
- `pyra_reviews` مرتبط بـ `file_path` (نصي) — بينما نحن نربط بـ `project_id` + `file_id` (FK)
- `pyra_reviews` يحتوي `resolved` و `type` (comment/approval) — ما نحتاجها
- فصل البيانات أفضل أمنياً — العميل ما يشوف مراجعات الفريق الداخلية
- الفريق يقدر يرد من الـ Workspace الرئيسي عبر endpoint جديد

#### 2.5.2 Comment UI

**في صفحة تفاصيل المشروع (تحت الملفات) أو عند معاينة ملف:**

```html
<div class="portal-comments-section">
    <h4 class="portal-section-title">💬 التعليقات</h4>
    
    <div class="portal-comments-list" id="comments-list">
        <!-- تعليق من الفريق -->
        <div class="portal-comment portal-comment-team">
            <div class="portal-comment-avatar">👤</div>
            <div class="portal-comment-body">
                <div class="portal-comment-header">
                    <strong>محمد</strong>
                    <span class="portal-comment-badge team">فريق</span>
                    <time>قبل 3 ساعات</time>
                </div>
                <p class="portal-comment-text">تم رفع النسخة النهائية من اللوجو</p>
                <button class="portal-comment-reply-btn" onclick="PortalApp.showReplyForm('cc_...')">رد</button>
            </div>
        </div>
        
        <!-- رد من العميل (متداخل) -->
        <div class="portal-comment portal-comment-client portal-comment-nested">
            <div class="portal-comment-avatar">🏢</div>
            <div class="portal-comment-body">
                <div class="portal-comment-header">
                    <strong>أحمد</strong>
                    <span class="portal-comment-badge client">عميل</span>
                    <time>قبل ساعة</time>
                </div>
                <p class="portal-comment-text">ممتاز! بس ممكن نغير اللون الثانوي؟</p>
            </div>
        </div>
    </div>
    
    <!-- حقل إضافة تعليق -->
    <div class="portal-comment-form">
        <textarea class="portal-input" id="new-comment-text" placeholder="اكتب تعليقك..."></textarea>
        <button class="portal-btn portal-btn-primary" onclick="PortalApp.addComment()">إرسال</button>
    </div>
</div>
```

#### 2.5.3 API Endpoints للتعليقات:

```
GET /portal/index.php?action=client_get_comments&project_id=prj_...&file_id=pf_...
Response: { success: true, comments: [...] }

POST /portal/index.php?action=client_add_comment
Request: { project_id: "prj_...", file_id: "pf_..." (optional), text: "...", parent_id: "cc_..." (optional) }
Response: { success: true, comment: { id: "cc_...", ... } }
```

#### 2.5.4 الفريق يرد من الـ Workspace الرئيسي

**Endpoint جديد في api.php (الـ Workspace الرئيسي):**
```
POST /api.php?action=team_reply_to_client
Request: { comment_id: "cc_...", text: "..." }
```

**أو** — نعرض تعليقات العملاء في الـ Review panel الموجود حالياً عند فتح ملف المشروع. يُضاف tab جديد "تعليقات العميل" بجانب "المراجعات".

**الحل الأسهل والأنسب:** endpoint جديد في api.php يسمح للفريق بإضافة تعليق في `pyra_client_comments` مع `author_type = 'team'`.

---

### 2.6 Notifications

#### 2.6.1 جدول: `pyra_client_notifications`

```sql
CREATE TABLE pyra_client_notifications (
    id VARCHAR(20) PRIMARY KEY,                     -- cn_1707926400_a3f2
    client_id VARCHAR(20) NOT NULL REFERENCES pyra_clients(id) ON DELETE CASCADE,
    type VARCHAR(30) NOT NULL CHECK (type IN (
        'new_file', 'file_updated', 'project_status', 
        'comment_reply', 'approval_reset', 'welcome'
    )),
    title VARCHAR(200) NOT NULL,
    message TEXT DEFAULT NULL,
    target_project_id VARCHAR(20) DEFAULT NULL,
    target_file_id VARCHAR(20) DEFAULT NULL,
    is_read BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMPTZ DEFAULT NOW()
);

CREATE INDEX idx_pyra_cn_client ON pyra_client_notifications(client_id);
CREATE INDEX idx_pyra_cn_read ON pyra_client_notifications(is_read) WHERE is_read = FALSE;
CREATE INDEX idx_pyra_cn_created ON pyra_client_notifications(created_at DESC);
```

#### 2.6.2 In-App Notifications

**Polling:** كل 30 ثانية (نفس النمط الحالي)

```javascript
// في portal-app.js
initNotifications() {
    this.pollNotifications();
    setInterval(() => this.pollNotifications(), 30000);
},

async pollNotifications() {
    const res = await this.apiFetch('?action=client_unread_count');
    const data = await res.json();
    const badge = document.getElementById('portal-notif-badge');
    if (data.count > 0) {
        badge.textContent = data.count;
        badge.style.display = 'flex';
    } else {
        badge.style.display = 'none';
    }
}
```

**UI: أيقونة الجرس في الـ top bar**
```html
<button class="portal-notif-btn" onclick="PortalApp.showNotifications()">
    🔔
    <span class="portal-notif-badge" id="portal-notif-badge" style="display:none">0</span>
</button>
```

#### 2.6.3 متى تُرسل إشعارات للعميل:

| الحدث | النوع | العنوان (مثال) |
|-------|-------|---------------|
| ملف جديد في مشروعه | `new_file` | "ملف جديد: logo-v3.png في Social Campaign" |
| ملف تم تحديثه (نسخة جديدة) | `file_updated` | "تم تحديث: brochure.pdf" |
| تغير حالة المشروع | `project_status` | "مشروعك 'Brand' انتقل لحالة: مراجعة" |
| رد من الفريق على تعليقه | `comment_reply` | "محمد رد على تعليقك" |
| إعادة تعيين الموافقة (ملف جديد يحتاج موافقة) | `approval_reset` | "ملف جديد يحتاج موافقتك: logo-v4.png" |
| ترحيب (حساب جديد) | `welcome` | "مرحباً في Pyra Portal! حسابك جاهز" |

#### 2.6.4 API Endpoints:

```
GET /portal/index.php?action=client_unread_count
Response: { count: 4 }

GET /portal/index.php?action=client_notifications&page=1
Response: { success: true, notifications: [...], total: 15 }

POST /portal/index.php?action=client_mark_notif_read
Request: { notification_id: "cn_..." }
Response: { success: true }

POST /portal/index.php?action=client_mark_all_read
Response: { success: true }
```

#### 2.6.5 Email Notifications

**PHPMailer:** لا نضيف dependency جديدة. نستخدم PHP `mail()` function أو SMTP مباشر عبر `fsockopen()`.

**الأبسط والأنسب:** نستخدم n8n webhook — عند كل حدث يحتاج إيميل، نرسل POST لـ n8n workflow يتولى الإرسال.

**لكن لو نحتاج حل مستقل بدون n8n:**

```php
function sendClientEmail(string $to, string $subject, string $htmlBody): bool {
    $headers = [
        'MIME-Version: 1.0',
        'Content-type: text/html; charset=UTF-8',
        'From: Pyramedia <noreply@pyramedia.info>',
        'Reply-To: info@pyramedia.info'
    ];
    return mail($to, $subject, $htmlBody, implode("\r\n", $headers));
}
```

**Email Template:**
```html
<!DOCTYPE html>
<html dir="rtl" lang="ar">
<head><meta charset="UTF-8"></head>
<body style="font-family: 'Segoe UI', Tahoma, sans-serif; background: #1a1a2e; color: #edf0f7; padding: 40px;">
    <div style="max-width: 600px; margin: 0 auto; background: #111620; border-radius: 12px; padding: 30px; border: 1px solid rgba(249,115,22,0.2);">
        <div style="text-align: center; margin-bottom: 30px;">
            <h1 style="color: #F97316; font-size: 24px;">Pyramedia</h1>
        </div>
        <h2 style="color: #edf0f7; font-size: 18px;">{{TITLE}}</h2>
        <p style="color: #8892a8; line-height: 1.8;">{{MESSAGE}}</p>
        <div style="text-align: center; margin: 30px 0;">
            <a href="{{ACTION_URL}}" style="background: #F97316; color: white; padding: 12px 30px; border-radius: 8px; text-decoration: none; font-weight: bold;">{{ACTION_TEXT}}</a>
        </div>
        <hr style="border: 1px solid rgba(255,255,255,0.1); margin: 30px 0;">
        <p style="color: #6b7280; font-size: 12px; text-align: center;">Pyramedia Workspace — pyramedia.info</p>
    </div>
</body>
</html>
```

**الأحداث اللي ترسل إيميل:**
1. ✅ حساب جديد (ترحيب + بيانات الدخول)
2. ✅ ملف جديد يحتاج موافقة
3. ✅ رد من الفريق على تعليق
4. ✅ إعادة تعيين كلمة المرور

---

### 2.7 Client Profile

**API:** `GET /portal/index.php?action=client_profile`

**UI:**
```
┌──────────────────────────────────────────────┐
│ 👤 الملف الشخصي                              │
│                                              │
│ [صورة]  الاسم: أحمد محمد                     │
│          الإيميل: ahmed@company.com           │
│          الشركة: Company XYZ (غير قابل للتعديل) │
│          الهاتف: +971-50-123-4567            │
│          الدور: primary (غير قابل للتعديل)     │
│                                              │
│ [تعديل البيانات]                               │
│ [تغيير كلمة المرور]                            │
│ [تغيير الصورة]                                │
└──────────────────────────────────────────────┘
```

**API Endpoints:**

```
GET /portal/index.php?action=client_profile
Response: { success: true, client: { name, email, phone, company, role, avatar_url, language } }

POST /portal/index.php?action=client_update_profile
Request: { name: "أحمد محمد", phone: "+971...", language: "ar" }
Response: { success: true }
Note: email update يتطلب verify (Phase 2)

POST /portal/index.php?action=client_change_password
Request: { current_password: "...", new_password: "..." }
Response: { success: true }

POST /portal/index.php?action=client_update_avatar (multipart/form-data)
Request: FormData with 'avatar' file
Response: { success: true, avatar_url: "..." }
```

**ما يقدر يغيّر:** `role`, `company`, `status`, `email` (في V1)

---

## 3. المتطلبات غير الوظيفية

### 3.1 Security

| الإجراء | التفاصيل |
|---------|----------|
| **Session Isolation** | `client_id`, `client_role`, `client_csrf_token` — مفاتيح مختلفة عن الفريق |
| **Data Isolation** | كل query يفلتر بـ `client_company` — العميل لا يشوف شركات ثانية |
| **CSRF** | `X-CSRF-Token` header على كل POST — `validateClientCsrf()` |
| **Brute Force** | `usleep(200ms)` + lockout بعد 5 محاولات (15 دقيقة) |
| **XSS** | `htmlspecialchars($output, ENT_QUOTES, 'UTF-8')` على كل output |
| **SQL Injection** | كل الـ queries عبر Supabase PostgREST مع URL encoding صحيح |
| **Path Traversal** | لا يوجد — الملفات تُجلب بـ `file_id` لا بـ path |
| **Rate Limiting** | Login: 200ms delay. API: يعتمد على Supabase rate limits |
| **Security Headers** | نفس headers الحالية: `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy` |
| **Password Policy** | الحد الأدنى 8 أحرف (يُفحص في PHP قبل hashing) |

### 3.2 UI/UX

**Design System:** نفس النظام الحالي بالضبط.

| العنصر | القيمة |
|--------|-------|
| **Theme** | Dark Luxury — glass morphism |
| **Primary Color** | `#F97316` (Pyramedia Orange) — يُقرأ من `pyra_settings.primary_color` |
| **Background** | `--bg-primary: #0a0e14`, `--bg-secondary: #111620` |
| **Glass Effect** | `backdrop-filter: blur(12px)`, `background: var(--bg-glass)` |
| **Border Radius** | `var(--radius)` = `10px` |
| **Fonts** | `Inter` (UI), `Noto Sans Arabic` (عربي), `JetBrains Mono` (كود) |
| **RTL** | `dir="rtl"` على `<body>` + `font-family: var(--font-arabic)` |
| **Responsive** | Mobile-first: `max-width: 900px`, `768px`, `600px` |
| **Animations** | `@keyframes fadeInUp`, `@keyframes slideIn`, `@keyframes modalPop` |

**CSS Variables الجديدة (تُضاف لـ portal-style.css):**
```css
/* Portal-specific — inherits all from style.css */
.portal-container {
    --portal-sidebar-width: 260px;
    --portal-header-height: 64px;
}
```

**اللغة الافتراضية:** العربية (RTL) — يُدعم الإنجليزية (LTR) عبر `language` في بيانات العميل.

### 3.3 Performance

| الإجراء | التفاصيل |
|---------|----------|
| **Lazy Loading** | الصور في قائمة الملفات: `loading="lazy"` |
| **Pagination** | 20 عنصر لكل صفحة — `?page=1` parameter |
| **Dashboard Cache** | PHP: cache بيانات Dashboard في Session لمدة 5 دقائق |
| **File Preview** | Preview يُحمّل عند النقر فقط (لا يُحمّل كل الملفات مقدماً) |
| **Debounce** | البحث في الملفات: 300ms debounce |

---

## 4. Database Schema (الكامل)

### 4.1 الجداول الجديدة (6 جداول)

```sql
-- =============================================
-- 1. pyra_clients — حسابات العملاء
-- =============================================
CREATE TABLE pyra_clients (
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

CREATE INDEX idx_clients_email ON pyra_clients(email);
CREATE INDEX idx_clients_company ON pyra_clients(company);
CREATE INDEX idx_clients_status ON pyra_clients(status);

-- =============================================
-- 2. pyra_projects — المشاريع
-- =============================================
CREATE TABLE pyra_projects (
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

CREATE INDEX idx_projects_company ON pyra_projects(client_company);
CREATE INDEX idx_projects_status ON pyra_projects(status);

-- =============================================
-- 3. pyra_project_files — ملفات المشاريع
-- =============================================
CREATE TABLE pyra_project_files (
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

CREATE INDEX idx_pf_project ON pyra_project_files(project_id);
CREATE INDEX idx_pf_approval ON pyra_project_files(needs_approval) WHERE needs_approval = TRUE;

-- =============================================
-- 4. pyra_file_approvals — موافقات الملفات
-- =============================================
CREATE TABLE pyra_file_approvals (
    id VARCHAR(20) PRIMARY KEY,
    file_id VARCHAR(20) NOT NULL REFERENCES pyra_project_files(id) ON DELETE CASCADE,
    client_id VARCHAR(20) NOT NULL REFERENCES pyra_clients(id) ON DELETE CASCADE,
    status VARCHAR(20) DEFAULT 'pending' CHECK (status IN ('pending', 'approved', 'revision_requested')),
    comment TEXT DEFAULT NULL,
    created_at TIMESTAMPTZ DEFAULT NOW(),
    updated_at TIMESTAMPTZ DEFAULT NOW(),
    UNIQUE(file_id, client_id)
);

CREATE INDEX idx_fa_file ON pyra_file_approvals(file_id);
CREATE INDEX idx_fa_client ON pyra_file_approvals(client_id);
CREATE INDEX idx_fa_status ON pyra_file_approvals(status);

-- =============================================
-- 5. pyra_client_comments — التعليقات
-- =============================================
CREATE TABLE pyra_client_comments (
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

CREATE INDEX idx_cc_project ON pyra_client_comments(project_id);
CREATE INDEX idx_cc_file ON pyra_client_comments(file_id);
CREATE INDEX idx_cc_parent ON pyra_client_comments(parent_id);

-- =============================================
-- 6. pyra_client_notifications — إشعارات العملاء
-- =============================================
CREATE TABLE pyra_client_notifications (
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

CREATE INDEX idx_cn_client ON pyra_client_notifications(client_id);
CREATE INDEX idx_cn_unread ON pyra_client_notifications(client_id, is_read) WHERE is_read = FALSE;

-- =============================================
-- 7. pyra_client_password_resets — إعادة تعيين كلمة المرور
-- =============================================
CREATE TABLE pyra_client_password_resets (
    id VARCHAR(20) PRIMARY KEY,
    client_id VARCHAR(20) NOT NULL REFERENCES pyra_clients(id) ON DELETE CASCADE,
    token VARCHAR(128) NOT NULL UNIQUE,
    expires_at TIMESTAMPTZ NOT NULL,
    used BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMPTZ DEFAULT NOW()
);

CREATE INDEX idx_cpr_token ON pyra_client_password_resets(token);
```

### 4.2 تعديلات على الجداول الحالية

**لا توجد تعديلات على الجداول الحالية.**

كل الجداول الجديدة مستقلة. الربط بين عالم الفريق (Workspace) وعالم العميل (Portal) يكون عبر:
- `pyra_projects.storage_path` يشير لنفس المسارات في Supabase Storage
- `pyra_projects.created_by` يستخدم username من `pyra_users`
- `pyra_client_comments.author_type = 'team'` + `author_id = username`
- الإشعارات للفريق تُنشأ في `pyra_notifications` (الجدول الحالي)

### 4.3 RLS Policies

**لا نحتاج RLS** — التطبيق يستخدم `service_role` key وكل الفلترة تتم في PHP.

---

## 5. API Endpoints (الكامل)

### 5.1 ملف الـ API: `/portal/index.php`

**⚠️ لا نعدل `api.php` الحالي — نعمل API جديد في `/portal/index.php`**

**السبب:** فصل أمني كامل. Portal API لها session checks مختلفة.

**هيكل `/portal/index.php`:**
```php
<?php
// portal/index.php
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';

// Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');

$action = $_GET['action'] ?? '';

// Actions that don't need auth
$publicActions = ['client_login', 'client_forgot_password', 'client_reset_password', 'client_session', 'getPublicSettings'];

if ($action && !in_array($action, $publicActions)) {
    // Require client auth
    $client = requireClientAuth();
    
    // CSRF check for POST requests
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        validateClientCsrf();
    }
}

if ($action) {
    header('Content-Type: application/json');
    
    switch ($action) {
        // === AUTH ===
        case 'client_login':
            // ... (see 5.2)
            break;
        case 'client_logout':
            // ...
            break;
        case 'client_session':
            // ...
            break;
        case 'client_forgot_password':
            // ...
            break;
        case 'client_reset_password':
            // ...
            break;
            
        // === DASHBOARD ===
        case 'client_dashboard':
            // ...
            break;
            
        // === PROJECTS ===
        case 'client_projects':
            // ...
            break;
        case 'client_project_detail':
            // ...
            break;
            
        // === FILES ===
        case 'client_file_preview':
            // ...
            break;
        case 'client_download':
            // ...
            break;
            
        // === APPROVALS ===
        case 'client_approve_file':
            // ...
            break;
        case 'client_request_revision':
            // ...
            break;
            
        // === COMMENTS ===
        case 'client_get_comments':
            // ...
            break;
        case 'client_add_comment':
            // ...
            break;
            
        // === NOTIFICATIONS ===
        case 'client_unread_count':
            // ...
            break;
        case 'client_notifications':
            // ...
            break;
        case 'client_mark_notif_read':
            // ...
            break;
        case 'client_mark_all_read':
            // ...
            break;
            
        // === PROFILE ===
        case 'client_profile':
            // ...
            break;
        case 'client_update_profile':
            // ...
            break;
        case 'client_change_password':
            // ...
            break;
        case 'client_update_avatar':
            // ...
            break;
            
        // === SETTINGS (public) ===
        case 'getPublicSettings':
            // same as existing api.php getPublicSettings
            break;
            
        default:
            http_response_code(404);
            echo json_encode(['error' => 'Unknown action']);
    }
    exit;
}

// ==============================
// HTML RENDERING (no action = show page)
// ==============================
$isLoggedIn = isClientLoggedIn();
$clientData = $isLoggedIn ? getClientData() : null;
$settings = getPublicSettings(); // function from auth.php

?>
<!DOCTYPE html>
<!-- ... HTML ... -->
```

### 5.2 كل Endpoint بالتفصيل

---

#### `POST /portal/index.php?action=client_login`

```
Method: POST (JSON body)
Auth: لا يحتاج
CSRF: لا يحتاج

Request Body:
{
    "email": "ahmed@company.com",
    "password": "mypassword123"
}

Response (نجاح):
{
    "success": true,
    "client": {
        "id": "c_1707926400_a3f2",
        "name": "أحمد",
        "email": "ahmed@company.com",
        "company": "Pyramedia",
        "role": "primary"
    }
}

Response (فشل):
{ "error": "بيانات الدخول غير صحيحة" }
{ "error": "الحساب مقفل. حاول بعد 15 دقيقة" }
{ "error": "الحساب معلّق. تواصل مع الإدارة" }

PHP Implementation Pattern:
```
```php
case 'client_login':
    $input = json_decode(file_get_contents('php://input'), true);
    $email = trim($input['email'] ?? '');
    $password = $input['password'] ?? '';
    
    if (!$email || !$password) {
        echo json_encode(['error' => 'البريد وكلمة المرور مطلوبين']);
        break;
    }
    
    usleep(200000); // brute force protection
    
    // Check lockout
    if (isClientAccountLocked($email)) {
        recordLoginAttempt('client:' . $email, false);
        echo json_encode(['error' => 'الحساب مقفل. حاول بعد 15 دقيقة']);
        break;
    }
    
    // Find client
    $result = dbRequest('GET', '/pyra_clients?email=eq.' . urlencode($email) . '&limit=1');
    $clients = $result['data'] ?? [];
    
    if (empty($clients) || !password_verify($password, $clients[0]['password_hash'])) {
        recordLoginAttempt('client:' . $email, false);
        echo json_encode(['error' => 'بيانات الدخول غير صحيحة']);
        break;
    }
    
    $client = $clients[0];
    
    if ($client['status'] !== 'active') {
        echo json_encode(['error' => 'الحساب معلّق. تواصل مع الإدارة']);
        break;
    }
    
    // Success
    session_regenerate_id(true);
    $_SESSION['client_id'] = $client['id'];
    $_SESSION['client_email'] = $client['email'];
    $_SESSION['client_name'] = $client['name'];
    $_SESSION['client_company'] = $client['company'];
    $_SESSION['client_role'] = $client['role'];
    $_SESSION['client_csrf_token'] = bin2hex(random_bytes(32));
    
    // Update last_login
    dbRequest('PATCH', '/pyra_clients?id=eq.' . urlencode($client['id']), [
        'last_login_at' => date('c')
    ]);
    
    recordLoginAttempt('client:' . $email, true);
    
    echo json_encode([
        'success' => true,
        'client' => [
            'id' => $client['id'],
            'name' => $client['name'],
            'email' => $client['email'],
            'company' => $client['company'],
            'role' => $client['role']
        ]
    ]);
    break;
```

---

#### `POST /portal/index.php?action=client_logout`

```
Method: POST
Auth: نعم
CSRF: نعم

Response: { "success": true }
```

```php
case 'client_logout':
    unset($_SESSION['client_id'], $_SESSION['client_email'], 
          $_SESSION['client_name'], $_SESSION['client_company'],
          $_SESSION['client_role'], $_SESSION['client_csrf_token']);
    echo json_encode(['success' => true]);
    break;
```

---

#### `GET /portal/index.php?action=client_session`

```
Method: GET
Auth: لا يحتاج (يفحص ويرجع)

Response (مسجل):
{
    "authenticated": true,
    "client": { "id": "...", "name": "...", "company": "...", "role": "..." },
    "csrf_token": "..."
}

Response (غير مسجل):
{ "authenticated": false }
```

---

#### `GET /portal/index.php?action=client_dashboard`

```
Method: GET
Auth: نعم

Response: (see section 2.2 above)
```

```php
case 'client_dashboard':
    $company = $_SESSION['client_company'];
    $clientId = $_SESSION['client_id'];
    
    // Active projects
    $projects = dbRequest('GET', '/pyra_projects?client_company=eq.' . urlencode($company) 
        . '&status=in.(active,in_progress,review,completed)&order=updated_at.desc&limit=5');
    
    // Count pending approvals
    $pendingApprovals = dbRequest('GET', '/pyra_file_approvals?client_id=eq.' . urlencode($clientId)
        . '&status=eq.pending&select=id,file_id,created_at,pyra_project_files(file_name,project_id,pyra_projects(name))&order=created_at.desc&limit=5');
    
    // Recent files
    $recentFiles = dbRequest('GET', '/pyra_project_files?select=id,file_name,file_size,mime_type,created_at,pyra_projects!inner(name,client_company)&pyra_projects.client_company=eq.' . urlencode($company) 
        . '&order=created_at.desc&limit=5');
    
    // Unread notifications
    $unreadCount = dbRequest('GET', '/pyra_client_notifications?client_id=eq.' . urlencode($clientId)
        . '&is_read=eq.false&select=count', ['Prefer' => 'count=exact']);
    
    // Recent notifications
    $recentNotifs = dbRequest('GET', '/pyra_client_notifications?client_id=eq.' . urlencode($clientId)
        . '&order=created_at.desc&limit=5');
    
    // Unread comments from team
    $unreadComments = dbRequest('GET', '/pyra_client_comments?select=count&author_type=eq.team&is_read_by_client=eq.false&pyra_projects!inner(client_company)&pyra_projects.client_company=eq.' . urlencode($company), ['Prefer' => 'count=exact']);
    
    echo json_encode([
        'success' => true,
        'dashboard' => [
            'client' => [
                'name' => $_SESSION['client_name'],
                'company' => $company,
                'last_login' => $client['last_login_at'] ?? null
            ],
            'projects' => [
                'total_active' => count($projects['data'] ?? []),
                'list' => $projects['data'] ?? []
            ],
            'pending_approvals' => [
                'total' => count($pendingApprovals['data'] ?? []),
                'list' => $pendingApprovals['data'] ?? []
            ],
            'recent_files' => [
                'total' => count($recentFiles['data'] ?? []),
                'list' => $recentFiles['data'] ?? []
            ],
            'unread_notifications' => intval($unreadCount['data'][0]['count'] ?? 0),
            'unread_comments' => intval($unreadComments['data'][0]['count'] ?? 0),
            'recent_notifications' => $recentNotifs['data'] ?? []
        ]
    ]);
    break;
```

---

#### `GET /portal/index.php?action=client_projects`

```
Method: GET
Auth: نعم
Parameters:
  - status (optional): "all" | "active" | "review" | "completed" — default "all" (excludes draft, archived)
  - page (optional): int — default 1

Response:
{
    "success": true,
    "projects": [...],
    "total": 8,
    "page": 1,
    "per_page": 20
}
```

---

#### `GET /portal/index.php?action=client_project_detail`

```
Method: GET
Auth: نعم
Parameters:
  - project_id (required): "prj_..."
  - file_category (optional): "all" | "design" | "video" | "document" | "audio" — default "all"
  - approval_filter (optional): "all" | "pending" | "approved" | "revision_requested" — default "all"
  - page (optional): int — default 1

Response:
{
    "success": true,
    "project": {
        "id": "prj_...",
        "name": "Social Media Campaign",
        "description": "...",
        "status": "review",
        "start_date": "2026-01-01",
        "deadline": "2026-03-15",
        "total_files": 12,
        "approved_files": 8,
        "pending_files": 3,
        "revision_files": 1
    },
    "files": [
        {
            "id": "pf_...",
            "file_name": "logo-v3.png",
            "file_path": "projects/pyramedia/social/logo-v3.png",
            "file_size": 2400000,
            "mime_type": "image/png",
            "category": "design",
            "version": 3,
            "needs_approval": true,
            "approval_status": "pending",
            "approval_comment": null,
            "uploaded_by": "mohammed",
            "created_at": "2026-02-14T..."
        }
    ],
    "total_files": 12,
    "page": 1,
    "per_page": 20
}

Errors:
{ "error": "المشروع غير موجود" }
```

---

#### `GET /portal/index.php?action=client_file_preview`

```
Method: GET
Auth: نعم
Parameters:
  - file_id (required): "pf_..."

Response:
{
    "success": true,
    "file": {
        "id": "pf_...",
        "file_name": "logo-v3.png",
        "file_path": "projects/pyramedia/social/logo-v3.png",
        "file_size": 2400000,
        "mime_type": "image/png",
        "category": "design",
        "version": 3,
        "needs_approval": true,
        "uploaded_by": "mohammed",
        "created_at": "2026-02-14T...",
        "public_url": "https://db.pyramedia.info/storage/v1/object/public/pyraai-workspace/projects/pyramedia/social/logo-v3.png",
        "project": {
            "id": "prj_...",
            "name": "Social Media Campaign"
        },
        "approval": {
            "id": "fa_...",
            "status": "pending",
            "comment": null,
            "updated_at": null
        }
    }
}
```

---

#### `GET /portal/index.php?action=client_download`

```
Method: GET
Auth: نعم
Parameters:
  - file_id (required): "pf_..."

Response: Binary file download (Content-Disposition: attachment)

Errors:
{ "error": "الملف غير موجود" }
```

---

#### `POST /portal/index.php?action=client_approve_file`

```
Method: POST (JSON)
Auth: نعم
CSRF: نعم
Role: primary فقط

Request: { "file_id": "pf_..." }
Response: { "success": true, "status": "approved" }

Side Effects:
1. تحديث pyra_file_approvals.status = 'approved'
2. إنشاء إشعار في pyra_notifications (للفريق):
   type='client_approval', title='العميل أحمد وافق على logo-v3.png'
```

---

#### `POST /portal/index.php?action=client_request_revision`

```
Method: POST (JSON)
Auth: نعم
CSRF: نعم
Role: primary فقط

Request: { "file_id": "pf_...", "comment": "اللون الثانوي مو مناسب" }
Response: { "success": true, "status": "revision_requested" }

Validation: comment مطلوب (لا يقل عن 10 حروف)

Side Effects:
1. تحديث pyra_file_approvals.status = 'revision_requested', comment = '...'
2. إنشاء إشعار في pyra_notifications (للفريق):
   type='client_revision_request', title='العميل أحمد طلب تعديل على logo-v3.png'
```

---

#### `GET /portal/index.php?action=client_get_comments`

```
Method: GET
Auth: نعم
Parameters:
  - project_id (required): "prj_..."
  - file_id (optional): "pf_..." — لو محدد يجيب تعليقات الملف فقط

Response:
{
    "success": true,
    "comments": [
        {
            "id": "cc_...",
            "author_type": "team",
            "author_name": "محمد",
            "text": "تم رفع النسخة النهائية",
            "parent_id": null,
            "created_at": "...",
            "replies": [
                {
                    "id": "cc_...",
                    "author_type": "client",
                    "author_name": "أحمد",
                    "text": "ممتاز!",
                    "parent_id": "cc_...",
                    "created_at": "..."
                }
            ]
        }
    ]
}
```

---

#### `POST /portal/index.php?action=client_add_comment`

```
Method: POST (JSON)
Auth: نعم
CSRF: نعم

Request:
{
    "project_id": "prj_...",
    "file_id": "pf_...",        // optional
    "text": "هل ممكن نغير الخط؟",
    "parent_id": "cc_..."       // optional (for replies)
}

Response:
{
    "success": true,
    "comment": {
        "id": "cc_...",
        "author_type": "client",
        "author_name": "أحمد",
        "text": "...",
        "created_at": "..."
    }
}

Validation: text مطلوب (3 حروف على الأقل)

Side Effects:
1. إنشاء record في pyra_client_comments (author_type='client')
2. إشعار في pyra_notifications للفريق (كل admins + المستخدمين اللي عندهم وصول لمسار المشروع)
```

---

#### `GET /portal/index.php?action=client_unread_count`

```
Method: GET
Auth: نعم

Response: { "count": 4 }
```

---

#### `GET /portal/index.php?action=client_notifications`

```
Method: GET
Auth: نعم
Parameters:
  - page (optional): int — default 1

Response:
{
    "success": true,
    "notifications": [
        {
            "id": "cn_...",
            "type": "new_file",
            "title": "ملف جديد: logo-v3.png",
            "message": "تم رفع ملف جديد في مشروع Social Media Campaign",
            "target_project_id": "prj_...",
            "target_file_id": "pf_...",
            "is_read": false,
            "created_at": "..."
        }
    ],
    "total": 15,
    "page": 1
}
```

---

#### `POST /portal/index.php?action=client_mark_notif_read`

```
Method: POST (JSON)
Auth: نعم
CSRF: نعم

Request: { "notification_id": "cn_..." }
Response: { "success": true }
```

---

#### `POST /portal/index.php?action=client_mark_all_read`

```
Method: POST
Auth: نعم
CSRF: نعم

Response: { "success": true }
```

---

#### `GET /portal/index.php?action=client_profile`

```
Method: GET
Auth: نعم

Response:
{
    "success": true,
    "client": {
        "id": "c_...",
        "name": "أحمد محمد",
        "email": "ahmed@company.com",
        "phone": "+971...",
        "company": "Pyramedia",
        "role": "primary",
        "avatar_url": "...",
        "language": "ar"
    }
}
```

---

#### `POST /portal/index.php?action=client_update_profile`

```
Method: POST (JSON)
Auth: نعم
CSRF: نعم

Request: { "name": "أحمد محمد", "phone": "+971...", "language": "ar" }
Response: { "success": true }

Validation:
- name: 2-100 حرف
- phone: 0-30 حرف (optional)
- language: "ar" or "en"
```

---

#### `POST /portal/index.php?action=client_change_password`

```
Method: POST (JSON)
Auth: نعم
CSRF: نعم

Request: { "current_password": "...", "new_password": "..." }
Response: { "success": true }

Errors:
{ "error": "كلمة المرور الحالية غير صحيحة" }
{ "error": "كلمة المرور الجديدة يجب أن تكون 8 حروف على الأقل" }
```

---

### 5.3 Endpoints جديدة في api.php (للفريق)

هذه الـ endpoints تُضاف في `api.php` الحالي (نفس النمط):

#### `POST /api.php?action=createClient`

```
Auth: admin فقط
Request: { "name": "أحمد", "email": "ahmed@company.com", "password": "...", "company": "Pyramedia", "phone": "...", "role": "primary" }
Response: { "success": true, "client": { "id": "c_..." } }
```

#### `GET /api.php?action=getClients`

```
Auth: admin فقط
Response: { "success": true, "clients": [...] }
```

#### `POST /api.php?action=updateClient`

```
Auth: admin فقط
Request: { "id": "c_...", "name": "...", "role": "...", "status": "..." }
```

#### `POST /api.php?action=deleteClient`

```
Auth: admin فقط
Request: { "id": "c_..." }
```

#### `POST /api.php?action=createProject`

```
Auth: admin فقط
Request: { "name": "...", "description": "...", "client_company": "Pyramedia", "status": "active", "start_date": "2026-01-01", "deadline": "2026-03-15", "storage_path": "projects/pyramedia/social" }
Response: { "success": true, "project": { "id": "prj_..." } }
```

#### `GET /api.php?action=getProjects`

```
Auth: admin فقط
Parameters: company (optional filter)
Response: { "success": true, "projects": [...] }
```

#### `POST /api.php?action=updateProject`

```
Auth: admin فقط
Request: { "id": "prj_...", "status": "review", ... }

Side Effects: لو status تغيّر → إشعار للعملاء
```

#### `POST /api.php?action=addProjectFile`

```
Auth: admin أو employee مع وصول
Request: { "project_id": "prj_...", "file_path": "projects/pyramedia/social/logo-v3.png", "file_name": "logo-v3.png", "file_size": 2400000, "mime_type": "image/png", "category": "design", "needs_approval": true }

Side Effects:
1. إنشاء record في pyra_project_files
2. لو needs_approval=true → إنشاء records في pyra_file_approvals لكل primary client في الشركة
3. إشعار للعملاء (pyra_client_notifications type='new_file')
```

#### `POST /api.php?action=team_reply_to_client`

```
Auth: admin أو employee
Request: { "project_id": "prj_...", "file_id": "pf_..." (optional), "text": "...", "parent_id": "cc_..." (optional) }

Creates record in pyra_client_comments with author_type='team'
Side Effects: إشعار للعميل (pyra_client_notifications type='comment_reply')
```

#### `GET /api.php?action=getClientComments`

```
Auth: admin أو employee
Parameters: project_id (required)
Response: { "success": true, "comments": [...] }
```

---

## 6. Frontend Components (التفصيل)

### 6.1 File Structure

```
portal/
├── index.php              ← Login screen + App shell (PHP renders HTML)
├── portal-app.js          ← Frontend controller (PortalApp object)
└── portal-style.css       ← Portal-specific styles (imports ../style.css base variables)
```

### 6.2 portal/index.php — HTML Structure

```html
<?php
// ... PHP logic (auth check, settings, etc.) ...
$isLoggedIn = isClientLoggedIn();
$clientData = $isLoggedIn ? getClientData() : null;
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($settings['app_name'] ?? 'Pyra Portal') ?> — Client Portal</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Noto+Sans+Arabic:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../style.css">
    <link rel="stylesheet" href="portal-style.css">
    <?php if ($settings['primary_color'] ?? ''): ?>
    <style>:root { --accent: <?= htmlspecialchars($settings['primary_color']) ?>; }</style>
    <?php endif; ?>
</head>
<body id="bodyEl" data-theme="pyramedia">

<?php if (!$isLoggedIn): ?>
    <!-- ============ LOGIN SCREEN ============ -->
    <div class="login-screen">
        <div class="login-particles">
            <span></span><span></span><span></span><span></span>
            <span></span><span></span><span></span><span></span>
        </div>
        <div class="login-card">
            <div class="login-logo">
                <!-- SVG Logo or img -->
                <h1><?= htmlspecialchars($settings['app_name'] ?? 'Pyramedia') ?></h1>
                <p class="login-subtitle">بوابة العملاء</p>
            </div>
            <form id="loginForm" onsubmit="PortalApp.handleLogin(event)">
                <div class="form-group">
                    <input type="email" id="loginEmail" placeholder="البريد الإلكتروني" required autocomplete="email">
                </div>
                <div class="form-group">
                    <input type="password" id="loginPassword" placeholder="كلمة المرور" required autocomplete="current-password">
                </div>
                <button type="submit" class="login-btn" id="loginBtn">تسجيل الدخول</button>
                <a href="#" class="login-forgot" onclick="PortalApp.showForgotPassword(event)">نسيت كلمة المرور؟</a>
            </form>
        </div>
    </div>
    
<?php else: ?>
    <!-- ============ APP SHELL ============ -->
    <div class="portal-app">
        <!-- Top Bar -->
        <div class="portal-top-bar">
            <div class="portal-logo">
                <span class="portal-logo-text"><?= htmlspecialchars($settings['app_name'] ?? 'Pyramedia') ?></span>
            </div>
            <div class="portal-nav">
                <button class="portal-nav-btn portal-nav-active" data-screen="dashboard" onclick="PortalApp.showScreen('dashboard')">🏠 الرئيسية</button>
                <button class="portal-nav-btn" data-screen="projects" onclick="PortalApp.showScreen('projects')">📁 المشاريع</button>
                <button class="portal-nav-btn" data-screen="notifications" onclick="PortalApp.showScreen('notifications')">
                    🔔 الإشعارات
                    <span class="portal-notif-badge" id="portal-notif-badge" style="display:none">0</span>
                </button>
            </div>
            <div class="portal-user-menu">
                <span class="portal-user-name"><?= htmlspecialchars($clientData['name']) ?></span>
                <button class="portal-btn-icon" onclick="PortalApp.showScreen('profile')" title="الملف الشخصي">👤</button>
                <button class="portal-btn-icon" onclick="PortalApp.handleLogout()" title="تسجيل الخروج">🚪</button>
            </div>
        </div>
        
        <!-- Main Content Area -->
        <div class="portal-main" id="portal-main">
            <!-- Dynamic content rendered by portal-app.js -->
            <div class="portal-loading" id="portal-loading">
                <div class="portal-spinner"></div>
            </div>
        </div>
    </div>
    
    <!-- Toast Container -->
    <div class="toast-container" id="toastContainer"></div>
    
    <!-- Modal -->
    <div class="modal-overlay" id="modalOverlay" style="display:none">
        <div class="modal" id="modalContent"></div>
    </div>
    
    <script>
        window.PORTAL_CONFIG = {
            supabaseUrl: '<?= SUPABASE_URL ?>',
            bucket: '<?= BUCKET ?>',
            client: <?= json_encode($clientData) ?>,
            csrf_token: '<?= $_SESSION['client_csrf_token'] ?>',
            settings: <?= json_encode($settings) ?>
        };
    </script>
    <script src="portal-app.js"></script>
<?php endif; ?>

</body>
</html>
```

### 6.3 portal-app.js — Frontend Controller

```javascript
const PortalApp = {
    // ============ State ============
    currentScreen: 'dashboard',
    currentProject: null,
    currentFile: null,
    client: null,
    
    // ============ Init ============
    init() {
        this.client = window.PORTAL_CONFIG.client;
        this.showScreen('dashboard');
        this.initNotifications();
    },

    // ============ API Helper ============
    async apiFetch(endpoint, options = {}) {
        const url = 'index.php' + endpoint;
        const defaults = {
            headers: {
                'X-CSRF-Token': window.PORTAL_CONFIG.csrf_token
            }
        };
        if (options.body && typeof options.body === 'object' && !(options.body instanceof FormData)) {
            defaults.headers['Content-Type'] = 'application/json';
            options.body = JSON.stringify(options.body);
        }
        return fetch(url, { ...defaults, ...options });
    },

    // ============ Auth ============
    async handleLogin(e) {
        e.preventDefault();
        const btn = document.getElementById('loginBtn');
        btn.disabled = true;
        btn.textContent = 'جاري الدخول...';
        
        try {
            const res = await fetch('index.php?action=client_login', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    email: document.getElementById('loginEmail').value,
                    password: document.getElementById('loginPassword').value
                })
            });
            const data = await res.json();
            if (data.success) {
                location.reload();
            } else {
                this.toast(data.error || 'خطأ في تسجيل الدخول', 'error');
                btn.disabled = false;
                btn.textContent = 'تسجيل الدخول';
            }
        } catch (err) {
            this.toast('خطأ في الاتصال', 'error');
            btn.disabled = false;
            btn.textContent = 'تسجيل الدخول';
        }
    },

    async handleLogout() {
        await this.apiFetch('?action=client_logout', { method: 'POST' });
        location.reload();
    },

    // ============ Screen Router ============
    showScreen(screen, params = {}) {
        this.currentScreen = screen;
        
        // Update nav
        document.querySelectorAll('.portal-nav-btn').forEach(btn => {
            btn.classList.toggle('portal-nav-active', btn.dataset.screen === screen);
        });
        
        switch (screen) {
            case 'dashboard': this.renderDashboard(); break;
            case 'projects': this.renderProjects(); break;
            case 'project_detail': this.renderProjectDetail(params.projectId); break;
            case 'file_preview': this.renderFilePreview(params.fileId); break;
            case 'notifications': this.renderNotifications(); break;
            case 'profile': this.renderProfile(); break;
        }
    },

    // ============ Dashboard ============
    async renderDashboard() {
        const main = document.getElementById('portal-main');
        main.innerHTML = '<div class="portal-loading"><div class="portal-spinner"></div></div>';
        
        try {
            const res = await this.apiFetch('?action=client_dashboard');
            const data = await res.json();
            if (!data.success) throw new Error(data.error);
            
            const d = data.dashboard;
            main.innerHTML = `
                <div class="portal-dashboard">
                    <div class="portal-welcome-card">
                        <h2>👋 مرحباً، ${this.escHtml(d.client.name)}</h2>
                        <p>${this.escHtml(d.client.company)}</p>
                    </div>
                    
                    <div class="portal-dashboard-grid">
                        <div class="portal-card" onclick="PortalApp.showScreen('projects')">
                            <div class="portal-card-header">
                                <span class="portal-card-icon">📁</span>
                                <span class="portal-card-count">${d.projects.total_active}</span>
                            </div>
                            <h3>المشاريع النشطة</h3>
                            <div class="portal-card-list">
                                ${d.projects.list.map(p => `
                                    <div class="portal-card-list-item" onclick="event.stopPropagation(); PortalApp.showScreen('project_detail', {projectId: '${this.escAttr(p.id)}'})">
                                        <span>${this.escHtml(p.name)}</span>
                                        <span class="portal-status-badge status-${this.escAttr(p.status)}">${this.statusLabel(p.status)}</span>
                                    </div>
                                `).join('')}
                            </div>
                        </div>
                        
                        <div class="portal-card">
                            <div class="portal-card-header">
                                <span class="portal-card-icon">⏳</span>
                                <span class="portal-card-count">${d.pending_approvals.total}</span>
                            </div>
                            <h3>بانتظار موافقتك</h3>
                            <div class="portal-card-list">
                                ${d.pending_approvals.list.map(a => `
                                    <div class="portal-card-list-item">
                                        <span>${this.escHtml(a.file_name || a.pyra_project_files?.file_name || '')}</span>
                                        <time>${this.formatDate(a.created_at)}</time>
                                    </div>
                                `).join('')}
                            </div>
                        </div>
                        
                        <div class="portal-card">
                            <div class="portal-card-header">
                                <span class="portal-card-icon">📄</span>
                                <span class="portal-card-count">${d.recent_files.total}</span>
                            </div>
                            <h3>آخر الملفات</h3>
                            <div class="portal-card-list">
                                ${d.recent_files.list.map(f => `
                                    <div class="portal-card-list-item">
                                        <span>${this.escHtml(f.file_name)}</span>
                                        <span>${this.formatSize(f.file_size)}</span>
                                    </div>
                                `).join('')}
                            </div>
                        </div>
                        
                        <div class="portal-card" onclick="PortalApp.showScreen('notifications')">
                            <div class="portal-card-header">
                                <span class="portal-card-icon">🔔</span>
                                <span class="portal-card-count">${d.unread_notifications}</span>
                            </div>
                            <h3>الإشعارات</h3>
                            <div class="portal-card-list">
                                ${d.recent_notifications.map(n => `
                                    <div class="portal-card-list-item ${n.is_read ? '' : 'portal-unread'}">
                                        <span>${this.escHtml(n.title)}</span>
                                        <time>${this.formatDate(n.created_at)}</time>
                                    </div>
                                `).join('')}
                            </div>
                        </div>
                    </div>
                </div>
            `;
        } catch (err) {
            main.innerHTML = `<div class="portal-error">خطأ في تحميل البيانات: ${this.escHtml(err.message)}</div>`;
        }
    },

    // ============ Projects List ============
    async renderProjects(page = 1) {
        const main = document.getElementById('portal-main');
        main.innerHTML = '<div class="portal-loading"><div class="portal-spinner"></div></div>';
        
        try {
            const res = await this.apiFetch(`?action=client_projects&page=${page}`);
            const data = await res.json();
            if (!data.success) throw new Error(data.error);
            
            main.innerHTML = `
                <div class="portal-projects">
                    <h2 class="portal-page-title">📁 المشاريع</h2>
                    <div class="portal-projects-grid">
                        ${data.projects.map(p => `
                            <div class="portal-project-card" onclick="PortalApp.showScreen('project_detail', {projectId: '${this.escAttr(p.id)}'})">
                                <div class="portal-project-cover">
                                    ${p.cover_image ? `<img src="${this.escAttr(p.cover_image)}" alt="" loading="lazy">` : '<div class="portal-project-cover-placeholder">📁</div>'}
                                    <span class="portal-status-badge status-${this.escAttr(p.status)}">${this.statusLabel(p.status)}</span>
                                </div>
                                <div class="portal-project-info">
                                    <h3>${this.escHtml(p.name)}</h3>
                                    ${p.description ? `<p>${this.escHtml(p.description.substring(0, 100))}</p>` : ''}
                                    <div class="portal-project-meta">
                                        ${p.deadline ? `<span>📅 ${p.deadline}</span>` : ''}
                                    </div>
                                </div>
                            </div>
                        `).join('')}
                    </div>
                    ${this.renderPagination(data.total, page, data.per_page, 'renderProjects')}
                </div>
            `;
        } catch (err) {
            main.innerHTML = `<div class="portal-error">${this.escHtml(err.message)}</div>`;
        }
    },

    // ============ Project Detail ============
    async renderProjectDetail(projectId, page = 1) {
        this.currentProject = projectId;
        const main = document.getElementById('portal-main');
        main.innerHTML = '<div class="portal-loading"><div class="portal-spinner"></div></div>';
        
        try {
            const res = await this.apiFetch(`?action=client_project_detail&project_id=${encodeURIComponent(projectId)}&page=${page}`);
            const data = await res.json();
            if (!data.success) throw new Error(data.error);
            
            const p = data.project;
            const progress = p.pending_files + p.approved_files > 0 
                ? Math.round((p.approved_files / (p.pending_files + p.approved_files + p.revision_files)) * 100) 
                : 0;
            
            main.innerHTML = `
                <div class="portal-project-detail">
                    <button class="portal-back-btn" onclick="PortalApp.showScreen('projects')">← رجوع</button>
                    
                    <div class="portal-project-header">
                        <h2>${this.escHtml(p.name)}</h2>
                        <span class="portal-status-badge status-${this.escAttr(p.status)}">${this.statusLabel(p.status)}</span>
                    </div>
                    ${p.description ? `<p class="portal-project-desc">${this.escHtml(p.description)}</p>` : ''}
                    
                    <div class="portal-project-dates">
                        ${p.start_date ? `<span>📅 بداية: ${p.start_date}</span>` : ''}
                        ${p.deadline ? `<span>📅 تسليم: ${p.deadline}</span>` : ''}
                    </div>
                    
                    ${progress > 0 ? `
                    <div class="portal-progress-container">
                        <div class="portal-progress-bar" style="width: ${progress}%"></div>
                        <span class="portal-progress-text">${progress}% تمت الموافقة</span>
                    </div>
                    ` : ''}
                    
                    <div class="portal-project-stats">
                        <span class="portal-stat">📁 ${p.total_files} ملف</span>
                        <span class="portal-stat portal-stat-pending">⏳ ${p.pending_files} بانتظار</span>
                        <span class="portal-stat portal-stat-approved">✅ ${p.approved_files} موافق</span>
                        <span class="portal-stat portal-stat-revision">❌ ${p.revision_files} تعديل</span>
                    </div>
                    
                    <div class="portal-files-list">
                        <h3>الملفات</h3>
                        ${data.files.map(f => this.renderFileItem(f)).join('')}
                    </div>
                    
                    ${this.renderPagination(data.total_files, page, data.per_page, `renderProjectDetail.bind(PortalApp, '${projectId}')`)}
                    
                    <div class="portal-comments-section" id="project-comments">
                        <!-- Comments loaded separately -->
                    </div>
                </div>
            `;
            
            // Load comments
            this.loadComments(projectId);
        } catch (err) {
            main.innerHTML = `<div class="portal-error">${this.escHtml(err.message)}</div>`;
        }
    },
    
    renderFileItem(f) {
        const icon = this.getFileIcon(f.mime_type);
        const approvalHtml = f.needs_approval ? this.renderApprovalBadge(f) : '';
        
        return `
            <div class="portal-file-item" data-file-id="${this.escAttr(f.id)}">
                <div class="portal-file-icon">${icon}</div>
                <div class="portal-file-info">
                    <span class="portal-file-name" onclick="PortalApp.showScreen('file_preview', {fileId: '${this.escAttr(f.id)}'})">${this.escHtml(f.file_name)}</span>
                    <span class="portal-file-meta">${this.formatSize(f.file_size)} · v${f.version} · ${this.formatDate(f.created_at)}</span>
                </div>
                <div class="portal-file-actions">
                    ${approvalHtml}
                    <button class="portal-btn-icon" onclick="PortalApp.showScreen('file_preview', {fileId: '${this.escAttr(f.id)}'})" title="معاينة">👁️</button>
                    <button class="portal-btn-icon" onclick="PortalApp.downloadFile('${this.escAttr(f.id)}')" title="تحميل">⬇️</button>
                </div>
            </div>
        `;
    },
    
    renderApprovalBadge(f) {
        const status = f.approval_status || 'pending';
        const badges = {
            'pending': '<span class="portal-approval-badge pending">⏳ بانتظار</span>',
            'approved': '<span class="portal-approval-badge approved">✅ موافق</span>',
            'revision_requested': '<span class="portal-approval-badge revision">❌ تعديل</span>'
        };
        return badges[status] || '';
    },

    // ============ File Preview ============
    async renderFilePreview(fileId) {
        this.currentFile = fileId;
        const main = document.getElementById('portal-main');
        main.innerHTML = '<div class="portal-loading"><div class="portal-spinner"></div></div>';
        
        try {
            const res = await this.apiFetch(`?action=client_file_preview&file_id=${encodeURIComponent(fileId)}`);
            const data = await res.json();
            if (!data.success) throw new Error(data.error);
            
            const f = data.file;
            const previewHtml = this.getPreviewHtml(f);
            const approvalHtml = f.needs_approval ? this.renderApprovalSection(f) : '';
            
            main.innerHTML = `
                <div class="portal-file-preview">
                    <button class="portal-back-btn" onclick="PortalApp.showScreen('project_detail', {projectId: '${this.escAttr(f.project.id)}'})">← ${this.escHtml(f.project.name)}</button>
                    
                    <div class="portal-preview-header">
                        <h2>${this.escHtml(f.file_name)}</h2>
                        <div class="portal-preview-meta">
                            <span>${this.formatSize(f.file_size)}</span>
                            <span>v${f.version}</span>
                            <span>${this.formatDate(f.created_at)}</span>
                        </div>
                        <button class="portal-btn portal-btn-secondary" onclick="PortalApp.downloadFile('${this.escAttr(f.id)}')">⬇️ تحميل</button>
                    </div>
                    
                    <div class="portal-preview-body">
                        ${previewHtml}
                    </div>
                    
                    ${approvalHtml}
                    
                    <div class="portal-comments-section" id="file-comments">
                        <!-- Comments loaded separately -->
                    </div>
                </div>
            `;
            
            // Load file-specific comments
            this.loadComments(f.project.id, f.id);
        } catch (err) {
            main.innerHTML = `<div class="portal-error">${this.escHtml(err.message)}</div>`;
        }
    },
    
    getPreviewHtml(f) {
        const url = f.public_url;
        const mime = f.mime_type || '';
        
        if (mime.startsWith('image/')) {
            return `<img src="${this.escAttr(url)}" class="portal-preview-image" alt="${this.escAttr(f.file_name)}">`;
        }
        if (mime === 'application/pdf') {
            return `<iframe src="${this.escAttr(url)}" class="portal-preview-pdf"></iframe>`;
        }
        if (mime.startsWith('video/')) {
            return `<video controls class="portal-preview-video"><source src="${this.escAttr(url)}" type="${this.escAttr(mime)}"></video>`;
        }
        if (mime.startsWith('audio/')) {
            return `<audio controls class="portal-preview-audio"><source src="${this.escAttr(url)}" type="${this.escAttr(mime)}"></audio>`;
        }
        // Other files - just show info
        return `
            <div class="portal-preview-placeholder">
                <span class="portal-preview-icon">${this.getFileIcon(mime)}</span>
                <p>لا يمكن معاينة هذا الملف</p>
                <button class="portal-btn portal-btn-primary" onclick="PortalApp.downloadFile('${this.escAttr(f.id)}')">⬇️ تحميل الملف</button>
            </div>
        `;
    },
    
    renderApprovalSection(f) {
        const approval = f.approval;
        if (!approval) return '';
        
        if (approval.status === 'pending') {
            return `
                <div class="portal-approval-section">
                    <h3>⏳ هذا الملف بانتظار موافقتك</h3>
                    <div class="portal-approval-actions">
                        <button class="portal-btn portal-btn-approve" onclick="PortalApp.approveFile('${this.escAttr(f.id)}')">✅ موافقة</button>
                        <button class="portal-btn portal-btn-revision" onclick="PortalApp.toggleRevisionForm()">❌ طلب تعديل</button>
                    </div>
                    <div class="portal-revision-form" id="revisionForm" style="display:none">
                        <textarea class="portal-input" id="revisionComment" placeholder="وصف التعديلات المطلوبة..." rows="3"></textarea>
                        <button class="portal-btn portal-btn-submit" onclick="PortalApp.requestRevision('${this.escAttr(f.id)}')">إرسال طلب التعديل</button>
                    </div>
                </div>
            `;
        }
        if (approval.status === 'approved') {
            return `
                <div class="portal-approval-section portal-approval-done">
                    <h3>✅ تمت الموافقة</h3>
                    <small>${this.formatDate(approval.updated_at)}</small>
                </div>
            `;
        }
        if (approval.status === 'revision_requested') {
            return `
                <div class="portal-approval-section portal-approval-revision">
                    <h3>❌ طُلب تعديل</h3>
                    <p class="portal-revision-comment">${this.escHtml(approval.comment || '')}</p>
                    <small>${this.formatDate(approval.updated_at)}</small>
                </div>
            `;
        }
        return '';
    },

    // ============ Approval Actions ============
    async approveFile(fileId) {
        if (!confirm('هل تريد الموافقة على هذا الملف؟')) return;
        try {
            const res = await this.apiFetch('?action=client_approve_file', {
                method: 'POST',
                body: { file_id: fileId }
            });
            const data = await res.json();
            if (data.success) {
                this.toast('✅ تمت الموافقة', 'success');
                this.renderFilePreview(fileId); // refresh
            } else {
                this.toast(data.error || 'خطأ', 'error');
            }
        } catch (err) {
            this.toast('خطأ في الاتصال', 'error');
        }
    },
    
    toggleRevisionForm() {
        const form = document.getElementById('revisionForm');
        form.style.display = form.style.display === 'none' ? 'block' : 'none';
    },
    
    async requestRevision(fileId) {
        const comment = document.getElementById('revisionComment').value.trim();
        if (comment.length < 10) {
            this.toast('يرجى كتابة وصف التعديلات (10 حروف على الأقل)', 'error');
            return;
        }
        try {
            const res = await this.apiFetch('?action=client_request_revision', {
                method: 'POST',
                body: { file_id: fileId, comment: comment }
            });
            const data = await res.json();
            if (data.success) {
                this.toast('❌ تم إرسال طلب التعديل', 'success');
                this.renderFilePreview(fileId); // refresh
            } else {
                this.toast(data.error || 'خطأ', 'error');
            }
        } catch (err) {
            this.toast('خطأ في الاتصال', 'error');
        }
    },

    // ============ Comments ============
    async loadComments(projectId, fileId = null) {
        const containerId = fileId ? 'file-comments' : 'project-comments';
        const container = document.getElementById(containerId);
        if (!container) return;
        
        let url = `?action=client_get_comments&project_id=${encodeURIComponent(projectId)}`;
        if (fileId) url += `&file_id=${encodeURIComponent(fileId)}`;
        
        try {
            const res = await this.apiFetch(url);
            const data = await res.json();
            if (!data.success) return;
            
            container.innerHTML = `
                <h3 class="portal-section-title">💬 التعليقات</h3>
                <div class="portal-comments-list">
                    ${data.comments.map(c => this.renderComment(c)).join('')}
                    ${data.comments.length === 0 ? '<p class="portal-empty">لا توجد تعليقات بعد</p>' : ''}
                </div>
                <div class="portal-comment-form">
                    <textarea class="portal-input" id="newCommentText" placeholder="اكتب تعليقك..." rows="2"></textarea>
                    <button class="portal-btn portal-btn-primary" onclick="PortalApp.addComment('${this.escAttr(projectId)}', ${fileId ? "'" + this.escAttr(fileId) + "'" : 'null'})">إرسال</button>
                </div>
            `;
            
            // Mark team comments as read
            this.markCommentsRead(projectId, fileId);
        } catch (err) {
            console.error('Error loading comments:', err);
        }
    },
    
    renderComment(c) {
        const isTeam = c.author_type === 'team';
        const replies = (c.replies || []).map(r => this.renderComment(r)).join('');
        
        return `
            <div class="portal-comment ${isTeam ? 'portal-comment-team' : 'portal-comment-client'}">
                <div class="portal-comment-body">
                    <div class="portal-comment-header">
                        <strong>${this.escHtml(c.author_name)}</strong>
                        <span class="portal-comment-badge ${isTeam ? 'team' : 'client'}">${isTeam ? 'فريق' : 'عميل'}</span>
                        <time>${this.formatDate(c.created_at)}</time>
                    </div>
                    <p class="portal-comment-text">${this.escHtml(c.text)}</p>
                    <button class="portal-comment-reply-btn" onclick="PortalApp.showReplyForm('${this.escAttr(c.id)}')">رد</button>
                    <div class="portal-reply-form" id="reply-form-${this.escAttr(c.id)}" style="display:none">
                        <textarea class="portal-input portal-input-small" id="reply-text-${this.escAttr(c.id)}" placeholder="اكتب ردك..." rows="2"></textarea>
                        <button class="portal-btn portal-btn-small" onclick="PortalApp.addReply('${this.escAttr(c.id)}')">رد</button>
                    </div>
                </div>
                ${replies ? `<div class="portal-comment-replies">${replies}</div>` : ''}
            </div>
        `;
    },
    
    showReplyForm(commentId) {
        const form = document.getElementById('reply-form-' + commentId);
        form.style.display = form.style.display === 'none' ? 'flex' : 'none';
    },
    
    async addComment(projectId, fileId) {
        const textEl = document.getElementById('newCommentText');
        const text = textEl.value.trim();
        if (text.length < 3) {
            this.toast('اكتب 3 حروف على الأقل', 'error');
            return;
        }
        
        const body = { project_id: projectId, text: text };
        if (fileId) body.file_id = fileId;
        
        try {
            const res = await this.apiFetch('?action=client_add_comment', {
                method: 'POST',
                body: body
            });
            const data = await res.json();
            if (data.success) {
                textEl.value = '';
                this.loadComments(projectId, fileId); // refresh
            } else {
                this.toast(data.error || 'خطأ', 'error');
            }
        } catch (err) {
            this.toast('خطأ في الاتصال', 'error');
        }
    },

    async addReply(commentId) {
        const textEl = document.getElementById('reply-text-' + commentId);
        const text = textEl.value.trim();
        if (text.length < 3) {
            this.toast('اكتب 3 حروف على الأقل', 'error');
            return;
        }
        
        try {
            const res = await this.apiFetch('?action=client_add_comment', {
                method: 'POST',
                body: { parent_id: commentId, text: text }
            });
            const data = await res.json();
            if (data.success) {
                textEl.value = '';
                // Refresh comments - find project context
                if (this.currentProject) {
                    this.loadComments(this.currentProject, this.currentFile);
                }
            } else {
                this.toast(data.error || 'خطأ', 'error');
            }
        } catch (err) {
            this.toast('خطأ في الاتصال', 'error');
        }
    },

    async markCommentsRead(projectId, fileId) {
        let url = `?action=client_mark_comments_read&project_id=${encodeURIComponent(projectId)}`;
        if (fileId) url += `&file_id=${encodeURIComponent(fileId)}`;
        await this.apiFetch(url, { method: 'POST' });
    },

    // ============ Notifications ============
    async renderNotifications() {
        const main = document.getElementById('portal-main');
        main.innerHTML = '<div class="portal-loading"><div class="portal-spinner"></div></div>';
        
        try {
            const res = await this.apiFetch('?action=client_notifications');
            const data = await res.json();
            if (!data.success) throw new Error(data.error);
            
            main.innerHTML = `
                <div class="portal-notifications">
                    <div class="portal-page-header">
                        <h2 class="portal-page-title">🔔 الإشعارات</h2>
                        ${data.notifications.length > 0 ? `<button class="portal-btn portal-btn-secondary" onclick="PortalApp.markAllNotificationsRead()">قراءة الكل</button>` : ''}
                    </div>
                    <div class="portal-notifications-list">
                        ${data.notifications.map(n => `
                            <div class="portal-notification-item ${n.is_read ? '' : 'portal-unread'}" onclick="PortalApp.handleNotificationClick('${this.escAttr(n.id)}', '${this.escAttr(n.target_type || '')}', '${this.escAttr(n.target_id || '')}')">
                                <div class="portal-notification-icon">${this.notifIcon(n.type)}</div>
                                <div class="portal-notification-body">
                                    <strong>${this.escHtml(n.title)}</strong>
                                    <p>${this.escHtml(n.message)}</p>
                                    <time>${this.formatDate(n.created_at)}</time>
                                </div>
                            </div>
                        `).join('')}
                        ${data.notifications.length === 0 ? '<p class="portal-empty">لا توجد إشعارات</p>' : ''}
                    </div>
                </div>
            `;
        } catch (err) {
            main.innerHTML = `<div class="portal-error">${this.escHtml(err.message)}</div>`;
        }
    },

    initNotifications() {
        this.pollNotifications();
        setInterval(() => this.pollNotifications(), 30000);
    },

    async pollNotifications() {
        try {
            const res = await this.apiFetch('?action=client_unread_count');
            const data = await res.json();
            if (data.success) {
                const badge = document.getElementById('portal-notif-badge');
                if (badge) {
                    badge.textContent = data.count;
                    badge.style.display = data.count > 0 ? 'inline' : 'none';
                }
            }
        } catch (err) { /* silent */ }
    },

    async markAllNotificationsRead() {
        await this.apiFetch('?action=client_mark_all_read', { method: 'POST' });
        this.pollNotifications();
        this.renderNotifications(); // refresh
    },

    handleNotificationClick(notifId, targetType, targetId) {
        // Mark as read
        this.apiFetch('?action=client_mark_notif_read', {
            method: 'POST',
            body: { notification_id: notifId }
        });
        // Navigate
        if (targetType === 'project' && targetId) {
            this.showScreen('project_detail', { projectId: targetId });
        } else if (targetType === 'file' && targetId) {
            this.showScreen('file_preview', { fileId: targetId });
        }
    },

    notifIcon(type) {
        const icons = {
            'file_uploaded': '📄',
            'comment_added': '💬',
            'approval_requested': '⏳',
            'file_approved': '✅',
            'revision_requested': '❌',
            'project_updated': '📁',
            'deadline_reminder': '⏰'
        };
        return icons[type] || '🔔';
    },

    // ============ Profile ============
    async renderProfile() {
        const main = document.getElementById('portal-main');
        main.innerHTML = '<div class="portal-loading"><div class="portal-spinner"></div></div>';
        
        try {
            const res = await this.apiFetch('?action=client_profile');
            const data = await res.json();
            if (!data.success) throw new Error(data.error);
            
            const p = data.profile;
            main.innerHTML = `
                <div class="portal-profile">
                    <h2 class="portal-page-title">👤 الملف الشخصي</h2>
                    <div class="portal-profile-card">
                        <div class="portal-profile-avatar">${this.escHtml(p.name.charAt(0))}</div>
                        <div class="portal-profile-info">
                            <h3>${this.escHtml(p.name)}</h3>
                            <p>${this.escHtml(p.company)}</p>
                            <p>${this.escHtml(p.email)}</p>
                            ${p.phone ? `<p>${this.escHtml(p.phone)}</p>` : ''}
                        </div>
                    </div>
                    <div class="portal-profile-form">
                        <h3>تغيير كلمة المرور</h3>
                        <input type="password" id="currentPassword" class="portal-input" placeholder="كلمة المرور الحالية">
                        <input type="password" id="newPassword" class="portal-input" placeholder="كلمة المرور الجديدة">
                        <input type="password" id="confirmPassword" class="portal-input" placeholder="تأكيد كلمة المرور">
                        <button class="portal-btn portal-btn-primary" onclick="PortalApp.changePassword()">تحديث كلمة المرور</button>
                    </div>
                </div>
            `;
        } catch (err) {
            main.innerHTML = `<div class="portal-error">${this.escHtml(err.message)}</div>`;
        }
    },

    async changePassword() {
        const current = document.getElementById('currentPassword').value;
        const newPass = document.getElementById('newPassword').value;
        const confirm = document.getElementById('confirmPassword').value;
        
        if (!current || !newPass || !confirm) {
            this.toast('يرجى تعبئة كل الحقول', 'error');
            return;
        }
        if (newPass !== confirm) {
            this.toast('كلمة المرور الجديدة غير متطابقة', 'error');
            return;
        }
        if (newPass.length < 8) {
            this.toast('كلمة المرور لازم 8 حروف على الأقل', 'error');
            return;
        }
        
        try {
            const res = await this.apiFetch('?action=client_change_password', {
                method: 'POST',
                body: { current_password: current, new_password: newPass }
            });
            const data = await res.json();
            if (data.success) {
                this.toast('✅ تم تغيير كلمة المرور', 'success');
                document.getElementById('currentPassword').value = '';
                document.getElementById('newPassword').value = '';
                document.getElementById('confirmPassword').value = '';
            } else {
                this.toast(data.error || 'خطأ', 'error');
            }
        } catch (err) {
            this.toast('خطأ في الاتصال', 'error');
        }
    },

    // ============ Downloads ============
    async downloadFile(fileId) {
        try {
            const res = await this.apiFetch(`?action=client_download_url&file_id=${encodeURIComponent(fileId)}`);
            const data = await res.json();
            if (data.success && data.url) {
                const a = document.createElement('a');
                a.href = data.url;
                a.download = '';
                document.body.appendChild(a);
                a.click();
                a.remove();
            } else {
                this.toast(data.error || 'خطأ في التحميل', 'error');
            }
        } catch (err) {
            this.toast('خطأ في الاتصال', 'error');
        }
    },

    // ============ Pagination ============
    renderPagination(total, currentPage, perPage, callback) {
        const totalPages = Math.ceil(total / perPage);
        if (totalPages <= 1) return '';
        
        let html = '<div class="portal-pagination">';
        for (let i = 1; i <= totalPages; i++) {
            html += `<button class="portal-page-btn ${i === currentPage ? 'active' : ''}" onclick="${callback}(${i})">${i}</button>`;
        }
        html += '</div>';
        return html;
    },

    // ============ Status Labels ============
    statusLabel(status) {
        const labels = {
            'active': 'نشط',
            'review': 'مراجعة',
            'completed': 'مكتمل',
            'on_hold': 'متوقف',
            'draft': 'مسودة'
        };
        return labels[status] || status;
    },

    // ============ Utilities ============
    getFileIcon(mime) {
        if (!mime) return '📄';
        if (mime.startsWith('image/')) return '🖼️';
        if (mime.startsWith('video/')) return '🎬';
        if (mime.startsWith('audio/')) return '🎵';
        if (mime === 'application/pdf') return '📕';
        if (mime.includes('word') || mime.includes('document')) return '📘';
        if (mime.includes('sheet') || mime.includes('excel')) return '📊';
        if (mime.includes('presentation') || mime.includes('powerpoint')) return '📙';
        if (mime.includes('zip') || mime.includes('archive')) return '📦';
        return '📄';
    },

    formatSize(bytes) {
        if (!bytes) return '—';
        const units = ['B', 'KB', 'MB', 'GB'];
        let i = 0;
        let size = bytes;
        while (size >= 1024 && i < units.length - 1) { size /= 1024; i++; }
        return size.toFixed(i > 0 ? 1 : 0) + ' ' + units[i];
    },

    formatDate(dateStr) {
        if (!dateStr) return '';
        const d = new Date(dateStr);
        const now = new Date();
        const diff = (now - d) / 1000;
        
        if (diff < 60) return 'الآن';
        if (diff < 3600) return Math.floor(diff / 60) + ' د';
        if (diff < 86400) return Math.floor(diff / 3600) + ' س';
        if (diff < 604800) return Math.floor(diff / 86400) + ' ي';
        return d.toLocaleDateString('ar-SA');
    },

    escHtml(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.textContent = String(str);
        return div.innerHTML;
    },

    escAttr(str) {
        return String(str || '').replace(/[&"'<>]/g, c => ({
            '&': '&amp;', '"': '&quot;', "'": '&#39;', '<': '&lt;', '>': '&gt;'
        }[c]));
    },

    toast(msg, type = 'info') {
        const container = document.getElementById('toastContainer');
        if (!container) return;
        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        toast.textContent = msg;
        container.appendChild(toast);
        setTimeout(() => toast.classList.add('toast-show'), 10);
        setTimeout(() => {
            toast.classList.remove('toast-show');
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    },

    showLoading(show) {
        const el = document.getElementById('portal-loading');
        if (el) el.style.display = show ? 'flex' : 'none';
    }
};

// ============ Init on DOM Ready ============
document.addEventListener('DOMContentLoaded', () => PortalApp.init());
```

### 6.4 portal-style.css — Portal Styles

```css
/* ============================================================
   Portal Client Styles
   يستورد الـ base variables من ../style.css
   ============================================================ */

/* ---- Layout ---- */
.portal-app {
    min-height: 100vh;
    background: var(--bg-primary);
    color: var(--text-primary);
    font-family: var(--font-arabic), var(--font-sans), sans-serif;
}

.portal-top-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 24px;
    height: 64px;
    background: var(--bg-glass);
    backdrop-filter: blur(12px);
    border-bottom: 1px solid rgba(255,255,255,0.06);
    position: sticky;
    top: 0;
    z-index: 100;
}

.portal-logo-text {
    font-size: 1.25rem;
    font-weight: 700;
    color: var(--accent);
}

.portal-nav {
    display: flex;
    gap: 4px;
}

.portal-nav-btn {
    background: transparent;
    border: none;
    color: var(--text-secondary);
    padding: 8px 16px;
    border-radius: var(--radius);
    cursor: pointer;
    font-family: inherit;
    font-size: 0.9rem;
    transition: all 0.2s;
    position: relative;
}

.portal-nav-btn:hover {
    background: rgba(255,255,255,0.05);
    color: var(--text-primary);
}

.portal-nav-btn.portal-nav-active {
    background: rgba(var(--accent-rgb, 139,92,246), 0.15);
    color: var(--accent);
}

.portal-notif-badge {
    position: absolute;
    top: 2px;
    right: 2px;
    background: #ef4444;
    color: white;
    font-size: 0.65rem;
    width: 18px;
    height: 18px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    animation: pulse 2s infinite;
}

.portal-user-menu {
    display: flex;
    align-items: center;
    gap: 12px;
}

.portal-user-name {
    font-size: 0.9rem;
    color: var(--text-secondary);
}

.portal-btn-icon {
    background: transparent;
    border: none;
    font-size: 1.2rem;
    cursor: pointer;
    padding: 6px;
    border-radius: var(--radius);
    transition: background 0.2s;
}

.portal-btn-icon:hover {
    background: rgba(255,255,255,0.08);
}

/* ---- Main Content ---- */
.portal-main {
    max-width: 1200px;
    margin: 0 auto;
    padding: 24px;
    min-height: calc(100vh - 64px);
}

.portal-loading {
    display: flex;
    justify-content: center;
    align-items: center;
    min-height: 200px;
}

.portal-spinner {
    width: 40px;
    height: 40px;
    border: 3px solid rgba(255,255,255,0.1);
    border-top-color: var(--accent);
    border-radius: 50%;
    animation: spin 0.8s linear infinite;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

/* ---- Cards & Dashboard ---- */
.portal-dashboard {
    animation: fadeInUp 0.4s ease;
}

.portal-welcome-card {
    background: var(--bg-glass);
    backdrop-filter: blur(12px);
    border: 1px solid rgba(255,255,255,0.06);
    border-radius: calc(var(--radius) * 1.5);
    padding: 32px;
    margin-bottom: 24px;
}

.portal-welcome-card h2 {
    margin: 0 0 8px;
    font-size: 1.5rem;
}

.portal-welcome-card p {
    margin: 0;
    color: var(--text-secondary);
}

.portal-dashboard-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 16px;
}

.portal-card {
    background: var(--bg-secondary);
    border: 1px solid rgba(255,255,255,0.06);
    border-radius: calc(var(--radius) * 1.5);
    padding: 20px;
    cursor: pointer;
    transition: transform 0.2s, border-color 0.2s;
}

.portal-card:hover {
    transform: translateY(-2px);
    border-color: rgba(var(--accent-rgb, 139,92,246), 0.3);
}

.portal-card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 12px;
}

.portal-card-icon {
    font-size: 1.5rem;
}

.portal-card-count {
    font-size: 2rem;
    font-weight: 700;
    color: var(--accent);
}

.portal-card h3 {
    margin: 0 0 12px;
    font-size: 1rem;
    color: var(--text-secondary);
}

.portal-card-list {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.portal-card-list-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 0.85rem;
    padding: 6px 0;
    border-top: 1px solid rgba(255,255,255,0.04);
}

/* ---- Status Badges ---- */
.portal-status-badge {
    font-size: 0.75rem;
    padding: 2px 8px;
    border-radius: 12px;
    font-weight: 600;
}

.status-active { background: rgba(16,185,129,0.15); color: #10b981; }
.status-review { background: rgba(245,158,11,0.15); color: #f59e0b; }
.status-completed { background: rgba(59,130,246,0.15); color: #3b82f6; }
.status-on_hold { background: rgba(239,68,68,0.15); color: #ef4444; }
.status-draft { background: rgba(107,114,128,0.15); color: #6b7280; }

/* ---- Projects ---- */
.portal-projects-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 20px;
}

.portal-project-card {
    background: var(--bg-secondary);
    border: 1px solid rgba(255,255,255,0.06);
    border-radius: calc(var(--radius) * 1.5);
    overflow: hidden;
    cursor: pointer;
    transition: transform 0.2s, border-color 0.2s;
}

.portal-project-card:hover {
    transform: translateY(-2px);
    border-color: rgba(var(--accent-rgb, 139,92,246), 0.3);
}

.portal-project-cover {
    height: 160px;
    position: relative;
    background: var(--bg-primary);
    overflow: hidden;
}

.portal-project-cover img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.portal-project-cover-placeholder {
    display: flex;
    align-items: center;
    justify-content: center;
    height: 100%;
    font-size: 3rem;
    opacity: 0.3;
}

.portal-project-cover .portal-status-badge {
    position: absolute;
    top: 12px;
    right: 12px;
}

.portal-project-info {
    padding: 16px;
}

.portal-project-info h3 {
    margin: 0 0 8px;
    font-size: 1.1rem;
}

.portal-project-info p {
    margin: 0;
    font-size: 0.85rem;
    color: var(--text-secondary);
}

.portal-project-meta {
    margin-top: 12px;
    font-size: 0.8rem;
    color: var(--text-secondary);
}

/* ---- Project Detail ---- */
.portal-back-btn {
    background: transparent;
    border: 1px solid rgba(255,255,255,0.1);
    color: var(--text-secondary);
    padding: 8px 16px;
    border-radius: var(--radius);
    cursor: pointer;
    font-family: inherit;
    margin-bottom: 16px;
    transition: all 0.2s;
}

.portal-back-btn:hover {
    border-color: var(--accent);
    color: var(--accent);
}

.portal-project-header {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 8px;
}

.portal-project-header h2 {
    margin: 0;
}

.portal-project-desc {
    color: var(--text-secondary);
    margin-bottom: 16px;
}

.portal-project-dates {
    display: flex;
    gap: 24px;
    font-size: 0.9rem;
    color: var(--text-secondary);
    margin-bottom: 16px;
}

.portal-progress-container {
    background: rgba(255,255,255,0.05);
    border-radius: 8px;
    height: 24px;
    position: relative;
    overflow: hidden;
    margin-bottom: 16px;
}

.portal-progress-bar {
    height: 100%;
    background: linear-gradient(90deg, var(--accent), #10b981);
    border-radius: 8px;
    transition: width 0.5s ease;
}

.portal-progress-text {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    font-size: 0.75rem;
    font-weight: 600;
}

.portal-project-stats {
    display: flex;
    gap: 16px;
    flex-wrap: wrap;
    margin-bottom: 24px;
}

.portal-stat {
    font-size: 0.9rem;
    padding: 6px 12px;
    background: rgba(255,255,255,0.05);
    border-radius: var(--radius);
}

.portal-stat-pending { color: #f59e0b; }
.portal-stat-approved { color: #10b981; }
.portal-stat-revision { color: #ef4444; }

/* ---- File Items ---- */
.portal-files-list h3 {
    margin-bottom: 12px;
    padding-bottom: 8px;
    border-bottom: 1px solid rgba(255,255,255,0.06);
}

.portal-file-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px;
    border-radius: var(--radius);
    transition: background 0.2s;
}

.portal-file-item:hover {
    background: rgba(255,255,255,0.03);
}

.portal-file-icon {
    font-size: 1.5rem;
    width: 40px;
    text-align: center;
}

.portal-file-info {
    flex: 1;
    min-width: 0;
}

.portal-file-name {
    display: block;
    font-weight: 500;
    cursor: pointer;
    transition: color 0.2s;
}

.portal-file-name:hover {
    color: var(--accent);
}

.portal-file-meta {
    font-size: 0.8rem;
    color: var(--text-secondary);
}

.portal-file-actions {
    display: flex;
    align-items: center;
    gap: 8px;
}

/* ---- Approval ---- */
.portal-approval-badge {
    font-size: 0.75rem;
    padding: 2px 8px;
    border-radius: 12px;
}

.portal-approval-badge.pending { background: rgba(245,158,11,0.15); color: #f59e0b; }
.portal-approval-badge.approved { background: rgba(16,185,129,0.15); color: #10b981; }
.portal-approval-badge.revision { background: rgba(239,68,68,0.15); color: #ef4444; }

.portal-approval-section {
    background: var(--bg-secondary);
    border: 1px solid rgba(255,255,255,0.06);
    border-radius: calc(var(--radius) * 1.5);
    padding: 24px;
    margin: 24px 0;
}

.portal-approval-section h3 {
    margin: 0 0 16px;
}

.portal-approval-actions {
    display: flex;
    gap: 12px;
}

.portal-btn {
    padding: 10px 20px;
    border: none;
    border-radius: var(--radius);
    cursor: pointer;
    font-family: inherit;
    font-size: 0.9rem;
    font-weight: 500;
    transition: all 0.2s;
}

.portal-btn-primary {
    background: var(--accent);
    color: white;
}

.portal-btn-primary:hover {
    filter: brightness(1.1);
}

.portal-btn-secondary {
    background: rgba(255,255,255,0.08);
    color: var(--text-primary);
}

.portal-btn-secondary:hover {
    background: rgba(255,255,255,0.12);
}

.portal-btn-approve {
    background: rgba(16,185,129,0.2);
    color: #10b981;
}

.portal-btn-approve:hover {
    background: rgba(16,185,129,0.3);
}

.portal-btn-revision {
    background: rgba(239,68,68,0.2);
    color: #ef4444;
}

.portal-btn-revision:hover {
    background: rgba(239,68,68,0.3);
}

.portal-btn-small {
    padding: 6px 12px;
    font-size: 0.8rem;
}

.portal-btn-submit {
    background: var(--accent);
    color: white;
    margin-top: 8px;
}

.portal-revision-form {
    margin-top: 16px;
}

.portal-approval-done {
    border-color: rgba(16,185,129,0.2);
}

.portal-approval-revision {
    border-color: rgba(239,68,68,0.2);
}

/* ---- File Preview ---- */
.portal-preview-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
    margin-bottom: 16px;
}

.portal-preview-header h2 {
    margin: 0;
}

.portal-preview-meta {
    display: flex;
    gap: 16px;
    font-size: 0.85rem;
    color: var(--text-secondary);
}

.portal-preview-body {
    background: var(--bg-secondary);
    border: 1px solid rgba(255,255,255,0.06);
    border-radius: calc(var(--radius) * 1.5);
    overflow: hidden;
    margin-bottom: 24px;
}

.portal-preview-image {
    max-width: 100%;
    height: auto;
    display: block;
}

.portal-preview-pdf {
    width: 100%;
    height: 80vh;
    border: none;
}

.portal-preview-video {
    width: 100%;
    max-height: 70vh;
}

.portal-preview-audio {
    width: 100%;
    padding: 24px;
}

.portal-preview-placeholder {
    text-align: center;
    padding: 48px;
    color: var(--text-secondary);
}

.portal-preview-icon {
    font-size: 4rem;
    display: block;
    margin-bottom: 16px;
}

/* ---- Comments ---- */
.portal-section-title {
    margin-bottom: 16px;
    padding-bottom: 8px;
    border-bottom: 1px solid rgba(255,255,255,0.06);
}

.portal-comments-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
    margin-bottom: 16px;
}

.portal-comment {
    border-radius: var(--radius);
    padding: 12px;
}

.portal-comment-team {
    background: rgba(var(--accent-rgb, 139,92,246), 0.08);
    border-right: 3px solid var(--accent);
}

.portal-comment-client {
    background: rgba(255,255,255,0.03);
    border-right: 3px solid var(--text-secondary);
}

.portal-comment-header {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 8px;
    font-size: 0.85rem;
}

.portal-comment-badge {
    font-size: 0.7rem;
    padding: 1px 6px;
    border-radius: 8px;
}

.portal-comment-badge.team {
    background: rgba(var(--accent-rgb, 139,92,246), 0.2);
    color: var(--accent);
}

.portal-comment-badge.client {
    background: rgba(255,255,255,0.1);
    color: var(--text-secondary);
}

.portal-comment-text {
    margin: 0;
    line-height: 1.6;
}

.portal-comment-reply-btn {
    background: transparent;
    border: none;
    color: var(--accent);
    font-size: 0.8rem;
    cursor: pointer;
    padding: 4px 0;
    margin-top: 6px;
}

.portal-reply-form {
    display: flex;
    gap: 8px;
    margin-top: 8px;
    align-items: flex-end;
}

.portal-comment-replies {
    margin-right: 20px;
    padding-right: 12px;
    border-right: 1px solid rgba(255,255,255,0.06);
}

.portal-comment-form {
    display: flex;
    gap: 8px;
    align-items: flex-end;
}

/* ---- Inputs ---- */
.portal-input {
    width: 100%;
    background: rgba(255,255,255,0.05);
    border: 1px solid rgba(255,255,255,0.1);
    border-radius: var(--radius);
    padding: 10px 14px;
    color: var(--text-primary);
    font-family: inherit;
    font-size: 0.9rem;
    transition: border-color 0.2s;
    resize: vertical;
}

.portal-input:focus {
    outline: none;
    border-color: var(--accent);
}

.portal-input-small {
    padding: 6px 10px;
    font-size: 0.85rem;
}

/* ---- Notifications ---- */
.portal-page-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 24px;
}

.portal-page-title {
    margin: 0;
}

.portal-notifications-list {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.portal-notification-item {
    display: flex;
    gap: 12px;
    padding: 16px;
    background: var(--bg-secondary);
    border: 1px solid rgba(255,255,255,0.06);
    border-radius: var(--radius);
    cursor: pointer;
    transition: all 0.2s;
}

.portal-notification-item:hover {
    border-color: rgba(var(--accent-rgb, 139,92,246), 0.3);
}

.portal-notification-item.portal-unread {
    border-right: 3px solid var(--accent);
    background: rgba(var(--accent-rgb, 139,92,246), 0.05);
}

.portal-notification-icon {
    font-size: 1.5rem;
}

.portal-notification-body strong {
    display: block;
    margin-bottom: 4px;
}

.portal-notification-body p {
    margin: 0;
    font-size: 0.85rem;
    color: var(--text-secondary);
}

.portal-notification-body time {
    font-size: 0.75rem;
    color: var(--text-secondary);
}

/* ---- Profile ---- */
.portal-profile-card {
    display: flex;
    align-items: center;
    gap: 20px;
    background: var(--bg-secondary);
    border: 1px solid rgba(255,255,255,0.06);
    border-radius: calc(var(--radius) * 1.5);
    padding: 24px;
    margin-bottom: 24px;
}

.portal-profile-avatar {
    width: 64px;
    height: 64px;
    background: linear-gradient(135deg, var(--accent), #ec4899);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    font-weight: 700;
}

.portal-profile-info h3 {
    margin: 0 0 4px;
}

.portal-profile-info p {
    margin: 0;
    font-size: 0.9rem;
    color: var(--text-secondary);
}

.portal-profile-form {
    background: var(--bg-secondary);
    border: 1px solid rgba(255,255,255,0.06);
    border-radius: calc(var(--radius) * 1.5);
    padding: 24px;
}

.portal-profile-form h3 {
    margin: 0 0 16px;
}

.portal-profile-form .portal-input {
    margin-bottom: 12px;
}

/* ---- Pagination ---- */
.portal-pagination {
    display: flex;
    justify-content: center;
    gap: 8px;
    margin: 24px 0;
}

.portal-page-btn {
    width: 36px;
    height: 36px;
    background: var(--bg-secondary);
    border: 1px solid rgba(255,255,255,0.1);
    border-radius: var(--radius);
    color: var(--text-secondary);
    cursor: pointer;
    transition: all 0.2s;
}

.portal-page-btn:hover, .portal-page-btn.active {
    background: var(--accent);
    color: white;
    border-color: var(--accent);
}

/* ---- Empty & Error States ---- */
.portal-empty {
    text-align: center;
    color: var(--text-secondary);
    padding: 24px;
    font-size: 0.9rem;
}

.portal-error {
    text-align: center;
    color: #ef4444;
    padding: 48px;
    font-size: 1rem;
}

/* ---- Responsive ---- */
@media (max-width: 768px) {
    .portal-top-bar {
        flex-wrap: wrap;
        height: auto;
        padding: 12px 16px;
        gap: 8px;
    }
    
    .portal-nav {
        order: 3;
        width: 100%;
        justify-content: center;
    }
    
    .portal-nav-btn {
        font-size: 0.8rem;
        padding: 6px 10px;
    }
    
    .portal-main {
        padding: 16px;
    }
    
    .portal-dashboard-grid {
        grid-template-columns: 1fr;
    }
    
    .portal-projects-grid {
        grid-template-columns: 1fr;
    }
    
    .portal-project-stats {
        flex-direction: column;
    }
    
    .portal-approval-actions {
        flex-direction: column;
    }
    
    .portal-comment-form {
        flex-direction: column;
    }
    
    .portal-preview-header {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .portal-profile-card {
        flex-direction: column;
        text-align: center;
    }
}

@media (max-width: 600px) {
    .portal-user-name {
        display: none;
    }
    
    .portal-welcome-card {
        padding: 20px;
    }
    
    .portal-welcome-card h2 {
        font-size: 1.2rem;
    }
}

/* ---- RTL Support ---- */
[dir="rtl"] .portal-comment-team {
    border-right: none;
    border-left: 3px solid var(--accent);
}

[dir="rtl"] .portal-comment-client {
    border-right: none;
    border-left: 3px solid var(--text-secondary);
}

[dir="rtl"] .portal-notification-item.portal-unread {
    border-right: none;
    border-left: 3px solid var(--accent);
}

[dir="rtl"] .portal-comment-replies {
    margin-right: 0;
    margin-left: 20px;
    padding-right: 0;
    padding-left: 12px;
    border-right: none;
    border-left: 1px solid rgba(255,255,255,0.06);
}

/* ---- Animations (reuse from main style.css) ---- */
@keyframes fadeInUp {
    from { opacity: 0; transform: translateY(16px); }
    to { opacity: 1; transform: translateY(0); }
}

/* Toast styles already defined in ../style.css — portal inherits them */
```

---

## 7. File Structure (هيكل الملفات)

### 7.1 الملفات الجديدة

```
pyra-workspace/
├── portal/                        ← 📁 مجلد جديد — بوابة العميل
│   ├── index.php                  ← صفحة الدخول + App shell (PHP + HTML)
│   ├── portal-app.js              ← Frontend controller (PortalApp object)
│   └── portal-style.css           ← CSS خاص بالبوابة
│
├── portal-schema.sql              ← 📄 جديد — DDL للجداول الجديدة (6 جداول)
│
├── (الملفات الحالية...)
│   ├── api.php                    ← ✏️ تعديل — endpoints جديدة للفريق
│   ├── auth.php                   ← ✏️ تعديل — client auth functions
│   ├── schema.sql                 ← (لا تعديل — الجداول الجديدة في ملف منفصل)
│   └── ...
```

### 7.2 الملفات الحالية اللي نعدلها

| الملف | نوع التعديل | التفاصيل |
|-------|------------|---------|
| `api.php` | إضافة endpoints | إضافة ~8 cases جديدة في switch statement للفريق (client management, project management) — **نفس النمط الحالي بالضبط** |
| `auth.php` | إضافة functions | إضافة `isClientLoggedIn()`, `getClientData()`, `clientDbRequest()`, `validateClientSession()` — أسفل الملف بعد الـ functions الحالية |
| `index.php` | إضافة رابط (اختياري) | إضافة رابط "بوابة العملاء" في صفحة تسجيل الدخول — تعديل بسيط جداً |

### 7.3 ⚠️ قواعد التعديل على api.php

```
✅ المسموح:
- إضافة cases جديدة في الـ switch($action) الموجود
- اتباع نفس نمط الـ try/catch + jsonResponse()
- استخدام نفس الـ helper functions (dbRequest, logActivity, sendNotification)

❌ الممنوع:
- تغيير أي case موجود
- تغيير بنية الـ switch أو الـ routing
- إضافة includes أو requires جديدة (ما عدا config.php الموجود)
- تغيير الـ headers أو الـ session handling الموجود
```

### 7.4 ⚠️ قواعد التعديل على auth.php

```
✅ المسموح:
- إضافة functions جديدة في نهاية الملف
- استخدام نفس الـ dbRequest() function الموجودة

❌ الممنوع:
- تغيير أي function موجودة
- تغيير session configuration
- تغيير الـ security headers
```

---

## 8. خطة التنفيذ (Implementation Plan)

### Phase 1: Database + Supabase Setup
> **المدة المتوقعة:** 2-3 ساعات

**الملفات المتأثرة:**
- `portal-schema.sql` (جديد)

**المهام:**
1. إنشاء ملف `portal-schema.sql` مع الجداول الستة:
   - `pyra_clients`
   - `pyra_projects`
   - `pyra_project_files`
   - `pyra_file_approvals`
   - `pyra_portal_comments`
   - `pyra_client_notifications`
2. إضافة الـ indexes المطلوبة
3. إنشاء الـ views (`v_project_summary`, `v_pending_approvals`)
4. تنفيذ الـ SQL على Supabase

**Acceptance Criteria:**
- [ ] كل الجداول تُنشأ بنجاح
- [ ] الـ foreign keys صحيحة
- [ ] الـ indexes تعمل
- [ ] الـ views ترجع بيانات صحيحة
- [ ] الـ ID generation يتبع نمط `c_timestamp_random` الموجود

**Dependencies:** لا يوجد — هذي أول خطوة

---

### Phase 2: API Endpoints (Portal Backend)
> **المدة المتوقعة:** 4-6 ساعات

**الملفات المتأثرة:**
- `portal/index.php` (جديد — backend API للبوابة)
- `auth.php` (تعديل — إضافة client auth functions)
- `api.php` (تعديل — إضافة team-facing endpoints)

**المهام:**
1. إضافة client auth functions في `auth.php`:
   - `isClientLoggedIn()` — فحص session العميل
   - `getClientData()` — جلب بيانات العميل
   - `validateClientSession()` — تحقق من صلاحية الجلسة
   - `clientDbRequest()` — wrapper لـ dbRequest مع client_id filter
2. إنشاء `portal/index.php` مع:
   - Client login/logout
   - Client dashboard
   - Projects list + detail
   - File preview + download
   - Approval actions (approve/request revision)
   - Comments CRUD
   - Notifications
   - Profile + password change
3. إضافة team endpoints في `api.php`:
   - `manage_clients` — CRUD للعملاء
   - `manage_projects` — CRUD للمشاريع
   - `manage_project_files` — إدارة ملفات المشروع
   - `get_client_activity` — نشاط العميل
   - `project_approvals_overview` — ملخص الموافقات

**Acceptance Criteria:**
- [ ] كل endpoint يرجع JSON صحيح
- [ ] Client auth مستقل عن team auth (session keys مختلفة)
- [ ] كل query يفلتر بـ `client_id` — عميل ما يشوف بيانات غيره
- [ ] CSRF protection شغال
- [ ] Error handling موحد (نفس نمط jsonResponse)
- [ ] Input validation لكل endpoint

**Dependencies:** Phase 1 (الجداول لازم تكون موجودة)

---

### Phase 3: Client Login Page
> **المدة المتوقعة:** 2-3 ساعات

**الملفات المتأثرة:**
- `portal/index.php` (تعديل — إضافة HTML rendering)
- `portal/portal-style.css` (جديد — جزئياً)

**المهام:**
1. رسم Login screen في `portal/index.php`:
   - نفس تصميم Login الحالي (particles + login card)
   - عنوان "بوابة العملاء"
   - حقل email + password
   - رابط "نسيت كلمة المرور"
2. PHP logic:
   - فحص `isClientLoggedIn()`
   - عرض Login أو App حسب الحالة
   - تمرير `PORTAL_CONFIG` للـ JS
3. CSS:
   - استيراد `../style.css` (base variables)
   - Login animations (نفس النمط)

**Acceptance Criteria:**
- [ ] Login screen يظهر بنفس جودة تصميم الـ workspace
- [ ] Login بـ email + password يشتغل
- [ ] Account lockout بعد 5 محاولات فاشلة
- [ ] Session تتجدد عند الدخول (session_regenerate_id)
- [ ] Redirect لـ Dashboard بعد Login ناجح
- [ ] "نسيت كلمة المرور" تعرض رسالة (تواصل مع الفريق)
- [ ] RTL يشتغل صح

**Dependencies:** Phase 2 (auth endpoints)

---

### Phase 4: Client Dashboard
> **المدة المتوقعة:** 3-4 ساعات

**الملفات المتأثرة:**
- `portal/portal-app.js` (جديد — renderDashboard + renderProjects)
- `portal/portal-style.css` (تعديل — dashboard styles)

**المهام:**
1. Dashboard:
   - بطاقة ترحيب (اسم + شركة)
   - المشاريع النشطة (عدد + قائمة)
   - بانتظار الموافقة (عدد + قائمة)
   - آخر الملفات
   - الإشعارات الأخيرة
2. Projects list:
   - Grid cards مع cover image
   - Status badge
   - Deadline
   - عدد الملفات
3. Pagination

**Acceptance Criteria:**
- [ ] Dashboard يحمّل في أقل من 2 ثانية
- [ ] الكروت clickable وتنقل للمشروع
- [ ] Status badges بألوان صحيحة
- [ ] Empty states واضحة
- [ ] Responsive — يشتغل على mobile
- [ ] Animations (fadeInUp) نفس النمط

**Dependencies:** Phase 2 + Phase 3

---

### Phase 5: Project View + File Approval
> **المدة المتوقعة:** 4-5 ساعات

**الملفات المتأثرة:**
- `portal/portal-app.js` (تعديل — renderProjectDetail + renderFilePreview)
- `portal/portal-style.css` (تعديل — file preview + approval styles)

**المهام:**
1. Project Detail:
   - معلومات المشروع (اسم، وصف، تواريخ)
   - Progress bar (نسبة الموافقة)
   - إحصائيات (ملفات، بانتظار، موافق، تعديل)
   - قائمة الملفات مع badges
2. File Preview:
   - معاينة حسب النوع (image, video, audio, PDF, أخرى)
   - معلومات الملف (حجم، إصدار، تاريخ)
   - زر تحميل
3. Approval Workflow:
   - زر ✅ موافقة (confirm dialog)
   - زر ❌ طلب تعديل (textarea + submit)
   - عرض حالة الموافقة الحالية

**Acceptance Criteria:**
- [ ] كل أنواع الملفات تُعرض صح (image, video, audio, PDF)
- [ ] Download يشتغل
- [ ] Approve يغير الحالة ويرسل إشعار للفريق
- [ ] Request Revision يطلب comment (10 حروف minimum)
- [ ] Progress bar يتحدث بعد الموافقة
- [ ] File versions تظهر (v1, v2...)
- [ ] الملفات تظهر بالترتيب الصحيح

**Dependencies:** Phase 4

---

### Phase 6: Comments + Notifications
> **المدة المتوقعة:** 3-4 ساعات

**الملفات المتأثرة:**
- `portal/portal-app.js` (تعديل — comments + notifications)
- `portal/portal-style.css` (تعديل — comment styles)
- `api.php` (تعديل — team notification endpoints)

**المهام:**
1. Comments:
   - قسم التعليقات أسفل كل مشروع/ملف
   - Threaded replies (ردود متسلسلة)
   - تمييز بين تعليقات الفريق والعميل
   - إضافة/رد على تعليقات
2. Notifications:
   - صفحة الإشعارات
   - Badge عدد غير المقروءة (polling 30s)
   - قراءة الكل
   - الضغط على الإشعار ينقل للمشروع/الملف
3. Team-side:
   - إشعار الفريق عند تعليق العميل
   - إشعار عند موافقة/طلب تعديل

**Acceptance Criteria:**
- [ ] التعليقات threaded (ردود تحت التعليق الأصلي)
- [ ] تمييز واضح (team vs client)
- [ ] Polling كل 30 ثانية
- [ ] Badge يتحدث تلقائياً
- [ ] الإشعارات clickable وتنقل للمحتوى
- [ ] XSS protection على كل النصوص (escHtml)
- [ ] RTL — الردود indented من اليمين

**Dependencies:** Phase 5

---

### Phase 7: Email Notifications
> **المدة المتوقعة:** 2-3 ساعات

**الملفات المتأثرة:**
- `auth.php` (تعديل — إضافة `sendClientEmail()`)
- `portal/index.php` (تعديل — trigger emails)

**المهام:**
1. Email function:
   - `sendClientEmail($to, $subject, $body)` — PHP `mail()` أو SMTP
   - HTML email template (branded, RTL)
2. Triggers:
   - عند إضافة ملف جديد للمشروع → email للعميل
   - عند رد الفريق على تعليق العميل → email
   - عند اقتراب الـ deadline (يدوي — الفريق يضغط زر)
   - عند إنشاء حساب عميل جديد → email ترحيب
3. Settings:
   - `email_notifications_enabled` (on/off)
   - `smtp_host`, `smtp_port`, `smtp_user`, `smtp_pass` في `config.php`

**Acceptance Criteria:**
- [ ] الإيميلات تصل (تحقق من Spam)
- [ ] HTML template يظهر صح (RTL + branding)
- [ ] إعدادات SMTP قابلة للتغيير
- [ ] لو SMTP فشل → لا يوقف العملية (try/catch + log)
- [ ] Rate limiting — ما يرسل أكثر من 10 emails/minute

**Dependencies:** Phase 6

---

### Phase 8: Testing + Polish
> **المدة المتوقعة:** 2-3 ساعات

**الملفات المتأثرة:**
- كل الملفات (مراجعة + تصحيح)

**المهام:**
1. Functional Testing:
   - إنشاء عميل test
   - Login/Logout
   - Dashboard loads
   - Projects list
   - File preview (كل الأنواع)
   - Approve + Request Revision
   - Comments + Replies
   - Notifications
   - Profile + Password change
2. Security Testing:
   - عميل يحاول يشوف بيانات عميل ثاني → ❌
   - عميل يحاول يعدل بيانات مو حقته → ❌
   - XSS attempt في التعليقات → ❌
   - CSRF token validation
   - SQL injection attempts → ❌
3. Responsive Testing:
   - Desktop (1440px+)
   - Tablet (768px)
   - Mobile (375px)
4. RTL Verification:
   - كل عنصر يظهر صح بالعربي
   - Indentation صحيح
   - Icons في المكان الصح
5. Polish:
   - Loading states واضحة
   - Error messages مفهومة
   - Empty states ودّية
   - Animations smooth

**Acceptance Criteria:**
- [ ] كل الـ flows تشتغل بدون أخطاء
- [ ] ما في console errors
- [ ] Responsive يشتغل صح على 3 أحجام
- [ ] RTL صحيح 100%
- [ ] Security tests pass
- [ ] Performance: كل صفحة تحت 3 ثواني

**Dependencies:** كل الـ phases السابقة

---

## 9. أمثلة كود (Code Patterns)

> ⚠️ كل الأمثلة مبنية على الكود الحالي في Pyra Workspace — التزم بنفس النمط بالضبط

### 9.1 مثال PHP Endpoint في api.php

```php
// ============ في api.php — داخل switch($action) ============

// --- إدارة العملاء (admin فقط) ---
case 'manage_clients':
    requireAuth();
    requireAdmin();
    
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        // جلب كل العملاء
        $result = dbRequest('GET', '/pyra_clients?select=id,name,email,company,phone,is_active,created_at&order=created_at.desc');
        jsonResponse(['success' => true, 'clients' => $result['data'] ?? []]);
    }
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $sub = $input['sub_action'] ?? '';
        
        switch ($sub) {
            case 'create':
                $name = trim($input['name'] ?? '');
                $email = trim($input['email'] ?? '');
                $company = trim($input['company'] ?? '');
                $password = $input['password'] ?? '';
                
                if (!$name || !$email || !$company || strlen($password) < 8) {
                    jsonResponse(['success' => false, 'error' => 'بيانات ناقصة']);
                }
                
                // فحص تكرار الإيميل
                $exists = dbRequest('GET', '/pyra_clients?email=eq.' . urlencode($email) . '&limit=1');
                if (!empty($exists['data'])) {
                    jsonResponse(['success' => false, 'error' => 'الإيميل موجود مسبقاً']);
                }
                
                $id = 'c_' . time() . '_' . bin2hex(random_bytes(2));
                $data = [
                    'id' => $id,
                    'name' => $name,
                    'email' => $email,
                    'company' => $company,
                    'phone' => trim($input['phone'] ?? ''),
                    'password_hash' => password_hash($password, PASSWORD_BCRYPT),
                    'is_active' => true,
                    'created_by' => $_SESSION['user'],
                    'created_at' => date('c')
                ];
                
                $result = dbRequest('POST', '/pyra_clients', $data, ['Prefer: return=representation']);
                
                logActivity('client_created', $id, ['client_name' => $name, 'company' => $company]);
                jsonResponse(['success' => true, 'client' => $result['data'][0] ?? $data]);
                break;
                
            case 'update':
                $id = $input['id'] ?? '';
                if (!$id) jsonResponse(['success' => false, 'error' => 'معرّف العميل مطلوب']);
                
                $updates = [];
                if (isset($input['name'])) $updates['name'] = trim($input['name']);
                if (isset($input['company'])) $updates['company'] = trim($input['company']);
                if (isset($input['phone'])) $updates['phone'] = trim($input['phone']);
                if (isset($input['is_active'])) $updates['is_active'] = (bool)$input['is_active'];
                
                if (!empty($updates)) {
                    $updates['updated_at'] = date('c');
                    dbRequest('PATCH', '/pyra_clients?id=eq.' . urlencode($id), $updates);
                }
                
                logActivity('client_updated', $id, $updates);
                jsonResponse(['success' => true]);
                break;
                
            case 'delete':
                $id = $input['id'] ?? '';
                if (!$id) jsonResponse(['success' => false, 'error' => 'معرّف العميل مطلوب']);
                
                dbRequest('DELETE', '/pyra_clients?id=eq.' . urlencode($id));
                logActivity('client_deleted', $id);
                jsonResponse(['success' => true]);
                break;
                
            default:
                jsonResponse(['success' => false, 'error' => 'عملية غير معروفة']);
        }
    }
    break;

// --- إدارة المشاريع (admin/employee) ---
case 'manage_projects':
    requireAuth();
    
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $clientId = $_GET['client_id'] ?? '';
        $query = '/pyra_projects?select=*&order=created_at.desc';
        if ($clientId) $query .= '&client_id=eq.' . urlencode($clientId);
        
        $result = dbRequest('GET', $query);
        jsonResponse(['success' => true, 'projects' => $result['data'] ?? []]);
    }
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        $sub = $input['sub_action'] ?? '';
        
        switch ($sub) {
            case 'create':
                $name = trim($input['name'] ?? '');
                $clientId = $input['client_id'] ?? '';
                
                if (!$name || !$clientId) {
                    jsonResponse(['success' => false, 'error' => 'اسم المشروع والعميل مطلوبين']);
                }
                
                $id = 'p_' . time() . '_' . bin2hex(random_bytes(2));
                $data = [
                    'id' => $id,
                    'client_id' => $clientId,
                    'name' => $name,
                    'description' => trim($input['description'] ?? ''),
                    'status' => 'active',
                    'start_date' => $input['start_date'] ?? date('Y-m-d'),
                    'deadline' => $input['deadline'] ?? null,
                    'created_by' => $_SESSION['user'],
                    'created_at' => date('c')
                ];
                
                $result = dbRequest('POST', '/pyra_projects', $data, ['Prefer: return=representation']);
                logActivity('project_created', $id, ['project_name' => $name, 'client_id' => $clientId]);
                jsonResponse(['success' => true, 'project' => $result['data'][0] ?? $data]);
                break;
                
            // ... update, delete patterns مماثلة
        }
    }
    break;
```

### 9.2 مثال JavaScript Function في portal-app.js

```javascript
// ============ نفس نمط App object في app.js ============

const PortalApp = {
    // State — نفس نمط App
    currentScreen: 'dashboard',
    currentProject: null,
    client: null,
    
    // API helper — نفس نمط App.apiFetch()
    async apiFetch(endpoint, options = {}) {
        const url = 'index.php' + endpoint;
        const defaults = {
            headers: {
                'X-CSRF-Token': window.PORTAL_CONFIG.csrf_token
            }
        };
        if (options.body && typeof options.body === 'object' && !(options.body instanceof FormData)) {
            defaults.headers['Content-Type'] = 'application/json';
            options.body = JSON.stringify(options.body);
        }
        return fetch(url, { ...defaults, ...options });
    },
    
    // Screen rendering — نفس نمط الـ template literals في app.js
    async renderProjects(page = 1) {
        const main = document.getElementById('portal-main');
        main.innerHTML = '<div class="portal-loading"><div class="portal-spinner"></div></div>';
        
        try {
            const res = await this.apiFetch(`?action=client_projects&page=${page}`);
            const data = await res.json();
            if (!data.success) throw new Error(data.error);
            
            main.innerHTML = `
                <div class="portal-projects">
                    <h2 class="portal-page-title">📁 المشاريع</h2>
                    <div class="portal-projects-grid">
                        ${data.projects.map(p => `
                            <div class="portal-project-card" onclick="PortalApp.showScreen('project_detail', {projectId: '${this.escAttr(p.id)}'})">
                                <!-- ... card content ... -->
                            </div>
                        `).join('')}
                    </div>
                </div>
            `;
        } catch (err) {
            main.innerHTML = `<div class="portal-error">${this.escHtml(err.message)}</div>`;
        }
    },
    
    // Utility functions — نفس نمط App utilities
    escHtml(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.textContent = String(str);
        return div.innerHTML;
    },
    
    escAttr(str) {
        return String(str || '').replace(/[&"'<>]/g, c => ({
            '&': '&amp;', '"': '&quot;', "'": '&#39;', '<': '&lt;', '>': '&gt;'
        }[c]));
    },
    
    toast(msg, type = 'info') {
        // نفس نمط App.toast() بالضبط
        const container = document.getElementById('toastContainer');
        if (!container) return;
        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        toast.textContent = msg;
        container.appendChild(toast);
        setTimeout(() => toast.classList.add('toast-show'), 10);
        setTimeout(() => {
            toast.classList.remove('toast-show');
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    }
};
```

### 9.3 مثال CSS Component

```css
/* ============ نفس نمط style.css ============ */

/* استخدم نفس الـ CSS variables الموجودة — لا تعرّف جديدة */

.portal-project-card {
    /* Glass morphism — نفس نمط الكروت الحالية */
    background: var(--bg-secondary);
    border: 1px solid rgba(255,255,255,0.06);
    border-radius: calc(var(--radius) * 1.5);
    overflow: hidden;
    cursor: pointer;
    transition: transform 0.2s, border-color 0.2s;
}

.portal-project-card:hover {
    transform: translateY(-2px);
    border-color: rgba(var(--accent-rgb, 139,92,246), 0.3);
}

/* Status badges — نفس ألوان النظام */
.portal-status-badge {
    font-size: 0.75rem;
    padding: 2px 8px;
    border-radius: 12px;
    font-weight: 600;
}

/* Responsive — نفس breakpoints الحالية */
@media (max-width: 768px) {
    .portal-projects-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 600px) {
    .portal-user-name {
        display: none;
    }
}

/* RTL — نفس نمط [dir="rtl"] selectors */
[dir="rtl"] .portal-comment-team {
    border-right: none;
    border-left: 3px solid var(--accent);
}
```

### 9.4 مثال Auth Function في auth.php

```php
// ============ يُضاف في نهاية auth.php ============

// ============ Client Portal Authentication ============

function isClientLoggedIn(): bool {
    return isset($_SESSION['client_id']) && !empty($_SESSION['client_id']);
}

function getClientData(): ?array {
    if (!isClientLoggedIn()) return null;
    
    $result = dbRequest('GET', '/pyra_clients?id=eq.' . urlencode($_SESSION['client_id']) . '&limit=1');
    if (empty($result['data'])) {
        // Client not found — clear session
        unset($_SESSION['client_id'], $_SESSION['client_name'], $_SESSION['client_company']);
        return null;
    }
    
    $client = $result['data'][0];
    if (!$client['is_active']) {
        unset($_SESSION['client_id'], $_SESSION['client_name'], $_SESSION['client_company']);
        return null;
    }
    
    return [
        'id' => $client['id'],
        'name' => $client['name'],
        'email' => $client['email'],
        'company' => $client['company'],
        'phone' => $client['phone'] ?? ''
    ];
}

function requireClientAuth(): void {
    if (!isClientLoggedIn()) {
        jsonResponse(['success' => false, 'error' => 'غير مصرح'], 401);
        exit;
    }
}

function clientProjectAccess(string $projectId): bool {
    if (!isClientLoggedIn()) return false;
    $result = dbRequest('GET', '/pyra_projects?id=eq.' . urlencode($projectId) . '&client_id=eq.' . urlencode($_SESSION['client_id']) . '&limit=1');
    return !empty($result['data']);
}
```

---

## 10. ملاحظات وقواعد للمطور (Claude Code)

### 10.1 ❌ الممنوعات (Don'ts)

| # | القاعدة | السبب |
|---|---------|-------|
| 1 | ❌ لا تغير أي pattern موجود في api.php | الكود يشتغل — لا تكسره |
| 2 | ❌ لا تضيف libraries أو CDN جديدة | الموقع يعتمد على vanilla فقط + mammoth.js |
| 3 | ❌ لا تعدل style.css الأصلي | أضف styles جديدة في portal-style.css |
| 4 | ❌ لا تعدل app.js | البورتال مستقل — portal-app.js |
| 5 | ❌ لا تغير أسماء CSS variables الحالية | مثل `--bg-primary`, `--accent`, `--radius` |
| 6 | ❌ لا تضيف npm/composer/webpack | المشروع بدون build tools — يبقى كذا |
| 7 | ❌ لا تغير session configuration في auth.php | الأمان الحالي ممتاز — لا تلمسه |
| 8 | ❌ لا تستخدم innerHTML بدون escHtml() | XSS vulnerability |
| 9 | ❌ لا تعمل SQL queries مباشرة | كل شيء يمر عبر dbRequest() |
| 10 | ❌ لا تعدل schema.sql | الجداول الجديدة في portal-schema.sql |

### 10.2 ✅ الواجبات (Do's)

| # | القاعدة | التفاصيل |
|---|---------|---------|
| 1 | ✅ نفس naming conventions | Functions: camelCase، Variables: camelCase، CSS: kebab-case مع prefix `portal-` |
| 2 | ✅ كل endpoint يمر بـ auth check | `requireClientAuth()` أو `requireAuth()` أول سطر |
| 3 | ✅ كل output يمر بـ XSS sanitization | `escHtml()` للنصوص، `escAttr()` للـ attributes، `htmlspecialchars()` في PHP |
| 4 | ✅ نفس responsive breakpoints | `900px`, `768px`, `600px` — لا تضيف breakpoints جديدة |
| 5 | ✅ RTL support لكل component جديد | `[dir="rtl"]` selectors لكل عنصر فيه اتجاه |
| 6 | ✅ Error handling موحد | `try/catch` + `jsonResponse()` في PHP، `try/catch` + `toast()` في JS |
| 7 | ✅ ID generation بنفس النمط | `prefix_timestamp_random` — مثل: `c_1707926400_a3f2` |
| 8 | ✅ CSRF token في كل POST request | `X-CSRF-Token` header — نفس النمط |
| 9 | ✅ Activity logging | كل عملية مهمة → `logActivity()` |
| 10 | ✅ Notifications | كل حدث يهم العميل/الفريق → `sendNotification()` أو insert في notifications table |
| 11 | ✅ اختبر على mobile و desktop | كل شاشة لازم تشتغل على 375px و 1440px |
| 12 | ✅ استخدم نفس الـ animation names | `fadeInUp`, `slideIn`, `pulse` — لا تعرّف animations جديدة إلا لو لازم |

### 10.3 الأخطاء الشائعة وكيف تتجنبها

| # | الخطأ | الحل |
|---|-------|------|
| 1 | **نسيان client_id filter** — عميل يشوف بيانات عميل ثاني | كل query على الـ portal لازم يكون فيه `&client_id=eq.{client_id}` |
| 2 | **Session collision** — login العميل يطرد الفريق | استخدم session keys مختلفة: `$_SESSION['client_id']` vs `$_SESSION['user']` |
| 3 | **CSRF token missing** — POST requests ترجع 403 | تأكد إن `apiFetch()` يرسل `X-CSRF-Token` في كل request |
| 4 | **Supabase `CREATE OR REPLACE VIEW`** — فشل لو الأعمدة تغيرت | استخدم `DROP VIEW IF EXISTS ... CASCADE` ثم `CREATE VIEW` |
| 5 | **innerHTML XSS** — نص المستخدم يتنفذ كـ HTML | كل نص مستخدم يمر بـ `escHtml()` قبل ما يندمج في template literal |
| 6 | **نسيان `break` في switch** — الكود يكمل للـ case التالي | كل case ينتهي بـ `break;` — راجع بعد ما تكتب |
| 7 | **Path traversal** — عميل يرسل `../../etc/passwd` | استخدم `sanitizePath()` الموجودة لكل مسار ملف |
| 8 | **ملف كبير بدون pagination** — الصفحة تتجمد | كل list endpoint يدعم `?page=X&per_page=Y` — الافتراضي 20 |
| 9 | **RTL مكسور** — عنصر يظهر معكوس | كل `border-right` لازم يكون عنده `[dir="rtl"]` يحوله لـ `border-left` |
| 10 | **Console errors** — toast container مو موجود | تحقق من وجود العنصر قبل ما تتعامل معاه: `if (!container) return;` |
| 11 | **نسيان loading state** — المستخدم يضغط مرتين | عرض spinner + disable الزر أثناء الطلب |
| 12 | **Email بدون try/catch** — فشل الإيميل يوقف العملية | كل `sendClientEmail()` داخل try/catch — الإيميل اختياري |

### 10.4 قواعد Git

```
- كل phase = commit منفصل
- Commit message بالعربي + الإنجليزي: "feat(portal): Phase 1 - Database schema + tables إنشاء الجداول"
- لا تعمل commit لملفات config.php أو credentials
- تأكد .gitignore يتجاهل: config.php, *.log, .env
```

---

## 11. ملخص الملفات والتغييرات

### 11.1 الملفات الجديدة

| # | الملف | الوصف | القسم المرجعي |
|---|-------|-------|-------------|
| 1 | `portal/index.php` | Login screen + App shell + Backend API (الـ routing كامل هنا) | §5.1, §6.2 |
| 2 | `portal/portal-app.js` | Frontend controller — PortalApp object (dashboard, projects, files, approval, comments, notifications, profile) | §6.3 |
| 3 | `portal/portal-style.css` | CSS خاص بالبوابة — يستورد variables من `../style.css` | §6.4 |
| 4 | `portal-schema.sql` | DDL للجداول الجديدة (6 جداول + views + indexes) | §4.1 |

### 11.2 الملفات المعدّلة

| # | الملف | نوع التعديل | التفاصيل |
|---|-------|------------|---------|
| 5 | `api.php` | إضافة ~5-8 cases | endpoints للفريق: manage_clients, manage_projects, manage_project_files, project_approvals_overview, get_client_activity | §5.3 |
| 6 | `auth.php` | إضافة ~4-5 functions | isClientLoggedIn(), getClientData(), requireClientAuth(), clientProjectAccess(), sendClientEmail() | §9.4 |
| 7 | `index.php` | تعديل بسيط (اختياري) | إضافة رابط "بوابة العملاء" في Login screen | — |
| 8 | `config.php` | إضافة SMTP settings (اختياري) | SMTP_HOST, SMTP_PORT, SMTP_USER, SMTP_PASS | §8 Phase 7 |

### 11.3 ملفات لا تُعدّل

| # | الملف | السبب |
|---|-------|-------|
| — | `app.js` | Frontend الحالي — البوابة مستقلة |
| — | `style.css` | التصميم الحالي — البوابة تستورد variables فقط |
| — | `schema.sql` | الـ schema الأصلي — الجداول الجديدة في ملف منفصل |
| — | `setup.php` | معالج التثبيت — لا تلمسه |
| — | `config.example.php` | قالب — ممكن تحديثه لإضافة SMTP fields |

### 11.4 ملخص الأرقام

| المقياس | القيمة |
|---------|--------|
| ملفات جديدة | 4 |
| ملفات معدّلة | 3-4 |
| جداول جديدة | 6 |
| API endpoints جديدة (portal) | ~15 |
| API endpoints جديدة (api.php) | ~5-8 |
| Auth functions جديدة | ~5 |
| JS functions جديدة (PortalApp) | ~30+ |
| CSS classes جديدة | ~60+ |
| أسطر كود تقريبية | ~2,500-3,000 سطر جديد |

---

> **نهاية الـ PRD — جاهز للتنفيذ** 🚀
> 
> المطور (Claude Code) يقدر يبدأ من Phase 1 ويمشي بالترتيب.
> كل phase مستقلة ويمكن اختبارها قبل الانتقال للتالية.
