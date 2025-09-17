<?php

namespace App\Http\Services;

use App\Models\ChatSession;
use App\Models\ChatHistory;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class ChatService
{
    /**
     * Initialize or get existing chat session
     */
    public function initializeSession($sessionId = null, $title = null)
    {
        $user = Auth::user();
        
        if ($sessionId) {
            // Get existing session
            $session = ChatSession::where('id_chat_session', $sessionId)
                ->where('user_id', $user->id_user)
                ->first();
                
            if (!$session) {
                throw new \Exception('Chat session not found or access denied');
            }
            
            return $session;
        }
        
        // Create new session
        if (!$title) {
            $title = 'Chat Session ' . Carbon::now()->format('M d, Y H:i');
        }
        
        $session = ChatSession::create([
            'user_id' => $user->id_user,
            'title' => $title,
            'created_by' => $user->username ?? $user->email,
            'created_at' => Carbon::now(),
            'modified_by' => $user->username ?? $user->email,
            'modified_at' => Carbon::now()
        ]);
        
        return $session;
    }

    /**
     * Save chat message to history
     */
    public function saveMessage($sessionId, $messages, $messageType = 'conversation')
    {
        try {
            $user = Auth::user();
            $now = Carbon::now();

            // Verify session belongs to user
            $session = ChatSession::where('id_chat_session', $sessionId)
                ->where('user_id', $user->id_user)
                ->first();

            if (!$session) {
                throw new \Exception('Chat session not found or access denied');
            }

            // Prepare message data
            $historyData = [
                'messages' => $messages,
                'type' => $messageType,
                'timestamp' => $now->toISOString(),
                'user_id' => $user->id_user
            ];

            // Save to chat history
            $chatHistory = ChatHistory::create([
                'session_id' => $sessionId,
                'history' => $historyData,
                'created_by' => $user->username ?? $user->email,
                'created_at' => $now,
                'modified_by' => $user->username ?? $user->email,
                'modified_at' => $now
            ]);

            // Update session last activity
            $session->update([
                'modified_by' => $user->username ?? $user->email,
                'modified_at' => $now
            ]);

            return $chatHistory;

        } catch (\Exception $e) {
            Log::error('Failed to save chat message', [
                'error' => $e->getMessage(),
                'session_id' => $sessionId,
                'user_id' => Auth::id()
            ]);
            
            throw $e;
        }
    }

    /**
     * Get recent sessions for user
     */
    public function getRecentSessions($limit = 10)
    {
        $user = Auth::user();
        
        return ChatSession::where('user_id', $user->id_user)
            ->orderBy('modified_at', 'desc')
            ->limit($limit)
            ->select([
                'id_chat_session',
                'title',
                'created_at',
                'modified_at'
            ])
            ->get();
    }

    /**
     * Update session title based on first message
     */
    public function updateSessionTitleFromMessage($sessionId, $userMessage, $maxLength = 50)
    {
        try {
            $user = Auth::user();

            $session = ChatSession::where('id_chat_session', $sessionId)
                ->where('user_id', $user->id_user)
                ->first();

            if (!$session) {
                return false;
            }

            // Generate title from first user message
            $title = $this->generateTitleFromMessage($userMessage, $maxLength);
            
            $session->update([
                'title' => $title,
                'modified_by' => $user->username ?? $user->email,
                'modified_at' => Carbon::now()
            ]);

            return true;

        } catch (\Exception $e) {
            Log::error('Failed to update session title', [
                'error' => $e->getMessage(),
                'session_id' => $sessionId,
                'user_id' => Auth::id()
            ]);
            
            return false;
        }
    }

    /**
     * Generate session title from message content
     */
    private function generateTitleFromMessage($message, $maxLength = 50)
    {
        // Clean and truncate message for title
        $title = trim($message);
        $title = preg_replace('/\s+/', ' ', $title); // Replace multiple spaces with single space
        
        if (strlen($title) > $maxLength) {
            $title = substr($title, 0, $maxLength - 3) . '...';
        }
        
        return $title ?: 'New Chat Session';
    }

    /**
     * Get session statistics
     */
    public function getSessionStatistics($sessionId)
    {
        try {
            $user = Auth::user();

            $session = ChatSession::where('id_chat_session', $sessionId)
                ->where('user_id', $user->id_user)
                ->first();

            if (!$session) {
                throw new \Exception('Chat session not found or access denied');
            }

            $messageCount = ChatHistory::where('session_id', $sessionId)->count();
            
            $firstMessage = ChatHistory::where('session_id', $sessionId)
                ->orderBy('created_at', 'asc')
                ->first();
                
            $lastMessage = ChatHistory::where('session_id', $sessionId)
                ->orderBy('created_at', 'desc')
                ->first();

            return [
                'session_id' => $sessionId,
                'title' => $session->title,
                'message_count' => $messageCount,
                'created_at' => $session->created_at,
                'last_activity' => $session->modified_at,
                'first_message_at' => $firstMessage ? $firstMessage->created_at : null,
                'last_message_at' => $lastMessage ? $lastMessage->created_at : null
            ];

        } catch (\Exception $e) {
            Log::error('Failed to get session statistics', [
                'error' => $e->getMessage(),
                'session_id' => $sessionId,
                'user_id' => Auth::id()
            ]);
            
            throw $e;
        }
    }
}
