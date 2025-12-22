<?php

namespace App\Support;

use Illuminate\Support\Str;

class TextNormalizer
{
    public static function forEmbedding(string $text): string
    {
        $normalized = Str::of($text)
            ->replace(["\r\n", "\r"], "\n")
            ->lower()
            ->ascii()
            ->replaceMatches('/[^a-z0-9\s\.\,\-\:\;\!\?]/', ' ')
            ->replaceMatches('/\s+/', ' ')
            ->trim()
            ->value();

        return $normalized !== '' ? $normalized : trim($text);
    }
}
