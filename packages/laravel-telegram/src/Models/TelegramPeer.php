<?php

declare(strict_types=1);

namespace Danog\LaravelTelegram\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $peer_id
 * @property string $type 'user' | 'chat' | 'channel'
 * @property string|null $username
 * @property string|null $phone
 */
class TelegramPeer extends Model
{
    protected $table = 'telegram_peers';

    protected $primaryKey = 'peer_id';
    public $incrementing = false;
    protected $keyType = 'int';

    protected $fillable = [
        'peer_id',
        'type',
        'username',
        'phone',
    ];

    protected $casts = [
        'peer_id' => 'integer',
    ];
}
