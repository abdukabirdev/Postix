<?php

declare(strict_types=1);

namespace App\Models\Telegram;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One Telegram user found in a watched group. Unique by telegram_user_id.
 *
 * @property int         $id
 * @property int         $telegram_user_id
 */
class TelegramScrapedUser extends Model
{
    protected $fillable = [
        'telegram_user_id',
        'username',
        'first_name',
        'last_name',
        'phone',
        'is_bot',
        'can_add_to_group',
        'add_to_group_error',
        'add_to_group_checked_at',
    ];

    protected function casts(): array
    {
        return [
            'telegram_user_id' => 'integer',
            'is_bot' => 'boolean',
            'can_add_to_group' => 'boolean',
            'add_to_group_checked_at' => 'datetime',
        ];
    }

    protected function phone(): Attribute
    {
        return Attribute::set(function (?string $value) {
            $normalised = $value === null ? '' : TelegramAccount::normalizePhone($value);

            return $normalised === '' ? null : $normalised;
        });
    }

    /**
     * The contact sync's people: in one of its groups, or in none (saved
     * from an account's contacts). Those only Join group found - in groups
     * of type database alone - are left out.
     */
    public function scopeForContacts(Builder $query): Builder
    {
        return $query->where(fn ($q) => $q
            ->whereDoesntHave('groups')
            ->orWhereHas('groups', fn ($g) => $g->where('type', TelegramScrapeGroup::TYPE_CONTACTS)));
    }

    /**
     * People with a public @username: anybody reaches them by the link, so a
     * contact adds nothing - they are kept in the database only.
     */
    public function scopeWithUsername(Builder $query): Builder
    {
        return $query->whereNotNull('username')->where('username', '!=', '');
    }

    /**
     * People without a @username: a contact is the only way to reach them.
     */
    public function scopeWithoutUsername(Builder $query): Builder
    {
        return $query->where(fn ($q) => $q->whereNull('username')->orWhere('username', ''));
    }

    /**
     * People a group invite may still try: Telegram has not refused them
     * because of themselves (privacy, a deleted account, too many groups).
     */
    public function scopeAddableToGroup(Builder $query): Builder
    {
        return $query->where(fn ($q) => $q->whereNull('can_add_to_group')->orWhere('can_add_to_group', true));
    }

    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(
            TelegramScrapeGroup::class,
            'telegram_scrape_group_user',
            'scraped_user_id',
            'group_id',
        )->withPivot('seen_at');
    }

    /**
     * The accounts that have this person in their Telegram contacts.
     */
    public function accountLinks(): HasMany
    {
        return $this->hasMany(TelegramAccountContactLink::class, 'scraped_user_id');
    }

    public function contact(): HasOne
    {
        return $this->hasOne(TelegramAccountContact::class, 'scraped_user_id');
    }
}
