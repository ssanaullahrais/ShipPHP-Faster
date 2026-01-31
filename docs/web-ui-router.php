<?php

// Basic router for ShipPHP Web UI
if (php_sapi_name() !== 'cli-server') {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if (strpos($path, '/api/command') === 0) {
    header('Content-Type: application/json');

    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || empty($input['command'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing command']);
        exit;
    }

    // Simple autoloader
    spl_autoload_register(function ($class) {
        $prefix = 'ShipPHP\\';
        $baseDir = dirname(__DIR__) . '/src/';
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

    class BufferOutput extends \ShipPHP\Helpers\Output
    {
        private $buffer = '';

        public function write($text, $color = null)
        {
            $this->buffer .= $text;
        }

        public function writeln($text = '', $color = null)
        {
            $this->buffer .= $text . PHP_EOL;
        }

        public function getBuffer()
        {
            return $this->buffer;
        }
    }

    $commandMap = [
        'status' => \ShipPHP\Commands\StatusCommand::class,
        'push' => \ShipPHP\Commands\PushCommand::class,
        'pull' => \ShipPHP\Commands\PullCommand::class,
        'sync' => \ShipPHP\Commands\SyncCommand::class,
        'tree' => \ShipPHP\Commands\TreeCommand::class,
        'delete' => \ShipPHP\Commands\DeleteCommand::class,
        'move' => \ShipPHP\Commands\MoveCommand::class,
        'rename' => \ShipPHP\Commands\RenameCommand::class,
        'trash' => \ShipPHP\Commands\TrashCommand::class,
        'backup' => \ShipPHP\Commands\BackupCommand::class,
        'health' => \ShipPHP\Commands\HealthCommand::class,
        'apply' => \ShipPHP\Commands\ApplyCommand::class,
        'plan' => \ShipPHP\Commands\PlanCommand::class,
        'lock' => \ShipPHP\Commands\LockCommand::class,
        'where' => \ShipPHP\Commands\WhereCommand::class,
        'extract' => \ShipPHP\Commands\ExtractCommand::class,
        'diff' => \ShipPHP\Commands\DiffCommand::class,
    ];

    $commandName = $input['command'];
    if (!isset($commandMap[$commandName])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Unknown command']);
        exit;
    }

    $options = [
        'flags' => $input['flags'] ?? [],
        'params' => $input['params'] ?? [],
        'args' => $input['args'] ?? [],
    ];

    $output = new BufferOutput();

    try {
        $class = $commandMap[$commandName];
        $instance = new $class($output);
        $instance->execute($options);
        echo json_encode([
            'success' => true,
            'output' => $output->getBuffer(),
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage(),
            'output' => $output->getBuffer(),
        ]);
    }
    exit;
}

$docRoot = __DIR__;
$file = realpath($docRoot . $path);

if ($file && strpos($file, $docRoot) === 0 && is_file($file)) {
    return false;
}

$index = $docRoot . '/web-ui.html';
if (file_exists($index)) {
    header('Content-Type: text/html');
    readfile($index);
    exit;
}

http_response_code(404);
echo 'Not Found';
