<?php

namespace ShipPHP\Core;

use ShipPHP\Security\Security;
use ShipPHP\Core\ProjectPaths;

/**
 * Configuration Manager
 * Handles shipphp.json configuration file
 */
class Config
{
    private $configPath;
    private $config;
    private $defaultConfig = [
        'version' => '2.0.0',
        'serverUrl' => '',
        'token' => '',
        'deleteOnPush' => false,
        'ignore' => [
            '.git',
            '.gitignore',
            '.ignore',
            '.shipphp',
            'shipphp-config',
            'shipphp-config/.shipphp',
            'backup',
            'shipphp.json',
            'shipphp-server.php',
            'shipphp',
            'shipphp.php',
            '.env',
            '.env.local',
            '.env.*.local',
            'node_modules',
            'vendor',
            '.vscode',
            '.idea',
            '*.log',
            '.DS_Store',
            'Thumbs.db'
        ],
        'environments' => [
            'production' => [
                'serverUrl' => '',
                'token' => '',
                'deleteOnPush' => false
            ]
        ],
        'currentEnv' => 'production',
        'security' => [
            'validateFileTypes' => true,
            'maxFileSize' => 104857600, // 100MB
            'allowedExtensions' => [],  // Empty = use defaults
            'rateLimit' => 120,
            'enableLogging' => true,
            'ipWhitelist' => []
        ]
    ];

    public function __construct($workingDir = null)
    {
        $workingDir = $workingDir ?: WORKING_DIR;
        $this->configPath = ProjectPaths::configFile($workingDir);
        $this->load();
    }

    /**
     * Load configuration from file
     */
    public function load()
    {
        if (!file_exists($this->configPath)) {
            $this->config = $this->defaultConfig;
            return;
        }

        $json = file_get_contents($this->configPath);
        $loaded = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception("Invalid JSON in shipphp.json: " . json_last_error_msg());
        }

        // Merge with defaults
        $this->config = array_replace_recursive($this->defaultConfig, $loaded);

