<?php

declare(strict_types=1);

namespace App\Models\Telegram;

use Illuminate\Database\Eloquent\Model;

/**
 * One read of Telegram the developer asked for through the reader API, left
 * for the process that holds the account's session.
 *
 * @property int                             $id
 * @property int                             $telegram_account_id
 * @property string                          $op
 * @property array<string, mixed>|null       $params
 * @property string                          $status
 * @property array<string, mixed>|null       $result
 * @property string|null                     $error
 * @property \Illuminate\Support\Carbon|null $started_at
 * @property \Illuminate\Support\Carbon|null $finished_at
 */
class TelegramReadRequest extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_DONE = 'done';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'telegram_account_id',
        'op',
        'params',
        'status',
        'result',
        'error',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'telegram_account_id' => 'integer',
            'params' => 'array',
            'result' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [self::STATUS_DONE, self::STATUS_FAILED], true);
    }
}
