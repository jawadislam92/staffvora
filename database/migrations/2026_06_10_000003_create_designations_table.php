<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('designations')) {
            Schema::create('designations', function (Blueprint $table) {
                $table->id();
                $table->string('name')->unique();
                $table->timestamps();
            });
        }

        $defaults = [
            'Admin',
            'Graphic Designer',
            'Video Editor',
            'Social Media Executive',
            'Content Writer',
            'Web Developer',
            'Project Manager',
            'Account Manager',
            'Virtual Assistant',
        ];

        foreach ($defaults as $designation) {
            DB::table('designations')->updateOrInsert(
                ['name' => $designation],
                ['updated_at' => now(), 'created_at' => now()]
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('designations');
    }
};
