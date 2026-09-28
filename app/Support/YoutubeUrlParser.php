<?php

namespace App\Support;

class YoutubeUrlParser
{
    private const ID_PATTERN = '[A-Za-z0-9_-]{11}';

    public static function parseVideoId(string $url): ?string
    {
        $url = trim($url);

        if ($url === '') {
            return null;
        }

        if (! preg_match('#^https?://#i', $url)) {
            $url = 'https://'.$url;
        }

        $host = parse_url($url, PHP_URL_HOST);
        $path = parse_url($url, PHP_URL_PATH) ?? '';

        if ($host === null) {
            return null;
        }

        $host = strtolower(preg_replace('/^www\./i', '', $host));

        if ($host === 'youtu.be') {
            if (preg_match('#^/('.self::ID_PATTERN.')$#', $path, $matches)) {
                return $matches[1];
            }

            return null;
        }

        if (! in_array($host, ['youtube.com', 'm.youtube.com'], true)) {
            return null;
        }

        if (preg_match('#^/(shorts|embed)/('.self::ID_PATTERN.')$#', $path, $matches)) {
            return $matches[2];
        }

        if ($path === '/watch') {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

            if (isset($query['v']) && preg_match('#^'.self::ID_PATTERN.'$#', $query['v'])) {
                return $query['v'];
            }
        }

        return null;
    }
}
