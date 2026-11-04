<?php
/*
Plugin Name: Moderator Activity Tracker
Plugin URI: --
Description: Tracks moderator activity in WordPress.
Version: 1.1
Author: Charoon
Author URI: --
*/

// ─── Activation: create table & grant capability ────────────────────────────

function mat_create_table() {
    global $wpdb;
    $table_name      = $wpdb->prefix . 'moderator_activity';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        user_id bigint(20) NOT NULL,
        user_name varchar(255) NOT NULL,
        action varchar(255) NOT NULL,
        order_id bigint(20) DEFAULT NULL,
        product_id bigint(20) DEFAULT NULL,
        timestamp datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
        PRIMARY KEY (id),
        KEY idx_user_timestamp (user_id, timestamp)
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);

    // Grant capability once on activation — not on every admin_menu load
    $role = get_role('shop_manager');
    if ($role && ! $role->has_cap('edit_shop_orders')) {
        $role->add_cap('edit_shop_orders');
    }
}
register_activation_hook(__FILE__, 'mat_create_table');


// ─── Status label helper — DYNAMIC (reads all registered WC statuses) ────────

// Arabic names for WooCommerce built-in statuses only (custom ones are read dynamically)
function mat_builtin_status_ar() {
    return [
        'pending'        => 'قيد الانتظار',
        'processing'     => 'قيد المعالجة',
        'on-hold'        => 'قيد الانتظار (معلق)',
        'completed'      => 'مكتمل',
        'cancelled'      => 'ملغي',
        'refunded'       => 'مسترجع',
        'failed'         => 'فشل',
        'checkout-draft' => 'مسودة',
    ];
}

// Returns [slug => label] for every registered status (built-in in Arabic, custom as registered)
function mat_all_status_labels() {
    $labels  = [];
    $builtin = mat_builtin_status_ar();
    if (function_exists('wc_get_order_statuses')) {
        foreach (wc_get_order_statuses() as $key => $name) {
            $slug          = (strpos($key, 'wc-') === 0) ? substr($key, 3) : $key;
            $labels[$slug] = isset($builtin[$slug]) ? $builtin[$slug] : $name;
        }
    }
    return $labels;
}

// All names a status could have been stored under in old/new log rows
function mat_status_variants($slug) {
    $variants = [$slug, 'wc-' . $slug];
    $builtin  = mat_builtin_status_ar();
    if (isset($builtin[$slug])) {
        $variants[] = $builtin[$slug];
    }
    if (function_exists('wc_get_order_statuses')) {
        $all = wc_get_order_statuses();
        if (isset($all['wc-' . $slug])) {
            $variants[] = $all['wc-' . $slug];
        }
    }
    $labels = mat_all_status_labels();
    if (isset($labels[$slug])) {
        $variants[] = $labels[$slug];
    }
    return array_values(array_unique(array_filter($variants)));
}

// Translate a stored status text (English name / slug) to Arabic for display (old rows too)
function mat_localize_status_text($text) {
    $text = trim($text);
    $key  = strtolower($text);
    foreach (mat_builtin_status_ar() as $slug => $ar) {
        foreach (mat_status_variants($slug) as $v) {
            if (strtolower($v) === $key) {
                return $ar;
            }
        }
        // common English names in case WooCommerce isn't loaded / is translated
        $en = strtolower(str_replace('-', ' ', $slug));
        if ($key === $en) {
            return $ar;
        }
    }
    return $text;
}

function mat_map_status_label($status) {
    $slug = (strpos($status, 'wc-') === 0) ? substr($status, 3) : $status;
    $all  = mat_all_status_labels();
    if (isset($all[$slug])) {
        return $all[$slug];
    }
    $builtin = mat_builtin_status_ar();
    return isset($builtin[$slug]) ? $builtin[$slug] : $status;
}


// ─── Core logging function ───────────────────────────────────────────────────

function log_moderator_activity($user_id, $action, $order_id = null, $product_id = null) {
    global $wpdb;

    // Fix: get_userdata() can return false for user_id = 0
    $user_info = get_userdata($user_id);
    if (! $user_info) {
        return;
    }

    $wpdb->insert(
        $wpdb->prefix . 'moderator_activity',
        [
            'user_id'    => $user_id,
            'user_name'  => $user_info->user_login,
            'action'     => $action,
            'order_id'   => $order_id,
            'product_id' => $product_id,
            'timestamp'  => current_time('mysql'),
        ]
    );
}


// ─── Track order status changes ──────────────────────────────────────────────

function track_order_status_change($order_id, $old_status, $new_status, $order) {
    $user_id = get_current_user_id();
    if (! $user_id) return;

    // Fix: use helper to map string slugs (not integer comparison which never matched)
    $action = mat_map_status_label($old_status) . ' --> ' . mat_map_status_label($new_status);
    log_moderator_activity($user_id, $action, $order_id);
}
add_action('woocommerce_order_status_changed', 'track_order_status_change', 10, 4);


// ─── Track product updates ───────────────────────────────────────────────────

function track_product_update($post_ID, $post, $update) {
    // Fix: skip autosaves and revisions to avoid spam logs
    if (wp_is_post_autosave($post_ID) || wp_is_post_revision($post_ID)) {
        return;
    }
    $user_id = get_current_user_id();
    if (! $user_id) return;

    log_moderator_activity($user_id, 'Updated product: ' . $post->post_title, null, $post_ID);
}
add_action('save_post_product', 'track_product_update', 10, 3);


// ─── Custom capability: who may VIEW the reports (manage it in User Role Editor) ──
// Moderators (e.g. the "reviewer" role) do NOT get it, so they can't see each
// other's activity or the upsell report. Give it to any role you want in
// User Role Editor → "view_moderator_activity".
add_action('admin_init', function () {
    if (get_option('mat_cap_granted') === '1') return;
    foreach (['administrator', 'shop_manager'] as $role_name) {
        $role = get_role($role_name);
        if ($role && ! $role->has_cap('view_moderator_activity')) {
            $role->add_cap('view_moderator_activity');
        }
    }
    update_option('mat_cap_granted', '1');
});

// ─── Admin menu ──────────────────────────────────────────────────────────────

function moderator_activity_menu() {
    // Fix: removed add_cap() from here — it now only runs on activation
    add_menu_page(
        'Moderator Activity',
        'Moderator Activity',
        'view_moderator_activity',
        'moderator-activity',
        'display_moderator_activity',
        'dashicons-admin-users',
        25
    );
}
add_action('admin_menu', 'moderator_activity_menu');


// ─── Enqueue assets (only on our page) ──────────────────────────────────────

function mat_enqueue_assets($hook) {
    if ($hook !== 'toplevel_page_moderator-activity' && $hook !== 'moderator-activity_page_moderator-upsell') return;
    // Fix: use WP's bundled jQuery UI instead of loading it twice from CDN
    wp_enqueue_script('jquery-ui-autocomplete');
    wp_enqueue_style(
        'jquery-ui-style',
        'https://code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css',
        [],
        '1.12.1'
    );
}
add_action('admin_enqueue_scripts', 'mat_enqueue_assets');


// ─── Helper: avatar colour from username ────────────────────────────────────

function mat_avatar_color($username) {
    $palette = ['#2271b1','#00a0d2','#d63638','#dba617','#00ba37','#8c5a8c','#e65054','#3858e9'];
    return $palette[ abs( crc32($username) ) % count($palette) ];
}

// ─── Helper: وقت نسبي بالعربية ──────────────────────────────────────────────

function mat_time_ago($datetime) {
    $diff = current_time('timestamp') - strtotime($datetime);
    if ($diff <    60) return 'الآن';
    if ($diff <  3600) return floor($diff / 60)    . ' دقيقة';
    if ($diff < 86400) return floor($diff / 3600)   . ' ساعة';
    if ($diff < 604800) return floor($diff / 86400)  . ' يوم';
    return date_i18n('j M Y', strtotime($datetime));
}

// ─── Admin display page ──────────────────────────────────────────────────────

