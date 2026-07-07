<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Tribunal;

class TribunalController extends Controller
{
    public function index()
    {
        $tribunais = Tribunal::where('ativo', true)->get();
        return response()->json($tribunais);
    }

    public function show($uuid)
    {
        $tribunal = Tribunal::where('uuid', $uuid)->first();
        return response()->json($tribunal);
    }
}