        // Load ignore patterns from .gitignore and .ignore files
        $this->loadGitignore();
        $this->loadIgnoreFile();
    }

    /**
     * Load ignore patterns from .gitignore
     */
    private function loadGitignore()
    {
        $gitignorePath = WORKING_DIR . '/.gitignore';

        if (!file_exists($gitignorePath)) {
            return;
        }

        $content = file_get_contents($gitignorePath);
        $lines = explode("\n", $content);
        $patterns = [];

        foreach ($lines as $line) {
            $line = trim($line);

            // Skip empty lines and comments
            if (empty($line) || $line[0] === '#') {
                continue;
            }

            $patterns[] = $line;
        }

        // Merge with config ignore patterns (unique)
        $existingIgnore = $this->get('ignore', []);
        $merged = array_unique(array_merge($existingIgnore, $patterns));
        $this->config['ignore'] = array_values($merged);
    }

    /**
     * Load ignore patterns from .ignore file (ShipPHP-specific)
     */
    private function loadIgnoreFile()
    {
        $ignorePath = ProjectPaths::ignoreFile();

        if (!file_exists($ignorePath)) {
            return;
        }

        $content = file_get_contents($ignorePath);
        $lines = explode("\n", $content);
        $patterns = [];

        foreach ($lines as $line) {
            $line = trim($line);

            // Skip empty lines and comments
            if (empty($line) || $line[0] === '#') {
                continue;
            }

            $patterns[] = $line;
        }

        // Merge with existing ignore patterns (unique)
        $existingIgnore = $this->get('ignore', []);
        $merged = array_unique(array_merge($existingIgnore, $patterns));
        $this->config['ignore'] = array_values($merged);
    }

    /**
     * Save configuration to file
     */
    public function save()
    {
        $json = json_encode($this->config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $configDir = dirname($this->configPath);
        if (!is_dir($configDir)) {
            if (!mkdir($configDir, 0755, true)) {
                throw new \Exception("Failed to create configuration directory");
            }
        }

        if (file_put_contents($this->configPath, $json) === false) {
            throw new \Exception("Failed to write shipphp.json");
        }

        return true;
    }

    /**
     * Get configuration value
     */
    public function get($key, $default = null)
    {
        $keys = explode('.', $key);
        $value = $this->config;

        foreach ($keys as $k) {
            if (!isset($value[$k])) {
                return $default;
            }
            $value = $value[$k];
        }

        return $value;
    }

    /**
     * Set configuration value (ENTERPRISE: With validation)
     */
    public function set($key, $value)
    {
        // ENTERPRISE: Validate inputs
        if (!is_string($key) || empty($key)) {
            throw new \Exception("Config key must be a non-empty string");
        }

        // Validate specific keys
        $this->validateConfigValue($key, $value);

        $keys = explode('.', $key);
        $config = &$this->config;

        foreach ($keys as $i => $k) {
            if ($i === count($keys) - 1) {
                $config[$k] = $value;
            } else {
                if (!isset($config[$k]) || !is_array($config[$k])) {
                    $config[$k] = [];
                }
                $config = &$config[$k];
            }
        }

        return $this;
    }

    /**
     * ENTERPRISE: Validate configuration values
     */
    private function validateConfigValue($key, $value)
    {
        switch ($key) {
            case 'serverUrl':
            case 'environments.production.serverUrl':
            case 'environments.staging.serverUrl':
                if (!empty($value) && !filter_var($value, FILTER_VALIDATE_URL)) {
                    throw new \Exception("Invalid URL format for '{$key}': {$value}");
                }
                break;

            case 'token':
            case 'environments.production.token':
            case 'environments.staging.token':
                if (!empty($value) && !preg_match('/^[a-f0-9]{64}$/i', $value)) {
                    throw new \Exception("Invalid token format for '{$key}'. Token must be 64 hex characters.");
                }
                break;

            case 'security.maxFileSize':
                if (!is_numeric($value) || $value < 1024) {
                    throw new \Exception("maxFileSize must be at least 1KB");
                }
                break;

            case 'security.rateLimit':
                if (!is_numeric($value) || $value < 1 || $value > 10000) {
                    throw new \Exception("rateLimit must be between 1 and 10000");
                }
                break;

            case 'deleteOnPush':
                if (!is_bool($value)) {
                    throw new \Exception("deleteOnPush must be a boolean");
                }
                break;
        }
    }

    /**
     * Check if config exists
     */
    public function exists()
    {
        return file_exists($this->configPath);
    }

    /**
     * Get current environment configuration
     */
    public function getCurrentEnv()
    {
        $envName = $this->get('currentEnv', 'production');
        $envConfig = $this->get("environments.{$envName}", []);

        // Merge with root config
        return [
            'name' => $envName,
            'serverUrl' => $envConfig['serverUrl'] ?? $this->get('serverUrl'),
            'token' => $envConfig['token'] ?? $this->get('token'),
            'deleteOnPush' => $envConfig['deleteOnPush'] ?? $this->get('deleteOnPush'),
        ];
    }

    /**
     * Switch environment
     */
    public function switchEnv($envName)
    {
        if (!isset($this->config['environments'][$envName])) {
            throw new \Exception("Environment '{$envName}' not found in configuration");
        }

        $this->set('currentEnv', $envName);
        return true;
    }

    /**
     * Validate configuration
     */
    public function validate()
    {
        $errors = [];
        $env = $this->getCurrentEnv();

        // Check required fields
        if (empty($env['serverUrl'])) {
            $errors[] = "serverUrl is required";
        } elseif (!filter_var($env['serverUrl'], FILTER_VALIDATE_URL)) {
            $errors[] = "serverUrl must be a valid URL";
        }

        if (empty($env['token'])) {
            $errors[] = "token is required";
        } elseif (!Security::validateToken($env['token'])) {
            $errors[] = "token must be a 64-character hexadecimal string";
        }

        if (!empty($errors)) {
            throw new \Exception("Configuration validation failed:\n  - " . implode("\n  - ", $errors));
        }

        return true;
    }

    /**
     * Initialize default configuration
     */
    public function init($serverUrl = '', $force = false, $serverConfig = [])
    {
        if ($this->exists() && !$force) {
            throw new \Exception("shipphp.json already exists. Use --force to overwrite.");
        }

        // Generate secure token
        $token = Security::generateToken();

        $this->config = $this->defaultConfig;
        $this->set('serverUrl', $serverUrl);
        $this->set('token', $token);
        $this->set('environments.production.serverUrl', $serverUrl);
        $this->set('environments.production.token', $token);

        // Apply server configuration
        if (!empty($serverConfig)) {
            if (isset($serverConfig['maxFileSize'])) {
                $this->set('security.maxFileSize', $serverConfig['maxFileSize']);
            }
            if (isset($serverConfig['rateLimit'])) {
                $this->set('security.rateLimit', $serverConfig['rateLimit']);
            }
            if (isset($serverConfig['enableLogging'])) {
                $this->set('security.enableLogging', $serverConfig['enableLogging']);
            }
            if (isset($serverConfig['ipWhitelist'])) {
                $this->set('security.ipWhitelist', $serverConfig['ipWhitelist']);
            }
        }

        $this->save();

        return [
            'token' => $token,
            'serverUrl' => $serverUrl,
            'serverConfig' => $serverConfig
        ];
    }

    /**
     * Generate .gitignore file
     */
    public function generateGitignore()
    {
        $gitignorePath = WORKING_DIR . '/.gitignore';

        $content = <<<'GITIGNORE'
# ShipPHP files
shipphp-config/
.shipphp/
shipphp.json
shipphp-server.php

# Environment files
.env
.env.local
.env.*.local

# Dependencies
node_modules/
vendor/

# IDE files
.vscode/
.idea/
*.sublime-project
*.sublime-workspace

# OS files
.DS_Store
Thumbs.db
desktop.ini

# Logs
*.log
npm-debug.log*
yarn-debug.log*
yarn-error.log*

# Git files
.git/
.gitignore

GITIGNORE;

        // Check if .gitignore already exists
        if (file_exists($gitignorePath)) {
            // Read existing content
            $existing = file_get_contents($gitignorePath);

            // Only add if ShipPHP section doesn't exist
            if (strpos($existing, '# ShipPHP files') === false) {
                // Add ShipPHP section to top
                $content = $content . "\n# Existing rules\n" . $existing;
            } else {
                // Don't overwrite if already has ShipPHP section
                return false;
            }
        }

        return file_put_contents($gitignorePath, $content) !== false;
    }

    /**
     * Generate .ignore file (ShipPHP-specific ignore patterns)
     */
    public function generateIgnoreFile()
    {
        $ignorePath = ProjectPaths::ignoreFile();
        $ignoreDir = dirname($ignorePath);

        if (!is_dir($ignoreDir)) {
            if (!mkdir($ignoreDir, 0755, true)) {
                throw new \Exception("Failed to create configuration directory");
            }
        }

        $content = <<<'IGNORE'
# ShipPHP-specific ignore patterns
# These files will be excluded from deployment and backups
# (Separate from .gitignore for ShipPHP-only exclusions)

# Backup directory
backup/

# ShipPHP internal files
.shipphp/
shipphp/
shipphp-config/

# Add your custom patterns below:
# example:
# *.tmp
# cache/
# uploads/large-files/

IGNORE;

        // Don't overwrite if already exists
        if (file_exists($ignorePath)) {
            return false;
        }

        return file_put_contents($ignorePath, $content) !== false;
    }

    /**
     * Get all configuration
     */
    public function all()
    {
        return $this->config;
    }

    /**
     * Check if file should be ignored
     */
    public function shouldIgnore($path)
    {
        $ignorePatterns = $this->get('ignore', []);

        foreach ($ignorePatterns as $pattern) {
            // Convert glob pattern to regex
            $regex = $this->globToRegex($pattern);

            if (preg_match($regex, $path)) {
                return true;
            }

            // Also check if path starts with pattern (for directories)
            if (strpos($path, rtrim($pattern, '/') . '/') === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Convert glob pattern to regex
     */
    private function globToRegex($pattern)
    {
        $pattern = preg_quote($pattern, '#');
        $pattern = str_replace('\*', '.*', $pattern);
        $pattern = str_replace('\?', '.', $pattern);

        return '#^' . $pattern . '$#';
    }

    /**
     * Get config file path
     */
    public function getPath()
    {
        return $this->configPath;
    }
}