function display_moderator_activity() {
    if (! current_user_can('view_moderator_activity')) {
        wp_die(__('You do not have sufficient permissions.'));
    }

    global $wpdb;
    $table_name       = $wpdb->prefix . 'moderator_activity';
    $results_per_page = 20;

    // ── Handle bulk delete ───────────────────────────────────────────────────
    if (isset($_POST['delete_selected'])) {
        check_admin_referer('mat_bulk_delete', 'mat_nonce');
        if (current_user_can('manage_options')) {
            $ids = isset($_POST['delete']) ? array_map('intval', (array) $_POST['delete']) : [];
            foreach ($ids as $id) {
                if ($id > 0) $wpdb->delete($table_name, ['id' => $id], ['%d']);
            }
        }
    }

    // ── Handle delete ALL ────────────────────────────────────────────────────
    $deleted_all = false;
    if (isset($_POST['delete_all'])) {
        check_admin_referer('mat_bulk_delete', 'mat_nonce');
        if (current_user_can('manage_options')) {
            $wpdb->query("DELETE FROM $table_name");
            $deleted_all = true;
        }
    }

    // ── Read filters ─────────────────────────────────────────────────────────
    $status_from  = isset($_GET['status_from'])  ? sanitize_text_field(wp_unslash($_GET['status_from'])) : '';
    $status_to    = isset($_GET['status_to'])    ? sanitize_text_field(wp_unslash($_GET['status_to']))   : '';
    $status_labels = mat_all_status_labels();
    $search_user  = isset($_GET['search_user'])  ? sanitize_text_field($_GET['search_user'])  : '';
    $search_uid   = isset($_GET['search_uid'])   ? intval($_GET['search_uid'])                 : 0;
    $start_date   = isset($_GET['start_date'])   ? sanitize_text_field($_GET['start_date'])   : '';
    $end_date     = isset($_GET['end_date'])     ? sanitize_text_field($_GET['end_date'])     : '';
    $search_order = isset($_GET['search_order']) ? sanitize_text_field($_GET['search_order']) : '';
    $current_page = isset($_GET['paged'])        ? max(1, intval($_GET['paged']))              : 1;
    $has_filters  = $search_user || $search_uid || $start_date || $end_date || $search_order || $status_from || $status_to;

    // ── Build shared WHERE ────────────────────────────────────────────────────
    $where  = 'WHERE 1=1';
    $params = [];

    if ($search_uid > 0) {
        $where .= ' AND user_id = %d';   $params[] = $search_uid;
    } elseif ($search_user !== '') {
        $where .= ' AND user_name LIKE %s'; $params[] = '%' . $wpdb->esc_like($search_user) . '%';
    }
    if ($start_date !== '') { $where .= ' AND timestamp >= %s'; $params[] = $start_date . ' 00:00:00'; }
    if ($end_date   !== '') { $where .= ' AND timestamp <= %s'; $params[] = $end_date   . ' 23:59:59'; }
    if ($search_order !== '') { $where .= ' AND order_id = %d'; $params[] = intval($search_order); }
    // Status filters: match every name a status may have been logged under
    // (Arabic label, WooCommerce registered name, raw slug) so old logs still match.
    if ($status_from !== '' && isset($status_labels[$status_from])) {
        $ors = [];
        foreach (mat_status_variants($status_from) as $v) {
            $ors[]    = 'TRIM(action) LIKE %s';
            $params[] = $wpdb->esc_like($v . ' -->') . '%';
        }
        $where .= ' AND (' . implode(' OR ', $ors) . ')';
    }
    if ($status_to !== '' && isset($status_labels[$status_to])) {
        $ors = [];
        foreach (mat_status_variants($status_to) as $v) {
            $ors[]    = 'TRIM(action) LIKE %s';
            $params[] = '%' . $wpdb->esc_like('--> ' . $v);
        }
        $where .= ' AND (' . implode(' OR ', $ors) . ')';
    }

    // ── Counts ───────────────────────────────────────────────────────────────
    $count_sql     = "SELECT COUNT(*) FROM $table_name $where";
    $total_results = empty($params) ? $wpdb->get_var($count_sql) : $wpdb->get_var($wpdb->prepare($count_sql, $params));
    $total_pages   = (int) ceil($total_results / $results_per_page);
    $offset        = ($current_page - 1) * $results_per_page;

    // ── Fetch page ───────────────────────────────────────────────────────────
    $data_params = array_merge($params, [$results_per_page, $offset]);
    $results     = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $table_name $where ORDER BY timestamp DESC LIMIT %d OFFSET %d",
        $data_params
    ));

    // ── Stats (always unfiltered) ─────────────────────────────────────────────
    $today_start  = current_time('Y-m-d') . ' 00:00:00';
    $week_start   = date('Y-m-d', strtotime('-7 days', current_time('timestamp'))) . ' 00:00:00';
    $stat_total   = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
    $stat_today   = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_name WHERE timestamp >= %s", $today_start));
    $stat_week    = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_name WHERE timestamp >= %s", $week_start));
    $stat_mods    = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT user_id) FROM $table_name WHERE timestamp >= %s", $week_start));

    $suggest_nonce = wp_create_nonce('mat_user_suggest');
    $filter_args   = [
        'page'         => 'moderator-activity',
        'search_user'  => rawurlencode($search_user),
        'search_uid'   => $search_uid ?: '',
        'start_date'   => rawurlencode($start_date),
        'end_date'     => rawurlencode($end_date),
        'search_order' => rawurlencode($search_order),
        'status_from'  => rawurlencode($status_from),
        'status_to'    => rawurlencode($status_to),
    ];
    ?>

    <style>
    /* ══════════════════════════════════════════════════════
       متغيرات التصميم — Design Variables
    ══════════════════════════════════════════════════════ */
    :root {
        --mat-blue:    #2271b1;
        --mat-blue-lt: #e8f0fa;
        --mat-green:   #00ba37;
        --mat-orange:  #dba617;
        --mat-red:     #d63638;
        --mat-purple:  #8c5a8c;
        --mat-dark:    #1d2327;
        --mat-mid:     #50575e;
        --mat-muted:   #787c82;
        --mat-border:  #dcdcde;
        --mat-bg:      #f0f0f1;
        --mat-white:   #ffffff;
        --mat-radius:  10px;
        --mat-shadow:  0 2px 8px rgba(0,0,0,.08);
        --mat-shadow-hover: 0 4px 16px rgba(0,0,0,.13);
        --mat-font:    'Segoe UI', Tahoma, Arial, sans-serif;
    }

    /* ── الصفحة الرئيسية — RTL ───────────────────────── */
    .mat-page {
        max-width: 1400px;
        direction: rtl;
        font-family: var(--mat-font);
    }
    .mat-page * { box-sizing: border-box; }

    /* ── رأس الصفحة ──────────────────────────────────── */
    .mat-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin: 20px 0 24px;
    }
    .mat-header-left { display: flex; align-items: center; gap: 12px; }
    .mat-header-icon {
        width: 48px; height: 48px;
        background: var(--mat-blue);
        border-radius: 12px;
        display: flex; align-items: center; justify-content: center;
    }
    .mat-header-icon .dashicons { font-size: 26px; color: #fff; width:26px; height:26px; }
    .mat-header h1 {
        margin: 0 !important; padding: 0 !important;
        font-size: 22px !important; line-height: 1.2 !important;
        color: var(--mat-dark); font-weight: 700;
    }
    .mat-header-sub { font-size: 13px; color: var(--mat-muted); margin-top: 2px; }
    .mat-header-badge {
        background: var(--mat-blue-lt); color: var(--mat-blue);
        font-size: 12px; font-weight: 600; padding: 4px 10px;
        border-radius: 20px; white-space: nowrap;
    }

    /* ── بطاقات الإحصائيات ────────────────────────────── */
    .mat-stats {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 14px;
        margin-bottom: 20px;
    }
    .mat-stat {
        background: var(--mat-white);
        border: 1px solid var(--mat-border);
        border-radius: var(--mat-radius);
        padding: 18px 20px;
        display: flex; align-items: center; gap: 14px;
        box-shadow: var(--mat-shadow);
        transition: box-shadow .2s;
    }
    .mat-stat:hover { box-shadow: var(--mat-shadow-hover); }
    .mat-stat-icon {
        width: 44px; height: 44px; border-radius: 10px;
        display: flex; align-items: center; justify-content: center; flex-shrink: 0;
    }
    .mat-stat-icon .dashicons { font-size: 22px; width:22px; height:22px; color:#fff; }
    .mat-stat-icon.blue   { background: var(--mat-blue); }
    .mat-stat-icon.green  { background: var(--mat-green); }
    .mat-stat-icon.orange { background: var(--mat-orange); }
    .mat-stat-icon.purple { background: var(--mat-purple); }
    .mat-stat-value { font-size: 26px; font-weight: 700; color: var(--mat-dark); line-height: 1; }
    .mat-stat-label { font-size: 12px; color: var(--mat-muted); margin-top: 4px; }

    /* ── البطاقات ─────────────────────────────────────── */
    .mat-card {
        background: var(--mat-white);
        border: 1px solid var(--mat-border);
        border-radius: var(--mat-radius);
        box-shadow: var(--mat-shadow);
        margin-bottom: 16px;
        overflow: hidden;
    }
    .mat-card-header {
        display: flex; align-items: center; justify-content: space-between;
        padding: 14px 20px;
        border-bottom: 1px solid var(--mat-border);
        background: #fafafa;
    }
    .mat-card-header h2 {
        margin: 0; font-size: 14px; font-weight: 600;
        color: var(--mat-dark); display: flex; align-items: center; gap: 7px;
    }
    .mat-card-header h2 .dashicons { font-size: 16px; width:16px; height:16px; color: var(--mat-blue); }
    .mat-card-body { padding: 18px 20px; }

    /* ── نموذج الفلترة — RTL aware ────────────────────── */
    .mat-filter-row {
        display: flex; align-items: flex-end; flex-wrap: wrap; gap: 12px;
    }
    .mat-field { display: flex; flex-direction: column; gap: 4px; }
    .mat-field label {
        font-size: 11px; font-weight: 600; color: var(--mat-mid);
        letter-spacing: .4px; text-align: right;
    }
    .mat-field input[type=text],
    .mat-field input[type=date] {
        height: 36px; padding: 0 10px;
        border: 1px solid var(--mat-border);
        border-radius: 6px; font-size: 13px;
        color: var(--mat-dark); background: #fff; outline: none;
        transition: border-color .15s, box-shadow .15s;
        direction: rtl; text-align: right;
    }
    .mat-field input:focus {
        border-color: var(--mat-blue);
        box-shadow: 0 0 0 2px rgba(34,113,177,.15);
    }
    /* حقل البحث بالمستخدم — أيقونة على اليمين */
    .mat-field-user { position: relative; }
    .mat-field-user input { padding-right: 32px; padding-left: 10px; min-width: 240px; }
    .mat-field-user .mat-search-ico {
        position: absolute; right: 9px; top: 50%; transform: translateY(-50%);
        color: var(--mat-muted); pointer-events: none;
        font-size: 15px; width:15px; height:15px;
    }
    .mat-filter-actions { display: flex; gap: 8px; padding-bottom: 1px; }
    .mat-btn {
        height: 36px; padding: 0 16px; border-radius: 6px;
        font-size: 13px; font-weight: 500; cursor: pointer;
        border: 1px solid transparent; display: inline-flex; align-items: center; gap: 5px;
        text-decoration: none; transition: all .15s; font-family: var(--mat-font);
    }
    .mat-btn-primary { background: var(--mat-blue); color: #fff; border-color: var(--mat-blue); }
    .mat-btn-primary:hover { background: #1a5e9e; color: #fff; }
    .mat-btn-ghost { background: #fff; color: var(--mat-mid); border-color: var(--mat-border); }
    .mat-btn-ghost:hover { background: var(--mat-bg); color: var(--mat-dark); }
    .mat-btn-danger { background: #fff; color: var(--mat-red); border-color: var(--mat-red); }
    .mat-btn-danger:hover { background: var(--mat-red); color: #fff; }
    .mat-btn-danger-solid { background: var(--mat-red); color: #fff; border-color: var(--mat-red); }
    .mat-btn-danger-solid:hover { background: #b32d2e; color: #fff; }
    .mat-field select {
        height: 36px; padding: 0 10px; border: 1px solid var(--mat-border);
        border-radius: 6px; font-size: 13px; background: #fff; direction: rtl;
    }
    .mat-notice { background:#edfaef; color:#1a7a35; border:1px solid #b8e6c1; border-radius:8px; padding:10px 14px; margin-bottom:14px; font-size:13px; }

    .mat-filter-active-tag {
        display: inline-flex; align-items: center; gap: 5px;
        background: var(--mat-blue-lt); color: var(--mat-blue);
        font-size: 12px; font-weight: 500; padding: 3px 10px; border-radius: 20px;
    }

    /* ── شريط أدوات الجدول ───────────────────────────── */
    .mat-toolbar {
        display: flex; align-items: center; justify-content: space-between;
        padding: 12px 20px; border-bottom: 1px solid var(--mat-border); background: #fafafa;
    }
    .mat-toolbar-left { font-size: 13px; color: var(--mat-muted); }
    .mat-toolbar-left strong { color: var(--mat-dark); }
    .mat-toolbar-right { display: flex; gap: 8px; align-items: center; }

    /* ── الجدول ──────────────────────────────────────── */
    .mat-table { width: 100%; border-collapse: collapse; }
    .mat-table th {
        text-align: right; font-size: 11px; font-weight: 700;
        letter-spacing: .5px; color: var(--mat-muted); padding: 10px 14px;
        border-bottom: 1px solid var(--mat-border); background: #fafafa; white-space: nowrap;
    }
    .mat-table th.col-check  { width: 36px; text-align: center; }
    .mat-table th.col-id     { width: 56px; }
    .mat-table th.col-user   { width: 180px; }
    .mat-table th.col-action { }
    .mat-table th.col-order  { width: 100px; }
    .mat-table th.col-time   { width: 150px; }
    .mat-table td {
        padding: 11px 14px; border-bottom: 1px solid #f0f0f1;
        font-size: 13px; color: var(--mat-dark); vertical-align: middle; text-align: right;
    }
    .mat-table td.col-check { text-align: center; }
    .mat-table tr:last-child td { border-bottom: none; }
    .mat-table tr:hover td { background: #f8fafd; }

    /* ── خلية المستخدم ───────────────────────────────── */
    .mat-user-cell { display: flex; align-items: center; gap: 9px; direction: rtl; justify-content: flex-start; }
    .mat-avatar {
        width: 32px; height: 32px; border-radius: 50%;
        font-size: 11px; font-weight: 700; color: #fff;
        display: flex; align-items: center; justify-content: center;
        flex-shrink: 0; letter-spacing: .5px;
    }
    .mat-username { font-weight: 500; color: var(--mat-dark); }

    /* ── خلية الإجراء ────────────────────────────────── */
    .mat-action-order {
        display: inline-flex; align-items: center; gap: 7px; flex-wrap: wrap;
        font-size: 13px; direction: rtl;
    }
    .mat-status-pill {
        display: inline-block; padding: 2px 9px;
        border-radius: 20px; font-size: 12px; font-weight: 500;
        background: #f0f0f1; color: var(--mat-mid); white-space: nowrap;
    }
    .mat-status-pill.from { background: #fff4e5; color: #996800; }
    .mat-status-pill.to   { background: #edfaef; color: #1a7a35; }
    .mat-action-arrow { color: var(--mat-muted); font-size: 15px; }
    .mat-action-product {
        display: inline-flex; align-items: center; gap: 6px;
        color: var(--mat-blue); font-size: 13px; direction: rtl;
    }
    .mat-action-product .dashicons { font-size: 15px; width:15px; height:15px; }

    /* ── رابط الطلب ──────────────────────────────────── */
    .mat-order-link {
        display: inline-flex; align-items: center; gap: 4px;
        color: var(--mat-blue); font-weight: 600; font-size: 13px;
        text-decoration: none; padding: 2px 8px;
        border: 1px solid var(--mat-blue-lt); border-radius: 5px;
        background: var(--mat-blue-lt); transition: all .15s;
    }
    .mat-order-link:hover { background: var(--mat-blue); color: #fff; border-color: var(--mat-blue); }

    /* ── خلية الوقت ──────────────────────────────────── */
    .mat-time { font-size: 12px; }
    .mat-time-rel { color: var(--mat-dark); font-weight: 500; }
    .mat-time-abs { color: var(--mat-muted); font-size: 11px; margin-top: 1px; }

    /* ── حالة فارغة ──────────────────────────────────── */
    .mat-empty { text-align: center; padding: 60px 20px; }
    .mat-empty-icon { font-size: 48px; color: var(--mat-border); margin-bottom: 12px; }
    .mat-empty h3 { margin: 0 0 6px; color: var(--mat-mid); font-size: 16px; }
    .mat-empty p  { margin: 0; color: var(--mat-muted); font-size: 13px; }

    /* ── ترقيم الصفحات ───────────────────────────────── */
    .mat-pagination {
        display: flex; align-items: center; justify-content: space-between;
        padding: 14px 20px; border-top: 1px solid var(--mat-border);
    }
    .mat-pagination .mat-page-info { font-size: 12px; color: var(--mat-muted); }
    .mat-pagination .pagination-links { display: flex; gap: 4px; align-items: center; flex-direction: row-reverse; }
    .mat-pagination .page-numbers {
        display: inline-flex; align-items: center; justify-content: center;
        min-width: 32px; height: 32px; padding: 0 10px;
        border: 1px solid var(--mat-border); border-radius: 6px;
        font-size: 13px; color: var(--mat-mid); text-decoration: none;
        background: #fff; transition: all .15s;
    }
    .mat-pagination .page-numbers:hover { border-color: var(--mat-blue); color: var(--mat-blue); }
    .mat-pagination .page-numbers.current {
        background: var(--mat-blue); color: #fff; border-color: var(--mat-blue); font-weight: 600;
    }
    .mat-pagination .page-numbers.dots { border: none; background: none; }

    /* ── قائمة الإكمال التلقائي ──────────────────────── */
    .ui-autocomplete {
        max-height: 300px; overflow-y: auto; overflow-x: hidden;
        border: 1px solid var(--mat-border) !important;
        border-radius: 8px !important; box-shadow: var(--mat-shadow-hover) !important;
        padding: 4px 0 !important; direction: rtl;
    }
    .ui-menu-item-wrapper { padding: 0 !important; }
    .ui-menu-item-wrapper.ui-state-active { background: var(--mat-blue-lt) !important; border: none !important; }
    .mat-ac-row { padding: 8px 12px; cursor: pointer; border-bottom: 1px solid #f5f5f5; }
    .mat-ac-row:last-child { border-bottom: none; }
    .mat-ac-top { display: flex; align-items: center; gap: 8px; direction: rtl; justify-content: flex-start; }
    .mat-ac-avatar { width:28px; height:28px; border-radius:50%; background:var(--mat-blue); color:#fff; font-size:11px; font-weight:700; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
    .mat-ac-login { font-weight: 600; font-size: 13px; color: var(--mat-dark); }
    .mat-ac-name  { font-size: 12px; color: var(--mat-muted); }
    .mat-ac-email { font-size: 11px; color: var(--mat-blue); margin-top: 1px; margin-right: 36px; text-align: right; }

    /* ── Responsive ──────────────────────────────────── */
    @media (max-width: 1100px) { .mat-stats { grid-template-columns: repeat(2, 1fr); } }
    @media (max-width: 700px) {
        .mat-page { margin-left: 12px; margin-right: 0; }
        .mat-stats { grid-template-columns: 1fr; }
        .mat-filter-row { flex-direction: column; align-items: stretch; }
        .mat-field-user input { min-width: unset; }
    }
    </style>

    <div class="mat-page">

        <!-- رأس الصفحة -->
        <div class="mat-header">
            <div class="mat-header-left">
                <div class="mat-header-icon">
                    <span class="dashicons dashicons-admin-users"></span>
                </div>
                <div>
                    <h1 class="mat-header">سجل نشاط المشرفين</h1>
                    <div class="mat-header-sub">تتبع ومراقبة جميع إجراءات المشرفين على الطلبات والمنتجات</div>
                </div>
            </div>
            <div class="mat-header-badge">
                <span class="dashicons dashicons-shield" style="font-size:13px;width:13px;height:13px;vertical-align:middle;"></span>
                v1.1
            </div>
        </div>

        <!-- بطاقات الإحصائيات -->
        <?php if ($deleted_all) : ?>
            <div class="mat-notice">تم حذف جميع السجلات بنجاح.</div>
        <?php endif; ?>
        <div class="mat-stats">
            <div class="mat-stat">
                <div class="mat-stat-icon blue"><span class="dashicons dashicons-chart-bar"></span></div>
                <div>
                    <div class="mat-stat-value"><?php echo number_format($stat_total); ?></div>
                    <div class="mat-stat-label">إجمالي السجلات</div>
                </div>
            </div>
            <div class="mat-stat">
                <div class="mat-stat-icon green"><span class="dashicons dashicons-calendar-alt"></span></div>
                <div>
                    <div class="mat-stat-value"><?php echo number_format($stat_today); ?></div>
                    <div class="mat-stat-label">اليوم</div>
                </div>
            </div>
            <div class="mat-stat">
                <div class="mat-stat-icon orange"><span class="dashicons dashicons-backup"></span></div>
                <div>
                    <div class="mat-stat-value"><?php echo number_format($stat_week); ?></div>
                    <div class="mat-stat-label">هذا الأسبوع</div>
                </div>
            </div>
            <div class="mat-stat">
                <div class="mat-stat-icon purple"><span class="dashicons dashicons-groups"></span></div>
                <div>
                    <div class="mat-stat-value"><?php echo number_format($stat_mods); ?></div>
                    <div class="mat-stat-label">مشرفون نشطون (7 أيام)</div>
                </div>
            </div>
        </div>

        <!-- بطاقة الفلاتر -->
        <div class="mat-card">
            <div class="mat-card-header">
                <h2>
                    <span class="dashicons dashicons-filter"></span>
                    فلترة النتائج
                    <?php if ($has_filters) : ?>
                        <span class="mat-filter-active-tag">نشط</span>
                    <?php endif; ?>
                </h2>
                <?php if ($has_filters) : ?>
                    <a href="<?php echo admin_url('admin.php?page=moderator-activity'); ?>"
                       class="mat-btn mat-btn-ghost" style="font-size:12px;height:28px;padding:0 10px;">
                        ✕ مسح الفلاتر
                    </a>
                <?php endif; ?>
            </div>
            <div class="mat-card-body">
                <form method="get" id="mat-search-form">
                    <input type="hidden" name="page" value="moderator-activity">
                    <input type="hidden" id="search_uid" name="search_uid"
                           value="<?php echo esc_attr($search_uid ?: ''); ?>">
                    <div class="mat-filter-row">

                        <div class="mat-field mat-field-user">
                            <label for="search_user">المستخدم</label>
                            <span class="dashicons dashicons-search mat-search-ico"></span>
                            <input type="text"
                                   id="search_user" name="search_user"
                                   value="<?php echo esc_attr($search_user); ?>"
                                   placeholder="ابحث بالاسم أو اسم المستخدم أو البريد..."
                                   autocomplete="off">
                        </div>

                        <div class="mat-field">
                            <label>من تاريخ</label>
                            <input type="date" name="start_date"
                                   value="<?php echo esc_attr($start_date); ?>" style="width:150px;">
                        </div>

                        <div class="mat-field">
                            <label>إلى تاريخ</label>
                            <input type="date" name="end_date"
                                   value="<?php echo esc_attr($end_date); ?>" style="width:150px;">
                        </div>

                        <div class="mat-field">
                            <label>رقم الطلب</label>
                            <input type="text" name="search_order"
                                   value="<?php echo esc_attr($search_order); ?>"
                                   placeholder="#12345" style="width:120px;">
                        </div>

                        <div class="mat-field">
                            <label>من حالة</label>
                            <select name="status_from" style="width:160px;">
                                <option value="">كل الحالات</option>
                                <?php foreach ($status_labels as $slug => $label) : ?>
                                    <option value="<?php echo esc_attr($slug); ?>" <?php selected($status_from, $slug); ?>><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mat-field">
                            <label>إلى حالة</label>
                            <select name="status_to" style="width:160px;">
                                <option value="">كل الحالات</option>
                                <?php foreach ($status_labels as $slug => $label) : ?>
                                    <option value="<?php echo esc_attr($slug); ?>" <?php selected($status_to, $slug); ?>><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mat-filter-actions">
                            <button type="submit" class="mat-btn mat-btn-primary">
                                <span class="dashicons dashicons-search" style="font-size:14px;width:14px;height:14px;"></span>
                                بحث
                            </button>
                            <?php if ($has_filters) : ?>
                                <a href="<?php echo admin_url('admin.php?page=moderator-activity'); ?>"
                                   class="mat-btn mat-btn-ghost">إعادة تعيين</a>
                            <?php endif; ?>
                        </div>

                    </div>
                </form>
            </div>
        </div>

        <!-- بطاقة الجدول -->
        <div class="mat-card">
            <form method="post" id="mat-delete-form">
                <?php wp_nonce_field('mat_bulk_delete', 'mat_nonce'); ?>

                <!-- شريط الأدوات -->
                <div class="mat-toolbar">
                    <div class="mat-toolbar-left">
                        <?php if ($has_filters) : ?>
                            <strong><?php echo number_format($total_results); ?></strong> نتيجة للفلاتر الحالية
                        <?php else : ?>
                            إجمالي السجلات: <strong><?php echo number_format($total_results); ?></strong>
                        <?php endif; ?>
                        <?php if ($total_pages > 1) : ?>
                            &nbsp;·&nbsp; صفحة <?php echo $current_page; ?> من <?php echo $total_pages; ?>
                        <?php endif; ?>
                    </div>
                    <div class="mat-toolbar-right">
                        <?php if (current_user_can('manage_options')) : ?>
                            <button type="submit" name="delete_selected" class="mat-btn mat-btn-danger"
                                    onclick="return confirm('هل تريد حذف السجلات المحددة؟ لا يمكن التراجع عن هذا الإجراء.');">
                                <span class="dashicons dashicons-trash" style="font-size:14px;width:14px;height:14px;"></span>
                                حذف المحدد
                            </button>
                            <button type="submit" name="delete_all" class="mat-btn mat-btn-danger-solid"
                                    onclick="return confirm('تحذير: سيتم حذف جميع السجلات نهائياً. هل أنت متأكد؟');">
                                <span class="dashicons dashicons-warning" style="font-size:14px;width:14px;height:14px;"></span>
                                حذف الكل
                            </button>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- الجدول -->
                <table class="mat-table">
                    <thead>
                        <tr>
                            <th class="col-check">
                                <input type="checkbox" id="mat-select-all" title="تحديد الكل">
                            </th>
                            <th class="col-id">#</th>
                            <th class="col-user">المستخدم</th>
                            <th class="col-action">الإجراء</th>
                            <th class="col-order">الطلب</th>
                            <th class="col-time">الوقت</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($results)) : ?>
                        <tr>
                            <td colspan="6">
                                <div class="mat-empty">
                                    <div class="mat-empty-icon">📋</div>
                                    <h3>لا توجد سجلات</h3>
                                    <p><?php echo $has_filters ? 'حاول تعديل الفلاتر أو مسحها.' : 'لم يتم تسجيل أي إجراءات حتى الآن.'; ?></p>
                                </div>
                            </td>
                        </tr>
                    <?php else : foreach ($results as $row) :
                        $initials = strtoupper(mb_substr($row->user_name, 0, 2, 'UTF-8'));
                        $av_color = mat_avatar_color($row->user_name);
                        $is_order = strpos($row->action, '-->') !== false;
                    ?>
                        <tr>
                            <!-- تحديد -->
                            <td class="col-check">
                                <input type="checkbox" name="delete[]" value="<?php echo esc_attr($row->id); ?>">
                            </td>

                            <!-- الرقم -->
                            <td style="color:var(--mat-muted); font-size:12px;"><?php echo esc_html($row->id); ?></td>

                            <!-- المستخدم -->
                            <td>
                                <div class="mat-user-cell">
                                    <div class="mat-avatar" style="background:<?php echo esc_attr($av_color); ?>">
                                        <?php echo esc_html($initials); ?>
                                    </div>
                                    <span class="mat-username"><?php echo esc_html($row->user_name); ?></span>
                                </div>
                            </td>

                            <!-- الإجراء -->
                            <td dir="rtl">
                                <?php if ($is_order) :
                                    $parts = explode('-->', $row->action, 2);
                                    $from  = mat_localize_status_text($parts[0]);
                                    $to    = mat_localize_status_text($parts[1] ?? '');
                                ?>
                                    <div class="mat-action-order">
                                        <span class="mat-status-pill from"><?php echo esc_html($from); ?></span>
                                        <span class="mat-action-arrow">←</span>
                                        <span class="mat-status-pill to"><?php echo esc_html($to); ?></span>
                                    </div>
                                <?php else : ?>
                                    <span class="mat-action-product">
                                        <span class="dashicons dashicons-products"></span>
                                        <?php echo esc_html($row->action); ?>
                                    </span>
                                <?php endif; ?>
                            </td>

                            <!-- الطلب -->
                            <td>
                                <?php if ($row->order_id) : ?>
                                    <a class="mat-order-link"
                                       href="<?php echo esc_url(admin_url('post.php?post=' . intval($row->order_id) . '&action=edit')); ?>"
                                       target="_blank">
                                        #<?php echo esc_html($row->order_id); ?>
                                        <span class="dashicons dashicons-external" style="font-size:11px;width:11px;height:11px;"></span>
                                    </a>
                                <?php else : ?>
                                    <span style="color:var(--mat-border);">—</span>
                                <?php endif; ?>
                            </td>

                            <!-- الوقت -->
                            <td>
                                <div class="mat-time">
                                    <div class="mat-time-rel"><?php echo esc_html(mat_time_ago($row->timestamp)); ?></div>
                                    <div class="mat-time-abs"><?php echo esc_html(date_i18n('j M · H:i', strtotime($row->timestamp))); ?></div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>

                <!-- ترقيم الصفحات -->
                <?php if ($total_pages > 1) :
                    $pagination_base = add_query_arg(array_merge($filter_args, ['paged' => '%#%']));
                ?>
                <div class="mat-pagination">
                    <div class="mat-page-info">
                        عرض
                        <?php echo number_format(($current_page - 1) * $results_per_page + 1); ?>
                        –
                        <?php echo number_format(min($current_page * $results_per_page, $total_results)); ?>
                        من <?php echo number_format($total_results); ?>
                    </div>
                    <div class="pagination-links">
                        <?php echo paginate_links([
                            'base'      => $pagination_base,
                            'format'    => '?paged=%#%',
                            'prev_text' => '›',
                            'next_text' => '‹',
                            'total'     => $total_pages,
                            'current'   => $current_page,
                            'type'      => 'plain',
                        ]); ?>
                    </div>
                </div>
                <?php endif; ?>

            </form>
        </div><!-- /.mat-card -->
    </div><!-- /.mat-page -->

    <script>
    jQuery(document).ready(function($) {

        // ── تحديد الكل ──────────────────────────────────────────────────────
        $('#mat-select-all').on('change', function() {
            $('input[name="delete[]"]').prop('checked', this.checked);
        });
        $(document).on('change', 'input[name="delete[]"]', function() {
            var total   = $('input[name="delete[]"]').length;
            var checked = $('input[name="delete[]"]:checked').length;
            $('#mat-select-all').prop('indeterminate', checked > 0 && checked < total);
            $('#mat-select-all').prop('checked', checked === total);
        });

        // ── الإكمال التلقائي متعدد الحقول ──────────────────────────────────
        var $input = $('#search_user');
        var $uid   = $('#search_uid');

        $input.autocomplete({
            source: function(request, response) {
                $.ajax({
                    url:  '<?php echo admin_url('admin-ajax.php'); ?>',
                    type: 'GET',
                    data: { action: 'mat_user_suggestions', term: request.term, nonce: '<?php echo esc_js($suggest_nonce); ?>' },
                    success: function(res) { response((res && res.data) ? res.data : []); },
                    error:   function()    { response([]); }
                });
            },
            minLength: 2,
            select: function(event, ui) {
                $input.val(ui.item.user_login);
                $uid.val(ui.item.user_id);
                return false;
            },
            change: function(event, ui) {
                if (!ui.item) $uid.val('');
            }

        // ── تخصيص عرض كل اقتراح ────────────────────────────────────────────
        }).data('ui-autocomplete')._renderItem = function(ul, item) {
            var initials = item.user_login.substring(0, 2).toUpperCase();
            var colors   = ['#2271b1','#00a0d2','#d63638','#dba617','#00ba37','#8c5a8c','#3858e9'];
            var hash     = 0;
            for (var i = 0; i < item.user_login.length; i++)
                hash = item.user_login.charCodeAt(i) + ((hash << 5) - hash);
            var color = colors[Math.abs(hash) % colors.length];

            return $('<li>').append(
                '<div class="mat-ac-row">' +
                    '<div class="mat-ac-top">' +
                        '<div class="mat-ac-avatar" style="background:' + color + '">' + $('<b>').text(initials).html() + '</div>' +
                        '<div>' +
                            '<span class="mat-ac-login">' + $('<b>').text(item.user_login).html() + '</span>' +
                            ' <span class="mat-ac-name">' + $('<span>').text(item.display_name).html() + '</span>' +
                        '</div>' +
                    '</div>' +
                    '<div class="mat-ac-email">' + $('<span>').text(item.user_email).html() + '</div>' +
                '</div>'
            ).appendTo(ul);
        };

        $input.on('input', function() {
            if ($(this).val() === '') $uid.val('');
        });

    });
    </script>
    <?php
}


// ─── AJAX: Multi-field user search suggestions ───────────────────────────────

// Fix: removed wp_ajax_nopriv — only authenticated users can query usernames
add_action('wp_ajax_mat_user_suggestions', 'mat_user_suggestions');

function mat_user_suggestions() {
    // Fix: verify nonce + capability before exposing user data
    if (! check_ajax_referer('mat_user_suggest', 'nonce', false)
        || ! current_user_can('view_moderator_activity')
    ) {
        wp_send_json_error('Unauthorized', 403);
        return;
    }

    global $wpdb;
    $term = sanitize_text_field(wp_unslash($_GET['term'] ?? ''));

    if (strlen($term) < 2) {
        wp_send_json_success([]);
        return;
    }

    $like = '%' . $wpdb->esc_like($term) . '%';

    // Search WordPress users table by login, email, OR display name
    $users = $wpdb->get_results($wpdb->prepare(
        "SELECT ID, user_login, user_email, display_name
           FROM {$wpdb->users}
          WHERE user_login    LIKE %s
             OR user_email    LIKE %s
             OR display_name  LIKE %s
          ORDER BY user_login ASC
          LIMIT 10",
        $like, $like, $like
    ));

    $items = [];
    foreach ($users as $u) {
        $items[] = [
            // 'label' shown in default rendering; 'value' placed in input on select
            'label'        => $u->user_login . ' — ' . $u->display_name . ' <' . $u->user_email . '>',
            'value'        => $u->user_login,
            'user_id'      => (int) $u->ID,
            'user_login'   => $u->user_login,
            'user_email'   => $u->user_email,
            'display_name' => $u->display_name,
        ];
    }

    wp_send_json_success($items);
}



/* ════════════════════════════════════════════════════════════════════════════
   تقرير الـ Upsell — Upsell report (data only, no commission calculation)
   Tracks what each moderator ADDED / REMOVED / CHANGED on existing orders.
════════════════════════════════════════════════════════════════════════════ */

// ─── DB table (created automatically, also for already-activated installs) ──

function mat_upsell_install() {
    if (get_option('mat_upsell_db_ver') === '1.0') return;
    global $wpdb;
    $table   = $wpdb->prefix . 'moderator_upsell';
    $charset = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE $table (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        order_id bigint(20) NOT NULL,
        user_id bigint(20) NOT NULL,
        user_name varchar(255) NOT NULL,
        product_id bigint(20) DEFAULT NULL,
        product_name varchar(255) DEFAULT NULL,
        event_type varchar(20) NOT NULL,
        qty_before int(11) DEFAULT 0,
        qty_after int(11) DEFAULT 0,
        amount_delta decimal(14,2) NOT NULL DEFAULT 0,
        created_at datetime NOT NULL,
        PRIMARY KEY (id),
        KEY idx_order (order_id),
        KEY idx_user_date (user_id, created_at)
    ) $charset;";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
    update_option('mat_upsell_db_ver', '1.0');
}
add_action('admin_init', 'mat_upsell_install');


// ─── Tracking helpers ────────────────────────────────────────────────────────

// Only track moderators working inside wp-admin
function mat_upsell_context_ok() {
    return is_admin() && get_current_user_id(); // all roles
}

// Orders created manually by a moderator have no "original" order → not tracked
// Status slugs (your custom statuses) where order changes must NOT be counted as upsell.
// Add/remove slugs here, or use the 'mat_upsell_excluded_statuses' filter.
function mat_upsell_excluded_statuses() {
    return apply_filters('mat_upsell_excluded_statuses', ['3', '10', '11', '30', '31', '32', '33']);
}

function mat_upsell_order_trackable($order_id) {
    $order = wc_get_order($order_id);
    if (! $order) return false;
    if ($order->get_meta('_mat_manual_order')) return false;

    // Skip orders currently in an excluded status (compare slug without "wc-")
    $status = (string) $order->get_status();
    if (strpos($status, 'wc-') === 0) $status = substr($status, 3);
    return ! in_array($status, array_map('strval', mat_upsell_excluded_statuses()), true);
}

function mat_log_upsell($order_id, $type, $product_id, $name, $qty_before, $qty_after, $delta) {
    global $wpdb;
    $user = wp_get_current_user();
    if (! $user || ! $user->ID) return;
    $wpdb->insert($wpdb->prefix . 'moderator_upsell', [
        'order_id'     => (int) $order_id,
        'user_id'      => (int) $user->ID,
        'user_name'    => $user->user_login,
        'product_id'   => $product_id ? (int) $product_id : null,
        'product_name' => mb_substr((string) $name, 0, 250),
        'event_type'   => $type,
        'qty_before'   => (int) $qty_before,
        'qty_after'    => (int) $qty_after,
        'amount_delta' => (float) $delta,
        'created_at'   => current_time('mysql'),
    ]);
}

// Mark orders a moderator creates by hand from wp-admin (no customer baseline)
add_action('woocommerce_new_order', function ($order_id) {
    if (is_admin() && get_current_user_id()) {
        $order = wc_get_order($order_id);
        if ($order) {
            $order->update_meta_data('_mat_manual_order', 1);
            $order->save_meta_data();
        }
    }
}, 5);

// 1) Product added to an existing order
add_action('woocommerce_new_order_item', function ($item_id, $item, $order_id) {
    if (! mat_upsell_context_ok() || ! ($item instanceof WC_Order_Item_Product)) return;
    if (! mat_upsell_order_trackable($order_id)) return;
    mat_log_upsell($order_id, 'add', $item->get_product_id(), $item->get_name(),
        0, $item->get_quantity(), (float) $item->get_total());
}, 10, 3);

// 2) Product removed from an order
add_action('woocommerce_before_delete_order_item', function ($item_id) {
    if (! mat_upsell_context_ok()) return;
    $item = WC_Order_Factory::get_order_item($item_id);
    if (! $item || ! ($item instanceof WC_Order_Item_Product)) return;
    $order_id = $item->get_order_id();
    if (! mat_upsell_order_trackable($order_id)) return;
    mat_log_upsell($order_id, 'remove', $item->get_product_id(), $item->get_name(),
        $item->get_quantity(), 0, -1 * (float) $item->get_total());
});

// 3) Quantity / price changes when the moderator presses "Save" on the order
function mat_upsell_items_state($order_id) {
    $state = [];
    $order = wc_get_order($order_id);
    if (! $order) return $state;
    foreach ($order->get_items() as $item_id => $item) {
        $state[$item_id] = [
            'product_id' => $item->get_product_id(),
            'name'       => $item->get_name(),
            'qty'        => (int) $item->get_quantity(),
            'total'      => (float) $item->get_total(),
        ];
    }
    return $state;
}

add_action('woocommerce_before_save_order_items', function ($order_id) {
    if (! mat_upsell_context_ok()) return;
    $GLOBALS['mat_items_before'][$order_id] = mat_upsell_items_state($order_id);
}, 10, 1);

add_action('woocommerce_saved_order_items', function ($order_id) {
    if (empty($GLOBALS['mat_items_before'][$order_id])) return;
    if (! mat_upsell_order_trackable($order_id)) return;
    $before = $GLOBALS['mat_items_before'][$order_id];
    $after  = mat_upsell_items_state($order_id);
    foreach ($after as $item_id => $now) {
        if (! isset($before[$item_id])) continue;
        $old   = $before[$item_id];
        $delta = round($now['total'] - $old['total'], 2);
        if ($delta != 0 || $now['qty'] !== $old['qty']) {
            mat_log_upsell($order_id, 'change', $now['product_id'], $now['name'],
                $old['qty'], $now['qty'], $delta);
        }
    }
    unset($GLOBALS['mat_items_before'][$order_id]);
}, 10, 1);


// ─── Menu ────────────────────────────────────────────────────────────────────

add_action('admin_menu', function () {
    add_submenu_page(
        'moderator-activity',
        'تقرير الـ Upsell',
        'تقرير الـ Upsell',
        'view_moderator_activity',
        'moderator-upsell',
        'mat_upsell_report_page'
    );
});


// ─── Data loader (shared by page + CSV export) ───────────────────────────────

function mat_upsell_fetch($filters) {
    global $wpdb;
    $table  = $wpdb->prefix . 'moderator_upsell';
    $where  = 'WHERE 1=1';
    $params = [];
    if ($filters['uid'] > 0)         { $where .= ' AND user_id = %d';      $params[] = $filters['uid']; }
    elseif ($filters['q'] !== '')    { $where .= ' AND user_name LIKE %s'; $params[] = '%' . $wpdb->esc_like($filters['q']) . '%'; }
    if ($filters['start'] !== '')    { $where .= ' AND created_at >= %s';  $params[] = $filters['start'] . ' 00:00:00'; }
    if ($filters['end'] !== '')      { $where .= ' AND created_at <= %s';  $params[] = $filters['end'] . ' 23:59:59'; }
    if ($filters['order'] > 0)       { $where .= ' AND order_id = %d';     $params[] = $filters['order']; }
    $sql  = "SELECT * FROM $table $where ORDER BY created_at DESC LIMIT 5000";
    $rows = $wpdb->get_results($params ? $wpdb->prepare($sql, $params) : $sql);

    // Attach current order status (and filter by it if requested)
    $status_cache = [];
    $out = [];
    foreach ($rows as $r) {
        if (! isset($status_cache[$r->order_id])) {
            $o = wc_get_order($r->order_id);
            $status_cache[$r->order_id] = $o ? $o->get_status() : '';
        }
        $r->order_status = $status_cache[$r->order_id];
        if ($filters['status'] !== '' && $r->order_status !== $filters['status']) continue;
        $out[] = $r;
    }
    return $out;
}

function mat_upsell_read_filters() {
    return [
        'uid'    => isset($_GET['uid'])    ? intval($_GET['uid']) : 0,
        'q'      => isset($_GET['q'])      ? sanitize_text_field(wp_unslash($_GET['q'])) : '',
        'start'  => isset($_GET['start'])  ? sanitize_text_field(wp_unslash($_GET['start'])) : date('Y-m-01', current_time('timestamp')),
        'end'    => isset($_GET['end'])    ? sanitize_text_field(wp_unslash($_GET['end']))   : '',
        'order'  => isset($_GET['order'])  ? intval($_GET['order']) : 0,
        'status' => isset($_GET['ostatus'])? sanitize_text_field(wp_unslash($_GET['ostatus'])) : '',
    ];
}

function mat_upsell_type_label($t) {
    $m = ['add' => 'إضافة منتج', 'remove' => 'حذف منتج', 'change' => 'تعديل كمية/سعر'];
    return isset($m[$t]) ? $m[$t] : $t;
}

function mat_upsell_order_link($order_id) {
    $o = wc_get_order($order_id);
    return $o ? $o->get_edit_order_url() : admin_url('post.php?post=' . intval($order_id) . '&action=edit');
}


// ─── CSV export ──────────────────────────────────────────────────────────────

add_action('admin_init', function () {
    if (! isset($_GET['page'], $_GET['mat_export']) || $_GET['page'] !== 'moderator-upsell') return;
    if (! current_user_can('view_moderator_activity')) return;
    check_admin_referer('mat_upsell_export');

    $rows = mat_upsell_fetch(mat_upsell_read_filters());
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=upsell-report-' . date('Y-m-d') . '.csv');
    echo "\xEF\xBB\xBF"; // BOM so Excel reads Arabic correctly
    $out = fopen('php://output', 'w');
    fputcsv($out, ['المشرف', 'رقم الطلب', 'حالة الطلب', 'المنتج', 'النوع', 'الكمية قبل', 'الكمية بعد', 'فرق المبلغ', 'الوقت']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r->user_name, $r->order_id, mat_map_status_label($r->order_status), $r->product_name,
            mat_upsell_type_label($r->event_type), $r->qty_before, $r->qty_after, $r->amount_delta, $r->created_at,
        ]);
    }
    fclose($out);
    exit;
});


// ─── Report page ─────────────────────────────────────────────────────────────

function mat_upsell_report_page() {
    if (! current_user_can('view_moderator_activity')) {
        wp_die(__('You do not have sufficient permissions.'));
    }
    global $wpdb;
    $f      = mat_upsell_read_filters();
    $rows   = mat_upsell_fetch($f);
    $labels = mat_all_status_labels();
    $mods   = $wpdb->get_results("SELECT DISTINCT user_id, user_name FROM {$wpdb->prefix}moderator_upsell ORDER BY user_name");

    // Aggregate per moderator
    $sum = [];
    foreach ($rows as $r) {
        $u = $r->user_id;
        if (! isset($sum[$u])) {
            $sum[$u] = ['name' => $r->user_name, 'orders' => [], 'added' => 0, 'removed' => 0, 'events' => 0];
        }
        $sum[$u]['orders'][$r->order_id] = true;
        $sum[$u]['events']++;
        if ($r->amount_delta >= 0) $sum[$u]['added']   += $r->amount_delta;
        else                       $sum[$u]['removed'] += $r->amount_delta;
    }
    uasort($sum, function ($a, $b) { return ($b['added'] + $b['removed']) <=> ($a['added'] + $a['removed']); });

    $total_net = 0;
    foreach ($sum as $s) $total_net += $s['added'] + $s['removed'];

    $per_page = 50;
    $page     = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
    $total    = count($rows);
    $pages    = (int) ceil($total / $per_page);
    $slice    = array_slice($rows, ($page - 1) * $per_page, $per_page);

    $base_args = ['page' => 'moderator-upsell', 'uid' => $f['uid'] ?: '', 'q' => $f['q'], 'start' => $f['start'], 'end' => $f['end'],
                  'order' => $f['order'] ?: '', 'ostatus' => $f['status']];
    $export_url = wp_nonce_url(add_query_arg(array_merge($base_args, ['mat_export' => 1]), admin_url('admin.php')), 'mat_upsell_export');
    ?>
    <style>
        .mu-page{direction:rtl;max-width:1400px;font-family:'Segoe UI',Tahoma,Arial,sans-serif;margin-top:20px}
        .mu-page h1{font-size:22px;margin:0 0 4px}
        .mu-sub{color:#787c82;font-size:13px;margin-bottom:18px}
        .mu-card{background:#fff;border:1px solid #dcdcde;border-radius:10px;box-shadow:0 2px 8px rgba(0,0,0,.08);margin-bottom:16px;overflow:hidden}
        .mu-card-h{padding:12px 20px;border-bottom:1px solid #dcdcde;background:#fafafa;font-weight:600;font-size:14px}
        .mu-card-b{padding:16px 20px}
        .mu-filters{display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end}
        .mu-filters label{display:block;font-size:11px;font-weight:600;color:#50575e;margin-bottom:4px}
        .mu-filters input,.mu-filters select{height:36px;border:1px solid #dcdcde;border-radius:6px;padding:0 10px;direction:rtl}
        .mu-btn{height:36px;padding:0 16px;border-radius:6px;border:1px solid #2271b1;background:#2271b1;color:#fff;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center}
        .mu-btn.ghost{background:#fff;color:#2271b1}
        .mu-table{width:100%;border-collapse:collapse}
        .mu-table th{text-align:right;font-size:12px;color:#787c82;padding:10px 14px;background:#fafafa;border-bottom:1px solid #dcdcde;white-space:nowrap}
        .mu-table td{padding:10px 14px;border-bottom:1px solid #f0f0f1;font-size:13px;text-align:right;vertical-align:middle}
        .mu-table tr:hover td{background:#f8fafd}
        .mu-pos{color:#1a7a35;font-weight:600}.mu-neg{color:#d63638;font-weight:600}
        .mu-pill{display:inline-block;padding:2px 9px;border-radius:20px;font-size:12px;background:#f0f0f1}
        .mu-pill.add{background:#edfaef;color:#1a7a35}.mu-pill.remove{background:#fcebeb;color:#b32d2e}.mu-pill.change{background:#fff4e5;color:#996800}
        .mu-total{font-size:13px;color:#50575e;margin-bottom:12px}
        .mu-table-wrap{overflow-x:auto}
        .mu-pager{padding:12px 20px;border-top:1px solid #dcdcde;display:flex;gap:4px;flex-wrap:wrap}
        .mu-pager .page-numbers{padding:4px 10px;border:1px solid #dcdcde;border-radius:6px;text-decoration:none;background:#fff}
        .mu-pager .current{background:#2271b1;color:#fff;border-color:#2271b1}
        .mu-filters .mu-ac-wrap{position:relative}
        .ui-autocomplete{max-height:300px;overflow-y:auto;overflow-x:hidden;border:1px solid #dcdcde!important;border-radius:8px!important;box-shadow:0 4px 16px rgba(0,0,0,.13)!important;padding:4px 0!important;direction:rtl;background:#fff;z-index:99999!important;max-width:340px}
        .ui-menu-item-wrapper{padding:0!important}
        .ui-menu-item-wrapper.ui-state-active{background:#e8f0fa!important;border:none!important;color:inherit!important}
        .mat-ac-row{padding:8px 12px;cursor:pointer;border-bottom:1px solid #f5f5f5}
        .mat-ac-top{display:flex;align-items:center;gap:8px;direction:rtl;justify-content:flex-start}
        .mat-ac-avatar{width:28px;height:28px;border-radius:50%;color:#fff;font-size:11px;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0}
        .mat-ac-login{font-weight:600;font-size:13px;color:#1d2327}
        .mat-ac-name{font-size:12px;color:#787c82}
        .mat-ac-email{font-size:11px;color:#2271b1;margin-top:1px;margin-right:36px;text-align:right;direction:ltr}
        @media(max-width:700px){.mu-page{margin-left:12px}}
    </style>

    <div class="mu-page">
        <h1>تقرير الـ Upsell</h1>
        <div class="mu-sub">ما أضافه أو عدّله أو حذفه كل مشرف على الطلبات (بيانات فقط — بدون حساب عمولة). الطلبات التي ينشئها المشرف يدوياً لا تُحتسب.</div>

        <div class="mu-card">
            <div class="mu-card-h">فلترة التقرير</div>
            <div class="mu-card-b">
                <form method="get" class="mu-filters">
                    <input type="hidden" name="page" value="moderator-upsell">
                    <div><label>المشرف / المستخدم</label>
                        <input type="hidden" id="mu_uid" name="uid" value="<?php echo esc_attr($f['uid'] ?: ''); ?>">
                        <input type="text" id="mu_user" name="q" value="<?php echo esc_attr($f['q']); ?>"
                               placeholder="ابحث بالاسم أو اسم المستخدم أو البريد..." autocomplete="off" style="min-width:260px"></div>
                    <div><label>من تاريخ</label><input type="date" name="start" value="<?php echo esc_attr($f['start']); ?>"></div>
                    <div><label>إلى تاريخ</label><input type="date" name="end" value="<?php echo esc_attr($f['end']); ?>"></div>
                    <div><label>رقم الطلب</label><input type="text" name="order" value="<?php echo esc_attr($f['order'] ?: ''); ?>" style="width:110px"></div>
                    <div><label>الحالة الحالية للطلب</label>
                        <select name="ostatus">
                            <option value="">كل الحالات</option>
                            <?php foreach ($labels as $slug => $label) : ?>
                                <option value="<?php echo esc_attr($slug); ?>" <?php selected($f['status'], $slug); ?>><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select></div>
                    <button type="submit" class="mu-btn">عرض</button>
                    <a class="mu-btn ghost" href="<?php echo esc_url(admin_url('admin.php?page=moderator-upsell')); ?>">إعادة تعيين</a>
                    <a class="mu-btn ghost" href="<?php echo esc_url($export_url); ?>">تصدير CSV</a>
                </form>
            </div>
        </div>

        <div class="mu-card">
            <div class="mu-card-h">ملخص لكل مشرف</div>
            <div class="mu-table-wrap">
            <table class="mu-table">
                <thead><tr>
                    <th>المشرف</th><th>عدد الطلبات</th><th>عدد التعديلات</th>
                    <th>إجمالي الإضافات</th><th>إجمالي النقص</th><th>الصافي</th>
                </tr></thead>
                <tbody>
                <?php if (! $sum) : ?>
                    <tr><td colspan="6" style="text-align:center;padding:30px;color:#787c82">لا توجد بيانات في هذه الفترة.</td></tr>
                <?php else : foreach ($sum as $s) : $net = $s['added'] + $s['removed']; ?>
                    <tr>
                        <td><strong><?php echo esc_html($s['name']); ?></strong></td>
                        <td><?php echo count($s['orders']); ?></td>
                        <td><?php echo (int) $s['events']; ?></td>
                        <td class="mu-pos"><?php echo wc_price($s['added']); ?></td>
                        <td class="mu-neg"><?php echo wc_price($s['removed']); ?></td>
                        <td class="<?php echo $net >= 0 ? 'mu-pos' : 'mu-neg'; ?>"><?php echo wc_price($net); ?></td>
                    </tr>
                <?php endforeach; ?>
                    <tr style="background:#fafafa;font-weight:700">
                        <td colspan="5">الإجمالي الصافي</td>
                        <td class="<?php echo $total_net >= 0 ? 'mu-pos' : 'mu-neg'; ?>"><?php echo wc_price($total_net); ?></td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
            </div>
        </div>

        <div class="mu-card">
            <div class="mu-card-h">تفاصيل التعديلات (<?php echo number_format($total); ?>)</div>
            <div class="mu-table-wrap">
            <table class="mu-table">
                <thead><tr>
                    <th>المشرف</th><th>الطلب</th><th>حالة الطلب</th><th>المنتج</th>
                    <th>النوع</th><th>الكمية</th><th>فرق المبلغ</th><th>الوقت</th>
                </tr></thead>
                <tbody>
                <?php if (! $slice) : ?>
                    <tr><td colspan="8" style="text-align:center;padding:30px;color:#787c82">لا توجد تعديلات.</td></tr>
                <?php else : foreach ($slice as $r) : ?>
                    <tr>
                        <td><?php echo esc_html($r->user_name); ?></td>
                        <td><a href="<?php echo esc_url(mat_upsell_order_link($r->order_id)); ?>" target="_blank">#<?php echo esc_html($r->order_id); ?></a></td>
                        <td><span class="mu-pill"><?php echo esc_html(mat_map_status_label($r->order_status)); ?></span></td>
                        <td><?php echo esc_html($r->product_name); ?></td>
                        <td><span class="mu-pill <?php echo esc_attr($r->event_type); ?>"><?php echo esc_html(mat_upsell_type_label($r->event_type)); ?></span></td>
                        <td><?php echo (int) $r->qty_before; ?> ← <?php echo (int) $r->qty_after; ?></td>
                        <td class="<?php echo $r->amount_delta >= 0 ? 'mu-pos' : 'mu-neg'; ?>"><?php echo wc_price($r->amount_delta); ?></td>
                        <td><?php echo esc_html(date_i18n('j M Y · H:i', strtotime($r->created_at))); ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
            </div>
            <?php if ($pages > 1) : ?>
                <div class="mu-pager">
                    <?php echo paginate_links([
                        'base'      => add_query_arg(array_merge($base_args, ['paged' => '%#%']), admin_url('admin.php')),
                        'format'    => '',
                        'total'     => $pages,
                        'current'   => $page,
                        'prev_text' => '›',
                        'next_text' => '‹',
                    ]); ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <script>
    jQuery(function($) {
        var $in = $('#mu_user'), $uid = $('#mu_uid');
        $in.autocomplete({
            minLength: 2,
            source: function(req, res) {
                $.get('<?php echo esc_js(admin_url('admin-ajax.php')); ?>', {
                    action: 'mat_user_suggestions', term: req.term,
                    nonce: '<?php echo esc_js(wp_create_nonce('mat_user_suggest')); ?>'
                }, function(r) { res(r && r.data ? r.data : []); }).fail(function() { res([]); });
            },
            select: function(e, ui) { $in.val(ui.item.user_login); $uid.val(ui.item.user_id); return false; },
            change: function(e, ui) { if (!ui.item) $uid.val(''); }
        });
        $in.data('ui-autocomplete')._renderItem = function(ul, item) {
            var colors = ['#2271b1','#00a0d2','#d63638','#dba617','#00ba37','#8c5a8c','#3858e9'], hash = 0;
            for (var i = 0; i < item.user_login.length; i++) hash = item.user_login.charCodeAt(i) + ((hash << 5) - hash);
            var color = colors[Math.abs(hash) % colors.length];
            return $('<li>').append(
                '<div class="mat-ac-row"><div class="mat-ac-top">' +
                '<div class="mat-ac-avatar" style="background:' + color + '">' + $('<b>').text(item.user_login.substring(0,2).toUpperCase()).html() + '</div>' +
                '<div><span class="mat-ac-login">' + $('<b>').text(item.user_login).html() + '</span> ' +
                '<span class="mat-ac-name">' + $('<span>').text(item.display_name).html() + '</span></div></div>' +
                '<div class="mat-ac-email">' + $('<span>').text(item.user_email).html() + '</div></div>'
            ).appendTo(ul);
        };
        $in.on('input', function() { $uid.val(''); });
    });
    </script>
    <?php
}
