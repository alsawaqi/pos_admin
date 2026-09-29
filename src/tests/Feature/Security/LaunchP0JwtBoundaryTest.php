<?php

use App\Models\User;
use App\Services\Auth\JwtTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);
it('W13 refuses correctly signed JWTs from another issuer or audience', function (string $claim) {
    $service = app(JwtTokenService::class);
    $token = $service->issueFor(User::factory()->create())->accessToken;
    expect($service->decode($token))->toHaveKey('sub');
    config(['pos_admin_auth.jwt.'.$claim => 'different-application']);
    expect(fn () => $service->decode($token))->toThrow(RuntimeException::class, 'JWT issuer or audience is invalid.');
})->with(['issuer', 'audience']);
it('W13 rechecks platform user type on an open session', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->getJson('/auth/user')->assertOk();
    $user->update(['user_type' => 'merchant']);
    $this->getJson('/auth/user')->assertUnauthorized();
    $this->assertGuest();
});
