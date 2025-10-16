<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Admin-post handlers: export/import/deactivate
 */
add_action('admin_post_ads_export_csv', function(){
    if ( ! current_user_can('manage_options') ) wp_die('Brak dostępu');
    global $wpdb;
    $table = $wpdb->prefix . 'ads';
    $rows = $wpdb->get_results("SELECT a.*, u.user_email FROM {$table} a LEFT JOIN {$wpdb->users} u ON u.ID = a.user_id WHERE a.status!='deleted' ORDER BY a.created_at DESC", ARRAY_A);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=ads-export.csv');
    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF");
    fputcsv($out, array('ID','Title','Email','Created At','Expiration Date','Status','Content'));
    foreach ($rows as $r) fputcsv($out, array($r['id'],$r['title'],$r['user_email'],$r['created_at'],$r['expiration_date'],$r['status'],$r['content']));
    fclose($out);
    exit;
});

add_action('admin_post_ads_import_csv', function(){
    if ( ! current_user_can('manage_options') ) wp_die('Brak dostępu');
    if ( ! isset($_FILES['import_file']) ) { wp_redirect(admin_url('admin.php?page=ads_manager_import_export&import=0')); exit; }
    $file = $_FILES['import_file']['tmp_name'];
    if (empty($file) || !file_exists($file)) { wp_redirect(admin_url('admin.php?page=ads_manager_import_export&import=0')); exit; }
    global $wpdb;
    $table = $wpdb->prefix . 'ads';
    if (($handle = fopen($file, "r")) !== FALSE) {
        $header = fgetcsv($handle, 0, ",");
        while (($data = fgetcsv($handle, 0, ",")) !== FALSE) {
            if (!$data) continue;
            $row = array_combine($header, $data);
            $id = isset($row['ID']) ? intval($row['ID']) : 0;
            $email = isset($row['Email']) ? sanitize_email($row['Email']) : '';
            $user_id = 0;
            if ($email) {
                $user = get_user_by('email', $email);
                if ($user) $user_id = $user->ID;
            }
            $title = isset($row['Title']) ? sanitize_text_field($row['Title']) : '';
            $content = isset($row['Content']) ? wp_kses_post($row['Content']) : '';
            $expiration = isset($row['Expiration Date']) ? sanitize_text_field($row['Expiration Date']) : date('Y-m-d H:i:s', strtotime('+30 days'));
            $status = isset($row['Status']) ? sanitize_text_field($row['Status']) : 'active';
            if ($id > 0) {
                $wpdb->update($table, array('user_id'=>$user_id,'title'=>$title,'content'=>$content,'expiration_date'=>$expiration,'status'=>$status), array('id'=>$id), array('%d','%s','%s','%s','%s'), array('%d'));
            } else {
                $wpdb->insert($table, array('user_id'=>$user_id,'title'=>$title,'content'=>$content,'expiration_date'=>$expiration,'status'=>$status), array('%d','%s','%s','%s','%s'));
            }
        }
        fclose($handle);
    }
    wp_redirect(admin_url('admin.php?page=ads_manager_import_export&import=1'));
    exit;
});

add_action('admin_post_ads_admin_deactivate', function(){
    if ( ! current_user_can('manage_options') ) wp_die('Brak dostępu');
    check_admin_referer('ads_manager_admin');
    $id = isset($_REQUEST['id']) ? intval($_REQUEST['id']) : 0;
    if ($id>0) {
        global $wpdb;
        $table = $wpdb->prefix . 'ads';
        $wpdb->update($table, array('status'=>'inactive'), array('id'=>$id), array('%s'), array('%d'));
    }
    wp_redirect(wp_get_referer() ?: admin_url('admin.php?page=ads_manager_ads'));
    exit;
});

/**
 * REST API routes
 */
