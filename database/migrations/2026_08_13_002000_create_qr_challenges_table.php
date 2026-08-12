<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// New table — zero existing QR/scan/pairing code anywhere in this
// codebase (confirmed by repo-wide search, see AUTH_FORENSIC_DISCOVERY.md).
// A QR challenge is a short-lived, single-use, purpose-bound token — never
// a permanent credential, never a password/API token embedded directly
// (see QR_SCAN_ARCHITECTURE.md's security section). `purpose` is a plain
// string (not a DB enum), matching the same reasoning
// service_categories.module and field_worker_capabilities.capability_type
// already established in this codebase — a new QR purpose (job-verification
// scan, identity confirmation, ...) is a routine addition later, not a
// migration.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qr_challenges', function (Blueprint $table) {
            $table->id();
            // Opaque, high-entropy, unguessable — this is what actually goes
            // inside the QR image. Never the primary key, never derivable
            // from anything predictable.
            $table->string('token', 64)->unique();
            // A SEPARATE secret, returned only in create()'s own HTTP
            // response, never rendered into the QR image — required to
            // poll status or retrieve the resulting session. Deliberately
            // not the same value as `token`: anyone who merely sees the QR
            // (a photo of the screen, a shoulder-surf) only gets the
            // scan/confirm value, never the value that could poll for or
            // steal the desktop's resulting session. See
            // QR_SCAN_ARCHITECTURE.md.
            $table->string('poll_token', 64)->unique();
            $table->string('purpose')->default('device_pairing');
            $table->enum('status', ['pending', 'confirmed', 'expired', 'revoked'])->default('pending');

            // The device/session that DISPLAYED the QR and is waiting for
            // it to be scanned (e.g. a desktop browser's session id) —
            // never a user identity, since nobody is authenticated on that
            // side yet; that's the entire point of this flow.
            $table->string('initiator_identifier')->nullable();
            $table->string('initiator_ip', 45)->nullable();

            // Set only once a real, already-authenticated mobile session
            // scans and confirms it.
            $table->foreignId('confirmed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('confirmed_device_identifier')->nullable();
            $table->string('confirmed_ip', 45)->nullable();

            // Free-form, purpose-specific context (e.g. which booking a
            // future job-verification-purpose challenge is scoped to) —
            // never anything sensitive; the row itself never carries a
            // password/permanent token.
            $table->json('payload')->nullable();

            // Set the moment the desktop's poll successfully retrieves the
            // resulting session token — a second poll after this is set
            // must never re-issue or re-reveal a token (one-time
            // retrieval, same "never becomes a reusable credential"
            // property as everything else about this challenge).
            $table->timestamp('session_claimed_at')->nullable();

            $table->timestamp('expires_at');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            $table->index(['purpose', 'status'], 'qr_challenges_purpose_status_idx');
            $table->index('expires_at', 'qr_challenges_expires_at_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qr_challenges');
    }
};
