<?php

namespace ShipPHP\Api;

/**
 * HTTP Request Parser
 * Parses incoming API requests
 */
class Request
{
    private $method;
    private $path;
    private $query;
    private $body;
    private $headers;

    public function __construct()
    {
        $this->method = $_SERVER['REQUEST_METHOD'];
        $this->path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $this->query = $_GET;
        $this->headers = $this->parseHeaders();
        $this->body = $this->parseBody();
    }

    /**
     * Get HTTP method
     */
    public function getMethod(): string
    {
        return $this->method;
    }

    /**
     * Get request path
     */
    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * Get query parameter
     */
    public function query(string $key, $default = null)
    {
        return $this->query[$key] ?? $default;
    }

    /**
     * Get all query parameters
     */
    public function allQuery(): array
    {
        return $this->query;
    }

    /**
     * Get body parameter
     */
    public function input(string $key, $default = null)
    {
        return $this->body[$key] ?? $default;
    }

    /**
     * Get all body parameters
     */
    public function all(): array
    {
        return $this->body;
    }

    /**
     * Get header value
     */
    public function header(string $key, $default = null): ?string
    {
        $key = strtolower($key);
        return $this->headers[$key] ?? $default;
    }

    /**
     * Get authorization token
     */
    public function bearerToken(): ?string
    {
        $auth = $this->header('authorization');
        if ($auth && preg_match('/Bearer\s+(.+)$/i', $auth, $matches)) {
            return $matches[1];
        }
        return null;
    }

    /**
     * Get uploaded file
     */
    public function file(string $key): ?array
    {
        return $_FILES[$key] ?? null;
    }

    /**
     * Check if request expects JSON
     */
    public function wantsJson(): bool
    {
        $accept = $this->header('accept', '');
        return strpos($accept, 'application/json') !== false;
    }

    /**
     * Parse request headers
     */
    private function parseHeaders(): array
    {
        $headers = [];

        foreach ($_SERVER as $key => $value) {
            if (strpos($key, 'HTTP_') === 0) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = $value;
            }
        }

        // Add content-type and content-length if present
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = $_SERVER['CONTENT_TYPE'];
        }
        if (isset($_SERVER['CONTENT_LENGTH'])) {
            $headers['content-length'] = $_SERVER['CONTENT_LENGTH'];
        }

        return $headers;
    }

    /**
     * Parse request body
     */
    private function parseBody(): array
    {
        // Handle JSON body
        $contentType = $this->header('content-type', '');

        if (strpos($contentType, 'application/json') !== false) {
            $raw = file_get_contents('php://input');
            $data = json_decode($raw, true);
            return is_array($data) ? $data : [];
        }

        // Handle form data
        if ($this->method === 'POST') {
            return $_POST;
        }

        // Handle other methods with form-urlencoded body
        if (in_array($this->method, ['PUT', 'PATCH', 'DELETE'])) {
            $raw = file_get_contents('php://input');
            parse_str($raw, $data);
            return $data;
        }

        return [];
    }
}
