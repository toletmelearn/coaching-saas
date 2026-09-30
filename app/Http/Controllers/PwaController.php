<?php

namespace App\Http\Controllers;

use App\Support\Branding\BrandingImageService;
use App\Support\Pwa\ServiceWorkerVersion;
use App\Support\TenantContext;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Per-tenant PWA endpoints: manifest, icons, header logo, service worker and the
 * offline fallback page. All are reached only via 'require.tenant' (404 on a central
 * domain) and the global CheckTenantSuspended middleware (503 while suspended) — see
 * routes/web.php. None of them require an authenticated tenant user.
 */
class PwaController extends Controller
{
    private const ICON_SIZES = ['180', '192', '512', '512-maskable'];

    public function __construct(private readonly TenantContext $tenantContext) {}

    public function manifest(): SymfonyResponse
    {
        $tenant = $this->tenantContext->get();

        $icon = function (string $size, string $purpose) use ($tenant) {
            $px = str_replace('-maskable', '', $size);

            return [
                'src' => "/pwa/icons/{$size}.png?v={$tenant->branding_version}",
                'sizes' => "{$px}x{$px}",
                'type' => 'image/png',
                'purpose' => $purpose,
            ];
        };

        $manifest = [
            'id' => '/',
            'name' => $tenant->name,
            'short_name' => mb_substr($tenant->name, 0, 12),
            'start_url' => '/?source=pwa',
            'scope' => '/',
            'display' => 'standalone',
            'orientation' => 'any',
            'theme_color' => $tenant->theme_color,
            'background_color' => '#ffffff',
            'lang' => 'en',
            'categories' => ['education'],
            'icons' => [
                $icon('192', 'any'),
                $icon('512', 'any'),
                $icon('512-maskable', 'maskable'),
            ],
        ];

        return response()->json($manifest)->header('Content-Type', 'application/manifest+json');
    }

    public function icon(string $sizeParam, BrandingImageService $images): SymfonyResponse
    {
        $size = str_ends_with($sizeParam, '.png') ? substr($sizeParam, 0, -4) : $sizeParam;

        if (! in_array($size, self::ICON_SIZES, true)) {
            abort(404);
        }

        $tenant = $this->tenantContext->get();

        return response($images->icon($tenant, $size), 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function logo(BrandingImageService $images): SymfonyResponse
    {
        $tenant = $this->tenantContext->get();

        return response($images->headerLogo($tenant), 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function serviceWorker(): SymfonyResponse
    {
        $source = file_get_contents(resource_path('js/sw.js'));

        $script = str_replace('__CACHE_VERSION__', $this->cacheVersion($source), $source);

        return response($script, 200, [
            'Content-Type' => 'application/javascript',
            'Cache-Control' => 'no-cache',
            'Service-Worker-Allowed' => '/',
        ]);
    }

    public function offline(): Response
    {
        $tenant = $this->tenantContext->get();

        return response(View::make('pwa.offline', ['tenant' => $tenant]));
    }

    private function cacheVersion(string $swSource): string
    {
        $manifestPath = public_path('build/manifest.json');
        $manifest = is_file($manifestPath) ? file_get_contents($manifestPath) : null;

        return ServiceWorkerVersion::compute($manifest === false ? null : $manifest, $swSource);
    }
}
