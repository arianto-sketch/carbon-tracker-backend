<?php

namespace App\Notifications;

use App\Models\CarbonEntry;
use App\Models\User;

/**
 * Notifikasi satu entri: entry_submitted (ke owner project), entry_approved & entry_rejected
 * (ke pembuat entri).
 */
class EntryWorkflowNotification extends ApprovalNotification
{
    private const MESSAGES = [
        'entry_submitted' => ':actor mengirim entri untuk di-approve di project :project.',
        'entry_approved'  => ':actor menyetujui entri Anda di project :project.',
        'entry_rejected'  => ':actor menolak entri Anda di project :project.',
    ];

    private const SUBJECTS = [
        'entry_submitted' => 'Entri menunggu approval: :project',
        'entry_approved'  => 'Entri disetujui: :project',
        'entry_rejected'  => 'Entri ditolak: :project',
    ];

    public function __construct(
        private string $type,
        private CarbonEntry $entry,
        private User $actor,
        private ?string $reason = null,
    ) {
        parent::__construct();
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'         => $this->type,
            'entry_id'     => $this->entry->id,
            'project_id'   => $this->entry->project_id,
            'project_name' => $this->entry->project?->name,
            'actor_name'   => $this->actor->name,
            'reason'       => $this->reason,
            'message'      => $this->message(),
        ];
    }

    protected function message(): string
    {
        return strtr(self::MESSAGES[$this->type], [':actor' => $this->actor->name, ':project' => $this->projectName()]);
    }

    protected function subject(): string
    {
        return strtr(self::SUBJECTS[$this->type], [':project' => $this->projectName()]);
    }

    protected function projectId(): int
    {
        return $this->entry->project_id;
    }

    protected function reason(): ?string
    {
        return $this->reason;
    }

    private function projectName(): string
    {
        return (string) $this->entry->project?->name;
    }
}
