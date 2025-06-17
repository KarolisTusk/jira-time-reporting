<?php

use App\Models\User;

test('returns a successful response', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/');

    $response->assertStatus(302); // Should redirect to dashboard
    $response->assertRedirect(route('dashboard'));
});
