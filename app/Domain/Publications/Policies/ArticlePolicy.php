<?php

declare(strict_types=1);

namespace App\Domain\Publications\Policies;

use App\Domain\Publications\Models\Article;
use App\Models\User;

/**
 * Who may manage the public library (spec section 5, phase 4 rows).
 *
 * SIX ABILITIES, AND NO DELETE PATH
 * =================================
 * An article is created, edited, published and unpublished. It is never deleted:
 * a withdrawn article is unpublished, and its row and file stay. delete,
 * forceDelete, restore and their bulk forms are refused for everyone, super
 * admin included, and `delete_article` is deliberately NEVER SEEDED — seeding an
 * ability nothing honours invites somebody to wire it up later. The policy test
 * grants it anyway and proves the refusal still stands.
 *
 * Reading or downloading a PUBLISHED article needs no permission at all. An
 * anonymous reader is not a role, so nothing here governs the public site; this
 * policy answers only the staff side.
 *
 * NOT REGISTERED IN AppServiceProvider, ON PURPOSE
 * ------------------------------------------------
 * Gate::guessPolicyName() resolves this class from
 * App\Domain\Publications\Models\Article unaided, like every other domain policy.
 * ArticleResourceTest asserts the resolution with Gate::getPolicyFor() rather than
 * assuming it, because an unregistered policy that stopped resolving would fail
 * silently, as a `false` on every check.
 *
 * PERMISSION-BASED, NEVER ROLE-BASED. Every method asks can(). Which roles hold
 * which ability is RolePermissionSeeder's business.
 *
 * Whether an article is already published (or not) is the lifecycle Actions'
 * business, decided under a row lock, because a policy cannot hold one. publish
 * and unpublish answer only "is this actor entitled to do it at all".
 */
class ArticlePolicy
{
    /** May this actor list the library? */
    public function viewAny(User $user): bool
    {
        return $user->can('view_any_article');
    }

    /** May this actor open one article? */
    public function view(User $user, Article $article): bool
    {
        return $user->can('view_article');
    }

    /** May this actor add an article? */
    public function create(User $user): bool
    {
        return $user->can('create_article');
    }

    /** May this actor edit an article's details, or replace its PDF? */
    public function update(User $user, Article $article): bool
    {
        return $user->can('update_article');
    }

    /** Making an article visible to readers. A separate grant from editing it. */
    public function publish(User $user, Article $article): bool
    {
        return $user->can('publish_article');
    }

    /** Withdrawing an article from readers. A separate grant from publishing it. */
    public function unpublish(User $user, Article $article): bool
    {
        return $user->can('unpublish_article');
    }

    /*
     * EVERY REMAINING FILAMENT ABILITY IS STATED, AND EVERY ONE IS FALSE.
     * ==================================================================
     * Filament reads an UNSTATED ability as ALLOW, so a policy that omits
     * `forceDelete` offers force-delete on a library whose entire point is that
     * nothing in it is destroyed. PolicyAbilitySurfaceTest fails the build on an
     * omission; writing each method out, even to return false, is what makes the
     * answer real.
     */

    /** Refused — an article is unpublished, never deleted. */
    public function delete(User $user, Article $article): bool
    {
        return false;
    }

    /** Refused — there is no bulk delete, and a bulk action would skip the per-record rule. */
    public function deleteAny(User $user): bool
    {
        return false;
    }

    /** Refused — nothing is soft-deleted here, so nothing is restorable. */
    public function restore(User $user, Article $article): bool
    {
        return false;
    }

    /** Refused — see restore(). */
    public function restoreAny(User $user): bool
    {
        return false;
    }

    /** Refused — the strongest form of the rule that articles survive. */
    public function forceDelete(User $user, Article $article): bool
    {
        return false;
    }

    /** Refused — see forceDelete(). */
    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    /**
     * Refused — a copy would carry a slug the unique index rejects and a file
     * path another row owns, so two articles would share one PDF.
     */
    public function replicate(User $user, Article $article): bool
    {
        return false;
    }

    /** Refused — the library has no manual ordering to defend. */
    public function reorder(User $user): bool
    {
        return false;
    }
}
