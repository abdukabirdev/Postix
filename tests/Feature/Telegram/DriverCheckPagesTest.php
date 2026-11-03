<?php

namespace Tests\Feature\Telegram;

use App\Helpers\DriverCheckNav;
use App\Helpers\MenuHelper;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The map of the driver-check panel: every page of the left menu opens in
 * its place, a page cut out of a bigger one shows only its own part, and the
 * home page and the control desk carry the whole map and every start button.
 */
class DriverCheckPagesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        /* The project migrations are MySQL-only: only what the pages read while rendering. */
        foreach (['telegram_settings', 'images', 'users', 'roles'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->unsignedBigInteger('role_id')->nullable();
            $table->rememberToken();
            $table->softDeletes();
            $table->timestamps();
        });

        /* The layout loads the signed-in user's avatar. */
        Schema::create('images', function (Blueprint $table): void {
            $table->id();
            $table->string('path')->nullable();
            $table->morphs('imageable');
            $table->timestamps();
        });

        Schema::create('telegram_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 64)->unique();
            $table->json('value')->nullable();
            $table->timestamps();
        });

        config()->set('auto_replies.path', storage_path('framework/testing/auto-replies-' . bin2hex(random_bytes(4)) . '.json'));

        $role = Role::query()->create(['name' => 'driverCheck']);

        $this->actingAs(User::query()->create(['name' => 'Checker', 'role_id' => $role->id]));
    }

    public function test_every_page_of_the_map_opens_in_its_place(): void
    {
        foreach (DriverCheckNav::tree() as $section) {
            foreach ($section['groups'] as $group) {
                foreach ($group['items'] as $item) {
                    $response = $this->get($item['path']);

                    $response->assertOk();

                    if ($item['also']) {
                        continue;
                    }

                    /* The trail above the title: the section and the subsection. */
                    $where = DriverCheckNav::locate($item['path']);

                    $this->assertSame($section['key'], $where['section']['key'], $item['path']);
                    $this->assertSame($group['key'], $where['group']['key'], $item['path']);

                    $response->assertSee('<nav class="dc-trail"', false);
                    $response->assertSee(e($group['title']), false);
                    $response->assertSee('aria-current="page"', false);
                }
            }
        }
    }

    public function test_the_longest_page_wins_and_a_page_listed_twice_opens_in_its_own_section(): void
    {
        $this->assertSame('penalty_texts', DriverCheckNav::locate('/driver-check/penalties/settings')['item']['key']);
        $this->assertSame('penalties', DriverCheckNav::locate('/driver-check/penalties')['item']['key']);
        $this->assertSame('bot_reports', DriverCheckNav::locate('/driver-check/group-invites/bot/reports')['item']['key']);
        $this->assertSame('operation_users', DriverCheckNav::locate('/driver-check/operation-users/7')['item']['key']);

        /* Processes is also under Telegram, but it lives in Monitoring. */
        $this->assertSame('monitoring', DriverCheckNav::locate('/driver-check/watchdog')['section']['key']);

        $this->assertNull(DriverCheckNav::locate('/driver-check'));
        $this->assertNull(DriverCheckNav::locate('/driver-check/profile'));
    }

    public function test_the_home_page_is_the_whole_map(): void
    {
        $response = $this->get('/driver-check')->assertOk();

        foreach (DriverCheckNav::tree() as $section) {
            $response->assertSee('id="' . $section['key'] . '"', false);
            $response->assertSee(e($section['title']), false);
        }

        $response->assertSee(route('driver-check.control'), false);
    }

    public function test_the_control_desk_has_every_start_and_stop(): void
    {
        $response = $this->get('/driver-check/control')->assertOk();

        foreach ([
            url('/api/telegram/listener-processes'),
            route('api.telegram.contact-sync.run'),
            route('api.telegram.contact-sync.stop'),
            route('api.telegram.group-invites.run'),
            route('api.telegram.group-invites.stop'),
            route('api.telegram.group-invites.check-left'),
            route('api.telegram.group-join.settings'),
            route('api.telegram.group-invites.bot.update'),
            route('api.telegram.queue.summary'),
        ] as $endpoint) {
            $response->assertSee(str_replace('/', '\/', $endpoint), false);
        }
    }

    public function test_a_page_cut_out_of_a_bigger_one_shows_only_its_part(): void
    {
        /* Penalty settings: the texts apart from the switches. */
        $this->get('/driver-check/penalties/settings')->assertOk()
            ->assertSee('addLevel()', false)
            ->assertDontSee("toggleSetting('enabled')", false);

        $this->get('/driver-check/penalties/sending')->assertOk()
            ->assertSee("toggleSetting('enabled')", false)
            ->assertDontSee('addLevel()', false);

        /* Auto replies: one part of the file per page. */
        $this->get('/driver-check/auto-replies')->assertOk()
            ->assertSee('form.only_after_penalty', false)
            ->assertDontSee('addKind()', false);

        $this->get('/driver-check/auto-replies/keywords')->assertOk()
            ->assertSee('addKind()', false)
            ->assertDontSee('form.greetings.enabled', false);

        $this->get('/driver-check/auto-replies/greetings')->assertOk()
            ->assertSee('form.greetings.enabled', false)
            ->assertSee('form.opening.enabled', false)
            ->assertDontSee('form.silence.enabled', false);

        $this->get('/driver-check/auto-replies/reminders')->assertOk()
            ->assertSee('form.silence.enabled', false)
            ->assertSee('form.follow_up.enabled', false)
            ->assertDontSee('form.greetings.enabled', false);

        /* The join bot: the connection, the reports, the requests. */
        $this->get('/driver-check/group-invites/bot')->assertOk()
            ->assertSee('connect()', false)
            ->assertDontSee('saveReports()', false);

        $this->get('/driver-check/group-invites/bot/reports')->assertOk()
            ->assertSee('saveReports()', false)
            ->assertDontSee('setKind', false);

        $this->get('/driver-check/group-invites/bot/requests')->assertOk()
            ->assertSee('setKind', false)
            ->assertDontSee('saveReports()', false);

        /* The spam list apart from what the bot deleted. */
        $this->get('/driver-check/group-invites/spam')->assertOk()
            ->assertSee('remove(sender)', false)
            ->assertDontSee('setStatus', false);

        $this->get('/driver-check/group-invites/spam/messages')->assertOk()
            ->assertSee('setStatus', false)
            ->assertDontSee('remove(sender)', false);

        /* No page of a section repeats the section's pages as a second strip of tabs. */
        $this->get('/driver-check/group-invites/log')->assertOk()
            ->assertDontSee('aria-label="' . e(__('driver_check.sections.invites')) . '"', false);
    }

    public function test_the_postix_menu_of_the_role_is_the_same_map(): void
    {
        $groups = MenuHelper::getDriverCheckGroups();

        $this->assertCount(count(DriverCheckNav::tree()), $groups);

        $paths = collect($groups)->pluck('items')->flatten(1)->pluck('path');

        /* Every page once: the copies listed in a second section are left out. */
        $this->assertSame($paths->unique()->count(), $paths->count());
        $this->assertContains('/driver-check/control', $paths->all());
    }
}
