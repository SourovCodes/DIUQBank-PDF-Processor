<?php

use Illuminate\Support\Facades\Route;

test('homepage redirects to docs', function (): void {
    $response = $this->get('/');

    $response->assertRedirect('/docs');
});

test('custom 404 page is branded', function (): void {
    config()->set('app.debug', false);

    $response = $this->get('/missing-page');

    $response
        ->assertNotFound()
        ->assertSee('Page not found')
        ->assertSee('Open API docs')
        ->assertSee('diuqbank.com');
});

test('custom 403 page is branded', function (): void {
    config()->set('app.debug', false);

    Route::get('/_error-test-403', function () {
        abort(403);
    });

    $response = $this->get('/_error-test-403');

    $response
        ->assertForbidden()
        ->assertSee('Access denied')
        ->assertSee('DIUQBank PDF Processor');
});

test('fallback 4xx error page is used for other client errors', function (): void {
    config()->set('app.debug', false);

    Route::get('/_error-test-418', function () {
        abort(418);
    });

    $response = $this->get('/_error-test-418');

    $response
        ->assertStatus(418)
        ->assertSee('Request could not be completed')
        ->assertSee('DIUQBank PDF Processor');
});

test('custom 500 page is branded', function (): void {
    config()->set('app.debug', false);

    Route::get('/_error-test-500', function () {
        abort(500);
    });

    $response = $this->get('/_error-test-500');

    $response
        ->assertStatus(500)
        ->assertSee('Server error')
        ->assertSee('Visit diuqbank.com');
});
