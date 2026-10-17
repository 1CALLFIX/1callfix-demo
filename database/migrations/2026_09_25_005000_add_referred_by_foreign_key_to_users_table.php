<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * REF 1CF-PROMPT-20260925-EARN4 — approved migration (R9). users.referred_by
 * was created as a bare foreignId() with no constraint (2026_08_01_003000),
 * so a deleted referrer left a dangling id behind.
 *
 * Orphans: MySQL/MariaDB would refuse to add the constraint while any
 * referred_by points at a missing user. Per R9, only QaSeeder data may be
 * repaired automatically: an orphan on a "[QA] …" user is set to NULL
 * (exactly what nullOnDelete would have done; the referrals row, if any,
 * was already cascade-deleted with the referrer) and the ids are printed.
 * An orphan on ANY other user stops the migration with the ids listed and
 * nothing changed. Local audit before this migration: 5 users with
 * referred_by, 0 orphans (all QaSeeder data).
 */
return new class extends Migration
{
    public function up(): void
    {
        $orphans = DB::table('users as u')
            ->leftJoin('users as r', 'r.id', '=', 'u.referred_by')
            ->whereNotNull('u.referred_by')
            ->whereNull('r.id')
            ->get(['u.id', 'u.name']);

        // R9: stop if an orphan belongs to anyone but QaSeeder's "[QA] …" users.
        $real = $orphans->reject(fn ($u) => str_starts_with((string) $u->name, '[QA]'));
        if ($real->isNotEmpty()) {
            throw new RuntimeException(
                'Refusing to add users.referred_by foreign key: '.$real->count().' non-QA user(s) point at a deleted referrer '
                .'(ids '.$real->pluck('id')->implode(',').'). Decide what to do with them first; nothing was changed.'
            );
        }

        $orphans = $orphans->pluck('id');
        if ($orphans->isNotEmpty()) {
            DB::table('users')->whereIn('id', $orphans)->update(['referred_by' => null]);
            fwrite(STDOUT, "  users.referred_by: cleared {$orphans->count()} orphan(s) pointing at deleted users: ids ".$orphans->implode(',').PHP_EOL);
        }

        Schema::table('users', function (Blueprint $table) {
            $table->foreign('referred_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['referred_by']);
        });
    }
};
