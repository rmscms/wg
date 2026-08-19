<?php

declare(strict_types=1);

namespace WgPanel;

use CURLFile;
use RuntimeException;
use TelegramBot\Api\BotApi;
use Throwable;

final class TelegramBridge
{
    private const MAX_BYTES = 50 * 1024 * 1024;
    private const UPLOAD_TIMEOUT = 120;

    /** @var array<string, mixed> */
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public static function isConfigured(array $config): bool
    {
        $token = trim((string) ($config['telegram']['bot_token'] ?? ''));
        $chatId = trim((string) ($config['telegram']['chat_id'] ?? ''));

        return $token !== '' && $chatId !== '';
    }

    public static function maskToken(string $token): string
    {
        $token = trim($token);

        if ($token === '') {
            return 'تنظیم نشده';
        }

        $len = strlen($token);

        if ($len <= 4) {
            return '••••';
        }

        return str_repeat('•', max(8, $len - 4)) . substr($token, -4);
    }

    public function sendTest(): void
    {
        $this->assertReady();

        try {
            $this->client()->sendMessage($this->chatId(), 'پنل WireGuard وصل شد');
        } catch (RuntimeException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw $this->wrap($e);
        }
    }

    public function sendBackup(string $path, string $caption): void
    {
        $this->assertReady();

        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('فایل بک‌آپ یافت نشد.');
        }

        $size = (int) filesize($path);

        if ($size > self::MAX_BYTES) {
            throw new RuntimeException('حجم فایل از ۵۰ مگابایت بیشتر است و تلگرام قبول نمی‌کند.');
        }

        $api = $this->client();
        $api->setCurlOption(CURLOPT_TIMEOUT, self::UPLOAD_TIMEOUT);
        $api->setCurlOption(CURLOPT_CONNECTTIMEOUT, 20);

        try {
            $api->sendDocument(
                $this->chatId(),
                new CURLFile($path, 'application/gzip', basename($path)),
                $caption
            );
        } catch (RuntimeException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw $this->wrap($e);
        }
    }

    private function assertReady(): void
    {
        if (!extension_loaded('curl')) {
            throw new RuntimeException('افزونه curl لازم است.');
        }

        if (!self::isConfigured($this->config)) {
            throw new RuntimeException('توکن ربات یا شناسه چت تلگرام تنظیم نشده است.');
        }
    }

    private function client(): BotApi
    {
        return new BotApi(trim((string) $this->config['telegram']['bot_token']));
    }

    private function chatId(): string
    {
        return trim((string) $this->config['telegram']['chat_id']);
    }

    private function wrap(Throwable $e): RuntimeException
    {
        $msg = trim($e->getMessage());

        if ($msg === '') {
            $msg = 'خطای ناشناخته';
        }

        return new RuntimeException('تلگرام: ' . $msg, 0, $e);
    }
}
