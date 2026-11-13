<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The owner, 2026-11-13: Sirojiddin is called "Сирож", never "Сера". The
 * name sat on his card (how to call him, personal answers) and went out
 * with the call by name. Only his card, only that word; nothing else
 * changes, and a card without it is left as it is.
 */
return new class extends Migration
{
    private const WORDS = [
        'Сера' => 'Сирож',
        'сера' => 'сирож',
        'СЕРА' => 'СИРОЖ',
        'Sera' => 'Siroj',
        'sera' => 'siroj',
        'SERA' => 'SIROJ',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('operation_users')) {
            return;
        }

        $columns = array_values(array_filter(
            ['address', 'personal_answers'],
            static fn (string $column): bool => Schema::hasColumn('operation_users', $column),
        ));

        if ($columns === []) {
            return;
        }

        $cards = DB::table('operation_users')
            ->where('name', 'like', '%SIROJIDDIN%')
            ->get(['id', ...$columns]);

        foreach ($cards as $card) {
            $changes = [];

            foreach ($columns as $column) {
                $raw = $card->{$column};

                if ($raw === null || $raw === '') {
                    continue;
                }

                $value = json_decode((string) $raw, true);

                if (! is_array($value)) {
                    continue;
                }

                $fixed = $this->fix($value);

                if ($fixed !== $value) {
                    $changes[$column] = json_encode($fixed, JSON_UNESCAPED_UNICODE);
                }
            }

            if ($changes !== []) {
                DB::table('operation_users')->where('id', $card->id)->update($changes);
            }
        }
    }

    public function down(): void
    {
        /* A name put right is not put back wrong. */
    }

    private function fix(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(fn (mixed $item): mixed => $this->fix($item), $value);
        }

        if (! is_string($value)) {
            return $value;
        }

        return (string) preg_replace_callback(
            '/(?<![\p{L}\p{M}])(' . implode('|', array_keys(self::WORDS)) . ')(?![\p{L}\p{M}])/u',
            static fn (array $match): string => self::WORDS[$match[1]],
            $value,
        );
    }
};
