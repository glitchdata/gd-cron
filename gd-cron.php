<?php
/**
 * Plugin Name: GD Cron Manager
 * Description: Manage, run, and create WordPress cron events from the admin.
 * Version: 0.1.0
 * Author: Your Name
 * License: GPL-2.0-or-later
 */

if (!defined('ABSPATH')) {
    exit;
}

class GDCronManager
{
    private const MENU_SLUG = 'gd-cron-manager';
    private const EDIT_SLUG = 'gd-cron-manager-edit';
    private const EVENTS_SLUG = 'gd-cron-manager-events';
    private const SETTINGS_SLUG = 'gd-cron-manager-settings';
    private const LOGS_SLUG = 'gd-cron-manager-logs';
    private const NONCE_ACTION = 'gd-cron-action';
    private const OPTION_KEY = 'gd_cron_settings';
    private const LOG_OPTION_KEY = 'gd_cron_log';
    private const LOG_TABLE = 'gd_cron_logs';
    private const LOG_DB_VERSION_KEY = 'gd_cron_log_db_version';
    private const LOG_DB_VERSION = '1.0.0';
    private static array $registered_hooks = [];
    private array $notices = [];
    private array $settings = [];

    public function __construct()
    {
        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$this, 'add_plugin_link']);
        add_action('admin_head', [$this, 'hide_edit_submenu']);
        add_action('init', [self::class, 'maybe_create_log_table'], 0);
        add_action('init', [$this, 'register_cron_listeners'], 1);
        register_activation_hook(__FILE__, [self::class, 'activate']);
    }

    public static function activate(): void
    {
        self::maybe_create_log_table();
    }

    public function register_menu(): void
    {
        add_menu_page(
            __('GD Cron', 'gd-cron'),
            __('GD Cron', 'gd-cron'),
            'manage_options',
            self::MENU_SLUG,
            [$this, 'render_page'],
            'dashicons-clock',
            65
        );

        add_submenu_page(
            self::MENU_SLUG,
            __('Dashboard', 'gd-cron'),
            __('Dashboard', 'gd-cron'),
            'manage_options',
            self::MENU_SLUG,
            [$this, 'render_page']
        );

        add_submenu_page(
            self::MENU_SLUG,
            __('Settings', 'gd-cron'),
            __('Settings', 'gd-cron'),
            'manage_options',
            self::SETTINGS_SLUG,
            [$this, 'render_page']
        );

        add_submenu_page(
            self::MENU_SLUG,
            __('Events', 'gd-cron'),
            __('Events', 'gd-cron'),
            'manage_options',
            self::EVENTS_SLUG,
            [$this, 'render_page']
        );

        add_submenu_page(
            self::MENU_SLUG,
            __('Logs', 'gd-cron'),
            __('Logs', 'gd-cron'),
            'manage_options',
            self::LOGS_SLUG,
            [$this, 'render_page']
        );

        add_submenu_page(
            self::MENU_SLUG,
            __('Edit Cron Event', 'gd-cron'),
            __('Edit Cron Event', 'gd-cron'),
            'manage_options',
            self::EDIT_SLUG,
            [$this, 'render_edit_page']
        );
    }

    public function enqueue_assets(string $hook): void
    {
        $allowed = [
            'toplevel_page_' . self::MENU_SLUG,
            self::MENU_SLUG . '_page_' . self::EDIT_SLUG,
        ];

        if (!in_array($hook, $allowed, true)) {
            return;
        }

        $version = '0.1.0';
        $base = plugins_url('', __FILE__);

        wp_enqueue_style(
            'gd-cron-admin',
            $base . '/assets/admin.css',
            [],
            $version
        );

        wp_enqueue_script(
            'gd-cron-admin',
            $base . '/assets/admin.js',
            ['jquery'],
            $version,
            true
        );
    }

    public function render_page(): void
    {
        self::maybe_create_log_table();
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to access this page.', 'gd-cron'));
        }

        $this->settings = $this->get_settings();
        $this->prune_logs();
        $this->handle_actions();

        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : self::MENU_SLUG;
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : '';

        if ($page === self::EVENTS_SLUG) {
            $tab = 'events';
        } elseif ($page === self::SETTINGS_SLUG) {
            $tab = 'settings';
        } elseif ($page === self::LOGS_SLUG) {
            $tab = 'logs';
        }

        if ($tab === '') {
            $tab = 'dashboard';
        }

        $tab = in_array($tab, ['dashboard', 'events', 'settings', 'logs'], true) ? $tab : 'dashboard';

        $events = $this->get_cron_events();
        $schedules = wp_get_schedules();
        $now = $this->now();

        echo '<div class="wrap gd-audit gd-audit-settings">';
        echo '<div class="gd-audit__tabs">';
        echo '<h1 class="wp-heading-inline">' . esc_html__('GD Cron', 'gd-cron') . '</h1>';
        $base_url = admin_url('admin.php?page=' . self::MENU_SLUG);
        echo '<h2 class="nav-tab-wrapper">';
        echo '<a href="' . esc_url(add_query_arg('tab', 'dashboard', $base_url)) . '" class="nav-tab ' . ($tab === 'dashboard' ? 'nav-tab-active' : '') . '">' . esc_html__('Dashboard', 'gd-cron') . '</a>';
        echo '<a href="' . esc_url(add_query_arg('tab', 'settings', $base_url)) . '" class="nav-tab ' . ($tab === 'settings' ? 'nav-tab-active' : '') . '">' . esc_html__('Settings', 'gd-cron') . '</a>';
        echo '<a href="' . esc_url(add_query_arg('tab', 'events', $base_url)) . '" class="nav-tab ' . ($tab === 'events' ? 'nav-tab-active' : '') . '">' . esc_html__('Events', 'gd-cron') . '</a>';
        echo '<a href="' . esc_url(add_query_arg('tab', 'logs', $base_url)) . '" class="nav-tab ' . ($tab === 'logs' ? 'nav-tab-active' : '') . '">' . esc_html__('Logs', 'gd-cron') . '</a>';
        echo '</h2>';
        echo '</div>';
        $this->render_notices();
        if ($tab === 'settings') {
            echo '<h1 class="gd-audit__section-title">' . esc_html__('Settings', 'gd-cron') . '</h1>';
        }

        if ($tab === 'dashboard') {
            $this->render_dashboard($events, $now);
        } elseif ($tab === 'settings') {
            $this->render_settings_form($schedules);
        } elseif ($tab === 'events') {
            $this->render_events_table($events, $now);
        } else {
            $log_filters = [
                'hook' => isset($_GET['log_hook']) ? sanitize_text_field(wp_unslash($_GET['log_hook'])) : '',
                'action' => isset($_GET['log_action']) ? sanitize_text_field(wp_unslash($_GET['log_action'])) : '',
            ];
            $log_page = isset($_GET['log_page']) ? max(1, (int) $_GET['log_page']) : 1;
            $per_page = 30;
            $log_sort = isset($_GET['log_sort']) ? sanitize_key(wp_unslash($_GET['log_sort'])) : 'created_at';
            $log_dir = isset($_GET['log_dir']) ? sanitize_key(wp_unslash($_GET['log_dir'])) : 'desc';
            $this->render_log_panel(
                $this->get_log($log_filters, $per_page, $log_page, $log_sort, $log_dir),
                $log_filters,
                $page,
                $log_page,
                $this->get_log_count($log_filters),
                $per_page,
                $log_sort,
                $log_dir
            );
        }
        echo '</div>';
        echo '</div>';
    }

    private function render_settings_form(array $schedules): void
    {
        $default_schedule = $this->settings['default_schedule'] ?? 'once';
        $default_first_run_offset = (int) ($this->settings['default_first_run_offset'] ?? 300);
        $license_key = isset($this->settings['license_key']) ? (string) $this->settings['license_key'] : '';
        $require_confirm = !empty($this->settings['require_delete_confirmation']);

        echo '<form method="post" class="gd-audit__settings-form gd-cron-form">';
        wp_nonce_field(self::NONCE_ACTION);
        echo '<input type="hidden" name="gd_cron_action" value="save_settings">';

        echo '<h2>' . esc_html__('API Key', 'gd-cron') . '</h2>';
        echo '<p>' . esc_html__('Add your API key to unlock premium updates and support.', 'gd-cron') . '</p>';
        echo '<table class="form-table" role="presentation">';
        echo '<tbody>';
        echo '<tr><th scope="row">';
        echo '<label for="gd-cron-license-key">' . esc_html__('API key', 'gd-cron') . '</label>';
        echo '</th><td>';
        echo '<input type="text" id="gd-cron-license-key" name="license_key" value="' . esc_attr($license_key) . '" class="regular-text" autocomplete="off" placeholder="XXXX-XXXX-XXXX-XXXX">';
        echo '<p class="description">' . esc_html__('Paste the key from your purchase receipt. Leave blank to deactivate the API key on this site.', 'gd-cron') . '</p>';
        echo '<button type="button" class="button button-secondary" style="margin-top:10px;">' . esc_html__('Validate API Key', 'gd-cron') . '</button>';
        echo '<a class="button" style="margin-top:10px; margin-left:6px;" href="https://license.glitchdata.com/shop/GD-01" target="_blank" rel="noopener noreferrer">' . esc_html__('Purchase API Key', 'gd-cron') . '</a>';
        echo '</td></tr>';
        echo '</tbody>';
        echo '</table>';

        echo '<h2>' . esc_html__('Defaults', 'gd-cron') . '</h2>';
        echo '<table class="form-table" role="presentation">';
        echo '<tbody>';

        echo '<tr><th scope="row">' . esc_html__('Default first-run offset (seconds)', 'gd-cron') . '</th><td>';
        echo '<input type="number" name="default_first_run_offset" value="' . esc_attr($default_first_run_offset) . '" min="60" step="60" class="small-text">';
        echo '<p class="description">' . esc_html__('Used when no time is provided while scheduling. Minimum 60 seconds.', 'gd-cron') . '</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row">' . esc_html__('Default recurrence', 'gd-cron') . '</th><td>';
        echo '<select name="default_schedule">';
        echo '<option value="once"' . selected($default_schedule, 'once', false) . '>' . esc_html__('Once', 'gd-cron') . '</option>';
        foreach ($schedules as $key => $data) {
            $label = $data['display'] ?? $key;
            echo '<option value="' . esc_attr($key) . '"' . selected($default_schedule, $key, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
        echo '</td></tr>';

        echo '<tr><th scope="row">' . esc_html__('Delete confirmation', 'gd-cron') . '</th><td>';
        echo '<label><input type="checkbox" name="require_delete_confirmation" value="1"' . checked($require_confirm, true, false) . '> ' . esc_html__('Ask before deleting events', 'gd-cron') . '</label>';
        echo '</td></tr>';

        echo '<tr><th scope="row">' . esc_html__('Prune logs older than (days)', 'gd-cron') . '</th><td>';
        echo '<input type="number" name="log_retention_days" value="' . esc_attr($this->settings['log_retention_days'] ?? 30) . '" min="0" step="1" class="small-text">';
        echo '<p class="description">' . esc_html__('0 disables pruning. Applies on admin load.', 'gd-cron') . '</p>';
        echo '</td></tr>';

        echo '</tbody>';
        echo '</table>';

        echo '<p class="submit">';
        echo '<button type="submit" class="button button-primary">' . esc_html__('Save settings', 'gd-cron') . '</button>';
        echo '</p>';
        echo '</form>';
    }

    private function render_events_table(array $events, int $now): void
    {
        echo '<h2>' . esc_html__('Scheduled Events', 'gd-cron') . '</h2>';
        echo '<p class="description">' . esc_html__('Run or delete cron events from here. Use cautiously on production sites.', 'gd-cron') . '</p>';

        $hook_filter = isset($_GET['hook_filter']) ? sanitize_text_field(wp_unslash($_GET['hook_filter'])) : '';
        $schedule_filter = isset($_GET['schedule_filter']) ? sanitize_text_field(wp_unslash($_GET['schedule_filter'])) : '';
        $only_due = !empty($_GET['only_due']);

        $schedules = wp_get_schedules();

        $filtered = array_filter($events, function ($event) use ($hook_filter, $schedule_filter, $only_due, $now) {
            if ($hook_filter !== '' && stripos($event['hook'], $hook_filter) === false) {
                return false;
            }
            if ($schedule_filter !== '' && $event['schedule'] !== $schedule_filter) {
                return false;
            }
            if ($only_due && $event['timestamp'] > $now) {
                return false;
            }
            return true;
        });

        echo '<form method="get" class="gd-cron-filters">';
        echo '<input type="hidden" name="page" value="' . esc_attr(self::MENU_SLUG) . '">';
        echo '<label>' . esc_html__('Hook contains', 'gd-cron') . ' <input type="text" name="hook_filter" value="' . esc_attr($hook_filter) . '" placeholder="my_hook"></label>';
        echo '<label>' . esc_html__('Recurrence', 'gd-cron') . ' <select name="schedule_filter">';
        echo '<option value="">' . esc_html__('Any', 'gd-cron') . '</option>';
        echo '<option value="once"' . selected($schedule_filter, 'once', false) . '>' . esc_html__('Once', 'gd-cron') . '</option>';
        foreach ($schedules as $key => $data) {
            $label = $data['display'] ?? $key;
            echo '<option value="' . esc_attr($key) . '"' . selected($schedule_filter, $key, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></label>';
        echo '<label><input type="checkbox" name="only_due" value="1"' . checked($only_due, true, false) . '> ' . esc_html__('Due now/overdue only', 'gd-cron') . '</label>';
        echo '<button class="button">' . esc_html__('Filter', 'gd-cron') . '</button> ';
        $reset_url = admin_url('admin.php?page=' . self::MENU_SLUG);
        echo '<a class="button" href="' . esc_url($reset_url) . '">' . esc_html__('Reset', 'gd-cron') . '</a> ';
        echo '<a class="button button-primary" href="' . esc_url(admin_url('admin.php?page=' . self::EDIT_SLUG)) . '">' . esc_html__('Add New Event', 'gd-cron') . '</a>';
        echo '</form>';

        if (empty($filtered)) {
            echo '<p>' . esc_html__('No cron events found.', 'gd-cron') . '</p>';
            return;
        }

        echo '<table class="widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Hook', 'gd-cron') . '</th>';
        echo '<th>' . esc_html__('Next Run', 'gd-cron') . '</th>';
        echo '<th>' . esc_html__('Recurrence', 'gd-cron') . '</th>';
        echo '<th>' . esc_html__('Args', 'gd-cron') . '</th>';
        echo '<th class="column-actions">' . esc_html__('Actions', 'gd-cron') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($filtered as $event) {
            $args_display = empty($event['args']) ? '&ndash;' : esc_html(wp_json_encode($event['args']));
            $human_time = $event['timestamp'] <= $now
                ? esc_html__('Due now', 'gd-cron')
                : esc_html(human_time_diff($now, $event['timestamp'])) . ' ' . esc_html__('from now', 'gd-cron');
            $date = esc_html(get_date_from_gmt(gmdate('Y-m-d H:i:s', $event['timestamp']), 'Y-m-d H:i:s'));

            echo '<tr>';
            echo '<td><code>' . esc_html($event['hook']) . '</code></td>';
            echo '<td>' . $date . '<br><span class="gd-cron-sub">' . $human_time . '</span></td>';
            echo '<td>' . esc_html($event['schedule_label']) . '</td>';
            echo '<td class="gd-cron-args">' . $args_display . '</td>';
            echo '<td class="column-actions">';
            $this->render_action_buttons($event);
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    private function render_dashboard(array $events, int $now): void
    {
        $total_events = count($events);
        $unique_hooks = count(array_unique(array_map(fn($e) => $e['hook'], $events)));
        $due_count = count(array_filter($events, fn($e) => $e['timestamp'] <= $now));
        $recurring_count = count(array_filter($events, fn($e) => $e['schedule'] !== 'once'));
        $one_off_count = $total_events - $recurring_count;

        $next_event = null;
        foreach ($events as $event) {
            if ($event['timestamp'] > $now) {
                $next_event = $event;
                break;
            }
        }

        echo '<h2>' . esc_html__('Dashboard', 'gd-cron') . '</h2>';
        echo '<div class="gd-cron-stats">';
        echo '<div class="gd-cron-stat"><div class="gd-cron-stat__label">' . esc_html__('Total events', 'gd-cron') . '</div><div class="gd-cron-stat__value">' . esc_html($total_events) . '</div></div>';
        echo '<div class="gd-cron-stat"><div class="gd-cron-stat__label">' . esc_html__('Unique hooks', 'gd-cron') . '</div><div class="gd-cron-stat__value">' . esc_html($unique_hooks) . '</div></div>';
        echo '<div class="gd-cron-stat"><div class="gd-cron-stat__label">' . esc_html__('Due/overdue', 'gd-cron') . '</div><div class="gd-cron-stat__value">' . esc_html($due_count) . '</div></div>';
        echo '<div class="gd-cron-stat"><div class="gd-cron-stat__label">' . esc_html__('Recurring', 'gd-cron') . '</div><div class="gd-cron-stat__value">' . esc_html($recurring_count) . '</div></div>';
        echo '<div class="gd-cron-stat"><div class="gd-cron-stat__label">' . esc_html__('One-off', 'gd-cron') . '</div><div class="gd-cron-stat__value">' . esc_html($one_off_count) . '</div></div>';
        if ($next_event) {
            $next_time = esc_html(get_date_from_gmt(gmdate('Y-m-d H:i:s', $next_event['timestamp']), 'Y-m-d H:i:s'));
            $next_hook = esc_html($next_event['hook']);
            echo '<div class="gd-cron-stat"><div class="gd-cron-stat__label">' . esc_html__('Next run', 'gd-cron') . '</div><div class="gd-cron-stat__value">' . $next_time . '<br><span class="gd-cron-sub">' . $next_hook . '</span></div></div>';
        }
        echo '</div>';

        echo '<h3>' . esc_html__('Upcoming events', 'gd-cron') . '</h3>';
        echo '<table class="widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Hook', 'gd-cron') . '</th>';
        echo '<th>' . esc_html__('Next Run', 'gd-cron') . '</th>';
        echo '<th>' . esc_html__('Recurrence', 'gd-cron') . '</th>';
        echo '</tr></thead><tbody>';

        $count = 0;
        foreach ($events as $event) {
            if (++$count > 5) {
                break;
            }
            $date = esc_html(get_date_from_gmt(gmdate('Y-m-d H:i:s', $event['timestamp']), 'Y-m-d H:i:s'));
            echo '<tr>';
            echo '<td><code>' . esc_html($event['hook']) . '</code></td>';
            echo '<td>' . $date . '</td>';
            echo '<td>' . esc_html($event['schedule_label']) . '</td>';
            echo '</tr>';
        }

        if ($total_events === 0) {
            echo '<tr><td colspan="3">' . esc_html__('No cron events found.', 'gd-cron') . '</td></tr>';
        }

        echo '</tbody></table>';
    }

    private function render_create_form(array $schedules, int $now): void
    {
        echo '<h2>' . esc_html__('Schedule New Event', 'gd-cron') . '</h2>';
        echo '<form method="post" class="gd-cron-form">';
        wp_nonce_field(self::NONCE_ACTION);
        echo '<input type="hidden" name="gd_cron_action" value="create">';

        echo '<label class="gd-cron-field">';
        echo '<span>' . esc_html__('Hook name', 'gd-cron') . '</span>';
        echo '<input type="text" name="hook" required placeholder="my_custom_hook">';
        echo '</label>';

        echo '<label class="gd-cron-field">';
        echo '<span>' . esc_html__('Arguments (JSON optional)', 'gd-cron') . '</span>';
        echo '<textarea name="args" rows="3" placeholder="[\"value\", 123]"></textarea>';
        echo '</label>';

        echo '<label class="gd-cron-field">';
        echo '<span>' . esc_html__('First run (local time)', 'gd-cron') . '</span>';
        $offset = (int) ($this->settings['default_first_run_offset'] ?? 300);
        $offset = max(60, $offset);
        $default_time = date('Y-m-d H:i', $now + $offset);
        echo '<input type="text" name="first_run" value="' . esc_attr($default_time) . '" placeholder="2025-12-11 14:30">';
        echo '<p class="description">' . esc_html__('Format: YYYY-MM-DD HH:MM. Uses site timezone.', 'gd-cron') . '</p>';
        echo '</label>';

        echo '<label class="gd-cron-field">';
        echo '<span>' . esc_html__('Recurrence', 'gd-cron') . '</span>';
        echo '<select name="schedule">';
        $default_schedule = $this->settings['default_schedule'] ?? 'once';
        echo '<option value="once"' . selected($default_schedule, 'once', false) . '>' . esc_html__('Once', 'gd-cron') . '</option>';
        foreach ($schedules as $key => $schedule) {
            $label = $schedule['display'] ?? $key;
            echo '<option value="' . esc_attr($key) . '"' . selected($default_schedule, $key, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
        echo '</label>';

        echo '<button type="submit" class="button button-primary">' . esc_html__('Schedule event', 'gd-cron') . '</button>';
        echo '</form>';
    }

    private function render_action_buttons(array $event): void
    {
        echo '<div class="gd-cron-actions">';
        echo '<form method="post">';
        wp_nonce_field(self::NONCE_ACTION);
        echo '<input type="hidden" name="gd_cron_action" value="run">';
        echo '<input type="hidden" name="timestamp" value="' . esc_attr($event['timestamp']) . '">';
        echo '<input type="hidden" name="hook" value="' . esc_attr($event['hook']) . '">';
        echo '<input type="hidden" name="sig" value="' . esc_attr($event['sig']) . '">';
        echo '<button class="button">' . esc_html__('Run now', 'gd-cron') . '</button>';
        echo '</form>';

        echo '<form method="post" class="gd-cron-inline">';
        wp_nonce_field(self::NONCE_ACTION);
        echo '<input type="hidden" name="gd_cron_action" value="delete">';
        echo '<input type="hidden" name="timestamp" value="' . esc_attr($event['timestamp']) . '">';
        echo '<input type="hidden" name="hook" value="' . esc_attr($event['hook']) . '">';
        echo '<input type="hidden" name="sig" value="' . esc_attr($event['sig']) . '">';
        $confirm_attr = !empty($this->settings['require_delete_confirmation'])
            ? ' data-confirm="' . esc_attr__('Delete this event?', 'gd-cron') . '"'
            : '';
        echo '<button class="button button-secondary gd-cron-delete"' . $confirm_attr . '>' . esc_html__('Delete', 'gd-cron') . '</button>';
        echo '</form>';

        $edit_url = add_query_arg([
            'page' => self::EDIT_SLUG,
            'timestamp' => $event['timestamp'],
            'hook' => $event['hook'],
            'sig' => $event['sig'],
        ], admin_url('admin.php'));

        echo '<a class="button" href="' . esc_url($edit_url) . '">' . esc_html__('Edit', 'gd-cron') . '</a>';
        echo '</div>';
    }

    private function handle_actions(): void
    {
        if (empty($_POST['gd_cron_action'])) {
            return;
        }

        check_admin_referer(self::NONCE_ACTION);

        $action = sanitize_text_field(wp_unslash($_POST['gd_cron_action']));

        switch ($action) {
            case 'run':
                $this->handle_run();
                break;
            case 'delete':
                $this->handle_delete();
                break;
            case 'create':
                $this->handle_create();
                break;
            case 'save_settings':
                $this->handle_save_settings();
                break;
            case 'edit':
                $this->handle_edit();
                break;
        }
    }

    private function handle_run(): void
    {
        $event = $this->find_event_from_request('post', true);
        if (!$event) {
            $this->add_notice(__('Event not found.', 'gd-cron'), 'error');
            return;
        }

        // Run the hook immediately.
        do_action_ref_array($event['hook'], $event['args']);
        $this->add_notice(sprintf(__('Ran hook %s.', 'gd-cron'), esc_html($event['hook'])), 'success');
        $this->log_event('run', $event['hook'], 'Ran now from admin');
    }

    private function handle_delete(): void
    {
        $event = $this->find_event_from_request();
        if (!$event) {
            $this->add_notice(__('Event not found.', 'gd-cron'), 'error');
            return;
        }

        $unscheduled = wp_unschedule_event($event['timestamp'], $event['hook'], $event['args']);
        if ($unscheduled) {
            $this->add_notice(__('Event removed.', 'gd-cron'), 'success');
            $this->log_event('delete', $event['hook'], 'Removed specific instance');
        } else {
            $this->add_notice(__('Unable to remove event.', 'gd-cron'), 'error');
        }
    }

    private function handle_create(): void
    {
        $hook = isset($_POST['hook']) ? sanitize_text_field(wp_unslash($_POST['hook'])) : '';
        $schedule = isset($_POST['schedule']) ? sanitize_text_field(wp_unslash($_POST['schedule'])) : 'once';
        $args_raw = isset($_POST['args']) ? wp_unslash($_POST['args']) : '';
        $first_run_raw = isset($_POST['first_run']) ? sanitize_text_field(wp_unslash($_POST['first_run'])) : '';

        if (empty($hook)) {
            $this->add_notice(__('Hook name is required.', 'gd-cron'), 'error');
            return;
        }

        $args = [];
        if (!empty($args_raw)) {
            $decoded = json_decode($args_raw, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->add_notice(__('Arguments must be valid JSON.', 'gd-cron'), 'error');
                return;
            }
            $args = $decoded;
        }

        $timestamp = $this->parse_datetime($first_run_raw);
        if (!$timestamp) {
            $this->add_notice(__('Invalid date/time format.', 'gd-cron'), 'error');
            return;
        }

        $now = $this->now();
        if ($timestamp < $now) {
            $timestamp = $now + 60;
        }

        if ($schedule === 'once') {
            $scheduled = wp_schedule_single_event($timestamp, $hook, $args);
        } else {
            $schedules = wp_get_schedules();
            if (!isset($schedules[$schedule])) {
                $this->add_notice(__('Unknown schedule.', 'gd-cron'), 'error');
                return;
            }
            $scheduled = wp_schedule_event($timestamp, $schedule, $hook, $args);
        }

        if ($scheduled) {
            $this->add_notice(__('Event scheduled.', 'gd-cron'), 'success');
            $note = 'Schedule ' . $schedule . ' at ' . wp_date('Y-m-d H:i:s', $timestamp);
            $this->log_event('create', $hook, $note);
        } else {
            $this->add_notice(__('Unable to schedule event. It may already exist with the same arguments.', 'gd-cron'), 'error');
        }
    }

    private function handle_edit(): void
    {
        $event = $this->find_event_from_request();
        if (!$event) {
            $this->add_notice(__('Event not found.', 'gd-cron'), 'error');
            return;
        }

        $raw = wp_unslash($_POST);
        $args_raw = isset($raw['args']) ? $raw['args'] : '';
        $schedule = isset($raw['schedule']) ? sanitize_text_field($raw['schedule']) : $event['schedule'];
        if ($schedule === 'single') {
            $schedule = 'once';
        }
        $first_run_raw = isset($raw['first_run']) ? sanitize_text_field($raw['first_run']) : '';

        $args = [];
        if (!empty($args_raw)) {
            $decoded = json_decode($args_raw, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->add_notice(__('Arguments must be valid JSON.', 'gd-cron'), 'error');
                return;
            }
            $args = $decoded;
        }

        $timestamp = $this->parse_datetime($first_run_raw);
        if (!$timestamp) {
            $this->add_notice(__('Invalid date/time format.', 'gd-cron'), 'error');
            return;
        }

        $now = $this->now();
        if ($timestamp < $now) {
            $timestamp = $now + 60;
        }

        $schedules = wp_get_schedules();
        if ($schedule !== 'once' && !isset($schedules[$schedule])) {
            $this->add_notice(__('Unknown schedule.', 'gd-cron'), 'error');
            return;
        }

        // Remove old event.
        $unscheduled = wp_unschedule_event($event['timestamp'], $event['hook'], $event['args']);

        // Schedule new event.
        if ($schedule === 'once') {
            $scheduled = wp_schedule_single_event($timestamp, $event['hook'], $args);
        } else {
            $scheduled = wp_schedule_event($timestamp, $schedule, $event['hook'], $args);
        }

        if ($unscheduled && $scheduled) {
            $this->add_notice(__('Event updated.', 'gd-cron'), 'success');
            $note = 'New schedule ' . $schedule . ' at ' . wp_date('Y-m-d H:i:s', $timestamp);
            $this->log_event('edit', $event['hook'], $note);
        } else {
            $this->add_notice(__('Unable to update event.', 'gd-cron'), 'error');
        }
    }

    private function handle_save_settings(): void
    {
        $raw = wp_unslash($_POST);
        $offset = isset($raw['default_first_run_offset']) ? (int) $raw['default_first_run_offset'] : 300;
        $schedule = isset($raw['default_schedule']) ? sanitize_text_field($raw['default_schedule']) : 'once';
        $require_confirm = !empty($raw['require_delete_confirmation']) ? 1 : 0;
        $license_key = isset($raw['license_key']) ? sanitize_text_field($raw['license_key']) : '';
        $log_retention_days = isset($raw['log_retention_days']) ? max(0, (int) $raw['log_retention_days']) : 30;

        $offset = max(60, $offset);

        $schedules = wp_get_schedules();
        if ($schedule !== 'once' && !isset($schedules[$schedule])) {
            $schedule = 'once';
        }

        $settings = [
            'default_first_run_offset' => $offset,
            'default_schedule' => $schedule,
            'license_key' => $license_key,
            'require_delete_confirmation' => $require_confirm,
            'log_retention_days' => $log_retention_days,
        ];

        update_option(self::OPTION_KEY, $settings, false);
        $this->settings = $settings;
        $this->add_notice(__('Settings saved.', 'gd-cron'), 'success');
    }

    private function get_cron_events(): array
    {
        $crons = _get_cron_array();
        if (empty($crons) || !is_array($crons)) {
            return [];
        }

        $schedules = wp_get_schedules();
        $events = [];

        foreach ($crons as $timestamp => $hooks) {
            foreach ($hooks as $hook => $instances) {
                foreach ($instances as $sig => $data) {
                    $schedule_raw = $data['schedule'] ?? 'single';
                    $schedule = $schedule_raw === 'single' ? 'once' : $schedule_raw;
                    $schedule_label = $schedule === 'once'
                        ? __('Once', 'gd-cron')
                        : ($schedules[$schedule]['display'] ?? $schedule);

                    $args = $data['args'] ?? [];
                    $events[] = [
                        'timestamp' => (int) $timestamp,
                        'hook' => (string) $hook,
                        'schedule' => $schedule,
                        'schedule_label' => (string) $schedule_label,
                        'args' => is_array($args) ? $args : [],
                        'sig' => (string) $sig,
                        'raw_schedule' => $schedule_raw,
                    ];
                }
            }
        }

        usort($events, function ($a, $b) {
            return $a['timestamp'] <=> $b['timestamp'];
        });

        return $events;
    }

    private function find_event_from_request(string $method = 'post', bool $allow_fallback = false): ?array
    {
        $source = $method === 'get' ? $_GET : $_POST;

        $timestamp = isset($source['timestamp']) ? absint($source['timestamp']) : 0;
        $hook = isset($source['hook']) ? sanitize_text_field(wp_unslash($source['hook'])) : '';
        $sig = isset($source['sig']) ? sanitize_text_field(wp_unslash($source['sig'])) : '';

        if (!$timestamp || !$hook || !$sig) {
            if (!$allow_fallback) {
                return null;
            }
        }

        $events = $this->get_cron_events();
        foreach ($events as $event) {
            if ((int) $event['timestamp'] === $timestamp && $event['hook'] === $hook && $event['sig'] === $sig) {
                return $event;
            }
        }

        if ($allow_fallback && $hook && $sig) {
            foreach ($events as $event) {
                if ($event['hook'] === $hook && $event['sig'] === $sig) {
                    return $event;
                }
            }
        }

        return null;
    }

    private function parse_datetime(string $input): ?int
    {
        if (empty($input)) {
            $offset = (int) ($this->settings['default_first_run_offset'] ?? 300);
            $offset = max(60, $offset);
            return $this->now() + $offset;
        }

        $tz = wp_timezone();
        $dt = date_create($input, $tz);
        if (!$dt) {
            return null;
        }

        return $dt->getTimestamp();
    }

    private function now(): int
    {
        return current_time('timestamp');
    }

    private function add_notice(string $message, string $type = 'info'): void
    {
        $this->notices[] = [
            'message' => $message,
            'type' => $type,
        ];
    }

    private function log_event(string $action, string $hook, string $note = ''): void
    {
        global $wpdb;
        $table = $this->get_log_table_name($wpdb);
        $wpdb->insert(
            $table,
            [
                'hook' => $hook,
                'action' => $action,
                'note' => $note,
                'created_at' => gmdate('Y-m-d H:i:s', $this->now()),
            ],
            ['%s', '%s', '%s', '%s']
        );
    }

    private function get_log(
        array $filters = [],
        int $limit = 30,
        int $page = 1,
        string $sort = 'created_at',
        string $direction = 'desc'
    ): array
    {
        global $wpdb;
        $table = $this->get_log_table_name($wpdb);
        $where = [];
        $params = [];

        $limit = max(1, $limit);
        $page = max(1, $page);
        $offset = ($page - 1) * $limit;

        $allowed_sorts = [
            'created_at' => 'created_at',
            'action' => 'action',
            'hook' => 'hook',
            'note' => 'note',
        ];
        $order_by = $allowed_sorts[$sort] ?? 'created_at';
        $direction = strtolower($direction) === 'asc' ? 'ASC' : 'DESC';

        if (!empty($filters['hook'])) {
            $where[] = 'hook LIKE %s';
            $params[] = '%' . $wpdb->esc_like($filters['hook']) . '%';
        }

        if (!empty($filters['action'])) {
            $where[] = 'action = %s';
            $params[] = $filters['action'];
        }

        $sql = "SELECT id, hook, action, note, created_at FROM {$table}";
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= " ORDER BY {$order_by} {$direction} LIMIT %d OFFSET %d";
        $params[] = $limit;
        $params[] = $offset;

        $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
        return is_array($rows) ? $rows : [];
    }

    private function get_log_count(array $filters = []): int
    {
        global $wpdb;
        $table = $this->get_log_table_name($wpdb);
        $where = [];
        $params = [];

        if (!empty($filters['hook'])) {
            $where[] = 'hook LIKE %s';
            $params[] = '%' . $wpdb->esc_like($filters['hook']) . '%';
        }

        if (!empty($filters['action'])) {
            $where[] = 'action = %s';
            $params[] = $filters['action'];
        }

        $sql = "SELECT COUNT(*) FROM {$table}";
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $count = $wpdb->get_var($params ? $wpdb->prepare($sql, $params) : $sql);
        return $count ? (int) $count : 0;
    }

    public function register_cron_listeners(): void
    {
        $crons = _get_cron_array();
        if (empty($crons) || !is_array($crons)) {
            return;
        }

        foreach ($crons as $hooks) {
            foreach ($hooks as $hook => $instances) {
                if (isset(self::$registered_hooks[$hook])) {
                    continue;
                }
                add_action($hook, [$this, 'handle_cron_execution'], 10, 10);
                self::$registered_hooks[$hook] = true;
            }
        }
    }

    public function handle_cron_execution(...$args): void
    {
        $hook = current_filter();
        if (!$hook) {
            return;
        }

        $note = '';
        if (!empty($args)) {
            $encoded = wp_json_encode($args);
            if (is_string($encoded)) {
                $note = substr($encoded, 0, 500);
            }
        }

        $this->log_event('auto-run', $hook, $note);
    }

    private function render_notices(): void
    {
        foreach ($this->notices as $notice) {
            $class = $notice['type'] === 'error' ? 'notice-error' : 'notice-success';
            echo '<div class="notice ' . esc_attr($class) . ' is-dismissible">';
            echo '<p>' . esc_html($notice['message']) . '</p>';
            echo '</div>';
        }
    }

    public function add_plugin_link(array $links): array
    {
        $url = admin_url('admin.php?page=' . self::MENU_SLUG);
        $links[] = '<a href="' . esc_url($url) . '">' . esc_html__('Open Cron Manager', 'gd-cron') . '</a>';
        return $links;
    }

    private function render_log_panel(
        array $log,
        array $filters,
        string $current_page = '',
        int $current_page_num = 1,
        int $total = 0,
        int $per_page = 30,
        string $sort = 'created_at',
        string $direction = 'desc'
    ): void
    {
        echo '<h2>' . esc_html__('Event Log', 'gd-cron') . '</h2>';
        echo '<p class="description">' . esc_html__('Recent actions performed through Cron Manager.', 'gd-cron') . '</p>';

        $page_param = $current_page ?: self::MENU_SLUG;
        $action_filter = $filters['action'] ?? '';
        $hook_filter = $filters['hook'] ?? '';
        $current_page_num = max(1, $current_page_num);
        $per_page = max(1, $per_page);
        $total = max($total, count($log));
        $total_pages = (int) ceil($total / $per_page);
        $sort = in_array($sort, ['created_at', 'action', 'hook', 'note'], true) ? $sort : 'created_at';
        $direction = strtolower($direction) === 'asc' ? 'asc' : 'desc';

        echo '<form method="get" class="gd-cron-filters" style="margin-top:8px;">';
        echo '<input type="hidden" name="page" value="' . esc_attr($page_param) . '">';
        echo '<input type="hidden" name="tab" value="logs">';
        echo '<input type="hidden" name="log_sort" value="' . esc_attr($sort) . '">';
        echo '<input type="hidden" name="log_dir" value="' . esc_attr($direction) . '">';
        echo '<label>' . esc_html__('Hook contains', 'gd-cron') . ' <input type="text" name="log_hook" value="' . esc_attr($hook_filter) . '" placeholder="my_hook"></label>';
        echo '<label>' . esc_html__('Action', 'gd-cron') . ' <select name="log_action">';
        echo '<option value="">' . esc_html__('Any', 'gd-cron') . '</option>';
        $actions = ['run', 'delete', 'create', 'edit', 'auto-run'];
        foreach ($actions as $action) {
            echo '<option value="' . esc_attr($action) . '"' . selected($action_filter, $action, false) . '>' . esc_html(ucfirst($action)) . '</option>';
        }
        echo '</select></label>';
        echo '<button class="button">' . esc_html__('Filter', 'gd-cron') . '</button> ';
        $reset_url = admin_url('admin.php?page=' . $page_param . '&tab=logs');
        echo '<a class="button" href="' . esc_url($reset_url) . '">' . esc_html__('Reset', 'gd-cron') . '</a>';
        echo '</form>';

        if (empty($log)) {
            echo '<p>' . esc_html__('No log entries yet.', 'gd-cron') . '</p>';
            return;
        }

        $base_url = admin_url('admin.php');
        $base_args = [
            'page' => $page_param,
            'tab' => 'logs',
        ];
        if ($hook_filter !== '') {
            $base_args['log_hook'] = $hook_filter;
        }
        if ($action_filter !== '') {
            $base_args['log_action'] = $action_filter;
        }

        $make_sort_link = function (string $field) use ($base_url, $base_args, $sort, $direction): string {
            $dir_for_link = ($sort === $field && $direction === 'asc') ? 'desc' : 'asc';
            $args = array_merge($base_args, [
                'log_sort' => $field,
                'log_dir' => $dir_for_link,
                'log_page' => 1,
            ]);
            return esc_url(add_query_arg($args, $base_url));
        };

        $sort_arrow = function (string $field) use ($sort, $direction): string {
            if ($sort === $field) {
                return $direction === 'asc' ? ' &uarr;' : ' &darr;';
            }
            return ' &uarr;&darr;';
        };

        echo '<table class="widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th><a href="' . $make_sort_link('created_at') . '">' . esc_html__('Time', 'gd-cron') . $sort_arrow('created_at') . '</a></th>';
        echo '<th><a href="' . $make_sort_link('action') . '">' . esc_html__('Action', 'gd-cron') . $sort_arrow('action') . '</a></th>';
        echo '<th><a href="' . $make_sort_link('hook') . '">' . esc_html__('Hook', 'gd-cron') . $sort_arrow('hook') . '</a></th>';
        echo '<th><a href="' . $make_sort_link('note') . '">' . esc_html__('Details', 'gd-cron') . $sort_arrow('note') . '</a></th>';
        echo '<th>' . esc_html__('JSON', 'gd-cron') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($log as $entry) {
            $time_display = !empty($entry['created_at']) ? esc_html(wp_date('Y-m-d H:i:s', strtotime($entry['created_at']))) : '';
            $action = isset($entry['action']) ? (string) $entry['action'] : '';
            $hook = isset($entry['hook']) ? (string) $entry['hook'] : '';
            $note = isset($entry['note']) ? (string) $entry['note'] : '';

            // Encode only the log entry fields (no extra UI data)
            $payload = array_intersect_key(
                $entry,
                array_flip(['hook', 'action', 'note', 'created_at'])
            );
            $json = wp_json_encode($payload);
            $data_url = $json ? 'data:application/json;charset=utf-8,' . rawurlencode($json) : '';

            echo '<tr>';
            echo '<td>' . $time_display . '</td>';
            echo '<td>' . esc_html(ucfirst($action)) . '</td>';
            echo '<td><code>' . esc_html($hook) . '</code></td>';
            echo '<td>' . esc_html($note) . '</td>';
            echo '<td>';
            if ($data_url) {
                $download_id = isset($entry['id']) ? (int) $entry['id'] : 0;
                echo '<a class="button button-small" href="' . esc_url($data_url) . '" download="gd-cron-log-' . esc_attr($download_id) . '.json">' . esc_html__('JSON', 'gd-cron') . '</a>';
            }
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';

        if ($total_pages > 1) {
            $base_args['log_sort'] = $sort;
            $base_args['log_dir'] = $direction;

            $prev_page = max(1, $current_page_num - 1);
            $next_page = min($total_pages, $current_page_num + 1);
            $prev_url = esc_url(add_query_arg(array_merge($base_args, ['log_page' => $prev_page]), $base_url));
            $next_url = esc_url(add_query_arg(array_merge($base_args, ['log_page' => $next_page]), $base_url));

            echo '<div class="tablenav" style="margin-top:12px;">';
            echo '<div class="tablenav-pages">';
            echo '<span class="displaying-num">' . sprintf(esc_html__('%d items', 'gd-cron'), (int) $total) . '</span>';
            echo '<span class="pagination-links">';
            $prev_disabled = $current_page_num <= 1 ? ' disabled' : '';
            $next_disabled = $current_page_num >= $total_pages ? ' disabled' : '';
            echo '<a class="prev-page button' . esc_attr($prev_disabled) . '" href="' . ($prev_disabled ? '#' : $prev_url) . '"><span class="screen-reader-text">' . esc_html__('Previous page', 'gd-cron') . '</span><span aria-hidden="true">&lsaquo;</span></a>';
            echo '<span class="paging-input">' . sprintf(esc_html__('Page %1$d of %2$d', 'gd-cron'), (int) $current_page_num, (int) $total_pages) . '</span>';
            echo '<a class="next-page button' . esc_attr($next_disabled) . '" href="' . ($next_disabled ? '#' : $next_url) . '"><span class="screen-reader-text">' . esc_html__('Next page', 'gd-cron') . '</span><span aria-hidden="true">&rsaquo;</span></a>';
            echo '</span>';
            echo '</div>';
            echo '</div>';
        }
    }

    public function render_edit_page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to access this page.', 'gd-cron'));
        }

        $this->settings = $this->get_settings();
        self::maybe_create_log_table();
        $this->prune_logs();
        $this->handle_actions();

        $event = $this->find_event_from_request('get', true);
        $schedules = wp_get_schedules();

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Edit Cron Event', 'gd-cron') . '</h1>';
        $this->render_notices();

        if (!$event) {
            echo '<p>' . esc_html__('Event not found. It may have just run or been removed.', 'gd-cron') . '</p>';
            echo '<p><a class="button" href="' . esc_url(admin_url('admin.php?page=' . self::MENU_SLUG)) . '">' . esc_html__('Back to Cron Manager', 'gd-cron') . '</a></p>';
            echo '</div>';
            return;
        }

        $current_dt = wp_date('Y-m-d H:i', $event['timestamp']);
        $current_schedule = $event['schedule'];

        echo '<form method="post" class="gd-cron-form">';
        wp_nonce_field(self::NONCE_ACTION);
        echo '<input type="hidden" name="gd_cron_action" value="edit">';
        echo '<input type="hidden" name="timestamp" value="' . esc_attr($event['timestamp']) . '">';
        echo '<input type="hidden" name="hook" value="' . esc_attr($event['hook']) . '">';
        echo '<input type="hidden" name="sig" value="' . esc_attr($event['sig']) . '">';

        echo '<table class="form-table" role="presentation">';
        echo '<tr><th scope="row">' . esc_html__('Hook', 'gd-cron') . '</th><td><code>' . esc_html($event['hook']) . '</code></td></tr>';

        echo '<tr><th scope="row">' . esc_html__('First run (local time)', 'gd-cron') . '</th><td>';
        echo '<input type="text" name="first_run" value="' . esc_attr($current_dt) . '" class="regular-text" placeholder="2025-12-11 14:30">';
        echo '</td></tr>';

        echo '<tr><th scope="row">' . esc_html__('Recurrence', 'gd-cron') . '</th><td>';
        echo '<select name="schedule">';
        echo '<option value="once"' . selected($current_schedule, 'once', false) . '>' . esc_html__('Once', 'gd-cron') . '</option>';
        foreach ($schedules as $key => $schedule_data) {
            $label = $schedule_data['display'] ?? $key;
            echo '<option value="' . esc_attr($key) . '"' . selected($current_schedule, $key, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
        echo '</td></tr>';

        echo '<tr><th scope="row">' . esc_html__('Arguments (JSON)', 'gd-cron') . '</th><td>';
        $args_value = !empty($event['args']) ? wp_json_encode($event['args']) : '';
        echo '<textarea name="args" rows="3" class="large-text code" placeholder="[\"foo\", 123]">' . esc_textarea($args_value) . '</textarea>';
        echo '</td></tr>';

        echo '</table>';

        echo '<p class="submit">';
        echo '<button type="submit" class="button button-primary">' . esc_html__('Save changes', 'gd-cron') . '</button> ';
        echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=' . self::MENU_SLUG)) . '">' . esc_html__('Cancel', 'gd-cron') . '</a>';
        echo '</p>';
        echo '</form>';
        echo '</div>';
    }

    public function hide_edit_submenu(): void
    {
        remove_submenu_page(self::MENU_SLUG, self::EDIT_SLUG);
    }

    private function get_settings(): array
    {
        $defaults = [
            'default_first_run_offset' => 300,
            'default_schedule' => 'once',
            'license_key' => '',
            'require_delete_confirmation' => 1,
            'log_retention_days' => 30,
        ];

        $saved = get_option(self::OPTION_KEY);
        if (!is_array($saved)) {
            return $defaults;
        }

        return array_merge($defaults, $saved);
    }

    public static function get_log_table_name($wpdb): string
    {
        return $wpdb->prefix . self::LOG_TABLE;
    }

    public static function maybe_create_log_table(): void
    {
        global $wpdb;
        $installed = get_option(self::LOG_DB_VERSION_KEY);
        if ($installed === self::LOG_DB_VERSION) {
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::get_log_table_name($wpdb);
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            hook varchar(191) NOT NULL,
            action varchar(50) NOT NULL,
            note text NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY hook (hook),
            KEY action (action),
            KEY created_at (created_at)
        ) {$charset_collate};";

        dbDelta($sql);
        update_option(self::LOG_DB_VERSION_KEY, self::LOG_DB_VERSION, false);
    }

    private function prune_logs(): void
    {
        $days = isset($this->settings['log_retention_days']) ? (int) $this->settings['log_retention_days'] : 30;
        if ($days <= 0) {
            return;
        }

        global $wpdb;
        $table = self::get_log_table_name($wpdb);
        $cutoff = gmdate('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS));
        $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE created_at < %s", $cutoff));
    }
}

new GDCronManager();