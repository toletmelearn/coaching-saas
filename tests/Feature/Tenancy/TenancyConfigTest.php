<?php

test('central domains config contains the default local hostnames', function () {
    expect(config('tenancy.central_domains'))
        ->toBeArray()
        ->toContain('coaching.test')
        ->toContain('platform.coaching.test')
        ->toContain('localhost')
        ->toContain('127.0.0.1');
});
