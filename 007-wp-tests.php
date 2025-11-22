<?php
/**
 * Plugin Name: WP Tests
 * Description: A plugin to facilitate testing functions via the wp/test action.
 * Version: 1.0.0
 * Author: Dan
 * License: GPLv2
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if (!defined('ABSPATH')) {
    exit;
}

// Lightweight buffer to capture early `do_action('wp/test', $payload)` calls
// so plugin developers can call the action without needing to add_action first.
global $wp_tests_early_buffer;
$wp_tests_early_buffer = [];

// Runtime logs stored in a global (in-memory, per-request).
global $wp_tests_runtime_logs;
$wp_tests_runtime_logs = [];

function wp_tests_buffer_early_call($payload)
{
    global $wp_tests_early_buffer;
    $wp_tests_early_buffer[] = $payload;
}

// Capture any early calls at the lowest priority.
add_action('wp/test', 'wp_tests_buffer_early_call', 0, 1);

class WP_Tests_Plugin
{
    private const OPTION_USER_STATES = 'wp_tests_user_states';
    private const OPTION_USER_LOGS = 'wp_tests_logs';
    private const OPTION_ALLOW_DB_ACTIONS = 'wp_tests_allow_db_actions';
    private const MAX_LOG_ENTRIES = 50;
    private $blocking_writes = false;
    private $allow_db_actions_override = false;

    public function register(): void
    {
        // Attach the real handler during `init` and flush any early buffered calls.
        add_action('init', [$this, 'bootstrap_runner_user'], 1);
        add_action('init', [$this, 'attach_test_handler_and_flush'], 5);
        add_action('admin_menu', [$this, 'register_admin_menu']);
        add_action('admin_init', [$this, 'handle_tools_save']);

        if ($this->is_cli_context()) {
            WP_CLI::add_command('wp-test', [$this, 'handle_cli_command']);
        }
    }

    public function attach_test_handler_and_flush(): void
    {
        // Register the actual handler at a normal priority.
        add_action('wp/test', [$this, 'handle_test_action'], 10, 1);

        // Flush any early buffered payloads captured before the handler was attached.
        global $wp_tests_early_buffer;
        if (!empty($wp_tests_early_buffer) && is_array($wp_tests_early_buffer)) {
            foreach ($wp_tests_early_buffer as $payload) {
                // Call the handler directly to avoid re-triggering the buffer.
                try {
                    $this->handle_test_action($payload);
                } catch (Throwable $e) {
                    // Swallow errors during early flush to avoid breaking bootstrap.
                }
            }
            // Clear the buffer so we don't process again.
            $wp_tests_early_buffer = [];
        }
    }

    public function maybe_register_test_action(): void
    {
        if (!is_user_logged_in()) {
            return;
        }

        $user_login = $this->get_current_user_login();
        if (!$user_login) {
            return;
        }

        $states = get_option(self::OPTION_USER_STATES, []);
        $enabled = isset($states[$user_login]) ? (bool) $states[$user_login] : false;

        if ($enabled) {
            add_action('wp/test', [$this, 'handle_test_action'], 10, 1);
        }
    }

    public function register_admin_menu(): void
    {
        add_management_page(
            __('WP Tests', 'wp-tests'),
            __('WP Tests', 'wp-tests'),
            'read',
            'wp-tests',
            [$this, 'render_tools_page']
        );
        $this->enqueue_admin_assets();
    }

    public function enqueue_admin_assets(): void
    {
        $url = plugin_dir_url(__FILE__) . '007-wp-tests.css';
        wp_enqueue_style('wp-tests-admin', $url, [], '1.0.0');
    }

    public function handle_tools_save(): void
    {
        $nonce = isset($_POST['wp_tests_tools_nonce']) ? sanitize_text_field(wp_unslash($_POST['wp_tests_tools_nonce'])) : '';
        if ($nonce === '' || !wp_verify_nonce($nonce, 'wp_tests_save_tools')) {
            return;
        }

        if (!is_user_logged_in()) {
            return;
        }

        $user_login = $this->get_current_user_login();
        if (!$user_login) {
            return;
        }

        if (!current_user_can('read')) {
            return;
        }

        $states = get_option(self::OPTION_USER_STATES, []);
        $states = is_array($states) ? $states : [];

        $enabled = isset($_POST['wp_tests_enabled']) && sanitize_text_field(wp_unslash($_POST['wp_tests_enabled'])) === '1';
        $states[$user_login] = $enabled;

        update_option(self::OPTION_USER_STATES, $states, false);

        $allow_db = isset($_POST['wp_tests_allow_db_actions']) && sanitize_text_field(wp_unslash($_POST['wp_tests_allow_db_actions'])) === '1';
        update_option(self::OPTION_ALLOW_DB_ACTIONS, $allow_db ? 1 : 0, false);

        // Redirect back to the tools page to avoid resubmission prompts and ensure state refresh.
        $redirect = add_query_arg(['page' => 'wp-tests'], admin_url('tools.php'));
        wp_safe_redirect($redirect);
        exit;
    }

    public function render_tools_page(): void
    {
        if (!current_user_can('read')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'wp-tests'));
        }

        $user_login = $this->get_current_user_login();
        $states = get_option(self::OPTION_USER_STATES, []);
        $states = is_array($states) ? $states : [];
        $enabled = isset($states[$user_login]) ? (bool) $states[$user_login] : false;
        $allow_db_actions = (bool) get_option(self::OPTION_ALLOW_DB_ACTIONS, false);
        ?>
        <div class="wrap wp-tests-wrap">
            <h1 class="wp-tests-title">
                <?php esc_html_e('WP Tests', 'wp-tests'); ?>
                <?php
                printf(
                    '<a class="wp-tests-github-link" href="%s" target="_blank" rel="noopener noreferrer"><span class="wp-tests-github-text">%s</span><span class="wp-tests-github-icon">&#x1F517;</span></a>',
                    esc_url('https://github.com/lazardanlucian/007-wp-tests'),
                    esc_html__('Help available in github', 'wp-tests')
                );
                ?>
            </h1>
            <div class="wp-tests-card">
                <form id="wp-tests-form" method="post" action="">
                    <?php wp_nonce_field('wp_tests_save_tools', 'wp_tests_tools_nonce'); ?>
                    <div class="wp-tests-controls">
                        <div class="wp-tests-control">
                            <div class="wp-tests-control-header">
                                <span class="wp-tests-control-label"><?php esc_html_e('Enable tests', 'wp-tests'); ?></span>
                                <label class="wp-tests-switch">
                                    <input type="hidden" name="wp_tests_enabled" value="0" />
                                    <input type="checkbox" name="wp_tests_enabled" value="1" <?php checked($enabled); ?> />
                                    <span class="wp-tests-slider"></span>
                                </label>
                            </div>
                            <p class="description"><?php esc_html_e('Toggle to allow the wp/test action to run for your user account.', 'wp-tests'); ?></p>
                        </div>
                        <div class="wp-tests-control">
                            <div class="wp-tests-control-header">
                                <span class="wp-tests-control-label"><?php esc_html_e('Allow database writes', 'wp-tests'); ?></span>
                                <label class="wp-tests-switch">
                                    <input type="hidden" name="wp_tests_allow_db_actions" value="0" />
                                    <input type="checkbox" name="wp_tests_allow_db_actions" value="1" <?php checked($allow_db_actions); ?> />
                                    <span class="wp-tests-slider"></span>
                                </label>
                            </div>
                            <p class="description"><?php esc_html_e('When enabled, write operations (options, posts, etc.) are permitted during test runs.', 'wp-tests'); ?></p>
                        </div>
                    </div>
                    <!-- Save handled automatically when toggling the checkbox -->
                </form>
            </div>
                <script>
                    (function () {
                        var form = document.getElementById('wp-tests-form');
                        if (!form) {
                            return;
                        }

                        var checkbox = form.querySelector('input[name="wp_tests_enabled"][type="checkbox"]');
                        var allowDb = form.querySelector('input[name="wp_tests_allow_db_actions"][type="checkbox"]');

                        function bindCheckbox(el) {
                            if (!el) {
                                return;
                            }

                            el.addEventListener('change', function () {
                                form.submit();
                            });
                        }

                        bindCheckbox(checkbox);
                        bindCheckbox(allowDb);
                    })();
                </script>
            <?php
            if ($enabled) {
                $all_logs = $this->get_user_logs($user_login);
                $user_id = get_current_user_id();
                $saved_filters = $this->get_saved_filters($user_id);
                $filters = $this->get_log_filters($saved_filters);
                $logs = $this->apply_log_filters($all_logs, $filters);
                $plugin_options = $this->get_plugin_filter_options($all_logs);
                $this->save_log_filters($user_id, $filters);
                ?>
                <?php if (!empty($all_logs)) : ?>
                    <form id="wp-tests-filters" class="wp-tests-filter-bar" method="get">
                        <input type="hidden" name="page" value="wp-tests" />
                        <?php wp_nonce_field('wp_tests_filter_logs', 'wp_tests_filters_nonce'); ?>
                        <label>
                            <span><?php esc_html_e('Plugin', 'wp-tests'); ?></span>
                            <select name="wp_tests_plugin_filter">
                                <option value=""><?php esc_html_e('All plugins', 'wp-tests'); ?></option>
                                <?php foreach ($plugin_options as $plugin_dir) : ?>
                                    <option value="<?php echo esc_attr($plugin_dir); ?>" <?php selected($filters['plugin'], $plugin_dir); ?>>
                                        <?php echo esc_html($plugin_dir); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>
                            <span><?php esc_html_e('Result', 'wp-tests'); ?></span>
                            <select name="wp_tests_result_filter">
                                <option value="all" <?php selected($filters['result'], 'all'); ?>><?php esc_html_e('All', 'wp-tests'); ?></option>
                                <option value="passed" <?php selected($filters['result'], 'passed'); ?>><?php esc_html_e('Passed', 'wp-tests'); ?></option>
                                <option value="failed" <?php selected($filters['result'], 'failed'); ?>><?php esc_html_e('Failed', 'wp-tests'); ?></option>
                            </select>
                        </label>
                        <label class="wp-tests-filter-search">
                            <span><?php esc_html_e('Search', 'wp-tests'); ?></span>
                            <input type="search" name="wp_tests_search" value="<?php echo esc_attr($filters['search']); ?>" placeholder="<?php esc_attr_e('Function, args, expected, actual', 'wp-tests'); ?>" />
                        </label>
                    </form>
                    <script>
                        (function () {
                            var form = document.getElementById('wp-tests-filters');
                            if (!form) {
                                return;
                            }

                            var inputs = form.querySelectorAll('select, input[type="search"]');
                            var debounceTimer;

                            function submitForm() {
                                form.submit();
                            }

                            inputs.forEach(function (el) {
                                if (el.type === 'search') {
                                    el.addEventListener('input', function () {
                                        clearTimeout(debounceTimer);
                                        debounceTimer = setTimeout(submitForm, 300);
                                    });
                                    el.addEventListener('keydown', function (e) {
                                        if (e.key === 'Enter') {
                                            e.preventDefault();
                                            submitForm();
                                        }
                                    });
                                } else {
                                    el.addEventListener('change', submitForm);
                                }
                            });
                        })();
                    </script>
                <?php endif; ?>
                    <div class="wp-tests-table-wrap">
                        <table class="widefat fixed striped wp-tests-table">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e('Function', 'wp-tests'); ?></th>
                                    <th><?php esc_html_e('Arguments', 'wp-tests'); ?></th>
                                    <th><?php esc_html_e('Expected', 'wp-tests'); ?></th>
                                    <th><?php esc_html_e('Actual', 'wp-tests'); ?></th>
                                    <th><?php esc_html_e('Result', 'wp-tests'); ?></th>
                                    <th><?php esc_html_e('Plugin Directory', 'wp-tests'); ?></th>
                                    <th><?php esc_html_e('Theme Directory', 'wp-tests'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($logs as $entry) : ?>
                                    <tr>
                                    <td><?php echo esc_html($entry['function']); ?></td>
                                    <td><code><?php echo esc_html($entry['args']); ?></code></td>
                                    <td><code><?php echo esc_html($entry['expected']); ?></code></td>
                                        <td><code><?php echo esc_html($entry['actual']); ?></code></td>
                                        <td>
                                            <?php if (!empty($entry['passed'])) : ?>
                                                <span style="color: #0a0; font-weight: 600;">✔</span>
                                            <?php else : ?>
                                                <span style="color: #a00; font-weight: 600;">✘</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo esc_html($entry['plugin'] ?? ''); ?></td>
                                        <td><?php echo esc_html($entry['theme'] ?? ''); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php if (!empty($all_logs) && empty($logs)) : ?>
                                <div class="wp-tests-empty"><p><?php esc_html_e('No test results match your current filters.', 'wp-tests'); ?></p></div>
                            <?php elseif (empty($all_logs)) : ?>
                                <div class="wp-tests-empty"><p><?php esc_html_e('No tests have been executed yet.', 'wp-tests'); ?></p></div>
                            <?php endif; ?>
                    </div>
            <?php } else { ?>
                <p><?php esc_html_e('Enable the switch above to log and review wp/test action runs.', 'wp-tests'); ?></p>
            <?php } ?>
        </div>
        <?php
    }

    public function handle_test_action($payload): void
    {
        $is_cli = $this->is_cli_context();

        if (!$is_cli) {
            if (!is_user_logged_in()) {
                return;
            }

            $user_login = $this->get_current_user_login();
            if (!$user_login) {
                return;
            }

            $states = get_option(self::OPTION_USER_STATES, []);
            if (empty($states[$user_login])) {
                return;
            }
        } else {
            // In CLI context, default to current user if set, otherwise tag as wp_cli.
            $user_login = $this->get_current_user_login() ?: 'wp_cli';
        }

        if (!is_array($payload)) {
            $this->record_failure_result($user_login, '', [], null, __('Invalid wp/test payload: expected array.', 'wp-tests'));
            return;
        }

        $has_function_key = array_key_exists('function', $payload);
        $has_assert_key = array_key_exists('assert', $payload);
        $has_args_key = array_key_exists('args', $payload);
        $function = $payload['function'] ?? null;
        $args = $payload['args'] ?? [];
        $expected = $payload['assert'] ?? null;

        if (!$has_args_key) {
            $this->record_failure_result($user_login, $function, [], $expected, __('Invalid wp/test payload: missing args key.', 'wp-tests'));
            return;
        }

        if (!is_array($args)) {
            $this->record_failure_result($user_login, $function, [], $expected, __('Invalid wp/test args: expected array.', 'wp-tests'));
            return;
        }

        $args = is_array($args) ? $args : [];

        if (!$has_function_key) {
            $this->record_failure_result($user_login, '', $args, $expected, __('Invalid wp/test payload: missing function key.', 'wp-tests'));
            return;
        }

        if ($function === null || $function === '') {
            $this->record_failure_result($user_login, '', $args, $expected, __('Invalid wp/test payload: empty function value.', 'wp-tests'));
            return;
        }

        if (!$has_assert_key) {
            $this->record_failure_result($user_login, $function, $args, $expected, __('Invalid wp/test payload: missing assert key.', 'wp-tests'));
            return;
        }

        $result = [
            'function' => is_string($function) ? $function : '',
            'args' => wp_json_encode($args),
            'expected' => wp_json_encode($expected),
            'actual' => '',
            'passed' => false,
            'plugin' => '',
            'theme' => '',
        ];

        if (!$function || (!function_exists($function) && !is_callable($function))) {
            $result['actual'] = esc_html__('Function not callable', 'wp-tests');
            $this->log_result($user_login, $result);
            return;
        }

        $original_user_id = get_current_user_id();
        $runner_user_id = $this->ensure_wp_tests_user();
        $switched_user = false;
        if ($runner_user_id && $runner_user_id !== $original_user_id) {
            wp_set_current_user($runner_user_id);
            $switched_user = true;
        }

        $block_writes = !$this->is_db_actions_allowed();
        if ($block_writes) {
            $this->start_blocking_writes();
        }

        $actual = null;
        $passed = false;
        $error_message = '';

        try {
            $actual = call_user_func_array($function, $args);
            $passed = $this->compare_results($actual, $expected);
        } catch (Throwable $e) {
            $error_message = $e->getMessage();
        } finally {
            if ($block_writes) {
                $this->stop_blocking_writes();
            }
            if ($switched_user) {
                wp_set_current_user($original_user_id ?: 0);
            }
        }

        $result['actual'] = $error_message ? $error_message : wp_json_encode($actual);
        $result['passed'] = $passed && !$error_message;
        $result['plugin'] = $this->detect_plugin_directory($function);
        $result['theme'] = $this->detect_theme_directory($function);

        $this->log_result($user_login, $result);
    }

    private function compare_results($actual, $expected): bool
    {
        if (is_scalar($actual) || $actual === null) {
            return $actual === $expected;
        }

        return wp_json_encode($actual) === wp_json_encode($expected);
    }

    private function log_result(string $user_login, array $entry): void
    {
        global $wp_tests_runtime_logs;
        if (!is_array($wp_tests_runtime_logs)) {
            $wp_tests_runtime_logs = [];
        }

        if (!isset($wp_tests_runtime_logs[$user_login]) || !is_array($wp_tests_runtime_logs[$user_login])) {
            $wp_tests_runtime_logs[$user_login] = [];
        }

        array_unshift($wp_tests_runtime_logs[$user_login], $entry);
        if (count($wp_tests_runtime_logs[$user_login]) > self::MAX_LOG_ENTRIES) {
            $wp_tests_runtime_logs[$user_login] = array_slice($wp_tests_runtime_logs[$user_login], 0, self::MAX_LOG_ENTRIES);
        }
    }

    private function get_user_logs(string $user_login): array
    {
        global $wp_tests_runtime_logs;
        if (!is_array($wp_tests_runtime_logs)) {
            return [];
        }

        return isset($wp_tests_runtime_logs[$user_login]) && is_array($wp_tests_runtime_logs[$user_login]) ? $wp_tests_runtime_logs[$user_login] : [];
    }

    private function get_all_logs(): array
    {
        global $wp_tests_runtime_logs;
        if (!is_array($wp_tests_runtime_logs)) {
            return [];
        }

        $all = [];
        foreach ($wp_tests_runtime_logs as $entries) {
            if (!is_array($entries)) {
                continue;
            }
            foreach ($entries as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $all[] = $entry;
            }
        }

        // Maintain insertion order (most recent at front) from log_result.

        return $all;
    }

    private function get_log_filters(array $defaults = []): array
    {
        $defaults = $this->normalize_filter_defaults($defaults);

        $nonce = isset($_GET['wp_tests_filters_nonce']) ? sanitize_text_field(wp_unslash($_GET['wp_tests_filters_nonce'])) : '';
        if ($nonce === '' || !wp_verify_nonce($nonce, 'wp_tests_filter_logs')) {
            return $this->normalize_filter_defaults($defaults);
        }

        $plugin = isset($_GET['wp_tests_plugin_filter']) ? sanitize_text_field(wp_unslash($_GET['wp_tests_plugin_filter'])) : $defaults['plugin'];
        $result = isset($_GET['wp_tests_result_filter']) ? sanitize_text_field(wp_unslash($_GET['wp_tests_result_filter'])) : $defaults['result'];
        $search = isset($_GET['wp_tests_search']) ? sanitize_text_field(wp_unslash($_GET['wp_tests_search'])) : $defaults['search'];

        return $this->normalize_filter_defaults([
            'plugin' => $plugin,
            'result' => $result,
            'search' => $search,
            'theme' => $defaults['theme'],
        ]);
    }

    private function get_saved_filters(int $user_id): array
    {
        $defaults = $this->normalize_filter_defaults();

        $stored = get_user_meta($user_id, 'wp_tests_filter_state', true);

        if (!is_array($stored)) {
            return $defaults;
        }

        return array_merge($defaults, array_intersect_key($stored, $defaults));
    }

    private function save_log_filters(int $user_id, array $filters): void
    {
        $filters = $this->normalize_filter_defaults($filters);

        update_user_meta($user_id, 'wp_tests_filter_state', $filters);
    }

    private function apply_log_filters(array $logs, array $filters): array
    {
        if (empty($logs)) {
            return [];
        }

        $filters = $this->normalize_filter_defaults($filters);
        $search = $filters['search'] !== '' ? strtolower($filters['search']) : '';

        return array_values(array_filter($logs, function ($entry) use ($filters, $search) {
            if ($filters['plugin'] !== '' && ($entry['plugin'] ?? '') !== $filters['plugin']) {
                return false;
            }

            if ($filters['theme'] !== '' && ($entry['theme'] ?? '') !== $filters['theme']) {
                return false;
            }

            if ($filters['result'] === 'passed' && empty($entry['passed'])) {
                return false;
            }

            if ($filters['result'] === 'failed' && !empty($entry['passed'])) {
                return false;
            }

            if ($search !== '') {
                $haystack = strtolower(
                    ($entry['function'] ?? '') .
                    ' ' . ($entry['args'] ?? '') .
                    ' ' . ($entry['expected'] ?? '') .
                    ' ' . ($entry['actual'] ?? '')
                );

                if (strpos($haystack, $search) === false) {
                    return false;
                }
            }

            return true;
        }));
    }

    private function get_plugin_filter_options(array $logs): array
    {
        $options = [];
        foreach ($logs as $entry) {
            if (empty($entry['plugin'])) {
                continue;
            }

            $options[$entry['plugin']] = true;
        }

        return array_keys($options);
    }

    private function normalize_filter_defaults(array $overrides = []): array
    {
        $defaults = [
            'plugin' => '',
            'theme' => '',
            'result' => 'all',
            'search' => '',
        ];

        if (empty($overrides)) {
            return $defaults;
        }

        $merged = array_merge($defaults, $overrides);
        $merged['result'] = in_array($merged['result'], ['all', 'passed', 'failed'], true) ? $merged['result'] : 'all';
        $merged['plugin'] = (string) $merged['plugin'];
        $merged['theme'] = (string) $merged['theme'];
        $merged['search'] = (string) $merged['search'];

        return $merged;
    }

    public function bootstrap_runner_user(): void
    {
        $this->ensure_wp_tests_role();
        $this->ensure_wp_tests_user();
    }

    private function ensure_wp_tests_role(): void
    {
        if (get_role('wp-tests')) {
            return;
        }

        add_role(
            'wp-tests',
            __('WP Tests', 'wp-tests'),
            [
                'read' => true,
            ]
        );
    }

    private function ensure_wp_tests_user(): ?int
    {
        $this->ensure_wp_tests_role();

        $existing_user = get_user_by('login', 'wp-tests-runner');
        if ($existing_user) {
            if (!in_array('wp-tests', (array) $existing_user->roles, true)) {
                wp_update_user(['ID' => $existing_user->ID, 'role' => 'wp-tests']);
            }
            return (int) $existing_user->ID;
        }

        $users = get_users([
            'role' => 'wp-tests',
            'number' => 1,
            'fields' => 'ID',
        ]);

        if (!empty($users)) {
            return (int) $users[0];
        }

        $user_id = wp_insert_user([
            'user_login' => 'wp-tests-runner',
            'user_pass' => wp_generate_password(24, true, true),
            'role' => 'wp-tests',
            'display_name' => __('WP Tests Runner', 'wp-tests'),
            'user_email' => 'wp-tests-runner+' . wp_generate_password(6, false, false) . '@example.com',
        ]);

        if (is_wp_error($user_id)) {
            return null;
        }

        return (int) $user_id;
    }

    private function start_blocking_writes(): void
    {
        if ($this->blocking_writes) {
            return;
        }

        $this->blocking_writes = true;
        add_filter('query', [$this, 'block_mutating_queries'], 0);
    }

    private function stop_blocking_writes(): void
    {
        if (!$this->blocking_writes) {
            return;
        }

        remove_filter('query', [$this, 'block_mutating_queries'], 0);
        $this->blocking_writes = false;
    }

    public function block_mutating_queries($query)
    {
        if (!$this->blocking_writes) {
            return $query;
        }

        if (preg_match('/^\\s*(INSERT|UPDATE|DELETE|REPLACE|ALTER|DROP|CREATE|TRUNCATE|LOAD|RENAME|GRANT|REVOKE|LOCK|UNLOCK)/i', $query)) {
            throw new Exception(esc_html__('WP Tests blocking write query during test run.', 'wp-tests'));
        }

        return $query;
    }

    private function is_db_actions_allowed(): bool
    {
        if ($this->allow_db_actions_override) {
            return true;
        }

        if ($this->is_cli_context() && $this->cli_flag_enabled('allow-db-actions')) {
            return true;
        }

        return (bool) get_option(self::OPTION_ALLOW_DB_ACTIONS, false);
    }

    private function cli_flag_enabled(string $flag): bool
    {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $argv_raw = isset($_SERVER['argv']) ? wp_unslash((array) $_SERVER['argv']) : [];
        if (empty($argv_raw)) {
            return false;
        }

        $argv = array_map('sanitize_text_field', array_map('strval', $argv_raw));

        $needle = '--' . $flag;
        foreach ($argv as $arg) {
            $arg = sanitize_text_field((string) $arg);
            if ($arg === $needle || strpos($arg, $needle . '=') === 0) {
                return true;
            }
        }

        return false;
    }

    public function handle_cli_command($args, $assoc_args): void
    {
        if (!class_exists('WP_CLI')) {
            return;
        }

        $this->allow_db_actions_override = !empty($assoc_args['allow-db-actions']);

        $filters = $this->normalize_filter_defaults([
            'plugin' => isset($assoc_args['plugin']) ? sanitize_text_field($assoc_args['plugin']) : '',
            'theme' => isset($assoc_args['theme']) ? sanitize_text_field($assoc_args['theme']) : '',
            'result' => isset($assoc_args['failed-only']) ? 'failed' : 'all',
            'search' => '',
        ]);

        $logs = $this->apply_log_filters($this->get_all_logs(), $filters);

        if (empty($logs)) {
            WP_CLI::log(__('No test results found for the specified filters.', 'wp-tests'));
            return;
        }

        $failed = 0;

        foreach ($logs as $entry) {
            $status = !empty($entry['passed']) ? 'PASS' : 'FAIL';
            $context_parts = [];
            if (!empty($entry['plugin'])) {
                $context_parts[] = 'plugin=' . $entry['plugin'];
            }
            if (!empty($entry['theme'])) {
                $context_parts[] = 'theme=' . $entry['theme'];
            }
            $context = empty($context_parts) ? '' : ' [' . implode(', ', $context_parts) . ']';

            $line = sprintf(
                '%s%s :: %s(%s) expected=%s actual=%s',
                $status,
                $context,
                $entry['function'] ?? '',
                $entry['args'] ?? '',
                $entry['expected'] ?? '',
                $entry['actual'] ?? ''
            );

            if (!empty($entry['passed'])) {
                WP_CLI::log($line);
            } else {
                WP_CLI::warning($line);
                $failed++;
            }
        }

        if ($failed > 0) {
            WP_CLI::error(sprintf(__('%d failing test(s).', 'wp-tests'), $failed));
        }

        WP_CLI::success(__('All tests passed.', 'wp-tests'));
    }

    private function get_current_user_login(): ?string
    {
        $user = wp_get_current_user();
        if (!$user || !$user->exists()) {
            return null;
        }

        return $user->user_login;
    }

    private function detect_plugin_directory($callable): string
    {
        return $this->detect_directory($callable, WP_PLUGIN_DIR);
    }

    private function detect_theme_directory($callable): string
    {
        return $this->detect_directory($callable, get_theme_root());
    }

    private function detect_directory($callable, string $root_path): string
    {
        $file = '';

        try {
            if (is_string($callable) && function_exists($callable)) {
                $ref = new ReflectionFunction($callable);
                $file = $ref->getFileName();
            } elseif (is_array($callable) && count($callable) === 2) {
                $ref = new ReflectionMethod($callable[0], $callable[1]);
                $file = $ref->getFileName();
            }
        } catch (ReflectionException $e) {
            return '';
        }

        if (!$file) {
            return '';
        }

        $root_path = wp_normalize_path($root_path);
        $file = wp_normalize_path($file);

        if (strpos($file, $root_path) !== 0) {
            return '';
        }

        $relative = str_replace($root_path, '', $file);
        $relative = ltrim($relative, '/\\');

        $parts = explode('/', $relative);
        if (!empty($parts)) {
            return $parts[0];
        }

        return '';
    }

    private function record_failure_result(string $user_login, $function, array $args, $expected, string $message): void
    {
        $func_label = '';
        if (is_string($function)) {
            $func_label = $function;
        } elseif (is_array($function)) {
            $func_label = implode('::', array_map('strval', $function));
        }

        $entry = [
            'function' => $func_label,
            'args' => wp_json_encode($args),
            'expected' => wp_json_encode($expected),
            'actual' => $message,
            'passed' => false,
            'plugin' => '',
            'theme' => '',
        ];

        if ($func_label && is_callable($function)) {
            $entry['plugin'] = $this->detect_plugin_directory($function);
            $entry['theme'] = $this->detect_theme_directory($function);
        }

        $this->log_result($user_login, $entry);
    }

    private function is_cli_context(): bool
    {
        return defined('WP_CLI') && WP_CLI;
    }
}

(function () {
    $plugin = new WP_Tests_Plugin();
    $plugin->register();
})();
