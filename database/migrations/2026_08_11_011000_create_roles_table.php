<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Final RBAC model, built once rather than as a throwaway. A Role is just a
// named bundle of Permissions (see create_permissions_table); WHERE a role
// applies to a given user is a separate concern, handled per-assignment by
// create_role_assignments_table — a role itself carries no scope.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            // System roles (Super Admin, Country Admin, City Admin, Zone Admin,
            // Franchise Owner, Operator, Support) ship with the app and can't
            // be deleted from the admin UI — only their permission set can be
            // adjusted. Custom roles an admin creates later are not system roles.
            $table->boolean('is_system')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
