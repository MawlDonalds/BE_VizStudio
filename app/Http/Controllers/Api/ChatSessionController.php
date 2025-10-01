<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChatSession;
use App\Models\ChatHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Carbon\Carbon;

class ChatSessionController extends Controller
{
    /**
     * Create a new chat session
     */
    public function createSession(Request $request)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'datasource_id' => 'nullable|integer|exists:datasources,id_datasource'
        ]);

        try {
            $user = Auth::user();
            $now = Carbon::now();
            
            // Generate UUID for session_id to match FastAPI format
            $sessionUuid = (string) Str::uuid();

            $session = ChatSession::create([
                'session_id' => $sessionUuid,  // Use UUID string like FastAPI
                'user_id' => $user->id_user,
                'title' => $request->title,
                'datasource_id' => $request->datasource_id,
                'created_by' => $user->username ?? $user->email,
                'created_at' => $now,
                'modified_by' => $user->username ?? $user->email,
                'modified_at' => $now
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Chat session created successfully',
                'data' => [
                    'session_id' => $session->session_id,  // Return UUID string
                    'id_chat_session' => $session->id_chat_session, // Also return auto-increment ID for backward compatibility
                    'title' => $session->title,
                    'datasource_id' => $session->datasource_id,
                    'created_at' => $session->created_at,
                    'user_id' => $session->user_id
                ]
            ], 201);

        } catch (\Exception $e) {
            Log::error('Failed to create chat session', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to create chat session',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get all chat sessions for the authenticated user
     */
    public function listUserSessions(Request $request)
    {
        try {
            $user = Auth::user();
            
            $sessions = ChatSession::where('user_id', $user->id_user)
                ->orderBy('modified_at', 'desc')
                ->select([
                    'id_chat_session',
                    'session_id',  // Include UUID session_id
                    'title',
                    'datasource_id',
                    'created_at',
                    'modified_at'
                ])
                ->get();

            return response()->json([
                'status' => 'success',
                'data' => $sessions,
                'total' => $sessions->count()
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to list chat sessions', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve chat sessions',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get chat history for a specific session
     * Support both UUID session_id (from FastAPI) and integer id_chat_session
     * Uses the same chat_history table as FastAPI/LangChain
     */
    public function getSessionHistory(Request $request, $sessionId)
    {
        try {
            $user = Auth::user();

            // Try to find session by UUID session_id first (FastAPI format)
            $session = ChatSession::where('session_id', $sessionId)
                ->where('user_id', $user->id_user)
                ->first();

            // If not found by UUID, try by integer id_chat_session (backward compatibility)
            if (!$session && is_numeric($sessionId)) {
                $session = ChatSession::where('id_chat_session', $sessionId)
                    ->where('user_id', $user->id_user)
                    ->first();
            }

            if (!$session) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Chat session not found or access denied'
                ], 404);
            }

            // Get chat history using the ChatHistory model (same table as FastAPI)
            $history = ChatHistory::bySession($session->session_id)
                ->ordered()
                ->get()
                ->map(function ($item) {
                    return [
                        'id' => $item->id,
                        'type' => $item->message_type,
                        'content' => $item->message_content,
                        'timestamp' => $item->created_at,
                        'session_id' => $item->session_id
                    ];
                });

            return response()->json([
                'status' => 'success',
                'data' => [
                    'session' => [
                        'id' => $session->id_chat_session,
                        'session_id' => $session->session_id,
                        'title' => $session->title,
                        'datasource_id' => $session->datasource_id,
                        'created_at' => $session->created_at
                    ],
                    'messages' => $history,
                    'total_messages' => $history->count()
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to get session history', [
                'error' => $e->getMessage(),
                'session_id' => $sessionId,
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve session history',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update session title
     * Support both UUID session_id and integer id_chat_session
     */
    public function updateSession(Request $request, $sessionId)
    {
        $request->validate([
            'title' => 'required|string|max:255'
        ]);

        try {
            $user = Auth::user();

            // Try to find session by UUID session_id first
            $session = ChatSession::where('session_id', $sessionId)
                ->where('user_id', $user->id_user)
                ->first();

            // If not found by UUID, try by integer id_chat_session
            if (!$session && is_numeric($sessionId)) {
                $session = ChatSession::where('id_chat_session', $sessionId)
                    ->where('user_id', $user->id_user)
                    ->first();
            }

            if (!$session) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Chat session not found or access denied'
                ], 404);
            }

            $session->update([
                'title' => $request->title,
                'modified_by' => $user->username ?? $user->email,
                'modified_at' => Carbon::now()
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Session updated successfully',
                'data' => [
                    'id_chat_session' => $session->id_chat_session,
                    'session_id' => $session->session_id,
                    'title' => $session->title,
                    'modified_at' => $session->modified_at
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to update session', [
                'error' => $e->getMessage(),
                'session_id' => $sessionId,
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update session',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete a chat session and its history
     * Support both UUID session_id and integer id_chat_session
     */
    public function deleteSession(Request $request, $sessionId)
    {
        try {
            $user = Auth::user();

            // Try to find session by UUID session_id first
            $session = ChatSession::where('session_id', $sessionId)
                ->where('user_id', $user->id_user)
                ->first();

            // If not found by UUID, try by integer id_chat_session
            if (!$session && is_numeric($sessionId)) {
                $session = ChatSession::where('id_chat_session', $sessionId)
                    ->where('user_id', $user->id_user)
                    ->first();
            }

            if (!$session) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Chat session not found or access denied'
                ], 404);
            }

            DB::transaction(function () use ($session) {
                // Delete chat history using ChatHistory model (same table as FastAPI)
                ChatHistory::bySession($session->session_id)->delete();
                
                // Delete session
                $session->delete();
            });

            return response()->json([
                'status' => 'success',
                'message' => 'Chat session deleted successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to delete session', [
                'error' => $e->getMessage(),
                'session_id' => $sessionId,
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to delete session',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Clear chat history for a session (keep session, delete messages)
     * Support both UUID session_id and integer id_chat_session
     */
    public function clearSessionHistory(Request $request, $sessionId)
    {
        try {
            $user = Auth::user();

            // Try to find session by UUID session_id first
            $session = ChatSession::where('session_id', $sessionId)
                ->where('user_id', $user->id_user)
                ->first();

            // If not found by UUID, try by integer id_chat_session
            if (!$session && is_numeric($sessionId)) {
                $session = ChatSession::where('id_chat_session', $sessionId)
                    ->where('user_id', $user->id_user)
                    ->first();
            }

            if (!$session) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Chat session not found or access denied'
                ], 404);
            }

            DB::transaction(function () use ($session) {
                // Clear chat history using ChatHistory model (same table as FastAPI)
                ChatHistory::bySession($session->session_id)->delete();
            });

            $session->update([
                'modified_by' => $user->username ?? $user->email,
                'modified_at' => Carbon::now()
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Chat history cleared successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to clear session history', [
                'error' => $e->getMessage(),
                'session_id' => $sessionId,
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to clear session history',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get or create session by UUID (for FastAPI compatibility)
     * This method is called by FastAPI when a session_id is provided
     */
    public function getOrCreateSessionByUuid(Request $request, $sessionUuid)
    {
        try {
            $user = Auth::user();
            
            // Try to find existing session by UUID
            $session = ChatSession::where('session_id', $sessionUuid)->first();
            
            if ($session) {
                // Verify user owns the session
                if ($session->user_id !== $user->id_user) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Session access denied'
                    ], 403);
                }

                return response()->json([
                    'status' => 'success',
                    'data' => [
                        'session_id' => $session->session_id,
                        'id_chat_session' => $session->id_chat_session,
                        'title' => $session->title,
                        'datasource_id' => $session->datasource_id,
                        'user_id' => $session->user_id,
                        'created_at' => $session->created_at,
                        'is_new' => false
                    ]
                ]);
            }

            // Create new session if not exists
            $now = Carbon::now();
            $newSession = ChatSession::create([
                'session_id' => $sessionUuid,
                'user_id' => $user->id_user,
                'title' => 'New Chat Session',  // Default title
                'datasource_id' => $request->datasource_id,
                'created_by' => $user->username ?? $user->email,
                'created_at' => $now,
                'modified_by' => $user->username ?? $user->email,
                'modified_at' => $now
            ]);

            return response()->json([
                'status' => 'success',
                'data' => [
                    'session_id' => $newSession->session_id,
                    'id_chat_session' => $newSession->id_chat_session,
                    'title' => $newSession->title,
                    'datasource_id' => $newSession->datasource_id,
                    'user_id' => $newSession->user_id,
                    'created_at' => $newSession->created_at,
                    'is_new' => true
                ]
            ], 201);

        } catch (\Exception $e) {
            Log::error('Failed to get or create session by UUID', [
                'error' => $e->getMessage(),
                'session_uuid' => $sessionUuid,
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to get or create session',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get session statistics (for dashboard/analytics)
     */
    public function getSessionStats(Request $request, $sessionId)
    {
        try {
            $user = Auth::user();

            // Find session by UUID or ID
            $session = ChatSession::where('session_id', $sessionId)
                ->where('user_id', $user->id_user)
                ->first();

            if (!$session && is_numeric($sessionId)) {
                $session = ChatSession::where('id_chat_session', $sessionId)
                    ->where('user_id', $user->id_user)
                    ->first();
            }

            if (!$session) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Chat session not found or access denied'
                ], 404);
            }

            // Get message statistics
            $totalMessages = ChatHistory::bySession($session->session_id)->count();
            $humanMessages = ChatHistory::bySession($session->session_id)
                ->whereJsonContains('message->type', 'human')
                ->count();
            $aiMessages = ChatHistory::bySession($session->session_id)
                ->whereJsonContains('message->type', 'ai')
                ->count();
            
            $firstMessage = ChatHistory::bySession($session->session_id)
                ->orderBy('created_at', 'asc')
                ->first();
            $lastMessage = ChatHistory::bySession($session->session_id)
                ->orderBy('created_at', 'desc')
                ->first();

            return response()->json([
                'status' => 'success',
                'data' => [
                    'session' => [
                        'id' => $session->id_chat_session,
                        'session_id' => $session->session_id,
                        'title' => $session->title,
                        'datasource_id' => $session->datasource_id
                    ],
                    'stats' => [
                        'total_messages' => $totalMessages,
                        'human_messages' => $humanMessages,
                        'ai_messages' => $aiMessages,
                        'first_message_at' => $firstMessage?->created_at,
                        'last_message_at' => $lastMessage?->created_at,
                        'duration' => $firstMessage && $lastMessage 
                            ? $lastMessage->created_at->diffInMinutes($firstMessage->created_at) . ' minutes'
                            : null
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to get session stats', [
                'error' => $e->getMessage(),
                'session_id' => $sessionId,
                'user_id' => Auth::id()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to retrieve session statistics',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
