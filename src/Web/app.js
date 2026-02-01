/**
 * ShipPHP Web Dashboard Application
 * Complete frontend for ShipPHP deployment tool
 */

const App = {
    // State
    currentPage: 'dashboard',
    currentPath: '/',
    selectedFiles: [],
    statusData: null,
    isInitialized: false,
    autoRefreshInterval: null,

    // API Base URL
    apiBase: '/api',

    /**
     * Initialize the application
     */
    async init() {
        // Initialize Lucide icons
        lucide.createIcons();

        // Check if project is initialized
        try {
            const health = await this.api('GET', '/health');
            if (health.success) {
                this.isInitialized = true;
                this.showMainLayout();
                await this.loadDashboard();
            } else {
                this.showSetupPage();
            }
        } catch (error) {
            this.showSetupPage();
        }

        // Hide splash screen
        setTimeout(() => {
            document.getElementById('splash-screen').style.display = 'none';
        }, 800);
    },

    /**
     * API Helper
     */
    async api(method, endpoint, data = null) {
        const options = {
            method,
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            }
        };

        if (data && (method === 'POST' || method === 'PUT' || method === 'PATCH')) {
            options.body = JSON.stringify(data);
        }

        try {
            const response = await fetch(this.apiBase + endpoint, options);
            const json = await response.json();
            return json;
        } catch (error) {
            console.error('API Error:', error);
            return { success: false, error: error.message };
        }
    },

    /**
     * Show Setup Page
     */
    showSetupPage() {
        document.getElementById('page-setup').classList.add('active');
        document.getElementById('main-layout').classList.add('hidden');
        this.loadProfiles();
        lucide.createIcons();
    },

    /**
     * Show Main Layout
     */
    showMainLayout() {
        document.getElementById('page-setup').classList.remove('active');
        document.getElementById('main-layout').classList.remove('hidden');
        lucide.createIcons();
    },

    /**
     * Show Init Form
     */
    showInitForm() {
        document.getElementById('setup-options').classList.add('hidden');
        document.getElementById('setup-profile-select').classList.add('hidden');
        document.getElementById('setup-init-form').classList.remove('hidden');
        this.updateSetupStep(2);
        lucide.createIcons();
    },

    /**
     * Show Profile Selection
     */
    showProfileSelect() {
        document.getElementById('setup-options').classList.add('hidden');
        document.getElementById('setup-init-form').classList.add('hidden');
        document.getElementById('setup-profile-select').classList.remove('hidden');
        this.updateSetupStep(2);
        this.loadProfiles();
        lucide.createIcons();
    },

    /**
     * Back to Options
     */
    backToOptions() {
        document.getElementById('setup-options').classList.remove('hidden');
        document.getElementById('setup-init-form').classList.add('hidden');
        document.getElementById('setup-profile-select').classList.add('hidden');
        this.updateSetupStep(1);
        lucide.createIcons();
    },

    /**
     * Update Setup Step Indicator
     */
    updateSetupStep(step) {
        for (let i = 1; i <= 3; i++) {
            const el = document.getElementById(`step-${i}`);
            if (i <= step) {
                el.classList.remove('opacity-50');
                el.querySelector('div').classList.remove('bg-slate-700');
                el.querySelector('div').classList.add('bg-indigo-500');
                el.querySelector('span').classList.remove('text-slate-400');
                el.querySelector('span').classList.add('text-white');
            } else {
                el.classList.add('opacity-50');
                el.querySelector('div').classList.add('bg-slate-700');
                el.querySelector('div').classList.remove('bg-indigo-500');
                el.querySelector('span').classList.add('text-slate-400');
                el.querySelector('span').classList.remove('text-white');
            }
        }
    },

    /**
     * Load Profiles
     */
    async loadProfiles() {
        const result = await this.api('GET', '/profiles');
        const container = document.getElementById('profile-list');
        const noProfiles = document.getElementById('no-profiles');

        if (result.success && result.data && result.data.profiles && Object.keys(result.data.profiles).length > 0) {
            container.innerHTML = '';
            noProfiles.classList.add('hidden');

            for (const [id, profile] of Object.entries(result.data.profiles)) {
                const isDefault = result.data.default === id;
                container.innerHTML += `
                    <button onclick="App.selectProfile('${id}')" class="w-full rounded-lg border border-slate-800 bg-slate-800/50 p-4 text-left hover:border-indigo-500/50 transition-colors">
                        <div class="flex items-center justify-between">
                            <div>
                                <h4 class="font-medium text-white">${profile.projectName || id}</h4>
                                <p class="text-xs text-slate-400 mt-1">${profile.domain || profile.serverUrl}</p>
                            </div>
                            ${isDefault ? '<span class="rounded-full bg-indigo-500/20 px-2 py-1 text-xs text-indigo-200">Default</span>' : ''}
                        </div>
                    </button>
                `;
            }
        } else {
            container.innerHTML = '';
            noProfiles.classList.remove('hidden');
        }
        lucide.createIcons();
    },

    /**
     * Initialize Project
     */
    async initProject() {
        const projectName = document.getElementById('init-project-name').value;
        const environment = document.getElementById('init-environment').value;
        const serverUrl = document.getElementById('init-server-url').value;
        const token = document.getElementById('init-token').value;

        if (!serverUrl || !token) {
            this.toast('Error', 'Server URL and Token are required', 'error');
            return;
        }

        this.showGlobalProgress('Initializing project...');

        const result = await this.api('POST', '/config/init', {
            projectName,
            environment,
            serverUrl,
            token
        });

        this.hideGlobalProgress();

        if (result.success) {
            this.toast('Success', 'Project initialized successfully!', 'success');
            this.updateSetupStep(3);
            setTimeout(() => {
                this.isInitialized = true;
                this.showMainLayout();
                this.loadDashboard();
            }, 1000);
        } else {
            this.toast('Error', result.error || 'Failed to initialize project', 'error');
        }
    },

    /**
     * Select Profile
     */
    async selectProfile(profileId) {
        this.showGlobalProgress('Connecting to profile...');

        const result = await this.api('POST', '/config/login', { profile: profileId });

        this.hideGlobalProgress();

        if (result.success) {
            this.toast('Connected', 'Successfully connected to profile', 'success');
            this.updateSetupStep(3);
            setTimeout(() => {
                this.isInitialized = true;
                this.showMainLayout();
                this.loadDashboard();
            }, 1000);
        } else {
            this.toast('Error', result.error || 'Failed to connect to profile', 'error');
        }
    },

    /**
     * Navigation
     */
    navigate(page) {
        // Update page visibility
        document.querySelectorAll('.page').forEach(p => p.classList.remove('active'));
        const pageEl = document.getElementById(`page-${page}`);
        if (pageEl) {
            pageEl.classList.add('active');
        }

        // Update nav items
        document.querySelectorAll('.nav-item').forEach(n => {
            n.classList.remove('bg-indigo-500/20', 'text-indigo-200');
            n.classList.add('text-slate-300');
        });
        const navItem = document.querySelector(`[data-nav="${page}"]`);
        if (navItem) {
            navItem.classList.add('bg-indigo-500/20', 'text-indigo-200');
            navItem.classList.remove('text-slate-300');
        }

        // Update header title
        const titles = {
            dashboard: 'Dashboard',
            status: 'File Status',
            push: 'Push Changes',
            pull: 'Pull Changes',
            files: 'File Explorer',
            plans: 'Operation Plans',
            backups: 'Backups',
            trash: 'Trash',
            logs: 'Server Logs',
            settings: 'Settings'
        };
        document.getElementById('header-title').textContent = titles[page] || 'Dashboard';

        this.currentPage = page;

        // Load page-specific data
        this.loadPageData(page);

        return false;
    },

    /**
     * Load Page Data
     */
    async loadPageData(page) {
        switch (page) {
            case 'dashboard':
                await this.loadDashboard();
                break;
            case 'status':
                await this.refreshStatus();
                break;
            case 'push':
                await this.loadPushData();
                break;
            case 'pull':
                await this.loadPullData();
                break;
            case 'files':
                await this.refreshTree();
                break;
            case 'plans':
                await this.loadPlans();
                break;
            case 'backups':
                await this.loadBackups();
                break;
            case 'trash':
                await this.loadTrash();
                break;
            case 'logs':
                await this.refreshLogs();
                break;
            case 'settings':
                await this.loadSettings();
                break;
        }
    },

    /**
     * Load Dashboard
     */
    async loadDashboard() {
        // Load health
        this.loadHealth();

        // Load server info
        this.loadServerInfo();

        // Load stats
        await this.loadStats();

        // Load recent changes
        await this.loadRecentChanges();
    },

    /**
     * Load Health Status
     */
    async loadHealth() {
        const container = document.getElementById('health-status');
        try {
            const result = await this.api('GET', '/health');
            if (result.success) {
                container.innerHTML = `
                    <div class="flex items-center justify-center gap-2 text-emerald-200">
                        <i data-lucide="check-circle" class="h-6 w-6"></i>
                        <span class="font-medium">Healthy</span>
                    </div>
                    <p class="text-xs text-slate-400 mt-2">Server responding normally</p>
                `;
            } else {
                container.innerHTML = `
                    <div class="flex items-center justify-center gap-2 text-rose-200">
                        <i data-lucide="alert-circle" class="h-6 w-6"></i>
                        <span class="font-medium">Offline</span>
                    </div>
                    <p class="text-xs text-slate-400 mt-2">${result.error || 'Cannot connect'}</p>
                `;
            }
        } catch (e) {
            container.innerHTML = `
                <div class="flex items-center justify-center gap-2 text-rose-200">
                    <i data-lucide="wifi-off" class="h-6 w-6"></i>
                    <span class="font-medium">Error</span>
                </div>
            `;
        }
        lucide.createIcons();
    },

    /**
     * Load Server Info
     */
    async loadServerInfo() {
        try {
            const result = await this.api('GET', '/server/info');
            if (result.success && result.data) {
                document.getElementById('info-php').textContent = result.data.phpVersion || '-';
                document.getElementById('info-server').textContent = result.data.server || '-';
                const pathEl = document.getElementById('info-path');
                pathEl.textContent = result.data.basePath || '-';
                pathEl.title = result.data.basePath || '';
                document.getElementById('info-version').textContent = result.data.version || '-';
                document.getElementById('profile-name').textContent = result.data.profile || 'Connected';
            }
        } catch (e) {
            console.error('Failed to load server info:', e);
        }
    },

    /**
     * Load Stats
     */
    async loadStats() {
        // Get status count
        const statusResult = await this.api('GET', '/status');
        let changesCount = 0;
        if (statusResult.success && statusResult.data) {
            const changes = statusResult.data.changes || {};
            changesCount = (changes.modified?.length || 0) + (changes.new?.length || 0) + (changes.deleted?.length || 0);
        }
        document.getElementById('stat-changes').textContent = changesCount;

        // Get plans count
        const plansResult = await this.api('GET', '/plans');
        const plansCount = plansResult.success && plansResult.data ? (plansResult.data.operations?.length || 0) : 0;
        document.getElementById('stat-plans').textContent = plansCount;

        // Get backups count
        const backupsResult = await this.api('GET', '/backups');
        const backupsCount = backupsResult.success && backupsResult.data ? (backupsResult.data.backups?.length || 0) : 0;
        document.getElementById('stat-backups').textContent = backupsCount;

        // Get trash count
        const trashResult = await this.api('GET', '/trash');
        const trashCount = trashResult.success && trashResult.data ? (trashResult.data.items?.length || 0) : 0;
        document.getElementById('stat-trash').textContent = trashCount;

        // Update badges
        this.updateBadge('status-badge', changesCount);
        this.updateBadge('plans-badge', plansCount);
        this.updateBadge('trash-badge', trashCount);
    },

    /**
     * Update Badge
     */
    updateBadge(id, count) {
        const badge = document.getElementById(id);
        if (count > 0) {
            badge.textContent = count;
            badge.classList.remove('hidden');
        } else {
            badge.classList.add('hidden');
        }
    },

    /**
     * Load Recent Changes
     */
    async loadRecentChanges() {
        const container = document.getElementById('recent-changes');
        const result = await this.api('GET', '/status');

        if (result.success && result.data && result.data.changes) {
            const changes = result.data.changes;
            const allChanges = [
                ...(changes.modified || []).map(f => ({ file: f, type: 'modified' })),
                ...(changes.new || []).map(f => ({ file: f, type: 'new' })),
                ...(changes.deleted || []).map(f => ({ file: f, type: 'deleted' }))
            ].slice(0, 5);

            if (allChanges.length > 0) {
                container.innerHTML = allChanges.map(change => `
                    <div class="rounded-lg border border-slate-800 bg-slate-800/50 p-3">
                        <div class="flex items-center justify-between">
                            <span class="truncate">${change.file}</span>
                            <span class="text-xs ${this.getChangeTypeColor(change.type)}">${change.type}</span>
                        </div>
                    </div>
                `).join('');
            } else {
                container.innerHTML = `
                    <div class="text-center py-8 text-slate-400">
                        <i data-lucide="check-circle" class="h-8 w-8 mx-auto mb-2 opacity-50"></i>
                        <p>No pending changes</p>
                    </div>
                `;
            }
        }
        lucide.createIcons();
    },

    /**
     * Get Change Type Color
     */
    getChangeTypeColor(type) {
        switch (type) {
            case 'modified': return 'text-amber-200';
            case 'new': return 'text-emerald-200';
            case 'deleted': return 'text-rose-200';
            default: return 'text-slate-400';
        }
    },

    /**
     * Refresh Status
     */
    async refreshStatus() {
        const container = document.getElementById('status-files');
        container.innerHTML = `
            <div class="text-center py-8">
                <div class="spinner h-6 w-6 mx-auto border-2 border-slate-600 border-t-indigo-500 rounded-full"></div>
                <p class="mt-2 text-slate-400">Loading...</p>
            </div>
        `;

        const result = await this.api('GET', '/status');

        if (result.success && result.data) {
            this.statusData = result.data;
            const changes = result.data.changes || {};

            const modified = changes.modified || [];
            const newFiles = changes.new || [];
            const deleted = changes.deleted || [];

            document.getElementById('status-modified').textContent = modified.length;
            document.getElementById('status-new').textContent = newFiles.length;
            document.getElementById('status-deleted').textContent = deleted.length;

            const allChanges = [
                ...modified.map(f => ({ file: f, type: 'modified' })),
                ...newFiles.map(f => ({ file: f, type: 'new' })),
                ...deleted.map(f => ({ file: f, type: 'deleted' }))
            ];

            if (allChanges.length > 0) {
                container.innerHTML = allChanges.map(change => `
                    <div class="rounded-lg border border-slate-800 bg-slate-800/50 p-3">
                        <div class="flex items-center justify-between">
                            <label class="flex items-center gap-3 cursor-pointer">
                                <input type="checkbox" class="file-checkbox h-4 w-4 rounded border-slate-600 bg-slate-700" data-file="${change.file}" data-type="${change.type}">
                                <span class="truncate">${change.file}</span>
                            </label>
                            <span class="text-xs ${this.getChangeTypeColor(change.type)}">${change.type}</span>
                        </div>
                    </div>
                `).join('');
            } else {
                container.innerHTML = `
                    <div class="text-center py-8 text-slate-400">
                        <i data-lucide="check-circle" class="h-8 w-8 mx-auto mb-2 opacity-50"></i>
                        <p>Everything is in sync!</p>
                    </div>
                `;
            }

            // Update stats
            await this.loadStats();
        } else {
            container.innerHTML = `
                <div class="text-center py-8 text-rose-400">
                    <i data-lucide="alert-circle" class="h-8 w-8 mx-auto mb-2 opacity-50"></i>
                    <p>${result.error || 'Failed to load status'}</p>
                </div>
            `;
        }
        lucide.createIcons();
    },

    /**
     * Select All Changes
     */
    selectAllChanges() {
        document.querySelectorAll('.file-checkbox').forEach(cb => cb.checked = true);
    },

    /**
     * Load Push Data
     */
    async loadPushData() {
        await this.refreshStatus();

        const container = document.getElementById('push-files');
        if (this.statusData && this.statusData.changes) {
            const changes = this.statusData.changes;
            const toUpload = [...(changes.modified || []), ...(changes.new || [])];
            const toDelete = changes.deleted || [];

            document.getElementById('push-upload').textContent = toUpload.length;
            document.getElementById('push-delete').textContent = toDelete.length;

            // Calculate size (placeholder)
            document.getElementById('push-size').textContent = this.formatSize(toUpload.length * 1024);

            if (toUpload.length > 0 || toDelete.length > 0) {
                container.innerHTML = [
                    ...toUpload.map(f => `
                        <div class="rounded-lg border border-slate-800 bg-slate-800/50 p-3">
                            <div class="flex items-center justify-between">
                                <span class="truncate">${f}</span>
                                <span class="text-xs text-emerald-200">upload</span>
                            </div>
                        </div>
                    `),
                    ...toDelete.map(f => `
                        <div class="rounded-lg border border-slate-800 bg-slate-800/50 p-3">
                            <div class="flex items-center justify-between">
                                <span class="truncate">${f}</span>
                                <span class="text-xs text-rose-200">delete</span>
                            </div>
                        </div>
                    `)
                ].join('');
            } else {
                container.innerHTML = `
                    <div class="text-center py-8 text-slate-400">
                        <p>No files to push</p>
                    </div>
                `;
            }
        }
    },

    /**
     * Execute Push
     */
    async executePush() {
        const createBackup = document.getElementById('push-backup').checked;
        const deleteFiles = document.getElementById('push-delete-files').checked;
        const dryRun = document.getElementById('push-dry-run').checked;

        if (dryRun) {
            this.toast('Dry Run', 'This is a preview only. No files were changed.', 'info');
            return;
        }

        // Show progress
        document.getElementById('push-progress').classList.remove('hidden');
        this.updateProgress('push', 0, 'Starting push...');

        try {
            // Create backup if needed
            if (createBackup) {
                this.updateProgress('push', 10, 'Creating backup...');
                await this.api('POST', '/backups');
            }

            // Execute push
            this.updateProgress('push', 30, 'Uploading files...');

            const result = await this.api('POST', '/push', {
                deleteOnPush: deleteFiles
            });

            if (result.success) {
                this.updateProgress('push', 100, 'Complete!', 'success');
                this.toast('Push Complete', `Successfully pushed ${result.data?.uploaded || 0} files`, 'success');
                await this.refreshStatus();
            } else {
                this.updateProgress('push', 100, 'Failed', 'error');
                this.toast('Push Failed', result.error || 'Unknown error', 'error');
            }
        } catch (error) {
            this.updateProgress('push', 100, 'Error', 'error');
            this.toast('Error', error.message, 'error');
        }

        // Hide progress after delay
        setTimeout(() => {
            document.getElementById('push-progress').classList.add('hidden');
        }, 2000);
    },

    /**
     * Load Pull Data
     */
    async loadPullData() {
        await this.refreshStatus();

        const container = document.getElementById('pull-files');
        if (this.statusData && this.statusData.changes) {
            const changes = this.statusData.changes;
            // For pull, we show server files that are different
            const toDownload = [...(changes.modified || []), ...(changes.deleted || [])];

            document.getElementById('pull-download').textContent = toDownload.length;
            document.getElementById('pull-delete').textContent = (changes.new || []).length;
            document.getElementById('pull-size').textContent = this.formatSize(toDownload.length * 1024);

            if (toDownload.length > 0) {
                container.innerHTML = toDownload.map(f => `
                    <div class="rounded-lg border border-slate-800 bg-slate-800/50 p-3">
                        <div class="flex items-center justify-between">
                            <span class="truncate">${f}</span>
                            <span class="text-xs text-indigo-200">download</span>
                        </div>
                    </div>
                `).join('');
            } else {
                container.innerHTML = `
                    <div class="text-center py-8 text-slate-400">
                        <p>No files to pull</p>
                    </div>
                `;
            }
        }
    },

    /**
     * Execute Pull
     */
    async executePull() {
        const createBackup = document.getElementById('pull-backup').checked;

        // Show progress
        document.getElementById('pull-progress').classList.remove('hidden');
        this.updateProgress('pull', 0, 'Starting pull...');

        try {
            // Create backup if needed
            if (createBackup) {
                this.updateProgress('pull', 10, 'Creating local backup...');
                await this.api('POST', '/backups');
            }

            // Execute pull
            this.updateProgress('pull', 30, 'Downloading files...');

            const result = await this.api('POST', '/pull');

            if (result.success) {
                this.updateProgress('pull', 100, 'Complete!', 'success');
                this.toast('Pull Complete', `Successfully pulled ${result.data?.downloaded || 0} files`, 'success');
                await this.refreshStatus();
            } else {
                this.updateProgress('pull', 100, 'Failed', 'error');
                this.toast('Pull Failed', result.error || 'Unknown error', 'error');
            }
        } catch (error) {
            this.updateProgress('pull', 100, 'Error', 'error');
            this.toast('Error', error.message, 'error');
        }

        setTimeout(() => {
            document.getElementById('pull-progress').classList.add('hidden');
        }, 2000);
    },

    /**
     * Update Progress
     */
    updateProgress(type, percent, message, status = 'normal') {
        const bar = document.getElementById(`${type}-progress-bar`);
        const text = document.getElementById(`${type}-progress-text`);
        const file = document.getElementById(`${type}-progress-file`);

        bar.style.width = `${percent}%`;
        text.textContent = `${percent}%`;
        file.textContent = message;

        bar.classList.remove('progress-bar', 'progress-bar-success', 'progress-bar-error');
        if (status === 'success') {
            bar.classList.add('progress-bar-success');
        } else if (status === 'error') {
            bar.classList.add('progress-bar-error');
        } else {
            bar.classList.add('progress-bar');
        }
    },

    /**
     * Refresh Tree
     */
    async refreshTree() {
        const container = document.getElementById('file-tree');
        container.innerHTML = `
            <div class="text-center py-8 text-slate-400">
                <div class="spinner h-6 w-6 mx-auto border-2 border-slate-600 border-t-indigo-500 rounded-full"></div>
                <p class="mt-2">Loading...</p>
            </div>
        `;

        const result = await this.api('GET', `/files/tree?path=${encodeURIComponent(this.currentPath)}`);

        if (result.success && result.data) {
            this.renderTree(result.data);
            this.updateBreadcrumb();
        } else {
            container.innerHTML = `
                <div class="text-center py-8 text-rose-400">
                    <i data-lucide="alert-circle" class="h-8 w-8 mx-auto mb-2 opacity-50"></i>
                    <p>${result.error || 'Failed to load files'}</p>
                </div>
            `;
        }
        lucide.createIcons();
    },

    /**
     * Render Tree
     */
    renderTree(data) {
        const container = document.getElementById('file-tree');
        const items = data.items || data.tree || [];

        if (items.length === 0) {
            container.innerHTML = `
                <div class="text-center py-8 text-slate-400">
                    <i data-lucide="folder-open" class="h-8 w-8 mx-auto mb-2 opacity-50"></i>
                    <p>Empty directory</p>
                </div>
            `;
            lucide.createIcons();
            return;
        }

        // Sort: directories first, then files
        const sorted = items.sort((a, b) => {
            if (a.type === 'directory' && b.type !== 'directory') return -1;
            if (a.type !== 'directory' && b.type === 'directory') return 1;
            return a.name.localeCompare(b.name);
        });

        container.innerHTML = sorted.map(item => {
            const icon = item.type === 'directory' ? 'folder' : this.getFileIcon(item.name);
            const iconColor = item.type === 'directory' ? 'text-amber-200' : 'text-slate-400';

            return `
                <div class="file-item rounded-lg border border-slate-800 bg-slate-800/50 p-2 cursor-pointer hover:bg-slate-800 transition-colors"
                     onclick="App.selectFile('${item.name}', '${item.type}')"
                     ondblclick="App.openFile('${item.name}', '${item.type}')">
                    <div class="flex items-center gap-3">
                        <i data-lucide="${icon}" class="h-4 w-4 ${iconColor}"></i>
                        <span class="flex-1 truncate">${item.name}</span>
                        ${item.size ? `<span class="text-xs text-slate-500">${this.formatSize(item.size)}</span>` : ''}
                    </div>
                </div>
            `;
        }).join('');

        lucide.createIcons();
    },

    /**
     * Get File Icon
     */
    getFileIcon(filename) {
        const ext = filename.split('.').pop().toLowerCase();
        const icons = {
            php: 'file-code',
            js: 'file-code',
            ts: 'file-code',
            json: 'file-json',
            html: 'file-code',
            css: 'file-code',
            md: 'file-text',
            txt: 'file-text',
            png: 'image',
            jpg: 'image',
            jpeg: 'image',
            gif: 'image',
            svg: 'image',
            pdf: 'file-text',
            zip: 'archive',
            tar: 'archive',
            gz: 'archive'
        };
        return icons[ext] || 'file';
    },

    /**
     * Update Breadcrumb
     */
    updateBreadcrumb() {
        const container = document.getElementById('file-breadcrumb');
        const parts = this.currentPath.split('/').filter(p => p);

        let html = `<button onclick="App.navigateTo('/')" class="hover:text-white">/</button>`;

        let path = '';
        for (const part of parts) {
            path += '/' + part;
            html += `<span class="text-slate-600">/</span>`;
            html += `<button onclick="App.navigateTo('${path}')" class="hover:text-white">${part}</button>`;
        }

        container.innerHTML = html;
    },

    /**
     * Navigate to Path
     */
    navigateTo(path) {
        this.currentPath = path || '/';
        this.refreshTree();
    },

    /**
     * Navigate Up
     */
    navigateUp() {
        const parts = this.currentPath.split('/').filter(p => p);
        parts.pop();
        this.currentPath = '/' + parts.join('/');
        this.refreshTree();
    },

    /**
     * Select File
     */
    async selectFile(name, type) {
        const path = this.currentPath === '/' ? '/' + name : this.currentPath + '/' + name;

        // Show file details
        const container = document.getElementById('file-details');

        if (type === 'directory') {
            container.innerHTML = `
                <div class="space-y-3">
                    <div class="flex items-center gap-3 mb-4">
                        <i data-lucide="folder" class="h-8 w-8 text-amber-200"></i>
                        <div>
                            <p class="font-medium text-white">${name}</p>
                            <p class="text-xs text-slate-400">Directory</p>
                        </div>
                    </div>
                    <button onclick="App.openFile('${name}', 'directory')" class="w-full rounded-lg bg-indigo-500 px-3 py-2 text-sm text-white hover:bg-indigo-600">
                        Open Directory
                    </button>
                    <button onclick="App.deleteFile('${path}')" class="w-full rounded-lg border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-sm text-rose-200 hover:bg-rose-500/20">
                        Delete
                    </button>
                </div>
            `;
        } else {
            // Get file info
            const result = await this.api('GET', `/files/info?path=${encodeURIComponent(path)}`);
            const info = result.success ? result.data : {};

            container.innerHTML = `
                <div class="space-y-3">
                    <div class="flex items-center gap-3 mb-4">
                        <i data-lucide="${this.getFileIcon(name)}" class="h-8 w-8 text-slate-400"></i>
                        <div>
                            <p class="font-medium text-white truncate" title="${name}">${name}</p>
                            <p class="text-xs text-slate-400">${this.formatSize(info.size || 0)}</p>
                        </div>
                    </div>
                    <div class="text-xs space-y-2 text-slate-400">
                        <div class="flex justify-between">
                            <span>Modified</span>
                            <span class="text-slate-200">${info.modified || '-'}</span>
                        </div>
                        <div class="flex justify-between">
                            <span>Permissions</span>
                            <span class="text-slate-200">${info.permissions || '-'}</span>
                        </div>
                    </div>
                    <div class="pt-2 space-y-2">
                        <button onclick="App.viewFile('${path}')" class="w-full rounded-lg bg-indigo-500 px-3 py-2 text-sm text-white hover:bg-indigo-600">
                            View Content
                        </button>
                        <button onclick="App.downloadFile('${path}')" class="w-full rounded-lg border border-slate-700 bg-slate-800 px-3 py-2 text-sm text-slate-200 hover:bg-slate-700">
                            Download
                        </button>
                        <button onclick="App.deleteFile('${path}')" class="w-full rounded-lg border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-sm text-rose-200 hover:bg-rose-500/20">
                            Delete
                        </button>
                    </div>
                </div>
            `;
        }

        lucide.createIcons();
    },

    /**
     * Open File/Directory
     */
    openFile(name, type) {
        if (type === 'directory') {
            this.currentPath = this.currentPath === '/' ? '/' + name : this.currentPath + '/' + name;
            this.refreshTree();
        } else {
            const path = this.currentPath === '/' ? '/' + name : this.currentPath + '/' + name;
            this.viewFile(path);
        }
    },

    /**
     * View File Content
     */
    async viewFile(path) {
        const result = await this.api('GET', `/files/read?path=${encodeURIComponent(path)}`);

        if (result.success) {
            this.showModal(`
                <div class="p-6">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-lg font-semibold text-white">${path.split('/').pop()}</h3>
                        <button onclick="App.closeModal()" class="text-slate-400 hover:text-white">
                            <i data-lucide="x" class="h-5 w-5"></i>
                        </button>
                    </div>
                    <pre class="bg-slate-950 rounded-lg p-4 text-xs text-slate-300 overflow-auto max-h-96 font-mono">${this.escapeHtml(result.data?.content || '')}</pre>
                </div>
            `);
        } else {
            this.toast('Error', result.error || 'Failed to read file', 'error');
        }
    },

    /**
     * Download File
     */
    async downloadFile(path) {
        this.toast('Download', 'Preparing download...', 'info');
        // In a real implementation, this would trigger a file download
        const result = await this.api('GET', `/files/read?path=${encodeURIComponent(path)}`);
        if (result.success && result.data?.content) {
            const blob = new Blob([result.data.content], { type: 'text/plain' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = path.split('/').pop();
            a.click();
            URL.revokeObjectURL(url);
            this.toast('Downloaded', 'File downloaded successfully', 'success');
        } else {
            this.toast('Error', 'Failed to download file', 'error');
        }
    },

    /**
     * Delete File
     */
    async deleteFile(path) {
        if (!confirm(`Are you sure you want to delete "${path}"?`)) return;

        const result = await this.api('DELETE', `/files?path=${encodeURIComponent(path)}`);

        if (result.success) {
            this.toast('Deleted', 'File moved to trash', 'success');
            this.refreshTree();
        } else {
            this.toast('Error', result.error || 'Failed to delete file', 'error');
        }
    },

    /**
     * Show Create Folder Modal
     */
    showCreateFolder() {
        this.showModal(`
            <div class="p-6">
                <h3 class="text-lg font-semibold text-white mb-4">Create New Folder</h3>
                <input type="text" id="new-folder-name" class="w-full rounded-lg border border-slate-700 bg-slate-800 px-4 py-2.5 text-sm focus:border-indigo-500 focus:outline-none" placeholder="Folder name">
                <div class="flex justify-end gap-2 mt-4">
                    <button onclick="App.closeModal()" class="rounded-lg border border-slate-700 bg-slate-800 px-4 py-2 text-sm text-slate-200 hover:bg-slate-700">Cancel</button>
                    <button onclick="App.createFolder()" class="rounded-lg bg-indigo-500 px-4 py-2 text-sm text-white hover:bg-indigo-600">Create</button>
                </div>
            </div>
        `);
    },

    /**
     * Create Folder
     */
    async createFolder() {
        const name = document.getElementById('new-folder-name').value;
        if (!name) {
            this.toast('Error', 'Please enter a folder name', 'error');
            return;
        }

        const path = this.currentPath === '/' ? '/' + name : this.currentPath + '/' + name;
        const result = await this.api('POST', '/files/mkdir', { path });

        if (result.success) {
            this.toast('Created', 'Folder created successfully', 'success');
            this.closeModal();
            this.refreshTree();
        } else {
            this.toast('Error', result.error || 'Failed to create folder', 'error');
        }
    },

    /**
     * Show Create File Modal
     */
    showCreateFile() {
        this.showModal(`
            <div class="p-6">
                <h3 class="text-lg font-semibold text-white mb-4">Create New File</h3>
                <input type="text" id="new-file-name" class="w-full rounded-lg border border-slate-700 bg-slate-800 px-4 py-2.5 text-sm focus:border-indigo-500 focus:outline-none mb-3" placeholder="File name">
                <textarea id="new-file-content" class="w-full rounded-lg border border-slate-700 bg-slate-800 px-4 py-2.5 text-sm focus:border-indigo-500 focus:outline-none font-mono h-32" placeholder="File content (optional)"></textarea>
                <div class="flex justify-end gap-2 mt-4">
                    <button onclick="App.closeModal()" class="rounded-lg border border-slate-700 bg-slate-800 px-4 py-2 text-sm text-slate-200 hover:bg-slate-700">Cancel</button>
                    <button onclick="App.createFile()" class="rounded-lg bg-indigo-500 px-4 py-2 text-sm text-white hover:bg-indigo-600">Create</button>
                </div>
            </div>
        `);
    },

    /**
     * Create File
     */
    async createFile() {
        const name = document.getElementById('new-file-name').value;
        const content = document.getElementById('new-file-content').value;

        if (!name) {
            this.toast('Error', 'Please enter a file name', 'error');
            return;
        }

        const path = this.currentPath === '/' ? '/' + name : this.currentPath + '/' + name;

        let result;
        if (content) {
            result = await this.api('POST', '/files/write', { path, content });
        } else {
            result = await this.api('POST', '/files/touch', { path });
        }

        if (result.success) {
            this.toast('Created', 'File created successfully', 'success');
            this.closeModal();
            this.refreshTree();
        } else {
            this.toast('Error', result.error || 'Failed to create file', 'error');
        }
    },

    /**
     * Search Files
     */
    async searchFiles() {
        const query = document.getElementById('search-query').value;
        if (!query) {
            this.toast('Error', 'Please enter a search pattern', 'error');
            return;
        }

        const container = document.getElementById('search-results');
        container.classList.remove('hidden');
        container.innerHTML = `
            <div class="text-center py-4">
                <div class="spinner h-5 w-5 mx-auto border-2 border-slate-600 border-t-indigo-500 rounded-full"></div>
            </div>
        `;

        const result = await this.api('GET', `/files/search?pattern=${encodeURIComponent(query)}`);

        if (result.success && result.data?.files) {
            if (result.data.files.length > 0) {
                container.innerHTML = result.data.files.map(file => `
                    <div class="rounded-lg border border-slate-800 bg-slate-800/50 p-2">
                        <span class="text-sm truncate">${file}</span>
                    </div>
                `).join('');
            } else {
                container.innerHTML = `<p class="text-center text-slate-400 py-4">No files found</p>`;
            }
        } else {
            container.innerHTML = `<p class="text-center text-rose-400 py-4">${result.error || 'Search failed'}</p>`;
        }
    },

    /**
     * Load Plans
     */
    async loadPlans() {
        const container = document.getElementById('plans-list');
        container.innerHTML = `
            <div class="text-center py-8">
                <div class="spinner h-6 w-6 mx-auto border-2 border-slate-600 border-t-indigo-500 rounded-full"></div>
            </div>
        `;

        const result = await this.api('GET', '/plans');

        if (result.success && result.data?.operations && result.data.operations.length > 0) {
            container.innerHTML = result.data.operations.map((op, i) => `
                <div class="rounded-lg border border-slate-800 bg-slate-800/50 p-3">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <span class="h-6 w-6 rounded-full bg-indigo-500/20 flex items-center justify-center text-xs text-indigo-200">${i + 1}</span>
                            <div>
                                <p class="font-medium">${op.type}</p>
                                <p class="text-xs text-slate-400">${op.path || op.data?.path || '-'}</p>
                            </div>
                        </div>
                        <button onclick="App.removePlan(${i})" class="text-slate-400 hover:text-rose-400">
                            <i data-lucide="x" class="h-4 w-4"></i>
                        </button>
                    </div>
                </div>
            `).join('');
        } else {
            container.innerHTML = `
                <div class="text-center py-8 text-slate-400">
                    <i data-lucide="clipboard" class="h-8 w-8 mx-auto mb-2 opacity-50"></i>
                    <p>No queued operations</p>
                </div>
            `;
        }
        lucide.createIcons();
    },

    /**
     * Clear Plans
     */
    async clearPlans() {
        if (!confirm('Clear all queued operations?')) return;

        const result = await this.api('DELETE', '/plans');

        if (result.success) {
            this.toast('Cleared', 'All operations cleared', 'success');
            this.loadPlans();
            this.loadStats();
        } else {
            this.toast('Error', result.error || 'Failed to clear plans', 'error');
        }
    },

    /**
     * Apply Plans
     */
    async applyPlans() {
        if (!confirm('Execute all queued operations?')) return;

        this.showGlobalProgress('Executing operations...');

        const result = await this.api('POST', '/plans/apply');

        this.hideGlobalProgress();

        if (result.success) {
            this.toast('Complete', 'All operations executed successfully', 'success');
            this.loadPlans();
            this.loadStats();
        } else {
            this.toast('Error', result.error || 'Some operations failed', 'error');
        }
    },

    /**
     * Load Backups
     */
    async loadBackups() {
        const container = document.getElementById('backups-list');
        container.innerHTML = `
            <div class="text-center py-8">
                <div class="spinner h-6 w-6 mx-auto border-2 border-slate-600 border-t-indigo-500 rounded-full"></div>
            </div>
        `;

        const result = await this.api('GET', '/backups');

        if (result.success && result.data?.backups && result.data.backups.length > 0) {
            container.innerHTML = result.data.backups.map(backup => `
                <div class="rounded-lg border border-slate-800 bg-slate-800/50 p-4">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="font-medium text-white">${backup.version || backup.id}</p>
                            <p class="text-xs text-slate-400">${backup.created || backup.date} • ${backup.fileCount || 0} files</p>
                        </div>
                        <div class="flex gap-2">
                            <button onclick="App.restoreBackup('${backup.id}')" class="rounded-lg border border-slate-700 bg-slate-800 px-3 py-1.5 text-xs text-slate-200 hover:bg-slate-700">
                                Restore
                            </button>
                            <button onclick="App.deleteBackup('${backup.id}')" class="rounded-lg border border-rose-500/30 bg-rose-500/10 px-3 py-1.5 text-xs text-rose-200 hover:bg-rose-500/20">
                                Delete
                            </button>
                        </div>
                    </div>
                </div>
            `).join('');
        } else {
            container.innerHTML = `
                <div class="text-center py-8 text-slate-400">
                    <i data-lucide="archive" class="h-8 w-8 mx-auto mb-2 opacity-50"></i>
                    <p>No backups found</p>
                </div>
            `;
        }
        lucide.createIcons();
    },

    /**
     * Create Backup
     */
    async createBackup() {
        this.showGlobalProgress('Creating backup...');

        const result = await this.api('POST', '/backups');

        this.hideGlobalProgress();

        if (result.success) {
            this.toast('Backup Created', `Version ${result.data?.version || 'new'} created`, 'success');
            if (this.currentPage === 'backups') {
                this.loadBackups();
            }
            this.loadStats();
        } else {
            this.toast('Error', result.error || 'Failed to create backup', 'error');
        }
    },

    /**
     * Restore Backup
     */
    async restoreBackup(id) {
        if (!confirm(`Restore backup ${id}? This will overwrite current files.`)) return;

        this.showGlobalProgress('Restoring backup...');

        const result = await this.api('POST', `/backups/${id}/restore`);

        this.hideGlobalProgress();

        if (result.success) {
            this.toast('Restored', 'Backup restored successfully', 'success');
        } else {
            this.toast('Error', result.error || 'Failed to restore backup', 'error');
        }
    },

    /**
     * Delete Backup
     */
    async deleteBackup(id) {
        if (!confirm(`Delete backup ${id}?`)) return;

        const result = await this.api('DELETE', `/backups/${id}`);

        if (result.success) {
            this.toast('Deleted', 'Backup deleted', 'success');
            this.loadBackups();
            this.loadStats();
        } else {
            this.toast('Error', result.error || 'Failed to delete backup', 'error');
        }
    },

    /**
     * Load Trash
     */
    async loadTrash() {
        const container = document.getElementById('trash-list');
        container.innerHTML = `
            <div class="text-center py-8">
                <div class="spinner h-6 w-6 mx-auto border-2 border-slate-600 border-t-indigo-500 rounded-full"></div>
            </div>
        `;

        const result = await this.api('GET', '/trash');

        if (result.success && result.data?.items && result.data.items.length > 0) {
            container.innerHTML = result.data.items.map(item => `
                <div class="rounded-lg border border-slate-800 bg-slate-800/50 p-4">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="font-medium text-white">${item.name || item.path}</p>
                            <p class="text-xs text-slate-400">Deleted: ${item.deleted || item.date}</p>
                        </div>
                        <button onclick="App.restoreTrash('${item.id}')" class="rounded-lg border border-emerald-500/30 bg-emerald-500/10 px-3 py-1.5 text-xs text-emerald-200 hover:bg-emerald-500/20">
                            Restore
                        </button>
                    </div>
                </div>
            `).join('');
        } else {
            container.innerHTML = `
                <div class="text-center py-8 text-slate-400">
                    <i data-lucide="trash-2" class="h-8 w-8 mx-auto mb-2 opacity-50"></i>
                    <p>Trash is empty</p>
                </div>
            `;
        }
        lucide.createIcons();
    },

    /**
     * Restore from Trash
     */
    async restoreTrash(id) {
        const result = await this.api('POST', `/trash/${id}/restore`);

        if (result.success) {
            this.toast('Restored', 'Item restored from trash', 'success');
            this.loadTrash();
            this.loadStats();
        } else {
            this.toast('Error', result.error || 'Failed to restore item', 'error');
        }
    },

    /**
     * Empty Trash
     */
    async emptyTrash() {
        if (!confirm('Permanently delete all items in trash?')) return;

        const result = await this.api('DELETE', '/trash');

        if (result.success) {
            this.toast('Emptied', 'Trash has been emptied', 'success');
            this.loadTrash();
            this.loadStats();
        } else {
            this.toast('Error', result.error || 'Failed to empty trash', 'error');
        }
    },

    /**
     * Refresh Logs
     */
    async refreshLogs() {
        const container = document.getElementById('logs-content');
        const filter = document.getElementById('log-filter').value;

        container.innerHTML = `
            <div class="text-center py-8">
                <div class="spinner h-5 w-5 mx-auto border-2 border-slate-600 border-t-indigo-500 rounded-full"></div>
            </div>
        `;

        const result = await this.api('GET', `/logs?filter=${filter}`);

        if (result.success && result.data?.logs) {
            if (result.data.logs.length > 0) {
                container.innerHTML = result.data.logs.map(log => {
                    const color = log.level === 'error' ? 'text-rose-400' :
                                  log.level === 'warning' ? 'text-amber-400' : 'text-slate-400';
                    return `<div class="${color}">[${log.time || log.date}] ${log.message}</div>`;
                }).join('');
            } else {
                container.innerHTML = `<div class="text-center py-8 text-slate-400">No logs available</div>`;
            }
        } else {
            container.innerHTML = `<div class="text-center py-8 text-rose-400">${result.error || 'Failed to load logs'}</div>`;
        }
    },

    /**
     * Load Settings
     */
    async loadSettings() {
        const result = await this.api('GET', '/config');

        if (result.success && result.data) {
            document.getElementById('settings-url').value = result.data.serverUrl || '';
            document.getElementById('settings-token').value = result.data.token || '';
        }
    },

    /**
     * Toggle Token Visibility
     */
    toggleTokenVisibility() {
        const input = document.getElementById('settings-token');
        input.type = input.type === 'password' ? 'text' : 'password';
    },

    /**
     * Disconnect Project
     */
    async disconnectProject() {
        if (!confirm('Disconnect this project? You will need to reinitialize or login again.')) return;

        const result = await this.api('POST', '/config/disconnect');

        if (result.success) {
            this.toast('Disconnected', 'Project disconnected', 'success');
            this.isInitialized = false;
            this.showSetupPage();
        } else {
            this.toast('Error', result.error || 'Failed to disconnect', 'error');
        }
    },

    /**
     * Rotate Token
     */
    async rotateToken() {
        if (!confirm('Generate a new authentication token? You will need to update the server file.')) return;

        const result = await this.api('POST', '/config/rotate-token');

        if (result.success) {
            this.toast('Token Rotated', 'New token generated. Update your server file.', 'success');
            this.loadSettings();
        } else {
            this.toast('Error', result.error || 'Failed to rotate token', 'error');
        }
    },

    /**
     * Toggle Maintenance Mode
     */
    async toggleMaintenance() {
        const result = await this.api('POST', '/server/lock');

        if (result.success) {
            const mode = result.data?.locked ? 'enabled' : 'disabled';
            this.toast('Maintenance', `Maintenance mode ${mode}`, 'success');
        } else {
            this.toast('Error', result.error || 'Failed to toggle maintenance', 'error');
        }
    },

    /**
     * Quick Sync
     */
    async quickSync() {
        await this.refreshStatus();

        if (this.statusData && this.statusData.changes) {
            const changes = this.statusData.changes;
            const total = (changes.modified?.length || 0) + (changes.new?.length || 0) + (changes.deleted?.length || 0);

            if (total === 0) {
                this.toast('In Sync', 'Everything is already in sync!', 'success');
                return;
            }

            if (confirm(`Push ${total} changed files to server?`)) {
                await this.executePush();
            }
        }
    },

    /**
     * Refresh All
     */
    async refreshAll() {
        this.showGlobalProgress('Refreshing...');
        await this.loadDashboard();
        this.hideGlobalProgress();
        this.toast('Refreshed', 'Dashboard updated', 'success');
    },

    // ===================
    // UI Helpers
    // ===================

    /**
     * Show Toast Notification
     */
    toast(title, message, type = 'info') {
        const container = document.getElementById('toast-container');
        const toast = document.createElement('div');

        const styles = {
            info: 'border-slate-700 bg-slate-900/95',
            success: 'border-emerald-500/30 bg-emerald-500/10',
            error: 'border-rose-500/30 bg-rose-500/10',
            warning: 'border-amber-500/30 bg-amber-500/10'
        };

        const icons = {
            info: 'info',
            success: 'check-circle',
            error: 'alert-circle',
            warning: 'alert-triangle'
        };

        const iconColors = {
            info: 'text-slate-400',
            success: 'text-emerald-400',
            error: 'text-rose-400',
            warning: 'text-amber-400'
        };

        toast.className = `toast-enter w-80 rounded-xl border px-4 py-3 shadow-lg backdrop-blur-sm ${styles[type]}`;
        toast.innerHTML = `
            <div class="flex items-start gap-3">
                <i data-lucide="${icons[type]}" class="h-5 w-5 ${iconColors[type]} flex-shrink-0 mt-0.5"></i>
                <div class="flex-1 min-w-0">
                    <p class="text-sm font-semibold text-white">${title}</p>
                    <p class="text-xs text-slate-300 mt-0.5">${message}</p>
                </div>
                <button onclick="this.parentElement.parentElement.remove()" class="text-slate-400 hover:text-white">
                    <i data-lucide="x" class="h-4 w-4"></i>
                </button>
            </div>
        `;

        container.appendChild(toast);
        lucide.createIcons();

        setTimeout(() => {
            toast.classList.remove('toast-enter');
            toast.classList.add('toast-exit');
            setTimeout(() => toast.remove(), 300);
        }, 4000);
    },

    /**
     * Show Modal
     */
    showModal(content) {
        const container = document.getElementById('modal-container');
        const contentEl = document.getElementById('modal-content');
        contentEl.innerHTML = content;
        container.classList.remove('hidden');
        lucide.createIcons();
    },

    /**
     * Close Modal
     */
    closeModal() {
        document.getElementById('modal-container').classList.add('hidden');
    },

    /**
     * Show Global Progress
     */
    showGlobalProgress(text, detail = '') {
        document.getElementById('global-progress-text').textContent = text;
        document.getElementById('global-progress-detail').textContent = detail;
        document.getElementById('global-progress').classList.remove('hidden');
    },

    /**
     * Hide Global Progress
     */
    hideGlobalProgress() {
        document.getElementById('global-progress').classList.add('hidden');
    },

    /**
     * Format File Size
     */
    formatSize(bytes) {
        if (bytes === 0) return '0 B';
        const k = 1024;
        const sizes = ['B', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
    },

    /**
     * Escape HTML
     */
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
};

// Initialize on DOM ready
document.addEventListener('DOMContentLoaded', () => App.init());
