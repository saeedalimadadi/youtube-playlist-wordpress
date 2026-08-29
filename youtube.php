
// === دریافت لیست ویدیوهای یوتیوب با کش یک‌روزه (نسخه بهینه‌شده) ===
function get_youtube_videos_paginated($page = 1, $per_page = 10) {
    $cache_key = 'youtube_channel_videos_cache';
    $cached_data = get_transient($cache_key);

    if ($cached_data !== false) {
        $videos = $cached_data;
    } else {
        // کلید API و شناسه کانال خود را اینجا قرار دهید
        $api_key = ''; // کلید API شما
        $channel_id = ''; // شناسه کانال شما

        // 1. دریافت شناسه پلی‌لیست آپلودها
        $playlist_id_url = "https://www.googleapis.com/youtube/v3/channels?part=contentDetails&id={$channel_id}&key={$api_key}";
        $playlist_response = wp_remote_get($playlist_id_url);
        if (is_wp_error($playlist_response)) return [];
        $playlist_data = json_decode(wp_remote_retrieve_body($playlist_response), true);
        $playlist_id = $playlist_data['items'][0]['contentDetails']['relatedPlaylists']['uploads'] ?? null;
        if (!$playlist_id) return [];

        // 2. دریافت لیست آیتم‌های پلی‌لیست (فقط شناسه‌ها)
        $playlist_items_url = "https://www.googleapis.com/youtube/v3/playlistItems?part=contentDetails&playlistId={$playlist_id}&key={$api_key}&maxResults=50";
        $items_response = wp_remote_get($playlist_items_url);
        if (is_wp_error($items_response)) return [];
        $items_data = json_decode(wp_remote_retrieve_body($items_response), true);
        if (!isset($items_data['items'])) return [];

        $video_ids = [];
        foreach ($items_data['items'] as $item) {
            $video_ids[] = $item['contentDetails']['videoId'];
        }

        if (empty($video_ids)) {
             set_transient($cache_key, [], DAY_IN_SECONDS); // کش کردن نتیجه خالی برای جلوگیری از درخواست‌های مکرر
             return [];
        }

        // 3. دریافت جزئیات همه ویدیوها (شامل عنوان، زمان و تاریخ) با یک درخواست
        $video_ids_string = implode(',', $video_ids);
        $videos_url = "https://www.googleapis.com/youtube/v3/videos?part=snippet,contentDetails&id={$video_ids_string}&key={$api_key}";
        $videos_response = wp_remote_get($videos_url);
        if (is_wp_error($videos_response)) return [];
        
        $videos_data = json_decode(wp_remote_retrieve_body($videos_response), true);
        if (!isset($videos_data['items'])) return [];

        $videos = [];
        foreach ($videos_data['items'] as $item) {
            $duration_str = $item['contentDetails']['duration'];
            $duration_seconds = convertISO8601ToSeconds($duration_str);

            // فیلتر کردن ویدیوهای بالای ۶۰ ثانیه
            if ($duration_seconds >= 60) {
                $videos[] = [
                    'title'       => $item['snippet']['title'],
                    'video_id'    => $item['id'],
                    'published_at'=> $item['snippet']['publishedAt']
                ];
            }
        }

        // مرتب‌سازی بر اساس تاریخ انتشار
        usort($videos, function($a, $b) {
            return strtotime($b['published_at']) - strtotime($a['published_at']);
        });

        // ذخیره در کش برای ۲۴ ساعت
        set_transient($cache_key, $videos, DAY_IN_SECONDS);
    }

    $total_videos = count($videos);
    $offset = ($page - 1) * $per_page;
    $paged_videos = array_slice($videos, $offset, $per_page);

    return [
        'videos'       => $paged_videos,
        'total'        => $total_videos,
        'per_page'     => $per_page,
        'current_page' => $page,
        'total_pages'  => ceil($total_videos / $per_page)
    ];
}

// === تبدیل فرمت زمان ISO 8601 به ثانیه ===
function convertISO8601ToSeconds($duration) {
    try {
        $interval = new DateInterval($duration);
        return ($interval->h * 3600) + ($interval->i * 60) + $interval->s;
    } catch(Exception $e) {
        return 0;
    }
}

