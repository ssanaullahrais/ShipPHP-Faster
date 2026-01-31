# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**ShipPHP Faster** (v2.1.1) is a professional, git-like PHP deployment tool that provides secure push/pull functionality for syncing files between a local development environment and a web server. It features automatic change detection, comprehensive backup/restore functionality, global profile management, operation planning, and enterprise-grade security.

### Key Components

- **shipphp.php** - Main CLI application entry point
- **version.php** - Single source of truth for version number
- **shipphp-server.php** - Server-side receiver script (generated during init)
- **src/** - Modular architecture with Commands, Core, Helpers, and Security
- **shipphp.json** - Project configuration (generated during init)
- **~/.shipphp/profiles.json** - Global profile storage

## Development Commands

All commands run from the repository root using PHP CLI:

```bash
# Setup & Configuration
shipphp install --global              # Install globally (use from anywhere)
shipphp init                          # Initialize project in current directory
shipphp login                         # Connect project to a global profile
shipphp bootstrap ./ship              # Create bootstrap file for shorter commands
shipphp env [name]                    # Switch between environments

# Deployment
shipphp status                        # Show changes since last sync
shipphp push [path]                   # Upload changed files to server
shipphp pull [path]                   # Download changed files from server
shipphp sync                          # Status + Push (with confirmation)
shipphp push local.php --to=remote.php    # Push to specific server path
shipphp pull remote.php --to=local.php    # Pull to specific local path

# Backup Management (Version Tracked)
shipphp backup                        # List all local backups
shipphp backup create                 # Create local backup (auto-versioned)
shipphp backup create --server        # Create and upload to server
shipphp backup restore <id>           # Restore from local backup
shipphp backup restore <id> --server  # Download and restore from server
shipphp backup restore-server <id>    # Restore server files from server backup
shipphp backup sync <id>              # Upload specific backup to server
shipphp backup sync --all             # Upload all local backups to server
shipphp backup pull <id>              # Download specific backup from server
shipphp backup pull --all             # Download all backups from server
shipphp backup delete <id> --local    # Delete from local only
shipphp backup delete <id> --server   # Delete from server only
shipphp backup delete <id> --both     # Delete from both
shipphp backup delete --all           # Delete all backups (with confirmation)
shipphp backup stats                  # Show backup comparison table

# Profile Management
shipphp profile list                  # List all global profiles
shipphp profile add                   # Add new profile interactively
shipphp profile show <name>           # Show profile details
shipphp profile use <name>            # Set default profile
shipphp profile remove <name>         # Remove profile
shipphp server generate <name>        # Generate server file & create profile

# Security
shipphp token show                    # Show current authentication token
shipphp token rotate                  # Generate new token (requires server upload)

# Server Utilities
shipphp health                        # Check server health
shipphp health --detailed             # Detailed health diagnostics
shipphp tree [path]                   # Show server file tree
shipphp where                         # Show server base directory
shipphp delete <path>                 # Delete/trash files on server
shipphp delete <path> --pattern=*.log # Pattern-based deletion
shipphp delete <path> --permanent     # Permanently delete (no trash)
shipphp trash                         # List trashed items
shipphp trash restore <id>            # Restore from trash
shipphp move <path> --to=<dest>       # Move files on server
shipphp move <path> --to=<dest> --copy  # Copy files on server
shipphp rename <path> --find=X --replace=Y  # Batch rename files
shipphp lock on --message="Maintenance"    # Enable maintenance mode
shipphp lock off                      # Disable maintenance mode
shipphp extract <zip>                 # Extract zip archive on server

# Operation Planning
shipphp delete <path> --plan          # Queue operation instead of executing
shipphp plan                          # View queued operations
shipphp plan clear                    # Clear queued operations
shipphp apply                         # Execute all queued operations

# Utilities
shipphp diff [file]                   # Show hash differences
```

## Architecture & Code Structure

### Modern Modular Architecture

```
shipphp/
├── shipphp.php              # CLI entry point with autoloader
├── version.php              # Single source of version number
├── templates/               # Template files
│   └── shipphp-server.template.php  # Server-side receiver template
├── src/
│   ├── Commands/            # Command classes (25 commands)
│   │   ├── ApplyCommand.php       # Execute queued operations
│   │   ├── BackupCommand.php      # Version-tracked backup system
│   │   ├── BaseCommand.php        # Shared command logic
│   │   ├── BootstrapCommand.php   # Create bootstrap files
│   │   ├── DeleteCommand.php      # Delete/trash server files
│   │   ├── DiffCommand.php        # Show file differences
│   │   ├── EnvCommand.php         # Environment switching
│   │   ├── ExtractCommand.php     # Extract zip archives
│   │   ├── HealthCommand.php      # Server health checks
│   │   ├── InitCommand.php        # Interactive initialization
│   │   ├── InstallCommand.php     # Global installation
│   │   ├── LockCommand.php        # Maintenance mode toggle
│   │   ├── LoginCommand.php       # Connect to global profiles
│   │   ├── MoveCommand.php        # Move/copy server files
│   │   ├── PlanCommand.php        # View/clear queued operations
│   │   ├── ProfileCommand.php     # Profile management
│   │   ├── PullCommand.php        # Download from server
│   │   ├── PushCommand.php        # Deploy to server
│   │   ├── RenameCommand.php      # Batch rename files
│   │   ├── ServerCommand.php      # Server file generation
│   │   ├── StatusCommand.php      # Show changes
│   │   ├── SyncCommand.php        # Status + Push
│   │   ├── TokenCommand.php       # Token management
│   │   ├── TrashCommand.php       # Trash management
│   │   ├── TreeCommand.php        # Server file tree
│   │   └── WhereCommand.php       # Show server base directory
│   ├── Core/                # Core functionality
│   │   ├── ApiClient.php          # Server communication
│   │   ├── Application.php        # Command router
│   │   ├── Backup.php             # Backup management
│   │   ├── Config.php             # Configuration manager
│   │   ├── PlanManager.php        # Operation queue management
│   │   ├── ProfileManager.php     # Global profile storage
│   │   ├── ProjectPaths.php       # Config/state path resolution
│   │   ├── State.php              # File state tracking
│   │   └── VersionChecker.php     # Update checking via GitHub API
│   ├── Helpers/
│   │   └── Output.php             # CLI output formatting
│   └── Security/
│       └── Security.php           # Token generation & validation
```

### Command Pattern

Each command extends `BaseCommand` and implements:
```php
public function execute($options)
{
    // Command logic with access to:
    // - $this->config (Config instance)
    // - $this->state (State instance)
    // - $this->backup (Backup instance)
    // - $this->output (Output instance)
    // - $this->plan (PlanManager instance)
}
```

### Client-Server Communication

Uses REST-like HTTP API with 64-character token authentication:

**Supported Actions:**
- `test` - Connection verification
- `info` - Server information
- `list` - Get server file list
- `upload` - Upload single file
- `download` - Download single file
- `delete` - Delete file/directory
- `trash` - Move to trash
- `trashList` - List trashed items
- `trashRestore` - Restore from trash
- `move` - Move files on server
- `rename` - Rename files on server
- `extract` - Extract zip archives
- `lock` - Toggle maintenance mode
- `tree` - Get file tree structure
- `where` - Get base directory
- `backup` - Create server backup
- `backups` - List server backups
- `restore` - Restore server backup
- `deleteBackup` - Remove server backup

**Security Features:**
- SHA256 file hashing for change detection
- Timing-safe token comparison
- Path traversal prevention
- IP whitelisting (optional)
- Rate limiting (configurable)
- Comprehensive request logging

## Configuration System

### Directory Structure Options

ShipPHP supports two configuration layouts:

**Isolated Config (Recommended):**
```
project-root/
├── shipphp-config/          # All ShipPHP files isolated
│   ├── shipphp.json
│   ├── shipphp-server.php
│   └── .shipphp/
│       ├── state.json
│       ├── profile.link     # Profile reference
│       └── plan.json        # Queued operations
└── (your project files)
```

**Legacy Root-Level Config:**
```
project-root/
├── shipphp.json
├── shipphp-server.php
├── .shipphp/
│   ├── state.json
│   ├── profile.link
│   └── plan.json
└── (your project files)
```

The `ProjectPaths` class automatically detects which layout is in use.

### shipphp.json Structure

Generated during `init`, stores all project configuration:

```json
{
  "version": "2.1.1",
  "serverUrl": "https://example.com/shipphp-server.php",
  "token": "64-character-hexadecimal-token",
  "deleteOnPush": false,
  "backup": {
    "enabled": true,
    "beforePush": true,
    "beforePull": false,
    "keepLast": 10,
    "autoClean": true
  },
  "ignore": [
    ".git", ".shipphp", "node_modules", "vendor", "*.log"
  ],
  "security": {
    "maxFileSize": 104857600,
    "rateLimit": 120,
    "enableLogging": true,
    "ipWhitelist": []
  },
  "environments": {
    "production": { /* env-specific config */ }
  },
  "currentEnv": "production"
}
```

### Global Profile System

Profiles are stored globally at `~/.shipphp/profiles.json`:

```json
{
  "profiles": {
    "myblog-com-a3f9": {
      "projectName": "My Blog",
      "domain": "myblog.com",
      "serverUrl": "https://myblog.com/shipphp-server.php",
      "token": "64-character-token",
      "created": "2026-01-27 14:30:22",
      "updated": "2026-01-27 14:30:22"
    }
  },
  "default": "myblog-com-a3f9",
  "version": "2.1.0"
}
```

**Profile Features:**
- Cross-platform home directory detection
- Secure file permissions (chmod 600)
- Unique ID generation from domain names
- Default profile support
- Profile linking via `.shipphp/profile.link`

### .gitignore Integration

**Automatic Generation:**
- `init` command creates .gitignore if it doesn't exist
- Includes ShipPHP files, dependencies, IDE files, OS files

**Automatic Loading:**
- Config class reads .gitignore on load
- Patterns merged with shipphp.json ignore list
- Both sources respected during file scanning

### File Exclusion System

Files are excluded from sync if:
1. Listed in `shipphp.json` ignore array
2. Matched in `.gitignore` patterns
3. Glob pattern matches (e.g., `*.log`, `node_modules/*`)

**Common exclusions:** `.git`, `.shipphp`, `shipphp.json`, `shipphp-server.php`, `.env`, `node_modules`, `vendor`, IDE folders, `*.log`

## Backup & Restore System

### Version-Tracked Backup System

**Manual Backup Creation:**
- Backups are created on-demand using `backup create` command
- Each backup gets an automatic semantic version (v2.0.0, v2.0.1, v2.0.2, etc.)
- Versions increment automatically with each new backup

**Backup Storage:**
- Local: `/backup/` directory in project root
- Each backup is in a timestamped + versioned directory
- Format: `YYYY-MM-DD-HHMMSS-vX.X.X` (e.g., `2026-01-27-143022-v2.0.0`)
- Includes `manifest.json` with metadata
- Version tracking stored in `/backup/.versions.json`

**Backup Manifest:**
```json
{
  "id": "2026-01-27-143022-v2.0.0",
  "version": "v2.0.0",
  "created": "2026-01-27T14:30:22+00:00",
  "fileCount": 42,
  "totalSize": 1048576,
  "files": {
    "index.php": {
      "hash": "sha256-hash-here",
      "size": 1024
    }
  }
}
```

## Operation Planning System

Queue operations for later execution using the `--plan` flag:

```bash
# Queue operations
shipphp delete logs --pattern=*.log --plan
shipphp move uploads/old --to=archive --plan
shipphp rename images --find=-thumb --replace=-small --plan

# View queued operations
shipphp plan

# Execute all queued operations
shipphp apply

# Clear queue without executing
shipphp plan clear
```

**Storage:** `.shipphp/plan.json` in state directory

**Supported Operations:** delete, move, rename

## Security Architecture

### Multi-Layer Security

**Token Authentication:**
- 64-character hexadecimal tokens (256-bit security)
- Timing-safe comparison prevents timing attacks
- Auto-generated during initialization
- Token rotation via `token rotate` command

**Path Security:**
- Strict path traversal prevention
- All paths validated with `realpath()`
- Cannot access files outside BASE_DIR

**Network Security:**
- Optional IP whitelisting with CIDR support
- Rate limiting (default: 120 req/min)
- Prevents brute-force attacks

**File Security:**
- SHA256 hashing for integrity verification
- File size limits (configurable)
- Uploaded files set to 0644, directories to 0755

**Request Security:**
- All requests logged for audit trail
- Failed authentication attempts logged
- Security headers (X-Frame-Options, X-XSS-Protection, etc.)

## Initial Setup Workflow

### Option 1: New Project Setup

```bash
cd /path/to/your/project
shipphp init                    # Interactive setup
# Upload shipphp-server.php to your server
shipphp status                  # Test connection
shipphp push                    # Deploy everything
```

### Option 2: Connect to Existing Profile

```bash
cd /path/to/your/project
shipphp login                   # Select from global profiles
shipphp status                  # Test connection
shipphp push                    # Deploy
```

### Global Installation

```bash
shipphp install --global        # Install globally
# Now use 'shipphp' command from anywhere
```

## Typical Development Workflow

### Daily Development

```bash
# Make changes to your files...
shipphp status                  # Check what changed
shipphp push                    # Deploy changes
```

### Profile Management

```bash
shipphp profile list            # View all profiles
shipphp profile add             # Add new profile
shipphp profile use mysite      # Switch default profile
shipphp profile show mysite     # View profile details
```

### Server File Operations

```bash
shipphp tree                    # View server structure
shipphp tree public/images      # View specific directory
shipphp delete cache --pattern=*.tmp  # Delete temp files
shipphp trash                   # View deleted files
shipphp trash restore abc123    # Recover deleted file
shipphp move old --to=archive   # Move directory
shipphp lock on --message="Updating..."  # Maintenance mode
```

### Multi-Environment Deployment

```bash
shipphp env staging             # Switch to staging
shipphp push                    # Deploy to staging
shipphp env production          # Switch to production
shipphp push                    # Deploy to production
```

## Code Style Conventions

- **Indentation**: 4 spaces (no tabs)
- **Constants**: UPPER_SNAKE_CASE (e.g., `SHIPPHP_TOKEN`, `SHIPPHP_VERSION`)
- **Classes**: PascalCase (e.g., `InitCommand`, `ApiClient`, `ProfileManager`)
- **Methods**: camelCase (e.g., `generateServerFile`, `getHomeDirectory`)
- **Namespaces**: `ShipPHP\Commands`, `ShipPHP\Core`
- **File names**: PascalCase for classes, lowercase for executables

## Error Handling Patterns

### Command-Level Errors

```php
try {
    // Operation logic
} catch (\Exception $e) {
    $this->output->error($e->getMessage());
    $this->output->writeln();
}
```

### User-Friendly Error Messages

The `Application.php` handles common errors with friendly guidance:
- "Project Not Initialized" - Suggests `init` or `login` commands
- "Server Configuration Incomplete" - Guides to reconfigure

### API Communication Errors

- HTTP 200: Success
- HTTP 401: Authentication failed (invalid token)
- HTTP 403: Access denied (IP whitelist, rate limit)
- HTTP 500: Server error

All JSON responses include:
```json
{
  "success": true/false,
  "message": "Human-readable message",
  "data": { /* response data */ },
  "error": "Error details (if failed)"
}
```

## Adding New Commands

1. Create command class in `src/Commands/`
2. Extend `BaseCommand`
3. Implement `execute($options)` method
4. Register in `src/Core/Application.php` commands array
5. Add to help text in `showHelp()` method

Example:
```php
<?php
namespace ShipPHP\Commands;

