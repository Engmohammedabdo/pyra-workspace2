# Pyra Workspace

A full-featured PHP file manager for **Supabase Self-Hosted Storage** with team collaboration, role-based access control, file versioning, and a modern dark luxury UI.

![PHP](https://img.shields.io/badge/PHP-8.0+-777BB4?logo=php&logoColor=white)
![Supabase](https://img.shields.io/badge/Supabase-Self--Hosted-3ECF8E?logo=supabase&logoColor=white)
![License](https://img.shields.io/badge/License-MIT-blue)

> **Live**: [workspeace.pyramedia.info](http://workspeace.pyramedia.info/)

---

## Features

### Core - File Management
- **File Browsing** - Navigate folders with breadcrumb trail, back button, and keyboard shortcuts
- **File Preview** - Inline preview for images, video, audio, PDF, Markdown, DOCX, code, and text files
- **Upload** - Drag & drop or button upload with animated progress indicator, multi-file support
- **Download** - Download any file directly
- **Edit** - Edit text-based files (Markdown, JSON, YAML, code, etc.) with `Ctrl+S` save
- **Rename / Delete / Batch Delete** - Full file operations with confirmation
- **Create Folders** - Create new folders anywhere
- **Copy Public URL** - Copy the Supabase public URL for any file
- **Search** - Instant filter within the current directory
- **Deep Search** - Search across all files and folders (`Ctrl+Shift+F`)
- **Sort** - Sort by name, size, or modification date
- **Context Menu** - Right-click context menu on files and folders
- **DOCX Support** - Word documents rendered as HTML via mammoth.js
- **Markdown Rendering** - Full Markdown preview with syntax highlighting
- **RTL / Arabic Support** - Auto-detects Arabic text and switches to RTL layout
- **Arabic Filename Support** - Arabic/Unicode filenames are safely stored and displayed with original names preserved

### File Versioning
- **Automatic Versioning** - Every file upload creates a version snapshot (stored in `.versions/`)
- **Version History Panel** - View all versions of a file with timestamps and sizes
- **Restore Version** - Restore any previous version with one click
- **Delete Version** - Remove specific versions to free storage
- **Configurable Limits** - Max versions per file controlled via system settings (default: 10)

### Role-Based Dashboards
- **Admin Dashboard** - Storage overview, recent activity, user/team counts, system health, quick actions
- **Employee Dashboard** - Personal stats, accessible folders, recent uploads, team memberships
- **Client Dashboard** - Assigned folder quick access, recent files, review activity summary

### Authentication & Access Control
- **Session-based Authentication** - Secure login with bcrypt password hashing
- **Role-based Access Control (RBAC)** - Three user roles: `admin` / `employee` / `client`
- **Path-based Permissions** - Restrict users to specific folders
- **Browse vs Write Separation** - Navigation allows parent folder traversal; write operations (upload, edit, delete) require direct path access
- **Granular Permissions** - Control upload, edit, delete, download, create folder, and review per user
- **User Management Panel** - Admin UI to add, edit, delete users and change passwords
- **CSRF Protection** - Token-based CSRF protection on all state-changing operations

### Session Management & Security
- **Active Sessions** - View all active sessions with device info, IP address, and last activity
- **Session Termination** - Terminate individual sessions or all other sessions at once
- **Login History** - Full login attempt history with IP tracking and success/failure status
- **Rate Limiting** - Configurable failed login attempt limits with lockout duration
- **Session Timeout** - Auto-logout after configurable inactivity period

### Teams & Advanced Permissions
- **Teams / Groups** - Create teams with shared permissions and manage members
- **File-Level Permissions** - Grant access to individual files/folders for a user or team
- **Temporary Permissions** - Set expiry dates on permissions (auto-cleanup)
- **Enhanced Access Control** - `canAccessPathEnhanced()` checks user + team + file-level permissions
- **Write Path Protection** - `canWritePath()` enforces strict path checks for all write operations
- **Context Menu Permissions** - Right-click any file/folder to manage permissions (admin)

### Collaboration
- **Review System** - Comments and approvals on files
- **Threaded Replies** - Reply to specific comments to create discussion threads
- **Resolve Reviews** - Admin can mark reviews as resolved
- **Review Tracking** - Reviews follow files when renamed/moved
- **Notifications** - Real-time notification bell with polling (30s)
- **Smart Notifications** - Comments notify all admins + users with access; clicking opens the file directly
- **Notification Badge** - Pulsing red badge for unread notifications

### File Safety
- **Trash / Recycle Bin** - Soft delete with restore capability
- **Auto-Purge** - Trashed files auto-delete after 30 days
- **Activity Log** - Full audit trail of all operations with IP address tracking
- **Share Links** - Temporary links with expiry date and download limit

### System Settings (Admin)
- **Branding** - Customize app name, logo URL, and accent color
- **Upload Limits** - Configure max file size and allowed file types
- **Version Control** - Set max versions per file
- **Security Settings** - Configure session timeout, max login attempts, and lockout duration
- **Public Settings API** - Non-authenticated endpoint for login page branding

### UI / UX
- **Dual Theme** - Purple (default) and Pyramedia Orange theme with toggle switch
- **List / Grid View** - Toggle between list and grid layout (persisted in localStorage)
- **Colored File Icons** - Each file type has a unique color (folders=amber, images=pink, video=red, audio=purple, PDF=red, code=green, docs=blue, archives=orange)
- **Animations** - Staggered file entrance, preview slide-in, modal pop-in, folder hover bounce
- **Enhanced Login** - Floating particles, animated logo, "Remember Me" checkbox
- **User Avatar** - Initials-based avatar in the top bar
- **Enhanced Breadcrumbs** - Folder icons in each breadcrumb segment
- **Enhanced Drag & Drop** - Pulsing border, radial gradient, floating upload icon
- **Enhanced Empty State** - Large SVG icon with helpful text
- **Responsive Design** - Optimized for mobile and tablet screens
- **Glass Morphism** - Backdrop blur, grain texture overlay
- **Dark Luxury Aesthetic** - Refined dark theme with layered depth

---

## Tech Stack

| Layer | Technology |
|-------|-----------|
| Backend | PHP 8.0+ with cURL |
| Database | PostgreSQL (via Supabase PostgREST) |
| Storage | Supabase Self-Hosted Storage API |
| Frontend | Vanilla JavaScript (single `App` object) |
| Styling | CSS3 with custom properties, no frameworks |
| Fonts | Inter, JetBrains Mono, Noto Sans Arabic |
| Auth | Session-based with bcrypt + CSRF tokens |

---

## Requirements

- PHP 8.0+ with cURL extension
- Apache (XAMPP, WAMP, etc.) or any PHP web server
- Supabase Self-Hosted instance with Storage and PostgreSQL enabled
- Service role API key from Supabase

---

## Installation

### 1. Clone the repository

```bash
git clone https://github.com/Engmohammedabdo/pyra-workspace.git
cd pyra-workspace
```

### 2. Create configuration file

```bash
cp config.example.php config.php
```

Edit `config.php`:

```php
define('SUPABASE_URL', 'https://your-supabase-instance.com');
define('SUPABASE_SERVICE_KEY', 'your-service-role-key-here');
define('SUPABASE_BUCKET', 'your-bucket-name');
define('MAX_UPLOAD_SIZE', 524288000); // 500MB
```

### 3. Run database schema

Run the entire `schema.sql` file in your **Supabase SQL Editor**. This creates all 14 tables:

| Table | Purpose |
|-------|---------|
| `pyra_users` | Users with roles and JSONB permissions |
| `pyra_reviews` | File comments, approvals, and threaded replies |
| `pyra_trash` | Soft-deleted files (recycle bin) |
| `pyra_activity_log` | Audit trail of all operations with IP tracking |
| `pyra_notifications` | User notification system |
| `pyra_share_links` | Temporary file sharing links with token auth |
| `pyra_teams` | Teams/groups with shared JSONB permissions |
| `pyra_team_members` | Team membership (user-team mapping) |
| `pyra_file_permissions` | File/folder level permissions with expiry dates |
| `pyra_file_versions` | File version history with metadata |
| `pyra_file_index` | Search index with original filename preservation |
| `pyra_settings` | System configuration key-value store |
| `pyra_sessions` | Active session tracking with device info |
| `pyra_login_attempts` | Login attempt history for rate limiting |

### 4. Create first admin user

Open `http://localhost/pyra-workspace/setup.php` and follow the wizard, or create manually via SQL:

```sql
INSERT INTO pyra_users (username, password_hash, role, display_name, permissions)
VALUES ('admin', '$2y$10$...', 'admin', 'Administrator', '{"allowed_paths":["*"],"can_upload":true,"can_edit":true,"can_delete":true,"can_download":true,"can_create_folder":true,"can_review":true}');
```

### 5. Delete setup file and open

```bash
rm setup.php
```

Navigate to: `http://localhost/pyra-workspace/`

---

## Project Structure

```
pyra-workspace/
├── index.php              # HTML shell (login + app layout)             278 lines
├── api.php                # REST API (57 endpoints)                   1,768 lines
├── auth.php               # Auth, RBAC, teams, security               1,305 lines
├── app.js                 # Frontend controller (24 sections)         4,190 lines
├── style.css              # Dark luxury theme + responsive            4,233 lines
├── schema.sql             # Database schema (14 tables + indexes)       296 lines
├── config.php             # Supabase credentials (gitignored)
├── config.example.php     # Config template
├── setup.php              # Setup wizard (delete after use)
├── ROADMAP.md             # Feature roadmap (Arabic)
└── README.md              # This file
```

**Total codebase**: ~12,600 lines

---

## Roles & Permissions

| Permission | Admin | Employee | Client |
|------------|:-----:|:--------:|:------:|
| View files | All folders | Assigned folders | Assigned folder |
| Upload | Yes | Yes | No |
| Edit | Yes | Yes | No |
| Delete | Yes | No | No |
| Create Folder | Yes | Yes | No |
| Download | Yes | Yes | Yes |
| Review/Comment | Yes | Yes | Yes |
| View Dashboard | Admin dashboard | Employee dashboard | Client dashboard |
| Manage Users | Yes | No | No |
| Manage Teams | Yes | No | No |
| Manage Permissions | Yes | No | No |
| System Settings | Yes | No | No |

### Permissions JSON structure

```json
{
    "allowed_paths": ["*"],
    "can_upload": true,
    "can_edit": true,
    "can_delete": false,
    "can_download": true,
    "can_create_folder": true,
    "can_review": true
}
```

### Permission Evaluation Order

When checking access, the system evaluates in order:
1. **User permissions** (from `pyra_users.permissions`)
2. **Team permissions** (from teams the user belongs to)
3. **File-level permissions** (from `pyra_file_permissions`, with expiry check)

### Browse vs Write Access

The system separates **browse** and **write** access:

- **Browse** (`canAccessPathEnhanced`) - Allows navigating parent folders to reach allowed subfolders. For example, a user with access to `projects/design` can browse through `projects/` but cannot write there.
- **Write** (`canWritePath`) - Requires the target path to be **at or inside** an allowed path. No parent folder write-through. Enforced on: upload, delete, rename, save, createFolder, batchDelete, createShareLink, restoreVersion.

---

## API Reference

All API calls go through `api.php` with an `action` parameter.

### Authentication (3 endpoints)

| Action | Method | Description |
|--------|--------|-------------|
| `login` | POST | Authenticate user (supports `remember` flag) |
| `logout` | POST | End session |
| `session` | GET | Check authentication status (includes CSRF token) |

### Dashboard (1 endpoint)

| Action | Method | Description |
|--------|--------|-------------|
| `getDashboard` | GET | Role-specific dashboard data (admin/employee/client) |

### File Operations (11 endpoints)

| Action | Method | Description |
|--------|--------|-------------|
| `list` | GET | List files and folders (enriched with display names) |
| `upload` | POST (multipart) | Upload files (Arabic names auto-sanitized) |
| `download` | GET | Download a file |
| `delete` | POST | Delete a file (moves to trash) |
| `deleteBatch` | POST | Delete multiple files |
| `rename` | POST | Move/rename a file |
| `content` | GET | Get text content (JSON) |
| `save` | POST | Save/update text content |
| `createFolder` | POST | Create a new folder |
| `proxy` | GET | Proxy binary file (DOCX preview) |
| `publicUrl` | GET | Get public URL |

### File Versioning (4 endpoints)

| Action | Method | Description |
|--------|--------|-------------|
| `getFileVersions` | GET | Get version history for a file |
| `restoreVersion` | POST | Restore a previous version |
| `deleteVersion` | POST | Delete a specific version |
| `rebuildIndex` | POST | Rebuild the file search index (admin) |

### Reviews (4 endpoints)

| Action | Method | Description |
|--------|--------|-------------|
| `getReviews` | GET | Get all reviews for a file (with threaded replies) |
| `addReview` | POST | Add comment or approval (supports `parent_id` for replies) |
| `resolveReview` | POST | Toggle resolved status (admin) |
| `deleteReview` | POST | Delete a review (admin) |

### Trash (5 endpoints)

| Action | Method | Description |
|--------|--------|-------------|
| `listTrash` | GET | List trashed items |
| `restoreTrash` | POST | Restore a trashed item |
| `permanentDelete` | POST | Permanently delete from trash |
| `emptyTrash` | POST | Empty all trash (admin) |
| `purgeExpired` | POST | Remove items past 30-day retention |

### Notifications (4 endpoints)

| Action | Method | Description |
|--------|--------|-------------|
| `getNotifications` | GET | Get user notifications |
| `getUnreadCount` | GET | Get unread notification count |
| `markNotifRead` | POST | Mark single notification as read |
| `markAllNotifsRead` | POST | Mark all notifications as read |

### Activity Log (1 endpoint)

| Action | Method | Description |
|--------|--------|-------------|
| `getActivityLog` | GET | Get activity log (filters: user, action, date range) |

### Search (1 endpoint)

| Action | Method | Description |
|--------|--------|-------------|
| `deepSearch` | GET | Search all files/folders recursively (uses file index) |

### Share Links (4 endpoints)

| Action | Method | Description |
|--------|--------|-------------|
| `createShareLink` | POST | Create temporary share link |
| `getShareLinks` | GET | Get share links for a file |
| `deactivateShareLink` | POST | Deactivate a share link |
| `shareAccess` | GET | Access shared file via token |

### User Management (5 endpoints, admin only)

| Action | Method | Description |
|--------|--------|-------------|
| `getUsers` | GET | List all users |
| `addUser` | POST | Create a new user |
| `updateUser` | POST | Update user details/permissions |
| `deleteUser` | POST | Delete a user |
| `changePassword` | POST | Change user password |

### Teams (6 endpoints, admin only)

| Action | Method | Description |
|--------|--------|-------------|
| `getTeams` | GET | List all teams |
| `createTeam` | POST | Create a team with permissions |
| `updateTeam` | POST | Update team details |
| `deleteTeam` | POST | Delete a team |
| `addTeamMember` | POST | Add user to team (notifies user) |
| `removeTeamMember` | POST | Remove user from team |

### File Permissions (4 endpoints, admin only)

| Action | Method | Description |
|--------|--------|-------------|
| `setFilePermission` | POST | Set permission on file/folder for user/team |
| `getFilePermissions` | GET | Get permissions for a file |
| `removeFilePermission` | POST | Remove a permission |
| `cleanExpiredPermissions` | POST | Remove all expired permissions |

### System Settings (3 endpoints, admin only)

| Action | Method | Description |
|--------|--------|-------------|
| `getSettings` | GET | Get all system settings |
| `updateSettings` | POST | Update system settings |
| `getPublicSettings` | GET | Get public branding settings (no auth required) |

### Session Management (3 endpoints)

| Action | Method | Description |
|--------|--------|-------------|
| `getSessions` | GET | Get all active sessions for current user |
| `terminateSession` | POST | Terminate a specific session |
| `terminateAllSessions` | POST | Terminate all other sessions |

### Login History (1 endpoint)

| Action | Method | Description |
|--------|--------|-------------|
| `getLoginHistory` | GET | Get login attempt history |

**Total: 57 API endpoints**

---

## Keyboard Shortcuts

| Shortcut | Action |
|----------|--------|
| `Alt + Left` | Go back to parent folder |
| `Ctrl + S` | Save file (in edit mode) |
| `Ctrl + Shift + F` | Deep search all folders |
| `Escape` | Close preview / modal / context menu |
| `Delete` | Delete selected file |

---

## Supported File Previews

| Type | Extensions |
|------|-----------|
| Images | jpg, jpeg, png, gif, svg, webp, bmp, ico, tiff |
| Video | mp4, webm, mov, avi, mkv, flv, wmv |
| Audio | mp3, wav, ogg, flac, aac, m4a, wma |
| Documents | docx (via mammoth.js), pdf |
| Markdown | md, markdown |
| Code | js, ts, py, php, html, css, json, xml, yaml, yml, sh, bash, sql, rb, go, rs, java, c, cpp, h, jsx, tsx, vue, svelte |
| Text | txt, log, csv, ini, cfg, conf, env, toml, properties |

---

## UI Themes

The app includes two themes, toggled via the switch in the top bar:

| Theme | Accent Color | Description |
|-------|-------------|-------------|
| **Purple** (default) | `#8b5cf6` | Refined dark luxury with purple accents |
| **Pyramedia Orange** | `#F97316` | Warm orange theme matching Pyramedia branding |

Theme preference is saved in `localStorage` under key `pyra-theme`.

---

## Security

### Authentication & Session
- **Password Hashing** - bcrypt via `password_hash()`
- **Session Security** - HTTPOnly and SameSite=Strict cookies
- **CSRF Protection** - Token generated per session, validated on all POST requests via `X-CSRF-Token` header
- **Session Tracking** - Active sessions stored in DB with device info, IP, and last activity
- **Session Timeout** - Configurable auto-logout after inactivity (default: 8 hours)
- **Rate Limiting** - Failed login attempts tracked; account locked after configurable threshold (default: 5 attempts, 15 min lockout)

### Path & Access Control
- **RBAC Enforcement** - Every API operation checks role and permissions
- **Browse/Write Separation** - `canAccessPathEnhanced()` for navigation, `canWritePath()` for modifications
- **Path Sanitization** - All file paths sanitized to prevent path traversal
- **Path Filtering** - Non-admin users only see files within their allowed paths
- **Write Protection** - Upload, delete, rename, save, createFolder require direct path authorization (no parent write-through)

### Infrastructure
- **Security Headers** - X-Content-Type-Options, X-Frame-Options, Referrer-Policy
- **Service Role Key** - Stored server-side only, never exposed to client
- **Arabic Filename Sanitization** - Unicode filenames converted to safe ASCII for Supabase Storage; original names preserved in DB
- **Hidden System Folders** - `.versions` and `.trash` folders hidden from file browser

> **Important**: This application uses the Supabase **service role key** which has full access. Always keep `config.php` out of version control.

---

## Database Schema

14 tables with indexes. See `schema.sql` for full definitions.

```
pyra_users              - User accounts with RBAC and JSONB permissions
pyra_reviews            - File comments, approvals, and threaded replies (parent_id)
pyra_trash              - Soft-deleted files with 30-day auto-purge
pyra_activity_log       - Audit trail with IP tracking
pyra_notifications      - User notification system
pyra_share_links        - Temporary file sharing with token auth
pyra_teams              - Teams/groups with shared JSONB permissions
pyra_team_members       - Team membership (M:N with cascade delete)
pyra_file_permissions   - File-level permissions with expiry dates
pyra_file_versions      - File version snapshots with metadata
pyra_file_index         - Search index with original Arabic filename preservation
pyra_settings           - System configuration (branding, limits, security)
pyra_sessions           - Active session tracking (device, IP, last activity)
pyra_login_attempts     - Login history for rate limiting and audit
```

---

## System Settings

Configurable via the admin settings panel:

| Setting | Key | Default | Description |
|---------|-----|---------|-------------|
| App Name | `app_name` | Pyra Workspace | Displayed in header and login |
| Logo URL | `logo_url` | (empty) | Custom logo image URL |
| Accent Color | `accent_color` | `#8b5cf6` | Primary UI accent color |
| Max Upload Size | `max_upload_size` | 524288000 (500MB) | Maximum file upload size in bytes |
| Allowed File Types | `allowed_file_types` | `*` | Comma-separated extensions or `*` |
| Max Versions | `max_versions_per_file` | 10 | Maximum version snapshots per file |
| Session Timeout | `session_timeout_hours` | 8 | Hours before auto-logout |
| Max Login Attempts | `max_login_attempts` | 5 | Failed attempts before lockout |
| Lockout Duration | `lockout_duration_minutes` | 15 | Minutes to lock account after max attempts |

---

## License

MIT
