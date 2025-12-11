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
    private const NONCE_ACTION = 'gd-cron-action';
    private const OPTION_KEY = 'gd_cron_settings';
    private const LOG_OPTION_KEY = 'gd_cron_log';
    private array $notices = [];
    private array $settings = [];

    public function __construct()
    {
        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$this, 'add_plugin_link']);
        add_action('admin_head', [$this, 'hide_edit_submenu']);
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
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to access this page.', 'gd-cron'));
        }

        $this->settings = $this->get_settings();
        $this->handle_actions();

        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'events';
        $tab = in_array($tab, ['events', 'settings', 'logs'], true) ? $tab : 'events';

        $events = $this->get_cron_events();
        $schedules = wp_get_schedules();
        $now = $this->now();

        echo '<div class="wrap gd-audit gd-audit-settings">';
        echo '<div class="gd-audit__tabs">';
        echo '<h1 class="wp-heading-inline">' . esc_html__('GD Cron', 'gd-cron') . '</h1>';
        $base_url = admin_url('admin.php?page=' . self::MENU_SLUG);
        echo '<h2 class="nav-tab-wrapper">';
        echo '<a href="' . esc_url(add_query_arg('tab', 'events', $base_url)) . '" class="nav-tab ' . ($tab === 'events' ? 'nav-tab-active' : '') . '">' . esc_html__('Events', 'gd-cron') . '</a>';
        echo '<a href="' . esc_url(add_query_arg('tab', 'settings', $base_url)) . '" class="nav-tab ' . ($tab === 'settings' ? 'nav-tab-active' : '') . '">' . esc_html__('Settings', 'gd-cron') . '</a>';
        echo '<a href="' . esc_url(add_query_arg('tab', 'logs', $base_url)) . '" class="nav-tab ' . ($tab === 'logs' ? 'nav-tab-active' : '') . '">' . esc_html__('Logs', 'gd-cron') . '</a>';
        echo '</h2>';
        echo '</div>';
        $this->render_notices();
        if ($tab === 'settings') {
            echo '<h1 class="gd-audit__section-title">' . esc_html__('Settings', 'gd-cron') . '</h1>';
        }

        if ($tab === 'events') {
            $this->render_events_table($events, $now);
        } elseif ($tab === 'settings') {
            $this->render_settings_form($schedules);
        } else {
            $this->render_log_panel($this->get_log());
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

        echo '<h2>' . esc_html__('License', 'gd-cron') . '</h2>';
        echo '<p>' . esc_html__('Add your license key to unlock premium updates and support.', 'gd-cron') . '</p>';
        echo '<table class="form-table" role="presentation">';
        echo '<tbody>';
        echo '<tr><th scope="row">';
        echo '<label for="gd-cron-license-key">' . esc_html__('License key', 'gd-cron') . '</label>';
        echo '</th><td>';
        echo '<input type="text" id="gd-cron-license-key" name="license_key" value="' . esc_attr($license_key) . '" class="regular-text" autocomplete="off" placeholder="XXXX-XXXX-XXXX-XXXX">';
        echo '<p class="description">' . esc_html__('Paste the key from your purchase receipt. Leave blank to deactivate the license on this site.', 'gd-cron') . '</p>';
        echo '<button type="button" class="button button-secondary" style="margin-top:10px;">' . esc_html__('Validate License', 'gd-cron') . '</button>';
        echo '<a class="button" style="margin-top:10px; margin-left:6px;" href="https://license.glitchdata.com/shop/GD-01" target="_blank" rel="noopener noreferrer">' . esc_html__('Purchase License', 'gd-cron') . '</a>';
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
        $log = get_option(self::LOG_OPTION_KEY, []);
        if (!is_array($log)) {
            $log = [];
        }

        $entry = [
            'time' => $this->now(),
            'action' => $action,
            'hook' => $hook,
            'note' => $note,
        ];

        array_unshift($log, $entry);
        $log = array_slice($log, 0, 50);

        update_option(self::LOG_OPTION_KEY, $log, false);
    }

    private function get_log(): array
    {
        $log = get_option(self::LOG_OPTION_KEY, []);
        if (!is_array($log)) {
            return [];
        }
        return $log;
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

    private function render_log_panel(array $log): void
    {
        echo '<h2>' . esc_html__('Event Log', 'gd-cron') . '</h2>';
        echo '<p class="description">' . esc_html__('Recent actions performed through Cron Manager.', 'gd-cron') . '</p>';

        if (empty($log)) {
            echo '<p>' . esc_html__('No log entries yet.', 'gd-cron') . '</p>';
            return;
        }

        echo '<table class="widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Time', 'gd-cron') . '</th>';
        echo '<th>' . esc_html__('Action', 'gd-cron') . '</th>';
        echo '<th>' . esc_html__('Hook', 'gd-cron') . '</th>';
        echo '<th>' . esc_html__('Details', 'gd-cron') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($log as $entry) {
            $time = isset($entry['time']) ? (int) $entry['time'] : 0;
            $action = isset($entry['action']) ? (string) $entry['action'] : '';
            $hook = isset($entry['hook']) ? (string) $entry['hook'] : '';
            $note = isset($entry['note']) ? (string) $entry['note'] : '';

            $time_display = $time ? wp_date('Y-m-d H:i:s', $time) : '';

            echo '<tr>';
            echo '<td>' . esc_html($time_display) . '</td>';
            echo '<td>' . esc_html(ucfirst($action)) . '</td>';
            echo '<td><code>' . esc_html($hook) . '</code></td>';
            echo '<td>' . esc_html($note) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    public function render_edit_page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to access this page.', 'gd-cron'));
        }

        $this->settings = $this->get_settings();
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
        ];

        $saved = get_option(self::OPTION_KEY);
        if (!is_array($saved)) {
            return $defaults;
        }

        return array_merge($defaults, $saved);
    }
}

new GDCronManager();