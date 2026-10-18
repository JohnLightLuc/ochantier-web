<?php

namespace App\Http\Controllers\Api\V1\Worker;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\AttendanceResource;
use App\Models\Attendance;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Pointages de l’ouvrier, enregistrés et validés par le promoteur.
 */
class AttendanceController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);

        return AttendanceResource::collection(
            Attendance::query()
                ->where('worker_id', $request->user()->id)
                ->when($request->date('from'), fn ($q, $from) => $q->whereDate('date', '>=', $from))
                ->when($request->date('to'), fn ($q, $to) => $q->whereDate('date', '<=', $to))
                ->with('site')
                ->latest('date')
                ->paginate(31)
        );
    }

    public function show(Request $request, Attendance $attendance): AttendanceResource
    {
        abort_unless($attendance->worker_id === $request->user()->id, 404);

        return AttendanceResource::make($attendance->load('site'));
    }
}
