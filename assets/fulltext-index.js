/* WP Unified Indexer — Fulltext JS
 * v2.7.2 — Autor: Eduardo Vieira
 */
(function($){
  'use strict';

  // Util: chamada REST com fallback para AJAX
  async function callAPI(path, data, ajaxAction){
    // Tenta REST
    try{
      const res = await fetch(WPUI.rest + path, {
        method: 'POST',
        headers: {'Content-Type':'application/json','X-WP-Nonce': WPUI.nonce},
        body: JSON.stringify(data || {})
      });
      if (res.ok) return await res.json();
    }catch(e){ /* cai no AJAX */ }

    // Fallback AJAX WP
    const fd = new FormData();
    fd.append('action', ajaxAction);
    fd.append('nonce', WPUI.ajax_nonce);
    if (data) Object.keys(data).forEach(k => fd.append(k, data[k]));
    const r = await fetch(WPUI.ajax, { method:'POST', body: fd });
    const j = await r.json();
    if (j && j.success) return j.data || j;
    return j;
  }

  function uiToast(msg, type){
    type = type || 'success';
    const $n = $('<div class="wpui-toast '+type+'"></div>').text(msg).appendTo('body');
    setTimeout(()=>{ $n.addClass('show'); }, 10);
    setTimeout(()=>{ $n.removeClass('show'); setTimeout(()=> $n.remove(), 300); }, 2500);
  }

  function setBusy($btn, busy, label){
    if (busy){
      $btn.data('orig', $btn.text());
      $btn.addClass('busy').text((label||'Processando...'));
    } else {
      const o = $btn.data('orig'); if (o) $btn.text(o);
      $btn.removeClass('busy');
    }
  }

  function updateCounters(kind, delta){
    const c = (kind==='ft') ? WPUI.counts_ft : WPUI.counts_st;
    if (delta && typeof delta.indexed === 'number'){
      c.indexed += delta.indexed;
      c.pending = Math.max(0, c.published - c.indexed);
    }
    const wrap = $('.wpui-wrap');
    wrap.find('.wpui-counter-v.published').text(c.published);
    wrap.find('.wpui-counter-v.indexed').text(c.indexed);
    wrap.find('.wpui-counter-v.pending').text(c.pending);
  }

  async function loopIndexAll($btn){
    const kind = 'ft';
    let totalIndexedNow = 0;
    setBusy($btn, true, $btn.data('label') || 'Indexando...');
    while(true){
      const json = await callAPI('fulltext/index-all', {batch:10}, 'wpui_fulltext_index_all');
      const processed = (json && json.processed) ? parseInt(json.processed,10) : 0;
      if (processed>0){
        totalIndexedNow += processed;
        updateCounters(kind, {indexed: processed});
        $btn.text(($btn.data('label') || 'Indexando...')+' '+totalIndexedNow);
      }
      // para quando processar menos que o batch ou quando pendente chegar a zero
      if (processed < 10 || (WPUI.counts_ft.pending <= 0)) break;
    }
    setBusy($btn, false);
    uiToast('Indexação Fulltext concluída.');
    // Atualiza a tabela (simples: recarrega)
    $('.wpui-refresh').trigger('click');
  }

  async function doManual($btn){
    const id  = $('.wpui-id-ft').val().trim();
    const url = $('.wpui-url-ft').val().trim();
    if (!id && !url){ uiToast('Informe ID ou URL.', 'error'); return; }
    setBusy($btn, true, 'Indexando...');
    const json = await callAPI('fulltext/index-one', {id, url, mode:'manual'}, 'wpui_fulltext_index_one');
    setBusy($btn, false);
    if (json && (json.status==='ok' || json.success)){
      updateCounters('ft', {indexed:1});
      uiToast('Indexado com sucesso.');
      $('.wpui-refresh').trigger('click');
    } else if (json && json.status==='already_indexed'){
      if (confirm(WPUI.i18n.already)){
        setBusy($btn, true, 'Reindexando...');
        const re = await callAPI('fulltext/index-one', {id, url, force_reindex: true, mode:'manual'}, 'wpui_fulltext_index_one');
        setBusy($btn, false);
        if (re && (re.status==='ok' || re.success)){
          uiToast('Reindexado com sucesso.');
          $('.wpui-refresh').trigger('click');
        } else {
          uiToast(WPUI.i18n.error, 'error');
        }
      } else {
        uiToast(WPUI.i18n.already, 'info');
      }
    } else {
      uiToast('Falha ao indexar.', 'error');
    }
  }

  function bind(){
    // Indexar tudo (batch 10/10)
    $('.wpui-index-all[data-kind="ft"]').off('click').on('click', function(e){
      e.preventDefault();
      if ($(this).hasClass('busy')) return;
      loopIndexAll($(this));
    });

    // Manual
    $('.wpui-index-manual[data-kind="ft"]').off('click').on('click', function(e){
      e.preventDefault();
      if ($(this).hasClass('busy')) return;
      doManual($(this));
    });

    // Export CSV
    $('.wpui-export[data-kind="ft"]').off('click').on('click', function(e){
      e.preventDefault();
      const url = WPUI.ajax + '?action=wpui_export&type=ft&nonce='+encodeURIComponent(WPUI.ajax_nonce);
      window.location.href = url;
    });

    // Refresh (forçar clean reload da página atual)
    $('.wpui-refresh').off('click').on('click', function(e){
      e.preventDefault();
      const href = $(this).attr('href') || window.location.href;
      window.location.href = href;
    });
  }

  $(document).ready(bind);
})(jQuery);
