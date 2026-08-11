<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Livewire\Users\Create;
use App\Livewire\Users\Edit;
use App\Livewire\Users\Index;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Password;
use Livewire\Livewire;
use Tests\TestCase;

class UsersTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_renders_existing_users(): void
    {
        $actor = User::factory()->create();
        $other = User::factory()->create();

        Livewire::actingAs($actor)
            ->test(Index::class)
            ->assertSee($other->email);
    }

    public function test_create_persists_a_new_user_and_sends_a_reset_link(): void
    {
        Password::shouldReceive('sendResetLink')->once()->andReturn(Password::RESET_LINK_SENT);
        $actor = User::factory()->create();

        Livewire::actingAs($actor)
            ->test(Create::class)
            ->set('name', 'New User')
            ->set('email', 'new-user@example.test')
            ->call('save')
            ->assertRedirect(route('users.index'));

        $this->assertDatabaseHas('users', ['email' => 'new-user@example.test']);
    }

    public function test_edit_updates_an_existing_user(): void
    {
        $actor = User::factory()->create();
        $target = User::factory()->create(['name' => 'Old Name']);

        Livewire::actingAs($actor)
            ->test(Edit::class, ['user' => $target])
            ->set('name', 'New Name')
            ->call('save')
            ->assertRedirect(route('users.index'));

        $this->assertSame('New Name', $target->fresh()->name);
    }

    public function test_delete_removes_a_different_user(): void
    {
        $actor = User::factory()->create();
        $target = User::factory()->create();

        Livewire::actingAs($actor)
            ->test(Index::class)
            ->call('delete', $target->id);

        $this->assertDatabaseMissing('users', ['id' => $target->id]);
    }
}
