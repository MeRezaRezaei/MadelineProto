<?php

declare(strict_types=1);

namespace Danog\LaravelTelegram\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id Telegram Channel / Supergroup ID
 * @property string|null $access_hash
 * @property string $title
 * @property string|null $username
 * @property int $participants_count
 * @property bool $is_broadcast
 * @property bool $is_megagroup
 * @property bool $is_verified
 * @property array|null $photo
 * @property array|null $raw_attributes
 */
class TelegramChannel extends Model
{
    protected $table = 'telegram_channels';

    public $incrementing = false;
    protected $keyType = 'int';

    protected $fillable = [
        'id',
        'access_hash',
        'title',
        'username',
        'participants_count',
        'is_broadcast',
        'is_megagroup',
        'is_verified',
        'photo',
        'raw_attributes',
    ];

    protected $casts = [
        'id' => 'integer',
        'participants_count' => 'integer',
        'is_broadcast' => 'boolean',
        'is_megagroup' => 'boolean',
        'is_verified' => 'boolean',
        'photo' => 'array',
        'raw_attributes' => 'array',
    ];

    public function messages(): HasMany
    {
        return $this->hasMany(TelegramMessage::class, 'peer_id', 'id');
    }
}
