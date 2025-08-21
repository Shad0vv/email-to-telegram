<?php
/*
Plugin Name: WP Telegram Email
Description: Перехватывает отправку писем WordPress и отправляет их в Telegram
Version: 1.4
Author: Andrew Arutunyan & Grok
Text Domain: wp-telegram-email
Domain Path: /languages
*/

// Предотвращаем прямой доступ к файлу
if (!defined('ABSPATH')) {
    exit;
}

class WP_Telegram_Email {
    private $bot_token;
    private $chat_id;
    private $send_attachments;

    public function __construct() {
        // Загружаем переводы
        load_plugin_textdomain('wp-telegram-email', false, dirname(plugin_basename(__FILE__)) . '/languages/');

        // Инициализируем значения по умолчанию
        $this->bot_token = get_option('wp_telegram_email_bot_token', '');
        $this->chat_id = get_option('wp_telegram_email_chat_id', '');
        $this->send_attachments = get_option('wp_telegram_email_send_attachments', false);

        // Перехватываем отправку писем
        add_filter('wp_mail', [$this, 'send_to_telegram'], 10, 1);

        // Регистрируем настройки
        add_action('admin_init', [$this, 'register_settings']);

        // Добавляем уведомления об ошибках
        add_action('admin_notices', [$this, 'display_admin_notices']);
    }

    // Геттеры и сеттеры
    public function get_bot_token() {
        return $this->bot_token;
    }

    public function get_chat_id() {
        return $this->chat_id;
    }

    public function get_send_attachments() {
        return $this->send_attachments;
    }

    public function set_bot_token($token) {
        $this->bot_token = $token;
    }

    public function set_chat_id($chat) {
        $this->chat_id = $chat;
    }

    public function set_send_attachments($send) {
        $this->send_attachments = $send;
    }

    // Регистрация настроек
    public function register_settings() {
        register_setting('wp_telegram_email_settings', 'wp_telegram_email_bot_token', [
            'sanitize_callback' => [$this, 'sanitize_bot_token'],
        ]);
        register_setting('wp_telegram_email_settings', 'wp_telegram_email_chat_id', [
            'sanitize_callback' => [$this, 'sanitize_chat_id'],
        ]);
        register_setting('wp_telegram_email_settings', 'wp_telegram_email_send_attachments', [
            'sanitize_callback' => 'rest_sanitize_boolean',
        ]);
    }

    // Санитизация bot_token
    public function sanitize_bot_token($token) {
        $token = sanitize_text_field($token);
        if (!empty($token) && !preg_match('/^\d+:[\w-]+$/', $token)) {
            add_settings_error(
                'wp_telegram_email_settings',
                'invalid_bot_token',
                __('Неверный формат токена бота. Он должен содержать цифры, двоеточие и строку символов.', 'wp-telegram-email'),
                'error'
            );
            return '';
        }
        return $token;
    }

    // Санитизация chat_id
    public function sanitize_chat_id($chat_id) {
        $chat_id = sanitize_text_field($chat_id);
        if (!empty($chat_id) && !preg_match('/^@[\w_]+$|^-?\d+$/', $chat_id)) {
            add_settings_error(
                'wp_telegram_email_settings',
                'invalid_chat_id',
                __('Неверный формат ID чата. Используйте числовой ID или @username.', 'wp-telegram-email'),
                'error'
            );
            return '';
        }
        return $chat_id;
    }

    // Отображение уведомлений об ошибках
    public function display_admin_notices() {
        settings_errors('wp_telegram_email_settings');
    }

    public function send_to_telegram($args) {
        // Извлекаем данные письма
        $to = is_array($args['to']) ? implode(', ', $args['to']) : $args['to'];
        $subject = isset($args['subject']) ? $args['subject'] : '';
        $message = isset($args['message']) ? $args['message'] : '';
        $headers = isset($args['headers']) ? (is_array($args['headers']) ? implode("\n", $args['headers']) : $args['headers']) : '';
        $attachments = isset($args['attachments']) ? $args['attachments'] : [];

        // Формируем сообщение для Telegram
        $telegram_message = sprintf(
            __("📧 *Новое письмо из WordPress*\n\nКому: %s\nТема: %s\nСообщение:\n%s\n", 'wp-telegram-email'),
            esc_html($to),
            esc_html($subject),
            esc_html($message)
        );

        // Добавляем информацию о вложениях, если они есть
        if (!empty($attachments)) {
            $telegram_message .= __("\nВложения:\n", 'wp-telegram-email');
            foreach ($attachments as $attachment) {
                $telegram_message .= "- " . basename($attachment) . "\n";
            }
        }

        // Позволяем другим плагинам модифицировать сообщение
        $telegram_message = apply_filters('wp_telegram_email_message', $telegram_message, $args);

        // Отправляем сообщение и вложения
        $this->send_telegram_message($telegram_message, $this->send_attachments ? $attachments : []);

        // Возвращаем исходные аргументы
        return $args;
    }

