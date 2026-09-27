<?php
declare(strict_types=1);

final class UrlTools
{
    public static function extractUrls(string $text): array
    {
        preg_match_all('~https?://[^\s<>"\']+~iu', $text, $m);
        $urls = [];

        foreach ($m[0] ?? [] as $url) {
            $url = rtrim($url, ".,;:!?)]}");
            if (filter_var($url, FILTER_VALIDATE_URL)) {
                $urls[] = $url;
            }
        }

        return array_values(array_unique($urls));
    }

    public static function platform(string $url): ?string
    {
        $host = strtolower((string)parse_url($url, PHP_URL_HOST));
        $host = preg_replace('/^www\./', '', $host);

        if ($host === '' || $host === null) {
            return null;
        }

        return match (true) {
            str_contains($host, 'shopee') => 'shopee',
            str_contains($host, 'mercadolivre') || str_contains($host, 'mercadolibre') || $host === 'meli.la' => 'mercadolivre',
            str_contains($host, 'magazineluiza') || str_contains($host, 'magalu') || str_contains($host, 'magazinevoce') => 'magalu',
            default => null,
        };
    }

    public static function resolve(string $url): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_HEADER => true,
            CURLOPT_NOBODY => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'Mozilla/5.0 Afiliabot/1.0',
        ]);

        $body = curl_exec($ch);
        $effective = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);

        return $effective !== '' ? $effective : $url;
    }

    public static function replaceUrl(string $text, string $old, string $new): string
    {
        return str_replace($old, $new, $text);
    }

    public static function appendQuery(string $url, array $params): string
    {
        $params = array_filter($params, static fn($v) => $v !== null && $v !== '');
        if (!$params) {
            return $url;
        }

        $fragment = '';
        if (($pos = strpos($url, '#')) !== false) {
            $fragment = substr($url, $pos);
            $url = substr($url, 0, $pos);
        }

        $separator = str_contains($url, '?') ? '&' : '?';
        return $url . $separator . http_build_query($params) . $fragment;
    }

    public static function applyTemplate(string $template, string $url, string $id): ?string
    {
        if (trim($template) === '') {
            return null;
        }

        return str_replace(
            ['{url}', '{id}'],
            [rawurlencode($url), rawurlencode($id)],
            $template
        );
    }
}
