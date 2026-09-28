<?php

use App\Support\YoutubeUrlParser;

test('all accepted URL formats parse to the same video ID', function (string $url) {
    expect(YoutubeUrlParser::parseVideoId($url))->toBe('dQw4w9WgXcQ');
})->with([
    'youtube.com/watch?v=dQw4w9WgXcQ',
    'https://youtube.com/watch?v=dQw4w9WgXcQ',
    'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
    'https://www.youtube.com/watch?v=dQw4w9WgXcQ&list=PLxyz&t=10s',
    'youtu.be/dQw4w9WgXcQ',
    'https://youtu.be/dQw4w9WgXcQ',
    'https://youtu.be/dQw4w9WgXcQ?t=10',
    'youtube.com/shorts/dQw4w9WgXcQ',
    'https://www.youtube.com/shorts/dQw4w9WgXcQ',
    'youtube.com/embed/dQw4w9WgXcQ',
    'https://www.youtube.com/embed/dQw4w9WgXcQ',
    'https://m.youtube.com/watch?v=dQw4w9WgXcQ',
    'm.youtube.com/watch?v=dQw4w9WgXcQ',
]);

test('invalid YouTube URLs are rejected', function (string $url) {
    expect(YoutubeUrlParser::parseVideoId($url))->toBeNull();
})->with([
    'not a url at all',
    'https://vimeo.com/12345678',
    'https://example.com/watch?v=dQw4w9WgXcQ',
    'https://youtube.com/watch?v=short',
    'https://youtube.com/',
    '',
]);
