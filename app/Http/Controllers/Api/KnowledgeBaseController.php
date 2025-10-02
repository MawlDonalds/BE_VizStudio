<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Services\KnowledgeBaseService;
use Illuminate\Http\Request;
use App\Models\KnowledgeBase;

class KnowledgeBaseController extends Controller
{
    protected $knowledgeBaseService;

    public function __construct(KnowledgeBaseService $knowledgeBaseService)
    {
        $this->knowledgeBaseService = $knowledgeBaseService;
    }

    public function index(Request $request)
    {
        $query = KnowledgeBase::query();
        if ($request->id_datasource) {
            $query->where('id_datasource', $request->id_datasource);
        }
        return response()->json($query->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'id_datasource' => 'required|integer|exists:datasources,id_datasource',
            'id_user' => 'required|integer|exists:users,user_id',
            'entry_type' => 'required|string|max:50',
            'term' => 'required|string|max:255',
            'content' => 'required|string',
        ]);

        $knowledge = $this->knowledgeBaseService->store($validated);
        return response()->json($knowledge, 201);
    }

    public function update(Request $request, $id)
    {
        $knowledge = KnowledgeBase::findOrFail($id);

        $validated = $request->validate([
            'entry_type' => 'string|max:50',
            'term' => 'string|max:255',
            'content' => 'string',
        ]);

        $updated = $this->knowledgeBaseService->update($knowledge, $validated);
        return response()->json($updated);
    }

    public function destroy($id)
    {
        $knowledge = KnowledgeBase::findOrFail($id);
        $this->knowledgeBaseService->destroy($knowledge);
        return response()->json(null, 204);
    }
}