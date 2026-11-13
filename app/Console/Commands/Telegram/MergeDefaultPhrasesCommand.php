<?php

declare(strict_types=1);

namespace App\Console\Commands\Telegram;

use App\Application\Telegram\Services\AutoReplyRules;
use App\Application\Telegram\Services\AutoReplyStore;
use App\Application\Telegram\Services\ClientCheckRules;
use App\Application\Telegram\Services\ClientCheckRulesStore;
use Illuminate\Console\Command;

/**
 * New texts of config/auto_replies.php and config/client_checks.php - the
 * variants taken from real chats - added to what was saved in the panel,
 * which otherwise keeps its own copy and never sees them.
 *
 * Added after what is there, never twice. Matched by name (auto reply
 * kinds, greetings) and by `from` (penalty levels); the nudge, the opening
 * and the follow-up are one each. A text removed in the panel and still
 * shipped comes back - there is no history to tell it from a new one.
 *
 * A kind of auto reply that is shipped and missing from the saved copy (by
 * name) is added whole, after the saved ones.
 *
 * The one thing taken out: auto reply texts no longer shipped
 * (auto_replies.retired) and penalty comments no longer shipped
 * (client_checks.retired), from every list of the saved copy.
 */
final class MergeDefaultPhrasesCommand extends Command
{
    protected $signature = 'telegram:merge-defaults';

    protected $description = 'Add the shipped auto reply and penalty texts to the ones saved in the panel';

    private int $added = 0;

    private int $removed = 0;

    /**
     * @var list<string>
     */
    private array $retired = [];

    /**
     * @var list<string>
     */
    private array $retiredPenalties = [];

    public function handle(AutoReplyStore $autoReplies, ClientCheckRulesStore $penalties): int
    {
        $this->added = 0;
        $this->removed = 0;
        $this->retired = array_values(array_map('strval', (array) config('auto_replies.retired', [])));
        $this->retiredPenalties = array_values(array_map('strval', (array) config('client_checks.retired', [])));

        $this->autoReplies($autoReplies);
        $this->penalties($penalties);

        $this->info("Added: {$this->added}");
        $this->info("Removed: {$this->removed}");

        return self::SUCCESS;
    }

    private function autoReplies(AutoReplyStore $store): void
    {
        if (! $store->exists() || $store->error() !== null) {
            $this->line('Auto replies: nothing saved in the panel, the config is used as is.');

            return;
        }

        $rules = $store->current()->toArray();
        $defaults = $store->defaults()->toArray();

        foreach ($rules['replies'] as &$reply) {
            $reply['answers'] = $this->retire($reply['answers']);

            $default = collect($defaults['replies'])->firstWhere('name', $reply['name']);

            if ($default !== null) {
                $reply['answers'] = $this->sets($reply['answers'], $default['answers'], AutoReplyRules::MAX_ANSWERS);
                $reply['keywords'] = $this->list($reply['keywords'], $default['keywords'], AutoReplyRules::MAX_KEYWORDS);
            }
        }
        unset($reply);

        /* A shipped kind the saved copy does not have yet (2026-11-13: «да», Объяснение). */
        $names = array_column($rules['replies'], 'name');

        foreach ($defaults['replies'] as $default) {
            if (in_array($default['name'], $names, true) || count($rules['replies']) >= AutoReplyRules::MAX_REPLIES) {
                continue;
            }

            $rules['replies'][] = $default;
            $names[] = $default['name'];
            $this->added++;
        }

        foreach ($rules['greetings']['list'] as &$greeting) {
            $greeting['answers'] = $this->retire($greeting['answers']);

            $default = collect($defaults['greetings']['list'])->firstWhere('name', $greeting['name']);

            if ($default !== null) {
                $greeting['answers'] = $this->sets($greeting['answers'], $default['answers'], AutoReplyRules::MAX_ANSWERS);
                $greeting['keywords'] = $this->list($greeting['keywords'], $default['keywords'], AutoReplyRules::MAX_KEYWORDS);
            }
        }
        unset($greeting);

        $rules['greetings']['fillers'] = $this->list($rules['greetings']['fillers'], $defaults['greetings']['fillers'], AutoReplyRules::MAX_FILLERS);

        foreach ([['silence', 'answers'], ['opening', 'answers'], ['follow_up', 'answers'], ['follow_up', 'answers_many']] as [$owner, $field]) {
            $rules[$owner][$field] = $this->sets($this->retire($rules[$owner][$field]), $defaults[$owner][$field], AutoReplyRules::MAX_ANSWERS);
        }

        $store->save(AutoReplyRules::fromArray($rules));
    }

    private function penalties(ClientCheckRulesStore $store): void
    {
        if (! $store->isCustomised()) {
            $this->line('Penalties: nothing saved in the panel, the config is used as is.');

            return;
        }

        $rules = $store->current()->toArray();
        $defaults = $store->defaults()->toArray();

        foreach ($rules['roles'] as $role => &$ladder) {
            foreach ($ladder['levels'] as &$level) {
                $default = collect($defaults['roles'][$role]['levels'] ?? [])->firstWhere('from', $level['from']);

                $level['phrases'] = $this->retirePenalties($level['phrases']);

                if ($default !== null) {
                    $level['phrases'] = $this->sets($level['phrases'], $default['phrases'], ClientCheckRules::MAX_PHRASES);
                }
            }
            unset($level);
        }
        unset($ladder);

        $store->save(ClientCheckRules::fromArray($rules));
    }

    /**
     * The saved penalty comments without the retired ones.
     *
     * @param array<string, array<string, list<string>>> $sets
     * @return array<string, array<string, list<string>>>
     */
    private function retirePenalties(array $sets): array
    {
        foreach ($sets as $language => $tones) {
            foreach ($tones as $tone => $texts) {
                $kept = array_values(array_diff($texts, $this->retiredPenalties));

                $this->removed += count($texts) - count($kept);

                $sets[$language][$tone] = $kept;
            }
        }

        return $sets;
    }

    /**
     * The saved answers without the retired texts.
     *
     * @param array<string, array<string, list<string>>> $sets
     * @return array<string, array<string, list<string>>>
     */
    private function retire(array $sets): array
    {
        foreach ($sets as $language => $tones) {
            foreach ($tones as $tone => $texts) {
                $kept = array_values(array_diff($texts, $this->retired));

                $this->removed += count($texts) - count($kept);

                $sets[$language][$tone] = $kept;
            }
        }

        return $sets;
    }

    /**
     * @param array<string, array<string, list<string>>> $current
     * @param array<string, array<string, list<string>>> $defaults
     * @return array<string, array<string, list<string>>>
     */
    private function sets(array $current, array $defaults, int $max): array
    {
        foreach ($defaults as $language => $tones) {
            foreach ($tones as $tone => $texts) {
                $current[$language][$tone] = $this->list($current[$language][$tone] ?? [], $texts, $max);
            }
        }

        return $current;
    }

    /**
     * @param list<string> $current
     * @param list<string> $defaults
     * @return list<string>
     */
    private function list(array $current, array $defaults, int $max): array
    {
        $merged = array_slice(array_values(array_unique([...$current, ...$defaults])), 0, $max);

        $this->added += max(0, count($merged) - count($current));

        return $merged;
    }
}
