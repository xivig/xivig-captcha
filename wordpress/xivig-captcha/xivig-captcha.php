<?php
/**
 * Plugin Name: Xivig Captcha & Anti-Abuse Shield
 * Plugin URI:  https://github.com/cyberword/xivig-captcha
 * Description: High-performance, privacy-first Proof-of-Work anti-bot protection for WordPress and WooCommerce.
 * Version:     2.0.0
 * Author:      CyberWord
 * License:     MIT
 * Text Domain: xivig-captcha
 */

if (!defined('ABSPATH')) {
    exit;
}

// 1. Standalone Autoloader for WordPress environments without global Composer
spl_autoload_register(function ($class) {
    $prefix = 'Aegis\\Captcha\\';
    $baseDir = dirname(__DIR__, 2) . '/src/';
    $len = strlen($prefix);

    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});

use Aegis\Captcha\Aegis;
use Aegis\Captcha\Config\AegisConfig;
use Aegis\Captcha\Storage\FileNonceStorage;

final class XivigWpPlugin
{
    private static ?self $instance = null;
    private ?Aegis $aegis = null;

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        add_action('init', [$this, 'initAegis']);
        add_action('admin_menu', [$this, 'registerAdminMenu']);
        add_action('admin_init', [$this, 'registerSettings']);
        add_action('wp_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('login_enqueue_scripts', [$this, 'enqueueAssets']);

        // Form Protection Hooks
        $options = get_option('xivig_captcha_options', []);

        if (!empty($options['protect_login'])) {
            add_action('login_form', [$this, 'renderCaptchaWidget']);
            add_filter('authenticate', [$this, 'verifyLogin'], 20, 3);
        }

        if (!empty($options['protect_register'])) {
            add_action('register_form', [$this, 'renderCaptchaWidget']);
            add_filter('registration_errors', [$this, 'verifyRegistration'], 10, 3);
        }

        if (!empty($options['protect_comments'])) {
            add_action('comment_form_after_fields', [$this, 'renderCaptchaWidget']);
            add_action('comment_form_logged_in_after', [$this, 'renderCaptchaWidget']);
            add_filter('preprocess_comment', [$this, 'verifyComment']);
        }

        // WooCommerce Checkout Protection
        if (!empty($options['protect_woocommerce'])) {
            add_action('woocommerce_after_checkout_billing_form', [$this, 'renderCaptchaWidget']);
            add_action('woocommerce_checkout_process', [$this, 'verifyWooCommerce']);
        }
    }

    public function initAegis(): void
    {
        $options = get_option('xivig_captcha_options', []);
        $secretKey = $options['secret_key'] ?? (defined('AUTH_KEY') ? AUTH_KEY : 'default-xivig-wordpress-secret-key-32ch');

        if (strlen($secretKey) < 32) {
            $secretKey = str_pad($secretKey, 32, '0');
        }

        $config = new AegisConfig(
            secretKey: $secretKey,
            mode: $options['mode'] ?? AegisConfig::MODE_INVISIBLE,
            powDifficulty: (int)($options['pow_difficulty'] ?? 3),
            challengeEndpoint: home_url('/?xivig=challenge')
        );

        $uploadDir = wp_upload_dir();
        $storageDir = $uploadDir['basedir'] . '/xivig-nonces';
        $storage = new FileNonceStorage($storageDir);

        $this->aegis = Aegis::init($config, $storage);

        // Challenge API Endpoint interceptor
        if (isset($_GET['xivig']) && $_GET['xivig'] === 'challenge') {
            header('Content-Type: application/json');
            header('Cache-Control: no-store, private');
            $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
            echo json_encode($this->aegis->createChallenge($ip, $ua), JSON_THROW_ON_ERROR);
            exit;
        }
    }

    public function enqueueAssets(): void
    {
        $pluginUrl = plugin_dir_url(__FILE__);
        $assetsUrl = dirname($pluginUrl, 2) . '/assets/';

        wp_enqueue_style('xivig-captcha-css', $assetsUrl . 'css/aegis.css', [], '2.0.0');
        wp_enqueue_script('xivig-captcha-js', $assetsUrl . 'js/aegis.js', [], '2.0.0', true);
    }

    public function renderCaptchaWidget(): void
    {
        if ($this->aegis) {
            echo $this->aegis->renderWidget();
        }
    }

