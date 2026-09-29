<?php

use App\Enums\DemoRequestStatus;
use App\Models\DemoRequest;
use App\Models\PlatformAdmin;

test('the demo requests list shows requests and can mark one as contacted', function () {
    $admin = PlatformAdmin::factory()->create();
    $demoRequest = DemoRequest::factory()->create(['name' => 'Priya Sharma']);

    $index = $this->actingAs($admin, 'platform_admin')
        ->get('http://coaching.test/admin/demo-requests');

    $index->assertOk();
    $index->assertSee('Priya Sharma');
    $index->assertSee(__('platform.admin.demo_requests.statuses.new'));

    $response = $this->actingAs($admin, 'platform_admin')
        ->post("http://coaching.test/admin/demo-requests/{$demoRequest->id}/mark-contacted");

    $response->assertRedirect();

    expect($demoRequest->fresh()->status)->toBe(DemoRequestStatus::Contacted)
        ->and($demoRequest->fresh()->contacted_at)->not->toBeNull();
});

test('demo requests routes require a platform admin', function () {
    $response = $this->get('http://coaching.test/admin/demo-requests');

    $response->assertRedirect('http://coaching.test/admin/login');
});
