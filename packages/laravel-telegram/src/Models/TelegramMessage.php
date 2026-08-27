<?php

declare(strict_types=1);

namespace Danog\LaravelTelegram\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id Message ID inside chat
 * @property int $peer_id Chat / Channel / User ID
 * @property int|null $from_id Sender User / Channel ID
 * @property \Illuminate\Support\Carbon $date
 * @property string|null $message
 * @property string|null $media_type
 * @property string|null $media_hash SHA-256 for file deduplication
 * @property array|null $media_meta File references, sizes, dimensions, mime
 * @property int|null $reply_to_msg_id
 * @property int|null $reply_to_peer_id
 * @property array|null $entities TL MessageEntity array (bold, links, code, mentions)
 * @property int|null $views
 * @property int|null $forwards
 * @property bool $is_outgoing
 * @property array|null $raw_attributes
 * @property \Illuminate\Support\Carbon|null $deleted_at Set when Telegram issues deletion update (Never hard-deleted)
 */
class TelegramMessage extends Model
{
    use SoftDeletes;

    protected $table = 'telegram_messages';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'peer_id',
        'from_id',
        'date',
        'message',
        'media_type',
        'media_hash',
        'media_meta',
        'reply_to_msg_id',
        'reply_to_peer_id',
        'entities',
        'views',
        'forwards',
        'is_outgoing',
        'raw_attributes',
        'deleted_at',
    ];

    protected $casts = [
        'id' => 'integer',
        'peer_id' => 'integer',
        'from_id' => 'integer',
        'date' => 'datetime',
        'media_meta' => 'array',
        'reply_to_msg_id' => 'integer',
        'reply_to_peer_id' => 'integer',
        'entities' => 'array',
        'views' => 'integer',
        'forwards' => 'integer',
        'is_outgoing' => 'boolean',
        'raw_attributes' => 'array',
        'deleted_at' => 'datetime',
    ];

    public function sender(): BelongsTo
    {
        return $this->belongsTo(TelegramUser::class, 'from_id', 'id');
    }

    public function peer(): BelongsTo
    {
        return $this->belongsTo(TelegramPeer::class, 'peer_id', 'peer_id');
    }
}
