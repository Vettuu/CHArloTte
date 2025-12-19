<?php

namespace App\Http\Controllers;

use App\Http\Requests\KnowledgeSearchRequest;
use App\Knowledge\KnowledgeSearchService;
use Illuminate\Http\JsonResponse;

class KnowledgeSearchController extends Controller
{
    public function __construct(private readonly KnowledgeSearchService $search)
    {
    }

    public function __invoke(KnowledgeSearchRequest $request): JsonResponse
    {
        $results = $this->search->search(
            $request->string('query')->toString(),
            $request->integer('limit', 3)
        );

        return response()->json(['data' => $results]);
    }
}
