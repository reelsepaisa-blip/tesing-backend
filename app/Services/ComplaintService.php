<?php

namespace App\Services;

use App\Models\User;
use App\Models\Complaint;
use App\Models\ComplaintMessage;
use App\Enums\ComplaintStatus;
use App\Enums\ComplaintType;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

class ComplaintService
{
    protected WalletService $walletService;

    public function __construct(WalletService $walletService)
    {
        $this->walletService = $walletService;
    }

    
    public function createComplaint(User $user, array $data): Complaint
    {
        return DB::transaction(function () use ($user, $data) {
            $complaint = Complaint::create([
                'complaint_id' => $this->generateComplaintId(),
                'user_id' => $user->id,
                'booking_id' => $data['booking_id'] ?? null,
                'type' => $data['type'],
                'subject' => $data['subject'],
                'description' => $data['description'],
                'priority' => ComplaintType::from($data['type'])->getPriority(),
                'status' => ComplaintStatus::PENDING,
                'meta_data' => $data['meta_data'] ?? null,
            ]);

            $this->addMessage($complaint, $user, $data['description']);

            if (isset($data['attachments'])) {
                foreach ($data['attachments'] as $attachment) {
                    $complaint->addAttachment($attachment);
                }
            }

            Event::dispatch('complaint.created', $complaint);

            return $complaint;
        });
    }

    
    public function assignComplaint(Complaint $complaint, User $agent): Complaint
    {
        if (!$complaint->status->canTransitionTo(ComplaintStatus::ASSIGNED)) {
            throw ValidationException::withMessages([
                'status' => ['Cannot assign complaint in current status.'],
            ]);
        }

        if (!$agent->hasRole('support')) {
            throw ValidationException::withMessages([
                'agent' => ['Invalid support agent.'],
            ]);
        }

        return DB::transaction(function () use ($complaint, $agent) {
            $complaint->update([
                'agent_id' => $agent->id,
                'status' => ComplaintStatus::ASSIGNED,
                'assigned_at' => now(),
            ]);

            Event::dispatch('complaint.assigned', $complaint);

            return $complaint;
        });
    }

    
    public function addMessage(Complaint $complaint, User $user, string $message, ?array $attachments = null): ComplaintMessage
    {
        if ($complaint->status->isFinal()) {
            throw ValidationException::withMessages([
                'status' => ['Cannot add message to closed complaint.'],
            ]);
        }

        return DB::transaction(function () use ($complaint, $user, $message, $attachments) {
            $complaintMessage = ComplaintMessage::create([
                'complaint_id' => $complaint->id,
                'user_id' => $user->id,
                'message' => $message,
            ]);

            if ($attachments) {
                foreach ($attachments as $attachment) {
                    $complaintMessage->addAttachment($attachment);
                }
            }

            $complaint->touch();

            Event::dispatch('complaint.message_added', $complaintMessage);

            return $complaintMessage;
        });
    }

    
    public function updateStatus(Complaint $complaint, ComplaintStatus $status, ?string $note = null): Complaint
    {
        if (!$complaint->status->canTransitionTo($status)) {
            throw ValidationException::withMessages([
                'status' => ['Invalid status transition.'],
            ]);
        }

        return DB::transaction(function () use ($complaint, $status, $note) {
            $complaint->update([
                'status' => $status,
                $this->getStatusTimestampField($status) => now(),
            ]);

            if ($note) {
                $this->addMessage($complaint, auth()->user(), $note);
            }

            Event::dispatch('complaint.status_updated', $complaint);

            return $complaint;
        });
    }

    
    public function processRefund(Complaint $complaint, array $data): Complaint
    {
        if (!$complaint->type->allowsRefund()) {
            throw ValidationException::withMessages([
                'type' => ['Refund not allowed for this complaint type.'],
            ]);
        }

        return DB::transaction(function () use ($complaint, $data) {
            $this->walletService->processRefund($complaint->user, $data['amount'], [
                'description' => $data['description'] ?? 'Complaint refund',
                'reference_id' => $complaint->id,
                'reference_type' => 'complaint',
            ]);

            $complaint->update([
                'refund_amount' => $data['amount'],
                'refund_processed_at' => now(),
                'meta_data' => array_merge($complaint->meta_data ?? [], [
                    'refund' => [
                        'amount' => $data['amount'],
                        'reason' => $data['description'] ?? null,
                        'processed_by' => auth()->id(),
                        'processed_at' => now(),
                    ],
                ]),
            ]);

            $this->addMessage(
                $complaint,
                auth()->user(),
                "Refund of {$data['amount']} processed to wallet. " . ($data['description'] ?? '')
            );

            Event::dispatch('complaint.refund_processed', $complaint);

            return $complaint;
        });
    }

    
    public function escalateComplaint(Complaint $complaint, string $reason): Complaint
    {
        if (!$complaint->status->canTransitionTo(ComplaintStatus::ESCALATED)) {
            throw ValidationException::withMessages([
                'status' => ['Cannot escalate complaint in current status.'],
            ]);
        }

        return DB::transaction(function () use ($complaint, $reason) {
            $complaint->update([
                'status' => ComplaintStatus::ESCALATED,
                'escalated_at' => now(),
                'meta_data' => array_merge($complaint->meta_data ?? [], [
                    'escalation' => [
                        'reason' => $reason,
                        'escalated_by' => auth()->id(),
                        'escalated_at' => now(),
                    ],
                ]),
            ]);

            $this->addMessage($complaint, auth()->user(), "Complaint escalated: $reason");

            Event::dispatch('complaint.escalated', $complaint);

            return $complaint;
        });
    }

    
    protected function getStatusTimestampField(ComplaintStatus $status): string
    {
        return match($status) {
            ComplaintStatus::ASSIGNED => 'assigned_at',
            ComplaintStatus::IN_PROGRESS => 'in_progress_at',
            ComplaintStatus::RESOLVED => 'resolved_at',
            ComplaintStatus::CLOSED => 'closed_at',
            ComplaintStatus::ESCALATED => 'escalated_at',
            default => 'updated_at',
        };
    }

    
    protected function generateComplaintId(): string
    {
        do {
            $id = 'COMP-' . strtoupper(Str::random(8));
        } while (Complaint::where('complaint_id', $id)->exists());

        return $id;
    }
}