    public function send_telegram_message($message, $attachments = []) {
        if (empty($this->bot_token) || empty($this->chat_id)) {
            add_settings_error(
                'wp_telegram_email_settings',
                'missing_config',
                __('Токен бота или ID чата не заданы.', 'wp-telegram-email'),
                'error'
            );
            return false;
        }

        // Разбиваем сообщение, если оно превышает 4096 символов
        $max_length = 4096;
        $messages = [];
        while (strlen($message) > $max_length) {
            $split_pos = strrpos(substr($message, 0, $max_length), "\n");
            $split_pos = $split_pos !== false ? $split_pos : $max_length;
            $messages[] = substr($message, 0, $split_pos);
            $message = substr($message, $split_pos);
        }
        $messages[] = $message;

        $success = true;

        // Отправляем каждую часть сообщения
        foreach ($messages as $part) {
            $url = "https://api.telegram.org/bot{$this->bot_token}/sendMessage";
            $data = [
                'chat_id' => $this->chat_id,
                'text' => $part,
                'parse_mode' => 'Markdown',
            ];

            $response = $this->make_telegram_request($url, $data);

            if (is_wp_error($response)) {
                add_settings_error(
                    'wp_telegram_email_settings',
                    'telegram_error',
                    sprintf(__('Ошибка отправки в Telegram: %s', 'wp-telegram-email'), $response->get_error_message()),
                    'error'
                );
                $success = false;
                continue;
            }

            $body = wp_remote_retrieve_body($response);
            $result = json_decode($body, true);

            if (!isset($result['ok']) || $result['ok'] !== true) {
                $error_message = isset($result['description']) ? $result['description'] : __('Неизвестная ошибка Telegram API', 'wp-telegram-email');
                add_settings_error(
                    'wp_telegram_email_settings',
                    'telegram_api_error',
                    sprintf(__('Ошибка Telegram API: %s', 'wp-telegram-email'), $error_message),
                    'error'
                );
                $success = false;
            }
        }

        // Отправляем вложения, если включена опция
        if ($success && $this->send_attachments && !empty($attachments) && function_exists('curl_init')) {
            foreach ($attachments as $attachment) {
                if (file_exists($attachment)) {
                    $url = "https://api.telegram.org/bot{$this->bot_token}/sendDocument";
                    $data = [
                        'chat_id' => $this->chat_id,
                        'document' => new CURLFile($attachment, mime_content_type($attachment), basename($attachment)),
                    ];

                    $response = $this->make_curl_request($url, $data);

                    if (is_wp_error($response)) {
                        add_settings_error(
                            'wp_telegram_email_settings',
                            'attachment_error',
                            sprintf(__('Ошибка отправки вложения %s: %s', 'wp-telegram-email'), basename($attachment), $response->get_error_message()),
                            'error'
                        );
                        $success = false;
                    }
                }
            }
        }

        return $success;
    }

    // Запрос к Telegram API с обработкой rate limit
    private function make_telegram_request($url, $data, $retries = 3, $delay = 1) {
        for ($i = 0; $i < $retries; $i++) {
            $response = wp_remote_post($url, [
                'body' => $data,
                'timeout' => 30,
            ]);

            if (is_wp_error($response)) {
                return $response;
            }

            $body = wp_remote_retrieve_body($response);
            $result = json_decode($body, true);

            if (isset($result['ok']) && $result['ok'] === true) {
                return $response;
            }

            if (isset($result['error_code']) && $result['error_code'] === 429) {
                sleep($delay);
                $delay *= 2; // Экспоненциальная задержка
                continue;
            }

            return new WP_Error('telegram_api_error', isset($result['description']) ? $result['description'] : __('Неизвестная ошибка Telegram API', 'wp-telegram-email'));
        }

        return new WP_Error('telegram_rate_limit', __('Превышен лимит запросов к Telegram API', 'wp-telegram-email'));
    }

    // cURL запрос для отправки вложений
    private function make_curl_request($url, $data) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $response = curl_exec($ch);
        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            return new WP_Error('curl_error', $error);
        }

        curl_close($ch);
        $result = json_decode($response, true);

        if (isset($result['ok']) && $result['ok'] === true) {
            return $response;
        }

        return new WP_Error('telegram_api_error', isset($result['description']) ? $result['description'] : __('Неизвестная ошибка Telegram API', 'wp-telegram-email'));
    }
}

// Инициализируем плагин
$wp_telegram_email = new WP_Telegram_Email();

// Добавляем страницу настроек
add_action('admin_menu', 'wp_telegram_email_add_settings_page');
function wp_telegram_email_add_settings_page() {
    add_options_page(
        __('WP Telegram Email Settings', 'wp-telegram-email'),
        __('WP Telegram Email', 'wp-telegram-email'),
        'manage_options',
        'wp-telegram-email',
        'wp_telegram_email_settings_page'
    );
}

