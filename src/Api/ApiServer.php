<?php

namespace ShipPHP\Api;

// Define constants if not already defined
if (!defined('WORKING_DIR')) {
    define('WORKING_DIR', getcwd());
}

if (!defined('SHIPPHP_VERSION')) {
    $versionFile = dirname(dirname(__DIR__)) . '/version.php';
    define('SHIPPHP_VERSION', file_exists($versionFile) ? require $versionFile : '2.1.1');
}

if (!defined('SHIPPHP_COMMAND')) {
    define('SHIPPHP_COMMAND', 'shipphp');
}

// Autoload classes
spl_autoload_register(function ($class) {
    $prefix = 'ShipPHP\\';
    $baseDir = dirname(__DIR__) . '/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

use ShipPHP\Core\Config;
use ShipPHP\Core\ApiClient;
use ShipPHP\Core\State;
use ShipPHP\Core\Backup;
use ShipPHP\Core\ProfileManager;
use ShipPHP\Core\ProjectPaths;
use ShipPHP\Core\PlanManager;
use ShipPHP\Helpers\Output;

/**
 * API Server
 * Handles all API requests for the Web UI
 */
class ApiServer
{
    private $request;
    private $response;
    private $config;
    private $api;
    private $state;

    public function __construct()
    {
        $this->request = new Request();
        $this->response = new Response();
    }

    /**
     * Handle incoming API request
     */
    public function handle()
    {
        // Handle CORS preflight
        if ($this->request->getMethod() === 'OPTIONS') {
            $this->response->handleCors();
            return;
        }

        try {
            // Parse route
            $path = $this->request->getPath();
            $path = preg_replace('#^/api/?#', '', $path);
            $segments = array_filter(explode('/', $path));
            $segments = array_values($segments);

            $method = $this->request->getMethod();

            // Route to handler
            $this->route($method, $segments);
        } catch (\Exception $e) {
            $this->response->error($e->getMessage(), 500);
        }
    }

    /**
     * Route request to appropriate handler
     */
    private function route($method, $segments)
    {
        $resource = $segments[0] ?? '';
        $action = $segments[1] ?? '';
        $id = $segments[2] ?? '';

        switch ($resource) {
            // Health & Status
            case 'health':
                $this->handleHealth();
                break;

            case 'status':
                $this->handleStatus();
                break;

            // Deployment
            case 'push':
                $this->handlePush();
                break;

            case 'pull':
                $this->handlePull();
                break;

            case 'sync':
                $this->handleSync();
                break;

            // Files
            case 'files':
                $this->handleFiles($method, $action, $id);
                break;

            // Trash
            case 'trash':
                $this->handleTrash($method, $action, $id);
                break;

            // Backups
            case 'backups':
                $this->handleBackups($method, $action, $id);
                break;

            // Plans
            case 'plans':
                $this->handlePlans($method, $action);
                break;

            // Profiles
            case 'profiles':
                $this->handleProfiles($method, $action);
                break;

            // Server
            case 'server':
                $this->handleServer($action);
                break;

            // Logs
            case 'logs':
                $this->handleLogs();
                break;

            // Config
            case 'config':
                $this->handleConfig($method, $action);
                break;

            default:
                $this->response->error('Not found', 404);
        }
    }

    /**
     * Initialize API client
     */
    private function initApi()
    {
        if ($this->api) {
            return;
        }

        $this->config = new Config();

        if (!$this->config->exists()) {
            throw new \Exception('Project not initialized');
        }

        $env = $this->config->getCurrentEnv();

        if (empty($env['serverUrl']) || empty($env['token'])) {
            throw new \Exception('Server configuration incomplete');
        }

        $this->api = new ApiClient($env['serverUrl'], $env['token']);
    }

    /**
     * Initialize state
     */
    private function initState()
    {
        if ($this->state) {
            return;
        }

        $this->state = new State();
    }

    // ==========================================
    // HANDLERS
    // ==========================================

    private function handleHealth()
    {
        try {
            $this->initApi();
            $result = $this->api->getHealth();
            $this->response->success($result, 'Health check complete');
        } catch (\Exception $e) {
            // Return basic health info even without server connection
            $this->response->success([
                'status' => 'disconnected',
                'local' => [
                    'initialized' => file_exists(ProjectPaths::configFile()),
                    'version' => SHIPPHP_VERSION
                ],
                'error' => $e->getMessage()
            ], 'Health check (local only)');
        }
    }

    private function handleStatus()
    {
        $this->initApi();
        $this->initState();

        // Scan local files
        $localFiles = $this->state->scanLocalFiles();

        // Get server files
        $serverFiles = $this->api->listFiles();

        // Compare
        $changes = $this->state->compareWithServer($serverFiles);

        $this->response->success([
            'changes' => $changes,
            'summary' => [
                'toUpload' => count($changes['toUpload'] ?? []),
                'toDownload' => count($changes['toDownload'] ?? []),
                'toDelete' => count($changes['toDelete'] ?? []),
                'conflicts' => count($changes['conflicts'] ?? [])
            ],
            'localFiles' => count($localFiles),
            'serverFiles' => count($serverFiles)
        ], 'Status retrieved');
    }

    private function handlePush()
    {
        $this->initApi();
        $this->initState();

        $path = $this->request->input('path');
        $force = $this->request->input('force', false);
        $dryRun = $this->request->input('dryRun', false);

        // Get changes
        $localFiles = $this->state->scanLocalFiles();
        $serverFiles = $this->api->listFiles();
        $changes = $this->state->compareWithServer($serverFiles);

        $toUpload = $changes['toUpload'] ?? [];

        // Filter by path if specified
        if ($path) {
            $toUpload = array_filter($toUpload, function($file) use ($path) {
                return strpos($file, $path) === 0;
            });
        }

        if ($dryRun) {
            $this->response->success([
                'dryRun' => true,
                'toUpload' => $toUpload,
                'count' => count($toUpload)
            ], 'Dry run complete');
            return;
        }

        // Upload files
        $uploaded = 0;
        $failed = 0;
        $errors = [];

        foreach ($toUpload as $file) {
            try {
                $localPath = WORKING_DIR . '/' . $file;
                $this->api->uploadFile($localPath, $file);
                $this->state->setFileHash($file, hash_file('sha256', $localPath));
                $uploaded++;
            } catch (\Exception $e) {
                $failed++;
                $errors[] = ['file' => $file, 'error' => $e->getMessage()];
            }
        }

        $this->state->save();

        $this->response->success([
            'uploaded' => $uploaded,
            'failed' => $failed,
            'errors' => $errors
        ], "Push complete: {$uploaded} uploaded, {$failed} failed");
    }

    private function handlePull()
    {
        $this->initApi();
        $this->initState();

        $path = $this->request->input('path');
        $force = $this->request->input('force', false);
        $dryRun = $this->request->input('dryRun', false);

        // Get changes
        $localFiles = $this->state->scanLocalFiles();
        $serverFiles = $this->api->listFiles();
        $changes = $this->state->compareWithServer($serverFiles);

        $toDownload = $changes['toDownload'] ?? [];

        // Filter by path if specified
        if ($path) {
            $toDownload = array_filter($toDownload, function($file) use ($path) {
                return strpos($file, $path) === 0;
            });
        }

        if ($dryRun) {
            $this->response->success([
                'dryRun' => true,
                'toDownload' => $toDownload,
                'count' => count($toDownload)
            ], 'Dry run complete');
            return;
        }

        // Download files
        $downloaded = 0;
        $failed = 0;
        $errors = [];

        foreach ($toDownload as $file) {
            try {
                $localPath = WORKING_DIR . '/' . $file;
                $this->api->downloadFile($file, $localPath);
                $this->state->setFileHash($file, hash_file('sha256', $localPath));
                $downloaded++;
            } catch (\Exception $e) {
                $failed++;
                $errors[] = ['file' => $file, 'error' => $e->getMessage()];
            }
        }

        $this->state->save();

        $this->response->success([
            'downloaded' => $downloaded,
            'failed' => $failed,
            'errors' => $errors
        ], "Pull complete: {$downloaded} downloaded, {$failed} failed");
    }

    private function handleSync()
    {
        // Combined status + push
        $this->handleStatus();
    }

    private function handleFiles($method, $action, $id)
    {
        $this->initApi();

        switch ($action) {
            case 'tree':
                $path = $this->request->query('path', '');
                $depth = $this->request->query('depth', 3);
                $result = $this->api->getTree($path, $depth);
                $this->response->success($result, 'Tree retrieved');
                break;

            case 'read':
                $path = $this->request->query('path') ?: $this->request->input('path');
                $result = $this->api->readFile($path);
                $this->response->success($result, 'File content retrieved');
                break;

            case 'write':
            case 'create':
                $path = $this->request->input('path');
                $content = $this->request->input('content', '');
                $overwrite = $this->request->input('overwrite', false);
                $result = $this->api->writeFile($path, $content, $overwrite);
                $this->response->success($result, 'File written');
                break;

            case 'mkdir':
                $path = $this->request->input('path');
                $result = $this->api->mkdir($path);
                $this->response->success($result, 'Directory created');
                break;

            case 'touch':
                $path = $this->request->input('path');
                $result = $this->api->touch($path);
                $this->response->success($result, 'File touched');
                break;

            case 'copy':
                $source = $this->request->input('source');
                $destination = $this->request->input('destination');
                $overwrite = $this->request->input('overwrite', false);
                $result = $this->api->copyFile($source, $destination, $overwrite);
                $this->response->success($result, 'File copied');
                break;

            case 'move':
                $items = $this->request->input('items', []);
                $mode = $this->request->input('mode', 'move');
                $result = $this->api->moveFiles($items, $mode);
                $this->response->success($result, 'Files moved');
                break;

            case 'rename':
                $path = $this->request->input('path');
                $find = $this->request->input('find');
                $replace = $this->request->input('replace');
                $pattern = $this->request->input('pattern', '*');
                $result = $this->api->rename($path, $find, $replace, $pattern);
                $this->response->success($result, 'Files renamed');
                break;

            case 'chmod':
                $path = $this->request->input('path');
                $mode = $this->request->input('mode');
                $recursive = $this->request->input('recursive', false);
                $result = $this->api->chmod($path, $mode, $recursive);
                $this->response->success($result, 'Permissions changed');
                break;

            case 'info':
                $path = $this->request->query('path') ?: $this->request->input('path');
                $result = $this->api->getFileInfo($path);
                $this->response->success($result, 'File info retrieved');
                break;

            case 'search':
                $pattern = $this->request->query('pattern');
                $path = $this->request->query('path', '');
                $max = $this->request->query('max', 100);
                $result = $this->api->search($pattern, $path, $max);
                $this->response->success($result, 'Search complete');
                break;

            case 'grep':
                $text = $this->request->query('text');
                $path = $this->request->query('path', '');
                $pattern = $this->request->query('pattern', '*');
                $max = $this->request->query('max', 50);
                $result = $this->api->grep($text, $path, $pattern, $max);
                $this->response->success($result, 'Grep complete');
                break;

            case 'download':
                $path = $this->request->query('path');
                $result = $this->api->readFile($path);
                // Return file content for download
                $this->response->success([
                    'path' => $path,
                    'content' => base64_encode($result['content']),
                    'size' => $result['size'] ?? 0,
                    'mime' => $result['mime'] ?? 'application/octet-stream'
                ], 'File downloaded');
                break;

            case 'upload':
                $file = $this->request->file('file');
                $path = $this->request->input('path');
                if (!$file) {
                    $this->response->error('No file uploaded', 400);
                    return;
                }
                // Handle file upload through ApiClient
                $result = $this->api->uploadFile($file['tmp_name'], $path);
                $this->response->success($result, 'File uploaded');
                break;

            default:
                // Default: DELETE or get by path
                if ($method === 'DELETE') {
                    $path = $this->request->input('path');
                    $permanent = $this->request->input('permanent', false);
                    if ($permanent) {
                        $this->api->deleteFile($path);
                    } else {
                        $this->api->trashFiles([$path]);
                    }
                    $this->response->success(['path' => $path], 'File deleted');
                } else {
                    $this->response->error('Invalid files action', 400);
                }
        }
    }

    private function handleTrash($method, $action, $id)
    {
        $this->initApi();

        switch ($action) {
            case 'restore':
                $trashId = $id ?: $this->request->input('id');
                $force = $this->request->input('force', false);
                $result = $this->api->restoreTrash($trashId, $force);
                $this->response->success($result, 'Item restored');
                break;

            case 'empty':
                $result = $this->api->emptyTrash();
                $this->response->success($result, 'Trash emptied');
                break;

            default:
                // List trash
                if ($method === 'GET') {
                    $items = $this->api->listTrash();
                    $this->response->success(['items' => $items], 'Trash list retrieved');
                } elseif ($method === 'DELETE') {
                    $result = $this->api->emptyTrash();
                    $this->response->success($result, 'Trash emptied');
                } else {
                    $this->response->error('Invalid trash action', 400);
                }
        }
    }

    private function handleBackups($method, $action, $id)
    {
        $this->initApi();

        switch ($action) {
            case 'restore':
                $backupId = $id ?: $this->request->input('id');
                $result = $this->api->restoreBackup($backupId);
                $this->response->success($result, 'Backup restored');
                break;

            case 'stats':
                $serverBackups = $this->api->listBackups();
                // Get local backups
                $backup = new Backup(new Config(), new Output());
                $localBackups = $backup->listBackups();
                $this->response->success([
                    'server' => $serverBackups,
                    'local' => $localBackups
                ], 'Backup stats retrieved');
                break;

            default:
                if ($method === 'GET') {
                    // List backups
                    $backups = $this->api->listBackups();
                    $this->response->success(['backups' => $backups], 'Backups retrieved');
                } elseif ($method === 'POST') {
                    // Create backup
                    $backupId = date('Y-m-d-His');
                    $result = $this->api->createBackup($backupId);
                    $this->response->success($result, 'Backup created');
                } elseif ($method === 'DELETE') {
                    // Delete backup
                    $backupId = $id ?: $this->request->input('id');
                    $this->api->deleteBackup($backupId);
                    $this->response->success(['id' => $backupId], 'Backup deleted');
                } else {
                    $this->response->error('Invalid backups action', 400);
                }
        }
    }

    private function handlePlans($method, $action)
    {
        $this->initApi();

        $plan = new PlanManager();

        switch ($action) {
            case 'apply':
                $operations = $plan->getOperations();

                if (empty($operations)) {
                    $this->response->success(['applied' => 0], 'No operations to apply');
                    return;
                }

                $applied = 0;
                $failed = 0;
                $errors = [];

                foreach ($operations as $op) {
                    try {
                        $this->applyOperation($op);
                        $applied++;
                    } catch (\Exception $e) {
                        $failed++;
                        $errors[] = ['operation' => $op, 'error' => $e->getMessage()];
                    }
                }

                $plan->clear();

                $this->response->success([
                    'applied' => $applied,
                    'failed' => $failed,
                    'errors' => $errors
                ], "Applied {$applied} operations");
                break;

            case 'clear':
                $plan->clear();
                $this->response->success([], 'Plan cleared');
                break;

            default:
                if ($method === 'GET') {
                    $operations = $plan->getOperations();
                    $this->response->success(['operations' => $operations], 'Plan retrieved');
                } elseif ($method === 'POST') {
                    $operation = $this->request->all();
                    $plan->addOperation($operation)->save();
                    $this->response->success(['operation' => $operation], 'Operation added to plan');
                } elseif ($method === 'DELETE') {
                    $plan->clear();
                    $this->response->success([], 'Plan cleared');
                } else {
                    $this->response->error('Invalid plans action', 400);
                }
        }
    }

    private function applyOperation($op)
    {
        $type = $op['type'] ?? '';

        switch ($type) {
            case 'delete':
                $this->api->trashFiles($op['paths'] ?? []);
                break;
            case 'move':
                $this->api->moveFiles($op['items'] ?? [], $op['mode'] ?? 'move');
                break;
            case 'rename':
                $this->api->rename($op['path'] ?? '', $op['find'] ?? '', $op['replace'] ?? '');
                break;
            default:
                throw new \Exception("Unknown operation type: {$type}");
        }
    }

    private function handleProfiles($method, $action)
    {
        ProfileManager::init();

        switch ($action) {
            case 'default':
                if ($method === 'PUT' || $method === 'POST') {
                    $name = $this->request->input('name');
                    ProfileManager::setDefault($name);
                    $this->response->success(['default' => $name], 'Default profile set');
                } else {
                    $default = ProfileManager::getDefault();
                    $this->response->success(['default' => $default], 'Default profile retrieved');
                }
                break;

            default:
                if ($method === 'GET') {
                    if ($action) {
                        // Get specific profile
                        $profile = ProfileManager::get($action);
                        if ($profile) {
                            $this->response->success($profile, 'Profile retrieved');
                        } else {
                            $this->response->error('Profile not found', 404);
                        }
                    } else {
                        // List all profiles
                        $profiles = ProfileManager::all();
                        $default = ProfileManager::getDefault();
                        $this->response->success([
                            'profiles' => $profiles,
                            'default' => $default
                        ], 'Profiles retrieved');
                    }
                } elseif ($method === 'POST') {
                    $data = $this->request->all();
                    $name = $data['name'] ?? ProfileManager::generateProfileId($data['domain'] ?? 'profile');
                    ProfileManager::add($name, $data);
                    $this->response->success(['name' => $name], 'Profile created');
                } elseif ($method === 'DELETE') {
                    ProfileManager::remove($action);
                    $this->response->success(['name' => $action], 'Profile removed');
                } else {
                    $this->response->error('Invalid profiles action', 400);
                }
        }
    }

    private function handleServer($action)
    {
        $this->initApi();

        switch ($action) {
            case 'lock':
                $mode = $this->request->input('mode', 'status');
                $message = $this->request->input('message', '');
                $result = $this->api->lock($mode, $message);
                $this->response->success($result, 'Lock operation complete');
                break;

            case 'where':
                $result = $this->api->where();
                $this->response->success($result, 'Server location retrieved');
                break;

            case 'stats':
                $result = $this->api->getStats();
                $this->response->success($result, 'Server stats retrieved');
                break;

            case 'extract':
                $path = $this->request->input('path');
                $destination = $this->request->input('destination', '');
                $overwrite = $this->request->input('overwrite', false);
                $result = $this->api->extractArchive($path, $destination, $overwrite);
                $this->response->success($result, 'Archive extracted');
                break;

            case 'watch':
                $since = $this->request->query('since', '');
                $path = $this->request->query('path', '');
                $result = $this->api->watch($since, $path);
                $this->response->success($result, 'Watch complete');
                break;

            default:
                $this->response->error('Invalid server action', 400);
        }
    }

    private function handleLogs()
    {
        $this->initApi();

        $lines = $this->request->query('lines', 100);
        $filter = $this->request->query('filter', '');

        $result = $this->api->getLogs($lines, $filter);
        $this->response->success($result, 'Logs retrieved');
    }

    private function handleConfig($method, $action)
    {
        switch ($action) {
            case 'env':
                $this->initApi();
                if ($method === 'GET') {
                    $env = $this->config->getCurrentEnv();
                    $envName = $this->config->get('currentEnv', 'default');
                    $this->response->success([
                        'name' => $envName,
                        'serverUrl' => $env['serverUrl'] ?? '',
                        'hasToken' => !empty($env['token'])
                    ], 'Environment retrieved');
                } else {
                    $name = $this->request->input('name');
                    $this->config->set('currentEnv', $name);
                    $this->config->save();
                    $this->response->success(['name' => $name], 'Environment changed');
                }
                break;

            case 'token':
                $this->initApi();
                if ($method === 'GET') {
                    $env = $this->config->getCurrentEnv();
                    $token = $env['token'] ?? '';
                    $this->response->success([
                        'preview' => substr($token, 0, 8) . '...' . substr($token, -4),
                        'length' => strlen($token)
                    ], 'Token info retrieved');
                } else {
                    // Generate new token
                    $newToken = bin2hex(random_bytes(32));
                    $this->config->set('token', $newToken);
                    $this->config->save();
                    $this->response->success([
                        'token' => $newToken,
                        'message' => 'Token rotated. Update server with new token.'
                    ], 'Token rotated');
                }
                break;

            default:
                $this->response->error('Invalid config action', 400);
        }
    }
}
