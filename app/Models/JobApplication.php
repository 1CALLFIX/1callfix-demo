<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Someone who applied on the Careers page (owner 2026-11-21). One row per (mobile, job); a repeat submit updates it.
 * Status is set by the team on Admin → Job applications, never by the browser; a repeat submit keeps it. The CV is on the private disk.
 */
class JobApplication extends Model
{
    public const STATUSES = [
        'new' => 'New',
        'shortlisted' => 'Shortlisted',
        'interview' => 'Interview',
        'hired' => 'Hired',
        'rejected' => 'Rejected',
    ];

    public const EXPERIENCE = [
        'fresher' => 'Fresher',
        '1-2' => '1-2 years',
        '3-5' => '3-5 years',
        '5+' => '5+ years',
    ];

    protected $fillable = [
        'job_opening_id', 'name', 'phone', 'email', 'city', 'experience', 'current_work', 'two_wheeler', 'cv_path',
        'message', 'status', 'admin_notes', 'consent_at', 'consent_text', 'acquisition', 'submit_count',
    ];

    protected $casts = [
        'acquisition' => 'array',
        'consent_at' => 'datetime',
        'two_wheeler' => 'boolean',
        'submit_count' => 'integer',
    ];

    public function opening()
    {
        return $this->belongsTo(JobOpening::class, 'job_opening_id')->withTrashed();
    }

    public function jobTitle(): string
    {
        return $this->opening?->title ?? 'Any role';
    }

    /**
     * Create the application, or update the person's earlier one for the same job. Safe against two simultaneous
     * submits: the unique index decides, and the loser re-reads and updates. Returns [application, old CV path or null].
     *
     * @return array{0: self, 1: ?string}
     */
    public static function capture(array $d): array
    {
        $attempt = function () use ($d): array {
            return DB::transaction(function () use ($d) {
                $row = static::where('phone', $d['phone'])
                    ->when($d['job_opening_id'] === null, fn ($q) => $q->whereNull('job_opening_id'), fn ($q) => $q->where('job_opening_id', $d['job_opening_id']))
                    ->lockForUpdate()->first();

                if ($row === null) {
                    return [static::create($d + ['status' => 'new', 'consent_at' => now(), 'submit_count' => 1]), null];
                }

                $oldCv = null;
                $row->fill(collect($d)->except(['cv_path', 'acquisition'])->all());
                if (! empty($d['cv_path'])) {
                    $oldCv = $row->cv_path;
                    $row->cv_path = $d['cv_path'];
                }
                $row->acquisition = $row->acquisition ?: ($d['acquisition'] ?? null);
                $row->consent_at = now();
                $row->submit_count = $row->submit_count + 1;
                $row->save();

                return [$row, $oldCv];
            });
        };

        try {
            return $attempt();
        } catch (QueryException $e) {
            // Unique (phone, job) lost a race with an identical submit: the row now exists, update it.
            return $attempt();
        }
    }
}
