<?php

declare(strict_types=1);

namespace App\Console\Commands\Telegram;

use App\Application\Telegram\Services\MadelineService;
use App\Application\Telegram\Services\TelegramAccountProcessService;
use App\Application\Telegram\Services\TelegramContactResolver;
use App\Application\Telegram\Services\TelegramNameMatcher;
use App\Application\Telegram\Support\SessionSleep;
use App\Enums\Drivers\TelegramDriverCheckStatus;
use App\Enums\Drivers\TelegramDriverMessageType;
use App\Enums\Telegram\TelegramAccountProcess as TelegramAccountProcessEnum;
use App\Models\Driver\TelegramDriverCheck;
use App\Models\Telegram\TelegramResolvedPhone;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Revolt\EventLoop;
use Throwable;

final class ResolveTelegramPhoneCommand extends Command
{
    protected $signature = 'telegram:resolve-phone {checkId}';

    protected $description =
        'Resolve driver phone using five different random Telegram accounts';

    private const MAX_ATTEMPTS = 5;

    /**
     * How many cancelled operations may be retried on a fresh session
     * without costing an attempt.
     *
     * A cancellation is a wedged connection, so retrying is worth it;
     * but when Telegram itself is unreachable every session is
     * cancelled, and an unbounded retry would keep a check spinning
     * forever.
     */
    private const MAX_CANCELLED_RETRIES = 2;

