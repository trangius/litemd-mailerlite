<?php

declare(strict_types=1);

namespace LiteMD\Plugins\Mailerlite;

use LiteMD\Plugin as PluginRegistry;
use LiteMD\Config;
use LiteMD\BasePath;
use LiteMD\Http;

// ----------------------------------------------------------------------------
// MailerLite plugin. Adds a newsletter subscription checkbox to the user auth
// dropdown, syncs with MailerLite, and handles webhooks for external unsubscribes.
// On login, polls MailerLite to reconcile local state.
// ----------------------------------------------------------------------------
class Plugin
{
    // ----------------------------------------------------------------------------
    // Plugin metadata shown in the admin Plugins tab.
    // ----------------------------------------------------------------------------
    public static function meta(): array
    {
        return [
            'name'        => 'MailerLite',
            'version'     => '1.0',
            'description' => 'Newsletter subscription synced with MailerLite.',
            'author'      => 'LiteMD',
            'requires'    => [['mysql', '1.0'], ['users', '1.0']],
            'setup_fields' => [
                ['name' => 'api_key', 'label' => 'MailerLite API key', 'type' => 'text', 'required' => true],
            ],
        ];
    }

    // ----------------------------------------------------------------------------
    // Runs once on install. Adds the newsletter column to the users table and
    // stores the API key in config.
    // ----------------------------------------------------------------------------
    public static function setup(array $data = []): string
    {
        $apiKey = trim((string) ($data['api_key'] ?? ''));
        if ($apiKey === '') {
            throw new \RuntimeException('MailerLite API key is required.');
        }

        // Test the API key before saving
        $response = self::mailerliteRequest('GET', 'subscribers?limit=1', [], $apiKey);
        if ($response === null) {
            throw new \RuntimeException('Could not connect to MailerLite. Check your API key.');
        }

        // Save API key to config.php under plugins.newsletter
        $configFile = dirname(__DIR__, 2) . '/config.php';
        $config = is_file($configFile) ? (array) require $configFile : [];
        if (!isset($config['plugins'])) {
            $config['plugins'] = [];
        }
        $config['plugins']['mailerlite'] = [
            'api_key' => $apiKey,
        ];
        self::writeMainConfig($configFile, $config);

        // Add newsletter column to users table
        $pdo = PluginRegistry::getService('database');
        if (!$pdo) {
            throw new \RuntimeException('Database service not available.');
        }

        // Add column if it doesn't exist
        $cols = $pdo->query('SHOW COLUMNS FROM users LIKE "newsletter"')->fetchAll();
        if (empty($cols)) {
            $pdo->exec('ALTER TABLE users ADD COLUMN newsletter TINYINT DEFAULT 0');
        }

        return 'Connected to MailerLite and added newsletter column.';
    }

    // ----------------------------------------------------------------------------
    // Returns cleanup actions shown before uninstall confirmation.
    // ----------------------------------------------------------------------------
    public static function uninstall(): array
    {
        return [
            [
                'description' => 'Remove newsletter column from users table and API key from config',
                'destructive' => true,
                'execute'     => function () {
                    // Remove the column
                    $pdo = PluginRegistry::getService('database');
                    if ($pdo) {
                        try {
                            $pdo->exec('ALTER TABLE users DROP COLUMN newsletter');
                        } catch (\Throwable $e) {
                            // Column may already be gone
                        }
                    }

                    // Remove config
                    $configFile = dirname(__DIR__, 2) . '/config.php';
                    if (is_file($configFile)) {
                        $config = (array) require $configFile;
                        if (isset($config['plugins']['mailerlite'])) {
                            unset($config['plugins']['mailerlite']);
                        }
                        self::writeMainConfig($configFile, $config);
                    }
                },
            ],
        ];
    }

