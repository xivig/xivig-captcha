# Framework Integration: WordPress & WooCommerce

Xivig CAPTCHA includes a standalone, ready-to-install WordPress plugin located at `wordpress/xivig-captcha/xivig-captcha.php`. It protects core WordPress forms and WooCommerce checkout against automated bot attacks without degrading conversion rates.

---

## 1. Plugin Installation

### Option A: Direct Copy
Copy the `wordpress/xivig-captcha` directory into your WordPress installation:
```
wp-content/
└── plugins/
    └── xivig-captcha/
        └── xivig-captcha.php
```

### Option B: Zip Installation
1. Compress `wordpress/xivig-captcha` into a ZIP archive.
2. In your WordPress admin panel, go to **Plugins > Add New > Upload Plugin**.
3. Choose the ZIP file and click **Install Now**, then **Activate Plugin**.

---

## 2. Admin Settings & Configuration

Navigate to **Settings > Xivig Captcha** in the WordPress admin panel.

### Security Controls:
- **Secret Key**: Must be at least 32 characters long. If left blank, it automatically seeds from WordPress's private `AUTH_KEY`.
- **Proof of Work Difficulty**:
  - `2 - Ultra Light`: Minimal friction (~5ms solve time).
  - `3 - Standard (Recommended)`: Ideal balance of security and responsiveness (~20ms).
  - `4 - High Friction`: Recommended during targeted brute-force attacks (~80ms).
  - `5 - Maximum Defense`: High computational cost (~300ms).
- **Form Protection Checkboxes**:
  - [x] **WordPress Login (`wp-login.php`)**: Defends against credential stuffing and brute-force password cracking.
  - [x] **User Registration (`register_form`)**: Blocks automated fake user creation.
  - [x] **Blog Comments (`comment_form`)**: Eliminates comment spam bots.
  - [x] **WooCommerce Checkout (`woocommerce_checkout_process`)**: Prevents automated card testing fraud and bot checkout hoarding.

### Built-In Hardening:
- All settings saves verify admin capabilities (`current_user_can('manage_options')`) and CSRF nonces (`check_admin_referer`).
- Reverse proxy headers (`CF-Connecting-IP`, `X-Forwarded-For`) are parsed safely.
- Temporary storage uses a locked directory inside `wp-content/uploads/xivig-nonces/` with automated garbage collection.

---

## 3. Custom Theme / Hook Usage

If you prefer to integrate Xivig Captcha into custom themes or third-party forms (e.g., Contact Form 7, Elementor, WPForms):

### Step 1: Render the Widget
```php
<?php if (function_exists('xivig_captcha_widget')): ?>
    <?php xivig_captcha_widget(); ?>
<?php endif; ?>
```

### Step 2: Verify in Custom Form Actions
```php
add_action('my_custom_form_submit', function() {
    $payload = sanitize_text_field(wp_unslash($_POST['aegis_payload'] ?? ''));
    $ip = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'));
    $ua = sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'));

    $aegis = \Aegis\Captcha\Aegis::getInstance();
    $result = $aegis?->verify($payload, $ip, $ua);

    if (!$result || !$result->isHuman) {
        wp_die(__('Security verification failed. Automated activity detected.', 'xivig-captcha'), 403);
    }
});
```
