<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_action('admin_menu', function(){
    add_menu_page('Zewnętrzne Ogłoszenia', 'Zewnętrzne Ogłoszenia', 'manage_options', 'ads_manager', '', 'dashicons-megaphone', 60);
    add_submenu_page('ads_manager', 'Ogłoszenia', 'Ogłoszenia', 'manage_options', 'ads_manager_ads', 'ads_manager_render_ads');
    add_submenu_page('ads_manager', 'Płatności', 'Płatności', 'manage_options', 'ads_manager_payments', 'ads_manager_render_payments');
    add_submenu_page('ads_manager', 'Ustawienia', 'Ustawienia', 'manage_options', 'ads_manager_settings', 'ads_manager_render_settings');
    add_submenu_page('ads_manager', 'Import / Eksport', 'Import / Eksport', 'manage_options', 'ads_manager_import_export', 'ads_manager_render_import_export');
});

function ads_manager_render_ads(){
    if (!current_user_can('manage_options')) wp_die('Brak dostępu');
    global $wpdb;
    $table = $wpdb->prefix . 'ads';
    $users = $wpdb->users;
    $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
    $orderby = isset($_GET['orderby']) ? sanitize_text_field($_GET['orderby']) : 'created_at';
    $order = (isset($_GET['order']) && strtolower($_GET['order'])==='asc') ? 'ASC' : 'DESC';
    $paged = max(1, intval($_GET['paged'] ?? 1));
    $per = 20;
    $offset = ($paged-1)*$per;
    $where = "WHERE status != 'deleted'";
    $params = array();
    if ($search) {
        $where .= " AND ( {$table}.title LIKE %s OR {$users}.user_email LIKE %s )";
        $like = '%' . $wpdb->esc_like($search) . '%';
        $params[] = $like; $params[] = $like;
    }
    $join = "LEFT JOIN {$users} ON {$users}.ID = {$table}.user_id";
    $sql = "SELECT SQL_CALC_FOUND_ROWS {$table}.*, {$users}.user_email FROM {$table} {$join} {$where} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
    $query_params = array_merge($params, array($per, $offset));
    if (!empty($params)) $sql = $wpdb->prepare($sql, $query_params);
    else $sql = $wpdb->prepare($sql, $per, $offset);
    $rows = $wpdb->get_results($sql);
    $total = intval($wpdb->get_var("SELECT FOUND_ROWS()"));
    $total_pages = max(1, ceil($total / $per));
    ?>
    <div class="wrap">
      <h1>Lista ogłoszeń</h1>
      <form method="get" style="margin-bottom:12px;">
        <input type="hidden" name="page" value="ads_manager_ads">
        <input type="text" name="s" placeholder="Szukaj po tytule lub e-mailu" value="<?php echo esc_attr($search); ?>">
        <button class="button">Szukaj</button>
        &nbsp;
        <a class="button" href="<?php echo esc_url(admin_url('admin-post.php?action=ads_export_csv&s='.urlencode($search).'&orderby='.urlencode($orderby).'&order='.urlencode($order))); ?>">Eksportuj CSV</a>
      </form>
      <table class="widefat fixed striped">
        <thead><tr>
          <th><a href="<?php echo esc_url(add_query_arg(array('orderby'=>'id','order'=>$order==='ASC'?'desc':'asc'))); ?>">ID</a></th>
          <th><a href="<?php echo esc_url(add_query_arg(array('orderby'=>'title','order'=>$order==='ASC'?'desc':'asc'))); ?>">Tytuł</a></th>
          <th><a href="<?php echo esc_url(add_query_arg(array('orderby'=>'user_email','order'=>$order==='ASC'?'desc':'asc'))); ?>">E-mail</a></th>
          <th><a href="<?php echo esc_url(add_query_arg(array('orderby'=>'created_at','order'=>$order==='ASC'?'desc':'asc'))); ?>">Data dodania</a></th>
          <th><a href="<?php echo esc_url(add_query_arg(array('orderby'=>'expiration_date','order'=>$order==='ASC'?'desc':'asc'))); ?>">Data ważności</a></th>
          <th>Status</th>
          <th>Akcje</th>
        </tr></thead>
        <tbody>
        <?php if ($rows){ foreach ($rows as $r){ $email = $r->user_email ?: '-'; $row_class = ($r->status !== 'active') ? 'inactive' : ''; ?>
          <tr class="<?php echo esc_attr($row_class); ?>">
            <td><?php echo esc_html($r->id); ?></td>
            <td><?php echo esc_html($r->title); ?></td>
            <td><?php echo esc_html($email); ?></td>
            <td><?php echo esc_html($r->created_at); ?></td>
            <td><?php echo esc_html($r->expiration_date); ?></td>
            <td><?php echo esc_html($r->status); ?></td>
            <td>
              <a class="button" href="<?php echo admin_url('admin.php?page=ads_manager_import_export&edit_id='.intval($r->id)); ?>">Edytuj</a>
              <a class="button" href="<?php echo admin_url('admin-post.php?action=ads_admin_deactivate&id='.intval($r->id).'&_wpnonce='.wp_create_nonce('ads_manager_admin')); ?>">Dezaktywuj</a>
            </td>
          </tr>
        <?php } } else { echo '<tr><td colspan="7">Brak ogłoszeń.</td></tr>'; } ?>
        </tbody>
      </table>
      <div style="margin-top:12px;">
        <?php if ($total_pages>1){ if ($paged>1) echo '<a class="button" href="'.esc_url(add_query_arg('paged',$paged-1)).'">&laquo; Poprzednia</a> '; echo ' Strona '.$paged.' z '.$total_pages; if ($paged<$total_pages) echo ' <a class="button" href="'.esc_url(add_query_arg('paged',$paged+1)).'">Następna &raquo;</a>'; } ?>
      </div>
    </div>
    <?php
}