    // ----------------------------------------------------------------------------
    // Runs on every request. Registers the checkbox slot, webhook route,
    // assets, and API actions.
    // ----------------------------------------------------------------------------
    public static function register(): void
    {
        // Strip /admin suffix so asset URLs are correct on both public and admin pages
        $base = BasePath::detect('/admin');

        // Sync newsletter status with MailerLite on login (once per session)
        if (isset($_SESSION['user_id']) && is_int($_SESSION['user_id'])
            && empty($_SESSION['newsletter_synced'])) {

            $pdo = PluginRegistry::getService('database');
            if ($pdo) {
                $stmt = $pdo->prepare('SELECT email FROM users WHERE id = ?');
                $stmt->execute([$_SESSION['user_id']]);
                $row = $stmt->fetch();
                if ($row) {
                    self::syncOnLogin($_SESSION['user_id'], $row['email']);
                    $_SESSION['newsletter_synced'] = true;
                }
            }
        }

        // Auto-subscribe new users to the newsletter
        PluginRegistry::addToSlot('user-registered', [self::class, 'onUserRegistered']);

        // Inject newsletter checkbox into the auth dropdown
        PluginRegistry::addToSlot('auth-dropdown-extras', [self::class, 'renderCheckbox']);

        // Public JS for the checkbox toggle
        PluginRegistry::addAsset('js', $base . '/plugins/mailerlite/assets/mailerlite.js');
        PluginRegistry::addAsset('css', $base . '/plugins/mailerlite/assets/mailerlite.css');

        // Public route for toggling subscription from the auth dropdown
        PluginRegistry::addRoute('/newsletter-toggle', [self::class, 'handleToggleRoute']);

        // Webhook endpoint for MailerLite to notify us of unsubscribes
        PluginRegistry::addRoute('/newsletter-webhook', [self::class, 'handleWebhook']);

        // Admin API action for toggling subscription from the members panel
        PluginRegistry::addApiAction('newsletter-toggle', [self::class, 'apiToggle'], 'POST');

        // Settings sub-tab under Advanced
        PluginRegistry::addAdvancedTab([
            'slug'  => 'mailerlite',
            'label' => 'MailerLite',
            'file'  => __DIR__ . '/admin/settings.php',
        ]);

        // API action for saving settings from the admin panel
        PluginRegistry::addApiAction('mailerlite-settings-save', [self::class, 'apiSaveSettings'], 'POST');
    }

    // ----------------------------------------------------------------------------
    // Save updated API key from the admin settings panel.
    // ----------------------------------------------------------------------------
    public static function apiSaveSettings(array $payload = []): void
    {
        $apiKey = trim((string) ($payload['api_key'] ?? ''));
        if ($apiKey === '') {
            editor_error_response('API key is required.', 400, 'saving MailerLite settings');
            return;
        }

        // Test the key before saving
        $response = self::mailerliteRequest('GET', 'subscribers?limit=1', [], $apiKey);
        if ($response === null) {
            editor_error_response('Could not connect to MailerLite. Check your API key.', 400, 'saving MailerLite settings');
            return;
        }

        // Update config.php plugins.mailerlite section
        $configFile = dirname(__DIR__, 2) . '/config.php';
        $config = is_file($configFile) ? (array) require $configFile : [];
        if (!isset($config['plugins'])) {
            $config['plugins'] = [];
        }
        $config['plugins']['mailerlite'] = [
            'api_key' => $apiKey,
        ];
        self::writeMainConfig($configFile, $config);

        editor_log('MailerLite settings updated');
        editor_json_response(['ok' => true]);
    }

    // ========================================================================
    // Slot renderer — newsletter checkbox in auth dropdown
    // ========================================================================

    // ----------------------------------------------------------------------------
    // Render the newsletter checkbox for the auth-dropdown-extras slot.
    // ----------------------------------------------------------------------------
    public static function renderCheckbox(array $ctx = []): string
    {
        $currentUser = $ctx['currentUser'] ?? null;
        if (!$currentUser) return '';

        // Query the newsletter column directly since Auth::currentUser()
        // doesn't include it (it belongs to this plugin, not Users)
        $pdo = PluginRegistry::getService('database');
        $isSubscribed = false;
        if ($pdo) {
            $stmt = $pdo->prepare('SELECT newsletter FROM users WHERE id = ?');
            $stmt->execute([$currentUser['id']]);
            $row = $stmt->fetch();
            $isSubscribed = $row && !empty($row['newsletter']);
        }

        ob_start();
        include __DIR__ . '/includes/checkbox.php';
        return ob_get_clean();
    }

