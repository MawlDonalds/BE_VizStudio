<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChatSession;
use App\Models\ChatHistory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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

            $session = ChatSession::create([
                'user_id' => $user->id_user,
                'title' => $request->title,
                'created_by' => $user->username ?? $user->email,
                'created_at' => $now,
                'modified_by' => $user->username ?? $user->email,
                'modified_at' => $now
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Chat session created successfully',
                'data' => [
                    'session_id' => $session->id_chat_session,
                    'title' => $session->title,
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
                    'title',
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
     */
    public function getSessionHistory(Request $request, $sessionId)
    {
        try {
            $user = Auth::user();

            // Verify session belongs to user
            $session = ChatSession::where('id_chat_session', $sessionId)
                ->where('user_id', $user->id_user)
                ->first();

            if (!$session) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Chat session not found or access denied'
                ], 404);
            }

            // Get chat history
            $history = ChatHistory::where('session_id', $sessionId)
                ->orderBy('created_at', 'asc')
                ->select([
                    'id_history',
                    'history',
                    'created_at'
                ])
                ->get();

            return response()->json([
                'status' => 'success',
                'data' => [
                    'session' => [
                        'id' => $session->id_chat_session,
                        'title' => $session->title,
                        'created_at' => $session->created_at
                    ],
                    'messages' => $history->map(function ($item) {
                        return [
                            'id' => $item->id_history,
                            'messages' => $item->history,
                            'timestamp' => $item->created_at
                        ];
                    })
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
     */
    public function updateSession(Request $request, $sessionId)
    {
        $request->validate([
            'title' => 'required|string|max:255'
        ]);

        try {
            $user = Auth::user();

            $session = ChatSession::where('id_chat_session', $sessionId)
                ->where('user_id', $user->id_user)
                ->first();

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
                    'session_id' => $session->id_chat_session,
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
     */
    public function deleteSession(Request $request, $sessionId)
    {
        try {
            $user = Auth::user();

            $session = ChatSession::where('id_chat_session', $sessionId)
                ->where('user_id', $user->id_user)
                ->first();

            if (!$session) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Chat session not found or access denied'
                ], 404);
            }

            DB::transaction(function () use ($session) {
                // Delete chat history first (foreign key constraint)
                ChatHistory::where('session_id', $session->id_chat_session)->delete();
                
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
     */
    public function clearSessionHistory(Request $request, $sessionId)
    {
        try {
            $user = Auth::user();

            $session = ChatSession::where('id_chat_session', $sessionId)
                ->where('user_id', $user->id_user)
                ->first();

            if (!$session) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Chat session not found or access denied'
                ], 404);
            }

            ChatHistory::where('session_id', $sessionId)->delete();

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
}
