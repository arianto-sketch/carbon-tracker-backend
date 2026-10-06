<?php

namespace App\Notifications;

use App\Models\CarbonEntry;
use App\Models\User;
use Illuminate\Notifications\Notification;

/**
 * Notifikasi in-app (channel database, sinkron) untuk alur approval entri:
 * entry_submitted (ke owner project), entry_approved & entry_rejected (ke pembuat entri).
 */
class EntryWorkflowNotification extends Notification
{
    private const MESSAGES = [
        'entry_submitted' => ':actor mengirim entri untuk di-approve di project :project.',
        'entry_approved'  => ':actor menyetujui entri Anda di project :project.',
        'entry_rejected'  => ':actor menolak entri Anda di project :project.',
    ];

    public function __construct(
        private string $type,
        private CarbonEntry $entry,
        private User $actor,
        private ?string $reason = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $projectName = $this->entry->project?->name;

        return [
            'type'         => $this->type,
            'entry_id'     => $this->entry->id,
            'project_id'   => $this->entry->project_id,
            'project_name' => $projectName,
            'actor_name'   => $this->actor->name,
            'reason'       => $this->reason,
            'message'      => strtr(self::MESSAGES[$this->type], [':actor' => $this->actor->name, ':project' => (string) $projectName]),
        ];
    }
}