    // ========================================================================
    // Registration hook — auto-subscribe new users
    // ========================================================================

    // ----------------------------------------------------------------------------
    // Called via the 'user-registered' slot after a new account is created.
    // Adds the user to MailerLite and sets the local newsletter flag to 1.
    // ----------------------------------------------------------------------------
    public static function onUserRegistered(array $ctx = []): string
    {
        $userId = (int) ($ctx['user_id'] ?? 0);
        $email = (string) ($ctx['email'] ?? '');
        if ($userId <= 0 || $email === '') return '';

        $apiKey = self::getApiKey();
        if ($apiKey === '') return '';

        // Add to MailerLite
        $response = self::mailerliteRequest('POST', 'subscribers', [
            'email' => $email,
            'status' => 'active',
        ], $apiKey);

        // Update local flag (even if MailerLite fails, we set it so the
        // checkbox shows checked — the login sync will reconcile later)
        $pdo = PluginRegistry::getService('database');
        if ($pdo) {
            $stmt = $pdo->prepare('UPDATE users SET newsletter = 1 WHERE id = ?');
            $stmt->execute([$userId]);
        }

        // Mark session as synced so we don't poll MailerLite again immediately
        $_SESSION['newsletter_synced'] = true;

        return '';
    }

    // ========================================================================
    // Login hook — poll MailerLite to reconcile local state
    // ========================================================================

    // ----------------------------------------------------------------------------
    // Called after a user logs in or registers. Checks MailerLite for their
    // current subscription status and updates the local column to match.
    // ----------------------------------------------------------------------------
    public static function syncOnLogin(int $userId, string $email): void
    {
        $apiKey = self::getApiKey();
        if ($apiKey === '') return;

        $response = self::mailerliteRequest('GET', 'subscribers/' . urlencode($email), [], $apiKey);

        $isActive = false;
        if ($response !== null && isset($response['data']['status'])) {
            $isActive = ($response['data']['status'] === 'active');
        }

        // Update local column
        $pdo = PluginRegistry::getService('database');
        if ($pdo) {
            $stmt = $pdo->prepare('UPDATE users SET newsletter = ? WHERE id = ?');
            $stmt->execute([$isActive ? 1 : 0, $userId]);
        }
    }

    // ========================================================================
    // Public toggle route — called from the auth dropdown checkbox
    // ========================================================================

    // ----------------------------------------------------------------------------
    // Handle POST to /newsletter-toggle from logged-in users toggling the
    // checkbox in the auth dropdown.
    // ----------------------------------------------------------------------------
    public static function handleToggleRoute(): void
    {
        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            Http::jsonResponse(['ok' => false, 'error' => 'Method not allowed.'], 405);
        }

        // Require login
        if (!isset($_SESSION['user_id']) || !is_int($_SESSION['user_id'])) {
            Http::jsonResponse(['ok' => false, 'error' => 'Not logged in.'], 401);
        }

