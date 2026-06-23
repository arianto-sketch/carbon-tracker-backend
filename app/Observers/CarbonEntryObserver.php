<?php

namespace App\Observers;

use App\Models\AuditLog;
use App\Models\CarbonEntry;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

class CarbonEntryObserver
{
    public function created(CarbonEntry $entry): void
    {
        $this->log('created', $entry, null, $entry->toArray());
    }

    public function updated(CarbonEntry $entry): void
    {
        $this->log('updated', $entry, $entry->getOriginal(), $entry->getChanges());
    }

    public function deleted(CarbonEntry $entry): void
    {
        $this->log('deleted', $entry, $entry->toArray(), null);
    }

    private function log(string $action, CarbonEntry $entry, ?array $oldValues, ?array $newValues): void
    {
        AuditLog::create([
            'user_id'    => Auth::id(),
            'action'     => $action,
            'model_type' => CarbonEntry::class,
            'model_id'   => $entry->id,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => Request::ip(),
            'user_agent' => Request::userAgent(),
            'created_at' => now(),
        ]);
    }
}
