<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The feedback / request inbox, as lightweight tickets.
 *
 * `feedback_items` holds the opening request (subject/message/type/status) and
 * the two read-state stamps that drive the unread badges in BOTH directions:
 *   - response_seen_at : when the SUBMITTER last read the thread
 *   - manager_seen_at  : when a MANAGER last read it
 * `feedback_messages` holds every reply after the opening post, from either
 * side, so a request is a conversation rather than a single canned response.
 * `context` keeps what the person was looking at when they filed it (search
 * query, page URL) so the request is actionable without a back-and-forth.
 *
 * Both tables are TENANT-OWNED: organization_id is nullable + indexed, exactly
 * like every other tenant table here, and the OrganizationScope does the
 * filtering. Relationship columns are plain indexed integers (no DB-level
 * foreign keys) so the schema is identical on SQLite (local) and MySQL (prod);
 * the application enforces the relationships (see the Feedback model, which
 * cascades message deletion itself).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('feedback_items')) {
            Schema::create('feedback_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('organization_id')->nullable()->index();
                $table->unsignedBigInteger('user_id')->nullable()->index();   // submitter
                $table->string('type')->default('question');   // question | feature_request | bug | missing_doc
                $table->string('subject');
                $table->text('message');
                $table->json('context')->nullable();           // { query, url }
                $table->string('status')->default('new');      // new | in_review | planned | done | declined
                $table->unsignedBigInteger('handled_by')->nullable()->index();
                $table->timestamp('handled_at')->nullable();
                $table->timestamp('response_seen_at')->nullable();
                $table->timestamp('manager_seen_at')->nullable();
                $table->timestamps();

                $table->index(['organization_id', 'status', 'created_at'], 'feedback_items_org_status_idx');
            });
        }

        if (! Schema::hasTable('feedback_messages')) {
            Schema::create('feedback_messages', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('organization_id')->nullable()->index();
                $table->unsignedBigInteger('feedback_item_id')->index();
                $table->unsignedBigInteger('user_id')->nullable()->index();   // author
                $table->text('body');
                $table->timestamps();

                $table->index(['feedback_item_id', 'created_at'], 'feedback_messages_thread_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('feedback_messages');
        Schema::dropIfExists('feedback_items');
    }
};
