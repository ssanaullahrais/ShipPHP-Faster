# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**ShipPHP Faster** is a professional, git-like PHP deployment tool that provides secure push/pull functionality for syncing files between a local development environment and a web server. It features automatic change detection, comprehensive backup/restore functionality, and enterprise-grade security.

### Key Components

- **shipphp.php** - Main CLI application entry point
- **shipphp-server.php** - Server-side receiver script
- **src/** - Modular architecture with Commands, Core, Helpers, and Security
- **shipphp.json** - Project configuration (generated during init)
- **.gitignore** - Automatically generated ignore patterns

## Development Commands

All commands run from the repository root using PHP CLI:

```bash
# Setup & Initialization
php shipphp/shipphp.php init                 # Interactive initialization with full config
php shipphp/shipphp.php bootstrap ./ship     # Create bootstrap file for easier usage

# Deployment
php shipphp/shipphp.php status               # Check what changed
php shipphp/shipphp.php push                 # Deploy changes to server
php shipphp/shipphp.php pull                 # Download server changes
php shipphp/shipphp.php sync                 # Status + Push with confirmation

# Backup Management (Version Tracked)
php shipphp/shipphp.php backup               # List all backups
php shipphp/shipphp.php backup create        # Create local backup (auto-versioned)
php shipphp/shipphp.php backup create --server   # Create and upload to server
php shipphp/shipphp.php backup restore <id>      # Restore from local backup
php shipphp/shipphp.php backup restore <id> --server  # Download and restore from server
php shipphp/shipphp.php backup sync <id>         # Upload specific backup to server
php shipphp/shipphp.php backup sync --all        # Upload all local backups to server
php shipphp/shipphp.php backup pull <id>         # Download specific backup from server
php shipphp/shipphp.php backup pull --all        # Download all backups from server
php shipphp/shipphp.php backup delete <id> --local   # Delete from local only
php shipphp/shipphp.php backup delete <id> --server  # Delete from server only
php shipphp/shipphp.php backup delete <id> --both    # Delete from both
php shipphp/shipphp.php backup delete --all          # Delete all backups (with confirmation)
php shipphp/shipphp.php backup stats         # Show backup comparison table (local & server)

# Utilities
php shipphp/shipphp.php env [name]           # Switch environments
php shipphp/shipphp.php diff [file]          # Show hash differences
```

## Architecture & Code Structure

### Modern Modular Architecture

```
shipphp/
├── shipphp.php              # CLI entry point with autoloader
├── templates/               # Template files
│   └── shipphp-server.template.php  # Server-side receiver template
├── src/
│   ├── Commands/            # Command classes (one per command)
│   │   ├── InitCommand.php        # Interactive initialization
│   │   ├── StatusCommand.php      # Show changes
│   │   ├── PushCommand.php        # Deploy to server
│   │   ├── PullCommand.php        # Download from server
│   │   ├── BackupCommand.php      # Version-tracked backup system
│   │   └── BaseCommand.php        # Shared command logic
│   ├── Core/                # Core functionality
│   │   ├── Application.php        # Command router
│   │   ├── Config.php            # Configuration manager
│   │   ├── State.php             # File state tracking
│   │   ├── Backup.php            # Backup management
│   │   └── ApiClient.php         # Server communication
│   ├── Helpers/
│   │   └── Output.php            # CLI output formatting
│   └── Security/
│       └── Security.php          # Token generation & validation
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

### shipphp.json Structure

Generated during `init`, stores all project configuration:

```json
{
  "version": "2.0.0",
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

### .gitignore Integration

**Automatic Generation:**
- `init` command creates .gitignore if it doesn't exist
- Includes ShipPHP files, dependencies, IDE files, OS files

**Automatic Loading:**
- Config class reads .gitignore on load
- Patterns merged with shipphp.json ignore list
- Both sources respected during file scanning

**Benefits:**
- Standard Git ignore patterns work automatically
- Users can edit .gitignore as usual
- No need to duplicate patterns in shipphp.json

### Server Configuration (shipphp-server.php)

During `init`, you interactively configure:

**Max File Size:**
- Recommended: 100MB for most projects
- Range: 1MB - 2048MB
- Validation: Auto-corrects invalid values

**Server-Side Backups:**
- Creates backups before destructive operations on server
- Provides extra safety layer
- Configurable retention (default: 10 backups)

**IP Whitelist:**
- Optional: Restrict access to specific IPs
- Supports CIDR notation (e.g., 10.0.0.0/8)
- Can be left empty to allow all IPs

**Rate Limiting:**
- Prevents brute-force attacks
- Default: 120 requests/minute
- Range: 10 - 1000 requests/min

**Logging:**
- Logs all requests to .shipphp-server.log
- Helps troubleshooting and security auditing
- Recommended: Enabled

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
- No automatic backups - full control over when backups are created

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

### Backup Commands

**Create Local Backup:**
```bash
shipphp backup create
```
Creates a versioned backup of all project files (respecting .gitignore).

**Create and Upload to Server:**
```bash
shipphp backup create --server
```
Creates local backup and immediately uploads it to the server.

**List All Backups:**
```bash
shipphp backup
```
Shows all local backups with version numbers, file counts, and sizes.

**Restore from Local Backup:**
```bash
shipphp backup restore 2026-01-27-143022-v2.0.0
```
Restores all files from the specified backup (with confirmation).

**Download and Restore from Server:**
```bash
shipphp backup restore 2026-01-27-143022-v2.0.0 --server
```
Downloads backup from server first, then restores it locally.

**Sync Specific Backup to Server:**
```bash
shipphp backup sync 2026-01-27-143022-v2.0.0
```
Uploads a specific backup to the server.

**Sync All Backups to Server:**
```bash
shipphp backup sync --all
```
Uploads all local backups to the server for safekeeping.

### Version Tracking Features

- **Automatic versioning**: Starts at v2.0.0, increments patch version automatically
- **Version history**: `.versions.json` tracks all backup versions
- **Easy identification**: Version in directory name for quick reference
- **Independent local/server**: Can maintain different backups locally vs. server

### Safety Features

- **Confirmation prompts** for all restore operations
- **Detailed preview** showing what will be restored
- **File validation** ensures backup integrity
- **Error recovery** with detailed error messages
- **Respects .gitignore** - only backs up relevant files

## Security Architecture

### Multi-Layer Security

**Token Authentication:**
- 64-character hexadecimal tokens (256-bit security)
- Timing-safe comparison prevents timing attacks
- Auto-generated during initialization

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
- File type validation (optional)
- Uploaded files set to 0644, directories to 0755

**Request Security:**
- All requests logged for audit trail
- Failed authentication attempts logged
- Detailed error messages in logs only (not responses)
- Security headers (X-Frame-Options, X-XSS-Protection, etc.)

## Initial Setup Workflow

### Interactive Initialization

```bash
cd /path/to/your/project
php shipphp/shipphp.php init
```

The init command will interactively ask:

1. **Domain/URL** - Where your project runs (e.g., example.com)
2. **Max File Size** - Upload limit with recommendations
3. **IP Whitelist** - Optional IP restrictions
4. **Rate Limit** - API request throttling
5. **Logging** - Request logging for debugging

**What Gets Created:**
- `.gitignore` - Git ignore patterns (if doesn't exist)
- `shipphp.json` - Your configuration
- `shipphp-server.php` - Fully configured server file
- `.shipphp/` - State directory
- `.shipphp/state.json` - File tracking database
- `/backup/` - Created when you run your first backup

**Next Steps:**
1. Upload `shipphp-server.php` to your server
2. Run `shipphp status` to test connection
3. Run `shipphp push` to deploy

### Security Best Practices

✅ **DO:**
- Use the auto-generated 64-character token
- Add `shipphp.json` to `.gitignore` if token is sensitive
- Review logs regularly (`.shipphp-server.log`)
- Create regular backups using `backup create`
- Use IP whitelist for sensitive deployments
- Test with `--dry-run` first

❌ **DON'T:**
- Share tokens publicly or commit to repos
- Forget to create backups before major changes
- Use `--force` without understanding consequences
- Skip confirmations on destructive operations
- Ignore security warnings

## Typical Development Workflow

### First Deployment

```bash
1. cd /path/to/project
2. php shipphp/shipphp.php init          # Interactive setup
3. # Upload shipphp-server.php to server
4. php shipphp/shipphp.php status        # Test connection
5. php shipphp/shipphp.php push          # Deploy everything
```

### Daily Development

```bash
# Make changes to your files...

php shipphp/shipphp.php status           # Check what changed
php shipphp/shipphp.php push             # Deploy changes
```

ShipPHP automatically tracks ALL file changes using SHA256 hashes - no manual staging required!

### Backup Management

```bash
php shipphp/shipphp.php backup create    # Create versioned backup
php shipphp/shipphp.php backup           # List all backups
php shipphp/shipphp.php backup restore <id>  # Restore from backup
php shipphp/shipphp.php backup pull --all    # Download all backups from server
php shipphp/shipphp.php backup delete <id> --both  # Delete backup from both
php shipphp/shipphp.php backup stats     # Compare local vs server backups
```

### Advanced Workflows

**Multi-Environment Deployment:**
```bash
php shipphp/shipphp.php env staging      # Switch to staging
php shipphp/shipphp.php push             # Deploy to staging
php shipphp/shipphp.php env production   # Switch to production
php shipphp/shipphp.php push             # Deploy to production
```

**Backup with Server Sync:**
```bash
php shipphp/shipphp.php backup create --server  # Create and upload to server
php shipphp/shipphp.php backup sync --all       # Sync all backups to server
php shipphp/shipphp.php backup pull --all       # Download all backups from server
php shipphp/shipphp.php backup restore <id> --server  # Restore from server
php shipphp/shipphp.php backup delete <id> --both     # Delete from both locations
php shipphp/shipphp.php backup stats            # View comparison table
```

## Code Style Conventions

- **Indentation**: 4 spaces (no tabs)
- **Constants**: UPPER_SNAKE_CASE (e.g., `SHIPPHP_TOKEN`)
- **Classes**: PascalCase (e.g., `InitCommand`, `ApiClient`)
- **Methods**: camelCase (e.g., `generateServerFile`)
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

### User-Friendly Output

- Success: Green checkmark (✓) with success message
- Error: Red X (✗) with error details
- Warning: Yellow triangle (⚠) with warning
- Info: Blue (ℹ) with information
- Progress bars for file operations

## Adding New Commands

1. Create command class in `src/Commands/`
2. Extend `BaseCommand`
3. Implement `execute($options)` method
4. Register in `src/Core/Application.php`
5. Add to help text

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

### Backup.php Management

- **Version tracking**: Automatic semantic versioning (v2.0.0, v2.0.1, etc.)
- **Local backups**: Stored in `/backup/` directory
- **Manifest system**: JSON metadata for each backup with version info
- **Server sync**: Upload/download backups to/from server
- **Respects .gitignore**: Only backs up project files, not dependencies

### Output.php Formatting

- **Color support**: Cross-platform color support (Windows/Unix)
- **Progress bars**: For long-running operations
- **Tables**: Formatted data display
- **Boxes**: Highlighted information
- **Interactive prompts**: Yes/no confirmations, text input

## Key Differences from Legacy deploy.php

| Feature | Legacy | ShipPHP Faster |
|---------|--------|----------------|
| Architecture | Single file | Modular (Commands/Core/Helpers) |
| Configuration | Hard-coded constants | JSON config file |
| Initialization | Manual editing | Interactive `init` command |
| Backups | None | Version-tracked manual backups |
| .gitignore | Not supported | Automatically respected |
| Server Config | Manual editing | Generated during init |
| Security | Basic token | Multi-layer (token, IP, rate limit) |
| Logging | Minimal | Comprehensive audit trail |
| Error Handling | Basic | User-friendly with recovery |
