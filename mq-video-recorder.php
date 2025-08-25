<?php
/**
 * Plugin Name: MQ Video Manager
 * Description: Shortcode to upload/record videos with per-membership (PMPro) limits, file-size cap, and replace support.
 * Version: 1.0.0
 * Author: Madquick
 * Text Domain: mqvm
 */

if (!defined('ABSPATH')) exit;

define('MQVM_VERSION', '1.0.0');
define('MQVM_PATH', plugin_dir_path(__FILE__));
define('MQVM_URL',  plugin_dir_url(__FILE__));

class MQ_Video_Manager {
    const CPT             = 'mq_video';
    const REST_NS         = 'mq-video/v1';
    const OPTION_DEFAULTS = 'mq_video_defaults';
    const MAX_FILE_BYTES  = 314572800; // hard safety net: 300 * 1024 * 1024

    public function __construct() {
        add_action('init',                  [$this, 'register_cpt']);
        add_action('admin_menu',            [$this, 'register_settings_page']);
        add_action('admin_init',            [$this, 'register_settings_fields']);
        add_action('init',                  [$this, 'register_shortcode']);
        add_action('wp_enqueue_scripts',    [$this, 'enqueue_assets']);
        add_action('rest_api_init',         [$this, 'register_routes']);
        add_filter('upload_mimes',          [$this, 'allow_video_mimes']);
    }

    /** Resolve effective limits (PMPro level-based with fallback). */
    private function get_effective_limits_for_user($user_id) {
        $opt = get_option(self::OPTION_DEFAULTS, []);
        $def = $opt['default'] ?? ['max_videos'=>10,'file_size_mb'=>300,'record_limit_secs'=>60,'max_total_mb'=>0];

        $levelRow = [];
        if (function_exists('pmpro_getMembershipLevelForUser')) {
            $level = pmpro_getMembershipLevelForUser($user_id);
            if ($level && !empty($opt['levels'][$level->id])) {
                $levelRow = $opt['levels'][$level->id]; // may be partial (inherit per-field)
            }
        }

        $max_videos        = array_key_exists('max_videos', $levelRow)        ? intval($levelRow['max_videos'])        : intval($def['max_videos']);
        $file_size_mb      = array_key_exists('file_size_mb', $levelRow)      ? intval($levelRow['file_size_mb'])      : intval($def['file_size_mb']);
        $record_limit_secs = array_key_exists('record_limit_secs', $levelRow) ? intval($levelRow['record_limit_secs']) : intval($def['record_limit_secs']);
        $max_total_mb      = array_key_exists('max_total_mb', $levelRow)      ? intval($levelRow['max_total_mb'])      : intval($def['max_total_mb'] ?? 0);

        return [
            'max_videos'        => max(0, $max_videos),
            'file_size_mb'      => max(1, $file_size_mb),
            'record_limit_secs' => max(1, $record_limit_secs),
            'max_total_mb'      => max(0, $max_total_mb), // 0 == unlimited total storage
        ];
    }

    public function register_cpt() {
        register_post_type(self::CPT, [
            'label'         => 'User Videos',
            'public'        => false,
            'show_ui'       => true,
            'capability_type'=> 'post',
            'supports'      => ['title', 'author', 'custom-fields'],
            'map_meta_cap'  => true,
            'show_in_menu'  => true,
        ]);
    }

    public function register_shortcode() {
        add_shortcode('mq_video_manager', [$this, 'render_shortcode']);
    }