    private function getClientIp(): string
    {
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            return sanitize_text_field(wp_unslash($_SERVER['HTTP_CF_CONNECTING_IP']));
        }
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $parts = explode(',', (string)wp_unslash($_SERVER['HTTP_X_FORWARDED_FOR']));
            return trim(sanitize_text_field($parts[0]));
        }
        return sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'));
    }

    private function getClientUserAgent(): string
    {
        return sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'));
    }

    public function verifyLogin($user, $username, $password)
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST)) {
            $payload = sanitize_text_field(wp_unslash($_POST['aegis_payload'] ?? ''));
            $ip = $this->getClientIp();
            $ua = $this->getClientUserAgent();

            $res = $this->aegis?->verify($payload, $ip, $ua);
            if (!$res || !$res->isHuman) {
                return new WP_Error('xivig_bot_blocked', __('<strong>Error:</strong> Automated bot activity detected.', 'xivig-captcha'));
            }
        }
        return $user;
    }

    public function verifyRegistration($errors, $sanitized_user_login, $user_email)
    {
        $payload = sanitize_text_field(wp_unslash($_POST['aegis_payload'] ?? ''));
        $ip = $this->getClientIp();
        $ua = $this->getClientUserAgent();

        $res = $this->aegis?->verify($payload, $ip, $ua);
        if (!$res || !$res->isHuman) {
            $errors->add('xivig_bot_blocked', __('Automated registration attempt blocked by Xivig.', 'xivig-captcha'));
        }
        return $errors;
    }

    public function verifyComment($commentdata)
    {
        $payload = sanitize_text_field(wp_unslash($_POST['aegis_payload'] ?? ''));
        $ip = $this->getClientIp();
        $ua = $this->getClientUserAgent();

        $res = $this->aegis?->verify($payload, $ip, $ua);
        if (!$res || !$res->isHuman) {
            wp_die(__('Comment submission blocked: automated activity suspected.', 'xivig-captcha'), 403);
        }
        return $commentdata;
    }

    public function verifyWooCommerce(): void
    {
        $payload = sanitize_text_field(wp_unslash($_POST['aegis_payload'] ?? ''));
        $ip = $this->getClientIp();
        $ua = $this->getClientUserAgent();

        $res = $this->aegis?->verify($payload, $ip, $ua);
        if (!$res || !$res->isHuman) {
            wc_add_notice(__('Security verification failed. Please refresh and retry checkout.', 'xivig-captcha'), 'error');
        }
    }

    public function registerAdminMenu(): void
    {
        add_options_page(
            'Xivig Captcha Settings',
            'Xivig Captcha',
            'manage_options',
            'xivig-captcha',
            [$this, 'renderAdminSettingsPage']
        );
    }

    public function registerSettings(): void
    {
        register_setting('xivig_captcha_group', 'xivig_captcha_options', [
            'type' => 'array',
            'sanitize_callback' => [$this, 'sanitizeOptions'],
            'default' => [
                'pow_difficulty' => 3,
                'protect_login' => 1,
                'protect_register' => 1,
                'protect_comments' => 1,
                'protect_woocommerce' => 1
            ]
        ]);
    }

    /**
     * Strict input sanitization and validation for admin settings
     */
    public function sanitizeOptions(mixed $input): array
    {
        $sanitized = [];
        if (!is_array($input)) {
            return $sanitized;
        }

        if (!empty($input['secret_key'])) {
            $key = sanitize_text_field((string)$input['secret_key']);
            $sanitized['secret_key'] = strlen($key) >= 32 ? $key : str_pad($key, 32, '0');
        }

        $difficulty = isset($input['pow_difficulty']) ? (int)$input['pow_difficulty'] : 3;
        $sanitized['pow_difficulty'] = max(1, min(6, $difficulty));

        $sanitized['protect_login'] = !empty($input['protect_login']) ? 1 : 0;
        $sanitized['protect_register'] = !empty($input['protect_register']) ? 1 : 0;
        $sanitized['protect_comments'] = !empty($input['protect_comments']) ? 1 : 0;
        $sanitized['protect_woocommerce'] = !empty($input['protect_woocommerce']) ? 1 : 0;

        return $sanitized;
    }

    public function renderAdminSettingsPage(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'xivig-captcha'), 403);
        }
        $options = get_option('xivig_captcha_options', [
            'pow_difficulty' => 3,
            'protect_login' => 1,
            'protect_register' => 1,
            'protect_comments' => 1,
            'protect_woocommerce' => 1
        ]);
        ?>
        <div class="wrap">
            <h1>Xivig Captcha & Anti-Abuse Settings</h1>
            <form method="post" action="options.php">
                <?php settings_fields('xivig_captcha_group'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">Secret Key</th>
                        <td>
                            <input type="text" name="xivig_captcha_options[secret_key]" value="<?php echo esc_attr($options['secret_key'] ?? ''); ?>" class="regular-text" placeholder="Auto-generated from AUTH_KEY if blank">
                            <p class="description">Must be at least 32 characters long.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Proof of Work Difficulty</th>
                        <td>
                            <select name="xivig_captcha_options[pow_difficulty]">
                                <option value="2" <?php selected($options['pow_difficulty'] ?? 3, 2); ?>>2 - Ultra Light (~5ms)</option>
                                <option value="3" <?php selected($options['pow_difficulty'] ?? 3, 3); ?>>3 - Standard Recommended (~20ms)</option>
                                <option value="4" <?php selected($options['pow_difficulty'] ?? 3, 4); ?>>4 - High Friction (~80ms)</option>
                                <option value="5" <?php selected($options['pow_difficulty'] ?? 3, 5); ?>>5 - Maximum Defense (~300ms)</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Protected Forms</th>
                        <td>
                            <label><input type="checkbox" name="xivig_captcha_options[protect_login]" value="1" <?php checked(!empty($options['protect_login'])); ?>> WordPress Login (wp-login.php)</label><br>
                            <label><input type="checkbox" name="xivig_captcha_options[protect_register]" value="1" <?php checked(!empty($options['protect_register'])); ?>> User Registration</label><br>
                            <label><input type="checkbox" name="xivig_captcha_options[protect_comments]" value="1" <?php checked(!empty($options['protect_comments'])); ?>> Blog Comments</label><br>
                            <label><input type="checkbox" name="xivig_captcha_options[protect_woocommerce]" value="1" <?php checked(!empty($options['protect_woocommerce'])); ?>> WooCommerce Checkout</label>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
}

XivigWpPlugin::instance();
