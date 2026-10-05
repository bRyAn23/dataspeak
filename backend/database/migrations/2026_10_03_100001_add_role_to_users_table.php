<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Data dictionary: users.role (enum user_role). Extends the framework users table.
 */
return new class extends Migration
{
    private const ROLES = ['USER', 'ADMIN'];

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 32)->default('USER')->after('password');
        });

        if (DB::getDriverName() === 'pgsql') {
            $list = implode(', ', array_map(fn ($v) => "'{$v}'", self::ROLES));
            DB::statement("ALTER TABLE users ADD CONSTRAINT chk_users_role CHECK (role IN ({$list}))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS chk_users_role');
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }
};
