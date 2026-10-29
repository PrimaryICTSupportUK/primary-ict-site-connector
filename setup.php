<?php
/** Pairing UI helpers. Never persist the one-time code or put it in a URL. */
if (!defined('ABSPATH')) { exit; }
final class PICTS_Setup {
    private static function origin(string $url): ?string {
        $parts = parse_url($url);
        if (!$parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || !preg_match('/^[a-z0-9.-]+\.[a-z]{2,63}$/i', $parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || !empty($parts['path']) || (isset($parts['port']) && $parts['port'] !== 443)) { return null; }
        return 'https://' . strtolower($parts['host']);
    }
    public static function return_url(string $origin, ?string $path): ?string {
        $safe = self::origin($origin);
        if (!$safe) { return null; }
        if ($path === null || $path === '') { return $safe . '/dashboard'; }
        if (!preg_match('#^/dashboard/setup/[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$#i', $path)) { return null; }
        return $safe . $path;
    }
    public static function parse_details(string $raw): array {
        if (strlen($raw) > 8192) { throw new InvalidArgumentException('invalid'); }
        try { $value = json_decode($raw, true, 8, JSON_THROW_ON_ERROR); }
        catch (JsonException $e) { throw new InvalidArgumentException('invalid'); }
        $keys = ['format', 'service_url', 'site_id', 'pairing_code', 'expires_at', 'return_path'];
        if (!is_array($value) || count($value) !== count($keys) || array_diff(array_keys($value), $keys)) { throw new InvalidArgumentException('invalid'); }
        foreach ($keys as $key) { if (!isset($value[$key]) || !is_string($value[$key])) { throw new InvalidArgumentException('invalid'); } }
        if ($value['format'] !== 'picts-site-connector/1' || !self::origin($value['service_url']) || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value['site_id']) || !preg_match('/^picts_pair_[A-Za-z0-9_-]{43}$/', $value['pairing_code']) || !self::return_url($value['service_url'], $value['return_path']) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,6})?(Z|[+-]\d{2}:\d{2})$/', $value['expires_at'])) { throw new InvalidArgumentException('invalid'); }
        try { $expires = new DateTimeImmutable($value['expires_at']); }
        catch (Exception $e) { throw new InvalidArgumentException('invalid'); }
        if ($expires->getTimestamp() <= time()) { throw new InvalidArgumentException('expired'); }
        $value['service_url'] = self::origin($value['service_url']);
        $value['site_id'] = strtolower($value['site_id']);
        return $value;
    }
}
