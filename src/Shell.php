<?php

declare(strict_types=1);

namespace WgPanel;

use RuntimeException;

final class Shell
{
    public static function run(string $command, bool $mustSucceed = true, bool $sudo = false): array
    {
        $output = [];
        $exitCode = 0;

        if ($sudo && function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $sudo = false;
        }

        if ($sudo) {
            $command = 'sudo -n ' . $command;
        }

        exec($command . ' 2>&1', $output, $exitCode);
        $result = [
            'exit_code' => $exitCode,
            'output' => implode("\n", $output),
        ];

        if ($mustSucceed && $exitCode !== 0) {
            throw new RuntimeException(
                "Command failed ({$exitCode}): {$command}\n{$result['output']}"
            );
        }

        return $result;
    }

    public static function runScript(string $script, array $args = [], bool $mustSucceed = true): array
    {
        if (!is_file($script)) {
            throw new RuntimeException("Script not found: {$script}");
        }

        $escaped = array_map(static fn(string $arg): string => escapeshellarg($arg), $args);
        $argString = $escaped !== [] ? ' ' . implode(' ', $escaped) : '';

        if (is_executable($script)) {
            $command = escapeshellcmd($script) . $argString;
        } elseif (is_readable($script)) {
            $command = '/bin/bash ' . escapeshellarg($script) . $argString;
        } else {
            throw new RuntimeException(
                "Script not accessible: {$script}. Run as root: chmod +x {$script}"
            );
        }

        return self::run($command, $mustSucceed, true);
    }
}
