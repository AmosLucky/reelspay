<?php

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(LazilyRefreshDatabase::class);

it('redirects guests from the admin library to login', function () {
    $this->get(route('tiktok-library.index'))
        ->assertRedirect(route('login'));
});

it('rejects invalid admin credentials', function () {
    $this->post(route('login.store'), [
        'email' => 'admin@gmail.com',
        'password' => 'incorrect',
    ])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('authenticates with the seeded admin credentials', function () {
    $this->seed();

    $this->post(route('login.store'), [
        'email' => 'admin@gmail.com',
        'password' => '12345678',
    ])
        ->assertRedirect(route('tiktok-library.index'));

    $this->assertAuthenticatedAs(User::where('email', 'admin@gmail.com')->firstOrFail());
    expect(Hash::check('12345678', User::where('email', 'admin@gmail.com')->value('password')))->toBeTrue();
});

it('logs out the authenticated admin', function () {
    $admin = User::factory()->create();

    $this->actingAs($admin)
        ->post(route('logout'))
        ->assertRedirect(route('login'));

    $this->assertGuest();
});
