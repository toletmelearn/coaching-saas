<?php

test('the platform landing page renders on the central domain with the marketing sections', function () {
    $response = $this->get('http://coaching.test/');

    $response->assertOk();
    $response->assertSee(__('platform.home.hero.heading'));
    $response->assertSee(__('platform.home.features.heading'));
    $response->assertSee(__('platform.home.how_it_works.heading'));
    $response->assertSee(__('platform.home.pricing.heading'));
    $response->assertSee(__('platform.home.pricing.body'));
    $response->assertSee(__('platform.home.demo_form.heading'));
});

test('the landing page also renders on 127.0.0.1', function () {
    $response = $this->get('http://127.0.0.1/');

    $response->assertOk();
    $response->assertSee(__('platform.home.hero.heading'));
});

test('the header shows the brand name from config(app.name) and an admin login link', function () {
    config(['app.name' => 'Totally Custom Brand']);

    $response = $this->get('http://coaching.test/');

    $response->assertOk();
    $response->assertSee('Totally Custom Brand', false);
    $response->assertSee(__('platform.admin_login_link'));
    $response->assertSee('href="http://coaching.test/admin/login"', false);
});
