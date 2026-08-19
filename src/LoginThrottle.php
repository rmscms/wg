<?php

declare(strict_types=1);

namespace WgPanel;

final class LoginThrottle
{
    private const MAX_ATTEMPTS = 3;
    private const LOCKOUT_SECONDS = 900;

    private string $storageDir;

    public function __construct(string $storageDir)
    {
        $this->storageDir = rtrim($storageDir, '/\\');
    }

    public function isBlocked(): bool
    {
        return $this->remainingLockSeconds() > 0;
    }

    public function remainingLockSeconds(): int
    {
        $state = $this->readState();

        if (($state['locked_until'] ?? 0) <= time()) {
            return 0;
        }

        return (int) ($state['locked_until'] - time());
    }

    public function remainingAttempts(): int
    {
        if ($this->isBlocked()) {
            return 0;
        }

        $state = $this->readState();

        return max(0, self::MAX_ATTEMPTS - (int) ($state['attempts'] ?? 0));
    }

    public function recordFailure(): void
    {
        $state = $this->readState();
        $now = time();

        if (($state['locked_until'] ?? 0) > $now) {
            return;
        }

        if (($state['locked_until'] ?? 0) > 0 && ($state['locked_until'] ?? 0) <= $now) {
            $state = ['attempts' => 0, 'locked_until' => 0];
        }

        $state['attempts'] = (int) ($state['attempts'] ?? 0) + 1;

        if ($state['attempts'] >= self::MAX_ATTEMPTS) {
            $state['locked_until'] = $now + self::LOCKOUT_SECONDS;
        }

        $this->writeState($state);
    }

    public function clear(): void
    {
        $path = $this->statePath();

        if (is_file($path)) {
            @unlink($path);
        }
    }

    public function failureMessage(): string
    {
        if ($this->isBlocked()) {
            return $this->lockMessage();
        }

        $remaining = $this->remainingAttempts();

        if ($remaining <= 0) {
            return $this->lockMessage();
        }

        return 'نام کاربری یا رمز عبور اشتباه است. '
            . $remaining . ' تلاش دیگر باقی مانده.';
    }

    public function lockMessage(): string
    {
        $seconds = $this->remainingLockSeconds();

        if ($seconds <= 0) {
            return 'نام کاربری یا رمز عبور اشتباه است.';
        }

        $minutes = max(1, (int) ceil($seconds / 60));

        return 'به دلیل ۳ تلاش ناموفق، ورود به مدت '
            . $minutes . ' دقیقه مسدود شده است.';
    }

    public static function clientIp(): string
    {
        $sources = [
            $_SERVER['HTTP_CF_CONNECTING_IP'] ?? null,
            $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null,
            $_SERVER['REMOTE_ADDR'] ?? null,
        ];

        foreach ($sources as $source) {
            if (!is_string($source) || trim($source) === '') {
                continue;
            }

            $ip = trim(explode(',', $source)[0]);

            if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                return $ip;
            }
        }

        return '0.0.0.0';
    }

    /** @return array{attempts: int, locked_until: int} */
    private function readState(): array
    {
        $path = $this->statePath();

        if (!is_file($path)) {
            return ['attempts' => 0, 'locked_until' => 0];
        }

        $handle = @fopen($path, 'c+');

        if ($handle === false) {
            return ['attempts' => 0, 'locked_until' => 0];
        }

        try {
            if (!flock($handle, LOCK_SH)) {
                return ['attempts' => 0, 'locked_until' => 0];
            }

            $raw = stream_get_contents($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }

        if (!is_string($raw) || trim($raw) === '') {
            return ['attempts' => 0, 'locked_until' => 0];
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            return ['attempts' => 0, 'locked_until' => 0];
        }

        $now = time();
        $lockedUntil = (int) ($decoded['locked_until'] ?? 0);

        if ($lockedUntil > 0 && $lockedUntil <= $now) {
            return ['attempts' => 0, 'locked_until' => 0];
        }

        return [
            'attempts' => max(0, (int) ($decoded['attempts'] ?? 0)),
            'locked_until' => $lockedUntil,
        ];
    }

    /** @param array{attempts: int, locked_until: int} $state */
    private function writeState(array $state): void
    {
        if (!is_dir($this->storageDir) && !mkdir($this->storageDir, 0750, true) && !is_dir($this->storageDir)) {
            return;
        }

        $path = $this->statePath();
        $payload = json_encode([
            'attempts' => max(0, (int) $state['attempts']),
            'locked_until' => max(0, (int) $state['locked_until']),
            'ip' => self::clientIp(),
            'updated_at' => time(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($payload === false) {
            return;
        }

        $handle = @fopen($path, 'c+');

        if ($handle === false) {
            return;
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                return;
            }

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, $payload);
            fflush($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }
    }

    private function statePath(): string
    {
        return $this->storageDir . '/' . hash('sha256', self::clientIp()) . '.json';
    }
}
