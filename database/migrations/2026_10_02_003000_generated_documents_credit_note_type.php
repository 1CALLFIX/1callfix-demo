<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * REF 1CF-CANCEL-POLICY-001 — a credit note is a numbered document of its own type. Both the document table and its
 * numbering-sequence table constrain `type` to the same enum, so both are widened together.
 *
 * up():   ['invoice','receipt']  ->  ['invoice','receipt','credit_note']   (every existing value kept; one added)
 * down(): REFUSES while any row uses 'credit_note' (documents are numbered legal records — never deleted or rewritten).
 */
return new class extends Migration
{
    private const TABLES = ['generated_documents', 'document_number_sequences'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->enum('type', ['invoice', 'receipt', 'credit_note'])->change();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            $rows = DB::table($table)->where('type', 'credit_note')->count();
            if ($rows > 0) {
                throw new \RuntimeException(
                    "Cannot roll back: {$rows} row(s) in {$table} use type 'credit_note'. Rolling back would delete or corrupt numbered "
                    .'credit notes. Export and remove them deliberately first, then roll back.'
                );
            }
        }

        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->enum('type', ['invoice', 'receipt'])->change();
            });
        }
    }
};
