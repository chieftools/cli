<?php

namespace App\Services;

use Throwable;
use RuntimeException;

class ConfigManager
{
    private const DEFAULT_CONFIG = [
        'access_token'  => null,
        'refresh_token' => null,
        'team_slug'     => null,
        'team_name'     => null,
    ];

    private string $basePath;

    /** @var array<string, mixed> */
    private array $config;

    public function __construct()
    {
        $this->basePath = $_SERVER['HOME'] . '/.config/chief';

        if (!$this->ensureConfigDirectory()) {
            throw new RuntimeException("Unable to create config directory: {$this->basePath}");
        }

        $this->readConfig();

        if (!file_exists($this->getConfigFilePath())) {
            $this->writeConfig();
        }
    }

    public function get(string $key, $default = null)
    {
        return $this->config[$key] ?? $default;
    }

    public function set(string $key, $value): void
    {
        $this->setMultiple([$key => $value]);
    }

    public function setMultiple(array $values): void
    {
        foreach ($values as $key => $value) {
            $this->config[$key] = $value;
        }

        $this->writeConfig();
    }

    public function has(string $key): bool
    {
        return isset($this->config[$key]);
    }

    public function remove(string $key): void
    {
        if (!array_key_exists($key, $this->config)) {
            return;
        }

        $this->set($key, null);
    }

    public function reset(): void
    {
        $this->config = self::DEFAULT_CONFIG;

        $this->writeConfig();
    }

    private function readConfig(): void
    {
        try {
            $loadedConfig = require $this->getConfigFilePath();

            $this->config = is_array($loadedConfig)
                ? array_merge(self::DEFAULT_CONFIG, $loadedConfig)
                : self::DEFAULT_CONFIG;
        } catch (Throwable $e) {
            throw new RuntimeException("Failed to load config file: {$this->getConfigFilePath()} ({$e->getMessage()})", 0, $e);
        }
    }

    private function writeConfig(): void
    {
        $fileContents = '<?php' . PHP_EOL . PHP_EOL . 'return ' . var_export($this->config, true) . ';' . PHP_EOL;

        if (!file_put_contents($this->getConfigFilePath(), $fileContents)) {
            throw new RuntimeException("Failed to write config file: {$this->getConfigFilePath()}");
        }
    }

    private function getConfigFilePath(): string
    {
        return $this->basePath . '/config.php';
    }

    private function ensureConfigDirectory(): bool
    {
        if (is_dir($this->basePath)) {
            return true;
        }

        return mkdir($this->basePath, 0755, true);
    }
}
