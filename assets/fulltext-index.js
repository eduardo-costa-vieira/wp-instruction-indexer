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

  function ensureModal(){
    if ($('#wpui-modal').length) return;
    $('body').append(
      '<div id="wpui-modal" class="wpui-modal">'+
        '<div class="wpui-modal-content">'+
          '<span class="wpui-modal-close">&times;</span>'+
          '<div class="wpui-modal-body"><p>Carregando...</p></div>'+
        '</div>'+
      '</div>'
    );
    $('.wpui-modal-close').on('click', ()=> $('#wpui-modal').removeClass('show'));
    $('#wpui-modal').on('click', function(e){ if(e.target===this) $(this).removeClass('show'); });
  }

  function showModal(html){
    ensureModal();
    $('#wpui-modal .wpui-modal-body').html(html);
    $('#wpui-modal').addClass('show');
  }

  async function expandForPost(post_id){
    const fd = new FormData();
    fd.append('action','wpui_structure_items_for_post');
    fd.append('nonce', WPUI.ajax_nonce);
    fd.append('id', post_id);
    const r = await fetch(WPUI.ajax, { method:'POST', body: fd });
    const j = await r.json();
    if (!j || !j.success){ uiToast('Erro ao carregar itens.', 'error'); return; }

    const rows = j.data.items || [];
    if (!rows.length){ showModal('<p>Nenhum item indexado para este post.</p>'); return; }

    let html = '<table class="wpui-modal-table"><thead><tr>'+
      '<th>ID</th><th>Título</th><th>Termos</th><th>Sinônimos</th><th>Link</th>'+
      '</tr></thead><tbody>';
    rows.forEach(it=>{
      html += '<tr>'+
        '<td>'+ (it.item_id||'') +'</td>'+
        '<td>'+ (it.item_title||'') +'</td>'+
        '<td>'+ (it.terms||'') +'</td>'+
        '<td>'+ (it.synonyms||'') +'</td>'+
        '<td>'+(it.url?('<a target="_blank" class="button button-small" href="'+it.url+'">Abrir</a>'):'')+'</td>'+
      '</tr>';
    });
    html += '</tbody></table>';
    showModal(html);
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
      uiToast(WPUI.i18n.already, 'error');
    } else {
      uiToast('Falha ao indexar.', 'error');
    }
    $('.wpui-id-ft, .wpui-url-ft').val('');
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
      window.location.reload();
    });

    // Expandir
    $(document).on('click', '.wpui-expand', function(e){
      e.preventDefault();
      const pid = $(this).data('post');
      if (!pid){ uiToast('ID inválido.', 'error'); return; }
      expandForPost(pid);
    });

    // Search as you type with debounce e submit automático
    const $s = $('#wpui-ft-search-input, #search-input');
    let stTimer;
    $s.on('keyup', function(){
      clearTimeout(stTimer);
      const $form = $(this).closest('form');
      stTimer = setTimeout(()=> $form.submit(), 350);
    });
  }

  $(document).ready(bind);
})(jQuery);