class MyCommand extends BaseCommand
{
    public function execute($options)
    {
        $this->initConfig();  // Load config if needed
        $this->header("My Command");

        // Command logic here

        // Access plan manager for queued operations
        if (isset($options['flags']['plan'])) {
            $this->plan->addOperation([
                'type' => 'myoperation',
                'data' => $someData
            ])->save();
            $this->output->success("Operation queued");
            return;
        }

        $this->output->success("Done!");
    }
}
```

## Important Implementation Notes

### Config.php Features

- **Automatic .gitignore loading**: Reads and merges .gitignore patterns on load
- **Validation**: Validates URLs, tokens, and required fields
- **Environment switching**: Supports multiple deployment targets
- **Deep array access**: Use dot notation for nested config values

### State.php Tracking

- **SHA256 hashing**: Detects file changes by content, not timestamp
- **Efficient scanning**: Only scans non-ignored files
- **Change detection**: Compares local vs server state accurately

### ProfileManager.php Features

- **Global storage**: `~/.shipphp/profiles.json`
- **Cross-platform**: Windows/Unix/Mac home directory detection
- **Secure permissions**: chmod 600 for profile file
- **Profile ID generation**: Domain-based unique IDs (e.g., `myblog-com-a3f9`)
- **Default profile**: First profile auto-set as default

### PlanManager.php Features

- **Operation queuing**: Store operations for batch execution
- **Persistent storage**: `.shipphp/plan.json`
- **Clear and apply**: Execute all queued ops or clear queue

### ProjectPaths.php Features

- **Auto-detection**: Detects isolated (`shipphp-config/`) vs legacy root config
- **Consistent paths**: All path resolution goes through this class
- **Methods**: `configDir()`, `configFile()`, `stateDir()`, `stateFile()`, `serverFile()`, `linkFile()`

### VersionChecker.php Features

- **GitHub API integration**: Checks latest release version
- **24-hour cache**: Prevents excessive API calls
- **Update notifications**: Shows update commands in dashboard
- **Installation type detection**: Global vs local installation

### Output.php Formatting

- **Color support**: Cross-platform color support (Windows/Unix)
- **Progress bars**: For long-running operations
- **Tables**: Formatted data display
- **Boxes**: Highlighted information
- **Interactive prompts**: Yes/no confirmations, text input

## Command-Line Options Reference

| Option | Description |
|--------|-------------|
| `--help, -h` | Show help information |
| `--version, -v` | Show version information |
| `--dry-run` | Preview changes without executing |
| `--force` | Skip confirmations (use with caution) |
| `--plan` | Queue operation instead of executing |
| `--pattern` | Filter by glob pattern |
| `--exclude` | Exclude files by glob pattern |
| `--select-all` | Select all items matching criteria |
| `--permanent` | Permanently delete instead of trash |
| `--message` | Custom message for maintenance lock |
| `--detailed, -d` | Show detailed output |
| `--yes, -y` | Auto-confirm all prompts |
| `--to` | Target path for push/pull/move operations |
| `--from` | Source path override |
| `--copy` | Copy instead of move |
| `--find` | Find pattern for batch rename |
| `--replace` | Replace pattern for batch rename |
| `--server` | Target server for backup operations |
| `--local` | Target local for backup operations |
| `--both` | Target both local and server |
| `--all` | Apply to all items |

## Version History

- **v2.1.1** - Centralized version reporting, profile persistence
- **v2.1.0** - Config isolation, direct path overrides, server utilities
- **v2.0.0** - Initial public release with core deployment features
