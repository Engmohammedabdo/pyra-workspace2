/**
 * Pyra Workspace — Client Portal App
 * Frontend controller for the client portal
 */
const PortalApp = {

    // ============ State ============
    currentScreen: 'dashboard',
    currentProject: null,
    currentFile: null,
    client: null,
    csrfToken: '',
    userMenuOpen: false,
    mobileNavOpen: false,

    // ============ Init ============
    init() {
        const config = window.PORTAL_CONFIG || {};
        this.client = config.client;
        this.csrfToken = config.csrf_token || '';

        this.showScreen('dashboard');
        this.initNotifications();
        this.initClickOutside();
    },

    // ============ API Helper ============
    async apiFetch(endpoint, options = {}) {
        const url = 'index.php' + endpoint;
        const headers = {
            'X-CSRF-Token': this.csrfToken,
            ...(options.headers || {})
        };

        if (options.body && typeof options.body === 'object' && !(options.body instanceof FormData)) {
            headers['Content-Type'] = 'application/json';
            options.body = JSON.stringify(options.body);
        }

        const res = await fetch(url, { ...options, headers });

        if (res.status === 401) {
            this.toast('انتهت الجلسة. يرجى تسجيل الدخول مرة أخرى', 'error');
            setTimeout(() => location.reload(), 1500);
            throw new Error('Session expired');
        }

        return res;
    },

    // ============ Auth ============
    async handleLogin(e) {
        e.preventDefault();
        const btn = document.getElementById('loginBtn');
        const card = document.getElementById('loginCard');
        const errorEl = document.getElementById('loginError');
        const email = document.getElementById('loginEmail').value.trim();
        const password = document.getElementById('loginPassword').value;

        if (!email || !password) {
            this.showLoginError(errorEl, 'البريد الإلكتروني وكلمة المرور مطلوبان');
            return;
        }

        // Loading state
        btn.classList.add('portal-btn-loading');
        btn.disabled = true;
        errorEl.textContent = '';
        errorEl.classList.remove('portal-has-error');

        try {
            const res = await fetch('index.php?action=client_login', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ email, password })
            });
            const data = await res.json();

            if (data.success) {
                card.style.transition = 'opacity 0.3s, transform 0.3s';
                card.style.opacity = '0';
                card.style.transform = 'scale(0.95)';
                setTimeout(() => location.reload(), 300);
            } else {
                this.showLoginError(errorEl, data.error || 'خطأ في تسجيل الدخول');
                btn.classList.remove('portal-btn-loading');
                btn.disabled = false;
            }
        } catch (err) {
            this.showLoginError(errorEl, 'خطأ في الاتصال بالخادم');
            btn.classList.remove('portal-btn-loading');
            btn.disabled = false;
        }
    },

    showLoginError(el, msg) {
        el.textContent = msg;
        el.classList.add('portal-has-error');
    },

    async handleForgotPassword(e) {
        e.preventDefault();
        const btn = document.getElementById('forgotBtn');
        const errorEl = document.getElementById('forgotError');
        const successEl = document.getElementById('forgotSuccess');
        const email = document.getElementById('forgotEmail').value.trim();

        if (!email) {
            this.showLoginError(errorEl, 'البريد الإلكتروني مطلوب');
            return;
        }

        btn.classList.add('portal-btn-loading');
        btn.disabled = true;
        errorEl.textContent = '';
        errorEl.classList.remove('portal-has-error');
        successEl.classList.add('portal-hidden');

        try {
            const res = await fetch('index.php?action=client_forgot_password', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ email })
            });
            const data = await res.json();

            if (data.success) {
                successEl.textContent = data.message || 'تم إرسال رابط الاستعادة. تحقق من بريدك الإلكتروني';
                successEl.classList.remove('portal-hidden');
                btn.classList.remove('portal-btn-loading');
                btn.disabled = true;
            } else {
                this.showLoginError(errorEl, data.error || 'حدث خطأ');
                btn.classList.remove('portal-btn-loading');
                btn.disabled = false;
            }
        } catch (err) {
            this.showLoginError(errorEl, 'خطأ في الاتصال بالخادم');
            btn.classList.remove('portal-btn-loading');
            btn.disabled = false;
        }
    },

    showForgotPasswordForm() {
        document.getElementById('loginForm').classList.add('portal-hidden');
        document.getElementById('forgotForm').classList.remove('portal-hidden');
        document.getElementById('forgotEmail').focus();
    },

    showLoginForm() {
        document.getElementById('forgotForm').classList.add('portal-hidden');
        document.getElementById('loginForm').classList.remove('portal-hidden');
        document.getElementById('loginEmail').focus();
    },

    async handleLogout() {
        try {
            await this.apiFetch('?action=client_logout', { method: 'POST' });
        } catch (e) {
            // Ignore errors
        }
        location.reload();
    },

    // ============ Screen Router ============
    showScreen(screen, params = {}) {
        this.currentScreen = screen;

        // Update active nav
        document.querySelectorAll('.portal-nav-btn').forEach(btn => {
            btn.classList.toggle('portal-nav-active', btn.dataset.screen === screen);
        });

        // Close mobile nav on screen change
        if (this.mobileNavOpen) this.toggleMobileNav();

        switch (screen) {
            case 'dashboard':
                this.renderDashboard();
                break;
            case 'projects':
                this.renderProjects(params.page || 1);
                break;
            case 'project_detail':
                this.renderProjectDetail(params.projectId);
                break;
            case 'file_preview':
                this.renderFilePreview(params.fileId);
                break;
            case 'notifications':
                this.renderNotifications();
                break;
            case 'profile':
                this.renderProfile();
                break;
            default:
                this.renderDashboard();
        }
    },

    // ============ Dashboard ============
    async renderDashboard() {
        const main = document.getElementById('portalMain');
        main.innerHTML = '<div class="portal-loading"><div class="portal-spinner"></div></div>';

        try {
            const res = await this.apiFetch('?action=client_dashboard');
            const data = await res.json();
            if (!data.success) throw new Error(data.error);

            const d = data.dashboard;
            main.innerHTML = `
                <div class="portal-dashboard">
                    <div class="portal-welcome-card">
                        <h2>مرحبا، ${this.escHtml(d.client.name)}</h2>
                        <p>${this.escHtml(d.client.company)}</p>
                    </div>
                    <div class="portal-dashboard-grid">
                        <div class="portal-card" onclick="PortalApp.showScreen('projects')">
                            <div class="portal-card-header">
                                <span class="portal-card-icon"><i data-lucide="folder-open"></i></span>
                                <span class="portal-card-count">${d.projects.total_active}</span>
                            </div>
                            <h3>المشاريع النشطة</h3>
                            <div class="portal-card-list">
                                ${(d.projects.list || []).slice(0, 3).map(p => `
                                    <div class="portal-card-list-item" onclick="event.stopPropagation(); PortalApp.showScreen('project_detail', {projectId: '${this.escAttr(p.id)}'})">
                                        <span>${this.escHtml(p.name)}</span>
                                        <span class="portal-status-badge status-${this.escAttr(p.status)}">${this.statusLabel(p.status)}</span>
                                    </div>
                                `).join('')}
                            </div>
                        </div>
                        <div class="portal-card">
                            <div class="portal-card-header">
                                <span class="portal-card-icon"><i data-lucide="clock"></i></span>
                                <span class="portal-card-count">${d.pending_approvals.total}</span>
                            </div>
                            <h3>بانتظار موافقتك</h3>
                            <div class="portal-card-list">
                                ${(d.pending_approvals.list || []).slice(0, 3).map(a => `
                                    <div class="portal-card-list-item">
                                        <span>${this.escHtml(a.file_name || '')}</span>
                                        <time>${this.timeAgo(a.created_at)}</time>
                                    </div>
                                `).join('')}
                            </div>
                        </div>
                        <div class="portal-card">
                            <div class="portal-card-header">
                                <span class="portal-card-icon"><i data-lucide="file-text"></i></span>
                                <span class="portal-card-count">${d.recent_files.total}</span>
                            </div>
                            <h3>آخر الملفات</h3>
                            <div class="portal-card-list">
                                ${(d.recent_files.list || []).slice(0, 3).map(f => `
                                    <div class="portal-card-list-item">
                                        <span>${this.escHtml(f.file_name)}</span>
                                        <span>${this.formatSize(f.file_size)}</span>
                                    </div>
                                `).join('')}
                            </div>
                        </div>
                        <div class="portal-card" onclick="PortalApp.showScreen('notifications')">
                            <div class="portal-card-header">
                                <span class="portal-card-icon"><i data-lucide="bell"></i></span>
                                <span class="portal-card-count">${d.unread_notifications}</span>
                            </div>
                            <h3>الإشعارات</h3>
                            <div class="portal-card-list">
                                ${(d.recent_notifications || []).slice(0, 3).map(n => `
                                    <div class="portal-card-list-item ${n.is_read ? '' : 'portal-unread'}">
                                        <span>${this.escHtml(n.title)}</span>
                                        <time>${this.timeAgo(n.created_at)}</time>
                                    </div>
                                `).join('')}
                            </div>
                        </div>
                    </div>
                </div>
            `;
            if (typeof lucide !== 'undefined') lucide.createIcons();
        } catch (err) {
            main.innerHTML = `<div class="portal-error"><p>خطأ في تحميل البيانات</p><p style="font-size:0.85rem;color:var(--text-muted)">${this.escHtml(err.message)}</p></div>`;
        }
    },

    // ============ Projects ============
    async renderProjects(page) {
        const main = document.getElementById('portalMain');
        main.innerHTML = '<div class="portal-loading"><div class="portal-spinner"></div></div>';

        try {
            const res = await this.apiFetch(`?action=client_projects&page=${page || 1}`);
            const data = await res.json();
            if (!data.success) throw new Error(data.error);

            if (data.projects.length === 0) {
                main.innerHTML = `<div class="portal-error"><p>لا توجد مشاريع حالياً</p></div>`;
                return;
            }

            main.innerHTML = `
                <div class="portal-projects">
                    <h2 class="portal-page-title">المشاريع</h2>
                    <div class="portal-projects-grid">
                        ${data.projects.map(p => `
                            <div class="portal-project-card" onclick="PortalApp.showScreen('project_detail', {projectId: '${this.escAttr(p.id)}'})">
                                <div class="portal-project-cover">
                                    ${p.cover_image ? `<img src="${this.escAttr(p.cover_image)}" alt="" loading="lazy">` : '<div class="portal-project-cover-placeholder"><i data-lucide="folder-open" style="width:48px;height:48px"></i></div>'}
                                    <span class="portal-status-badge status-${this.escAttr(p.status)}">${this.statusLabel(p.status)}</span>
                                </div>
                                <div class="portal-project-info">
                                    <h3>${this.escHtml(p.name)}</h3>
                                    ${p.description ? `<p>${this.escHtml(p.description).substring(0, 100)}</p>` : ''}
                                    <div class="portal-project-meta">
                                        ${p.deadline ? `<span><i data-lucide="calendar" style="width:14px;height:14px;display:inline"></i> ${this.formatDate(p.deadline)}</span>` : ''}
                                    </div>
                                </div>
                            </div>
                        `).join('')}
                    </div>
                </div>
            `;
            if (typeof lucide !== 'undefined') lucide.createIcons();
        } catch (err) {
            main.innerHTML = `<div class="portal-error"><p>خطأ في تحميل المشاريع</p></div>`;
        }
    },

    // ============ Project Detail (stub) ============
    async renderProjectDetail(projectId) {
        const main = document.getElementById('portalMain');
        main.innerHTML = '<div class="portal-loading"><div class="portal-spinner"></div></div>';
        this.currentProject = projectId;
        // Phase 4: Full implementation
        main.innerHTML = `<div class="portal-error"><p>تفاصيل المشروع — قيد التطوير</p></div>`;
    },

    // ============ File Preview (stub) ============
    async renderFilePreview(fileId) {
        const main = document.getElementById('portalMain');
        main.innerHTML = '<div class="portal-loading"><div class="portal-spinner"></div></div>';
        this.currentFile = fileId;
        // Phase 4: Full implementation
        main.innerHTML = `<div class="portal-error"><p>معاينة الملف — قيد التطوير</p></div>`;
    },

    // ============ Notifications (stub) ============
    async renderNotifications() {
        const main = document.getElementById('portalMain');
        main.innerHTML = '<div class="portal-loading"><div class="portal-spinner"></div></div>';
        // Phase 4: Full implementation
        main.innerHTML = `<div class="portal-error"><p>الإشعارات — قيد التطوير</p></div>`;
    },

    // ============ Profile (stub) ============
    async renderProfile() {
        const main = document.getElementById('portalMain');
        main.innerHTML = '<div class="portal-loading"><div class="portal-spinner"></div></div>';
        // Phase 4: Full implementation
        main.innerHTML = `<div class="portal-error"><p>الملف الشخصي — قيد التطوير</p></div>`;
    },

    // ============ Notifications Badge ============
    initNotifications() {
        this.updateUnreadCount();
        setInterval(() => this.updateUnreadCount(), 60000);
    },

    async updateUnreadCount() {
        try {
            const res = await this.apiFetch('?action=client_unread_count');
            const data = await res.json();
            const badge = document.getElementById('portalNotifBadge');
            if (badge) {
                const count = data.count || 0;
                badge.textContent = count;
                badge.style.display = count > 0 ? 'flex' : 'none';
            }
        } catch (e) {
            // Silent fail
        }
    },

    // ============ UI Helpers ============
    toggleUserMenu() {
        this.userMenuOpen = !this.userMenuOpen;
        const dropdown = document.getElementById('userDropdown');
        if (dropdown) {
            dropdown.classList.toggle('portal-dropdown-open', this.userMenuOpen);
        }
    },

    toggleMobileNav() {
        this.mobileNavOpen = !this.mobileNavOpen;
        const nav = document.getElementById('portalNav');
        if (nav) {
            nav.classList.toggle('portal-nav-open', this.mobileNavOpen);
        }
    },

    initClickOutside() {
        document.addEventListener('click', (e) => {
            // Close user dropdown
            if (this.userMenuOpen) {
                const menu = document.querySelector('.portal-user-menu');
                if (menu && !menu.contains(e.target)) {
                    this.userMenuOpen = false;
                    const dropdown = document.getElementById('userDropdown');
                    if (dropdown) dropdown.classList.remove('portal-dropdown-open');
                }
            }
        });
    },

    // ============ Toast ============
    toast(msg, type = 'info') {
        const container = document.getElementById('toastContainer');
        if (!container) return;

        const toast = document.createElement('div');
        toast.className = `portal-toast toast-${type}`;
        toast.textContent = msg;
        container.appendChild(toast);

        setTimeout(() => {
            toast.classList.add('portal-toast-out');
            setTimeout(() => toast.remove(), 300);
        }, 4000);
    },

    // ============ Modal ============
    showModal(html) {
        const overlay = document.getElementById('modalOverlay');
        const content = document.getElementById('modalContent');
        if (overlay && content) {
            content.innerHTML = html;
            overlay.classList.add('portal-modal-open');
        }
    },

    closeModal() {
        const overlay = document.getElementById('modalOverlay');
        if (overlay) {
            overlay.classList.remove('portal-modal-open');
        }
    },

    // ============ XSS Protection ============
    escHtml(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    },

    escAttr(str) {
        if (!str) return '';
        return str.replace(/[&"'<>]/g, c => ({
            '&': '&amp;', '"': '&quot;', "'": '&#39;', '<': '&lt;', '>': '&gt;'
        })[c]);
    },

    // ============ Formatters ============
    statusLabel(status) {
        const labels = {
            'active': 'نشط',
            'in_progress': 'قيد التنفيذ',
            'review': 'مراجعة',
            'completed': 'مكتمل',
            'pending': 'بانتظار',
            'approved': 'تمت الموافقة',
            'revision_requested': 'طلب تعديل'
        };
        return labels[status] || status;
    },

    formatSize(bytes) {
        if (!bytes) return '0 B';
        const sizes = ['B', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(1024));
        return (bytes / Math.pow(1024, i)).toFixed(i > 1 ? 1 : 0) + ' ' + sizes[i];
    },

    formatDate(dateStr) {
        if (!dateStr) return '';
        try {
            return new Date(dateStr).toLocaleDateString('ar-EG', {
                year: 'numeric',
                month: 'short',
                day: 'numeric'
            });
        } catch {
            return dateStr;
        }
    },

    timeAgo(dateStr) {
        if (!dateStr) return '';
        try {
            const now = new Date();
            const date = new Date(dateStr);
            const diff = Math.floor((now - date) / 1000);

            if (diff < 60) return 'الآن';
            if (diff < 3600) return `قبل ${Math.floor(diff / 60)} دقيقة`;
            if (diff < 86400) return `قبل ${Math.floor(diff / 3600)} ساعة`;
            if (diff < 604800) return `قبل ${Math.floor(diff / 86400)} يوم`;
            return this.formatDate(dateStr);
        } catch {
            return dateStr;
        }
    }
};

