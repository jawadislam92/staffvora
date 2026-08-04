<?php

namespace App\Tenancy;

use App\Models\Organization;

/**
 * Request-scoped holder for the active tenant. Bound as a container singleton
 * so every consumer (the global scope, the auto-fill hook, controllers) shares
 * one value per request/command.
 *
 * It is set explicitly — from the authenticated user (web/API, via the
 * Authenticated event + middleware) or by a background job looping tenants. The
 * value is a plain id and reading it never touches the database or the auth
 * guard, so the OrganizationScope can never recurse while loading a model.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * TWO DIFFERENT QUESTIONS, TWO DIFFERENT METHODS
 * ─────────────────────────────────────────────────────────────────────────────
 * These are NOT the same question and conflating them re-opened a cross-tenant
 * Slack leak:
 *
 *   • "Which organization_id should this QUERY be filtered by?" → id().
 *     Inside runUnscoped() the answer is deliberately NULL: the caller asked to
 *     read across tenants.
 *
 *   • "Is this code running on behalf of a tenant?" → check() / boundId().
 *     runUnscoped() does NOT change the answer. A landlord query wrapped around
 *     a tenant's work does not turn that work into platform work.
 *
 * check() used to answer the first question, so `runFor($org, fn () =>
 * runUnscoped(fn () => $slack->configured()))` reported "no tenant bound" and
 * SlackBotService fell through to the OPERATOR's platform bot token while a
 * tenant was very much bound. Anything making a SECURITY decision ("whose
 * credential/channel/settings row is this?") must use check()/boundId(); only
 * query scoping may use id().
 */
class CurrentOrganization
{
    private ?int $id = null;

    /** When true, scoping is disabled regardless of the bound id (platform/landlord queries). */
    private bool $unscoped = false;

    public function set(int|Organization|null $organization): void
    {
        $this->id = $organization instanceof Organization ? $organization->id : $organization;
    }

    /**
     * The organization id QUERIES should be filtered by, or null when scoping is
     * off (no tenant bound, or inside runUnscoped()). Never use this to decide
     * whose credentials/settings apply — use boundId() for that.
     */
    public function id(): ?int
    {
        return $this->unscoped ? null : $this->id;
    }

    /**
     * The bound tenant id REGARDLESS of query scoping — the answer to "on whose
     * behalf is this running?". This is the id a credential, a Slack channel or
     * a settings row must be resolved against.
     */
    public function boundId(): ?int
    {
        return $this->id;
    }

    /** Is a tenant bound at all? True inside runUnscoped() when one is. */
    public function check(): bool
    {
        return $this->id !== null;
    }

    /** Is tenant query scoping currently in force? False inside runUnscoped(). */
    public function scoped(): bool
    {
        return ! $this->unscoped && $this->id !== null;
    }

    public function forget(): void
    {
        $this->id = null;
    }

    /**
     * Run a callback with tenant scoping disabled (e.g. the landlord console
     * listing every organization's data). Restores the previous state after.
     *
     * This suspends QUERY SCOPING only. Any tenant that was bound stays bound as
     * far as check()/boundId() are concerned, so a credential lookup inside this
     * block still resolves that tenant and never the platform default.
     */
    public function runUnscoped(callable $callback): mixed
    {
        $previous = $this->unscoped;
        $this->unscoped = true;

        try {
            return $callback();
        } finally {
            $this->unscoped = $previous;
        }
    }

    /** Bind a specific tenant for the duration of a callback (background jobs). */
    public function runFor(int|Organization $organization, callable $callback): mixed
    {
        $previousId = $this->id;
        $previousUnscoped = $this->unscoped;
        $this->unscoped = false;
        $this->set($organization);

        try {
            return $callback();
        } finally {
            $this->id = $previousId;
            $this->unscoped = $previousUnscoped;
        }
    }
}
