<?php

test('the homepage redirects to the docs page', function () {
    $response = $this->get('/');

    $response->assertRedirect('/docs');
});
