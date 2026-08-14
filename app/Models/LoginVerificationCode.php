<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Одноразовый код второго фактора при входе — отправляется на email,
 * хранится только хэш (как пароль). Активен ровно один код на пользователя:
 * issue() удаляет все неиспользованные до создания нового.
 */
class LoginVerificationCode extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'code_hash',
        'expires_at',
        'used_at',
        'ip',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Отзывает прежние коды и выпускает новый. Возвращает код в открытом виде — для письма. */
    public static function issue(User $user, ?string $ip): string
    {
        self::where('user_id', $user->id)->whereNull('used_at')->delete();

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        self::create([
            'user_id' => $user->id,
            'code_hash' => hash('sha256', $code),
            'expires_at' => now()->addMinutes((int) config('two_factor.code_ttl_minutes')),
            'ip' => $ip,
        ]);

        return $code;
    }

    /** Сверяет код с последним активным (неиспользованным, ещё не истёкшим) и, если совпал, гасит его. */
    public static function verify(User $user, string $code): bool
    {
        $row = self::where('user_id', $user->id)
            ->where('code_hash', hash('sha256', $code))
            ->whereNull('used_at')
            ->where('expires_at', '>=', now())
            ->first();

        if ($row === null) {
            return false;
        }

        $row->update(['used_at' => now()]);

        return true;
    }
}
