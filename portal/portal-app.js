/**
 * Pyra Workspace — Client Portal App
 * Frontend controller for the client portal
 * Phase 5: Project Detail + File Preview + Approvals
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
    _historyLock: false,

    // ============ Init ============
    init() {
        const config = window.PORTAL_CONFIG || {};
        this.client = config.client;
        this.csrfToken = config.csrf_token || '';

        this.initHistory();
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

    // ============ Browser History ============
    initHistory() {
        // Parse initial URL hash or default to dashboard
        const route = this._parseRoute();
        this.showScreen(route.screen, route.params, { pushState: false });

        // Handle browser back/forward
        window.addEventListener('popstate', (e) => {
            this._historyLock = true;
            const state = e.state || { screen: 'dashboard', params: {} };
            this.showScreen(state.screen, state.params, { pushState: false });
            this._historyLock = false;
        });
    },

    _parseRoute() {
        const hash = location.hash.replace('#', '');
        if (!hash) return { screen: 'dashboard', params: {} };

        const parts = hash.split('/');
        const screen = parts[0] || 'dashboard';
        const params = {};

        if (screen === 'projects' && parts[1]) params.page = parseInt(parts[1]) || 1;
        if (screen === 'project_detail' && parts[1]) params.projectId = parts[1];
        if (screen === 'file_preview' && parts[1]) params.fileId = parts[1];

        return { screen, params };
    },

    _buildHash(screen, params = {}) {
        switch (screen) {
            case 'dashboard': return '#dashboard';
            case 'projects': return params.page > 1 ? `#projects/${params.page}` : '#projects';
            case 'project_detail': return `#project_detail/${params.projectId || ''}`;
            case 'file_preview': return `#file_preview/${params.fileId || ''}`;
            case 'notifications': return '#notifications';
            case 'profile': return '#profile';
            default: return '#dashboard';
        }
    },

    // ============ Screen Router ============
    showScreen(screen, params = {}, options = {}) {
        const { pushState = true } = options;
        this.currentScreen = screen;

        // Update active nav
        const navScreen = ['project_detail', 'file_preview'].includes(screen) ? 'projects' : screen;
        document.querySelectorAll('.portal-nav-btn').forEach(btn => {
            btn.classList.toggle('portal-nav-active', btn.dataset.screen === navScreen);
        });

        // Close mobile nav on screen change
        if (this.mobileNavOpen) this.toggleMobileNav();

        // Push browser history (unless coming from popstate or initial load)
        if (pushState && !this._historyLock) {
            const hash = this._buildHash(screen, params);
            const state = { screen, params };
            history.pushState(state, '', hash);
        }

        // Scroll to top
        const main = document.getElementById('portalMain');
        if (main) main.scrollTop = 0;
        window.scrollTo({ top: 0, behavior: 'instant' });

        switch (screen) {
            case 'dashboard':
                this.renderDashboard();
                break;
            case 'projects':
                this.renderProjects(params.page || 1, params.status);
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

    // ============ Skeleton Loaders ============
    _dashboardSkeleton() {
        return `
            <div class="portal-dashboard">
                <div class="portal-welcome-card portal-skeleton-card">
                    <div class="portal-skeleton" style="height:28px;width:200px;margin-bottom:12px"></div>
                    <div class="portal-skeleton" style="height:18px;width:160px"></div>
                </div>
                <div class="portal-dashboard-grid">
                    ${[1,2,3,4,5,6].map(() => `
                        <div class="portal-card portal-skeleton-card">
                            <div class="portal-card-header">
                                <div class="portal-skeleton" style="width:40px;height:40px;border-radius:10px"></div>
                                <div class="portal-skeleton" style="width:48px;height:36px;border-radius:8px"></div>
                            </div>
                            <div class="portal-skeleton" style="height:18px;width:120px;margin:12px 0 16px"></div>
                            <div class="portal-skeleton" style="height:14px;width:100%;margin-bottom:8px"></div>
                            <div class="portal-skeleton" style="height:14px;width:85%;margin-bottom:8px"></div>
                            <div class="portal-skeleton" style="height:14px;width:70%"></div>
                        </div>
                    `).join('')}
                </div>
            </div>
        `;
    },

    _projectsSkeleton() {
        return `
            <div class="portal-projects">
                <div class="portal-skeleton" style="height:30px;width:120px;margin-bottom:24px"></div>
                <div class="portal-projects-grid">
                    ${[1,2,3].map(() => `
                        <div class="portal-project-card portal-skeleton-card">
                            <div class="portal-skeleton" style="height:160px;border-radius:0"></div>
                            <div style="padding:16px">
                                <div class="portal-skeleton" style="height:20px;width:70%;margin-bottom:10px"></div>
                                <div class="portal-skeleton" style="height:14px;width:100%;margin-bottom:6px"></div>
                                <div class="portal-skeleton" style="height:14px;width:60%;margin-bottom:16px"></div>
                                <div class="portal-skeleton" style="height:12px;width:40%"></div>
                            </div>
                        </div>
                    `).join('')}
                </div>
            </div>
        `;
    },

    // ============ Dashboard ============
    async renderDashboard() {
        const main = document.getElementById('portalMain');
        main.innerHTML = this._dashboardSkeleton();

        try {
            const res = await this.apiFetch('?action=client_dashboard');
            const data = await res.json();
            if (!data.success) throw new Error(data.error);

            const d = data.dashboard;
            const lastLogin = d.client.last_login ? this.timeAgo(d.client.last_login) : '';
            main.innerHTML = `
                <div class="portal-dashboard">
                    <div class="portal-welcome-card">
                        <div class="portal-welcome-content">
                            <div class="portal-welcome-text">
                                <h2>مرحباً، ${this.escHtml(d.client.name)} 👋</h2>
                                <p>${this.escHtml(d.client.company)}${lastLogin ? ` · آخر دخول: ${this.escHtml(lastLogin)}` : ''}</p>
                            </div>
                            <div class="portal-welcome-stats">
                                <div class="portal-welcome-stat">
                                    <span class="portal-welcome-stat-num">${d.projects.total_active}</span>
                                    <span class="portal-welcome-stat-label">مشاريع</span>
                                </div>
                                <div class="portal-welcome-stat">
                                    <span class="portal-welcome-stat-num">${d.pending_approvals.total}</span>
                                    <span class="portal-welcome-stat-label">بانتظار</span>
                                </div>
                                <div class="portal-welcome-stat">
                                    <span class="portal-welcome-stat-num">${d.unread_notifications}</span>
                                    <span class="portal-welcome-stat-label">إشعارات</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="portal-dashboard-grid">
                        ${this._renderDashCard({
                            icon: 'folder-open', count: d.projects.total_active,
                            title: 'المشاريع النشطة',
                            items: (d.projects.list || []).slice(0, 3).map(p => ({
                                label: p.name,
                                extra: `<span class="portal-status-badge status-${this.escAttr(p.status)}">${this.statusLabel(p.status)}</span>`,
                                onclick: `event.stopPropagation(); PortalApp.showScreen('project_detail', {projectId: '${this.escAttr(p.id)}'})`
                            })),
                            onclick: "PortalApp.showScreen('projects')",
                            footer: 'عرض كل المشاريع'
                        })}
                        ${this._renderDashCard({
                            icon: 'clock', count: d.pending_approvals.total,
                            title: 'بانتظار موافقتك',
                            accent: d.pending_approvals.total > 0 ? 'warning' : null,
                            items: (d.pending_approvals.list || []).slice(0, 3).map(a => ({
                                label: a.file_name || '',
                                extra: `<time>${this.timeAgo(a.created_at)}</time>`
                            })),
                            emptyText: 'لا توجد ملفات بانتظار الموافقة 🎉'
                        })}
                        ${this._renderDashCard({
                            icon: 'file-text', count: d.recent_files.total,
                            title: 'آخر الملفات',
                            items: (d.recent_files.list || []).slice(0, 3).map(f => ({
                                label: f.file_name,
                                extra: `<span class="portal-card-file-size">${this.formatSize(f.file_size)}</span>`,
                                icon: this.getFileIcon(f.mime_type)
                            })),
                            emptyText: 'لا توجد ملفات بعد'
                        })}
                        ${this._renderDashCard({
                            icon: 'message-circle', count: d.unread_comments || 0,
                            title: 'رسائل جديدة',
                            items: [],
                            emptyText: d.unread_comments > 0
                                ? `لديك ${d.unread_comments} رسائل جديدة من الفريق`
                                : 'لا توجد رسائل جديدة',
                            onclick: null
                        })}
                        ${this._renderDashCard({
                            icon: 'bell', count: d.unread_notifications,
                            title: 'الإشعارات',
                            items: (d.recent_notifications || []).slice(0, 3).map(n => ({
                                label: n.title,
                                extra: `<time>${this.timeAgo(n.created_at)}</time>`,
                                unread: !n.is_read
                            })),
                            onclick: "PortalApp.showScreen('notifications')",
                            footer: 'عرض كل الإشعارات',
                            emptyText: 'لا توجد إشعارات'
                        })}
                        ${this._renderDashCard({
                            icon: 'activity', count: null,
                            title: 'ملخص سريع',
                            custom: `
                                <div class="portal-quick-stats">
                                    <div class="portal-quick-stat">
                                        <i data-lucide="check-circle" style="width:16px;height:16px;color:var(--portal-success)"></i>
                                        <span>${d.projects.list ? d.projects.list.filter(p => p.status === 'completed').length : 0} مكتمل</span>
                                    </div>
                                    <div class="portal-quick-stat">
                                        <i data-lucide="loader" style="width:16px;height:16px;color:var(--portal-info)"></i>
                                        <span>${d.projects.list ? d.projects.list.filter(p => p.status === 'in_progress').length : 0} قيد التنفيذ</span>
                                    </div>
                                    <div class="portal-quick-stat">
                                        <i data-lucide="eye" style="width:16px;height:16px;color:var(--portal-warning)"></i>
                                        <span>${d.projects.list ? d.projects.list.filter(p => p.status === 'review').length : 0} مراجعة</span>
                                    </div>
                                    <div class="portal-quick-stat">
                                        <i data-lucide="file-check" style="width:16px;height:16px;color:var(--portal-success)"></i>
                                        <span>${d.pending_approvals.total === 0 ? 'كل الملفات مراجعة ✓' : d.pending_approvals.total + ' ملف بانتظارك'}</span>
                                    </div>
                                </div>
                            `
                        })}
                    </div>
                </div>
            `;
            if (typeof lucide !== 'undefined') lucide.createIcons();
        } catch (err) {
            main.innerHTML = `
                <div class="portal-error-state">
                    <div class="portal-error-icon"><i data-lucide="alert-triangle"></i></div>
                    <h3>خطأ في تحميل البيانات</h3>
                    <p>${this.escHtml(err.message)}</p>
                    <button class="portal-btn-retry" onclick="PortalApp.renderDashboard()">
                        <i data-lucide="refresh-cw"></i> إعادة المحاولة
                    </button>
                </div>
            `;
            if (typeof lucide !== 'undefined') lucide.createIcons();
        }
    },

    _renderDashCard({ icon, count, title, items, onclick, footer, emptyText, accent, custom }) {
        const clickAttr = onclick ? `onclick="${onclick}"` : '';
        const accentClass = accent ? ` portal-card--${accent}` : '';
        const cursorClass = onclick ? ' portal-card--clickable' : '';

        const countHtml = count !== null && count !== undefined
            ? `<span class="portal-card-count">${count}</span>` : '';

        let bodyHtml = '';
        if (custom) {
            bodyHtml = custom;
        } else if (items && items.length > 0) {
            bodyHtml = `<div class="portal-card-list">
                ${items.map(item => `
                    <div class="portal-card-list-item${item.unread ? ' portal-unread' : ''}"${item.onclick ? ` onclick="${item.onclick}"` : ''}>
                        <span class="portal-card-item-label">${item.icon ? `<span class="portal-card-item-icon">${item.icon}</span>` : ''}${this.escHtml(item.label)}</span>
                        ${item.extra || ''}
                    </div>
                `).join('')}
            </div>`;
        } else if (emptyText) {
            bodyHtml = `<div class="portal-card-empty">${this.escHtml(emptyText)}</div>`;
        }

        const footerHtml = footer && onclick
            ? `<div class="portal-card-footer"><span>${this.escHtml(footer)}</span><i data-lucide="arrow-left" style="width:14px;height:14px"></i></div>` : '';

        return `
            <div class="portal-card${accentClass}${cursorClass}" ${clickAttr}>
                <div class="portal-card-header">
                    <span class="portal-card-icon"><i data-lucide="${icon}"></i></span>
                    ${countHtml}
                </div>
                <h3>${this.escHtml(title)}</h3>
                ${bodyHtml}
                ${footerHtml}
            </div>
        `;
    },

    // ============ Projects ============
    async renderProjects(page, statusFilter) {
        const main = document.getElementById('portalMain');
        main.innerHTML = this._projectsSkeleton();

        const currentStatus = statusFilter || 'all';

        try {
            const res = await this.apiFetch(`?action=client_projects&page=${page || 1}&status=${currentStatus}`);
            const data = await res.json();
            if (!data.success) throw new Error(data.error);

            const totalPages = Math.ceil(data.total / data.per_page);

            // Status filter tabs
            const statuses = [
                { key: 'all', label: 'الكل' },
                { key: 'active', label: 'نشط' },
                { key: 'in_progress', label: 'قيد التنفيذ' },
                { key: 'review', label: 'مراجعة' },
                { key: 'completed', label: 'مكتمل' }
            ];

            const filterHtml = `
                <div class="portal-filter-tabs">
                    ${statuses.map(s => `
                        <button class="portal-filter-tab${s.key === currentStatus ? ' portal-filter-active' : ''}"
                            onclick="PortalApp.showScreen('projects', {page: 1, status: '${s.key}'})">
                            ${this.escHtml(s.label)}
                        </button>
                    `).join('')}
                </div>
            `;

            if (data.projects.length === 0) {
                main.innerHTML = `
                    <div class="portal-projects">
                        <div class="portal-page-header">
                            <h2 class="portal-page-title">المشاريع</h2>
                            <span class="portal-page-count">${data.total} مشروع</span>
                        </div>
                        ${filterHtml}
                        <div class="portal-empty-state">
                            <div class="portal-empty-icon"><i data-lucide="folder-open"></i></div>
                            <h3>لا توجد مشاريع</h3>
                            <p>${currentStatus !== 'all' ? 'لا توجد مشاريع بهذه الحالة. جرّب تصفية مختلفة' : 'لم تُضف أي مشاريع بعد'}</p>
                        </div>
                    </div>
                `;
                if (typeof lucide !== 'undefined') lucide.createIcons();
                return;
            }

            main.innerHTML = `
                <div class="portal-projects">
                    <div class="portal-page-header">
                        <h2 class="portal-page-title">المشاريع</h2>
                        <span class="portal-page-count">${data.total} مشروع</span>
                    </div>
                    ${filterHtml}
                    <div class="portal-projects-grid">
                        ${data.projects.map((p, idx) => `
                            <div class="portal-project-card" style="animation-delay:${idx * 0.06}s" onclick="PortalApp.showScreen('project_detail', {projectId: '${this.escAttr(p.id)}'})">
                                <div class="portal-project-cover">
                                    ${p.cover_image
                                        ? `<img src="${this.escAttr(p.cover_image)}" alt="" loading="lazy">`
                                        : `<div class="portal-project-cover-placeholder"><i data-lucide="folder-open" style="width:48px;height:48px"></i></div>`
                                    }
                                    <span class="portal-status-badge status-${this.escAttr(p.status)}">${this.statusLabel(p.status)}</span>
                                </div>
                                <div class="portal-project-info">
                                    <h3>${this.escHtml(p.name)}</h3>
                                    ${p.description ? `<p class="portal-project-desc">${this.escHtml(p.description).substring(0, 120)}${p.description.length > 120 ? '...' : ''}</p>` : ''}
                                    <div class="portal-project-meta">
                                        ${p.deadline ? `<span class="portal-meta-item"><i data-lucide="calendar" style="width:14px;height:14px"></i> ${this.formatDate(p.deadline)}</span>` : ''}
                                        ${p.start_date ? `<span class="portal-meta-item"><i data-lucide="play" style="width:14px;height:14px"></i> ${this.formatDate(p.start_date)}</span>` : ''}
                                    </div>
                                </div>
                            </div>
                        `).join('')}
                    </div>
                    ${this._renderPagination(data.total, page || 1, data.per_page, currentStatus)}
                </div>
            `;
            if (typeof lucide !== 'undefined') lucide.createIcons();
        } catch (err) {
            main.innerHTML = `
                <div class="portal-error-state">
                    <div class="portal-error-icon"><i data-lucide="alert-triangle"></i></div>
                    <h3>خطأ في تحميل المشاريع</h3>
                    <p>${this.escHtml(err.message)}</p>
                    <button class="portal-btn-retry" onclick="PortalApp.showScreen('projects')">
                        <i data-lucide="refresh-cw"></i> إعادة المحاولة
                    </button>
                </div>
            `;
            if (typeof lucide !== 'undefined') lucide.createIcons();
        }
    },

    _renderPagination(total, currentPage, perPage, statusFilter) {
        const totalPages = Math.ceil(total / perPage);
        if (totalPages <= 1) return '';

        const status = statusFilter || 'all';
        let pages = [];
        for (let i = 1; i <= totalPages; i++) {
            if (i === 1 || i === totalPages || (i >= currentPage - 1 && i <= currentPage + 1)) {
                pages.push(i);
            } else if (pages[pages.length - 1] !== '...') {
                pages.push('...');
            }
        }

        return `
            <div class="portal-pagination">
                <button class="portal-pagination-btn"
                    ${currentPage <= 1 ? 'disabled' : ''}
                    onclick="PortalApp.showScreen('projects', {page: ${currentPage - 1}, status: '${status}'})">
                    <i data-lucide="chevron-right" style="width:16px;height:16px"></i>
                </button>
                ${pages.map(p => p === '...'
                    ? '<span class="portal-pagination-dots">...</span>'
                    : `<button class="portal-pagination-btn${p === currentPage ? ' portal-pagination-active' : ''}"
                        onclick="PortalApp.showScreen('projects', {page: ${p}, status: '${status}'})">${p}</button>`
                ).join('')}
                <button class="portal-pagination-btn"
                    ${currentPage >= totalPages ? 'disabled' : ''}
                    onclick="PortalApp.showScreen('projects', {page: ${currentPage + 1}, status: '${status}'})">
                    <i data-lucide="chevron-left" style="width:16px;height:16px"></i>
                </button>
            </div>
        `;
    },

    // ============ Project Detail Skeleton ============
    _projectDetailSkeleton() {
        return `
            <div class="portal-project-detail">
                <div class="portal-skeleton" style="height:18px;width:100px;margin-bottom:20px"></div>
                <div class="portal-detail-hero portal-skeleton-card">
                    <div class="portal-skeleton" style="height:28px;width:240px;margin-bottom:10px"></div>
                    <div class="portal-skeleton" style="height:16px;width:180px;margin-bottom:16px"></div>
                    <div class="portal-skeleton" style="height:4px;width:100%;border-radius:2px"></div>
                </div>
                <div class="portal-skeleton" style="height:40px;width:100%;margin:20px 0;border-radius:20px"></div>
                <div class="portal-file-grid">
                    ${[1,2,3,4].map(() => `
                        <div class="portal-file-card portal-skeleton-card">
                            <div class="portal-skeleton" style="height:56px;width:56px;border-radius:12px"></div>
                            <div style="flex:1">
                                <div class="portal-skeleton" style="height:16px;width:70%;margin-bottom:8px"></div>
                                <div class="portal-skeleton" style="height:12px;width:50%"></div>
                            </div>
                        </div>
                    `).join('')}
                </div>
            </div>
        `;
    },

    // ============ Project Detail ============
    async renderProjectDetail(projectId) {
        const main = document.getElementById('portalMain');
        main.innerHTML = this._projectDetailSkeleton();
        this.currentProject = projectId;
        this._currentFileCategory = 'all';
        this._currentApprovalFilter = 'all';

        try {
            const res = await this.apiFetch(`?action=client_project_detail&project_id=${encodeURIComponent(projectId)}`);
            const data = await res.json();
            if (!data.success) throw new Error(data.error);

            this._projectData = data;
            this._renderProjectDetailView(data);
        } catch (err) {
            main.innerHTML = `
                <div class="portal-error-state">
                    <button class="portal-back-btn" onclick="PortalApp.showScreen('projects')">
                        <i data-lucide="arrow-right"></i> رجوع للمشاريع
                    </button>
                    <div class="portal-error-icon"><i data-lucide="alert-triangle"></i></div>
                    <h3>خطأ في تحميل المشروع</h3>
                    <p>${this.escHtml(err.message)}</p>
                    <button class="portal-btn-retry" onclick="PortalApp.renderProjectDetail('${this.escAttr(projectId)}')">
                        <i data-lucide="refresh-cw"></i> إعادة المحاولة
                    </button>
                </div>
            `;
            if (typeof lucide !== 'undefined') lucide.createIcons();
        }
    },

    _renderProjectDetailView(data) {
        const main = document.getElementById('portalMain');
        const p = data.project;
        const files = data.files || [];

        // Progress calculation
        const totalApproval = p.approved_files + p.pending_files + p.revision_files;
        const progressPct = totalApproval > 0 ? Math.round((p.approved_files / totalApproval) * 100) : 0;

        // Category filter tabs
        const categories = [
            { key: 'all', label: 'الكل', icon: 'layers' },
            { key: 'design', label: 'تصاميم', icon: 'image' },
            { key: 'video', label: 'فيديو', icon: 'video' },
            { key: 'document', label: 'مستندات', icon: 'file-text' },
            { key: 'audio', label: 'صوت', icon: 'music' }
        ];

        // Approval filter
        const approvalFilters = [
            { key: 'all', label: 'الكل' },
            { key: 'pending', label: 'بانتظار الموافقة' },
            { key: 'approved', label: 'تمت الموافقة' },
            { key: 'revision_requested', label: 'طلب تعديل' }
        ];

        const catFilter = this._currentFileCategory || 'all';
        const appFilter = this._currentApprovalFilter || 'all';

        // Client-side filtering
        let filteredFiles = files;
        if (catFilter !== 'all') {
            filteredFiles = filteredFiles.filter(f => f.category === catFilter);
        }
        if (appFilter !== 'all') {
            filteredFiles = filteredFiles.filter(f => (f.approval_status || '') === appFilter);
        }

        main.innerHTML = `
            <div class="portal-project-detail">
                <button class="portal-back-btn" onclick="PortalApp.showScreen('projects')">
                    <i data-lucide="arrow-right"></i> رجوع للمشاريع
                </button>

                <div class="portal-detail-hero">
                    <div class="portal-detail-hero-content">
                        <div class="portal-detail-title-row">
                            <h1>${this.escHtml(p.name)}</h1>
                            <span class="portal-status-badge status-${this.escAttr(p.status)}">${this.statusLabel(p.status)}</span>
                        </div>
                        ${p.description ? `<p class="portal-detail-desc">${this.escHtml(p.description)}</p>` : ''}
                        <div class="portal-detail-meta">
                            ${p.start_date ? `<span class="portal-meta-item"><i data-lucide="calendar" style="width:15px;height:15px"></i> بداية: ${this.formatDate(p.start_date)}</span>` : ''}
                            ${p.deadline ? `<span class="portal-meta-item"><i data-lucide="calendar-clock" style="width:15px;height:15px"></i> التسليم: ${this.formatDate(p.deadline)}</span>` : ''}
                            <span class="portal-meta-item"><i data-lucide="file" style="width:15px;height:15px"></i> ${p.total_files} ملف</span>
                        </div>
                    </div>
                    ${totalApproval > 0 ? `
                        <div class="portal-detail-progress-section">
                            <div class="portal-detail-progress-stats">
                                <span class="portal-detail-progress-label">تقدّم الموافقات</span>
                                <span class="portal-detail-progress-pct">${progressPct}%</span>
                            </div>
                            <div class="portal-progress-bar-wrap">
                                <div class="portal-progress-bar" style="width:${progressPct}%"></div>
                            </div>
                            <div class="portal-detail-approval-summary">
                                <span class="portal-approval-stat portal-approval-stat--approved">
                                    <i data-lucide="check-circle" style="width:14px;height:14px"></i> ${p.approved_files} مُوافق
                                </span>
                                <span class="portal-approval-stat portal-approval-stat--pending">
                                    <i data-lucide="clock" style="width:14px;height:14px"></i> ${p.pending_files} بانتظار
                                </span>
                                ${p.revision_files > 0 ? `
                                    <span class="portal-approval-stat portal-approval-stat--revision">
                                        <i data-lucide="edit-3" style="width:14px;height:14px"></i> ${p.revision_files} تعديل
                                    </span>
                                ` : ''}
                            </div>
                        </div>
                    ` : ''}
                </div>

                <div class="portal-detail-filters">
                    <div class="portal-filter-tabs">
                        ${categories.map(c => `
                            <button class="portal-filter-tab${c.key === catFilter ? ' portal-filter-active' : ''}"
                                onclick="PortalApp._filterProjectFiles('${c.key}', null)">
                                <i data-lucide="${c.icon}" style="width:14px;height:14px"></i>
                                ${this.escHtml(c.label)}
                            </button>
                        `).join('')}
                    </div>
                    <div class="portal-filter-tabs portal-filter-tabs--secondary">
                        ${approvalFilters.map(a => `
                            <button class="portal-filter-tab portal-filter-tab--sm${a.key === appFilter ? ' portal-filter-active' : ''}"
                                onclick="PortalApp._filterProjectFiles(null, '${a.key}')">
                                ${this.escHtml(a.label)}
                            </button>
                        `).join('')}
                    </div>
                </div>

                ${filteredFiles.length > 0 ? `
                    <div class="portal-file-grid">
                        ${filteredFiles.map((f, idx) => this._renderFileCard(f, idx)).join('')}
                    </div>
                ` : `
                    <div class="portal-empty-state">
                        <div class="portal-empty-icon"><i data-lucide="file-x"></i></div>
                        <h3>لا توجد ملفات</h3>
                        <p>${catFilter !== 'all' || appFilter !== 'all' ? 'لا توجد ملفات تطابق الفلتر. جرّب تصفية مختلفة' : 'لم يتم رفع أي ملفات بعد لهذا المشروع'}</p>
                    </div>
                `}
            </div>
        `;
        if (typeof lucide !== 'undefined') lucide.createIcons();
    },

    _renderFileCard(f, idx) {
        const mimeIcon = this.getFileIcon(f.mime_type);
        const approvalBadge = this._approvalBadgeHtml(f.approval_status);

        return `
            <div class="portal-file-card" style="animation-delay:${idx * 0.04}s"
                onclick="PortalApp.showScreen('file_preview', {fileId: '${this.escAttr(f.id)}'})">
                <div class="portal-file-card-icon">
                    ${this._getFileThumbnail(f)}
                </div>
                <div class="portal-file-card-info">
                    <div class="portal-file-card-name">${this.escHtml(f.file_name)}</div>
                    <div class="portal-file-card-meta">
                        <span>${this.formatSize(f.file_size)}</span>
                        <span>·</span>
                        <span>v${f.version || 1}</span>
                        <span>·</span>
                        <span>${this.timeAgo(f.created_at)}</span>
                    </div>
                </div>
                <div class="portal-file-card-status">
                    ${approvalBadge}
                </div>
            </div>
        `;
    },

    _getFileThumbnail(f) {
        const mime = f.mime_type || '';
        if (mime.startsWith('image/') && f.file_path) {
            const config = window.PORTAL_CONFIG || {};
            const url = (config.supabaseUrl || '') + '/storage/v1/object/public/' + (config.bucket || '') + '/' + f.file_path;
            return `<img src="${this.escAttr(url)}" alt="" class="portal-file-thumb" loading="lazy">`;
        }
        return this.getFileIcon(mime);
    },

    _approvalBadgeHtml(status) {
        if (!status) return '';
        const map = {
            'pending': { label: 'بانتظار', icon: 'clock', cls: 'pending' },
            'approved': { label: 'مُوافق', icon: 'check-circle', cls: 'approved' },
            'revision_requested': { label: 'تعديل', icon: 'edit-3', cls: 'revision' }
        };
        const s = map[status];
        if (!s) return '';
        return `<span class="portal-approval-badge portal-approval-badge--${s.cls}">
            <i data-lucide="${s.icon}" style="width:12px;height:12px"></i>
            ${s.label}
        </span>`;
    },

    _filterProjectFiles(category, approval) {
        if (category !== null) this._currentFileCategory = category;
        if (approval !== null) this._currentApprovalFilter = approval;
        if (this._projectData) {
            this._renderProjectDetailView(this._projectData);
        }
    },

    // ============ File Preview ============
    _filePreviewSkeleton() {
        return `
            <div class="portal-file-preview">
                <div class="portal-skeleton" style="height:18px;width:100px;margin-bottom:20px"></div>
                <div class="portal-preview-container portal-skeleton-card">
                    <div class="portal-skeleton" style="height:400px;width:100%;border-radius:12px"></div>
                </div>
                <div class="portal-preview-info portal-skeleton-card" style="margin-top:20px">
                    <div class="portal-skeleton" style="height:24px;width:200px;margin-bottom:10px"></div>
                    <div class="portal-skeleton" style="height:14px;width:150px;margin-bottom:16px"></div>
                    <div class="portal-skeleton" style="height:44px;width:100%;border-radius:10px"></div>
                </div>
            </div>
        `;
    },

    async renderFilePreview(fileId) {
        const main = document.getElementById('portalMain');
        main.innerHTML = this._filePreviewSkeleton();
        this.currentFile = fileId;

        try {
            const res = await this.apiFetch(`?action=client_file_preview&file_id=${encodeURIComponent(fileId)}`);
            const data = await res.json();
            if (!data.success) throw new Error(data.error);

            const f = data.file;
            const mime = f.mime_type || '';
            const approval = f.approval;
            const canApprove = f.needs_approval && (window.PORTAL_CONFIG?.client?.role === 'primary');

            // Back button — go to project detail if we know the project
            const backAction = f.project
                ? `PortalApp.showScreen('project_detail', {projectId: '${this.escAttr(f.project.id)}'})`
                : `history.back()`;
            const backLabel = f.project ? `رجوع لـ ${this.escHtml(f.project.name)}` : 'رجوع';

            // Build preview HTML based on MIME type
            const previewHtml = this._buildPreviewMedia(f, mime);

            // Build approval section
            const approvalHtml = canApprove ? this._buildApprovalSection(f, approval) : this._buildApprovalReadonly(approval);

            main.innerHTML = `
                <div class="portal-file-preview">
                    <button class="portal-back-btn" onclick="${backAction}">
                        <i data-lucide="arrow-right"></i> ${backLabel}
                    </button>

                    <div class="portal-preview-container">
                        ${previewHtml}
                    </div>

                    <div class="portal-preview-info-panel">
                        <div class="portal-preview-header">
                            <div class="portal-preview-file-icon">
                                ${this.getFileIcon(mime)}
                            </div>
                            <div class="portal-preview-file-details">
                                <h2>${this.escHtml(f.file_name)}</h2>
                                <div class="portal-preview-meta">
                                    <span>${this.formatSize(f.file_size)}</span>
                                    <span>·</span>
                                    <span>${this.escHtml(f.category || 'general')}</span>
                                    <span>·</span>
                                    <span>الإصدار ${f.version || 1}</span>
                                    <span>·</span>
                                    <span>${this.timeAgo(f.created_at)}</span>
                                </div>
                            </div>
                        </div>

                        <div class="portal-preview-actions">
                            <a href="index.php?action=client_download&file_id=${this.escAttr(f.id)}"
                                class="portal-btn portal-btn--primary" target="_blank">
                                <i data-lucide="download" style="width:16px;height:16px"></i>
                                تحميل الملف
                            </a>
                            ${f.public_url ? `
                                <button class="portal-btn portal-btn--ghost" onclick="PortalApp._copyLink('${this.escAttr(f.public_url)}')">
                                    <i data-lucide="link" style="width:16px;height:16px"></i>
                                    نسخ الرابط
                                </button>
                            ` : ''}
                        </div>

                        ${approvalHtml}

                        ${f.project ? `
                            <div class="portal-preview-project-link">
                                <span class="portal-meta-item"><i data-lucide="folder-open" style="width:14px;height:14px"></i> ${this.escHtml(f.project.name)}</span>
                            </div>
                        ` : ''}
                    </div>
                </div>
            `;
            if (typeof lucide !== 'undefined') lucide.createIcons();
        } catch (err) {
            main.innerHTML = `
                <div class="portal-error-state">
                    <button class="portal-back-btn" onclick="history.back()">
                        <i data-lucide="arrow-right"></i> رجوع
                    </button>
                    <div class="portal-error-icon"><i data-lucide="alert-triangle"></i></div>
                    <h3>خطأ في تحميل الملف</h3>
                    <p>${this.escHtml(err.message)}</p>
                    <button class="portal-btn-retry" onclick="PortalApp.renderFilePreview('${this.escAttr(fileId)}')">
                        <i data-lucide="refresh-cw"></i> إعادة المحاولة
                    </button>
                </div>
            `;
            if (typeof lucide !== 'undefined') lucide.createIcons();
        }
    },

    _buildPreviewMedia(f, mime) {
        const url = f.public_url || '';
        if (!url) {
            return `<div class="portal-preview-placeholder"><i data-lucide="file-question" style="width:64px;height:64px"></i><p>المعاينة غير متاحة</p></div>`;
        }

        if (mime.startsWith('image/')) {
            return `<img src="${this.escAttr(url)}" alt="${this.escAttr(f.file_name)}" class="portal-preview-image" loading="lazy">`;
        }
        if (mime.startsWith('video/')) {
            return `<video controls preload="metadata" class="portal-preview-video"><source src="${this.escAttr(url)}" type="${this.escAttr(mime)}">المتصفح لا يدعم تشغيل الفيديو</video>`;
        }
        if (mime.startsWith('audio/')) {
            return `
                <div class="portal-preview-audio-wrap">
                    <div class="portal-preview-audio-icon"><i data-lucide="music" style="width:64px;height:64px"></i></div>
                    <audio controls preload="metadata" class="portal-preview-audio"><source src="${this.escAttr(url)}" type="${this.escAttr(mime)}"></audio>
                </div>
            `;
        }
        if (mime.includes('pdf')) {
            return `<iframe src="${this.escAttr(url)}" class="portal-preview-pdf" title="${this.escAttr(f.file_name)}"></iframe>`;
        }

        // Generic file — show icon + info
        return `
            <div class="portal-preview-placeholder">
                ${this.getFileIcon(mime)}
                <p>${this.escHtml(f.file_name)}</p>
                <span>${this.formatSize(f.file_size)}</span>
            </div>
        `;
    },

    // ============ Approval Section ============
    _buildApprovalSection(f, approval) {
        if (!approval) {
            // No approval record yet, but file needs approval
            return this._buildApprovalPending(f.id, null);
        }

        switch (approval.status) {
            case 'pending':
                return this._buildApprovalPending(f.id, approval);
            case 'approved':
                return this._buildApprovalApproved(approval);
            case 'revision_requested':
                return this._buildApprovalRevision(f.id, approval);
            default:
                return '';
        }
    },

    _buildApprovalPending(fileId, approval) {
        return `
            <div class="portal-approval-section portal-approval-section--pending" id="approvalSection">
                <div class="portal-approval-header">
                    <i data-lucide="clock" style="width:20px;height:20px;color:var(--portal-warning)"></i>
                    <span>هذا الملف بانتظار موافقتك</span>
                </div>
                <div class="portal-approval-actions" id="approvalActions">
                    <button class="portal-btn portal-btn--success" onclick="PortalApp.approveFile('${this.escAttr(fileId)}')">
                        <i data-lucide="check" style="width:16px;height:16px"></i>
                        موافقة
                    </button>
                    <button class="portal-btn portal-btn--danger-outline" onclick="PortalApp.showRevisionForm('${this.escAttr(fileId)}')">
                        <i data-lucide="edit-3" style="width:16px;height:16px"></i>
                        طلب تعديل
                    </button>
                </div>
                <div class="portal-revision-form" id="revisionForm" style="display:none">
                    <textarea class="portal-textarea" id="revisionComment" placeholder="وصف التعديلات المطلوبة... (10 حروف على الأقل)" rows="3"></textarea>
                    <div class="portal-revision-form-actions">
                        <button class="portal-btn portal-btn--danger" id="revisionSubmitBtn" onclick="PortalApp.requestRevision('${this.escAttr(fileId)}')">
                            <i data-lucide="send" style="width:14px;height:14px"></i>
                            إرسال طلب التعديل
                        </button>
                        <button class="portal-btn portal-btn--ghost" onclick="PortalApp.hideRevisionForm()">إلغاء</button>
                    </div>
                </div>
            </div>
        `;
    },

    _buildApprovalApproved(approval) {
        return `
            <div class="portal-approval-section portal-approval-section--approved">
                <div class="portal-approval-header">
                    <i data-lucide="check-circle" style="width:20px;height:20px;color:var(--portal-success)"></i>
                    <span>تمت الموافقة</span>
                    ${approval.updated_at ? `<time>${this.timeAgo(approval.updated_at)}</time>` : ''}
                </div>
            </div>
        `;
    },

    _buildApprovalRevision(fileId, approval) {
        return `
            <div class="portal-approval-section portal-approval-section--revision">
                <div class="portal-approval-header">
                    <i data-lucide="edit-3" style="width:20px;height:20px;color:var(--portal-danger)"></i>
                    <span>طُلب تعديل</span>
                    ${approval.updated_at ? `<time>${this.timeAgo(approval.updated_at)}</time>` : ''}
                </div>
                ${approval.comment ? `
                    <div class="portal-approval-comment">
                        <p>"${this.escHtml(approval.comment)}"</p>
                    </div>
                ` : ''}
            </div>
        `;
    },

    _buildApprovalReadonly(approval) {
        if (!approval) return '';
        switch (approval.status) {
            case 'approved':
                return this._buildApprovalApproved(approval);
            case 'revision_requested':
                return this._buildApprovalRevision(null, approval);
            case 'pending':
                return `
                    <div class="portal-approval-section portal-approval-section--pending">
                        <div class="portal-approval-header">
                            <i data-lucide="clock" style="width:20px;height:20px;color:var(--portal-warning)"></i>
                            <span>بانتظار الموافقة</span>
                        </div>
                    </div>
                `;
            default:
                return '';
        }
    },

    // ============ Approval Actions ============
    showRevisionForm(fileId) {
        const form = document.getElementById('revisionForm');
        const actions = document.getElementById('approvalActions');
        if (form) form.style.display = 'block';
        if (actions) actions.style.display = 'none';
        const textarea = document.getElementById('revisionComment');
        if (textarea) textarea.focus();
    },

    hideRevisionForm() {
        const form = document.getElementById('revisionForm');
        const actions = document.getElementById('approvalActions');
        if (form) form.style.display = 'none';
        if (actions) actions.style.display = 'flex';
    },

    async approveFile(fileId) {
        const section = document.getElementById('approvalSection');
        try {
            const res = await this.apiFetch('?action=client_approve_file', {
                method: 'POST',
                body: { file_id: fileId }
            });
            const data = await res.json();

            if (data.success) {
                this.toast('تمت الموافقة بنجاح ✅', 'success');
                // Replace approval section with approved state
                if (section) {
                    section.outerHTML = this._buildApprovalApproved({ status: 'approved', updated_at: new Date().toISOString() });
                    if (typeof lucide !== 'undefined') lucide.createIcons();
                }
            } else {
                this.toast(data.error || 'حدث خطأ', 'error');
            }
        } catch (err) {
            this.toast('خطأ في الاتصال بالخادم', 'error');
        }
    },

    async requestRevision(fileId) {
        const comment = (document.getElementById('revisionComment')?.value || '').trim();
        if (comment.length < 10) {
            this.toast('التعليق يجب أن يكون 10 حروف على الأقل', 'error');
            return;
        }

        const btn = document.getElementById('revisionSubmitBtn');
        if (btn) { btn.disabled = true; btn.classList.add('portal-btn-loading'); }

        try {
            const res = await this.apiFetch('?action=client_request_revision', {
                method: 'POST',
                body: { file_id: fileId, comment }
            });
            const data = await res.json();

            if (data.success) {
                this.toast('تم إرسال طلب التعديل ✏️', 'success');
                const section = document.getElementById('approvalSection');
                if (section) {
                    section.outerHTML = this._buildApprovalRevision(fileId, {
                        status: 'revision_requested',
                        comment,
                        updated_at: new Date().toISOString()
                    });
                    if (typeof lucide !== 'undefined') lucide.createIcons();
                }
            } else {
                this.toast(data.error || 'حدث خطأ', 'error');
                if (btn) { btn.disabled = false; btn.classList.remove('portal-btn-loading'); }
            }
        } catch (err) {
            this.toast('خطأ في الاتصال بالخادم', 'error');
            if (btn) { btn.disabled = false; btn.classList.remove('portal-btn-loading'); }
        }
    },

    _copyLink(url) {
        if (navigator.clipboard) {
            navigator.clipboard.writeText(url).then(() => {
                this.toast('تم نسخ الرابط 📋', 'success');
            }).catch(() => {
                this.toast('لم يتم نسخ الرابط', 'error');
            });
        } else {
            // Fallback
            const inp = document.createElement('input');
            inp.value = url;
            document.body.appendChild(inp);
            inp.select();
            document.execCommand('copy');
            inp.remove();
            this.toast('تم نسخ الرابط 📋', 'success');
        }
    },

    // ============ Notifications (stub) ============
    async renderNotifications() {
        const main = document.getElementById('portalMain');
        main.innerHTML = '<div class="portal-loading"><div class="portal-spinner"></div></div>';
        main.innerHTML = `
            <div class="portal-stub-screen">
                <div class="portal-empty-state">
                    <div class="portal-empty-icon"><i data-lucide="bell"></i></div>
                    <h3>الإشعارات</h3>
                    <p>هذه الشاشة قيد التطوير — سيتم إضافتها في المرحلة القادمة</p>
                </div>
            </div>
        `;
        if (typeof lucide !== 'undefined') lucide.createIcons();
    },

    // ============ Profile (stub) ============
    async renderProfile() {
        const main = document.getElementById('portalMain');
        main.innerHTML = '<div class="portal-loading"><div class="portal-spinner"></div></div>';
        main.innerHTML = `
            <div class="portal-stub-screen">
                <div class="portal-empty-state">
                    <div class="portal-empty-icon"><i data-lucide="user"></i></div>
                    <h3>الملف الشخصي</h3>
                    <p>هذه الشاشة قيد التطوير — سيتم إضافتها في المرحلة القادمة</p>
                </div>
            </div>
        `;
        if (typeof lucide !== 'undefined') lucide.createIcons();
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
        div.textContent = String(str);
        return div.innerHTML;
    },

    escAttr(str) {
        if (!str) return '';
        return String(str).replace(/[&"'<>]/g, c => ({
            '&': '&amp;', '"': '&quot;', "'": '&#39;', '<': '&lt;', '>': '&gt;'
        })[c]);
    },

    // ============ File Icon Helper ============
    getFileIcon(mimeType) {
        if (!mimeType) return '<i data-lucide="file" style="width:16px;height:16px"></i>';
        if (mimeType.startsWith('image/')) return '<i data-lucide="image" style="width:16px;height:16px;color:#10b981"></i>';
        if (mimeType.startsWith('video/')) return '<i data-lucide="video" style="width:16px;height:16px;color:#8b5cf6"></i>';
        if (mimeType.startsWith('audio/')) return '<i data-lucide="music" style="width:16px;height:16px;color:#f59e0b"></i>';
        if (mimeType.includes('pdf')) return '<i data-lucide="file-text" style="width:16px;height:16px;color:#ef4444"></i>';
        return '<i data-lucide="file" style="width:16px;height:16px"></i>';
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
            'revision_requested': 'طلب تعديل',
            'draft': 'مسودة',
            'archived': 'مؤرشف'
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
