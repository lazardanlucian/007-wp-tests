=== WP Tests ===
Contributors: Dan
Tags: testing, debugging, cli, hooks
Requires at least: 6.3
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Lightweight per-user test harness that runs `do_action( 'wp/test' )` payloads and shows results in Tools or WP-CLI.

== Description ==

Run quick, per-request assertions inside WordPress by firing the `wp/test` action with a payload that names the callable, arguments, and expected value. Results are scoped to the current user, surfaced in a small Tools screen, and can be filtered or inspected via WP-CLI.

== Usage ==

= Triggering tests with `do_action( 'wp/test' )` =
The plugin buffers calls made before it loads, then attaches its handler on `init`. Send an array payload with three keys:

```php
do_action(
    'wp/test',
    [
        'function' => 'my_custom_function',     // Callable name or [ClassName, 'method']
        'args'     => ['foo', 'bar'],           // Arguments array (required key, can be empty)
        'assert'   => 'expected return value',  // Expected value; compared strictly for scalars
    ]
);
```

Notes:
- Calls are ignored unless the current user has enabled tests on the Tools page (CLI is always allowed).
- When the callable is not found, has missing keys, or throws, the result is recorded as a failure with the error message.
- Writes to the database are blocked during a test run unless explicitly allowed (see below).

= Using the Tools page =
1. Go to `Tools → WP Tests`.
2. Toggle **Enable tests** to let `wp/test` runs execute for your account. The switch submits automatically.
3. Toggle **Allow database writes** if your assertions need to modify data (options, posts, etc.). Leave off for safer, read-only checks.
4. When enabled, the page lists recent runs with filters for plugin directory, result (pass/fail), and a search box for function, args, expected, or actual values.

= Using WP-CLI =
Run the command from your WordPress install:

```
wp wp-test [--plugin=<dir>] [--theme=<dir>] [--failed-only] [--allow-db-actions]
```

- `--plugin` / `--theme` filter results by the detected callable location.
- `--failed-only` shows only failures and exits non-zero if any fail.
- `--allow-db-actions` permits database writes for the run (overrides the setting).
- Output is PASS/FAIL lines with context; failures are surfaced as WP-CLI warnings and error out when present.

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/wp-tests` or install via your preferred method.
2. Activate the plugin through the **Plugins** screen.
3. Visit `Tools → WP Tests` to enable tests for your user before firing `do_action( 'wp/test' )` in your code.

== Frequently Asked Questions ==

= Do I have to enable tests for every user? =
Yes. Runs are opt-in per account via the Tools switch to avoid surprising side effects on other users’ requests.

= How are results stored? =
Results live in memory per request and are capped to the most recent entries. The Tools screen only shows runs from the current request lifecycle.

= Why are my writes failing? =
Database writes are blocked during tests by default. Enable **Allow database writes** on the Tools page or pass `--allow-db-actions` when using WP-CLI if your callable needs to modify data.

== Screenshots ==

1. Tools page with enable/allow-db-write toggles and test results table.

== Changelog ==

= 0.1.0 =
* Initial release with `wp/test` action handling, Tools page, log filtering, and `wp wp-test` command.
