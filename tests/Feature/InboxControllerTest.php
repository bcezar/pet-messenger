<?php

namespace Tests\Feature;

use App\Models\ChatSession;
use App\Models\Company;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for InboxController
 * 
 * Validates:
 * - Multi-tenant isolation (no data leakage between companies)
 * - Lock mechanism prevents race conditions
 * - Status transitions work correctly
 * - Filters work as expected
 */
class InboxControllerTest extends TestCase
{
    use RefreshDatabase;

    private Company $company1;
    private Company $company2;
    private User $user1;
    private User $user2;
    private ChatSession $session1;
    private ChatSession $session2;

    protected function setUp(): void
    {
        parent::setUp();

        // Create two companies with users
        $this->company1 = Company::factory()->create();
        $this->company2 = Company::factory()->create();

        $this->user1 = User::factory()->create(['company_id' => $this->company1->id]);
        $this->user2 = User::factory()->create(['company_id' => $this->company2->id]);

        // Create sessions for each company
        $this->session1 = ChatSession::create([
            'company_id' => $this->company1->id,
            'client_phone' => '+5511999999991',
            'status' => ChatSession::STATUS_BOT,
        ]);

        $this->session2 = ChatSession::create([
            'company_id' => $this->company2->id,
            'client_phone' => '+5511999999992',
            'status' => ChatSession::STATUS_BOT,
        ]);
    }

    /** @test */
    public function it_lists_only_sessions_from_user_company(): void
    {
        $response = $this->actingAs($this->user1)
            ->getJson('/api/inbox');

        $response->assertOk();
        $data = $response->json('data');
        
        $this->assertCount(1, $data);
        $this->assertEquals($this->session1->id, $data[0]['id']);
    }

    /** @test */
    public function it_prevents_access_to_other_company_sessions(): void
    {
        // User1 tries to access session from company2
        $response = $this->actingAs($this->user1)
            ->getJson("/api/inbox/{$this->session2->id}");

        $response->assertNotFound();
    }

    /** @test */
    public function it_filters_sessions_by_status(): void
    {
        // Create human session
        $humanSession = ChatSession::create([
            'company_id' => $this->company1->id,
            'client_phone' => '+5511999999993',
            'status' => ChatSession::STATUS_HUMAN,
        ]);

        $response = $this->actingAs($this->user1)
            ->getJson('/api/inbox?status=human');

        $response->assertOk();
        $data = $response->json('data');
        
        $this->assertCount(1, $data);
        $this->assertEquals($humanSession->id, $data[0]['id']);
    }

    /** @test */
    public function it_filters_sessions_with_unread_messages(): void
    {
        // Add unread message to session1
        Message::create([
            'company_id' => $this->company1->id,
            'chat_session_id' => $this->session1->id,
            'channel' => Message::CHANNEL_WHATSAPP,
            'content' => 'Unread message',
            'direction' => Message::DIRECTION_INBOUND,
            'sender_type' => Message::SENDER_CLIENT,
            'status' => Message::STATUS_SENT,
            'client_phone' => $this->session1->client_phone,
        ]);

        $response = $this->actingAs($this->user1)
            ->getJson('/api/inbox?unread=true');

        $response->assertOk();
        $data = $response->json('data');
        
        $this->assertCount(1, $data);
        $this->assertEquals($this->session1->id, $data[0]['id']);
    }

    /** @test */
    public function it_locks_session_successfully(): void
    {
        $response = $this->actingAs($this->user1)
            ->postJson("/api/inbox/{$this->session1->id}/lock");

        $response->assertOk();
        $response->assertJson([
            'message' => 'Session locked successfully',
        ]);

        $this->session1->refresh();
        $this->assertTrue($this->session1->isLockedBy($this->user1->id));
        $this->assertEquals(ChatSession::STATUS_HUMAN, $this->session1->status);
    }

    /** @test */
    public function it_prevents_double_lock_by_different_users(): void
    {
        // User 1 locks the session
        $this->actingAs($this->user1)
            ->postJson("/api/inbox/{$this->session1->id}/lock");

        // User 2 from same company tries to lock
        $user3 = User::factory()->create(['company_id' => $this->company1->id]);
        
        $response = $this->actingAs($user3)
            ->postJson("/api/inbox/{$this->session1->id}/lock");

        $response->assertStatus(409);
        $response->assertJson([
            'message' => 'Session is already locked by another user',
        ]);
    }

    /** @test */
    public function it_allows_same_user_to_lock_again(): void
    {
        // First lock
        $this->actingAs($this->user1)
            ->postJson("/api/inbox/{$this->session1->id}/lock");

        // Same user locks again
        $response = $this->actingAs($this->user1)
            ->postJson("/api/inbox/{$this->session1->id}/lock");

        $response->assertOk();
        $response->assertJson([
            'message' => 'Session already locked by you',
        ]);
    }

    /** @test */
    public function it_resets_unread_count_when_locking(): void
    {
        // Add unread message
        Message::create([
            'company_id' => $this->company1->id,
            'chat_session_id' => $this->session1->id,
            'channel' => Message::CHANNEL_WHATSAPP,
            'content' => 'Unread message',
            'direction' => Message::DIRECTION_INBOUND,
            'sender_type' => Message::SENDER_CLIENT,
            'status' => Message::STATUS_SENT,
            'client_phone' => $this->session1->client_phone,
        ]);

        $this->session1->refresh();
        $this->assertEquals(1, $this->session1->unread_count);

        // Lock session
        $this->actingAs($this->user1)
            ->postJson("/api/inbox/{$this->session1->id}/lock");

        $this->session1->refresh();
        $this->assertEquals(0, $this->session1->unread_count);
    }

