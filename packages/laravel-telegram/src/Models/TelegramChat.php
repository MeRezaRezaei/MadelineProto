<?php

declare(strict_types=1);

namespace Danog\LaravelTelegram\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id Telegram Group Chat ID
 * @property string $title
 * @property int $participants_count
 * @property array|null $photo
 * @property array|null $raw_attributes
 */
class TelegramChat extends Model
{
    protected $table = 'telegram_chats';

    public $incrementing = false;
    protected $keyType = 'int';

    protected $fillable = [
        'id',
        'title',
        'participants_count',
        'photo',
        'raw_attributes',
    ];

    protected $casts = [
        'id' => 'integer',
        'participants_count' => 'integer',
        'photo' => 'array',
        'raw_attributes' => 'array',
    ];

    public function messages(): HasMany
    {
        return $this->hasMany(TelegramMessage::class, 'peer_id', 'id');
    }
}
