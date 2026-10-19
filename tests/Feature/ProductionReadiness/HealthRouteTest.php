<?php

test('/up returns 200 with ok status when database is reachable', function () {
    $this->get('/up')
        ->assertStatus(200)
        ->assertJson(['status' => 'ok'])
        ->assertJsonMissing(['exception'])
        ->assertJsonMissing(['trace'])
        ->assertJsonMissing(['password'])
        ->assertJsonMissing(['key']);
});

test('/up response contains no application secrets', function () {
    $body = $this->get('/up')->getContent();

    // Only assert against non-empty secrets; an empty string is contained in every string.
    $appKey = config('app.key');
    if ($appKey !== null && $appKey !== '') {
        expect($body)->not->toContain($appKey);
    }

    $dbPassword = config('database.connections.mysql.password');
    if ($dbPassword !== null && $dbPassword !== '') {
        expect($body)->not->toContain($dbPassword);
    }

    // Structural assertions: only the status field, nothing diagnostic
    expect($body)->not->toContain('exception')->not->toContain('trace');
});

test('/up is accessible without a tenant subdomain', function () {
    // The health route must be reachable from a central domain (no tenant context required).
    $this->get('http://'.config('tenancy.platform_domain').'/up')
        ->assertStatus(200)
        ->assertJson(['status' => 'ok']);
});
