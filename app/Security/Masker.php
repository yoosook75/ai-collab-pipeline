<?php

namespace App\Security;

/**
 * Removes personal data and secrets before text leaves the server
 * (AI prompts, public GitHub issues).
 */
final class Masker
{
    /** @var array<string, string> pattern => replacement, applied in order */
    private const RULES = [
        '/[\w.+-]+@[\w-]+(?:\.[\w-]+)+/u' => '[email]',
        '/\b(api[_-]?key|secret|token|key|password|passwd|pwd)\s*[=:]\s*\S+/i' => '$1=[secret]',
        '/\bBearer\s+\S+/i' => 'Bearer [secret]',
        '/(?=[A-Za-z0-9+\/=_-]*\d)(?=[A-Za-z0-9+\/=_-]*[A-Za-z])[A-Za-z0-9+\/=_-]{32,}/' => '[secret]',
        '/\b(?:\d{1,3}\.){3}\d{1,3}\b/' => '[ip]',
        '/(?<![\w:])(?=[0-9a-f:]*[0-9a-f])(?:[0-9a-f]{0,4}:){2,7}[0-9a-f]{0,4}(?![\w:])/i' => '[ip]',
    ];

    public static function mask(string $text): string
    {
        foreach (self::RULES as $pattern => $replacement) {
            $text = (string) preg_replace($pattern, $replacement, $text);
        }

        return $text;
    }
}