add_action('rest_api_init', function() {
    global $wpdb;
    $ads_table = $wpdb->prefix . 'ads';
    $payments_table = $wpdb->prefix . 'ads_payments';

    // LOGIN
    register_rest_route('ads/v1', '/login', array(
        'methods' => 'POST',
        'callback' => function( \WP_REST_Request $request ) {
            $params = $request->get_json_params();
            $username = isset($params['username']) ? sanitize_user($params['username']) : '';
            $password = isset($params['password']) ? $params['password'] : '';
            if ( empty($username) || empty($password) ) {
                return new WP_Error('invalid_credentials','Nieprawidłowy login lub hasło.', array('status'=>400));
            }
            $creds = array('user_login'=>$username,'user_password'=>$password,'remember'=>true);
            $user = wp_signon($creds, is_ssl());
            if ( is_wp_error($user) ) {
                return new WP_Error('invalid_credentials','Nieprawidłowy login lub hasło.', array('status'=>401));
            }
            wp_set_current_user($user->ID);
            wp_set_auth_cookie($user->ID, true);
            $opts = get_option('ads_manager_options', array());
            $redirect = isset($opts['login_redirect']) ? $opts['login_redirect'] : '';
            return rest_ensure_response(array('success'=>true,'user_id'=>$user->ID,'redirect'=>$redirect));
        },
        'permission_callback' => '__return_true',
    ));

    // LOGOUT
    register_rest_route('ads/v1', '/logout', array(
        'methods' => 'POST',
        'callback' => function() {
            wp_clear_auth_cookie();
            return rest_ensure_response(array('success'=>true));
        },
        'permission_callback' => '__return_true',
    ));

    // CREATE
    register_rest_route('ads/v1', '/create', array(
        'methods' => 'POST',
        'callback' => function( \WP_REST_Request $request ) use ( $wpdb, $ads_table, $payments_table ) {
            if ( ! is_user_logged_in() ) {
                return new WP_Error('rest_forbidden','Musisz być zalogowany.', array('status'=>401));
            }
            $params = $request->get_json_params();
            $title = isset($params['title']) ? sanitize_text_field($params['title']) : '';
            $content = isset($params['content']) ? wp_kses_post($params['content']) : '';
            $category = isset($params['category']) ? sanitize_text_field($params['category']) : null;
            $user_id = get_current_user_id();
            $opts = get_option('ads_manager_options', array());
            $days = isset($opts['default_expiration_days']) ? intval($opts['default_expiration_days']) : 30;
            $price = floatval(get_option('ads_price_per_ad', 0));
            $demo = isset($opts['demo_mode']) && $opts['demo_mode'] ? true : false;

            if ( $demo || $price <= 0 ) {
                $expiration = date('Y-m-d H:i:s', strtotime('+'. $days .' days'));
                $inserted = $wpdb->insert($ads_table, array('user_id'=>$user_id,'title'=>$title,'content'=>$content,'category_slug'=>$category,'expiration_date'=>$expiration,'status'=>'active'), array('%d','%s','%s','%s','%s','%s'));
                if ( ! $inserted ) return new WP_Error('db_error','Błąd zapisu', array('status'=>500));
                return rest_ensure_response(array('success'=>true,'ad_id'=>$wpdb->insert_id,'expiration_date'=>$expiration,'demo'=>true));
            } else {
                $amount = number_format($price,2,'.','');
                $insert = $wpdb->insert($payments_table, array('ad_id'=>0,'user_id'=>$user_id,'stripe_session_id'=>'','amount'=>$amount,'currency'=>'PLN','payment_status'=>'pending'), array('%d','%d','%s','%f','%s','%s'));
                if ( ! $insert ) return new WP_Error('db_error','Błąd zapisu płatności', array('status'=>500));
                $payment_id = $wpdb->insert_id;
                $publishable = (isset($opts['stripe_mode']) && $opts['stripe_mode'] === 'live') ? $opts['stripe_live_publishable_key'] : $opts['stripe_test_publishable_key'];
                return rest_ensure_response(array('payment_required'=>true,'payment_id'=>$payment_id,'amount'=>$amount,'currency'=>'PLN','stripe_publishable'=>$publishable));
            }
        },
        'permission_callback' => function() {
            if ( ! is_user_logged_in() ) return new WP_Error('rest_forbidden','Musisz być zalogowany.', array('status'=>401));
            $user = wp_get_current_user();
            if ( in_array('ads_user', (array)$user->roles, true) || current_user_can('manage_options') ) return true;
            return new WP_Error('rest_forbidden','Nie masz takiego uprawnienia.', array('status'=>403));
        },
    ));

    // UPDATE
    register_rest_route('ads/v1', '/update/(?P<id>\d+)', array(
        'methods' => 'POST',
        'callback' => function( \WP_REST_Request $request ) use ( $wpdb, $ads_table ) {
            $id = intval($request['id']);
            $params = $request->get_json_params();
            $title = isset($params['title']) ? sanitize_text_field($params['title']) : null;
            $content = isset($params['content']) ? wp_kses_post($params['content']) : null;
            $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$ads_table} WHERE id=%d", $id ) );
            if ( ! $row ) return new WP_Error('not_found','Nie znaleziono ogłoszenia.', array('status'=>404));
            $user = wp_get_current_user();
            if ( $row->user_id != $user->ID && ! current_user_can('manage_options') ) return new WP_Error('rest_forbidden','Brak dostępu', array('status'=>403));
            $data = array(); $format = array();
            if ( null !== $title ) { $data['title'] = $title; $format[] = '%s'; }
            if ( null !== $content ) { $data['content'] = $content; $format[] = '%s'; }
            if ( empty($data) ) return rest_ensure_response(array('message'=>'Brak zmian.'));
            $wpdb->update( $ads_table, $data, array('id'=>$id), $format, array('%d') );
            return rest_ensure_response(array('success'=>true,'message'=>'Zaktualizowano'));
        },
        'permission_callback' => function() {
            if ( ! is_user_logged_in() ) return new WP_Error('rest_forbidden','Musisz być zalogowany.', array('status'=>401));
            $user = wp_get_current_user();
            if ( in_array('ads_user', (array)$user->roles, true) || current_user_can('manage_options') ) return true;
            return new WP_Error('rest_forbidden','Nie masz takiego uprawnienia.', array('status'=>403));
        },
    ));

    // DELETE
    register_rest_route('ads/v1', '/delete/(?P<id>\d+)', array(
        'methods' => 'POST',
        'callback' => function( \WP_REST_Request $request ) use ( $wpdb, $ads_table ) {
            $id = intval($request['id']);
            $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$ads_table} WHERE id=%d", $id ) );
            if ( ! $row ) return new WP_Error('not_found','Nie znaleziono ogłoszenia.', array('status'=>404));
            $user = wp_get_current_user();
            if ( $row->user_id != $user->ID && ! current_user_can('manage_options') ) return new WP_Error('rest_forbidden','Brak dostępu', array('status'=>403));
            $wpdb->update( $ads_table, array('status'=>'deleted'), array('id'=>$id), array('%s'), array('%d') );
            return rest_ensure_response(array('success'=>true,'message'=>'Usunięto.'));
        },
        'permission_callback' => '__return_true',
    ));

    // LIST
    register_rest_route('ads/v1', '/list', array(
        'methods' => 'GET',
        'callback' => function( \WP_REST_Request $request ) use ( $wpdb, $ads_table ) {
            $cat = $request->get_param('category_id');
            $page = max(1, intval($request->get_param('page') ?: 1));
            $per = intval($request->get_param('per_page') ?: 10);
            $offset = ($page - 1) * $per;
            $where = "WHERE status != 'deleted'";
            $params = array();
            if ( $cat ) {
                $where .= " AND category_slug = %s";
                $params[] = $cat;
            }
            $sql = "SELECT * FROM {$ads_table} {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d";
            if ( ! empty( $params ) ) {
                $sql = $wpdb->prepare( $sql, array_merge( $params, array( $per, $offset ) ) );
            } else {
                $sql = $wpdb->prepare( $sql, $per, $offset );
            }
            $rows = $wpdb->get_results( $sql );
            return rest_ensure_response( array( 'items' => $rows ) );
        },
        'permission_callback' => '__return_true',
    ));

    // CHECKOUT - create Stripe Checkout Session
    register_rest_route('ads/v1', '/checkout/(?P<pid>\d+)', array(
        'methods' => 'POST',
        'callback' => function( \WP_REST_Request $request ) use ( $wpdb, $payments_table ) {
            $pid = intval($request['pid']);
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$payments_table} WHERE id=%d", $pid));
            if (!$row) return new WP_Error('not_found','Nie znaleziono płatności', array('status'=>404));
            $opts = get_option('ads_manager_options', array());
            $mode = isset($opts['stripe_mode']) ? $opts['stripe_mode'] : 'test';
            $secret = ($mode==='live') ? $opts['stripe_live_secret_key'] : $opts['stripe_test_secret_key'];
            $publishable = ($mode==='live') ? $opts['stripe_live_publishable_key'] : $opts['stripe_test_publishable_key'];
            if (empty($secret) || empty($publishable)) return new WP_Error('stripe_missing','Brak kluczy Stripe w ustawieniach.', array('status'=>500));
            if (!class_exists('\\Stripe\\Stripe')) {
                return new WP_Error('stripe_lib','Biblioteka stripe-php nie jest zainstalowana na serwerze. Zainstaluj ją (composer require stripe/stripe-php).', array('status'=>500));
            }
            \Stripe\Stripe::setApiKey($secret);
            $params = $request->get_json_params();
            $metadata = array();
            if (isset($params['title'])) $metadata['title'] = sanitize_text_field($params['title']);
            if (isset($params['content'])) $metadata['content'] = wp_strip_all_tags($params['content']);
            if (isset($params['category'])) $metadata['category'] = sanitize_text_field($params['category']);
            if (isset($params['user_id'])) $metadata['user_id'] = intval($params['user_id']);
            try {
                $session = \Stripe\Checkout\Session::create([
                    'payment_method_types' => ['card'],
                    'line_items' => [[ 'price_data' => [ 'currency' => 'pln', 'product_data' => ['name' => 'Opłata za ogłoszenie'], 'unit_amount' => intval(floatval($row->amount) * 100) ], 'quantity' => 1 ]],
                    'mode' => 'payment',
                    'metadata' => $metadata,
                    'success_url' => home_url('/?ads_payment=success&pid='.$pid),
                    'cancel_url' => home_url('/?ads_payment=cancel&pid='.$pid),
                ]);
                $wpdb->update($payments_table, array('stripe_session_id'=>$session->id), array('id'=>$pid), array('%s'), array('%d'));
                return rest_ensure_response(array('session_id'=>$session->id,'stripe_publishable'=>$publishable,'checkout_url'=>$session->url));
            } catch (Exception $e) {
                return new WP_Error('stripe_error','Błąd Stripe: '.$e->getMessage(), array('status'=>500));
            }
        },
        'permission_callback' => '__return_true',
    ));

    // STRIPE WEBHOOK
    register_rest_route('ads/v1', '/stripe-webhook', array(
        'methods' => 'POST',
        'callback' => function( \WP_REST_Request $request ) use ( $wpdb, $payments_table, $ads_table ) {
            $payload = @file_get_contents('php://input');
            $sig_header = isset($_SERVER['HTTP_STRIPE_SIGNATURE']) ? $_SERVER['HTTP_STRIPE_SIGNATURE'] : '';
            $opts = get_option('ads_manager_options', array());
            $secret = isset($opts['stripe_webhook_secret']) ? $opts['stripe_webhook_secret'] : '';
            if (!class_exists('\\Stripe\\Webhook') || empty($secret)) {
                $data = json_decode($payload, true);
                if (isset($data['type']) && $data['type'] === 'checkout.session.completed') {
                    $session = $data['data']['object'];
                    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$payments_table} WHERE stripe_session_id=%s", $session['id']));
                    if ($row) {
                        $wpdb->update($payments_table, array('payment_status'=>'succeeded'), array('id'=>$row->id), array('%s'), array('%d'));
                        if (isset($session['metadata'])) {
                            $meta = $session['metadata'];
                            $title = isset($meta['title']) ? sanitize_text_field($meta['title']) : '';
                            $content = isset($meta['content']) ? wp_kses_post($meta['content']) : '';
                            $category = isset($meta['category']) ? sanitize_text_field($meta['category']) : null;
                            $user_id = isset($meta['user_id']) ? intval($meta['user_id']) : $row->user_id;
                            $opts = get_option('ads_manager_options', array());
                            $days = isset($opts['default_expiration_days']) ? intval($opts['default_expiration_days']) : 30;
                            $expiration = date('Y-m-d H:i:s', strtotime('+'. $days .' days'));
                            $wpdb->insert($ads_table, array('user_id'=>$user_id,'title'=>$title,'content'=>$content,'category_slug'=>$category,'expiration_date'=>$expiration,'status'=>'active'), array('%d','%s','%s','%s','%s','%s'));
                            $ad_id = $wpdb->insert_id;
                            $wpdb->update($payments_table, array('ad_id'=>$ad_id), array('id'=>$row->id), array('%d'), array('%d'));
                        }
                    }
                }
                return rest_ensure_response(array('success'=>true));
            }
            try {
                $event = \Stripe\Webhook::constructEvent($payload, $sig_header, $secret);
                if ($event->type === 'checkout.session.completed') {
                    $session = $event->data->object;
                    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$payments_table} WHERE stripe_session_id=%s", $session->id));
                    if ($row) {
                        $wpdb->update($payments_table, array('payment_status'=>'succeeded'), array('id'=>$row->id), array('%s'), array('%d'));
                        if (isset($session->metadata) && isset($session->metadata->title)) {
                            $title = sanitize_text_field($session->metadata->title);
                            $content = wp_kses_post($session->metadata->content);
                            $category = sanitize_text_field($session->metadata->category);
                            $user_id = $row->user_id;
                            $opts = get_option('ads_manager_options', array());
                            $days = isset($opts['default_expiration_days']) ? intval($opts['default_expiration_days']) : 30;
                            $expiration = date('Y-m-d H:i:s', strtotime('+'. $days .' days'));
                            $wpdb->insert($ads_table, array('user_id'=>$user_id,'title'=>$title,'content'=>$content,'category_slug'=>$category,'expiration_date'=>$expiration,'status'=>'active'), array('%d','%s','%s','%s','%s','%s'));
                            $ad_id = $wpdb->insert_id;
                            $wpdb->update($payments_table, array('ad_id'=>$ad_id), array('id'=>$row->id), array('%d'), array('%d'));
                        }
                    }
                }
            } catch (Exception $e) {
                return new WP_Error('stripe_webhook_error','Webhook error: '.$e->getMessage(), array('status'=>400));
            }
            return rest_ensure_response(array('success'=>true));
        },
        'permission_callback' => '__return_true',
    ));

});
