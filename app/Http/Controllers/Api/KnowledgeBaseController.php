<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Services\KnowledgeBaseService;
use Illuminate\Http\Request;
use App\Models\KnowledgeBase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class KnowledgeBaseController extends Controller
{
    protected $knowledgeBaseService;

    public function __construct(KnowledgeBaseService $knowledgeBaseService)
    {
        $this->knowledgeBaseService = $knowledgeBaseService;
    }

    public function index(Request $request)
    {
        try {
            $user = Auth::user();
            if (!$user) {
                return response()->json(['error' => 'User not authenticated'], 401);
            }
            
            $perPage = $request->input('per_page', 10); // Default 10 row per halaman
            $query = KnowledgeBase::query();
            
            // Filter berdasarkan user yang sedang login
            $query->where('id_user', $user->id_user);
            
            if ($request->id_datasource) {
                $query->where('id_datasource', $request->id_datasource);
            }

            // Gunakan paginate untuk mengembalikan data dengan informasi paginasi
            $knowledge = $query->paginate($perPage);

            return response()->json($knowledge);
        } catch (\Exception $e) {
            Log::error('Failed to fetch knowledge: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json(['error' => 'Failed to fetch knowledge'], 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'id_datasource' => 'required|integer|exists:datasources,id_datasource',
                'entry_type' => 'required|string|max:50',
                'term' => 'required|string|max:255',
                'content' => 'required|string',
            ]);

            // Ambil user yang sedang login dari JWT token
            $user = Auth::user();
            if (!$user) {
                return response()->json(['error' => 'User not authenticated'], 401);
            }
            
            // Tambahkan id_user dari user yang authenticated
            $validated['id_user'] = $user->id_user;

            $knowledge = $this->knowledgeBaseService->store($validated);
            return response()->json($knowledge, 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('Validation failed: ' . json_encode($e->errors()), ['input' => $request->all()]);
            return response()->json(['error' => $e->errors()], 422);
        } catch (\Exception $e) {
            Log::error('Failed to store knowledge: ' . $e->getMessage(), [
                'input' => $request->all(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['error' => 'Failed to store knowledge: ' . $e->getMessage()], 500);
        }
    }

    public function show($id)
    {
        try {
            $user = Auth::user();
            if (!$user) {
                return response()->json(['error' => 'User not authenticated'], 401);
            }
            
            $knowledge = KnowledgeBase::findOrFail($id);
            
            // Pastikan user hanya bisa lihat knowledge yang mereka buat
            if ($knowledge->id_user !== $user->id_user) {
                return response()->json(['error' => 'Unauthorized - You can only view your own knowledge entries'], 403);
            }
            
            return response()->json($knowledge);
        } catch (\Exception $e) {
            Log::error('Failed to show knowledge: ' . $e->getMessage(), [
                'id' => $id,
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['error' => 'Failed to show knowledge'], 500);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $user = Auth::user();
            if (!$user) {
                return response()->json(['error' => 'User not authenticated'], 401);
            }
            
            $knowledge = KnowledgeBase::findOrFail($id);
            
            // Pastikan user hanya bisa update knowledge yang mereka buat
            if ($knowledge->id_user !== $user->id_user) {
                return response()->json(['error' => 'Unauthorized - You can only update your own knowledge entries'], 403);
            }

            $validated = $request->validate([
                'entry_type' => 'string|max:50',
                'term' => 'string|max:255',
                'content' => 'string',
            ]);

            $updated = $this->knowledgeBaseService->update($knowledge, $validated);
            return response()->json($updated);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('Validation failed: ' . json_encode($e->errors()), ['input' => $request->all()]);
            return response()->json(['error' => $e->errors()], 422);
        } catch (\Exception $e) {
            Log::error('Failed to update knowledge: ' . $e->getMessage(), [
                'id' => $id,
                'input' => $request->all(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['error' => 'Failed to update knowledge: ' . $e->getMessage()], 500);
        }
    }

    public function destroy($id)
    {
        try {
            $user = Auth::user();
            if (!$user) {
                return response()->json(['error' => 'User not authenticated'], 401);
            }
            
            $knowledge = KnowledgeBase::findOrFail($id);
            
            // Pastikan user hanya bisa delete knowledge yang mereka buat
            if ($knowledge->id_user !== $user->id_user) {
                return response()->json(['error' => 'Unauthorized - You can only delete your own knowledge entries'], 403);
            }
            
            $this->knowledgeBaseService->destroy($knowledge);
            return response()->json(null, 204);
        } catch (\Exception $e) {
            Log::error('Failed to delete knowledge: ' . $e->getMessage(), [
                'id' => $id,
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['error' => 'Failed to delete knowledge'], 500);
        }
    }
}