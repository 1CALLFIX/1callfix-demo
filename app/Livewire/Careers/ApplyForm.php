<?php

namespace App\Livewire\Careers;

use App\Livewire\Customer\Auth\Concerns\InteractsWithAuthThrottle;
use App\Models\JobApplication;
use App\Models\JobOpening;
use App\Support\Acquisition\AcquisitionContext;
use App\Support\InfoPages\CareersPage;
use App\Support\PhoneNumber;
use App\Support\SiteText;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * The application form on the Careers page (owner 2026-11-21), modelled on Partner\ApplyForm: name, mobile, city,
 * experience (chips), two-wheeler (only for jobs that need one), optional CV (PDF, private disk), consent. Server-side
 * validation, a honeypot, throttling per mobile and per IP, and the same "thanks" reply whether or not the number
 * applied before. Nothing personal goes in a URL. The team sees it on Admin → Job applications.
 */
class ApplyForm extends Component
{
    use InteractsWithAuthThrottle;
    use WithFileUploads;

    public const ANY = 'any';

    /** The job id as a string, or ANY. */
    public string $job = '';

    public string $name = '';

    public string $phone = '';

    public string $email = '';

    public string $city = 'Nellore';

    public string $experience = '';

    public string $currentWork = '';

    /** '' | 'yes' | 'no' */
    public string $twoWheeler = '';

    public $cv = null;

    public string $message = '';

    public bool $consent = false;

    /** Honeypot: real people never see or fill this. */
    public string $website = '';

    public string $error = '';

    #[Locked]
    public bool $done = false;

    public function mount(?int $jobId = null): void
    {
        $this->job = $jobId && JobOpening::query()->open()->whereKey($jobId)->exists() ? (string) $jobId : self::ANY;
        if ($user = auth()->user()) {
            $this->name = (string) $user->name;
            $this->phone = (string) $user->phone;
        }
    }

    private function openJobs()
    {
        return JobOpening::query()->open()->get(['id', 'title', 'needs_two_wheeler']);
    }

    private function chosenJob(): ?JobOpening
    {
        return $this->job === self::ANY ? null : $this->openJobs()->firstWhere('id', (int) $this->job);
    }

    public function again(): void
    {
        $this->reset(['email', 'experience', 'currentWork', 'twoWheeler', 'cv', 'message', 'consent', 'website', 'error', 'done']);
        $this->job = self::ANY;
        $this->resetErrorBag();
    }

    public function submit(): void
    {
        $this->error = '';
        $jobs = $this->openJobs();
        $chosen = $this->chosenJob();
        $nameRule = "regex:/^[\\p{L}\\p{M}][\\p{L}\\p{M} .'\\-]*$/u";

        $this->validate([
            'job' => ['required', Rule::in(array_merge([self::ANY], $jobs->pluck('id')->map(fn ($id) => (string) $id)->all()))],
            'name' => ['required', 'string', 'min:2', 'max:120', $nameRule],
            'phone' => ['required', 'string', 'max:20', 'regex:/^[0-9+][0-9 \\-]*$/'],
            'email' => ['nullable', 'email:rfc', 'max:150'],
            'city' => ['required', 'string', 'min:2', 'max:120', $nameRule],
            'experience' => ['required', Rule::in(array_keys(JobApplication::EXPERIENCE))],
            'currentWork' => ['nullable', 'string', 'max:150'],
            'twoWheeler' => [Rule::requiredIf((bool) $chosen?->needs_two_wheeler), 'nullable', Rule::in(['yes', 'no'])],
            'cv' => ['nullable', 'file', 'mimes:pdf', 'mimetypes:application/pdf', 'max:'.CareersPage::CV_MAX_KB],
            'message' => ['nullable', 'string', 'max:1000'],
            'consent' => ['accepted'],
        ], [
            'job.required' => 'Choose a job.', 'job.in' => 'Choose a job.',
            'name.required' => 'Enter your full name.', 'name.regex' => 'Enter your name.',
            'phone.required' => 'Enter your mobile number.', 'phone.regex' => 'Enter a valid mobile number.',
            'email.email' => 'Enter a valid email, or leave it empty.',
            'city.required' => 'Enter your city.', 'city.regex' => 'Enter your city.',
            'experience.required' => 'Pick your work experience.', 'experience.in' => 'Pick your work experience.',
            'twoWheeler.required' => 'Tell us if you have a two-wheeler.',
            'cv.mimes' => 'The CV must be a PDF file.', 'cv.mimetypes' => 'The CV must be a PDF file.',
            'cv.max' => 'The CV must be 5 MB or smaller.', 'cv.uploaded' => 'The CV could not be uploaded. Try a PDF of 5 MB or smaller.',
            'consent.accepted' => 'Please tick the box to continue.',
        ]);

        if (! PhoneNumber::looksValid($this->phone)) {
            $this->addError('phone', 'Enter a valid 10-digit mobile number.');

            return;
        }

        // A bot that fills the hidden field gets the same friendly screen and nothing is saved.
        if ($this->website !== '') {
            $this->done = true;

            return;
        }

        $national = PhoneNumber::national($this->phone);
        if ($this->isThrottled('job-apply', $national, maxPerIdentifier: 5, maxPerIp: 30)) {
            return;
        }
        $this->hitThrottle('job-apply', $national, 3600);

        $cvPath = null;
        try {
            if ($this->cv) {
                $cvPath = $this->cv->storeAs(CareersPage::CV_DIR, Str::uuid().'.pdf', 'local') ?: null;
            }

            [, $oldCv] = JobApplication::capture([
                'job_opening_id' => $chosen?->id,
                'name' => trim($this->name),
                'phone' => $national,
                'email' => trim($this->email) ?: null,
                'city' => trim($this->city),
                'experience' => $this->experience,
                'current_work' => trim($this->currentWork) ?: null,
                'two_wheeler' => $this->twoWheeler === '' ? null : $this->twoWheeler === 'yes',
                'cv_path' => $cvPath,
                'message' => trim($this->message) ?: null,
                'consent_text' => SiteText::get('careers.form.consent'),
                'acquisition' => AcquisitionContext::current(),
            ]);
            if ($oldCv && $oldCv !== $cvPath) {
                Storage::disk('local')->delete($oldCv);
            }
        } catch (\Throwable $e) {
            report($e);
            if ($cvPath) {
                Storage::disk('local')->delete($cvPath);
            }
            $this->error = 'Something went wrong. Please try again.';

            return;
        }

        $this->cv = null;
        $this->done = true;
    }

    public function render()
    {
        $chosen = $this->chosenJob();

        return view('livewire.careers.apply-form', [
            'jobs' => $this->openJobs(),
            'chosen' => $chosen,
            'experienceOptions' => JobApplication::EXPERIENCE,
            't' => fn (string $key, array $r = []) => SiteText::get($key, $r),
        ]);
    }
}
