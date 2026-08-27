<?php

declare(strict_types=1);

namespace Danog\LaravelTelegram\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $account_id
 * @property int $entity_id User, Chat, or Channel ID
 * @property string $relationship 'contact' | 'member' | 'admin' | 'creator' | 'self'
 */
class TelegramAccountEntity extends Model
{
    protected $table = 'telegram_account_entities';

    public $incrementing = false;

    protected $fillable = [
        'account_id',
        'entity_id',
        'relationship',
    ];

    protected $casts = [
        'account_id' => 'integer',
        'entity_id' => 'integer',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(TelegramAccount::class, 'account_id', 'id');
    }
}
