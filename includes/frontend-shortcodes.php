<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Frontend shortcodes for Ads Manager
 *
 * Shortcodes:
 * - [ads_login_form]
 * - [ads_frontend_form]
 * - [ads_my_ads]
 * - [ads_category_ads]  (supports category_slug OR category_id OR uses current queried object slug)
 *
 */

add_action('init', 'ads_manager_register_shortcodes');
function ads_manager_register_shortcodes() {
    add_shortcode('ads_login_form', 'ads_manager_shortcode_login_form');
    add_shortcode('ads_frontend_form', 'ads_manager_shortcode_frontend_form');
    add_shortcode('ads_my_ads', 'ads_manager_shortcode_my_ads');
    add_shortcode('ads_category_ads', 'ads_manager_shortcode_category_ads');
}
function ads_manager_print_modal_once() {
    static $printed = false;
    if ( $printed ) return;
    $printed = true;
    ?>
    <style>
        .ads-modal-backdrop { position: fixed; inset: 0; display: none; align-items: center; justify-content: center; background: rgba(0,0,0,0.45); z-index: 99999; }
        .ads-modal { width: 90%; max-width: 520px; background: #fff; border-radius: 8px; box-shadow: 0 8px 30px rgba(0,0,0,0.2); padding: 18px; transform: translateY(-10px) scale(0.98); opacity: 0; transition: opacity .18s ease, transform .22s cubic-bezier(.2,.9,.3,1); }
        .ads-modal.show { transform: translateY(0) scale(1); opacity: 1; }
        .ads-modal h3 { margin: 0 0 8px 0; font-size: 1.15rem; }
        .ads-modal .ads-modal-body { margin-bottom: 12px; color: #333; max-height: 50vh; overflow:auto; }
        .ads-modal .ads-modal-actions { text-align: right; }
        .ads-btn { display:inline-block; padding: 8px 12px; border-radius:6px; border:0; cursor:pointer; background:#0073aa; color:#fff; font-size:14px; }
        .ads-btn.secondary { background:#666; }
        .ads-msg-success { color: #0a7a1a; }
        .ads-msg-error { color: #a10a1a; }
    </style>

    <div id="ads-modal-backdrop" class="ads-modal-backdrop" aria-hidden="true">
        <div id="ads-modal" class="ads-modal" role="dialog" aria-modal="true" aria-labelledby="ads-modal-title">
            <h3 id="ads-modal-title">Informacja</h3>
            <div id="ads-modal-body" class="ads-modal-body"></div>
            <div class="ads-modal-actions">
                <button id="ads-modal-ok" class="ads-btn">OK</button>
            </div>
        </div>
    </div>

    <script>
        (function(){
            window.adsManagerModals = window.adsManagerModals || {};
            adsManagerModals.show = function(title, message, isSuccess) {
                var backdrop = document.getElementById('ads-modal-backdrop');
                var box = document.getElementById('ads-modal');
                var t = document.getElementById('ads-modal-title');
                var b = document.getElementById('ads-modal-body');
                t.textContent = title || 'Informacja';
                b.innerHTML = '';
                if ( typeof message === 'string' ) {
                    var p = document.createElement('div');
                    p.innerHTML = message;
                    b.appendChild(p);
                } else if ( message instanceof Node ) {
                    b.appendChild(message);
                }
                if ( isSuccess ) b.classList.remove('ads-msg-error'), b.classList.add('ads-msg-success');
                else b.classList.remove('ads-msg-success'), b.classList.add('ads-msg-error');
                backdrop.style.display = 'flex';
                setTimeout(function(){ box.classList.add('show'); },10);
            };
            adsManagerModals.hide = function() {
                var backdrop = document.getElementById('ads-modal-backdrop');
                var box = document.getElementById('ads-modal');
                box.classList.remove('show');
                setTimeout(function(){ backdrop.style.display = 'none'; },240);
            };
            document.addEventListener('DOMContentLoaded', function(){
                var ok = document.getElementById('ads-modal-ok');
                if (ok) ok.addEventListener('click', function(){ adsManagerModals.hide(); });
                var backdrop = document.getElementById('ads-modal-backdrop');
                if (backdrop) backdrop.addEventListener('click', function(e){
                    if (e.target === backdrop) adsManagerModals.hide();
                });
            });
        })();
    </script>
    <?php
}

function ads_manager_rest_root() {
    if ( defined('REST_API_VERSION') ) {
        return rest_url();
    }
    return site_url('/wp-json');
}

function ads_manager_shortcode_login_form($atts){
    ads_manager_print_modal_once();

    if ( is_user_logged_in() ) {
        ob_start(); ?>
        <div class="ads-login-wrap">
            <p>Zalogowany: <?php echo esc_html( wp_get_current_user()->user_login ); ?></p>
            <button id="ads-logout-btn" class="ads-btn">Wyloguj się</button>
        </div>
        <script>
            document.addEventListener('DOMContentLoaded', function(){
                var btn = document.getElementById('ads-logout-btn');
                if (!btn) return;
                btn.addEventListener('click', function(){
                    fetch((window.adsManager && adsManager.root ? adsManager.root : '<?php echo esc_js( rtrim( ads_manager_rest_root(), '/' ) ); ?>') + '/logout', { method:'POST', credentials:'include', headers:{ 'Content-Type':'application/json','X-WP-Nonce': (window.adsManager && adsManager.nonce ? adsManager.nonce : '') } })
                        .then(function(){ location.reload(); }).catch(function(){ location.reload(); });
                });
            });
        </script>
        <?php
        return ob_get_clean();
    }

    ob_start();
    ?>
    <form id="ads-login-form" class="ads-form">
        <h3>Logowanie</h3>
        <p><label>Nazwa użytkownika:<br><input type="text" id="ads-login-username" name="username"></label></p>
        <p><label>Hasło:<br><input type="password" id="ads-login-password" name="password"></label></p>
        <p><button type="submit" class="ads-btn">Zaloguj</button></p>
    </form>

    <script>
        document.addEventListener('DOMContentLoaded', function(){
            var form = document.getElementById('ads-login-form');
            if (!form) return;
            form.addEventListener('submit', function(e){
                e.preventDefault();
                var u = document.getElementById('ads-login-username').value;
                var p = document.getElementById('ads-login-password').value;
                fetch((window.adsManager && adsManager.root ? adsManager.root : '<?php echo esc_js( rtrim( ads_manager_rest_root(), '/' ) ); ?>') + '/login', { method:'POST', credentials:'include', headers:{ 'Content-Type':'application/json' }, body: JSON.stringify({ username: u, password: p }) })
                    .then(function(r){ return r.json(); }).then(function(json){
                    if (json && json.success) {
                        if (json.redirect) window.location.href = json.redirect;
                        else { window.adsManagerModals.show('Sukces','Zalogowano', true); setTimeout(function(){ location.reload(); }, 700); }
                    } else {
                        window.adsManagerModals.show('Błąd','Nieprawidłowy login lub hasło', false);
                    }
                }).catch(function(){ window.adsManagerModals.show('Błąd','Błąd logowania', false); });
            });
        });
    </script>
    <?php
    return ob_get_clean();
}

function ads_manager_shortcode_frontend_form($atts){
    ads_manager_print_modal_once();

    if ( ! is_user_logged_in() ) {
        return '<p>Musisz być zalogowany, aby dodać ogłoszenie. Użyj [ads_login_form].</p>';
    }

    $categories = get_categories( array(
            'orderby' => 'name',
            'order'   => 'ASC',
            'hide_empty' => false
    ) );

    ob_start(); ?>
    <form id="ads-frontend-form" class="ads-form">
        <h3>Dodaj ogłoszenie</h3>
        <p><label>Tytuł:<br><input type="text" id="ads-frontend-title" name="title" required></label></p>
        <p><label>Treść:<br><textarea id="ads-frontend-content" name="content" style="height:160px;" required></textarea></label></p>
        <p>
            <label>Kategoria:<br>
                <select id="ads-frontend-category" name="category" required>
                    <option value="">-- Wybierz kategorię --</option>
                    <?php foreach ( $categories as $cat ) : ?>
                        <option value="<?php echo esc_attr( $cat->slug ); ?>"><?php echo esc_html( $cat->name ); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </p>
        <p><button type="submit" class="ads-btn">Dodaj ogłoszenie</button></p>
    </form>

    <script>
        (function(){
            var createForm = document.getElementById('ads-frontend-form');
            if (!createForm) return;

            function fetchJson(url, opts){
                opts = opts || {};
                opts.credentials = 'include';
                if (!opts.headers) opts.headers = {};
                if (typeof opts.body === 'string') opts.headers['Content-Type'] = 'application/json';
                return fetch(url, opts).then(function(r){ return r.json(); });
            }

            createForm.addEventListener('submit', function(e){
                e.preventDefault();
                var title = document.getElementById('ads-frontend-title').value.trim();
                var content = document.getElementById('ads-frontend-content').value.trim();
                var category = document.getElementById('ads-frontend-category').value;

                if (!title || !content || !category) {
                    window.adsManagerModals.show('Błąd', 'Wszystkie pola są wymagane. Uzupełnij tytuł, treść i wybierz kategorię.', false);
                    return;
                }

                var root = (window.adsManager && adsManager.root ? adsManager.root : '<?php echo esc_js( rtrim( ads_manager_rest_root(), '/' ) ); ?>');

                fetchJson(root + '/create', { method: 'POST', body: JSON.stringify({ title: title, content: content, category: category }) })
                    .then(function(json){
                        if (json && json.success && json.demo) {
                            window.adsManagerModals.show('Sukces', 'Ogłoszenie dodane (tryb demo). ID: ' + json.ad_id, true);
                            setTimeout(function(){ location.reload(); }, 900);
                            return;
                        }
                        if (json && json.payment_required) {
                            var pid = json.payment_id;
                            window.adsManagerModals.show('Płatność', 'Przekierowanie do płatności...', true);
                            fetchJson(root + '/checkout/' + pid, { method: 'POST', body: JSON.stringify({ title: title, content: content, category: category, user_id: (window.adsManager && adsManager.user_id ? adsManager.user_id : 0) }) })
                                .then(function(resp){
                                    if (resp && resp.checkout_url) {
                                        window.location.href = resp.checkout_url;
                                    } else {
                                        window.adsManagerModals.show('Błąd', (resp && resp.message) ? resp.message : 'Błąd tworzenia sesji płatności', false);
                                    }
                                }).catch(function(){ window.adsManagerModals.show('Błąd', 'Błąd komunikacji z serwerem', false); });
                            return;
                        }
                        if (json && json.success) {
                            window.adsManagerModals.show('Sukces', 'Ogłoszenie dodane. ID: ' + json.ad_id, true);
                            setTimeout(function(){ location.reload(); }, 900);
                        } else {
                            window.adsManagerModals.show('Błąd', (json && json.message) ? json.message : 'Błąd dodawania ogłoszenia', false);
                        }
                    }).catch(function(){ window.adsManagerModals.show('Błąd', 'Błąd sieci', false); });
            }, false);
        })();
    </script>
    <?php
    return ob_get_clean();
}

function ads_manager_shortcode_my_ads($atts){
    ads_manager_print_modal_once();

    if ( ! is_user_logged_in() ) return '<p>Musisz się zalogować.</p>';
    global $wpdb;
    $user_id = get_current_user_id();
    $table = $wpdb->prefix . 'ads';
    $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id=%d AND status!='deleted' ORDER BY created_at DESC", $user_id ) );
    ob_start();
    echo '<div class="ads-my-ads">';
    echo '<h3>Moje ogłoszenia</h3>';
    if ( $rows ) {
        echo '<table class="ads-table"><thead><tr><th>Tytuł</th><th>Kategoria</th><th>Data</th><th>Ważne do</th><th>Akcje</th></tr></thead><tbody>';
        foreach ( $rows as $r ) {
            echo '<tr>';
            echo '<td>'.esc_html($r->title).'</td>';
            echo '<td>'.esc_html(isset($r->category_slug) ? $r->category_slug : '').'</td>';
            echo '<td>'.esc_html(date_i18n('Y-m-d', strtotime($r->created_at))).'</td>';
            echo '<td>'.esc_html(date_i18n('Y-m-d', strtotime($r->expiration_date))).'</td>';
            echo '<td><button class="ads-edit-btn ads-btn" data-id="'.esc_attr($r->id).'" data-title="'.esc_attr($r->title).'" data-content="'.esc_attr($r->content).'">Edytuj</button> <button class="ads-delete-btn ads-btn" data-id="'.esc_attr($r->id).'">Usuń</button></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    } else {
        echo '<p>Brak ogłoszeń.</p>';
    }
    echo '</div>';
    return ob_get_clean();
}

function ads_manager_shortcode_category_ads($atts){
    ads_manager_print_modal_once();

    global $wpdb;
    $atts = shortcode_atts(array('category_id'=>'','per_page'=>10,'category_slug'=>''), $atts);
    $ads_table = $wpdb->prefix . 'ads';
    $per = intval($atts['per_page']);
    $offset = 0;
    $where = "WHERE status!='deleted'";
    $params = array();
    $slug = '';
    if ( ! empty($atts['category_slug']) ) {
        $slug = sanitize_text_field($atts['category_slug']);
    } elseif ( ! empty($atts['category_id']) ) {
        $term = get_term(intval($atts['category_id']));
        if ( $term && ! is_wp_error($term) ) $slug = $term->slug;
    } else {
        $queried = get_queried_object();
        if ( $queried && isset($queried->slug) ) $slug = $queried->slug;
    }

    if ( ! empty($slug) ) {
        $where .= " AND category_slug = %s";
        $params[] = $slug;
    }

    $sql = "SELECT * FROM {$ads_table} {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d";
    if ( ! empty( $params ) ) $sql = $wpdb->prepare( $sql, array_merge( $params, array($per, $offset) ) );
    else $sql = $wpdb->prepare( $sql, $per, $offset );
    $rows = $wpdb->get_results( $sql );
    ob_start();
    if ( $rows ) {
        echo '<div class="ads-category-list">';
        for ( $i=0; $i<count($rows); $i++ ) {
            $r = $rows[$i];
            echo '<div class="ads-category-item"><h4>'.esc_html($r->title).'</h4><div class="ads-date">'.esc_html(date_i18n('Y-m-d', strtotime($r->created_at))).'</div><p>'.wp_kses_post(wp_trim_words($r->content, 30)).'</p></div>';
        }
        echo '</div>';
    } else {
        echo '<p>Brak ogłoszeń w tej kategorii.</p>';
    }
    return ob_get_clean();
}