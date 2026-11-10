<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * The map of the driver-check panel: zone -> section -> subsection -> page.
 *
 *   zone        a big part of the work (the checks, our groups, the system)
 *   section     one product inside it, shown in the left menu
 *   subsection  the kind of page inside the section (work, data, settings)
 *   page        one page, one job
 *
 * The layout draws the left menu from here, the page header its trail, the
 * home page the whole map and the palette its list. A page that has to be
 * reachable from a second section is listed there again with `also` - the
 * link opens the page in its own section.
 */
final class DriverCheckNav
{
    public const HOME = '/driver-check';

    /**
     * [section key, zone, icon, [subsection key => [[page key, path, icon, also?], ...]]]
     */
    private const MAP = [
        ['checks', 'check', 'check-circle', [
            'results' => [
                ['operation_users', 'operation-users', 'users'],
                ['drivers', 'drivers', 'truck'],
                ['resolved_phones', 'resolved-phones', 'phone'],
            ],
            'sources' => [
                ['chats', 'chats', 'send'],
            ],
        ]],
        ['penalties', 'check', 'flag', [
            'log' => [
                ['penalties', 'penalties', 'flag'],
            ],
            'setup' => [
                ['penalty_sending', 'penalties/sending', 'sliders-h'],
                ['penalty_texts', 'penalties/settings', 'pencil'],
            ],
        ]],
        ['people', 'check', 'users', [
            'staff' => [
                ['people', 'people', 'users'],
                ['operators', 'operators', 'operator'],
                ['sales', 'sales', 'trending'],
            ],
        ]],
        ['messages', 'check', 'message', [
            'auto' => [
                ['auto_main', 'auto-replies', 'sliders-h'],
                ['auto_keywords', 'auto-replies/keywords', 'message'],
                ['auto_greetings', 'auto-replies/greetings', 'spark'],
                ['auto_reminders', 'auto-replies/reminders', 'clock'],
            ],
            'personal' => [
                ['personal_answers', 'personal-answers', 'user'],
            ],
            'to_groups' => [
                ['scheduled_messages', 'scheduled-messages', 'calendar'],
                ['file_routes', 'file-routes', 'route'],
                ['file_log', 'file-routes/log', 'layers'],
            ],
        ]],
        ['contacts', 'groups', 'book', [
            'work' => [
                ['contact_overview', 'contact-report', 'play'],
                ['contact_runs', 'contact-report/runs', 'layers'],
            ],
            'data' => [
                ['contacts', 'contacts', 'book'],
                ['contact_groups', 'contact-sync', 'send'],
                ['contact_accounts', 'contact-accounts', 'phone'],
            ],
            'setup' => [
                ['contact_settings', 'contact-settings', 'sliders-h'],
            ],
        ]],
        ['join', 'groups', 'search', [
            'work' => [
                ['join_status', 'group-join', 'pulse'],
                ['join_log', 'group-join/log', 'layers'],
            ],
            'groups' => [
                ['join_joined', 'group-join/joined', 'check-circle'],
                ['join_queue', 'group-join/queue', 'clock'],
                ['join_rejected', 'group-join/rejected', 'x-circle'],
            ],
            'data' => [
                ['join_people', 'group-join/people', 'users'],
            ],
            'setup' => [
                ['join_keywords', 'group-join/keywords', 'hash'],
                ['join_settings', 'group-join/settings', 'sliders-h'],
            ],
        ]],
        ['invites', 'groups', 'send', [
            'work' => [
                ['invites_overview', 'group-invites', 'play'],
                ['invites_log', 'group-invites/log', 'layers'],
                ['invites_analysis', 'group-invites/analysis', 'chart'],
                ['invites_ranking', 'group-invites/ranking', 'trending'],
            ],
            'data' => [
                ['invites_groups', 'group-invites/groups', 'send'],
                ['invites_people', 'group-invites/people', 'users'],
                ['invites_accounts', 'group-invites/accounts', 'phone'],
            ],
            'drivers' => [
                ['invites_drivers', 'group-invites/drivers', 'truck'],
            ],
            'setup' => [
                ['invites_settings', 'group-invites/settings', 'sliders-h'],
            ],
        ]],
        ['group_bot', 'groups', 'bot', [
            'bot' => [
                ['bot_connection', 'group-invites/bot', 'key'],
                ['bot_reports', 'group-invites/bot/reports', 'mail'],
            ],
            'requests' => [
                ['bot_requests', 'group-invites/bot/requests', 'inbox'],
            ],
            'members' => [
                ['bot_members', 'group-invites/bot/members', 'logout'],
            ],
            'spam' => [
                ['spam_list', 'group-invites/spam', 'shield'],
                ['spam_admins', 'group-invites/spam/admins', 'users'],
                ['spam_messages', 'group-invites/spam/messages', 'trash'],
            ],
        ]],
        ['telegram', 'system', 'telegram', [
            'accounts' => [
                ['sessions', 'sessions', 'key'],
                ['listeners', 'listeners', 'bot'],
                ['processes', 'watchdog', 'pulse', true],
            ],
            'duties' => [
                ['listener_duties', 'listeners/duties', 'route'],
                ['system', 'system', 'book'],
            ],
        ]],
        ['monitoring', 'system', 'pulse', [
            'control' => [
                ['control', 'control', 'play'],
            ],
            'processes' => [
                ['processes', 'watchdog', 'pulse'],
                ['driver_check_process', 'watchdog/driver-check', 'check-circle'],
                ['queue', 'queue', 'layers'],
            ],
            'problems' => [
                ['alerts', 'alerts', 'alert'],
            ],
        ]],
    ];

