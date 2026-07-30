<?php

test('the home page requires authentication', function () {
    $this->get('/')->assertRedirect(route('login'));
});
