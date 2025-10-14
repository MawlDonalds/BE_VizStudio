<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Services\KnowledgeBaseService;
use Illuminate\Http\Request;
use App\Models\KnowledgeBase;
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
            $perPage = $request->input('per_page', 10); // Default 10 row per halaman
            $query = KnowledgeBase::query();
            
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
                'id_user' => 'required|integer|exists:users,id_user',
                'entry_type' => 'required|string|max:50',
                'term' => 'required|string|max:255',
                'content' => 'required|string',
            ]);

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

    public function update(Request $request, $id)
    {
        try {
            $knowledge = KnowledgeBase::findOrFail($id);

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
            $knowledge = KnowledgeBase::findOrFail($id);
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