    /** @var array<string, array> */
    private static array $cache = [];

    /**
     * The zones in their order, each with its sections.
     *
     * @return array<int, array{key: string, title: string, sections: array}>
     */
    public static function zones(): array
    {
        $zones = [];

        foreach (self::tree() as $section) {
            $zones[$section['zone']] ??= [
                'key' => $section['zone'],
                'title' => __('driver_check.tree.zones.' . $section['zone']),
                'sections' => [],
            ];

            $zones[$section['zone']]['sections'][] = $section;
        }

        return array_values($zones);
    }

    /**
     * Every section with its subsections and pages, translated.
     */
    public static function tree(): array
    {
        $locale = app()->getLocale();

        if (isset(self::$cache[$locale])) {
            return self::$cache[$locale];
        }

        $tree = [];

        foreach (self::MAP as [$key, $zone, $icon, $subsections]) {
            $groups = [];

            foreach ($subsections as $subKey => $pages) {
                $items = [];

                foreach ($pages as $page) {
                    $items[] = [
                        'key' => $page[0],
                        'name' => __('driver_check.tree.pages.' . $page[0]),
                        'path' => self::HOME . '/' . $page[1],
                        'icon' => $page[2],
                        'also' => $page[3] ?? false,
                    ];
                }

                $groups[] = [
                    'key' => $subKey,
                    'title' => __("driver_check.tree.sections.{$key}.{$subKey}"),
                    'items' => $items,
                ];
            }

            $tree[] = [
                'key' => $key,
                'zone' => $zone,
                'icon' => $icon,
                'title' => __("driver_check.tree.sections.{$key}.title"),
                'about' => __("driver_check.tree.sections.{$key}.about"),
                'groups' => $groups,
                'first' => $groups[0]['items'][0]['path'],
            ];
        }

        return self::$cache[$locale] = $tree;
    }

    /**
     * Where a path sits: its section, subsection and page, or null for the
     * home page and the pages outside the map (the profile). The longest
     * matching page wins, so /penalties/settings is not /penalties.
     *
     * @return array{section: array, group: array, item: array}|null
     */
    public static function locate(?string $path = null): ?array
    {
        $path = '/' . trim($path ?? request()->path(), '/');
        $found = null;

        foreach (self::tree() as $section) {
            foreach ($section['groups'] as $group) {
                foreach ($group['items'] as $item) {
                    if ($item['also']) {
                        continue;
                    }

                    $hit = $path === $item['path'] || str_starts_with($path, $item['path'] . '/');

                    if ($hit && ($found === null || strlen($item['path']) > strlen($found['item']['path']))) {
                        $found = ['section' => $section, 'group' => $group, 'item' => $item];
                    }
                }
            }
        }

        return $found;
    }

    /**
     * The flat list for the palette: page, and where it lives.
     */
    public static function palette(): array
    {
        $list = [];

        foreach (self::tree() as $section) {
            foreach ($section['groups'] as $group) {
                foreach ($group['items'] as $item) {
                    if ($item['also']) {
                        continue;
                    }

                    $list[] = [
                        'name' => $item['name'],
                        'group' => $section['title'] . ' · ' . $group['title'],
                        'path' => url($item['path']),
                        'icon' => $item['icon'],
                    ];
                }
            }
        }

        return $list;
    }
}
