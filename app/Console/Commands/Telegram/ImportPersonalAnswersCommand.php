<?php

declare(strict_types=1);

namespace App\Console\Commands\Telegram;

use App\Application\Telegram\Services\AutoReplyStore;
use App\Application\Telegram\Services\ClientCheckRulesStore;
use App\Application\Telegram\Services\PersonalAnswers;
use App\Models\Telegram\OperationUser;
use Illuminate\Console\Command;
use JsonException;

/**
 * Adds personal answers from a file to the people's cards - the texts the
 * "Личные ответы" page holds - never taking away what is there.
 *
 * The file: {"people": [{"person_id", "name", "thanks": [...], "silence":
 * [...], "penalty": [...], "opening": [...], "ping": [...]}]}. A row is applied only when
 * the card with that id has that name, so a file made against one database
 * cannot write to the wrong person in another.
 *
 *   thanks  - the auto reply kind "Благодарность" (the first kind without it)
 *   silence - the nudge after ignored penalties
 *   opening - our greeting before the day's first penalty
 *   ping    - the call by name when a penalty stays unanswered (PingSilentByName)
 *   penalty - the penalty comment on every level of the person's ladder but
 *             an operator's last one, the "hand it over" threat
 *
 * A text the slot has already is not added twice; a slot keeps its "only"
 * switch (off for a new one) and at most PersonalAnswers::MAX_ITEMS items.
 */
final class ImportPersonalAnswersCommand extends Command
{
    protected $signature = 'telegram:personal-answers-import
        {file=database/data/personal_answers_2026_11_13.json : The file, relative to the project}
        {--dry : Only tell what would be added}';

    protected $description = 'Add personal answers from a file to the people cards, keeping what is there';

    private const THANKS_KIND = 'Благодарность';

    public function handle(AutoReplyStore $autoReplies, ClientCheckRulesStore $penalties): int
    {
        $path = base_path((string) $this->argument('file'));

        if (! is_file($path)) {
            $this->error("No file {$path}");

            return self::FAILURE;
        }

        try {
            $data = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->error('Not JSON: ' . $e->getMessage());

            return self::FAILURE;
        }

        $kinds = $autoReplies->current()->replies;
        $thanks = collect($kinds)->firstWhere('name', self::THANKS_KIND) ?? ($kinds[0] ?? null);

        $ladders = $penalties->current();

        $dry = (bool) $this->option('dry');
        $people = 0;
        $added = 0;

        foreach ((array) ($data['people'] ?? []) as $row) {
            $row = (array) $row;
            $person = OperationUser::query()->find((int) ($row['person_id'] ?? 0));

            if ($person === null || self::same((string) $person->name) !== self::same((string) ($row['name'] ?? ''))) {
                $this->warn('Skipped, no such card: #' . ($row['person_id'] ?? '?') . ' ' . ($row['name'] ?? ''));

                continue;
            }

            $wanted = [];

            if ($thanks !== null) {
                $wanted[PersonalAnswers::replySlot((string) $thanks['id'])] = $row['thanks'] ?? [];
            }

            $wanted[PersonalAnswers::SILENCE] = $row['silence'] ?? [];
            $wanted[PersonalAnswers::OPENING] = $row['opening'] ?? [];
            $wanted[PersonalAnswers::PING] = $row['ping'] ?? [];

            $role = $person->roleOrDefault();
            $levels = count($ladders->levels($role));
            /* Not an operator's last level: that comment is the threat, it says something else. */
            $last = $person->isSales() || $levels <= 1 ? $levels : $levels - 1;

            for ($level = 0; $level < $last; $level++) {
                $wanted[PersonalAnswers::penaltySlot($level)] = $row['penalty'] ?? [];
            }

            $slots = PersonalAnswers::sanitize($person->personal_answers ?? []);
            $before = $slots;
            $report = [];

            foreach ($wanted as $slot => $texts) {
                $items = $slots[$slot]['items'] ?? [];
                $have = array_map(
                    static fn (array $item): string => mb_strtolower(trim((string) ($item['text'] ?? ''))),
                    $items,
                );
                $new = 0;

                foreach ((array) $texts as $text) {
                    $text = trim((string) $text);

                    if ($text === '' || in_array(mb_strtolower($text), $have, true) || count($items) >= PersonalAnswers::MAX_ITEMS) {
                        continue;
                    }

                    $items[] = ['type' => PersonalAnswers::TYPE_TEXT, 'text' => $text];
                    $have[] = mb_strtolower($text);
                    $new++;
                }

                if ($new > 0) {
                    $slots[$slot] = ['only' => (bool) ($slots[$slot]['only'] ?? false), 'items' => $items];
                    $report[] = "{$slot} +{$new}";
                    $added += $new;
                }
            }

            if ($report === []) {
                continue;
            }

            $people++;
            $this->line(($dry ? '[dry] ' : '') . "#{$person->id} {$person->name}: " . implode(', ', $report));

            if (! $dry && $slots !== $before) {
                $person->update(['personal_answers' => PersonalAnswers::sanitize($slots)]);
            }
        }

        $this->info(($dry ? 'Would add' : 'Added') . ": {$added} texts for {$people} people");

        return self::SUCCESS;
    }

    /**
     * A name as both sides may write it: case and spaces do not count.
     */
    private static function same(string $name): string
    {
        return (string) preg_replace('/\s+/u', ' ', mb_strtolower(trim($name)));
    }
}
