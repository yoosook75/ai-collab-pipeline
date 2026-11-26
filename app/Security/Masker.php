<?php

namespace App\Security;

/**
 * Removes personal data and secrets before text leaves the server
 * (AI prompts, public GitHub issues).
 */
final class Masker
{
    /** Config keys whose exact values must never leave the server. */
    private const SECRET_CONFIG_KEYS = [
        'app.key',
        'database.connections.mysql.password',
        'database.connections.mariadb.password',
        'database.connections.pgsql.password',
        'mail.mailers.smtp.password',
        'ops.discord_webhook',
        'ops.slack_webhook',
        'ops.github.token',
    ];

    /** @var array<string, string> pattern => replacement, applied in order */
    private const RULES = [
        // user:pass@ inside URLs (mysql://acp:secret@db)
        '~(//)[^/\s:@]+:[^/\s@]+@~' => '$1[secret]@',
        '/[\w.+-]+@[\w-]+(?:\.[\w-]+)+/u' => '[email]',
        // KEY=value, "key":"value", 'key' => 'value', DB_PASSWORD=..., api-key: ...
        '/([\w-]*(?:api[_-]?key|secret|token|key|password|passwd|pwd)["\']?\s*(?:=>|=|:)\s*["\']?)[^\s"\',}]+/i' => '$1[secret]',
        '/(Authorization:\s*)(?:\w+\s+)?\S+/i' => '$1[secret]',
        '/\bBearer\s+\S+/i' => 'Bearer [secret]',
        '/\b(?:ghp|gho|ghs|ghu|ghr|github_pat)_[A-Za-z0-9_]+/' => '[secret]',
        '/(?=[A-Za-z0-9+\/=_-]*\d)(?=[A-Za-z0-9+\/=_-]*[A-Za-z])[A-Za-z0-9+\/=_-]{32,}/' => '[secret]',
        '/\b(?:\d{1,3}\.){3}\d{1,3}\b/' => '[ip]',
        '/(?<![\w:])(?=[0-9a-f:]*[0-9a-f])(?:[0-9a-f]{0,4}:){2,7}[0-9a-f]{0,4}(?![\w:])/i' => '[ip]',
    ];

    public static function mask(string $text): string
    {
        foreach (self::knownSecrets() as $secret) {
            $text = str_replace($secret, '[secret]', $text);
        }

        foreach (self::RULES as $pattern => $replacement) {
            $text = (string) preg_replace($pattern, $replacement, $text);
        }

        return $text;
    }

    /** @return list<string> */
    private static function knownSecrets(): array
    {
        if (! function_exists('app') || ! app()->bound('config')) {
            return [];
        }

        $values = [];
        foreach (self::SECRET_CONFIG_KEYS as $key) {
            $value = config($key);
            // Very short values would mask ordinary words; the regex rules still apply to them.
            if (is_string($value) && mb_strlen($value) >= 6) {
                $values[] = $value;
            }
        }

        return $values;
    }
}
