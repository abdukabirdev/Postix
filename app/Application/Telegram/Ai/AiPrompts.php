<?php

declare(strict_types=1);

namespace App\Application\Telegram\Ai;

use App\Models\Telegram\TelegramSetting;
use InvalidArgumentException;

/**
 * The prompts of the AI: the defaults of config/ai.php, and the panel's
 * edited copy of any of them (Driver Check > ИИ > Промпты). An edited one
 * wins; "reset" drops the edit and the default is used again - so a better
 * default shipped later reaches every prompt nobody touched.
 */
final class AiPrompts
{
    public const KEY = 'ai.prompts';

    public const MAX_LENGTH = 20000;

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys((array) config('ai.prompts', []));
    }

    public function get(string $key): string
    {
        $edited = $this->edited();

        if (isset($edited[$key]) && is_string($edited[$key]) && trim($edited[$key]) !== '') {
            return trim($edited[$key]);
        }

        return $this->default($key);
    }

    public function default(string $key): string
    {
        return trim((string) config("ai.prompts.{$key}", ''));
    }

    /**
     * @return array<string, array{text: string, default: string, edited: bool}>
     */
    public function all(): array
    {
        $edited = $this->edited();
        $list = [];

        foreach ($this->keys() as $key) {
            $list[$key] = [
                'text' => $this->get($key),
                'default' => $this->default($key),
                'edited' => isset($edited[$key]) && trim((string) $edited[$key]) !== '',
            ];
        }

        return $list;
    }

    public function save(string $key, string $text): void
    {
        $this->known($key);

        $edited = $this->edited();
        $text = trim(str_replace("\r\n", "\n", $text));

        if ($text === '' || $text === $this->default($key)) {
            unset($edited[$key]);
        } else {
            $edited[$key] = mb_substr($text, 0, self::MAX_LENGTH);
        }

        TelegramSetting::set(self::KEY, $edited);
    }

    public function reset(string $key): void
    {
        $this->known($key);

        $edited = $this->edited();
        unset($edited[$key]);

        TelegramSetting::set(self::KEY, $edited);
    }

    /**
     * @return array<string, string>
     */
    private function edited(): array
    {
        $stored = TelegramSetting::get(self::KEY, []);

        return is_array($stored) ? $stored : [];
    }

    private function known(string $key): void
    {
        if (! in_array($key, $this->keys(), true)) {
            throw new InvalidArgumentException("Unknown prompt: {$key}");
        }
    }
}
