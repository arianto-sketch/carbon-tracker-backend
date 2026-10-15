<?php

namespace App\Notifications;

use App\Models\Project;
use App\Models\User;

/**
 * Ringkasan approve/tolak massal: satu notifikasi per pembuat (bukan satu per entri), supaya
 * kotak masuk tidak dibanjiri email saat owner me-review banyak entri sekaligus.
 */
class EntryBatchNotification extends ApprovalNotification
{
    private const MESSAGES = [
        'entries_approved' => ':actor menyetujui :count entri Anda di project :project.',
        'entries_rejected' => ':actor menolak :count entri Anda di project :project.',
    ];

    private const SUBJECTS = [
        'entries_approved' => ':count entri disetujui: :project',
        'entries_rejected' => ':count entri ditolak: :project',
    ];

    public function __construct(
        private string $type,
        private Project $project,
        private User $actor,
        private int $count,
        private ?string $reason = null,
    ) {
        parent::__construct();
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'         => $this->type,
            'entry_id'     => null,
            'project_id'   => $this->project->id,
            'project_name' => $this->project->name,
            'actor_name'   => $this->actor->name,
            'reason'       => $this->reason,
            'count'        => $this->count,
            'message'      => $this->message(),
        ];
    }

    protected function message(): string
    {
        return strtr(self::MESSAGES[$this->type], [
            ':actor' => $this->actor->name, ':count' => (string) $this->count, ':project' => $this->project->name,
        ]);
    }

    protected function subject(): string
    {
        return strtr(self::SUBJECTS[$this->type], [':count' => (string) $this->count, ':project' => $this->project->name]);
    }

    protected function projectId(): int
    {
        return $this->project->id;
    }

    protected function reason(): ?string
    {
        return $this->reason;
    }
}
