<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The admins of our anti-spam, kept on their own page: their links in our
 * groups are not deleted, they may forward spam to the join request bot.
 * The owner (whoever the bot's reports go to) is one always, unlisted.
 *
 * Typed in the panel by ID, @username or a t.me link - one typed by
 * @username gets their id the first time the bot sees them; or come by an
 * invite link the panel makes (t.me/<bot>?start=<invite_token>): whoever
 * opens it and presses Start is an admin, the link is spent. An invite
 * not opened yet is a line with the token and no person.
 *
 * The Telegram ids typed in the old field "Кто может пересылать боту спам"
 * come over as admins.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_spam_admins', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('telegram_user_id')->nullable();
            /* Lowercase, without the @. */
            $table->string('username', 64)->nullable();
            $table->string('name')->nullable();
            $table->string('note')->nullable();
            /* An invite link not opened yet: the /start parameter, good once. */
            $table->string('invite_token', 64)->nullable()->unique();
            $table->timestamp('invite_expires_at')->nullable();
            /* When they came by the invite link. */
            $table->timestamp('joined_at')->nullable();
            $table->timestamps();

            $table->index('telegram_user_id');
            $table->index('username');
        });

        $saved = DB::table('telegram_settings')->where('key', 'group_invite.join_bot.spam')->value('value');
        $saved = is_string($saved) ? json_decode($saved, true) : null;
        $ids = is_array($saved) && is_array($saved['moderators'] ?? null) ? $saved['moderators'] : [];

        foreach (array_unique(array_map('intval', array_filter($ids, 'is_numeric'))) as $id) {
            if ($id > 0) {
                DB::table('telegram_spam_admins')->insert([
                    'telegram_user_id' => $id,
                    'note' => 'Из поля «Кто может пересылать боту спам»',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_spam_admins');
    }
};
