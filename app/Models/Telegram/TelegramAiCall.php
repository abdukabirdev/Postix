<?php

declare(strict_types=1);

namespace App\Models\Telegram;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One call to Claude (AiClient): what was asked, the answer, the money.
 *
 * @property int         $id
 * @property string      $area
 * @property string|null $kind
 * @property string      $mode
 * @property string      $status
 * @property bool        $used
 * @property int|null    $operation_user_id
 * @property string|null $subject_type
 * @property int|null    $subject_id
 * @property string|null $request
 * @property array|null  $answer
 * @property string|null $free_text
 * @property string|null $error
 * @property int         $input_tokens
 * @property int         $output_tokens
 * @property int         $cache_read_tokens
 * @property int         $cache_write_tokens
 * @property float       $cost_usd
 * @property int         $duration_ms
 */
class TelegramAiCall extends Model
{
    public const MODE_LIVE = 'live';

    public const MODE_SHADOW = 'shadow';

    public const STATUS_OK = 'ok';

    public const STATUS_FAILED = 'failed';

    public const STATUS_REFUSED = 'refused';

    protected $fillable = [
        'area',
        'kind',
        'mode',
        'status',
        'used',
        'operation_user_id',
        'subject_type',
        'subject_id',
        'request',
        'answer',
        'free_text',
        'error',
        'input_tokens',
        'output_tokens',
        'cache_read_tokens',
        'cache_write_tokens',
        'cost_usd',
        'duration_ms',
    ];

    protected function casts(): array
    {
        return [
            'used' => 'boolean',
            'answer' => 'array',
            'cost_usd' => 'float',
        ];
    }

    public function operationUser(): BelongsTo
    {
        return $this->belongsTo(OperationUser::class);
    }
}
