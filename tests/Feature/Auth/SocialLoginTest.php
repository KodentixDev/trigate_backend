<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Kreait\Firebase\Contract\Auth as FirebaseAuth;
use Lcobucci\JWT\Token\DataSet;
use Lcobucci\JWT\UnencryptedToken;
use Mockery;
use Tests\TestCase;

class SocialLoginTest extends TestCase
{
    use RefreshDatabase;

    private function fakeVerifiedToken(array $claims): UnencryptedToken
    {
        $token = Mockery::mock(UnencryptedToken::class);
        $token->shouldReceive('claims')->andReturn(new DataSet($claims, ''));

        return $token;
    }

    public function test_it_creates_a_new_user_on_first_social_login(): void
    {
        $claims = [
            'sub' => 'firebase-uid-123',
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'email_verified' => true,
            'picture' => 'https://example.com/avatar.png',
            'firebase' => ['sign_in_provider' => 'google.com'],
        ];

        $firebaseAuth = Mockery::mock(FirebaseAuth::class);
        $firebaseAuth->shouldReceive('verifyIdToken')
            ->once()
            ->with('valid-token')
            ->andReturn($this->fakeVerifiedToken($claims));

        $this->mock(\Kreait\Laravel\Firebase\FirebaseProjectManager::class, function ($mock) use ($firebaseAuth) {
            $mock->shouldReceive('project')->andReturnSelf();
            $mock->shouldReceive('auth')->andReturn($firebaseAuth);
        });

        $response = $this->postJson('/api/auth/social-login', ['id_token' => 'valid-token']);

        $response->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'firebase_uid', 'name', 'email']]);

        $this->assertDatabaseHas('users', [
            'firebase_uid' => 'firebase-uid-123',
            'email' => 'ada@example.com',
            'auth_provider' => 'google.com',
        ]);
    }

    public function test_it_reuses_the_existing_user_on_subsequent_logins(): void
    {
        $existing = User::factory()->create(['firebase_uid' => 'firebase-uid-123']);

        $claims = [
            'sub' => 'firebase-uid-123',
            'name' => $existing->name,
            'email' => $existing->email,
            'email_verified' => true,
            'picture' => null,
            'firebase' => ['sign_in_provider' => 'apple.com'],
        ];

        $firebaseAuth = Mockery::mock(FirebaseAuth::class);
        $firebaseAuth->shouldReceive('verifyIdToken')
            ->once()
            ->andReturn($this->fakeVerifiedToken($claims));

        $this->mock(\Kreait\Laravel\Firebase\FirebaseProjectManager::class, function ($mock) use ($firebaseAuth) {
            $mock->shouldReceive('project')->andReturnSelf();
            $mock->shouldReceive('auth')->andReturn($firebaseAuth);
        });

        $response = $this->postJson('/api/auth/social-login', ['id_token' => 'valid-token']);

        $response->assertOk();
        $this->assertSame(1, User::count());
        $this->assertDatabaseHas('users', [
            'id' => $existing->id,
            'auth_provider' => 'apple.com',
        ]);
    }

    public function test_it_rejects_an_invalid_firebase_token(): void
    {
        $firebaseAuth = Mockery::mock(FirebaseAuth::class);
        $firebaseAuth->shouldReceive('verifyIdToken')
            ->once()
            ->andThrow(new \Kreait\Firebase\Exception\Auth\FailedToVerifyToken('invalid'));

        $this->mock(\Kreait\Laravel\Firebase\FirebaseProjectManager::class, function ($mock) use ($firebaseAuth) {
            $mock->shouldReceive('project')->andReturnSelf();
            $mock->shouldReceive('auth')->andReturn($firebaseAuth);
        });

        $response = $this->postJson('/api/auth/social-login', ['id_token' => 'bad-token']);

        $response->assertStatus(401);
    }
}
