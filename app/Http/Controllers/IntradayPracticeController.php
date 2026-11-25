<?php

namespace App\Http\Controllers;

use App\Models\IntradayAlert;
use App\Services\IntradayPickService;
use App\Services\IntradayPracticeService;
use Illuminate\Http\Request;

class IntradayPracticeController extends Controller
{
    public function index(Request $request, IntradayPracticeService $practice, IntradayPickService $picks)
    {
        $report = $practice->report();

        if ($request->expectsJson()) {
            return response()->json($report);
        }

        return view('screener.practice', [
            'report' => $report,
            'picks' => $picks->today()->where('kind', IntradayAlert::SIGNAL),
            'trades' => IntradayAlert::where('kind', IntradayAlert::SIGNAL)
                ->orderByDesc('alert_date')->orderByDesc('id')->limit(200)->get(),
        ]);
    }
}
