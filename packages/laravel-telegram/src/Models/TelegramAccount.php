<?php

declare(strict_types=1);

namespace Danog\LaravelTelegram\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id Telegram User ID of the account owner
 * @property string|null $user_id Platform owner user UUID
 * @property string|null $phone
 * @property int $api_id
 * @property string $api_hash
 * @property int $dc_id
 * @property string $auth_state
 * @property string|null $session_key_encrypted
 * @property array|null $settings
 */
class TelegramAccount extends Model
{
    protected $table = 'telegram_accounts';

    public $incrementing = false;
    protected $keyType = 'int';

    protected $fillable = [
        'id',
        'user_id',
        'phone',
        'api_id',
        'api_hash',
        'dc_id',
        'auth_state',
        'session_key_encrypted',
        'settings',
    ];

    protected $casts = [
        'id' => 'integer',
        'api_id' => 'integer',
        'dc_id' => 'integer',
        'settings' => 'array',
    ];

    public function dialogs(): HasMany
    {
        return $this->hasMany(TelegramDialog::class, 'account_id', 'id');
    }

    public function linkedEntities(): HasMany
    {
        return $this->hasMany(TelegramAccountEntity::class, 'account_id', 'id');
    }

    public function isActive(): bool
    {
        return $this->auth_state === 'active';
    }
}