    public function render_shortcode($atts) {
        if (!is_user_logged_in()) {
            return '<p>Please log in to manage your video.</p>';
        }
        $nonce = wp_create_nonce('wp_rest');

        ob_start(); ?>
        <div id="mq-video-root"
             data-rest="<?php echo esc_attr(rest_url(self::REST_NS)); ?>"
             data-nonce="<?php echo esc_attr($nonce); ?>">

          <div class="mqvm-card">
            <h3 class="mqvm-title">My Video</h3>

            <div class="mqvm-preview-wrap">
              <div class="mqvm-video-shell">
                <video id="mqvm-live" class="mqvm-live" autoplay muted playsinline></video>

                <!-- overlay with timer + dot + grid -->
                <div class="mqvm-overlay" id="mqvm-overlay" hidden>
                  <div class="mqvm-topbar">
                    <span class="mqvm-dot"></span>
                    <span id="mqvm-timer">00:00</span>
                    <span class="mqvm-spacer"></span>
                    <span id="mqvm-size">0.00 MB</span>
                  </div>
                  <div class="mqvm-grid"></div>
                </div>
              </div>

              <div class="mqvm-controls">
                <button id="mqvm-record" class="button button-primary">Record (max 60s)</button>
                <button id="mqvm-stop"   class="button" disabled>Stop</button>
              </div>

              <p class="mqvm-hint" id="mqvm-live-hint">
                Align yourself in the frame. Preview is muted; audio still records.
              </p>

              <!-- Confirm row shows after recording stops (same-frame playback) -->
              <div id="mqvm-confirm-row" class="mqvm-confirm" hidden>
                <button id="mqvm-use"   class="button button-primary">Use this video</button>
                <button id="mqvm-retake" class="button">Retake</button>
                <span id="mqvm-meta" class="mqvm-meta"></span>
              </div>
            </div>

            <hr class="mqvm-sep" />

            <div class="mqvm-upload-row">
              <input id="mqvm-file" type="file" accept="video/*" />
              <button id="mqvm-upload" class="button">Upload file</button>
            </div>

            <div class="mqvm-progress" id="mqvm-progress" hidden>
              <div id="mqvm-bar"></div>
            </div>

            <div id="mqvm-list"></div>
          </div>
        </div>
        <?php
        return ob_get_clean();
    }

    public function enqueue_assets() {
        if (!is_user_logged_in()) return;
        if (!is_singular() && !is_page()) return;

        $in_use = false;
        global $post;
        if ($post instanceof WP_Post && has_shortcode($post->post_content, 'mq_video_manager')) {
            $in_use = true;
        }
        if (!$in_use) return;

        wp_register_style('mqvm-css', plugins_url('public.css', __FILE__), [], MQVM_VERSION);
        wp_enqueue_style('mqvm-css');

        wp_register_script('mqvm-js', plugins_url('public.js', __FILE__), [], MQVM_VERSION, true);
        wp_enqueue_script('mqvm-js');
    }

    public function register_routes() {
        register_rest_route(self::REST_NS, '/my', [
            'methods'             => 'GET',
            'callback'            => [$this, 'rest_list_my'],
            'permission_callback' => function () { return is_user_logged_in(); },
        ]);

        register_rest_route(self::REST_NS, '/upload', [
            'methods'             => 'POST',
            'callback'            => [$this, 'rest_upload'],
            'permission_callback' => function () { return is_user_logged_in(); },
            'args'                => ['replace_id' => ['required' => false]],
        ]);

        register_rest_route(self::REST_NS, '/delete/(?P<id>\d+)', [
            'methods'             => 'DELETE',
            'callback'            => [$this, 'rest_delete'],
            'permission_callback' => function () { return is_user_logged_in(); },
        ]);
    }

    public function register_settings_page() {
        add_options_page(
            'MQ Video Manager',
            'MQ Video Manager',
            'manage_options',
            'mqvm',
            function () {
                echo '<div class="wrap"><h1>MQ Video Manager</h1><form method="post" action="options.php">';
                settings_fields('mqvm');
                do_settings_sections('mqvm');
                submit_button();
                echo '</form></div>';
            }
        );
    }

