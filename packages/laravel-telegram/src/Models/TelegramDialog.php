<?php

declare(strict_types=1);

namespace Danog\LaravelTelegram\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $account_id
 * @property int $peer_id
 * @property int|null $top_message_id
 * @property int $unread_count
 * @property int $unread_mentions_count
 * @property int|null $pts
 * @property bool $is_pinned
 */
class TelegramDialog extends Model
{
    protected $table = 'telegram_dialogs';

    public $incrementing = false;

    protected $fillable = [
        'account_id',
        'peer_id',
        'top_message_id',
        'unread_count',
        'unread_mentions_count',
        'pts',
        'is_pinned',
    ];

    protected $casts = [
        'account_id' => 'integer',
        'peer_id' => 'integer',
        'top_message_id' => 'integer',
        'unread_count' => 'integer',
        'unread_mentions_count' => 'integer',
        'pts' => 'integer',
        'is_pinned' => 'boolean',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(TelegramAccount::class, 'account_id', 'id');
    }

    public function peer(): BelongsTo
    {
        return $this->belongsTo(TelegramPeer::class, 'peer_id', 'peer_id');
    }
}