        $payload = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            Http::jsonResponse(['ok' => false, 'error' => 'Invalid request.'], 400);
        }

        // Only allow users to toggle their own subscription
        $userId = $_SESSION['user_id'];
        $subscribe = !empty($payload['subscribe']);

        // Reuse the admin toggle logic
        $fakePayload = ['user_id' => $userId, 'subscribe' => $subscribe];
        self::apiToggle($fakePayload);
    }

    // ========================================================================
    // Webhook handler — MailerLite notifies us of changes
    // ========================================================================

    // ----------------------------------------------------------------------------
    // Handle incoming webhook from MailerLite. Updates the local newsletter
    // column when a subscriber's status changes.
    // ----------------------------------------------------------------------------
    public static function handleWebhook(): void
    {
        $payload = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($payload)) {
            Http::jsonResponse(['ok' => false, 'error' => 'Invalid payload.'], 400);
        }

        // MailerLite webhook events include subscriber.unsubscribed,
        // subscriber.subscribed, etc.
        $email = (string) ($payload['events'][0]['data']['subscriber']['email'] ?? '');
        $event = (string) ($payload['events'][0]['type'] ?? '');

        if ($email === '') {
            Http::jsonResponse(['ok' => true, 'message' => 'No email in payload.']);
        }

        $pdo = PluginRegistry::getService('database');
        if (!$pdo) {
            Http::jsonResponse(['ok' => false, 'error' => 'Database not available.'], 500);
        }

        // Update local column based on event type
        $subscribed = str_contains($event, 'subscribed') && !str_contains($event, 'unsubscribed') ? 1 : 0;
        $stmt = $pdo->prepare('UPDATE users SET newsletter = ? WHERE email = ?');
        $stmt->execute([$subscribed, $email]);

        Http::jsonResponse(['ok' => true]);
    }

    // ========================================================================
    // Admin API — toggle subscription
    // ========================================================================

    // ----------------------------------------------------------------------------
    // Toggle a user's newsletter subscription. Called from the frontend
    // checkbox and the admin panel.
    // ----------------------------------------------------------------------------
    public static function apiToggle(array $payload = []): void
    {
        $userId = (int) ($payload['user_id'] ?? 0);
        $subscribe = !empty($payload['subscribe']);

        if ($userId <= 0) {
            editor_error_response('Invalid user ID.', 400, 'toggling newsletter');
            return;
        }

        // Get the user's email
        $pdo = PluginRegistry::getService('database');
        $stmt = $pdo->prepare('SELECT email FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        if (!$user) {
            editor_error_response('User not found.', 404, 'toggling newsletter');
            return;
        }

        $apiKey = self::getApiKey();
        $email = $user['email'];

        if ($subscribe) {
            // Add/update subscriber on MailerLite
            $response = self::mailerliteRequest('POST', 'subscribers', [
                'email' => $email,
                'status' => 'active',
            ], $apiKey);

            if ($response === null) {
                editor_error_response('Could not reach MailerLite.', 502, 'subscribing ' . $email);
                return;
            }
        } else {
            // Unsubscribe on MailerLite
            $response = self::mailerliteRequest('PUT', 'subscribers/' . urlencode($email), [
                'status' => 'unsubscribed',
            ], $apiKey);

            if ($response === null) {
                editor_error_response('Could not reach MailerLite.', 502, 'unsubscribing ' . $email);
                return;
            }
        }

        // Update local column on success
        $stmt = $pdo->prepare('UPDATE users SET newsletter = ? WHERE id = ?');
        $stmt->execute([$subscribe ? 1 : 0, $userId]);

        editor_json_response(['ok' => true, 'newsletter' => $subscribe ? 1 : 0]);
    }

    // ========================================================================
    // MailerLite API helper
    // ========================================================================

    // ----------------------------------------------------------------------------
    // Make a request to the MailerLite API (v2). Returns the decoded JSON
    // response or null on failure.
    // ----------------------------------------------------------------------------
    private static function mailerliteRequest(string $method, string $endpoint, array $body = [], string $apiKey = ''): ?array
    {
        if ($apiKey === '') {
            $apiKey = self::getApiKey();
        }
        if ($apiKey === '') return null;

        $url = 'https://connect.mailerlite.com/api/' . ltrim($endpoint, '/');

        $headers = [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        } elseif ($method === 'PUT') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        } elseif ($method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        }

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($result === false || $httpCode >= 500) {
            return null;
        }

        return json_decode($result, true) ?: [];
    }

    // ----------------------------------------------------------------------------
    // Get the MailerLite API key from config.
    // ----------------------------------------------------------------------------
    private static function getApiKey(): string
    {
        $pluginConfig = Config::getPluginConfig('mailerlite', []);
        return (string) ($pluginConfig['api_key'] ?? '');
    }

    // ----------------------------------------------------------------------------
    // Write the full config array to the main config.php file.
    // ----------------------------------------------------------------------------
    private static function writeMainConfig(string $configFile, array $config): void
    {
        if (function_exists('var_export_short')) {
            $export = "<?php\n\nreturn " . var_export_short($config) . ";\n";
        } else {
            $export = "<?php\n\nreturn " . var_export($config, true) . ";\n";
        }

        $result = file_put_contents($configFile, $export);
        if ($result === false) {
            throw new \RuntimeException('Could not write config.php');
        }
    }
}
