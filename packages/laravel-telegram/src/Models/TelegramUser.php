<?php

declare(strict_types=1);

namespace Danog\LaravelTelegram\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id Telegram User ID
 * @property string|null $access_hash
 * @property string|null $first_name
 * @property string|null $last_name
 * @property string|null $username
 * @property string|null $phone
 * @property bool $is_bot
 * @property bool $is_verified
 * @property bool $is_premium
 * @property array|null $status
 * @property array|null $photo
 * @property array|null $raw_attributes
 */
class TelegramUser extends Model
{
    protected $table = 'telegram_users';

    public $incrementing = false;
    protected $keyType = 'int';

    protected $fillable = [
        'id',
        'access_hash',
        'first_name',
        'last_name',
        'username',
        'phone',
        'is_bot',
        'is_verified',
        'is_premium',
        'status',
        'photo',
        'raw_attributes',
    ];

    protected $casts = [
        'id' => 'integer',
        'is_bot' => 'boolean',
        'is_verified' => 'boolean',
        'is_premium' => 'boolean',
        'status' => 'array',
        'photo' => 'array',
        'raw_attributes' => 'array',
    ];

    public function messages(): HasMany
    {
        return $this->hasMany(TelegramMessage::class, 'from_id', 'id');
    }

    public function getFullNameAttribute(): string
    {
        return trim(($this->first_name ?? '') . ' ' . ($this->last_name ?? ''));
    }
}
