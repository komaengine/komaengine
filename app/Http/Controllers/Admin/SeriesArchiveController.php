<?php

namespace App\Http\Controllers\Admin;

use App\Archives\ArchiveException;
use App\Archives\KomaArchiveV1;
use App\Archives\KomaArchiveValidator;
use App\Archives\SeriesArchiveExporter;
use App\Http\Controllers\Controller;
use App\Models\Series;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SeriesArchiveController extends Controller
{
    public function __invoke(
        Request $request,
        Series $series,
        SeriesArchiveExporter $exporter,
    ): BinaryFileResponse|RedirectResponse {
        Gate::authorize('edit_series');
        $schemaVersion = (int) $request->integer(
            'schema',
            (int) config('koma.archives.default_schema_version', 2),
        );

        if (! in_array($schemaVersion, KomaArchiveValidator::SUPPORTED_SCHEMA_VERSIONS, true)) {
            return back()->with('error', 'The requested Koma archive schema version is not supported.');
        }

        $directory = storage_path('app/archive-downloads');
        File::ensureDirectoryExists($directory);
        $destination = $directory.DIRECTORY_SEPARATOR.Str::uuid().'.koma.zip';

        try {
            $archive = $exporter->export($series, $destination, schemaVersion: $schemaVersion);
        } catch (ArchiveException $e) {
            report($e);

            return back()->with('error', 'Archive export failed: '.$e->getMessage());
        }

        return response()
            ->download($archive->path, $archive->downloadName, [
                'Content-Type' => KomaArchiveV1::MIME_TYPE,
                'X-Content-Type-Options' => 'nosniff',
                'X-Koma-Archive-Version' => (string) $schemaVersion,
            ])
            ->deleteFileAfterSend(true);
    }
}
