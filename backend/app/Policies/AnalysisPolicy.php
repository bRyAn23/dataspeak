<?php

namespace App\Policies;

use App\Models\Analysis;
use App\Models\User;

/** FEAT-SEC-001: users act only on their own analyses. Non-owners receive 404 at lookup time. */
class AnalysisPolicy
{
    public function view(User $user, Analysis $analysis): bool
    {
        return $analysis->user_id === $user->id;
    }

    public function update(User $user, Analysis $analysis): bool
    {
        return $analysis->user_id === $user->id;
    }

    public function delete(User $user, Analysis $analysis): bool
    {
        return $analysis->user_id === $user->id;
    }
}
