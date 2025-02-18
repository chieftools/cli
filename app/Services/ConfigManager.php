<?php

namespace App\Services;

class ConfigManager
{
    private const DEFAULT_CONFIG = [
        'api_key' => null,
        'refresh_token' => null,
        'team_slug' => null,
        'team_name' => null,
    ];

    private string $configPath;
    private array $config;

    public function __construct(string $basePath = null)
    {
        $this->configPath = $basePath ?? $_SERVER['HOME'] . '/.config/chief';
        $this->initialize();
    }

    public function initialize(): void
    {
        if (!$this->ensureConfigDirectory()) {
            throw new \RuntimeException("Unable to create config directory: {$this->configPath}");
        }

        if (!file_exists($this->getConfigFile())) {
            $this->writeConfig(self::DEFAULT_CONFIG);
        }

        $this->loadConfig();
    }

    private function ensureConfigDirectory(): bool
    {
        if (!is_dir($this->configPath)) {
            return mkdir($this->configPath, 0755, true);
        }
        return true;
    }

    public function get(string $key, $default = null)
    {
        return $this->config[$key] ?? $default;
    }

    public function set(string $key, $value): void
    {
        $this->config[$key] = $value;
        $this->writeConfig($this->config);
    }

    public function setMultiple(array $values): void
    {
        foreach ($values as $key => $value) {
            $this->config[$key] = $value;
        }
        $this->writeConfig($this->config);
    }

    public function has(string $key): bool
    {
        return isset($this->config[$key]) && $this->config[$key] !== null;
    }

    public function remove(string $key): void
    {
        if (array_key_exists($key, $this->config)) {
            $this->config[$key] = null;
            $this->writeConfig($this->config);
        }
    }

    public function clear(): void
    {
        $this->config = self::DEFAULT_CONFIG;
        $this->writeConfig($this->config);
    }

    public function all(): array
    {
        return $this->config;
    }

    private function loadConfig(): void
    {
        $loadedConfig = require $this->getConfigFile();
        $this->config = is_array($loadedConfig) ? $loadedConfig : self::DEFAULT_CONFIG;
    }

    private function writeConfig(array $config): void
    {
        $configContent = "<?php\n\nreturn " . var_export($config, true) . ";\n";

        if (file_put_contents($this->getConfigFile(), $configContent) === false) {
            throw new \RuntimeException("Failed to write config file: {$this->getConfigFile()}");
        }
    }

    public function getConfigPath(): string
    {
        return $this->configPath;
    }

    public function getConfigFile(): string
    {
        return $this->configPath . '/config.php';
    }

    public function updateAuthData(string $accessToken, string $refreshToken, string $teamSlug): void
    {
        $this->setMultiple([
            'api_key' => $accessToken,
            'refresh_token' => $refreshToken,
            'team_slug' => $teamSlug,
        ]);
    }
}
