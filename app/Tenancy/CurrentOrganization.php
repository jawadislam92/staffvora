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

    /** The active organization id, or null when none is bound (scope becomes a no-op). */
    public function id(): ?int
    {
        return $this->unscoped ? null : $this->id;
    }

    public function check(): bool
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
