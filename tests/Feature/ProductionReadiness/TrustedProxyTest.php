<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    // A minimal probe that echoes the resolved IP and whether the request looks secure,
    // registered in the web group so it goes through TrustProxies middleware.
    Route::get('/proxy-probe', function (Request $request) {
        return response()->json([
            'ip' => $request->ip(),
            'secure' => $request->isSecure(),
        ]);
    })->middleware('web');
});

// Pick a Cloudflare IPv4 range that is in config/cloudflare.php: 173.245.48.0/20
// 173.245.48.1 is within that range and is a valid trusted proxy IP.
// A request with REMOTE_ADDR from a Cloudflare IP must honour X-Forwarded-For.
test('X-Forwarded-For from a Cloudflare IP is used as the client IP', function () {
    $this->get('http://coaching.test/proxy-probe', [
        'REMOTE_ADDR' => '173.245.48.1',
        'HTTP_X_FORWARDED_FOR' => '1.2.3.4',
    ])->assertOk()
        ->assertJson(['ip' => '1.2.3.4']);
});

test('X-Forwarded-For from a non-Cloudflare IP is ignored', function () {
    $this->get('http://coaching.test/proxy-probe', [
        'REMOTE_ADDR' => '5.5.5.5',  // not in Cloudflare ranges
        'HTTP_X_FORWARDED_FOR' => '1.2.3.4',
    ])->assertOk()
        ->assertJson(['ip' => '5.5.5.5']);  // REMOTE_ADDR, not the forged forwarded IP
});

test('HTTPS is detected from X-Forwarded-Proto when the request comes from a Cloudflare IP', function () {
    $this->get('http://coaching.test/proxy-probe', [
        'REMOTE_ADDR' => '173.245.48.1',
        'HTTP_X_FORWARDED_PROTO' => 'https',
    ])->assertOk()
        ->assertJson(['secure' => true]);
});

test('X-Forwarded-Proto from a non-Cloudflare IP does not force HTTPS detection', function () {
    $this->get('http://coaching.test/proxy-probe', [
        'REMOTE_ADDR' => '5.5.5.5',
        'HTTP_X_FORWARDED_PROTO' => 'https',
    ])->assertOk()
        ->assertJson(['secure' => false]);
});
