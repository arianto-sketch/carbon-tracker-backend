<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Putaran pengingat data yang sudah dikirim untuk satu periode */
class DataReminderRun extends Model
{
    public $timestamps = false;

    protected $fillable = ['period', 'round', 'recipient_count', 'ran_at'];

    protected function casts(): array
    {
        return [
            'round' => 'integer',
            'recipient_count' => 'integer',
            'ran_at' => 'datetime',
        ];
    }
}
