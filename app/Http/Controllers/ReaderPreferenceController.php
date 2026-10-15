<?php

namespace App\Http\Controllers;

use App\Models\Series;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ReaderPreferenceController extends Controller
{
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'mode' => ['required', 'string', Rule::in(Series::READER_MODES)],
        ]);

        $request->user()->forceFill([
            'reader_mode' => $validated['mode'],
        ])->save();

        return response()->json([
            'mode' => $request->user()->reader_mode,
        ]);
    }
}
