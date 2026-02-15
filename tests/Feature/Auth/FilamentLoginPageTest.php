<?php

it('renders google-only login page', function () {
    $this->withoutVite();

    $response = $this->get('/admin/login');

    $response->assertStatus(200);
    $response->assertSee('Entrar con Google');
    $response->assertSee('/auth/google/redirect');
    $response->assertDontSee('name="data[email]"', false);
    $response->assertDontSee('name="data[password]"', false);
});
