<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\JobOpening;
use App\Services\BrandingAssetService;
use App\Support\InfoPages\CareersPage;
use App\Support\Seo\PublicUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Careers page (owner 2026-11-21): /careers (open jobs) and /careers/{slug} (one job + its application form). A closed
 * job keeps its page with a "closed" note so shared links never break; an old slug 301s to the current one; /jobs and
 * /jobs/{slug} 301 here with the query string kept.
 */
class CareersController extends Controller
{
    public function index(): View
    {
        abort_unless(CareersPage::enabled(), 404);

        return view('customer.careers', ['jobs' => JobOpening::query()->open()->get()]);
    }

    public function show(Request $request, string $slug): View|RedirectResponse
    {
        abort_unless(CareersPage::enabled(), 404);

        // A removed job keeps its page too (shown as closed): public links never break.
        $job = JobOpening::withTrashed()->where('slug', $slug)->first();
        if ($job === null) {
            $moved = JobOpening::fromOldSlug($slug);
            abort_if($moved === null, 404);

            return redirect()->to(PublicUrl::keepingQueryOf($moved->url(), $request), 301);
        }

        return view('customer.careers-job', [
            'job' => $job,
            'otherJobs' => JobOpening::query()->open()->whereKeyNot($job->id)->get(),
            'schema' => [CareersPage::jobSchema($job, app(BrandingAssetService::class)->url('logo_display_path'))],
        ]);
    }

    /** /jobs and /jobs/{slug} → /careers…, query string kept (UTM survives). */
    public function moved(Request $request, ?string $slug = null): RedirectResponse
    {
        $target = $slug === null ? route('customer.careers') : route('customer.careers.show', $slug);

        return redirect()->to(PublicUrl::keepingQueryOf($target, $request), 301);
    }
}
