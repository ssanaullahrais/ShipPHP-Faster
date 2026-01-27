# ShipPHP Faster - The Easiest Way to Deploy Your PHP Website!

[![Latest Version](https://img.shields.io/packagist/v/shipphp/faster?style=flat-square)](https://packagist.org/packages/shipphp/faster)
[![Total Downloads](https://img.shields.io/packagist/dt/shipphp/faster?style=flat-square)](https://packagist.org/packages/shipphp/faster)
[![License](https://img.shields.io/packagist/l/shipphp/faster?style=flat-square)](https://github.com/ssanaullahrais/ShipPHP-Faster/blob/main/LICENSE)
[![PHP Version](https://img.shields.io/packagist/php-v/shipphp/faster?style=flat-square)](https://packagist.org/packages/shipphp/faster)

**The Easiest Way to Deploy Your PHP Website - Now with Global Installation & Profile Management!**

ShipPHP Faster is your all-in-one deployment toolkit for PHP websites designed to eliminate slow, risky manual uploads and messy FTP work forever. With a single command, you can push your latest changes to the server, pull updates down, check your deployment status, and instantly roll back or restore previous versions thanks to automatic version-tracked backups.

- **No technical knowledge required**
- **Global installation** (use from anywhere!)
- **Multi-project support** (manage unlimited websites)
- **Automatic version-tracked backups** (never lose your work!)
- **Lightning fast** (only uploads changed files)
- **Works everywhere** (Shared hosting, VPS, any server with PHP)
- **Beautiful CLI interface** (Claude Code-style status bar)

---

## 📦 Installation

### ⚡ Quick Install (Recommended)

**One command, done in seconds:**

```bash
composer global require shipphp/faster
```

That's it! ShipPHP is now available globally. Use it from any project:

```bash
cd /path/to/your/project
shipphp init
shipphp push
```

**Requirements:** PHP 7.4+ and Composer

---

### Alternative Installation Methods

<details>
<summary><b>Option 1: Manual Download (No Composer)</b></summary>

**Step 1: Download ShipPHP**

**Option A: Clone from GitHub**
```bash
git clone https://github.com/ssanaullahrais/ShipPHP-Faster.git shipphp
cd shipphp
```

**Option B: Download ZIP**
1. Go to: https://github.com/ssanaullahrais/ShipPHP-Faster
2. Click "Code" → "Download ZIP"
3. Extract the ZIP file

**Step 2: Install Globally (Automatic)**
```bash
php shipphp.php install --global
```

Now use `shipphp` command from anywhere!

</details>

<details>
<summary><b>Option 2: Per-Project Installation</b></summary>

### Local Installation (Per-Project)

Put the `shipphp` folder in each project:

```bash
# Your project structure
my-website/
├── shipphp/           ← Put ShipPHP folder here
├── index.php          ← Your website files
├── about.php
└── ...
```

**Create a shortcut command:**
```bash
cd /path/to/my-website
php shipphp/faster.php bootstrap ship

# Now use shorter commands:
php ship init
php ship push
php ship status
```

</details>

**💡 Tip:** For the best experience, use the Composer global installation method!

---

## 🎯 Quick Start (After Installation)

### Step 1: Initialize Your Project

```bash
# If using global installation:
shipphp init

# If using local installation:
php shipphp/faster.php init
# OR if you created bootstrap:
php ship init
```

**You'll be asked:**
- **Project name:** (e.g., "My Blog", "Client Website")
- **Domain:** Where your site runs (e.g., `myblog.com`)
- **Max file size:** Upload limit (default: 100MB)
- **IP whitelist:** Optional security (press Enter to skip)
- **Rate limit:** API throttling (default: 120 req/min)
- **Logging:** Enable request logs (recommended: Yes)

**What ShipPHP creates:**
- `shipphp.json` - Your local configuration
- `shipphp-server.php` - Upload this to your server
- `.shipphp/` - State tracking directory
- **Global profile** - Saved in `~/.shipphp/profiles.json`

### Step 2: Upload Server File

Upload `shipphp-server.php` to your website using FTP or cPanel:
```
https://yoursite.com/shipphp-server.php
```

### Step 3: Login & Deploy!

```bash
# Connect to your profile
shipphp login

# View changes
shipphp status

# Deploy!
shipphp push
```

---

## 🌟 What's New

### ✨ Features Overview

#### Smart Quick Start Dashboard

Just type `shipphp` to see a beautiful context-aware dashboard:

```bash
shipphp

╔════════════════════════════════════════════════════════════╗
║                                                            ║
║            🚀 ShipPHP Faster v1.0.0                        ║
║                                                            ║
╚════════════════════════════════════════════════════════════╝

STATUS
  Installation:  ✓ Global
  Current Dir:   ✓ Initialized

QUICK START
  Check changes:             shipphp status
  Deploy to server:          shipphp push
  Download from server:      shipphp pull
  Create backup:             shipphp backup create

COMMON COMMANDS
  shipphp status              Check what changed
  shipphp push                Deploy to server
  shipphp backup create       Create backup
  shipphp profile list        Manage profiles
  shipphp health              Check server health

NEED HELP?
  Full command list:         shipphp help
  Documentation:             https://github.com/ssanaullahrais/ShipPHP-Faster
```

#### Auto-Update Notifications

ShipPHP automatically checks for new releases and notifies you when updates are available.

```bash
shipphp --version
✓ ShipPHP Faster v1.0.0
```

#### Git-like Status Command

Clean, scannable output similar to `git status`:

```bash
shipphp status

╔════════════════════════════════════════════════════════════╗
║  ShipPHP Status                                           ║
╚════════════════════════════════════════════════════════════╝

On branch: production

Changes to push (local → server):
  M 3 modified
    M index.php
    M src/config.php
    M assets/style.css
  + 1 added
    + new-feature.php

Changes to pull (server → local):
  ✓ No changes

────────────────────────────────────────────────────────────
Summary:
  4 files to push
  0 files to pull

Next steps:
  Run 'shipphp push' to deploy local changes
```

Use `shipphp status --detailed` for full diagnostics!

---

### v2.0 - Global Installation & Profile Management

### Profile Management

Manage multiple websites easily:

```bash
# List all profiles
shipphp profile list

# Add new profile
shipphp profile add my-client-site

# Show profile details
shipphp profile show my-blog-prod

# Set default profile
shipphp profile use my-blog-prod

# Remove profile
shipphp profile remove old-project
```

### Login Command

Connect any project to your saved profiles:

```bash
shipphp login

# Shows profile table:
┌────┬──────────────────────┬─────────────────────┬──────────────────┐
│ ID │ Profile              │ Project Name        │ Domain           │
├────┼──────────────────────┼─────────────────────┼──────────────────┤
│ 1  │ myblog-com-a3f9      │ My Personal Blog    │ myblog.com       │
│ 2  │ client-com-x8k2      │ Client Website      │ client.com       │
└────┴──────────────────────┴─────────────────────┴──────────────────┘

Select profile (1-2): 1
✓ Connected to: myblog-com-a3f9
```

### Beautiful Status Bar

Every command shows your connection status (like Claude Code!):

```
╔════════════════════════════════════════════════════════════════════════════╗
║ 🚀 My Personal Blog  │  myblog.com  │  myblog-com-a3f9  │  ●abc...xyz  ║
╚════════════════════════════════════════════════════════════════════════════╝
```

### Token Security Management

```bash
# Show current authentication token
shipphp token show

# Rotate to new token (security best practice)
shipphp token rotate
```

### Server File Generation

Generate server files without initializing a project:

```bash
# Perfect for freelancers managing multiple clients
shipphp server generate client-staging

# Creates:
# - shipphp-server.php (ready to upload)
# - Global profile (client-staging)
```

---

## 📚 All Commands

### Setup & Configuration
```bash
shipphp init                    # Initialize project (creates profile automatically)
shipphp login                   # Connect project to a global profile
shipphp bootstrap [path]        # Create short command alias
shipphp env [name]              # Switch environments (staging/production)
```

### Deployment
```bash
shipphp                         # Smart dashboard (quick start guide)
shipphp status                  # Git-like status (clean output)
shipphp status --detailed       # Detailed status with diagnostics
shipphp push [path]             # Upload changed files to server
shipphp pull [path]             # Download changed files from server
shipphp sync                    # Status + Push (with confirmation)
```

### Backup Management (Version-Tracked)
```bash
shipphp backup create                    # Create versioned local backup (v1.0.0, v1.0.1, etc.)
shipphp backup create --server           # Create and upload to server
shipphp backup restore <id>              # Restore from local backup
shipphp backup restore <id> --server     # Download and restore from server
shipphp backup sync <id>                 # Upload specific backup to server
shipphp backup sync --all                # Upload all backups to server
shipphp backup pull <id>                 # Download specific backup from server
shipphp backup pull --all                # Download all backups from server
shipphp backup delete <id> --local       # Delete from local only
shipphp backup delete <id> --server      # Delete from server only
shipphp backup delete <id> --both        # Delete from both
shipphp backup stats                     # Show backup comparison table
shipphp backup                           # List all backups
```

### Profile Management
```bash
shipphp profile list                     # List all global profiles
shipphp profile add                      # Add new profile interactively
shipphp profile show <name>              # Show profile details
shipphp profile use <name>               # Set default profile
shipphp profile remove <name>            # Remove profile
shipphp server generate <name>           # Generate server file & create profile
```

### Security
```bash
shipphp token show                       # Show current authentication token
shipphp token rotate                     # Generate new token (requires server upload)
```

### Utilities
```bash
shipphp help                             # Full command list
shipphp --version                        # Check version (with update notifications)
shipphp health                           # Check server health and diagnostics
shipphp health --detailed                # Detailed health report
shipphp diff [file]                      # Show differences for specific file
```

---

## 🎬 Real-World Workflows

### Freelancer with Multiple Clients

```bash
# Install globally (see installation section above)
# After global installation is complete:

# Client 1 - Setup
shipphp server generate client1-prod
# Upload shipphp-server.php to client1.com

# Client 2 - Setup
shipphp server generate client2-staging
# Upload shipphp-server.php to staging.client2.com

# View all profiles
shipphp profile list

# Deploy to Client 1
cd /var/www/client1
shipphp login    # Select client1-prod
shipphp push

# Deploy to Client 2
cd /var/www/client2
shipphp login    # Select client2-staging
shipphp push

# Done! Easy switching between clients!
```

### Team with Shared Server

```bash
# Developer A (setup)
shipphp init    # Creates profile: company-prod
# Upload shipphp-server.php
# Share token with team (via 1Password, etc.)

# Developer B (join)
shipphp profile add company-prod
# Enter: URL and token from Developer A

# Both can deploy
cd /var/www/company-site
shipphp login    # Select company-prod
shipphp push
```

### Personal Projects

```bash
# Project 1
cd /var/www/myblog
shipphp init     # Auto-creates profile
shipphp login
shipphp push

# Project 2
cd /var/www/portfolio
shipphp init     # Auto-creates another profile
shipphp login
shipphp push

# Profiles saved globally - easy to switch!
```

---

## 🔒 Security Features

### Token-Based Authentication
- **64-character tokens** (256-bit security)
- **Timing-safe comparison** (prevents timing attacks)
- **Token rotation** (regenerate anytime)
- **Secure storage** (`chmod 600` on profile files)

### Path Protection
- **Path traversal prevention**
- **Cannot access files outside project directory**
- **Validated with `realpath()`**

### Network Security
- **Optional IP whitelisting** (CIDR support)
- **Rate limiting** (default: 120 req/min)
- **Request logging** (audit trail)

### File Security
- **SHA256 hashing** (integrity verification)
- **File size limits** (configurable)
- **Proper permissions** (files: 0644, directories: 0755)

---

## 💾 Automatic Backup System

### Version-Tracked Backups

Every backup gets an automatic semantic version:

```bash
shipphp backup create    # Creates: 2026-01-27-143022-v1.0.0
shipphp backup create    # Creates: 2026-01-27-143155-v1.0.1
shipphp backup create    # Creates: 2026-01-27-143301-v1.0.2
```

### Backup Features
- **Automatic versioning** (v1.0.0, v1.0.1, v1.0.2...)
- **Version history tracking** (`.versions.json`)
- **Local & server sync** (upload/download backups)
- **Respects .gitignore** (only backs up relevant files)
- **Manifest system** (JSON metadata with file hashes)
- **Easy restore** (one command to rollback)

### Backup Commands
```bash
shipphp backup                          # List all backups with versions
shipphp backup create                   # Create local v1.0.x backup
shipphp backup create --server          # Create and upload to server
shipphp backup restore <id>             # Restore specific version
shipphp backup sync --all               # Upload all to server
shipphp backup stats                    # Compare local vs server backups
```

---

## ⚙️ Configuration

### shipphp.json (Local Project Config)
```json
{
  "version": "1.0.0",
  "projectName": "My Blog",
  "profileId": "myblog-com-a3f9",
  "serverUrl": "https://myblog.com/shipphp-server.php",
  "token": "64-character-token-here",
  "backup": {
    "enabled": true,
    "beforePush": true,
    "keepLast": 10
  },
  "ignore": [
    ".git",
    "node_modules",
    "*.log"
  ]
}
```

### ~/.shipphp/profiles.json (Global Profiles)
```json
{
  "profiles": {
    "myblog-com-a3f9": {
      "projectName": "My Blog",
      "domain": "myblog.com",
      "serverUrl": "https://myblog.com/shipphp-server.php",
      "token": "64-character-token-here",
      "created": "2026-01-27 10:30:00"
    }
  },
  "default": "myblog-com-a3f9"
}
```

---

## 🐛 Troubleshooting

### "Connection failed"
1. Upload `shipphp-server.php` to your website
2. Check URL: `https://yoursite.com/shipphp-server.php`
3. Verify token matches in both files

### "Profile not found"
```bash
shipphp profile list    # See all profiles
shipphp init            # Create new profile
```

### "Not initialized"
```bash
shipphp init            # Initialize current directory
# OR
shipphp login           # Link to existing profile
```

### "Token mismatch"
```bash
shipphp token show      # Check current token
shipphp token rotate    # Generate new token
# Re-upload shipphp-server.php!
```

---

## 📖 Documentation

- **GitHub:** https://github.com/ssanaullahrais/ShipPHP-Faster
- **Issues:** Report bugs or request features
- **Wiki:** Detailed guides and tutorials

---

## 🎉 Why ShipPHP Faster?

### vs FTP
- ✅ **Automatic change detection** (only uploads what changed)
- ✅ **Version-tracked backups** (instant rollback)
- ✅ **No accidental overwrites** (safe deployments)
- ✅ **Beautiful CLI** (professional interface)

### vs Git Deploy
- ✅ **Works on shared hosting** (no SSH required)
- ✅ **No server setup** (just upload one PHP file)
- ✅ **Automatic backups** (built-in safety)
- ✅ **Beginner-friendly** (no Git knowledge needed)

### vs Other Tools
- ✅ **Global installation** (manage unlimited projects)
- ✅ **Profile system** (easy multi-project switching)
- ✅ **Token security** (enterprise-grade protection)
- ✅ **Version tracking** (semantic backup versioning)

---

## 💡 Tips & Best Practices

1. **Always create backups** before major changes:
   ```bash
   shipphp backup create --server
   shipphp push
   ```

2. **Use profiles** for multi-project management:
   ```bash
   shipphp profile list    # See all projects
   shipphp login           # Switch projects
   ```

3. **Rotate tokens regularly** (security best practice):
   ```bash
   shipphp token rotate
   # Upload new shipphp-server.php!
   ```

4. **Test with dry-run** before deploying:
   ```bash
   shipphp push --dry-run
   ```

5. **Check status first**:
   ```bash
   shipphp status          # Clean git-like status
   shipphp push            # Deploy changes
   ```

6. **Stay updated**:
   ```bash
   shipphp --version       # Check for updates
   composer global update shipphp/faster
   ```

---

## 🚀 Get Started Now!

```bash
# 1. Download ShipPHP
git clone https://github.com/ssanaullahrais/ShipPHP-Faster.git shipphp

# 2. Put in your project OR install globally (see Installation section)
cd /path/to/your/website

# 3. Initialize
php shipphp/faster.php init

# 4. Upload shipphp-server.php to your server

# 5. Deploy!
php shipphp/faster.php login
php shipphp/faster.php push
```

**Welcome to professional PHP deployment!** 🎉

---

## 📄 License

MIT License - Free to use for personal and commercial projects!

## 🤝 Contributing

Contributions welcome! Please open an issue or pull request on GitHub.

## ⭐ Support

If ShipPHP Faster helps you, please star the repository on GitHub!
