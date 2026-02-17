<?php

it('renders institutional sso login page', function () {
    $this->withoutVite();

    $response = $this->get('/admin/login');

    $response->assertStatus(200);
    $response->assertSee('Cuenta Institucional');
    $response->assertSee('/sso/login');
    $response->assertDontSee('name="data[email]"', false);
    $response->assertDontSee('name="data[password]"', false);
});

it('does not expose profile route in filament panel', function () {
    $this->get('/admin/profile')->assertNotFound();
});
