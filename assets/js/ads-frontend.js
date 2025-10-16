(function(){
'use strict';

function ensureModal() {
    if (window._ads_modal) return window._ads_modal;
    var modal = document.createElement('div');
    modal.className = 'ads-modal';
    modal.style.display = 'none';
    modal.innerHTML = '<div class="overlay"></div><div class="box"><h4 class="modal-title"></h4><div class="modal-body"></div><p><a class="close">Zamknij</a></p></div>';
    document.body.appendChild(modal);
    modal.querySelector('.overlay').addEventListener('click', function(){ hideModal(); });
    modal.querySelector('.close').addEventListener('click', function(){ hideModal(); });
    window._ads_modal = modal;
    return modal;
}
function showModal(title, html, timeout) {
    var modal = ensureModal();
    modal.querySelector('.modal-title').textContent = title || '';
    var body = modal.querySelector('.modal-body');
    if (typeof html === 'string') body.innerHTML = '<p>' + html + '</p>';
    else { body.innerHTML = ''; body.appendChild(html); }
    modal.style.display = 'flex';
    setTimeout(function(){ modal.classList.add('show'); }, 10);
    if (timeout) setTimeout(hideModal, timeout);
}
function hideModal() {
    var modal = ensureModal();
    modal.classList.remove('show');
    setTimeout(function(){ modal.style.display = 'none'; }, 200);
}
window.adsShowMessage = function(msg, success, timeout){
    var title = success ? 'Sukces' : 'Błąd';
    showModal(title, msg, timeout || 2500);
};
function fetchJson(url, options) {
    options = options || {};
    options.credentials = 'include';
    options.headers = options.headers || {};
    if (!options.headers['Content-Type']) options.headers['Content-Type'] = 'application/json';
    if (typeof adsManager !== 'undefined' && adsManager.nonce) options.headers['X-WP-Nonce'] = adsManager.nonce;
    return fetch(url, options).then(function(resp){ return resp.json().catch(function(){ return resp.text(); }); });
}
function attachFrontendHandlers(context){
    context = context || document;
    var createForm = context.querySelector('#ads-frontend-form');
    if (createForm && !createForm._ads_attached) {
        
createForm.addEventListener('submit', function(e){
    e.preventDefault();
    var title = document.getElementById('ads-frontend-title').value;
    var content = document.getElementById('ads-frontend-content').value;
    var category = document.getElementById('ads-frontend-category').value;
    // send create request
    fetchJson((adsManager && adsManager.root ? adsManager.root : '/wp-json/ads/v1') + '/create', { method: 'POST', body: JSON.stringify({ title: title, content: content, category: category }) })
    .then(function(json){
        if (json && json.success && json.demo) {
            adsShowMessage('Ogłoszenie dodane (tryb demo). ID: ' + json.ad_id, true);
            setTimeout(function(){ location.reload(); }, 900);
            return;
        }
        if (json && json.payment_required) {
            // start checkout: pass metadata to checkout endpoint
            var pid = json.payment_id;
            adsShowMessage('Przekierowanie do płatności...', true, 5000);
            fetchJson((adsManager && adsManager.root ? adsManager.root : '/wp-json/ads/v1') + '/checkout/' + pid, { method: 'POST', body: JSON.stringify({ title: title, content: content, category: category, user_id: (adsManager.user_id || 0) }) })
            .then(function(resp){
                if (resp && resp.checkout_url) {
                    // redirect user to Stripe Checkout
                    window.location.href = resp.checkout_url;
                } else {
                    adsShowMessage((resp && resp.message) || 'Błąd tworzenia sesji płatności', false);
                }
            }).catch(function(){ adsShowMessage('Błąd połączenia z serwerem', false); });
            return;
        }
        if (json && json.success) {
            adsShowMessage('Ogłoszenie dodane. ID: ' + json.ad_id, true);
            setTimeout(function(){ location.reload(); }, 900);
        } else {
            adsShowMessage('Błąd dodawania ogłoszenia', false);
        }
    }).catch(function(){ adsShowMessage('Błąd sieci', false); });
}, false);

        createForm._ads_attached = true;
    }

    var editButtons = context.querySelectorAll('.ads-edit-btn');
    editButtons.forEach(function(btn){
        if (btn._ads_edit_attached) return;
        btn._ads_edit_attached = true;
        btn.addEventListener('click', function(){
            var id = btn.getAttribute('data-id');
            var title = btn.getAttribute('data-title') || '';
            var content = btn.getAttribute('data-content') || '';
            var form = document.createElement('form');
            form.innerHTML = '<p><label>Tytuł:<br><input type="text" name="title" value="'+escapeHtml(title)+'" style="width:100%;"></label></p>' +
                             '<p><label>Treść:<br><textarea name="content" style="width:100%;height:160px;">'+escapeHtml(content)+'</textarea></label></p>' +
                             '<p><button class="ads-btn" type="submit">Zapisz</button> <button type="button" class="ads-btn cancel">Anuluj</button></p>';
            form.querySelector('.cancel').addEventListener('click', function(){ hideModal(); });
            form.addEventListener('submit', function(e){
                e.preventDefault();
                var fd = { title: form.elements['title'].value, content: form.elements['content'].value };
                fetchJson((adsManager && adsManager.root ? adsManager.root : '/wp-json/ads/v1') + '/update/' + id, { method: 'POST', body: JSON.stringify(fd) })
                .then(function(json){
                    if (json && (json.success || json.message==='Zaktualizowano')) {
                        adsShowMessage('Zapisano', true);
                        setTimeout(function(){ location.reload(); }, 700);
                    } else {
                        adsShowMessage((json && json.message) || 'Błąd zapisu', false);
                    }
                }).catch(function(){ adsShowMessage('Błąd sieci', false); });
            });
            showModal('Edytuj ogłoszenie', form);
        });
    });

    var delButtons = context.querySelectorAll('.ads-delete-btn');
    delButtons.forEach(function(btn){
        if (btn._ads_delete_attached) return;
        btn._ads_delete_attached = true;
        btn.addEventListener('click', function(){
            var id = btn.getAttribute('data-id');
            var confirmBox = document.createElement('div');
            confirmBox.innerHTML = '<p>Czy na pewno chcesz usunąć ogłoszenie?</p><p><button class="ads-btn confirm">Usuń</button> <button class="ads-btn cancel">Anuluj</button></p>';
            confirmBox.querySelector('.cancel').addEventListener('click', function(){ hideModal(); });
            confirmBox.querySelector('.confirm').addEventListener('click', function(){
                fetchJson((adsManager && adsManager.root ? adsManager.root : '/wp-json/ads/v1') + '/delete/' + id, { method: 'POST' })
                .then(function(json){
                    if (json && (json.success || json.message==='Usunięto.')) {
                        adsShowMessage('Usunięto', true);
                        setTimeout(function(){ location.reload(); }, 700);
                    } else {
                        adsShowMessage((json && json.message) || 'Błąd usuwania', false);
                    }
                }).catch(function(){ adsShowMessage('Błąd sieci', false); });
            });
            showModal('Potwierdzenie', confirmBox);
        });
    });
}

function escapeHtml(str){ return String(str||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

document.addEventListener('DOMContentLoaded', function(){
    attachFrontendHandlers(document);
    var observer = new MutationObserver(function(muts){
        muts.forEach(function(m){ attachFrontendHandlers(m.target); });
    });
    observer.observe(document.body, { childList:true, subtree:true });
});
})();