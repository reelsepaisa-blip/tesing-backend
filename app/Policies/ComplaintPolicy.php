<?php

namespace App\Policies;

use App\Models\Complaint;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class ComplaintPolicy
{

    public function viewAny(User $user): bool
    {
        return in_array((int) $user->role_id, [1, 4]); // Admin or Support
    }


    public function view(User $user, Complaint $complaint): bool
    {

        if (in_array((int) $user->role_id, [1, 4])) {
            return true;
        }

        return $user->id === $complaint->user_id;
    }


    public function create(User $user): bool
    {
        return true; // All authenticated users can create complaints
    }


    public function update(User $user, Complaint $complaint): bool
    {

        if (!in_array((int) $user->role_id, [1, 4])) {
            return false;
        }

        if ($complaint->status->isFinal()) {
            return false;
        }

        if (
            $complaint->status->requiresAgent() &&
            $complaint->agent_id !== null &&
            $complaint->agent_id !== $user->id
        ) {
            return false;
        }

        return true;
    }


    public function delete(User $user, Complaint $complaint): bool
    {
        return false; // Complaints should never be deleted
    }


    public function restore(User $user, Complaint $complaint): bool
    {
        return false; // Complaints should never be restored
    }


    public function forceDelete(User $user, Complaint $complaint): bool
    {
        return false; // Complaints should never be force deleted
    }


    public function reply(User $user, Complaint $complaint): bool
    {

        if ($complaint->status->isFinal()) {
            return false;
        }

        if (in_array((int) $user->role_id, [1, 4])) {
            return true;
        }

        return $user->id === $complaint->user_id;
    }


    public function processRefund(User $user, Complaint $complaint): bool
    {

        if (!in_array((int) $user->role_id, [1, 4])) {
            return false;
        }

        if ($complaint->status->isFinal()) {
            return false;
        }

        if ($complaint->refund_processed_at !== null) {
            return false;
        }

        return $complaint->type->allowsRefund();
    }


    public function escalate(User $user, Complaint $complaint): bool
    {

        if (!in_array((int) $user->role_id, [1, 4])) {
            return false;
        }

        if ($complaint->status->isFinal()) {
            return false;
        }

        return $complaint->status->canTransitionTo(\App\Enums\ComplaintStatus::ESCALATED);
    }
}
