<?php

declare(strict_types=1);

namespace WgPanel;

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use RuntimeException;

final class QrGenerator
{
    public static function png(string $data, int $scale = 8): string
    {
        if (!extension_loaded('gd')) {
            throw new RuntimeException('PHP GD extension is required for QR code generation.');
        }

        $options = new QROptions([
            'outputType' => QRCode::OUTPUT_IMAGE_PNG,
            'scale' => $scale,
            'quietzoneSize' => 2,
            'imageBase64' => false,
            'imageTransparent' => false,
        ]);

        $output = (new QRCode($options))->render($data);

        if (is_string($output) && str_starts_with($output, 'data:image/png;base64,')) {
            $decoded = base64_decode(substr($output, 22), true);

            if ($decoded !== false) {
                return $decoded;
            }
        }

        if (is_string($output) && $output !== '' && !str_starts_with($output, 'data:')) {
            return $output;
        }

        throw new RuntimeException('Failed to generate QR code.');
    }
}
