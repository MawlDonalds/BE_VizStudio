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
        try {
            // Generate embedding terlebih dahulu
            $embedding = $this->generateEmbedding($data['content']);
            
            if ($embedding === null) {
                throw new \Exception('Failed to generate embedding for content');
            }
            
            // Tambahkan embedding ke data
            $data['embedding'] = $embedding;
            
            // Simpan ke database sekaligus dengan embedding
            $knowledge = KnowledgeBase::create($data);

            return $knowledge;
        } catch (\Exception $e) {
            Log::error('Failed to store knowledge: ' . $e->getMessage());
            throw new \Exception('Failed to store knowledge: ' . $e->getMessage());
        }
    }

    public function update(KnowledgeBase $knowledge, array $data)
    {
        try {
            // Regenerate embedding jika content berubah
            if (isset($data['content'])) {
                $embedding = $this->generateEmbedding($data['content']);
                
                if ($embedding === null) {
                    throw new \Exception('Failed to generate embedding for updated content');
                }
                
                $data['embedding'] = $embedding;
            }
            
            $knowledge->update($data);
            return $knowledge;
        } catch (\Exception $e) {
            Log::error('Failed to update knowledge: ' . $e->getMessage());
            throw new \Exception('Failed to update knowledge: ' . $e->getMessage());
        }
    }

    public function destroy(KnowledgeBase $knowledge)
    {
        $knowledge->delete();
    }

    protected function generateEmbedding(string $content): ?string
    {
        try {
            $response = $this->client->post($this->fastApiUrl, [
                'json' => ['content' => $content],
            ]);

            $body = $response->getBody()->getContents();
            $decoded = json_decode($body, true);

            if (isset($decoded['embedding']) && is_array($decoded['embedding'])) {
                // Konversi ke format pgvector: [0.1,0.2,0.3,...]
                return '[' . implode(',', $decoded['embedding']) . ']';
            }

            throw new \Exception('Invalid embedding response: ' . $body);
        } catch (\Exception $e) {
            Log::error('Failed to generate embedding: ' . $e->getMessage());
            Log::error('Error details: ' . $e->getTraceAsString());
            return null; // Return null jika gagal
        }
    }
}