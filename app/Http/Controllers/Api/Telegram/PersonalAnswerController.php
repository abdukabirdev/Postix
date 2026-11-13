<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Telegram;

use App\Application\Telegram\Services\AutoReplyMedia;
use App\Application\Telegram\Services\AutoReplyStore;
use App\Application\Telegram\Services\ClientCheckRulesStore;
use App\Application\Telegram\Services\PersonalAnswers;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Telegram\PersonalAnswerRequest;
use App\Models\Telegram\OperationUser;
use Illuminate\Http\JsonResponse;

/**
 * Behind the "Личные ответы" page: a person's own answers per situation
 * (PersonalAnswers), kept on their card. Files are uploaded through the
 * auto replies (AutoReplyController::upload(), the Telegram GIF search)
 * and live in the same folder.
 *
 * Access is enforced by the `role:driverCheck,superadmin` middleware on the
 * route group (see routes/web.php).
 */
final class PersonalAnswerController extends Controller
{
    public function __construct(
        private readonly PersonalAnswers $personal,
        private readonly AutoReplyStore $autoReplies,
        private readonly ClientCheckRulesStore $penalties,
        private readonly AutoReplyMedia $media,
    ) {
    }

    /**
     * Everyone, with how much of their own they have, and the situations
     * an answer can be given for.
     */
    public function index(): JsonResponse
    {
        $people = OperationUser::query()
            ->orderBy('name')
            ->get(['id', 'name', 'role', 'language', 'respectful', 'address', 'telegram_username', 'is_active', 'dm_enabled', 'personal_answers'])
            ->map(fn (OperationUser $person): array => [
                ...$this->person($person),
                'counts' => $this->personal->counts($person),
            ]);

        return response()->json([
            'data' => $people,
            'situations' => $this->situations(),
        ]);
    }

    public function show(OperationUser $operationUser): JsonResponse
    {
        return $this->respond($operationUser);
    }

    public function update(PersonalAnswerRequest $request, OperationUser $operationUser): JsonResponse
    {
        $slots = PersonalAnswers::sanitize($request->validated('slots', []));

        $operationUser->update(['personal_answers' => $slots !== [] ? $slots : null]);

        /*
         * A voice taken out of here goes once nothing else uses it.
         */
        $this->media->prune([...$this->autoReplies->current()->mediaFiles(), ...$this->personal->mediaFiles()]);

        return $this->respond($operationUser, __('telegram.personal_answers.messages.saved'));
    }

    private function respond(OperationUser $person, ?string $message = null): JsonResponse
    {
        return response()->json([
            'data' => [
                ...$this->person($person),
                'counts' => $this->personal->counts($person),
                /*
                 * An object even when empty: the page adds slots by key.
                 */
                'slots' => (object) $this->personal->all($person),
            ],
            ...($message !== null ? ['message' => $message] : []),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function person(OperationUser $person): array
    {
        return [
            'id' => $person->id,
            'name' => $person->name,
            'role' => $person->roleOrDefault(),
            'language' => $person->messageLanguage(),
            'respectful' => (bool) $person->respectful,
            'address' => $person->addressFor($person->messageLanguage()),
            'telegram_username' => $person->telegramUsername(),
            'is_active' => (bool) $person->is_active,
            'dm_enabled' => (bool) $person->dm_enabled,
        ];
    }

    /**
     * What a personal answer can be given for, by slot: the penalty levels
     * of each role, the auto reply kinds and greetings, the nudge.
     *
     * @return array<string, mixed>
     */
    private function situations(): array
    {
        $penalties = $this->penalties->current();
        $autoReplies = $this->autoReplies->current();

        $levels = [];

        foreach (OperationUser::ROLES as $role) {
            $levels[$role] = array_map(
                static fn (array $level, int $index): array => [
                    'slot' => PersonalAnswers::penaltySlot($index),
                    'name' => $level['name'],
                    'from' => $level['from'],
                ],
                $penalties->levels($role),
                array_keys($penalties->levels($role)),
            );
        }

        return [
            'penalties' => $levels,
            'replies' => array_map(
                static fn (array $reply, int $index): array => [
                    'slot' => PersonalAnswers::replySlot($reply['id']),
                    'name' => $reply['name'],
                    'index' => $index,
                ],
                $autoReplies->replies,
                array_keys($autoReplies->replies),
            ),
            'greetings' => array_map(
                static fn (array $greeting, int $index): array => [
                    'slot' => PersonalAnswers::greetingSlot($greeting['id']),
                    'name' => $greeting['name'],
                    'index' => $index,
                ],
                $autoReplies->greetings['list'],
                array_keys($autoReplies->greetings['list']),
            ),
            'silence' => PersonalAnswers::SILENCE,
            'opening' => PersonalAnswers::OPENING,
            'ping' => PersonalAnswers::PING,
        ];
    }
}
