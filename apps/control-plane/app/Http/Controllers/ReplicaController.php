<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\ReplicaAgentResource;
use App\Models\ReplicaAgent;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ReplicaController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', ReplicaAgent::class);

        return ReplicaAgentResource::collection(
            ReplicaAgent::query()->orderBy('hostname')->paginate(15),
        );
    }
}