    public function handle(
        MadelineService $madelineService,
        TelegramContactResolver $resolver,
        TelegramNameMatcher $nameMatcher,
        TelegramAccountProcessService $processService,
    ): int {
        $checkId = (int) $this->argument('checkId');

        /*
         * An exception thrown in a MadelineProto callback ("The endpoint does
         * not exist!" from its IPC client) would take the process down in the
         * middle of a check. It is logged; the call it belonged to fails or
         * is cancelled, and the cancellation handling below takes over.
         */
        EventLoop::setErrorHandler(static function (Throwable $e) use ($checkId): void {
            Log::error('Phone resolver: an exception in the event loop', [
                'check_id' => $checkId,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        });

        /*
         * ============================================================
         * 1. GET CHECK
         * ============================================================
         */
        $check = TelegramDriverCheck::query()
            ->with([
                'driver',
                'operationUser',
            ])
            ->find($checkId);

        if (! $check) {
            $this->error(
                "Check #{$checkId} not found.",
            );

            Log::warning(
                'ResolveTelegramPhoneCommand check not found',
                [
                    'check_id' => $checkId,
                ],
            );

            return self::FAILURE;
        }

        /*
         * ============================================================
         * 2. ONLY DRIVER CHECKS
         * ============================================================
         *
         * An update is a check of its own when it changed the phone of
         * a driver this listener had never checked
         * (ProcessUpdatedDriverMessage).
         */
        if (
            $check->type !==
            TelegramDriverMessageType::CREATED_DRIVER
            && $check->type !==
            TelegramDriverMessageType::UPDATED_DRIVER
        ) {
            Log::info(
                'ResolveTelegramPhoneCommand skipped non-created-driver check',
                [
                    'check_id' => $check->id,
                    'type' => $check->type?->value,
                ],
            );

            return self::SUCCESS;
        }

        /*
         * ============================================================
         * 3. DRIVER MUST EXIST
         * ============================================================
         */
        if (! $check->driver_id) {
            $check->update([
                'status' =>
                    TelegramDriverCheckStatus::NotConfirmed,

                'error_message' =>
                    'Telegram driver is not assigned to check.',

                'checked_at' =>
                    now(),
            ]);

            Log::warning(
                'Telegram driver is missing for check',
                [
                    'check_id' => $check->id,
                ],
            );

            return self::SUCCESS;
        }

        /*
         * ============================================================
         * 4. ALREADY FINISHED
         * ============================================================
         */
        if (
            $check->status ===
                TelegramDriverCheckStatus::Confirmed
            ||
            $check->status ===
                TelegramDriverCheckStatus::NotConfirmed
        ) {
            return self::SUCCESS;
        }

        /*
         * ============================================================
         * 5. PHONE IS REQUIRED
         * ============================================================
         */
        if (! $check->phone_normalized) {
            $check->update([
                'status' =>
                    TelegramDriverCheckStatus::NotConfirmed,

                'error_message' =>
                    'Phone number is missing.',

                'checked_at' =>
                    now(),
            ]);

            $check->driver?->update([
                'status' => 'not_confirmed',
            ]);

            return self::SUCCESS;
        }

        /*
         * ============================================================
         * 6. ATOMICALLY CLAIM CHECK
         * ============================================================
         */
        $claimed = TelegramDriverCheck::query()
            ->whereKey($check->id)
            ->where(
                'status',
                TelegramDriverCheckStatus::Pending,
            )
            ->update([
                'status' =>
                    TelegramDriverCheckStatus::Processing,

                'error_message' => null,
            ]);

        if ($claimed === 0) {
            return self::SUCCESS;
        }

        $check->refresh();

        try {
            /*
             * ========================================================
             * 7. CACHE FIRST
             * ========================================================
             */
            $resolvedPhone = TelegramResolvedPhone::query()
                ->where(
                    'phone_normalized',
                    $check->phone_normalized,
                )
                ->first();

            if ($resolvedPhone) {
                Log::info(
                    'Telegram resolved phone found in cache',
                    [
                        'check_id' =>
                            $check->id,

                        'phone' =>
                            $check->phone_normalized,

                        'resolved_phone_id' =>
                            $resolvedPhone->id,

                        'telegram_user_id' =>
                            $resolvedPhone->telegram_user_id,
                    ],
                );

                $this->applyResolvedPhone(
                    check: $check,
                    resolvedPhone: $resolvedPhone,
                    nameMatcher: $nameMatcher,
                );

                return self::SUCCESS;
            }

            /*
             * ========================================================
             * 8. INITIALIZE RESOLVER STATE
             * ========================================================
             */
            $telegramRaw = $this->getTelegramRaw($check);

            $telegramRaw['resolver_attempts'] = [];
            $telegramRaw['resolver_attempt_account_ids'] = [];
            $telegramRaw['resolver_not_registered_count'] = 0;
            $telegramRaw['resolver_real_error_count'] = 0;
            $telegramRaw['resolver_result'] = null;

            $check->update([
                'attempts' => 0,
                'telegram_raw' => $telegramRaw,
            ]);

            $usedAccountIds = [];

            $notRegisteredCount = 0;

            $realErrorCount = 0;

            $cancelledRetries = 0;

            $lastError =
                'Telegram resolve failed.';

            /*
             * ========================================================
             * 9. FIVE ATTEMPTS
             * ========================================================
             */
            for (
                $attempt = 1;
                $attempt <= self::MAX_ATTEMPTS;
                $attempt++
            ) {
                /*
                 * ----------------------------------------------------
                 * CLAIM RANDOM ACCOUNT
                 * ----------------------------------------------------
                 */
                $account = $processService
                    ->claimRandomAvailableAccount(
                        TelegramAccountProcessEnum::ResolverPhone,
                        $usedAccountIds,
                    );

                /*
                 * ----------------------------------------------------
                 * NO ACCOUNT AVAILABLE
                 * ----------------------------------------------------
                 */
                if (! $account) {
                    $message =
                        'Resolver accounts are unavailable.';

                    $telegramRaw =
                        $this->getTelegramRaw($check);

                    $telegramRaw['resolver_result'] =
                        'resolver_unavailable';

                    $telegramRaw[
                        'resolver_unavailable_at_attempt'
                    ] = $attempt;

                    $telegramRaw[
                        'resolver_used_account_ids'
                    ] = $usedAccountIds;

                    $telegramRaw[
                        'resolver_not_registered_count'
                    ] = $notRegisteredCount;

                    $telegramRaw[
                        'resolver_real_error_count'
                    ] = $realErrorCount;

                    $check->update([
                        'status' =>
                            TelegramDriverCheckStatus::NotConfirmed,

                        'error_message' =>
                            $message,

                        'telegram_raw' =>
                            $telegramRaw,

                        'checked_at' =>
                            now(),
                    ]);

                    $check->driver?->update([
                        'status' =>
                            'not_confirmed',
                    ]);

                    Log::warning(
                        'Telegram resolver accounts unavailable',
                        [
                            'check_id' =>
                                $check->id,

                            'phone' =>
                                $check->phone_normalized,

                            'attempt' =>
                                $attempt,

                            'used_account_ids' =>
                                $usedAccountIds,
                        ],
                    );

                    return self::SUCCESS;
                }

                $accountId = (int) $account->id;

                /*
                 * Account cannot be reused in this check.
                 */
                $usedAccountIds[] = $accountId;

                $usedAccountIds = array_values(
                    array_unique(
                        $usedAccountIds,
                    ),
                );

                /*
                 * Save current attempt state.
                 */
                $telegramRaw =
                    $this->getTelegramRaw($check);

                $telegramRaw[
                    'resolver_current_attempt'
                ] = $attempt;

                $telegramRaw[
                    'resolver_last_account_id'
                ] = $accountId;

                $telegramRaw[
                    'resolver_last_account_phone'
                ] = $account->phone;

                $telegramRaw[
                    'resolver_attempt_account_ids'
                ] = $usedAccountIds;

                $check->update([
                    'attempts' =>
                        $attempt,

                    'telegram_raw' =>
                        $telegramRaw,
                ]);

                $api = null;

                try {
                    /*
                     * ------------------------------------------------
                     * START MADELINE
                     * ------------------------------------------------
                     */
                    $this->info(
                        sprintf(
                            '[%d/%d] Using account %s',
                            $attempt,
                            self::MAX_ATTEMPTS,
                            $account->phone,
                        ),
                    );

                    Log::info(
                        'Telegram resolver attempt started',
                        [
                            'check_id' =>
                                $check->id,

                            'attempt' =>
                                $attempt,

                            'account_id' =>
                                $accountId,

                            'phone' =>
                                $account->phone,

                            'used_account_ids' =>
                                $usedAccountIds,
                        ],
                    );

                    $api =
                        $madelineService->for(
                            $account,
                        );

                    if (! $api) {
                        throw new \RuntimeException(
                            'Failed to start MadelineProto.',
                        );
                    }

                    /*
                     * ------------------------------------------------
                     * RESOLVE PHONE
                     * ------------------------------------------------
                     *
                     * IMPORTANT:
                     * The resolver may catch Throwable internally
                     * and return an array instead.
                     */
                    $result =
                        $resolver->resolve(
                            $api,
                            $check->phone_normalized,
                            /*
                             * Diagnostic context only: lets the resolver name
                             * the check/attempt/account in its own cancellation
                             * log. Does not affect resolving or retries.
                             */
                            [
                                'check_id' =>
                                    $check->id,

                                'attempt' =>
                                    $attempt,

                                'account_id' =>
                                    $accountId,

                                'account_phone' =>
                                    $account->phone,
                            ],
                        );

                    /*
                     * ------------------------------------------------
                     * CANCELLED: A FRESH SESSION, NOT A LOST ATTEMPT
                     * ------------------------------------------------
                     *
                     * "The operation was cancelled" is an Amp timeout
                     * on the MTProto call. It says the connection
                     * behind this session is wedged - not that the
                     * account is bad and not that the phone is
                     * unknown. On the same session every further call
                     * is cancelled the same way, which is how a check
                     * ended up reporting nothing but "The operation was
                     * cancelled" after spending all five accounts.
                     *
                     * The answer is another session, and the cleanest
                     * way to get one is to end this attempt: the
                     * finally below stops MadelineProto, the account
                     * goes back in the pool, and the next pass opens a
                     * session from scratch - on this account or on
                     * another, whichever the pool hands out. The
                     * attempt is not charged, because nothing was
                     * asked of Telegram that it managed to answer.
                     *
                     * Bounded by MAX_CANCELLED_RETRIES so a Telegram
                     * side outage, where every session is cancelled,
                     * still terminates.
                     */
                    if (
                        $this->isCancelledResult($result)
                    ) {
                        $this->logCancelledResolverResult(
                            check: $check,
                            attempt: $attempt,
                            accountId: $accountId,
                            accountPhone: $account->phone,
                            result: $result,
                        );

                        if (
                            $cancelledRetries < self::MAX_CANCELLED_RETRIES
                        ) {
                            $cancelledRetries++;

                            /*
                             * The account was never really tried, so it
                             * is not spent: put it back in the pool.
                             */
                            $usedAccountIds = array_values(
                                array_diff(
                                    $usedAccountIds,
                                    [$accountId],
                                ),
                            );

                            /*
                             * Undo this iteration's charge against the
                             * attempt budget; the for-loop's increment
                             * puts it straight back.
                             */
                            $attempt--;

                            Log::warning(
                                'Telegram resolver retrying a cancelled operation on a new session',
                                [
                                    'check_id' =>
                                        $check->id,

                                    'account_id' =>
                                        $accountId,

                                    'cancelled_retries' =>
                                        $cancelledRetries,

                                    'max_cancelled_retries' =>
                                        self::MAX_CANCELLED_RETRIES,
                                ],
                            );

                            continue;
                        }

                        Log::critical(
                            'Telegram resolver out of cancellation retries',
                            [
                                'check_id' =>
                                    $check->id,

                                'account_id' =>
                                    $accountId,

                                'cancelled_retries' =>
                                    $cancelledRetries,
                            ],
                        );
                    }

                    $success =
                        (bool) (
                            $result['success']
                            ?? false
                        );

                    $reason =
                        (string) (
                            $result['reason']
                            ?? 'telegram_error'
                        );

                    $errorMessage =
                        $result['error_message']
                        ?? null;

                    Log::info(
                        'Telegram phone resolve result',
                        [
                            'check_id' =>
                                $check->id,

                            'attempt' =>
                                $attempt,

                            'account_id' =>
                                $accountId,

                            'phone' =>
                                $check->phone_normalized,

                            'success' =>
                                $success,

                            'reason' =>
                                $reason,

                            'error_message' =>
                                $errorMessage,
                        ],
                    );

                    /*
                     * ------------------------------------------------
                     * SAVE ATTEMPT HISTORY
                     * ------------------------------------------------
                     */
                    $telegramRaw =
                        $this->getTelegramRaw($check);

                    $attemptHistory =
                        $telegramRaw[
                            'resolver_attempts'
                        ] ?? [];

                    if (! is_array($attemptHistory)) {
                        $attemptHistory = [];
                    }

                    $attemptHistory[] = [
                        'attempt' =>
                            $attempt,

                        'account_id' =>
                            $accountId,

                        'account_phone' =>
                            $account->phone,

                        'success' =>
                            $success,

                        'reason' =>
                            $reason,

                        'error_message' =>
                            $errorMessage,

                        'at' =>
                            now()->toISOString(),
                    ];

                    $telegramRaw[
                        'resolver_attempts'
                    ] = $attemptHistory;

                    $check->update([
                        'telegram_raw' =>
                            $telegramRaw,
                    ]);

                    /*
                     * ------------------------------------------------
                     * PHONE NOT REGISTERED
                     * ------------------------------------------------
                     */
                    if (
                        ! $success
                        &&
                        $reason === 'telegram_not_registered'
                    ) {
                        $notRegisteredCount++;

                        $processService->registerNotFound(
                            $account,
                            TelegramAccountProcessEnum::ResolverPhone,
                            $reason,
                        );

                        $telegramRaw =
                            $this->getTelegramRaw($check);

                        $telegramRaw[
                            'resolver_not_registered_count'
                        ] = $notRegisteredCount;

                        $check->update([
                            'telegram_raw' =>
                                $telegramRaw,
                        ]);

                        $this->warn(
                            sprintf(
                                '[%d/%d] %s -> NOT REGISTERED',
                                $attempt,
                                self::MAX_ATTEMPTS,
                                $account->phone,
                            ),
                        );

                        continue;
                    }

                    /*
                     * ------------------------------------------------
                     * REAL TELEGRAM / ACCOUNT ERROR
                     * ------------------------------------------------
                     */
                    if (! $success) {
                        $realErrorCount++;

                        $lastError =
                            (string) (
                                $errorMessage
                                ?? $reason
                            );

                        $state =
                            $processService->registerFailure(
                                $account,
                                TelegramAccountProcessEnum::ResolverPhone,
                                $reason,
                            );

                        Log::warning(
                            'Telegram resolver account failure',
                            [
                                'check_id' =>
                                    $check->id,

                                'attempt' =>
                                    $attempt,

                                'account_id' =>
                                    $accountId,

                                'phone' =>
                                    $account->phone,

                                'reason' =>
                                    $reason,

                                'error' =>
                                    $lastError,

                                'failures' =>
                                    $state->failures,

                                'consecutive_failures' =>
                                    $state->consecutive_failures,

                                'is_available' =>
                                    $state->is_available,
                            ],
                        );

                        continue;
                    }

                    /*
                     * ------------------------------------------------
                     * SUCCESS BUT USER IS MISSING
                     * ------------------------------------------------
                     */
                    $user =
                        $result['user'] ?? null;

                    if (! $user) {
                        $realErrorCount++;

                        $lastError =
                            'Telegram returned no user.';

                        $state =
                            $processService->registerFailure(
                                $account,
                                TelegramAccountProcessEnum::ResolverPhone,
                                'telegram_user_missing',
                            );

                        Log::warning(
                            'Telegram resolver returned no user',
                            [
                                'check_id' =>
                                    $check->id,

                                'attempt' =>
                                    $attempt,

                                'account_id' =>
                                    $accountId,

                                'failures' =>
                                    $state->failures,

                                'consecutive_failures' =>
                                    $state->consecutive_failures,
                            ],
                        );

                        continue;
                    }

                    /*
                     * ------------------------------------------------
                     * EXTRACT TELEGRAM USER
                     * ------------------------------------------------
                     */
                    $telegramUserId =
                        isset($user['id'])
                            ? (int) $user['id']
                            : null;

                    $telegramUsername =
                        $user['username']
                        ?? null;

                    $telegramFirstName =
                        $user['first_name']
                        ?? null;

                    $telegramLastName =
                        $user['last_name']
                        ?? null;

                    /*
                     * ------------------------------------------------
                     * SAVE RESOLVED PHONE
                     * ------------------------------------------------
                     */
                    $resolvedPhone =
                        TelegramResolvedPhone::query()
                            ->updateOrCreate(
                                [
                                    'phone_normalized' =>
                                        $check->phone_normalized,
                                ],
                                [
                                    'telegram_user_id' =>
                                        $telegramUserId,

                                    'telegram_username' =>
                                        $telegramUsername,

                                    'telegram_first_name' =>
                                        $telegramFirstName,

                                    'telegram_last_name' =>
                                        $telegramLastName,

                                    'telegram_raw' =>
                                        $result['raw']
                                        ?? null,

                                    'telegram_account_id' =>
                                        $accountId,

                                    'driver_id' =>
                                        $check->driver_id,

                                    'resolved_at' =>
                                        now(),
                                ],
                            );

                    /*
                     * ------------------------------------------------
                     * ACCOUNT SUCCESS
                     * ------------------------------------------------
                     */
                    $state =
                        $processService->registerSuccess(
                            $account,
                            TelegramAccountProcessEnum::ResolverPhone,
                        );

                    /*
                     * ------------------------------------------------
                     * SAVE RESOLVER RESULT
                     * ------------------------------------------------
                     */
                    $telegramRaw =
                        $this->getTelegramRaw($check);

                    $telegramRaw[
                        'resolver_result'
                    ] = 'registered';

                    $telegramRaw[
                        'resolver_success_attempt'
                    ] = $attempt;

                    $telegramRaw[
                        'resolver_success_account_id'
                    ] = $accountId;

                    $telegramRaw[
                        'resolver_not_registered_count'
                    ] = $notRegisteredCount;

                    $telegramRaw[
                        'resolver_real_error_count'
                    ] = $realErrorCount;

                    $telegramRaw[
                        'resolver_success_account_failures'
                    ] = $state->failures;

                    $telegramRaw[
                        'resolver_success_account_consecutive_failures'
                    ] = $state->consecutive_failures;

                    $check->update([
                        'telegram_raw' =>
                            $telegramRaw,
                    ]);

                    Log::info(
                        'Telegram phone resolved successfully',
                        [
                            'check_id' =>
                                $check->id,

                            'phone' =>
                                $check->phone_normalized,

                            'attempt' =>
                                $attempt,

                            'account_id' =>
                                $accountId,

                            'telegram_user_id' =>
                                $telegramUserId,

                            'not_registered_count' =>
                                $notRegisteredCount,
                        ],
                    );

                    /*
                     * ------------------------------------------------
                     * NAME MATCH + FINAL STATUS
                     * ------------------------------------------------
                     */
                    $this->applyResolvedPhone(
                        check: $check,
                        resolvedPhone: $resolvedPhone,
                        nameMatcher: $nameMatcher,
                    );

                    return self::SUCCESS;
                } catch (Throwable $e) {
                    /*
                     * ------------------------------------------------
                     * ATTEMPT EXCEPTION
                     * ------------------------------------------------
                     */

                    /*
                     * applyResolvedPhone() writes the verdict and only
                     * then touches the driver row and the console, so a
                     * throw can land here with the check already
                     * decided. Carrying on would blame the account for
                     * it, spend the rest of the pool, and let block 12
                     * below overwrite a correct verdict with this
                     * message - which is exactly the failure the
                     * verdict guard exists to prevent.
                     */
                    $decided = $check->fresh() ?? $check;

                    if (
                        $this->hasVerdict(
                            $decided,
                            $this->getTelegramRaw($decided),
                        )
                    ) {
                        Log::warning(
                            'Telegram resolver attempt failed after the verdict was decided, verdict kept',
                            [
                                'check_id' =>
                                    $check->id,

                                'attempt' =>
                                    $attempt,

                                'account_id' =>
                                    $accountId,

                                'error' =>
                                    $e->getMessage(),

                                'exception' =>
                                    $e::class,
                            ],
                        );

                        return self::SUCCESS;
                    }

                    $realErrorCount++;

                    $lastError =
                        mb_substr(
                            $e->getMessage(),
                            0,
                            1000,
                        );

                    $state =
                        $processService->registerFailure(
                            $account,
                            TelegramAccountProcessEnum::ResolverPhone,
                            'command_exception',
                        );

                    /*
                     * FULL EXCEPTION LOG
                     */
                    Log::error(
                        'Telegram resolver attempt exception',
                        [
                            'check_id' =>
                                $check->id,

                            'attempt' =>
                                $attempt,

                            'account_id' =>
                                $accountId,

                            'phone' =>
                                $account->phone,

                            'error' =>
                                $lastError,

                            'exception' =>
                                $e::class,

                            /*
                             * FULL THROWABLE DETAILS
                             */
                            'exception_message' =>
                                $e->getMessage(),

                            'exception_code' =>
                                $e->getCode(),

                            'exception_file' =>
                                $e->getFile(),

                            'exception_line' =>
                                $e->getLine(),

                            'exception_trace' =>
                                $e->getTraceAsString(),

                            /*
                             * PREVIOUS THROWABLE
                             */
                            'previous_exception' =>
                                $e->getPrevious() !== null
                                    ? $e->getPrevious()::class
                                    : null,

                            'previous_message' =>
                                $e->getPrevious()?->getMessage(),

                            'previous_code' =>
                                $e->getPrevious()?->getCode(),

                            'previous_file' =>
                                $e->getPrevious()?->getFile(),

                            'previous_line' =>
                                $e->getPrevious()?->getLine(),

                            'previous_trace' =>
                                $e->getPrevious()?->getTraceAsString(),

                            /*
                             * PROCESS STATE
                             */
                            'failures' =>
                                $state->failures,

                            'consecutive_failures' =>
                                $state->consecutive_failures,

                            'is_available' =>
                                $state->is_available,
                        ],
                    );

                    /*
                     * SPECIAL CANCELLED LOG
                     */
                    if (
                        $this->isCancelledThrowable($e)
                    ) {
                        Log::critical(
                            'Telegram resolver CANCELLED OPERATION - FULL THROWABLE',
                            [
                                'check_id' =>
                                    $check->id,

                                'attempt' =>
                                    $attempt,

                                'account_id' =>
                                    $accountId,

                                'phone' =>
                                    $account->phone,

                                'exception' =>
                                    $e::class,

                                'message' =>
                                    $e->getMessage(),

                                'code' =>
                                    $e->getCode(),

                                'file' =>
                                    $e->getFile(),

                                'line' =>
                                    $e->getLine(),

                                'trace' =>
                                    $e->getTraceAsString(),

                                'previous' =>
                                    $e->getPrevious()
                                        ? [
                                            'class' =>
                                                $e->getPrevious()::class,

                                            'message' =>
                                                $e->getPrevious()->getMessage(),

                                            'code' =>
                                                $e->getPrevious()->getCode(),

                                            'file' =>
                                                $e->getPrevious()->getFile(),

                                            'line' =>
                                                $e->getPrevious()->getLine(),

                                            'trace' =>
                                                $e->getPrevious()->getTraceAsString(),
                                        ]
                                        : null,
                            ],
                        );
                    }

                    continue;
                } finally {
                    /*
                     * ------------------------------------------------
                     * STOP MADELINE
                     * ------------------------------------------------
                     */
                    if ($api) {
                        try {
                            SessionSleep::put($api, [
                                'check_id' => $check->id,
                                'account_id' => $accountId,
                            ]);

                            Log::info(
                                'MadelineProto stopped',
                                [
                                    'check_id' =>
                                        $check->id,

                                    'attempt' =>
                                        $attempt,

                                    'account_id' =>
                                        $accountId,
                                ],
                            );
                        } catch (Throwable $e) {
                            Log::warning(
                                'Failed to stop MadelineProto',
                                [
                                    'check_id' =>
                                        $check->id,

                                    'attempt' =>
                                        $attempt,

                                    'account_id' =>
                                        $accountId,

                                    'error' =>
                                        $e->getMessage(),

                                    'exception' =>
                                        $e::class,

                                    /*
                                     * Diagnostic information.
                                     */
                                    'exception_file' =>
                                        $e->getFile(),

                                    'exception_line' =>
                                        $e->getLine(),

                                    'exception_trace' =>
                                        $e->getTraceAsString(),

                                    'previous_exception' =>
                                        $e->getPrevious() !== null
                                            ? $e->getPrevious()::class
                                            : null,

                                    'previous_message' =>
                                        $e->getPrevious()?->getMessage(),

                                    'previous_trace' =>
                                        $e->getPrevious()?->getTraceAsString(),
                                ],
                            );
                        }
                    }

                    /*
                     * ------------------------------------------------
                     * RELEASE ACCOUNT
                     * ------------------------------------------------
                     */
                    $processService->release(
                        $account,
                        TelegramAccountProcessEnum::ResolverPhone,
                    );
                }
            }

            /*
             * ========================================================
             * 10. ALL ATTEMPTS FINISHED
             * ========================================================
             */
            $check->refresh();

            $telegramRaw =
                $this->getTelegramRaw($check);

            /*
             * A verdict reached during the loop ends the command here.
             * Blocks 11 and 12 below both write a final status
             * unconditionally, so without this a check that was decided
             * and then hit trouble on the way out would be rewritten as
             * "not confirmed" with whatever the last error happened to
             * be.
             */
            if ($this->hasVerdict($check, $telegramRaw)) {
                Log::warning(
                    'Telegram resolver loop ended after the verdict was decided, verdict kept',
                    [
                        'check_id' =>
                            $check->id,

                        'status' =>
                            $check->status?->value,

                        'error' =>
                            $lastError,
                    ],
                );

                return self::SUCCESS;
            }

            $telegramRaw[
                'resolver_finished'
            ] = true;

            $telegramRaw[
                'resolver_attempts_count'
            ] = self::MAX_ATTEMPTS;

            $telegramRaw[
                'resolver_not_registered_count'
            ] = $notRegisteredCount;

            $telegramRaw[
                'resolver_real_error_count'
            ] = $realErrorCount;

            /*
             * ========================================================
             * 11. ALL FIVE = PHONE NOT REGISTERED
             * ========================================================
             */
            if (
                $notRegisteredCount ===
                self::MAX_ATTEMPTS
            ) {
                $telegramRaw[
                    'resolver_result'
                ] = 'phone_not_registered';

                $check->update([
                    'status' =>
                        TelegramDriverCheckStatus::NotConfirmed,

                    'error_message' =>
                        null,

                    'telegram_raw' =>
                        $telegramRaw,

                    'checked_at' =>
                        now(),
                ]);

                $check->driver?->update([
                    'status' =>
                        'not_confirmed',
                ]);

                $this->warn(
                    "❌ {$check->phone_normalized} "
                    . 'is NOT REGISTERED on Telegram.',
                );

                return self::SUCCESS;
            }

            /*
             * ========================================================
             * 12. RESOLVER FAILED
             * ========================================================
             */
            $telegramRaw[
                'resolver_result'
            ] = 'resolver_failed_without_match';

            $telegramRaw[
                'resolver_error'
            ] = $lastError;

            $check->update([
                'status' =>
                    TelegramDriverCheckStatus::NotConfirmed,

                'error_message' =>
                    $lastError,

                'telegram_raw' =>
                    $telegramRaw,

                'checked_at' =>
                    now(),
            ]);

            $check->driver?->update([
                'status' =>
                    'not_confirmed',
            ]);

            Log::warning(
                'Telegram resolver completed without successful match',
                [
                    'check_id' =>
                        $check->id,

                    'phone' =>
                        $check->phone_normalized,

                    'attempts' =>
                        self::MAX_ATTEMPTS,

                    'not_registered_count' =>
                        $notRegisteredCount,

                    'real_error_count' =>
                        $realErrorCount,

                    'used_account_ids' =>
                        $usedAccountIds,

                    'error' =>
                        $lastError,
                ],
            );

            return self::FAILURE;
        } catch (Throwable $e) {
            /*
             * ========================================================
             * 13. GLOBAL ERROR
             * ========================================================
             */
            $error =
                mb_substr(
                    $e->getMessage(),
                    0,
                    1000,
                );

            /*
             * FULL GLOBAL THROWABLE
             */
            Log::error(
                'ResolveTelegramPhoneCommand failed',
                [
                    'check_id' =>
                        $check->id,

                    'phone' =>
                        $check->phone_normalized,

                    'attempts' =>
                        $check->attempts,

                    'error' =>
                        $error,

                    'exception' =>
                        $e::class,

                    'exception_message' =>
                        $e->getMessage(),

                    'exception_code' =>
                        $e->getCode(),

                    'exception_file' =>
                        $e->getFile(),

                    'exception_line' =>
                        $e->getLine(),

                    'exception_trace' =>
                        $e->getTraceAsString(),

                    'previous_exception' =>
                        $e->getPrevious() !== null
                            ? $e->getPrevious()::class
                            : null,

                    'previous_message' =>
                        $e->getPrevious()?->getMessage(),

                    'previous_code' =>
                        $e->getPrevious()?->getCode(),

                    'previous_file' =>
                        $e->getPrevious()?->getFile(),

                    'previous_line' =>
                        $e->getPrevious()?->getLine(),

                    'previous_trace' =>
                        $e->getPrevious()?->getTraceAsString(),
                ],
            );

            /*
             * SPECIAL GLOBAL CANCELLED LOG
             */
            if (
                $this->isCancelledThrowable($e)
            ) {
                Log::critical(
                    'ResolveTelegramPhoneCommand CANCELLED OPERATION - FULL THROWABLE',
                    [
                        'check_id' =>
                            $check->id,

                        'phone' =>
                            $check->phone_normalized,

                        'attempts' =>
                            $check->attempts,

                        'exception' =>
                            $e::class,

                        'message' =>
                            $e->getMessage(),

                        'code' =>
                            $e->getCode(),

                        'file' =>
                            $e->getFile(),

                        'line' =>
                            $e->getLine(),

                        'trace' =>
                            $e->getTraceAsString(),

                        'previous' =>
                            $e->getPrevious()
                                ? [
                                    'class' =>
                                        $e->getPrevious()::class,

                                    'message' =>
                                        $e->getPrevious()->getMessage(),

                                    'code' =>
                                        $e->getPrevious()->getCode(),

                                    'file' =>
                                        $e->getPrevious()->getFile(),

                                    'line' =>
                                        $e->getPrevious()->getLine(),

                                    'trace' =>
                                        $e->getPrevious()->getTraceAsString(),
                                ]
                                : null,
                    ],
                );
            }

            $check->refresh();

            $telegramRaw =
                $this->getTelegramRaw($check);

            /*
             * ========================================================
             * 13a. A LATE FAILURE MUST NOT ERASE A VERDICT
             * ========================================================
             *
             * Everything below this point runs after the phone was
             * resolved and the name compared. If the check already
             * carries its verdict, whatever went wrong afterwards is a
             * problem with this run, not with the driver: overwriting
             * the verdict here is how check #882 came back "NOT
             * CONFIRMED - Undefined property:
             * danog\MadelineProto\Exception::$class" while its own
             * report, one block further down, printed a name match of
             * 96. The error is recorded next to the verdict instead.
             */
            if ($this->hasVerdict($check, $telegramRaw)) {
                $telegramRaw[
                    'resolver_result'
                ] = 'command_failed_after_verdict';

                $telegramRaw[
                    'post_verdict_error'
                ] = $error;

                $check->update([
                    'telegram_raw' =>
                        $telegramRaw,
                ]);

                Log::warning(
                    'ResolveTelegramPhoneCommand failed after the verdict was decided, verdict kept',
                    [
                        'check_id' =>
                            $check->id,

                        'status' =>
                            $check->status?->value,

                        'error' =>
                            $error,
                    ],
                );

                return self::SUCCESS;
            }

            $telegramRaw[
                'resolver_result'
            ] = 'command_failed';

            $check->update([
                'status' =>
                    TelegramDriverCheckStatus::NotConfirmed,

                'error_message' =>
                    $error,

                'telegram_raw' =>
                    $telegramRaw,

                'checked_at' =>
                    now(),
            ]);

            $check->driver?->update([
                'status' =>
                    'not_confirmed',
            ]);

            return self::FAILURE;
        }
    }

    /**
     * Has this check already been decided?
     *
     * A verdict is a finished comparison: a final status, the time it
     * was reached, and the name_match that produced it. All three
     * together, because a status alone can also be the claim marker
     * being rolled back.
     *
     * @param  array<string, mixed>  $telegramRaw
     */
    private function hasVerdict(
        TelegramDriverCheck $check,
        array $telegramRaw,
    ): bool {
        if ($check->checked_at === null) {
            return false;
        }

        if (
            $check->status !== TelegramDriverCheckStatus::Confirmed
            && $check->status !== TelegramDriverCheckStatus::NotConfirmed
        ) {
            return false;
        }

        return isset($telegramRaw['name_match']);
    }

    /**
     * Check whether the resolver returned a cancelled operation.
     */
    private function isCancelledResult(
        mixed $result,
    ): bool {
        if (! is_array($result)) {
            return false;
        }

        if (($result['cancelled'] ?? false) === true) {
            return true;
        }

        $message = mb_strtolower(
            (string) (
                $result['error_message']
                ?? $result['message']
                ?? ''
            ),
        );

        $exception = mb_strtolower(
            (string) (
                $result['exception']
                ?? $result['exception_class']
                ?? ''
            ),
        );

        return str_contains(
            $message,
            'operation was cancelled',
        )
        || str_contains(
            $exception,
            'cancelledexception',
        );
    }

    /**
     * Check whether Throwable is an Amp cancellation.
     */
    private function isCancelledThrowable(
        Throwable $e,
    ): bool {
        return $e instanceof \Amp\CancelledException
            || str_contains(
                mb_strtolower(
                    $e->getMessage(),
                ),
                'operation was cancelled',
            )
            || str_contains(
                mb_strtolower(
                    $e::class,
                ),
                'cancelledexception',
            );
    }

    /**
     * Log all useful diagnostic information returned by resolver.
     *
     * We deliberately do not log `user` or `raw` here because they
     * may contain personal Telegram data and are not necessary for
     * diagnosing the cancellation.
     */
    private function logCancelledResolverResult(
        TelegramDriverCheck $check,
        int $attempt,
        int $accountId,
        string $accountPhone,
        array $result,
    ): void {
        Log::critical(
            'Telegram phone resolver returned CANCELLED OPERATION',
            [
                'check_id' =>
                    $check->id,

                'attempt' =>
                    $attempt,

                'account_id' =>
                    $accountId,

                'account_phone' =>
                    $accountPhone,

                /*
                 * Resolver result.
                 */
                'success' =>
                    $result['success']
                    ?? null,

                'reason' =>
                    $result['reason']
                    ?? null,

                'error_message' =>
                    $result['error_message']
                    ?? null,

                /*
                 * Original exception, if resolver provides it.
                 */
                'exception' =>
                    $result['exception']
                    ?? $result['exception_class']
                    ?? null,

                'exception_message' =>
                    $result['exception_message']
                    ?? null,

                'exception_code' =>
                    $result['exception_code']
                    ?? null,

                'exception_file' =>
                    $result['exception_file']
                    ?? null,

                'exception_line' =>
                    $result['exception_line']
                    ?? null,

                'exception_trace' =>
                    $result['exception_trace']
                    ?? null,

                /*
                 * Previous exception.
                 */
                'previous_exception' =>
                    $result['previous_exception']
                    ?? null,

                'previous_message' =>
                    $result['previous_message']
                    ?? null,

                'previous_code' =>
                    $result['previous_code']
                    ?? null,

                'previous_file' =>
                    $result['previous_file']
                    ?? null,

                'previous_line' =>
                    $result['previous_line']
                    ?? null,

                'previous_trace' =>
                    $result['previous_trace']
                    ?? null,

                /*
                 * Process information.
                 */
                'pid' =>
                    getmypid(),

                'php_version' =>
                    PHP_VERSION,

                'timestamp' =>
                    now()->toISOString(),
            ],
        );
    }

    /**
     * Convert telegram_raw to array.
     */
    private function getTelegramRaw(
        TelegramDriverCheck $check,
    ): array {
        $raw = $check->telegram_raw;

        if (is_array($raw)) {
            return $raw;
        }

        if (
            is_string($raw)
            && $raw !== ''
        ) {
            $decoded = json_decode(
                $raw,
                true,
            );

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    /**
     * Apply resolved Telegram user to check.
     */
    private function applyResolvedPhone(
        TelegramDriverCheck $check,
        TelegramResolvedPhone $resolvedPhone,
        TelegramNameMatcher $nameMatcher,
    ): void {
        $telegramRaw =
            $this->getTelegramRaw(
                $check,
            );

        $telegramRaw[
            'resolved_from_cache'
        ] = false;

        $telegramRaw[
            'resolved_phone_id'
        ] = $resolvedPhone->id;

        /*
         * ------------------------------------------------------------
         * Telegram data → Check
         * ------------------------------------------------------------
         */
        $check->update([
            'telegram_resolved_phone_id' =>
                $resolvedPhone->id,

            'telegram_user_id' =>
                $resolvedPhone->telegram_user_id,

            'telegram_username' =>
                $resolvedPhone->telegram_username,

            'telegram_first_name' =>
                $resolvedPhone->telegram_first_name,

            'telegram_last_name' =>
                $resolvedPhone->telegram_last_name,

            'telegram_raw' =>
                $telegramRaw,

            'error_message' =>
                null,
        ]);

        /*
         * ------------------------------------------------------------
         * Name matching
         * ------------------------------------------------------------
         */
        /*
         * The username is evidence like any other field, and often the
         * only field carrying the name in Latin letters
         * (@zjorayev320 next to a Cyrillic display name). It was being
         * resolved, stored, printed in the report - and then dropped
         * before the comparison.
         */
        $match = $nameMatcher->match(
            $check->driver_name,
            $resolvedPhone->telegram_first_name,
            $resolvedPhone->telegram_last_name,
            $resolvedPhone->telegram_username,
        );

        $telegramRaw[
            'name_match'
        ] = $match;

        $matched =
            (bool) ($match['matched'] ?? false);

        /*
         * ------------------------------------------------------------
         * Check = history/result
         * ------------------------------------------------------------
         */
        $check->update([
            'telegram_raw' =>
                $telegramRaw,

            'status' =>
                $matched
                    ? TelegramDriverCheckStatus::Confirmed
                    : TelegramDriverCheckStatus::NotConfirmed,

            'checked_at' =>
                now(),
        ]);

        /*
         * ------------------------------------------------------------
         * Driver = CURRENT STATE
         * ------------------------------------------------------------
         */
        $check->driver?->update([
            'status' =>
                $matched
                    ? 'confirmed'
                    : 'not_confirmed',
        ]);

        if ($matched) {
            $this->info(
                "✅ Check #{$check->id} confirmed.",
            );
        } else {
            $this->warn(
                "❌ Check #{$check->id} not confirmed.",
            );
        }
    }
}