    public function register_settings_fields() {
        // One option holds defaults + per-level overrides
        register_setting('mqvm', self::OPTION_DEFAULTS, [
            'type'              => 'array',
            'default'           => [
                'default' => [
                    'max_videos'        => 10,
                    'file_size_mb'      => 300,
                    'record_limit_secs' => 60,
                    'max_total_mb'      => 0,   // 0 = unlimited
                ],
                'levels'  => [], // levelId => [max_videos,file_size_mb,record_limit_secs,max_total_mb?]
            ],
            'sanitize_callback' => function($input){
                $out = ['default' => [], 'levels' => []];

                $def = $input['default'] ?? [];
                $out['default'] = [
                    'max_videos'        => max(0, intval($def['max_videos'] ?? 10)),
                    'file_size_mb'      => max(1, intval($def['file_size_mb'] ?? 300)),
                    'record_limit_secs' => max(1, intval($def['record_limit_secs'] ?? 60)),
                    'max_total_mb'      => max(0, intval($def['max_total_mb'] ?? 0)),
                ];

                if (!empty($input['levels']) && is_array($input['levels'])) {
                    foreach ($input['levels'] as $levelId => $row) {
                        $lid = intval($levelId);
                        if ($lid <= 0) continue;

                        $rowOut = [];
                        if (isset($row['max_videos']) && $row['max_videos'] !== '')         $rowOut['max_videos']        = max(0, intval($row['max_videos']));
                        if (isset($row['file_size_mb']) && $row['file_size_mb'] !== '')     $rowOut['file_size_mb']      = max(1, intval($row['file_size_mb']));
                        if (isset($row['record_limit_secs']) && $row['record_limit_secs'] !== '') $rowOut['record_limit_secs'] = max(1, intval($row['record_limit_secs']));
                        if (isset($row['max_total_mb']) && $row['max_total_mb'] !== '')     $rowOut['max_total_mb']      = max(0, intval($row['max_total_mb']));

                        if ($rowOut) $out['levels'][$lid] = $rowOut;
                    }
                }
                return $out;
            }
        ]);

        add_settings_section('mqvm_section', 'Per-Level Limits (Paid Memberships Pro)', function () {
            if (!function_exists('pmpro_getAllLevels')) {
                echo '<p><em>Paid Memberships Pro is not active. Install/activate PMPro to configure per-level limits.</em></p>';
                return;
            }

            $opt    = get_option(self::OPTION_DEFAULTS, []);
            $def    = $opt['default'] ?? ['max_videos'=>10,'file_size_mb'=>300,'record_limit_secs'=>60,'max_total_mb'=>0];
            $levels = pmpro_getAllLevels(true, true); // include hidden/inactive

            echo '<h3>Fallback (applies when user has no level, or a field is blank for their level)</h3>';
            echo '<table class="widefat fixed striped"><thead><tr>
                    <th style="width:40%">Scope</th>
                    <th style="width:15%">Max Videos</th>
                    <th style="width:15%">File Size (MB)</th>
                    <th style="width:15%">Record Limit (s)</th>
                    <th style="width:15%">Total Storage (MB)</th>
                  </tr></thead><tbody>';
            echo '<tr>
                    <td><strong>Default / No Level</strong></td>
                    <td><input type="number" min="0" name="'.self::OPTION_DEFAULTS.'[default][max_videos]" value="'.esc_attr(intval($def['max_videos'])).'"></td>
                    <td><input type="number" min="1" name="'.self::OPTION_DEFAULTS.'[default][file_size_mb]" value="'.esc_attr(intval($def['file_size_mb'])).'"></td>
                    <td><input type="number" min="1" name="'.self::OPTION_DEFAULTS.'[default][record_limit_secs]" value="'.esc_attr(intval($def['record_limit_secs'])).'"></td>
                    <td><input type="number" min="0" name="'.self::OPTION_DEFAULTS.'[default][max_total_mb]" value="'.esc_attr(intval($def['max_total_mb'])).'"></td>
                  </tr>';
            echo '</tbody></table>';

            if (empty($levels)) {
                echo '<p>No PMPro levels found.</p>';
                return;
            }

            $rows = $opt['levels'] ?? [];
            echo '<h3>Membership Levels</h3>';
            echo '<table class="widefat fixed striped"><thead><tr>
                    <th style="width:40%">Level (label)</th>
                    <th style="width:15%">Max Videos</th>
                    <th style="width:15%">File Size (MB)</th>
                    <th style="width:15%">Record Limit (s)</th>
                    <th style="width:15%">Total Storage (MB)</th>
                  </tr></thead><tbody>';

            foreach ($levels as $lvl) {
                $lid   = intval($lvl->id);
                $lname = esc_html($lvl->name);
                $row   = $rows[$lid] ?? [];
                $mv    = array_key_exists('max_videos', $row)        ? intval($row['max_videos'])        : '';
                $mb    = array_key_exists('file_size_mb', $row)      ? intval($row['file_size_mb'])      : '';
                $secs  = array_key_exists('record_limit_secs', $row) ? intval($row['record_limit_secs']) : '';
                $tot   = array_key_exists('max_total_mb', $row)      ? intval($row['max_total_mb'])      : '';

                echo '<tr>
                        <td><strong>'.$lname.'</strong> <span style="color:#666">(#'.$lid.')</span></td>
                        <td><input type="number" min="0" name="'.self::OPTION_DEFAULTS.'[levels]['.$lid.'][max_videos]" value="'.esc_attr($mv).'"></td>
                        <td><input type="number" min="1" name="'.self::OPTION_DEFAULTS.'[levels]['.$lid.'][file_size_mb]" value="'.esc_attr($mb).'"></td>
                        <td><input type="number" min="1" name="'.self::OPTION_DEFAULTS.'[levels]['.$lid.'][record_limit_secs]" value="'.esc_attr($secs).'"></td>
                        <td><input type="number" min="0" name="'.self::OPTION_DEFAULTS.'[levels]['.$lid.'][max_total_mb]" value="'.esc_attr($tot).'"></td>
                      </tr>';
            }
            echo '</tbody></table>';
        }, 'mqvm');
    }

    public function allow_video_mimes($mimes) {
        $mimes['mp4']  = 'video/mp4';
        $mimes['mov']  = 'video/quicktime';
        $mimes['webm'] = 'video/webm';
        $mimes['ogg']  = 'video/ogg';
        return $mimes;
    }

    private function get_user_usage($user_id) {
        $ids = get_posts([
            'post_type'      => self::CPT,
            'post_status'    => 'any',
            'author'         => $user_id,
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ]);
        $total_bytes = 0;
        foreach ($ids as $vid) {
            $total_bytes += intval(get_post_meta($vid, '_mqvm_filesize', true));
        }
        return [
            'count'       => count($ids),
            'total_bytes' => $total_bytes,
            'ids'         => $ids,
        ];
    }

    public function rest_list_my(WP_REST_Request $req) {
        $user_id = get_current_user_id();

        $q = new WP_Query([
            'post_type'      => self::CPT,
            'post_status'    => ['publish','private'],
            'author'         => $user_id,
            'posts_per_page' => -1,
        ]);

        $items = [];
        while ($q->have_posts()) {
            $q->the_post();
            $pid    = get_the_ID();
            $att_id = intval(get_post_meta($pid, '_mqvm_attachment_id', true));
            $url    = $att_id ? wp_get_attachment_url($att_id) : '';
            $items[] = [
                'id'            => $pid,
                'title'         => get_the_title(),
                'url'           => $url,
                'filesize'      => intval(get_post_meta($pid, '_mqvm_filesize', true)),
                'duration'      => floatval(get_post_meta($pid, '_mqvm_duration', true)),
                'attachment_id' => $att_id,
            ];
        }
        wp_reset_postdata();

        $usage  = $this->get_user_usage($user_id);
        $limits = $this->get_effective_limits_for_user($user_id);

        return new WP_REST_Response([
            'items' => $items,
            'limits'=> $limits,
            'usage' => [
                'count'    => $usage['count'],
                'total_mb' => round($usage['total_bytes'] / (1024*1024), 2),
            ],
        ], 200);
    }

    public function rest_upload(WP_REST_Request $req) {
        $user_id = get_current_user_id();

        if (!current_user_can('upload_files')) {
            return new WP_Error('forbidden', 'Insufficient capability', ['status' => 403]);
        }
        if (!isset($_FILES['file'])) {
            return new WP_Error('no_file', 'No file provided', ['status' => 400]);
        }

        $file       = $_FILES['file'];
        $replace_id = absint($req->get_param('replace_id'));
        $duration   = (float) $req->get_param('duration'); // optional (recorder flow)

        if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
            return new WP_Error('upload_error', 'Upload failed', ['status' => 400]);
        }

        $limits = $this->get_effective_limits_for_user($user_id);
        $max_file_bytes = min(self::MAX_FILE_BYTES, max(1, (int)$limits['file_size_mb']) * 1024 * 1024);
        $max_secs       = max(1, (int)$limits['record_limit_secs']);

        $size = (int) $file['size'];
        if ($size <= 0 || $size > $max_file_bytes) {
            return new WP_Error('file_too_large', 'File exceeds your plan’s file size limit (max ' . round($max_file_bytes / (1024*1024)) . 'MB).', ['status' => 413]);
        }
        if ($duration > ($max_secs + 0.5)) {
            return new WP_Error('duration_exceeded', 'Recorded videos must be ≤ ' . $max_secs . ' seconds.', ['status' => 400]);
        }

        // Quotas
        $usage       = $this->get_user_usage($user_id);
        $count_after = $usage['count'] + ($replace_id ? 0 : 1);
        if ($limits['max_videos'] > 0 && $count_after > $limits['max_videos']) {
            return new WP_Error('quota_videos', 'You have reached your plan’s video limit.', ['status' => 403]);
        }
        $max_total_bytes = ($limits['max_total_mb'] > 0) ? $limits['max_total_mb'] * 1024 * 1024 : PHP_INT_MAX;
        $current_total_after = $usage['total_bytes'] + $size;
        if ($limits['max_total_mb'] > 0 && $current_total_after > $max_total_bytes) {
            return new WP_Error('quota_storage', 'Uploading this would exceed your plan’s total storage limit.', ['status' => 403]);
        }

        // Move file
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $moved = wp_handle_upload($file, ['test_form' => false, 'mimes' => null]);
        if (isset($moved['error'])) {
            return new WP_Error('move_failed', $moved['error'], ['status' => 500]);
        }

        $mime = isset($moved['type']) ? (string) $moved['type'] : '';
        if (stripos($mime, 'video/') !== 0) {
            if (!empty($moved['file']) && file_exists($moved['file'])) @unlink($moved['file']);
            return new WP_Error('bad_mime', 'Only video files are allowed.', ['status' => 415]);
        }

        $attachment = [
            'guid'           => $moved['url'],
            'post_mime_type' => $mime,
            'post_title'     => sanitize_file_name($file['name']),
            'post_content'   => '',
            'post_status'    => 'inherit',
        ];
        $att_id = wp_insert_attachment($attachment, $moved['file']);
        if (!$att_id) {
            if (!empty($moved['file']) && file_exists($moved['file'])) @unlink($moved['file']);
            return new WP_Error('attachment_failed', 'Could not create attachment.', ['status' => 500]);
        }

        // Create/Reuse CPT
        $pid = $replace_id ? $replace_id : wp_insert_post([
            'post_type'   => self::CPT,
            'post_status' => 'private',
            'post_title'  => 'Video ' . current_time('Y-m-d H:i:s'),
            'post_author' => $user_id,
        ]);
        if (!$pid) {
            wp_delete_attachment($att_id, true);
            return new WP_Error('post_failed', 'Could not create video entry.', ['status' => 500]);
        }

        if ($replace_id) {
            $owner = (int) get_post_field('post_author', $replace_id);
            if ($owner !== $user_id) {
                wp_delete_attachment($att_id, true);
                return new WP_Error('not_owner', 'Cannot replace a video you do not own.', ['status' => 403]);
            }
            $old_att = (int) get_post_meta($replace_id, '_mqvm_attachment_id', true);
            if ($old_att) wp_delete_attachment($old_att, true);
        }

        update_post_meta($pid, '_mqvm_attachment_id', $att_id);
        update_post_meta($pid, '_mqvm_filesize', $size);
        if ($duration > 0) update_post_meta($pid, '_mqvm_duration', $duration);
        update_post_meta($pid, '_mqvm_original_filename', sanitize_file_name($file['name']));
        update_post_meta($pid, '_mqvm_uploaded_by_ip', isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field($_SERVER['REMOTE_ADDR']) : '');

        return $this->rest_list_my($req);
    }

    public function rest_delete(WP_REST_Request $req) {
        $user_id = get_current_user_id();
        $id = absint($req->get_param('id'));
        if (!$id) {
            return new WP_Error('bad_id', 'Invalid ID', ['status' => 400]);
        }

        $owner = (int) get_post_field('post_author', $id);
        if ($owner !== $user_id) {
            return new WP_Error('not_owner', 'Cannot delete a video you do not own', ['status' => 403]);
        }

        $att = (int) get_post_meta($id, '_mqvm_attachment_id', true);
        if ($att) wp_delete_attachment($att, true);
        wp_delete_post($id, true);

        return new WP_REST_Response(['ok' => true], 200);
    }
}

new MQ_Video_Manager();
