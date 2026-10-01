<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single-use set-password / reset-password token (LAUNCH-P1 P1-2,
 * P1-8). Same shared table and shape as pos_merchant's model.
 *
 * Only the SHA-256 hash of the token is stored; the raw value exists
 * only in the emailed link and in the admin's one-time "copy link"
 * response. `used_at` makes it single-use inside its expiry window.
 */
#[Fillable([
    'user_id',
    'token_hash',
    'purpose',
    'issued_by_user_id',
    'expires_at',
    'used_at',
    'created_at',
])]
class PasswordResetToken extends Model
{
    public const PURPOSE_INVITE = 'invite';

    public const PURPOSE_RESET = 'reset';

    public const PURPOSE_FORGOT = 'forgot';

    protected $table = 'pos_password_reset_tokens';

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
