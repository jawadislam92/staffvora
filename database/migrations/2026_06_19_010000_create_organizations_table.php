<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The tenant root. Every customer is one Organization; all tenant data is
 * scoped to it by organization_id (added to the other tables in a later
 * migration). owner_user_id is the "main person" who owns the account.
 * parent_org_id is reserved for a future agency/reseller hierarchy and is
 * unused in v1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();            // subdomain: acme.<product>.com
            $table->string('custom_domain')->nullable()->unique();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('trial');  // trial | active | past_due | suspended
            $table->string('display_name')->nullable();  // white-label product name shown in-app
            $table->string('logo_path')->nullable();      // per-org branding logo (S3)
            $table->string('accent_color', 9)->nullable()->default('#f97316');
            $table->foreignId('parent_org_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
