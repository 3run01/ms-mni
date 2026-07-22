<?php

namespace App\Http\Controllers;

use App\Services\Dashboard\DashboardMetricasService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    private const PERIODOS_VALIDOS = [7, 30, 90];

    public function index(Request $request, DashboardMetricasService $metricas): Response
    {
        $periodo = (int) $request->query('periodo', 30);

        if (!in_array($periodo, self::PERIODOS_VALIDOS, true)) {
            $periodo = 30;
        }

        return Inertia::render('dashboard', [
            'periodo' => $periodo,
            // deferred: agregações em tabelas grandes não seguram o primeiro paint
            'metricas' => Inertia::defer(fn () => $metricas->metricas($periodo)),
        ]);
    }
}
