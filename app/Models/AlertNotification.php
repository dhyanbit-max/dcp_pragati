<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AlertNotification extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['sent_at' => 'datetime', 'scheduled_for' => 'datetime'];

    public function alert(): BelongsTo
    {
        return $this->belongsTo(Alert::class)->withoutGlobalScopes();
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }
}
