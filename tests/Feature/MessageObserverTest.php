<?php

namespace Tests\Feature;

use App\Models\ChatSession;
use App\Models\Company;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for MessageObserver
 * 
 * Validates:
 * - unread_count increments correctly for inbound client messages
 * - unread_count does NOT increment for outbound or non-client messages
 * - unread_count does NOT increment on closed sessions
 * - last_message_at and last_message_preview update correctly
 */
class MessageObserverTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private ChatSession $session;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->user = User::factory()->create(['company_id' => $this->company->id]);
        $this->session = ChatSession::create([
            'company_id' => $this->company->id,
            'client_phone' => '+5511999999999',
            'status' => ChatSession::STATUS_BOT,
        ]);
    }

    /** @test */
    public function it_increments_unread_for_inbound_client_messages(): void
    {
        $this->assertEquals(0, $this->session->unread_count);

        // Create inbound client message
        Message::create([
            'company_id' => $this->company->id,
            'chat_session_id' => $this->session->id,
            'channel' => Message::CHANNEL_WHATSAPP,
            'content' => 'Hello from client',
            'direction' => Message::DIRECTION_INBOUND,
            'sender_type' => Message::SENDER_CLIENT,
            'status' => Message::STATUS_SENT,
            'client_phone' => $this->session->client_phone,
        ]);

        $this->session->refresh();
        $this->assertEquals(1, $this->session->unread_count);

        // Create another inbound client message
        Message::create([
            'company_id' => $this->company->id,
            'chat_session_id' => $this->session->id,
            'channel' => Message::CHANNEL_WHATSAPP,
            'content' => 'Second message',
            'direction' => Message::DIRECTION_INBOUND,
            'sender_type' => Message::SENDER_CLIENT,
            'status' => Message::STATUS_SENT,
            'client_phone' => $this->session->client_phone,
        ]);

        $this->session->refresh();
        $this->assertEquals(2, $this->session->unread_count);
    }

    /** @test */
    public function it_does_not_increment_unread_for_outbound_messages(): void
    {
        $this->assertEquals(0, $this->session->unread_count);

        // Create outbound message
        Message::create([
            'company_id' => $this->company->id,
            'chat_session_id' => $this->session->id,
            'user_id' => $this->user->id,
            'channel' => Message::CHANNEL_WHATSAPP,
            'content' => 'Response from bot',
            'direction' => Message::DIRECTION_OUTBOUND,
            'sender_type' => Message::SENDER_BOT,
            'status' => Message::STATUS_SENT,
            'client_phone' => $this->session->client_phone,
        ]);

        $this->session->refresh();
        $this->assertEquals(0, $this->session->unread_count);
    }

    /** @test */
    public function it_does_not_increment_unread_for_bot_messages(): void
    {
        $this->assertEquals(0, $this->session->unread_count);

        // Create bot message (even if inbound, which shouldn't happen but test defensive code)
        Message::create([
            'company_id' => $this->company->id,
            'chat_session_id' => $this->session->id,
            'channel' => Message::CHANNEL_WHATSAPP,
            'content' => 'Bot message',
            'direction' => Message::DIRECTION_INBOUND,
            'sender_type' => Message::SENDER_BOT,
            'status' => Message::STATUS_SENT,
            'client_phone' => $this->session->client_phone,
        ]);

        $this->session->refresh();
        $this->assertEquals(0, $this->session->unread_count);
    }

    /** @test */
    public function it_does_not_increment_unread_on_closed_sessions(): void
    {
        // Close the session
        $this->session->update(['status' => ChatSession::STATUS_CLOSED]);

        // Create inbound client message
        Message::create([
            'company_id' => $this->company->id,
            'chat_session_id' => $this->session->id,
            'channel' => Message::CHANNEL_WHATSAPP,
            'content' => 'Hello from client',
            'direction' => Message::DIRECTION_INBOUND,
            'sender_type' => Message::SENDER_CLIENT,
            'status' => Message::STATUS_SENT,
            'client_phone' => $this->session->client_phone,
        ]);

        $this->session->refresh();
        $this->assertEquals(0, $this->session->unread_count);
    }

    /** @test */
    public function it_updates_last_message_at_and_preview(): void
    {
        $this->assertNull($this->session->last_message_at);
        $this->assertNull($this->session->last_message_preview);

        $messageContent = 'This is a test message';
        
        Message::create([
            'company_id' => $this->company->id,
            'chat_session_id' => $this->session->id,
            'channel' => Message::CHANNEL_WHATSAPP,
            'content' => $messageContent,
            'direction' => Message::DIRECTION_INBOUND,
            'sender_type' => Message::SENDER_CLIENT,
            'status' => Message::STATUS_SENT,
            'client_phone' => $this->session->client_phone,
        ]);

        $this->session->refresh();
        $this->assertNotNull($this->session->last_message_at);
        $this->assertEquals($messageContent, $this->session->last_message_preview);
    }

    /** @test */
    public function it_truncates_long_message_preview(): void
    {
        $longMessage = str_repeat('a', 150);

        Message::create([
            'company_id' => $this->company->id,
            'chat_session_id' => $this->session->id,
            'channel' => Message::CHANNEL_WHATSAPP,
            'content' => $longMessage,
            'direction' => Message::DIRECTION_INBOUND,
            'sender_type' => Message::SENDER_CLIENT,
            'status' => Message::STATUS_SENT,
            'client_phone' => $this->session->client_phone,
        ]);

        $this->session->refresh();
        $this->assertEquals(100, mb_strlen($this->session->last_message_preview));
        $this->assertStringEndsWith('...', $this->session->last_message_preview);
    }
}