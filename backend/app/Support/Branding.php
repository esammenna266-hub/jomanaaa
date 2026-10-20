<?php

namespace App\Support;

/**
 * The company logo for printed papers. The PDF engine renders HTML it is handed as a string, so the picture is
 * embedded in the page itself (a data URI) instead of being linked. Returns null until a logo file exists, and the
 * papers then show the company name alone.
 */
final class Branding
{
    private const TYPES = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'svg' => 'image/svg+xml', 'webp' => 'image/webp'];

    private static ?string $cached = null;

    private static bool $loaded = false;

    public static function logoDataUri(): ?string
    {
        if (self::$loaded) {
            return self::$cached;
        }

        self::$loaded = true;
        $path = (string) config('gomana.company.logo');
        $type = self::TYPES[strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? null;

        if ($path === '' || $type === null || ! is_file($path) || filesize($path) > 2_000_000) {
            return self::$cached = null;
        }

        return self::$cached = 'data:'.$type.';base64,'.base64_encode((string) file_get_contents($path));
    }

    /**
     * For tests and for the day the logo file is replaced while the server keeps running.
     */
    public static function forget(): void
    {
        self::$loaded = false;
        self::$cached = null;
    }
}
