<?php
/**
 * Plugin Name: Sync Post Translation Add-On
 * Description: Add-on for "Sync Post with Other Site" plugin. Automatically translates posts from English to Italian, German, and Spanish using OpenAI API and syncs the translation to the target site.
 * Version: 1.0
 * Author: Devangi Savaliya
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class SyncPostTranslationAddon {
    private $api_key;
    private $target_domain;

    public function __construct() {
        add_action('plugins_loaded', [$this, 'check_required_plugins']);
    }

    /**
     * Check if required plugin is active.
     */
    public function check_required_plugins() {
        include_once(ABSPATH . 'wp-admin/includes/plugin.php'); // Ensure is_plugin_active is available

        // Check if the required plugin is active
        if (!is_plugin_active('sync-post-with-other-site/SyncPostWithOtherSite.php')) {
            add_action('admin_notices', [$this, 'admin_notice']);
            deactivate_plugins(plugin_basename(__FILE__)); // Deactivate this plugin
            return; // Exit if the required plugin is not active
        }

        $this->api_key = 'Your-API-Key'; // Replace with your actual API key
        $this->target_domain = 'Target Domain URL'; // Replace with the target domain

        add_action('admin_menu', [$this, 'add_translation_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('publish_post', [$this, 'handle_post_publish'], 10, 2);
    }

    /**
     * Display an admin notice if the required plugin is not active.
     */
    public function admin_notice() {
        ?>
        <div class="notice notice-error">
            <p><?php _e('Sync Post Translation Add-On requires the "Sync Post with Other Site" plugin to be active.', 'text-domain'); ?></p>
        </div>
        <?php
    }

    /**
     * Adds a menu item to the main admin menu for translation settings.
     */
    public function add_translation_menu() {
        add_menu_page(
            'Translation Settings',
            'Translation Settings',
            'manage_options',
            'sp-translation-settings',
            [$this, 'settings_page'],
            'dashicons-translation', // Icon for the menu
            20 // Position in the admin menu
        );
    }

    /**
     * Registers settings for language selection.
     */
    public function register_settings() {
        register_setting('sp_translation_options_group', 'sp_selected_language');
    }

    /**
     * Settings page for language selection using a dropdown.
     */
    public function settings_page() {
        ?>
        <div class="wrap">
            <h1>Translation Settings</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('sp_translation_options_group');
                ?>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row">Select Language:</th>
                        <td>
                            <select name="sp_selected_language">
                                <option value="it" <?php selected(get_option('sp_selected_language'), 'it'); ?>>Italian</option>
                                <option value="es" <?php selected(get_option('sp_selected_language'), 'es'); ?>>Spanish</option>
                                <option value="de" <?php selected(get_option('sp_selected_language'), 'de'); ?>>German</option>
                            </select>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Handles the post-publishing process to capture and send HTML content for translation.
     */
    public function handle_post_publish($post_id, $post) {
        // Check if the post type is 'post'
        if (get_post_type($post_id) != 'post') {
            return;
        }

        // Get the selected language from settings
        $selected_language = get_option('sp_selected_language', '');

        // Exit if no language is selected
        if (empty($selected_language)) {
            return;
        }

        // Translate the HTML content into the selected language
        $translated_content = $this->translate_full_html($post->post_content, $selected_language);
        if ($translated_content) {
            $this->send_translated_post_to_target($post, $translated_content, $selected_language);
        }
    }

    /**
     * Splits the HTML content into manageable chunks and sends each chunk to OpenAI for translation.
     */
    private function translate_full_html($html_content, $language) {
        // Split the HTML content into smaller parts
        $chunks = $this->split_html_content($html_content);
        $translated_content = '';

        // Translate each chunk
        foreach ($chunks as $chunk) {
            $translated_chunk = $this->translate_content($chunk, $language);
            if ($translated_chunk === 'Translation error') {
                return null; // Exit if translation fails
            }
            $translated_content .= $translated_chunk;
        }

        return $translated_content;
    }

    /**
     * Splits content by paragraphs, sentences, or character count.
     */
    private function split_html_content($html_content) {
        $max_chunk_size = 800; // Adjust to avoid hitting the token limit
        $chunks = [];
        $current_chunk = '';

        foreach (preg_split('/(?<=[.!?])\s+/', $html_content) as $sentence) {
            if (strlen($current_chunk) + strlen($sentence) > $max_chunk_size) {
                $chunks[] = $current_chunk;
                $current_chunk = '';
            }
            $current_chunk .= $sentence . ' ';
        }

        if (!empty($current_chunk)) {
            $chunks[] = $current_chunk;
        }

        return $chunks;
    }

    /**
     * Sends a chunk of HTML to OpenAI for translation.
     */
    private function translate_content($html_chunk, $target_language) {
        $url = 'https://api.openai.com/v1/chat/completions';

        $headers = [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->api_key,
        ];

        $data = [
            'model' => 'gpt-4', // Replace with the required model
            'messages' => [
                [
                    'role' => 'user',
                    'content' => 'Translate this HTML content to ' . $target_language . ': ' . $html_chunk,
                ],
            ],
            'temperature' => 0.7,
        ];

        $response = wp_remote_post($url, [
            'timeout' => 40, 
            'headers' => $headers,
            'body'    => json_encode($data),
        ]);

        if (is_wp_error($response)) {
            error_log('API Request Error: ' . $response->get_error_message());
            return 'Translation error';
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($body['choices'][0]['message']['content'])) {
            return $body['choices'][0]['message']['content'];
        } else {
            error_log('API Response: ' . print_r($body, true));
            return 'Translation error';
        }
    }

    /**
     * Sends the translated post to the target domain.
     */
    private function send_translated_post_to_target($original_post, $translated_content, $language) {
        $url = $this->target_domain . '/wp-json/wp/v2/posts'; // REST API endpoint of target site

        // Replace with the actual application password and username
        $application_password = 'Target domain Application password'; 
        $username = 'Target Domain Username'; // Username of the user who generated the application password

        $auth = base64_encode($username . ':' . $application_password);

        $headers = [
            'Content-Type' => 'application/json',
            'Authorization' => 'Basic ' . $auth,
        ];

        $data = [
            'title'   => $original_post->post_title . ' (' . strtoupper($language) . ')',
            'content' => $translated_content,
            'status'  => 'publish',
        ];

        $response = wp_remote_post($url, [
            'headers' => $headers,
            'body'    => json_encode($data),
            'sslverify' => false, 
        ]);

        if (is_wp_error($response)) {
            error_log('Error sending post to target site: ' . $response->get_error_message());
            return;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        if ($status_code != 201) {
            error_log('API Request Failed. Status Code: ' . $status_code . ', Response: ' . $body);
            return;
        }
        
        error_log('Post created successfully on the target domain: ' . $body);
    }
}

// Initialize the plugin
new SyncPostTranslationAddon();
