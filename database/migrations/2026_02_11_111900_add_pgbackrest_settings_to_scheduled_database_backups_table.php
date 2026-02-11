<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('scheduled_database_backups', function (Blueprint $table) {
            $table->string('engine')->default('pg_dump')->nullable();
            $table->integer('pgbackrest_process_max')->default(2)->nullable();
            $table->string('pgbackrest_compress_type')->default('zstd')->nullable();
            $table->integer('pgbackrest_compress_level')->default(3)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('scheduled_database_backups', function (Blueprint $table) {
            $table->dropColumn([
                'pgbackrest_driver',
                'pgbackrest_process_max',
                'pgbackrest_compress_type',
                'pgbackrest_compress_level'
            ]);
        });
    }
};
