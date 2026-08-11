<?php

declare(strict_types=1);

namespace Tests\Feature\User;

use App\Actions\User\DeleteUserAction;
use App\Models\AuditLog;
use App\Models\IpAllowlistEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserApiTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAllowlistedUser(): User
    {
        IpAllowlistEntry::factory()->create(['ip_address' => '127.0.0.1']);
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_index_returns_paginated_users(): void
    {
        $this->actingAsAllowlistedUser();
        User::factory()->count(3)->create();

        $response = $this->getJson('/api/users');

        $response->assertOk();
        // The acting user, the implicit approver `IpAllowlistEntry::factory()` creates for
        // `approved_by`, and 3 explicit factory users.
        $this->assertCount(5, $response->json('data'));
    }

    public function test_store_creates_user_sends_reset_link_and_hides_password(): void
    {
        Notification::fake();
        $actor = $this->actingAsAllowlistedUser();

        $response = $this->postJson('/api/users', [
            'name' => 'New Admin',
            'email' => 'new-admin@example.com',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.email', 'new-admin@example.com');
        $response->assertJsonMissingPath('data.password');

        $user = User::query()->where('email', 'new-admin@example.com')->sole();
        Notification::assertSentTo($user, \Illuminate\Auth\Notifications\ResetPassword::class);

        $log = AuditLog::query()->where('action', 'user.created')->sole();
        $this->assertSame($actor->id, $log->user_id);
        $this->assertSame($user->id, $log->subject_id);
    }

    public function test_store_rejects_duplicate_email(): void
    {
        $this->actingAsAllowlistedUser();
        User::factory()->create(['email' => 'taken@example.com']);

        $this->postJson('/api/users', [
            'name' => 'Someone',
            'email' => 'taken@example.com',
        ])->assertStatus(422);
    }

    public function test_update_changes_name_and_email(): void
    {
        $this->actingAsAllowlistedUser();
        $user = User::factory()->create();

        $response = $this->putJson("/api/users/{$user->id}", ['name' => 'Renamed']);

        $response->assertOk();
        $response->assertJsonPath('data.name', 'Renamed');
    }

    public function test_destroy_removes_a_different_user(): void
    {
        $this->actingAsAllowlistedUser();
        $other = User::factory()->create();

        $this->deleteJson("/api/users/{$other->id}")->assertNoContent();

        $this->assertDatabaseMissing('users', ['id' => $other->id]);
    }

    public function test_destroy_rejects_self_deletion(): void
    {
        $actor = $this->actingAsAllowlistedUser();
        User::factory()->create(); // ensure actor isn't the last user

        $this->deleteJson("/api/users/{$actor->id}")->assertStatus(422);

        $this->assertDatabaseHas('users', ['id' => $actor->id]);
    }

    /**
     * The "last user" guard can never be reached via the HTTP API in isolation from the
     * self-delete guard — Sanctum requires the actor to be a real persisted user, so if only one
     * user exists, that user IS necessarily the one making the request. Testing it therefore
     * means calling the Action directly with a non-persisted/unrelated $actor, which is the only
     * way to prove the guard exists without it being masked by the self-delete check.
     */
    public function test_delete_action_rejects_deleting_the_last_user(): void
    {
        $onlyUser = User::factory()->create();
        $unrelatedActor = User::factory()->make(['id' => 999999]);

        $this->expectException(ValidationException::class);

        (new DeleteUserAction())->handle($onlyUser, $unrelatedActor, '127.0.0.1');
    }

    public function test_requests_without_a_token_are_rejected(): void
    {
        IpAllowlistEntry::factory()->create(['ip_address' => '127.0.0.1']);

        $this->getJson('/api/users')->assertStatus(401);
    }

    public function test_requests_from_a_non_allowlisted_ip_are_rejected_even_when_authenticated(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/users')->assertStatus(403);
    }
}
