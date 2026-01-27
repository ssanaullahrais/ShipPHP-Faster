# ShipPHP Installation Guide

ShipPHP is a professional PHP deployment tool with git-like push/pull functionality. Install it globally once and use it across all your projects!

## 🚀 Quick Install (Recommended)

### Via Composer (Global Installation)

```bash
composer global require shipphp/faster
```

**That's it!** ShipPHP is now available globally. Use it from any directory:

```bash
cd /path/to/your/project
shipphp init
shipphp status
shipphp push
```

### Requirements

- **PHP 7.4+** (PHP 8.0+ recommended)
- **Composer** (for global installation)

---

## Alternative Installation Methods

### Method 1: Manual Download (No Composer)

If you don't have Composer or prefer manual installation:

1. **Download the latest release** from GitHub:
   ```bash
   wget https://github.com/yourusername/shipphp/archive/refs/heads/main.zip
   unzip main.zip
   cd shipphp-main
   ```

2. **Install globally** (automatic):
   ```bash
   php shipphp.php install --global
   ```

3. **Done!** Use `shipphp` command from anywhere.

### Method 2: Per-Project Installation

If you want ShipPHP in a specific project only:

```bash
cd /path/to/your/project
composer require --dev shipphp/faster
```

Then use:
```bash
vendor/bin/shipphp init
vendor/bin/shipphp status
vendor/bin/shipphp push
```

Or create a shortcut:
```bash
vendor/bin/shipphp bootstrap ./ship
```

Then use: `php ship status`, `php ship push`, etc.

---

## Verifying Installation

After installation, verify it works:

```bash
shipphp --version
# Should output: ShipPHP Faster v2.0.0

shipphp help
# Shows all available commands
```

---

## First Time Setup

Once installed, initialize ShipPHP in your project:

```bash
cd /path/to/your/project
shipphp init
```

This will:
- Ask for your project domain (e.g., example.com)
- Configure security settings (max file size, rate limits, etc.)
- Generate `shipphp-server.php` (upload this to your server)
- Create `.shipphp/` directory for state tracking
- Create `shipphp.json` config file

Upload the generated `shipphp-server.php` to your server, then:

```bash
shipphp status    # Check connection
shipphp push      # Deploy your project!
```

---

## Troubleshooting

### "composer: command not found"

Install Composer first:
- **Windows**: Download from [getcomposer.org](https://getcomposer.org/)
- **Mac**: `brew install composer`
- **Linux**: `curl -sS https://getcomposer.org/installer | php && sudo mv composer.phar /usr/local/bin/composer`

### "shipphp: command not found" after global install

Add Composer's global bin to your PATH:

**Linux/Mac:**
```bash
echo 'export PATH="$HOME/.composer/vendor/bin:$PATH"' >> ~/.bashrc
source ~/.bashrc
```

**Windows:**
1. Search "Environment Variables" in Windows
2. Edit "Path" variable
3. Add: `%APPDATA%\Composer\vendor\bin`
4. Restart terminal

### Permission Issues

**Linux/Mac:** You may need sudo for global installation:
```bash
sudo composer global require shipphp/faster
```

Or install to your home directory (no sudo needed):
```bash
composer global require shipphp/faster
```

---

## Updating ShipPHP

Update to the latest version:

```bash
composer global update shipphp/faster
```

---

## Uninstalling

Remove ShipPHP globally:

```bash
composer global remove shipphp/faster
```

---

## Next Steps

- Read the [README.md](README.md) for full documentation
- Check out [USAGE.md](USAGE.md) for advanced features
- Join our community for support

---

## Quick Start Guide

```bash
# 1. Install globally
composer global require shipphp/faster

# 2. Initialize in your project
cd /path/to/your/project
shipphp init

# 3. Upload shipphp-server.php to your server

# 4. Deploy!
shipphp status    # Check what changed
shipphp push      # Deploy changes
```

That's it! You're ready to deploy! 🚀
