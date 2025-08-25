<?php
/**
 * Plugin Name: MQ Video Manager
 * Description: Shortcode to upload/record videos with subscription-limited quotas, 300MB size cap, and replace support.
 * Version: 1.0.0
 * Author: Madquick
 * Text domain: mqvm
 */

if (!defined("ABSPATH")) {
    exit();
}

define("MQVM_VERSION", "1.0.0");
define("MQVM_PATH", plugin_dir_path(__FILE__));
define("MQVM_URL", plugin_dir_url(__FILE__));

class MQ_Video_Manager
{
    const CPT = "mq_video";
    const REST_NS = "mq-video/v1";
    const OPTION_DEFAULTS = "mq_video_defaults";
    const MAX_FILE_BYTES = 314572800; // 300 * 1024 * 1024

    public function __construct()
    {
        add_action("init", [$this, "register_cpt"]);

        add_action("admin_menu", [$this, "register_settings_page"]);

        add_action("init", [$this, "register_shortcode"]);
        add_action("wp_enqueue_scripts", [$this, "enqueue_assets"]);
        add_action("rest_api_init", [$this, "register_routes"]);
        add_filter("upload_mimes", [$this, "allow_video_mimes"]);
    }

    public function register_cpt()
    {
        register_post_type(self::CPT, [
            "label" => "User Videos",
            "public" => false,
            "show_ui" => true,
            "capability_type" => "post",
            "supports" => ["title", "author", "custom-fields"],
            "map_meta_cap" => true,
            "show_in_menu" => true,
        ]);
    }

    public function register_shortcode()
    {
        add_shortcode("mq_video_manager", [$this, "render_shortcode"]);
    }

    public function render_shortcode($atts)
    {
        if (!is_user_logged_in()) {
            return "<p>Please log in to manage your video.</p>";
        }

        $nonce = wp_create_nonce("wp_rest");

        ob_start();
        ?>
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
            <button id="mqvm-stop" class="button" disabled>Stop</button>
          </div>

          <!-- NEW: confirm row shown after recording stops, while playing back in the same frame -->
          <div id="mqvm-confirm-row" class="mqvm-confirm" hidden>
            <button id="mqvm-use" class="button button-primary">Use this video</button>
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

    <?php return ob_get_clean();
    }

    public function enqueue_assets()
    {
        if (!is_user_logged_in()) {
            return;
        }
        if (!is_singular() && !is_page()) {
            return;
        } // cheap guard; script loads only where shortcode likely appears via dep caching

        $in_use = false;
        global $post;
        if (
            $post instanceof WP_Post &&
            has_shortcode($post->post_content, "mq_video_manager")
        ) {
            $in_use = true;
        }

        if (!$in_use) {
            return;
        }

        wp_register_style(
            "mqvm-css",
            plugins_url("public.css", __FILE__),
            [],
            "1.0.0",
        );
        wp_enqueue_style("mqvm-css");

        wp_register_script(
            "mqvm-js",
            plugins_url("public.js", __FILE__),
            [],
            "1.0.0",
            true,
        );
        wp_enqueue_script("mqvm-js");
    }

    public function register_routes()
    {
        register_rest_route(self::REST_NS, "/my", [
            "methods" => "GET",
            "callback" => [$this, "rest_list_my"],
            "permission_callback" => function () {
                return is_user_logged_in();
            },
        ]);

        register_rest_route(self::REST_NS, "/upload", [
            "methods" => "POST",
            "callback" => [$this, "rest_upload"],
            "permission_callback" => function () {
                return is_user_logged_in();
            },
            "args" => [
                "replace_id" => ["required" => false],
            ],
        ]);

        register_rest_route(self::REST_NS, "/delete/(?P<id>\d+)", [
            "methods" => "DELETE",
            "callback" => [$this, "rest_delete"],
            "permission_callback" => function () {
                return is_user_logged_in();
            },
        ]);
    }

