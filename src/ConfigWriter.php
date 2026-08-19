<?php

declare(strict_types=1);

namespace WgPanel;

use RuntimeException;

final class ConfigWriter
{
    private string $path;

    public function __construct(string $configPath)
    {
        $this->path = $configPath;
    }

    /** Read and return the current config array. */
    public function read(): array
    {
        if (!is_file($this->path)) {
            throw new RuntimeException('Config file not found: ' . $this->path);
        }

        $config = require $this->path;

        if (!is_array($config)) {
            throw new RuntimeException('Config file must return an array.');
        }

        return $config;
    }

    /**
     * Deep-merge $changes into the current config and write back.
     * Only keys present in $changes are updated; everything else is preserved.
     */
    public function update(array $changes): void
    {
        $current = $this->read();
        $merged  = $this->deepMerge($current, $changes);
        $this->write($merged);
    }

    /** Write a full config array to the file. */
    public function write(array $config): void
    {
        if (!is_writable($this->path)) {
            throw new RuntimeException(
                'Config file is not writable: ' . $this->path .
                '. Run: chmod 660 ' . $this->path
            );
        }

        $export  = var_export($config, true);
        $content = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . $export . ";\n";

        $tmp = $this->path . '.tmp.' . bin2hex(random_bytes(4));

        if (file_put_contents($tmp, $content, LOCK_EX) === false) {
            throw new RuntimeException('Failed to write config to temporary file.');
        }

        if (!rename($tmp, $this->path)) {
            @unlink($tmp);
            throw new RuntimeException('Failed to replace config file.');
        }
    }

    /** Recursively merge $b into $a (scalar values in $b overwrite $a). */
    private function deepMerge(array $a, array $b): array
    {
        foreach ($b as $key => $value) {
            if (isset($a[$key]) && is_array($a[$key]) && is_array($value)) {
                $a[$key] = $this->deepMerge($a[$key], $value);
            } else {
                $a[$key] = $value;
            }
        }

        return $a;
    }
}