// ============ Boot ============
document.addEventListener('DOMContentLoaded', () => {
    // Login page bindings
    const loginForm = document.getElementById('loginForm');
    if (loginForm) {
        loginForm.addEventListener('submit', (e) => PortalApp.handleLogin(e));
    }

    const forgotForm = document.getElementById('forgotForm');
    if (forgotForm) {
        forgotForm.addEventListener('submit', (e) => PortalApp.handleForgotPassword(e));
    }

    const forgotLink = document.getElementById('forgotPasswordLink');
    if (forgotLink) {
        forgotLink.addEventListener('click', (e) => {
            e.preventDefault();
            PortalApp.showForgotPasswordForm();
        });
    }

    const backToLogin = document.getElementById('backToLogin');
    if (backToLogin) {
        backToLogin.addEventListener('click', (e) => {
            e.preventDefault();
            PortalApp.showLoginForm();
        });
    }

    // Password visibility toggle
    const pwToggle = document.getElementById('showPasswordToggle');
    if (pwToggle) {
        pwToggle.addEventListener('change', function() {
            const pwInput = document.getElementById('loginPassword');
            if (pwInput) {
                pwInput.type = this.checked ? 'text' : 'password';
            }
        });
    }

    // Modal close on overlay click
    const modalOverlay = document.getElementById('modalOverlay');
    if (modalOverlay) {
        modalOverlay.addEventListener('click', (e) => {
            if (e.target === modalOverlay) PortalApp.closeModal();
        });
    }

    // Escape key handlers
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            PortalApp.closeModal();
            if (PortalApp.userMenuOpen) PortalApp.toggleUserMenu();
        }
    });

    // App shell init (only for authenticated users)
    if (window.PORTAL_CONFIG && window.PORTAL_CONFIG.auth) {
        PortalApp.init();
    }
});