    public function register_settings_page()
    {
        register_setting("mqvm", self::OPTION_DEFAULTS, [
            "type" => "array",
            "default" => ["max_videos" => 10, "max_total_mb" => 300],
        ]);

        add_settings_section(
            "mqvm_section",
            "MQ Video Defaults",
            function () {
                echo "<p>Default limits if your subscription plugin does not override via filter.</p>";
            },
            "mqvm",
        );

        add_settings_field(
            "mqvm_max_videos",
            "Max Videos",
            function () {
                $opt = get_option(self::OPTION_DEFAULTS);
                $val = isset($opt["max_videos"])
                    ? intval($opt["max_videos"])
                    : 1;
                echo '<input type="number" min="0" name="' .
                    self::OPTION_DEFAULTS .
                    '[max_videos]" value="' .
                    esc_attr($val) .
                    '" />';
            },
            "mqvm",
            "mqvm_section",
        );

        add_settings_field(
            "mqvm_max_total_mb",
            "Max Total (MB)",
            function () {
                $opt = get_option(self::OPTION_DEFAULTS);
                $val = isset($opt["max_total_mb"])
                    ? intval($opt["max_total_mb"])
                    : 300;
                echo '<input type="number" min="0" name="' .
                    self::OPTION_DEFAULTS .
                    '[max_total_mb]" value="' .
                    esc_attr($val) .
                    '" />';
            },
            "mqvm",
            "mqvm_section",
        );

        add_options_page(
            "MQ Video Manager",
            "MQ Video Manager",
            "manage_options",
            "mqvm",
            function () {
                echo '<div class="wrap"><h1>MQ Video Manager</h1><form method="post" action="options.php">';
                settings_fields("mqvm");
                do_settings_sections("mqvm");
                submit_button();
                echo "</form></div>";
            },
        );
    }

    public function allow_video_mimes($mimes)
    {
        $mimes["mp4"] = "video/mp4";
        $mimes["mov"] = "video/quicktime";
        $mimes["webm"] = "video/webm";
        $mimes["ogg"] = "video/ogg";
        return $mimes;
    }

    private function get_user_limits($user_id)
    {
        $defaults = get_option(self::OPTION_DEFAULTS, [
            "max_videos" => 1,
            "max_total_mb" => 300,
        ]);
        $limits = [
            "max_videos" => max(0, intval($defaults["max_videos"] ?? 1)),
            "max_total_mb" => max(0, intval($defaults["max_total_mb"] ?? 300)),
        ];
        // Allow your subscription/membership plugin to override limits per user.
        $limits = apply_filters("mq_video_limits", $limits, $user_id);
        return $limits;
    }

    private function get_user_usage($user_id)
    {
        $args = [
            "post_type" => self::CPT,
            "post_status" => "any",
            "author" => $user_id,
            "posts_per_page" => -1,
            "fields" => "ids",
        ];
        $ids = get_posts($args);
        $count = count($ids);
        $total_bytes = 0;
        foreach ($ids as $vid) {
            $bytes = intval(get_post_meta($vid, "_mqvm_filesize", true));
            $total_bytes += $bytes;
        }
        return [
            "count" => $count,
            "total_bytes" => $total_bytes,
            "ids" => $ids,
        ];
    }

    public function rest_list_my(WP_REST_Request $req)
    {
        $user_id = get_current_user_id();
        $args = [
            "post_type" => self::CPT,
            "post_status" => ["publish", "private"],
            "author" => $user_id,
            "posts_per_page" => -1,
        ];
        $q = new WP_Query($args);
        $items = [];
        while ($q->have_posts()) {
            $q->the_post();
            $pid = get_the_ID();
            $att_id = intval(get_post_meta($pid, "_mqvm_attachment_id", true));
            $url = $att_id ? wp_get_attachment_url($att_id) : "";
            $items[] = [
                "id" => $pid,
                "title" => get_the_title(),
                "url" => $url,
                "filesize" => intval(
                    get_post_meta($pid, "_mqvm_filesize", true),
                ),
                "duration" => floatval(
                    get_post_meta($pid, "_mqvm_duration", true),
                ),
                "attachment_id" => $att_id,
            ];
        }
        wp_reset_postdata();

        $limits = $this->get_user_limits($user_id);
        $usage = $this->get_user_usage($user_id);

        return new WP_REST_Response(
            [
                "items" => $items,
                "limits" => $limits,
                "usage" => [
                    "count" => $usage["count"],
                    "total_mb" => round(
                        $usage["total_bytes"] / (1024 * 1024),
                        2,
                    ),
                ],
                "max_file_mb" => round(self::MAX_FILE_BYTES / (1024 * 1024)),
            ],
            200,
        );
    }