    /** @test */
    public function it_unlocks_session_by_owner(): void
    {
        // Lock first
        $this->actingAs($this->user1)
            ->postJson("/api/inbox/{$this->session1->id}/lock");

        // Unlock
        $response = $this->actingAs($this->user1)
            ->postJson("/api/inbox/{$this->session1->id}/unlock");

        $response->assertOk();
        $response->assertJson([
            'message' => 'Session unlocked successfully',
        ]);

        $this->session1->refresh();
        $this->assertFalse($this->session1->isLocked());
    }

    /** @test */
    public function it_prevents_unlock_by_non_owner(): void
    {
        // User 1 locks
        $this->actingAs($this->user1)
            ->postJson("/api/inbox/{$this->session1->id}/lock");

        // User 2 tries to unlock
        $user3 = User::factory()->create(['company_id' => $this->company1->id]);
        
        $response = $this->actingAs($user3)
            ->postJson("/api/inbox/{$this->session1->id}/unlock");

        $response->assertStatus(403);
        $response->assertJson([
            'message' => 'You cannot unlock this session',
        ]);
    }

    /** @test */
    public function it_forces_unlock_with_force_flag(): void
    {
        // User 1 locks
        $this->actingAs($this->user1)
            ->postJson("/api/inbox/{$this->session1->id}/lock");

        // User 2 force unlocks
        $user3 = User::factory()->create(['company_id' => $this->company1->id]);
        
        $response = $this->actingAs($user3)
            ->postJson("/api/inbox/{$this->session1->id}/unlock", ['force' => true]);

        $response->assertOk();
        
        $this->session1->refresh();
        $this->assertFalse($this->session1->isLocked());
    }

    /** @test */
    public function it_transfers_session_to_human(): void
    {
        $response = $this->actingAs($this->user1)
            ->postJson("/api/inbox/{$this->session1->id}/transfer-to-human");

        $response->assertOk();
        $response->assertJson([
            'message' => 'Session transferred to human attendance',
        ]);

        $this->session1->refresh();
        $this->assertEquals(ChatSession::STATUS_HUMAN, $this->session1->status);
    }

    /** @test */
    public function it_transfers_session_to_bot(): void
    {
        // First set to human
        $this->session1->update(['status' => ChatSession::STATUS_HUMAN]);

        $response = $this->actingAs($this->user1)
            ->postJson("/api/inbox/{$this->session1->id}/transfer-to-bot");

        $response->assertOk();
        $response->assertJson([
            'message' => 'Session transferred to bot attendance',
        ]);

        $this->session1->refresh();
        $this->assertEquals(ChatSession::STATUS_BOT, $this->session1->status);
        $this->assertNull($this->session1->locked_by_user_id);
    }

    /** @test */
    public function it_closes_session(): void
    {
        $response = $this->actingAs($this->user1)
            ->postJson("/api/inbox/{$this->session1->id}/close");

        $response->assertOk();
        $response->assertJson([
            'message' => 'Session closed successfully',
        ]);

        $this->session1->refresh();
        $this->assertEquals(ChatSession::STATUS_CLOSED, $this->session1->status);
        $this->assertNull($this->session1->locked_by_user_id);
    }

    /** @test */
    public function it_prevents_transfer_of_closed_sessions(): void
    {
        $this->session1->update(['status' => ChatSession::STATUS_CLOSED]);

        $response = $this->actingAs($this->user1)
            ->postJson("/api/inbox/{$this->session1->id}/transfer-to-human");

        $response->assertStatus(422);
        $response->assertJson([
            'message' => 'Cannot transfer a closed session',
        ]);
    }

    /** @test */
    public function it_shows_session_with_messages(): void
    {
        // Create messages
        Message::create([
            'company_id' => $this->company1->id,
            'chat_session_id' => $this->session1->id,
            'channel' => Message::CHANNEL_WHATSAPP,
            'content' => 'Message 1',
            'direction' => Message::DIRECTION_INBOUND,
            'sender_type' => Message::SENDER_CLIENT,
            'status' => Message::STATUS_SENT,
            'client_phone' => $this->session1->client_phone,
        ]);

        Message::create([
            'company_id' => $this->company1->id,
            'chat_session_id' => $this->session1->id,
            'channel' => Message::CHANNEL_WHATSAPP,
            'content' => 'Message 2',
            'direction' => Message::DIRECTION_OUTBOUND,
            'sender_type' => Message::SENDER_BOT,
            'status' => Message::STATUS_SENT,
            'client_phone' => $this->session1->client_phone,
        ]);

        $response = $this->actingAs($this->user1)
            ->getJson("/api/inbox/{$this->session1->id}");

        $response->assertOk();
        $data = $response->json();
        
        $this->assertCount(2, $data['messages']);
        $this->assertEquals('Message 1', $data['messages'][0]['content']);
        $this->assertEquals('Message 2', $data['messages'][1]['content']);
    }
}