// === شورتکد نمایش لیست ویدیوها و عضویت (نسخه اصلاح‌شده) ===
function show_youtube_iframe_videos() {
    ob_start();

    // شناسه کانال خود را اینجا نیز وارد کنید
    $channel_id = '';
    $subscribe_url = "https://www.youtube.com/channel/{$channel_id}?sub_confirmation=1";
    ?>
    <style>
        .subscribe-button{background-color:#FF0000;color:#fff;padding:12px 20px;border:none;border-radius:6px;font-size:16px;cursor:pointer;margin-bottom:30px;transition:background-color .3s}.subscribe-button:hover{background-color:#cc0000}.subscribe-section{text-align:center;margin-bottom:20px}.video-grid{display:grid;grid-template-columns:1fr 1fr;gap:20px}@media(max-width:768px){.video-grid{grid-template-columns:1fr}}.video-item{background:#fff;padding:10px;border-radius:8px;border:1px solid #ddd}.video-item iframe{width:100%;height:250px;border-radius:6px;border:none}.pagination{text-align:center;margin-top:20px}.pagination a{margin:0 5px;background:#0073aa;color:#fff;padding:8px 12px;border-radius:5px;text-decoration:none}.pagination a.current{background:#333}
    </style>

    <div class="subscribe-section">
        <a href="<?php echo esc_url($subscribe_url); ?>" target="_blank" rel="noopener noreferrer">
            <button class="subscribe-button">عضویت در کانال یوتیوب</button>
        </a>
    </div>

    <?php
    $current_page = isset($_GET['vpage']) ? max(1, intval($_GET['vpage'])) : 1;
    $result = get_youtube_videos_paginated($current_page, 10);

    $videos = $result['videos'];
    $total_pages = $result['total_pages'];

    if (empty($videos)) {
        echo '<p style="text-align:center; color:#555;">فعلاً هیچ ویدیویی برای نمایش وجود ندارد.</p>';
        return ob_get_clean();
    }

    echo '<div class="video-grid">';
    foreach ($videos as $video) {
        $video_id = esc_attr($video['video_id']);
        $title = esc_html($video['title']);
        $embed_url = "https://www.youtube.com/embed/" . $video_id; // آدرس صحیح embed
        
        echo '<div class="video-item">
                  <iframe src="' . $embed_url . '" title="' . $title . '" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen class="watchable-video" data-video-id="' . $video_id . '"></iframe>
                  <h3>' . $title . '</h3>
              </div>';
    }
    echo '</div>';

    if ($total_pages > 1) {
        echo '<div class="pagination">';
        for ($i = 1; $i <= $total_pages; $i++) {
            $current_class = ($i == $current_page) ? 'current' : '';
            // استفاده از vpage به جای page برای جلوگیری از تداخل با کوئری اصلی وردپرس
            echo '<a href="?vpage=' . $i . '" class="' . $current_class . '">' . $i . '</a>';
        }
        echo '</div>';
    }
    ?>
    <script>
        // اسکریپت پاداش‌دهی شما بدون تغییر باقی می‌ماند
        let totalWatchTime = 0;
        let watchInterval = null;

        document.querySelectorAll('.watchable-video').forEach(iframe => {
            iframe.addEventListener('mouseenter', () => {
                if (watchInterval === null) {
                    watchInterval = setInterval(() => {
                        totalWatchTime++;
                    }, 1000);
                }
            });
            iframe.addEventListener('mouseleave', () => {
                if (watchInterval !== null) {
                    clearInterval(watchInterval);
                    watchInterval = null;
                }
            });
        });

        window.addEventListener('beforeunload', () => {
            if (totalWatchTime >= 3600) {
                navigator.sendBeacon('<?php echo admin_url('admin-ajax.php'); ?>', new URLSearchParams({
                    action: 'credit_watch_time',
                    watch_time: totalWatchTime
                }));
            }
        });
    </script>
    <?php
    return ob_get_clean();
}
add_shortcode('youtube_iframe_list', 'show_youtube_iframe_videos');

// === AJAX عضویت در کانال و افزودن اعتبار یک‌بار ===
function ajax_add_initial_credit() {
    if ( !is_user_logged_in() ) wp_send_json_error('کاربر وارد نشده.');
    $user_id = get_current_user_id();

    if ( get_user_meta($user_id, 'subscribed_to_channel', true) !== 'yes' && class_exists('WooWallet') ) {
        $wallet_balance = woo_wallet()->wallet->get_wallet_balance($user_id, 'edit');
        $new_balance = $wallet_balance + 10000;
        woo_wallet()->wallet->update_wallet_balance($user_id, $new_balance);
        update_user_meta($user_id, 'subscribed_to_channel', 'yes');
        wp_send_json_success("اعتبار عضویت اضافه شد.");
    }
    wp_send_json_error("پاداش قبلاً داده شده.");
}
add_action('wp_ajax_add_initial_credit', 'ajax_add_initial_credit');

// === AJAX پاداش برای زمان مشاهده ===
function credit_watch_time_ajax() {
    if ( !is_user_logged_in() ) wp_send_json_error('کاربر وارد نشده است.');
    $user_id = get_current_user_id();
    $watch_time = isset($_POST['watch_time']) ? intval($_POST['watch_time']) : 0;

    $hours = floor($watch_time / 3600);
    if ( $hours > 0 && class_exists('WooWallet') ) {
        $credit = $hours * 12000;
        $wallet_balance = woo_wallet()->wallet->get_wallet_balance($user_id, 'edit');
        $new_balance = $wallet_balance + $credit;
        woo_wallet()->wallet->update_wallet_balance($user_id, $new_balance);
        update_user_meta($user_id, 'watch_time_credit_' . time(), $credit);
        wp_send_json_success("{$credit} تومان بابت تماشا اضافه شد.");
    }
    wp_send_json_error("زمان کافی نبود.");
}
add_action('wp_ajax_credit_watch_time', 'credit_watch_time_ajax');