function ads_manager_render_payments(){
    if (!current_user_can('manage_options')) wp_die('Brak dostępu');
    global $wpdb;
    $table = $wpdb->prefix . 'ads_payments';
    $rows = $wpdb->get_results("SELECT * FROM {$table} ORDER BY created_at DESC LIMIT 500");
    ?>
    <div class="wrap"><h1>Płatności</h1>
    <table class="widefat fixed striped"><thead><tr><th>ID</th><th>Ad ID</th><th>User ID</th><th>Amount</th><th>Currency</th><th>Status</th><th>Data</th></tr></thead><tbody>
    <?php if ($rows){ foreach ($rows as $r){ echo '<tr><td>'.esc_html($r->id).'</td><td>'.esc_html($r->ad_id).'</td><td>'.esc_html($r->user_id).'</td><td>'.esc_html($r->amount).'</td><td>'.esc_html($r->currency).'</td><td>'.esc_html($r->payment_status).'</td><td>'.esc_html($r->created_at).'</td></tr>'; } } else { echo '<tr><td colspan="7">Brak płatności</td></tr>'; } ?>
    </tbody></table></div>
    <?php
}

function ads_manager_render_settings(){
    if (!current_user_can('manage_options')) wp_die('Brak dostępu');
    $opts = get_option('ads_manager_options', array());
    $defaults = array('stripe_mode'=>'test','stripe_test_publishable_key'=>'','stripe_test_secret_key'=>'','stripe_live_publishable_key'=>'','stripe_live_secret_key'=>'','stripe_webhook_secret'=>'','login_redirect'=>'','demo_mode'=>0,'default_expiration_days'=>30);
    $opts = wp_parse_args($opts, $defaults);
    if ($_SERVER['REQUEST_METHOD']==='POST' && check_admin_referer('ads_manager_options')){
        $opt = array();
        $opt['stripe_mode'] = ($_POST['stripe_mode']==='live') ? 'live' : 'test';
        $opt['stripe_test_publishable_key'] = sanitize_text_field($_POST['stripe_test_publishable_key']);
        $opt['stripe_test_secret_key'] = sanitize_text_field($_POST['stripe_test_secret_key']);
        $opt['stripe_live_publishable_key'] = sanitize_text_field($_POST['stripe_live_publishable_key']);
        $opt['stripe_live_secret_key'] = sanitize_text_field($_POST['stripe_live_secret_key']);
        $opt['stripe_webhook_secret'] = sanitize_text_field($_POST['stripe_webhook_secret']);
        $opt['login_redirect'] = sanitize_text_field($_POST['login_redirect']);
        $opt['demo_mode'] = isset($_POST['demo_mode']) ? 1 : 0;
        $opt['default_expiration_days'] = intval($_POST['default_expiration_days'] ?: 30);
        $price = isset($_POST['ads_price_per_ad']) ? floatval(str_replace(',','.',$_POST['ads_price_per_ad'])) : 0; 
        update_option('ads_price_per_ad', $price);
        update_option('ads_manager_options', $opt);
        $opts = $opt;
        echo '<div class="updated"><p>Zapisano ustawienia.</p></div>';
    }
    ?>
    <div class="wrap">
      <h1>Ustawienia Zewnętrzne Ogłoszenia</h1>
      <form method="post">
        <?php wp_nonce_field('ads_manager_options'); ?>
        <table class="form-table">
          <tr><th>Tryb Stripe</th><td><select name="stripe_mode"><option value="test" <?php selected($opts['stripe_mode'],'test'); ?>>Testowy</option><option value="live" <?php selected($opts['stripe_mode'],'live'); ?>>Produkcyjny</option></select></td></tr>
          <tr><th>Stripe Test Publishable Key</th><td><input type="text" name="stripe_test_publishable_key" value="<?php echo esc_attr($opts['stripe_test_publishable_key']); ?>" class="regular-text"></td></tr>
          <tr><th>Stripe Test Secret Key</th><td><input type="text" name="stripe_test_secret_key" value="<?php echo esc_attr($opts['stripe_test_secret_key']); ?>" class="regular-text"></td></tr>
          <tr><th>Stripe Live Publishable Key</th><td><input type="text" name="stripe_live_publishable_key" value="<?php echo esc_attr($opts['stripe_live_publishable_key']); ?>" class="regular-text"></td></tr>
          <tr><th>Stripe Live Secret Key</th><td><input type="text" name="stripe_live_secret_key" value="<?php echo esc_attr($opts['stripe_live_secret_key']); ?>" class="regular-text"></td></tr>
          <tr><th>Stripe Webhook Secret</th><td><input type="text" name="stripe_webhook_secret" value="<?php echo esc_attr($opts['stripe_webhook_secret']); ?>" class="regular-text"></td></tr>
          <tr><th>Tryb demo (pomiń płatności)</th><td><label><input type="checkbox" name="demo_mode" value="1" <?php checked($opts['demo_mode'],1); ?>> Włącz tryb demo (płatności będą pomijane)</label></td></tr>
          <tr><th>Strona po zalogowaniu (URL)</th><td><input type="text" name="login_redirect" value="<?php echo esc_attr($opts['login_redirect']); ?>" placeholder="/moje-ogloszenia/" class="regular-text"></td></tr>
          <tr><th>Domyślna liczba dni ważności</th><td><input type="number" name="default_expiration_days" value="<?php echo esc_attr($opts['default_expiration_days']); ?>" class="small-text"> dni (nowe ogłoszenia będą miały datę ważności ustawioną na tyle dni)</td></tr>
          <tr><th>Cena dodania ogłoszenia (PLN)</th><td><input type="text" name="ads_price_per_ad" value="<?php echo esc_attr(get_option('ads_price_per_ad', '0.00')); ?>" class="regular-text"></td></tr>
        </table>
        <p><button class="button button-primary" type="submit">Zapisz</button></p>
      </form>
    </div>
    <?php
}

function ads_manager_render_import_export(){
    if (!current_user_can('manage_options')) wp_die('Brak dostępu');
    ?>
    <div class="wrap">
      <h1>Import / Eksport</h1>
      <p>Eksportuj wszystkie ogłoszenia do pliku CSV lub importuj plik CSV z ogłoszeniami.</p>
      <form method="get" action="<?php echo admin_url('admin-post.php'); ?>">
        <input type="hidden" name="action" value="ads_export_csv">
        <button class="button">Eksportuj wszystkie</button>
      </form>
      <hr>
      <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" enctype="multipart/form-data">
        <input type="hidden" name="action" value="ads_import_csv">
        <input type="file" name="import_file" accept=".csv">
        <button class="button">Importuj CSV</button>
      </form>
    </div>
    <?php
}