    public function rest_upload(WP_REST_Request $req)
    {
        $user_id = get_current_user_id();
        if (!current_user_can("upload_files")) {
            return new WP_Error("forbidden", "Insufficient capability", [
                "status" => 403,
            ]);
        }

        if (!isset($_FILES["file"])) {
            return new WP_Error("no_file", "No file provided", [
                "status" => 400,
            ]);
        }

        $file = $_FILES["file"];
        $replace_id = absint($req->get_param("replace_id"));
        $duration = floatval($req->get_param("duration")); // seconds (from client when recording)

        if ($file["error"] !== UPLOAD_ERR_OK) {
            return new WP_Error("upload_error", "Upload failed", [
                "status" => 400,
            ]);
        }

        $size = intval($file["size"]);
        if ($size <= 0 || $size > self::MAX_FILE_BYTES) {
            return new WP_Error("file_too_large", "File exceeds 300MB", [
                "status" => 413,
            ]);
        }

        if ($duration > 60.5) {
            return new WP_Error(
                "duration_exceeded",
                "Recorded videos must be <= 60 seconds",
                ["status" => 400],
            );
        }

        $limits = $this->get_user_limits($user_id);
        $usage = $this->get_user_usage($user_id);

        $current_total_after = $usage["total_bytes"] + $size;
        $max_total_bytes =
            $limits["max_total_mb"] > 0
                ? $limits["max_total_mb"] * 1024 * 1024
                : PHP_INT_MAX;

        // If replacing an existing one, don't count "another slot"
        $count_after = $usage["count"] + ($replace_id ? 0 : 1);

        if ($limits["max_videos"] > 0 && $count_after > $limits["max_videos"]) {
            return new WP_Error(
                "quota_videos",
                "You have reached your plan’s video limit",
                ["status" => 403],
            );
        }

        if (
            $limits["max_total_mb"] > 0 &&
            $current_total_after > $max_total_bytes
        ) {
            return new WP_Error(
                "quota_storage",
                "Uploading this would exceed your plan’s total storage limit",
                ["status" => 403],
            );
        }

        require_once ABSPATH . "wp-admin/includes/file.php";
        require_once ABSPATH . "wp-admin/includes/media.php";
        require_once ABSPATH . "wp-admin/includes/image.php";

        $overrides = ["test_form" => false];
        $moved = wp_handle_upload($file, $overrides);
        if (isset($moved["error"])) {
            return new WP_Error("move_failed", $moved["error"], [
                "status" => 500,
            ]);
        }

        $attachment = [
            "guid" => $moved["url"],
            "post_mime_type" => $moved["type"],
            "post_title" => sanitize_file_name($file["name"]),
            "post_content" => "",
            "post_status" => "inherit",
        ];

        $att_id = wp_insert_attachment($attachment, $moved["file"]);
        if (!$att_id) {
            return new WP_Error(
                "attachment_failed",
                "Could not create attachment",
                ["status" => 500],
            );
        }

        $pid = $replace_id
            ? $replace_id
            : wp_insert_post([
                "post_type" => self::CPT,
                "post_status" => "private",
                "post_title" => "Video " . current_time("Y-m-d H:i:s"),
                "post_author" => $user_id,
            ]);

        if (!$pid) {
            return new WP_Error("post_failed", "Could not create video entry", [
                "status" => 500,
            ]);
        }

        if ($replace_id) {
            $owner = (int) get_post_field("post_author", $replace_id);
            if ($owner !== $user_id) {
                return new WP_Error(
                    "not_owner",
                    "Cannot replace a video you do not own",
                    ["status" => 403],
                );
            }

            $old_att = (int) get_post_meta(
                $replace_id,
                "_mqvm_attachment_id",
                true,
            );
            if ($old_att) {
                wp_delete_attachment($old_att, true);
            }
        }

        update_post_meta($pid, "_mqvm_attachment_id", $att_id);
        update_post_meta($pid, "_mqvm_filesize", $size);
        if ($duration > 0) {
            update_post_meta($pid, "_mqvm_duration", $duration);
        }

        return $this->rest_list_my($req);
    }

    public function rest_delete(WP_REST_Request $req)
    {
        $user_id = get_current_user_id();
        $id = absint($req->get_param("id"));
        if (!$id) {
            return new WP_Error("bad_id", "Invalid ID", ["status" => 400]);
        }

        $owner = (int) get_post_field("post_author", $id);
        if ($owner !== $user_id) {
            return new WP_Error(
                "not_owner",
                "Cannot delete a video you do not own",
                ["status" => 403],
            );
        }

        $att = (int) get_post_meta($id, "_mqvm_attachment_id", true);
        if ($att) {
            wp_delete_attachment($att, true);
        }
        wp_delete_post($id, true);

        return new WP_REST_Response(["ok" => true], 200);
    }
}

new MQ_Video_Manager();
