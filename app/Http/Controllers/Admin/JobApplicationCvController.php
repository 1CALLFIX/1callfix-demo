<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\JobApplication;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Careers (owner 2026-11-21): the only way an applicant's CV is opened — streamed from the PRIVATE disk after a
 * `careers.manage` check, and logged. 404 (not 403) when not allowed, like KycDocumentController.
 */
class JobApplicationCvController extends Controller
{
    public function show(int $application)
    {
        abort_unless(auth()->user()?->hasPermission('careers.manage'), 404);

        $row = JobApplication::find($application);
        abort_if(! $row || ! $row->cv_path || ! Storage::disk('local')->exists($row->cv_path), 404);

        ActivityLogger::log(auth()->user(), 'job_application', $row->id, 'Job application CV opened');

        return Storage::disk('local')->response($row->cv_path, 'CV-'.Str::slug($row->name).'.pdf', [
            'Content-Disposition' => 'inline',
            'Content-Type' => 'application/pdf',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