// Страница настроек
function wp_telegram_email_settings_page() {
    if (!current_user_can('manage_options')) {
        return;
    }

    global $wp_telegram_email;

    // Сохранение настроек
    if (isset($_POST['wp_telegram_email_save'])) {
        check_admin_referer('wp_telegram_email_settings');
        update_option('wp_telegram_email_bot_token', sanitize_text_field($_POST['bot_token']));
        update_option('wp_telegram_email_chat_id', sanitize_text_field($_POST['chat_id']));
        update_option('wp_telegram_email_send_attachments', isset($_POST['send_attachments']));
        $wp_telegram_email->set_bot_token(sanitize_text_field($_POST['bot_token']));
        $wp_telegram_email->set_chat_id(sanitize_text_field($_POST['chat_id']));
        $wp_telegram_email->set_send_attachments(isset($_POST['send_attachments']));
    }

    // Обработка тестового сообщения
    if (isset($_POST['wp_telegram_email_test'])) {
        check_admin_referer('wp_telegram_email_test');
        $test_message = __("📧 *Тестовое сообщение*\n\nЭто тестовое сообщение от плагина WP Telegram Email.", 'wp-telegram-email');
        if ($wp_telegram_email->send_telegram_message($test_message)) {
            add_settings_error(
                'wp_telegram_email_settings',
                'test_success',
                __('Тестовое сообщение успешно отправлено в Telegram!', 'wp-telegram-email'),
                'success'
            );
        } else {
            add_settings_error(
                'wp_telegram_email_settings',
                'test_error',
                __('Ошибка отправки тестового сообщения. Проверьте токен бота и ID чата.', 'wp-telegram-email'),
                'error'
            );
        }
    }

    $bot_token = get_option('wp_telegram_email_bot_token', '');
    $chat_id = get_option('wp_telegram_email_chat_id', '');
    $send_attachments = get_option('wp_telegram_email_send_attachments', false);

    ?>
    <div class="wrap">
        <h1><?php _e('Настройки WP Telegram Email', 'wp-telegram-email'); ?></h1>
        <p><?php _e('Этот плагин перехватывает письма WordPress и отправляет их в Telegram. Для работы необходим токен бота и ID чата.', 'wp-telegram-email'); ?></p>
        <p><?php printf(
            __('Получите токен бота у <a href="%s" target="_blank">@BotFather</a> и ID чата у <a href="%s" target="_blank">@userinfobot</a> или аналогичного бота.', 'wp-telegram-email'),
            'https://t.me/BotFather',
            'https://t.me/userinfobot'
        ); ?></p>

        <form method="post" action="options.php">
            <?php
            settings_fields('wp_telegram_email_settings');
            do_settings_sections('wp_telegram_email_settings');
            ?>
            <table class="form-table">
                <tr>
                    <th><label for="bot_token"><?php _e('Токен бота', 'wp-telegram-email'); ?></label></th>
                    <td><input type="text" name="wp_telegram_email_bot_token" id="bot_token" value="<?php echo esc_attr($bot_token); ?>" class="regular-text"></td>
                </tr>
                <tr>
                    <th><label for="chat_id"><?php _e('ID чата', 'wp-telegram-email'); ?></label></th>
                    <td><input type="text" name="wp_telegram_email_chat_id" id="chat_id" value="<?php echo esc_attr($chat_id); ?>" class="regular-text"></td>
                </tr>
                <tr>
                    <th><label for="send_attachments"><?php _e('Отправлять вложения', 'wp-telegram-email'); ?></label></th>
                    <td><input type="checkbox" name="wp_telegram_email_send_attachments" id="send_attachments" value="1" <?php checked($send_attachments); ?>></td>
                </tr>
            </table>
            <?php submit_button(__('Сохранить изменения', 'wp-telegram-email')); ?>
        </form>

        <h2><?php _e('Проверка отправки', 'wp-telegram-email'); ?></h2>
        <form method="post">
            <?php wp_nonce_field('wp_telegram_email_test'); ?>
            <p><?php _e('Нажмите кнопку ниже, чтобы отправить тестовое сообщение в Telegram и проверить правильность настроек.', 'wp-telegram-email'); ?></p>
            <p class="submit">
                <input type="submit" name="wp_telegram_email_test" class="button-secondary" value="<?php _e('Проверить отправку', 'wp-telegram-email'); ?>">
            </p>
        </form>
    </div>
    <?php
}

// Обновляем настройки при загрузке плагина
add_action('plugins_loaded', function() use ($wp_telegram_email) {
    if ($bot_token = get_option('wp_telegram_email_bot_token')) {
        $wp_telegram_email->set_bot_token($bot_token);
    }
    if ($chat_id = get_option('wp_telegram_email_chat_id')) {
        $wp_telegram_email->set_chat_id($chat_id);
    }
    if ($send_attachments = get_option('wp_telegram_email_send_attachments')) {
        $wp_telegram_email->set_send_attachments($send_attachments);
    }
});
?>