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
    private array $notices = [];

    public function __construct()
    {
        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
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

        $this->handle_actions();

        $events = $this->get_cron_events();
        $schedules = wp_get_schedules();
        $now = $this->now();

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Cron Manager', 'gd-cron') . '</h1>';
        $this->render_notices();
        echo '<div class="gd-cron-grid">';
        echo '<div class="gd-cron-panel">';
        $this->render_events_table($events, $now);
        echo '</div>';
        echo '<div class="gd-cron-panel">';
        $this->render_create_form($schedules, $now);
        echo '</div>';
        echo '</div>';
        echo '</div>';
    }

    private function render_events_table(array $events, int $now): void
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
        $default_time = date('Y-m-d H:i', $now + 300);
        echo '<input type="text" name="first_run" value="' . esc_attr($default_time) . '" placeholder="2025-12-11 14:30">';
        echo '<p class="description">' . esc_html__('Format: YYYY-MM-DD HH:MM. Uses site timezone.', 'gd-cron') . '</p>';
        echo '</label>';

        echo '<label class="gd-cron-field">';
        echo '<span>' . esc_html__('Recurrence', 'gd-cron') . '</span>';
        echo '<select name="schedule">';
        echo '<option value="once">' . esc_html__('Once', 'gd-cron') . '</option>';
        foreach ($schedules as $key => $schedule) {
            $label = $schedule['display'] ?? $key;
            echo '<option value="' . esc_attr($key) . '">' . esc_html($label) . '</option>';
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
        echo '<button class="button button-secondary gd-cron-delete" data-confirm="' . esc_attr__('Delete this event?', 'gd-cron') . '">' . esc_html__('Delete', 'gd-cron') . '</button>';
        echo '</form>';
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
                    $schedule = $data['schedule'] ?? 'single';
                    $schedule_label = $schedule === 'single'
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
            return $this->now() + 300;
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
}

new GDCronManager();