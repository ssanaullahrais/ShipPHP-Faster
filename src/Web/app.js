const app = {
    state: {
        initialized: false,
        profiles: [],
        currentProfile: null
    },

    async init() {
        try {
            const config = await this.api('/api/config');

            if (config.data.initialized) {
                this.state.initialized = true;
                await this.loadDashboard();
            } else {
                await this.loadProfiles();
                this.showProfileSelection();
            }
        } catch (error) {
            this.showToast('Failed to load ShipPHP: ' + error.message, 'error');
        } finally {
            document.getElementById('loading').classList.add('hidden');
        }
    },

    async loadProfiles() {
        try {
            const response = await this.api('/api/profiles');
            const profiles = Object.entries(response.data.profiles || {}).map(([id, profile]) => ({
                id,
                ...profile
            }));
            this.state.profiles = profiles;

            const profilesList = document.getElementById('profiles-list');
            const noProfiles = document.getElementById('no-profiles');

            if (profiles.length === 0) {
                profilesList.classList.add('hidden');
                noProfiles.classList.remove('hidden');
            } else {
                profilesList.innerHTML = profiles.map(profile => {
                    const projectName = this.escapeHtml(profile.projectName || profile.id);
                    const domain = this.escapeHtml(profile.domain || profile.serverUrl || 'No domain');
                    return `
                        <button onclick="app.connectProfile('${profile.id}')"
                                class="w-full bg-slate-950 border border-slate-700 rounded-lg p-4 text-left hover:border-blue-500 transition group">
                            <div class="flex items-center justify-between">
                                <div>
                                    <h4 class="font-semibold text-white group-hover:text-blue-400 transition">${projectName}</h4>
                                    <p class="text-sm text-slate-400 mt-1">${domain}</p>
                                </div>
                                <svg class="w-5 h-5 text-slate-600 group-hover:text-blue-400 transition" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                                </svg>
                            </div>
                        </button>
                    `;
                }).join('');
            }
        } catch (error) {
            this.showToast('Failed to load profiles: ' + error.message, 'error');
        }
    },

    async connectProfile(profileId) {
        try {
            this.showToast('Connecting to profile...', 'info');
            await this.api('/api/config/login', { method: 'POST', body: { profile: profileId } });
            this.showToast('Connected successfully!', 'success');
            setTimeout(() => location.reload(), 500);
        } catch (error) {
            this.showToast('Failed to connect: ' + error.message, 'error');
        }
    },

    async loadDashboard() {
        try {
            const serverInfo = await this.api('/api/server/info');
            const config = await this.api('/api/config');

            document.getElementById('profile-name').textContent = config.data.profileName || 'Connected Project';

            const infoContainer = document.getElementById('server-info');
            infoContainer.innerHTML = `
                <div>
                    <p class="text-xs text-slate-500 mb-1">PHP Version</p>
                    <p class="text-sm font-medium text-white">${this.escapeHtml(serverInfo.data.phpVersion || 'Unknown')}</p>
                </div>
                <div>
                    <p class="text-xs text-slate-500 mb-1">Server</p>
                    <p class="text-sm font-medium text-white">${this.escapeHtml(serverInfo.data.server || 'Unknown')}</p>
                </div>
                <div>
                    <p class="text-xs text-slate-500 mb-1">Base Path</p>
                    <p class="text-sm font-medium text-white truncate">${this.escapeHtml(serverInfo.data.basePath || '-')}</p>
                </div>
                <div>
                    <p class="text-xs text-slate-500 mb-1">Version</p>
                    <p class="text-sm font-medium text-white">${this.escapeHtml(serverInfo.data.version || 'Unknown')}</p>
                </div>
            `;

            this.showDashboard();
        } catch (error) {
            this.showToast('Failed to load dashboard: ' + error.message, 'error');
        }
    },

    async showStatus() {
        try {
            this.showToast('Loading status...', 'info');
            const status = await this.api('/api/status');

            const panel = document.getElementById('status-panel');
            const content = document.getElementById('status-content');

            const changes = status.data.changes || {};
            const summary = status.data.summary || {};

            let html = `
                <div class="grid grid-cols-3 gap-4 mb-6">
                    <div class="bg-slate-950 rounded-lg p-4">
                        <p class="text-2xl font-bold text-yellow-400">${summary.modified || 0}</p>
                        <p class="text-sm text-slate-400">Modified</p>
                    </div>
                    <div class="bg-slate-950 rounded-lg p-4">
                        <p class="text-2xl font-bold text-green-400">${summary.new || 0}</p>
                        <p class="text-sm text-slate-400">New</p>
                    </div>
                    <div class="bg-slate-950 rounded-lg p-4">
                        <p class="text-2xl font-bold text-red-400">${summary.deleted || 0}</p>
                        <p class="text-sm text-slate-400">Deleted</p>
                    </div>
                </div>
            `;

            if (summary.total > 0) {
                html += '<div class="space-y-4">';

                if (changes.modified && changes.modified.length > 0) {
                    html += '<div><h4 class="text-sm font-semibold text-yellow-400 mb-2">Modified Files</h4><div class="space-y-1">';
                    changes.modified.slice(0, 10).forEach(file => {
                        html += `<div class="text-sm text-slate-300 font-mono">${this.escapeHtml(file)}</div>`;
                    });
                    if (changes.modified.length > 10) {
                        html += `<p class="text-xs text-slate-500 mt-2">... and ${changes.modified.length - 10} more</p>`;
                    }
                    html += '</div></div>';
                }

                if (changes.new && changes.new.length > 0) {
                    html += '<div><h4 class="text-sm font-semibold text-green-400 mb-2">New Files</h4><div class="space-y-1">';
                    changes.new.slice(0, 10).forEach(file => {
                        html += `<div class="text-sm text-slate-300 font-mono">${this.escapeHtml(file)}</div>`;
                    });
                    if (changes.new.length > 10) {
                        html += `<p class="text-xs text-slate-500 mt-2">... and ${changes.new.length - 10} more</p>`;
                    }
                    html += '</div></div>';
                }

                html += '</div>';
            } else {
                html += '<p class="text-slate-400 text-center py-4">No changes detected</p>';
            }

            content.innerHTML = html;
            panel.classList.remove('hidden');
            this.showToast('Status loaded', 'success');
        } catch (error) {
            this.showToast('Failed to load status: ' + error.message, 'error');
        }
    },

    async push() {
        if (!confirm('Push all changes to server?')) return;

        try {
            this.showToast('Pushing changes...', 'info');
            const result = await this.api('/api/push', { method: 'POST' });

            if (result.data.uploaded > 0) {
                this.showToast(`Pushed ${result.data.uploaded} files successfully`, 'success');
                await this.showStatus();
            } else {
                this.showToast('No files to push', 'info');
            }
        } catch (error) {
            this.showToast('Push failed: ' + error.message, 'error');
        }
    },

    async pull() {
        if (!confirm('Pull all changes from server? This will overwrite local files.')) return;

        try {
            this.showToast('Pulling changes...', 'info');
            const result = await this.api('/api/pull', { method: 'POST' });

            if (result.data.downloaded > 0) {
                this.showToast(`Pulled ${result.data.downloaded} files successfully`, 'success');
                await this.showStatus();
            } else {
                this.showToast('No files to pull', 'info');
            }
        } catch (error) {
            this.showToast('Pull failed: ' + error.message, 'error');
        }
    },

    async disconnect() {
        if (!confirm('Disconnect from this profile? You can reconnect anytime.')) return;

        try {
            await this.api('/api/config/disconnect', { method: 'POST' });
            this.showToast('Disconnected successfully', 'success');
            setTimeout(() => location.reload(), 500);
        } catch (error) {
            this.showToast('Failed to disconnect: ' + error.message, 'error');
        }
    },

    showProfileSelection() {
        document.getElementById('profile-selection').classList.remove('hidden');
    },

    showDashboard() {
        document.getElementById('dashboard').classList.remove('hidden');
    },

    async api(endpoint, options = {}) {
        const response = await fetch(endpoint, {
            method: options.method || 'GET',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            body: options.body ? JSON.stringify(options.body) : undefined
        });

        const data = await response.json();

        if (!data.success) {
            throw new Error(data.error || 'API request failed');
        }

        return data;
    },

    showToast(message, type = 'info') {
        const colors = {
            success: 'bg-green-500',
            error: 'bg-red-500',
            info: 'bg-blue-500',
            warning: 'bg-yellow-500'
        };

        const toast = document.createElement('div');
        toast.className = `${colors[type]} text-white px-6 py-3 rounded-lg shadow-lg transform transition-all duration-300 translate-x-0`;
        toast.textContent = message;

        const container = document.getElementById('toast-container');
        container.appendChild(toast);

        setTimeout(() => {
            toast.style.transform = 'translateX(400px)';
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    },

    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
};

document.addEventListener('DOMContentLoaded', () => app.init());
