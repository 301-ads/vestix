<?php

namespace App\Support;

class TickerIconPalette
{
    public static function extractFromFile(string $absolutePath): ?string
    {
        if (! is_file($absolutePath)) {
            return null;
        }

        $extension = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));
        $contents = file_get_contents($absolutePath);

        if ($contents === false) {
            return null;
        }

        return self::extractFromContents($contents, $extension);
    }

    public static function extractFromContents(string $contents, string $extension): ?string
    {
        return match ($extension) {
            'svg' => self::fromSvg($contents),
            'png', 'jpg', 'jpeg', 'webp', 'gif' => self::fromRasterString($contents),
            default => null,
        };
    }

    private static function fromSvg(string $svg): ?string
    {
        if (preg_match('/<rect\b[^>]*\bfill="(#[0-9a-fA-F]{3,8})"/', $svg, $matches)) {
            return self::normalizeHex($matches[1]);
        }

        if (preg_match('/<rect\b[^>]*\bfill:\s*(#[0-9a-fA-F]{3,8})/', $svg, $matches)) {
            return self::normalizeHex($matches[1]);
        }

        if (preg_match('/\bfill="(#[0-9a-fA-F]{3,8})"/', $svg, $matches)) {
            return self::normalizeHex($matches[1]);
        }

        if (preg_match('/\bstop-color="(#[0-9a-fA-F]{3,8})"/', $svg, $matches)) {
            return self::normalizeHex($matches[1]);
        }

        return null;
    }

    private static function fromRasterString(string $contents): ?string
    {
        if (! extension_loaded('gd')) {
            return null;
        }

        $image = @imagecreatefromstring($contents);

        if ($image === false) {
            return null;
        }

        $width = imagesx($image);
        $height = imagesy($image);

        if ($width <= 0 || $height <= 0) {
            imagedestroy($image);

            return null;
        }

        $samples = [];
        $points = [
            [0, 0],
            [$width - 1, 0],
            [0, $height - 1],
            [$width - 1, $height - 1],
            [(int) ($width / 2), (int) ($height / 2)],
            [(int) ($width * 0.25), (int) ($height * 0.25)],
            [(int) ($width * 0.75), (int) ($height * 0.25)],
        ];

        foreach ($points as [$x, $y]) {
            $rgba = imagecolorat($image, $x, $y);
            $alpha = ($rgba >> 24) & 0x7F;

            if ($alpha > 100) {
                continue;
            }

            $red = ($rgba >> 16) & 0xFF;
            $green = ($rgba >> 8) & 0xFF;
            $blue = $rgba & 0xFF;
            $key = sprintf('%02x%02x%02x', $red, $green, $blue);
            $samples[$key] = ($samples[$key] ?? 0) + 1;
        }

        imagedestroy($image);

        if ($samples === []) {
            return null;
        }

        arsort($samples);

        return '#'.array_key_first($samples);
    }

    private static function normalizeHex(string $hex): string
    {
        $hex = ltrim($hex, '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        return '#'.strtolower(substr($hex, 0, 6));
    }
}
