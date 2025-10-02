<?php

namespace App\Http\Services;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;
use App\Models\KnowledgeBase;

class KnowledgeBaseService
{
    protected $client;
    protected $fastApiUrl;

    public function __construct()
    {
        $this->client = new Client([
            'timeout' => env('FASTAPI_TIMEOUT', 120),
            'verify' => false,
        ]);
        $this->fastApiUrl = env('FASTAPI_URL', 'http://localhost:8001') . '/api/v1/knowledge/embed';
    }

    public function store(array $data)
    {
        // Simpan ke database
        $knowledge = KnowledgeBase::create($data);

        // Panggil FastAPI untuk generate embedding
        $embedding = $this->generateEmbedding($knowledge->content);

        // Update embedding di database
        $knowledge->update(['embedding' => $embedding]);

        return $knowledge;
    }

    public function update(KnowledgeBase $knowledge, array $data)
    {
        $knowledge->update($data);

        // Regenerate embedding jika content berubah
        if (isset($data['content'])) {
            $embedding = $this->generateEmbedding($knowledge->content);
            $knowledge->update(['embedding' => $embedding]);
        }

        return $knowledge;
    }

    public function destroy(KnowledgeBase $knowledge)
    {
        $knowledge->delete();
    }

    protected function generateEmbedding(string $content): string
    {
        try {
            $response = $this->client->post($this->fastApiUrl, [
                'json' => ['content' => $content],
            ]);

            $body = $response->getBody()->getContents();
            $decoded = json_decode($body, true);

            if (isset($decoded['embedding'])) {
                // Simpan sebagai string '[0.1,0.2,...]' untuk pgvector
                return '[' . implode(',', $decoded['embedding']) . ']';
            }

            throw new \Exception('Invalid embedding response');
        } catch (\Exception $e) {
            Log::error('Failed to generate embedding: ' . $e->getMessage());
            return '[]'; // Fallback empty vector
        }
    }
}