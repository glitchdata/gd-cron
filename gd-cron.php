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
    private const NONCE_ACTION = 'gd-cron-action';
    private const OPTION_KEY = 'gd_cron_settings';
    private array $notices = [];
    private array $settings = [];

    public function __construct()
    {
        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$this, 'add_plugin_link']);
    }

    public function register_menu(): void
    {
        add_management_page(
            __('Cron Manager', 'gd-cron'),
            __('Cron Manager', 'gd-cron'),
            'manage_options',
            self::MENU_SLUG,
            [$this, 'render_page']
        );
    }

    public function enqueue_assets(string $hook): void
    {
        if ($hook !== 'tools_page_' . self::MENU_SLUG) {
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

        $events = $this->get_cron_events();
        $schedules = wp_get_schedules();
        $now = $this->now();

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Cron Manager', 'gd-cron') . '</h1>';
        $this->render_notices();
        echo '<div class="gd-cron-grid">';
        echo '<div class="gd-cron-panel">';
        $this->render_events_table($events, $now, $schedules);
        echo '</div>';
        echo '<div class="gd-cron-panel">';
        $this->render_create_form($schedules, $now);
        echo '<hr class="gd-cron-divider">';
        $this->render_settings_form($schedules);
        echo '</div>';
        echo '</div>';
        echo '</div>';
    }

    private function render_events_table(array $events, int $now, array $schedules): void
    {
        echo '<h2>' . esc_html__('Scheduled Events', 'gd-cron') . '</h2>';
        echo '<p class="description">' . esc_html__('Run or delete cron events from here. Use cautiously on production sites.', 'gd-cron') . '</p>';

        if (empty($events)) {
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

        foreach ($events as $event) {
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
            $this->render_edit_row($event, $schedules, $now);
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

        echo '<button type="button" class="button gd-cron-edit-toggle" data-target="gd-cron-edit-' . esc_attr($event['sig']) . '">' . esc_html__('Edit', 'gd-cron') . '</button>';
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
        $event = $this->find_event_from_request();
        if (!$event) {
            $this->add_notice(__('Event not found.', 'gd-cron'), 'error');
            return;
        }

        // Run the hook immediately.
        do_action_ref_array($event['hook'], $event['args']);
        $this->add_notice(sprintf(__('Ran hook %s.', 'gd-cron'), esc_html($event['hook'])), 'success');
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

        $offset = max(60, $offset);

        $schedules = wp_get_schedules();
        if ($schedule !== 'once' && !isset($schedules[$schedule])) {
            $schedule = 'once';
        }

        $settings = [
            'default_first_run_offset' => $offset,
            'default_schedule' => $schedule,
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
                    ];
                }
            }
        }

        usort($events, function ($a, $b) {
            return $a['timestamp'] <=> $b['timestamp'];
        });

        return $events;
    }

    private function find_event_from_request(): ?array
    {
        $timestamp = isset($_POST['timestamp']) ? absint($_POST['timestamp']) : 0;
        $hook = isset($_POST['hook']) ? sanitize_text_field(wp_unslash($_POST['hook'])) : '';
        $sig = isset($_POST['sig']) ? sanitize_text_field(wp_unslash($_POST['sig'])) : '';

        if (!$timestamp || !$hook || !$sig) {
            return null;
        }

        $events = $this->get_cron_events();
        foreach ($events as $event) {
            if ((int) $event['timestamp'] === $timestamp && $event['hook'] === $hook && $event['sig'] === $sig) {
                return $event;
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
        $url = admin_url('tools.php?page=' . self::MENU_SLUG);
        $links[] = '<a href="' . esc_url($url) . '">' . esc_html__('Open Cron Manager', 'gd-cron') . '</a>';
        return $links;
    }

    private function render_edit_row(array $event, array $schedules, int $now): void
    {
        $row_id = 'gd-cron-edit-' . $event['sig'];
        $current_dt = wp_date('Y-m-d H:i', $event['timestamp']);

        echo '<tr id="' . esc_attr($row_id) . '" class="gd-cron-edit-row" style="display:none;">';
        echo '<td colspan="5">';
        echo '<form method="post" class="gd-cron-form gd-cron-inline-form">';
        wp_nonce_field(self::NONCE_ACTION);
        echo '<input type="hidden" name="gd_cron_action" value="edit">';
        echo '<input type="hidden" name="timestamp" value="' . esc_attr($event['timestamp']) . '">';
        echo '<input type="hidden" name="hook" value="' . esc_attr($event['hook']) . '">';
        echo '<input type="hidden" name="sig" value="' . esc_attr($event['sig']) . '">';

        echo '<div class="gd-cron-edit-grid">';
        echo '<label class="gd-cron-field">';
        echo '<span>' . esc_html__('First run (local time)', 'gd-cron') . '</span>';
        echo '<input type="text" name="first_run" value="' . esc_attr($current_dt) . '" placeholder="2025-12-11 14:30">';
        echo '</label>';

        echo '<label class="gd-cron-field">';
        echo '<span>' . esc_html__('Recurrence', 'gd-cron') . '</span>';
        $current_schedule = $event['schedule'] === 'single' ? 'once' : $event['schedule'];
        echo '<select name="schedule">';
        echo '<option value="once"' . selected($current_schedule, 'once', false) . '>' . esc_html__('Once', 'gd-cron') . '</option>';
        foreach ($schedules as $key => $schedule_data) {
            $label = $schedule_data['display'] ?? $key;
            echo '<option value="' . esc_attr($key) . '"' . selected($current_schedule, $key, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
        echo '</label>';

        echo '<label class="gd-cron-field">';
        echo '<span>' . esc_html__('Arguments (JSON)', 'gd-cron') . '</span>';
        $args_value = !empty($event['args']) ? wp_json_encode($event['args']) : '';
        echo '<textarea name="args" rows="2" placeholder="[\"foo\", 123]">' . esc_textarea($args_value) . '</textarea>';
        echo '</label>';
        echo '</div>';

        echo '<button type="submit" class="button button-primary">' . esc_html__('Save changes', 'gd-cron') . '</button> ';
        echo '<button type="button" class="button gd-cron-edit-cancel" data-target="' . esc_attr($row_id) . '">' . esc_html__('Cancel', 'gd-cron') . '</button>';
        echo '</form>';
        echo '</td>';
        echo '</tr>';
    }

    private function render_settings_form(array $schedules): void
    {
        $settings = $this->settings;
        $offset = (int) ($settings['default_first_run_offset'] ?? 300);
        $schedule = $settings['default_schedule'] ?? 'once';
        $require_confirm = !empty($settings['require_delete_confirmation']);

        echo '<h2>' . esc_html__('Settings', 'gd-cron') . '</h2>';
        echo '<form method="post" class="gd-cron-form">';
        wp_nonce_field(self::NONCE_ACTION);
        echo '<input type="hidden" name="gd_cron_action" value="save_settings">';

        echo '<label class="gd-cron-field">';
        echo '<span>' . esc_html__('Default first run offset (seconds)', 'gd-cron') . '</span>';
        echo '<input type="number" min="60" step="60" name="default_first_run_offset" value="' . esc_attr($offset) . '">';
        echo '<p class="description">' . esc_html__('Used when the first run field is left empty.', 'gd-cron') . '</p>';
        echo '</label>';

        echo '<label class="gd-cron-field">';
        echo '<span>' . esc_html__('Default recurrence', 'gd-cron') . '</span>';
        echo '<select name="default_schedule">';
        echo '<option value="once"' . selected($schedule, 'once', false) . '>' . esc_html__('Once', 'gd-cron') . '</option>';
        foreach ($schedules as $key => $schedule_data) {
            $label = $schedule_data['display'] ?? $key;
            echo '<option value="' . esc_attr($key) . '"' . selected($schedule, $key, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
        echo '</label>';

        echo '<label class="gd-cron-field">';
        echo '<input type="checkbox" name="require_delete_confirmation" value="1"' . checked($require_confirm, true, false) . '> ' . esc_html__('Ask for confirmation before deleting an event', 'gd-cron');
        echo '</label>';

        echo '<button type="submit" class="button button-primary">' . esc_html__('Save settings', 'gd-cron') . '</button>';
        echo '</form>';
    }

    private function get_settings(): array
    {
        $defaults = [
            'default_first_run_offset' => 300,
            'default_schedule' => 'once',
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