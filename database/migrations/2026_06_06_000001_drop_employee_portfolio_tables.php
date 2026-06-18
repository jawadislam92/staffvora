<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('employee_portfolio_items');
        Schema::dropIfExists('employee_portfolios');
    }

    public function down(): void
    {
        // Portfolio tables are intentionally not recreated because the feature was removed.
    }
};
