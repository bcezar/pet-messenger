<?php

namespace App\Http\Controllers;

use App\Models\ChatSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * InboxController handles human attendance operations
 * 
 * Architecture decisions:
 * - All queries MUST filter by company_id (multi-tenant enforced)
 * - Lock operations use optimistic locking to prevent race conditions
 * - unread_count is never updated manually, only through model methods
 * - Compatible with SQLite and MySQL
 * - Does not break bot compatibility
 */
class InboxController extends Controller
{
    /**
     * List chat sessions for inbox
     * 
     * Filters:
     * - status: bot, human, closed (optional)
     * - unread: boolean (optional)
     * - Always filtered by company_id
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(['bot', 'human', 'closed'])],
            'unread' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $user = Auth::user();
        $companyId = $user->company_id;

        // Start query with mandatory company filter
        $query = ChatSession::forCompany($companyId)
            ->with(['lockedBy:id,name', 'company:id,name'])
            ->latestMessages();

        // Apply optional filters
        if (isset($validated['status'])) {
            $query->byStatus($validated['status']);
        }

        if (isset($validated['unread']) && $validated['unread']) {
            $query->withUnread();
        }

        $sessions = $query->paginate($validated['per_page'] ?? 20);

        return response()->json($sessions);
    }

    /**
     * Show a single chat session with messages
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $user = Auth::user();
        $companyId = $user->company_id;

        $session = ChatSession::forCompany($companyId)
            ->with([
                'messages' => fn($q) => $q->orderBy('created_at', 'asc'),
                'lockedBy:id,name',
                'company:id,name',
            ])
            ->findOrFail($id);

        return response()->json($session);
    }

    /**
     * Lock a chat session for the current user
     * 
     * Uses optimistic locking to prevent race conditions:
     * - Only locks if session is unlocked
     * - Returns error if already locked by another user
     */
    public function lock(Request $request, int $id): JsonResponse
    {
        $user = Auth::user();
        $companyId = $user->company_id;

        // Fetch session with company filter
        $session = ChatSession::forCompany($companyId)->findOrFail($id);

        // Check if already locked by current user
        if ($session->isLockedBy($user->id)) {
            return response()->json([
                'message' => 'Session already locked by you',
                'session' => $session->fresh(['lockedBy:id,name']),
            ]);
        }

        // Attempt optimistic lock using raw query to prevent race condition
        $locked = DB::table('chat_sessions')
            ->where('id', $id)
            ->where('company_id', $companyId)
            ->whereNull('locked_by_user_id')
            ->update([
                'locked_by_user_id' => $user->id,
                'locked_at' => now(),
                'status' => ChatSession::STATUS_HUMAN,
                'updated_at' => now(),
            ]);

        if (!$locked) {
            // Refresh to get current lock info
            $session->refresh(['lockedBy:id,name']);
            
            return response()->json([
                'message' => 'Session is already locked by another user',
                'locked_by' => $session->lockedBy,
            ], 409);
        }

        // Mark messages as read when locking
        $session->refresh();
        $session->markAsRead();

        return response()->json([
            'message' => 'Session locked successfully',
            'session' => $session->fresh(['lockedBy:id,name']),
        ]);
    }

    /**
     * Unlock a chat session
     * 
     * Only the user who locked it can unlock (unless force = true)
     */
    public function unlock(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'force' => ['nullable', 'boolean'],
        ]);

        $user = Auth::user();
        $companyId = $user->company_id;
        $force = $validated['force'] ?? false;

        // Fetch session with company filter
        $session = ChatSession::forCompany($companyId)->findOrFail($id);

        // Check if session is locked
        if (!$session->isLocked()) {
            return response()->json([
                'message' => 'Session is not locked',
                'session' => $session,
            ]);
        }

        // Check permission to unlock
        if (!$force && !$session->isLockedBy($user->id)) {
            return response()->json([
                'message' => 'You cannot unlock this session',
                'locked_by' => $session->lockedBy,
            ], 403);
        }

        $session->unlock();

        return response()->json([
            'message' => 'Session unlocked successfully',
            'session' => $session->fresh(),
        ]);
    }

    /**
     * Transfer session to human attendance
     * 
     * Changes status from 'bot' to 'human'
     */
    public function transferToHuman(Request $request, int $id): JsonResponse
    {
        $user = Auth::user();
        $companyId = $user->company_id;

        // Fetch session with company filter
        $session = ChatSession::forCompany($companyId)->findOrFail($id);

        // Validate current status
        if ($session->status === ChatSession::STATUS_HUMAN) {
            return response()->json([
                'message' => 'Session is already in human attendance',
                'session' => $session,
            ]);
        }

        if ($session->status === ChatSession::STATUS_CLOSED) {
            return response()->json([
                'message' => 'Cannot transfer a closed session',
                'session' => $session,
            ], 422);
        }

        $session->transferToHuman();

        return response()->json([
            'message' => 'Session transferred to human attendance',
            'session' => $session->fresh(),
        ]);
    }

    /**
     * Transfer session back to bot
     * 
     * Changes status from 'human' to 'bot' and releases lock
     */
    public function transferToBot(Request $request, int $id): JsonResponse
    {
        $user = Auth::user();
        $companyId = $user->company_id;

        // Fetch session with company filter
        $session = ChatSession::forCompany($companyId)->findOrFail($id);

        // Validate current status
        if ($session->status === ChatSession::STATUS_BOT) {
            return response()->json([
                'message' => 'Session is already in bot attendance',
                'session' => $session,
            ]);
        }

        if ($session->status === ChatSession::STATUS_CLOSED) {
            return response()->json([
                'message' => 'Cannot transfer a closed session',
                'session' => $session,
            ], 422);
        }

        $session->transferToBot();

        return response()->json([
            'message' => 'Session transferred to bot attendance',
            'session' => $session->fresh(),
        ]);
    }

    /**
     * Close a chat session
     * 
     * Sets status to 'closed' and releases lock
     */
    public function close(Request $request, int $id): JsonResponse
    {
        $user = Auth::user();
        $companyId = $user->company_id;

        // Fetch session with company filter
        $session = ChatSession::forCompany($companyId)->findOrFail($id);

        // Check if already closed
        if ($session->status === ChatSession::STATUS_CLOSED) {
            return response()->json([
                'message' => 'Session is already closed',
                'session' => $session,
            ]);
        }

        $session->close();

        return response()->json([
            'message' => 'Session closed successfully',
            'session' => $session->fresh(),
        ]);
    }
}