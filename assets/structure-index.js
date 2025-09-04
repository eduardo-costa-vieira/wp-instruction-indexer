/* WP Unified Indexer — Structure JS
 * v2.7.2 — Autor: Eduardo Vieira
 */
(function($){
  'use strict';

  async function callAPI(path, data, ajaxAction){
    try{
      const res = await fetch(WPUI.rest + path, {
        method: 'POST',
        headers: {'Content-Type':'application/json','X-WP-Nonce': WPUI.nonce},
        body: JSON.stringify(data || {})
      });
      if (res.ok) return await res.json();
    }catch(e){}

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

  function updateCounters(kind, delta){
    const c = (kind==='st') ? WPUI.counts_st : WPUI.counts_ft;
    if (delta && typeof delta.indexed === 'number'){
      c.indexed = parseInt(c.indexed, 10) + delta.indexed;
      c.published = parseInt(c.published, 10);
      c.pending = Math.max(0, c.published - c.indexed);
    }
    const wrap = $('.wpui-wrap');
    wrap.find('.wpui-counter-v.published').text(c.published);
    wrap.find('.wpui-counter-v.indexed').text(c.indexed);
    wrap.find('.wpui-counter-v.pending').text(c.pending);
  }

  async function loopIndexAll($btn){
    const kind = 'st';
    let totalIndexedNow = 0;
    let totalSkippedNow = 0;
    let skippedURLs = [];
    setBusy($btn, true, $btn.data('label') || 'Indexando...');
    while(true){
      const json = await callAPI('structure/index-all', {batch:10}, 'wpui_structure_index_all');
      const processed = (json && json.processed) ? parseInt(json.processed,10) : 0;
      const skipped = (json && json.skipped) ? parseInt(json.skipped,10) : 0;
      if (processed>0){
        totalIndexedNow += processed;
        updateCounters(kind, {indexed: processed});
        $btn.text(($btn.data('label') || 'Indexando...')+' '+totalIndexedNow);
      }
      if (skipped>0){
        totalSkippedNow += skipped;
        if (Array.isArray(json.skipped_urls)) skippedURLs = skippedURLs.concat(json.skipped_urls);
      }
      if ((processed + skipped) < 10 || processed === 0 || (WPUI.counts_st.pending <= 0)) break;
    }
    setBusy($btn, false);
    let msg = 'Indexação da Estrutura concluída.';
    if (totalSkippedNow>0){
      msg += ' '+totalSkippedNow+' pulado'+(totalSkippedNow>1?'s':'')+'.';
    }
    uiToast(msg);
    if (totalSkippedNow>0 && skippedURLs.length){
      const list = skippedURLs.map(url=>'<li><a target="_blank" href="'+url+'">'+url+'</a></li>').join('');
      showModal('<p>Posts ignorados por falta de estrutura:</p><ul>'+list+'</ul>');
    }
    $('.wpui-refresh').trigger('click');
  }

  async function doManual($btn){
    const id  = $('.wpui-id-st').val().trim();
    const url = $('.wpui-url-st').val().trim();
    if (!id && !url){ uiToast('Informe ID ou URL.', 'error'); return; }
    setBusy($btn, true, 'Indexando...');
    const json = await callAPI('structure/index-one', {id, url, mode:'manual'}, 'wpui_structure_index_one');
    setBusy($btn, false);
    if (json && (json.status==='ok' || json.success)){
      updateCounters('st', {indexed:1});
      uiToast('Estrutura indexada.');
      $('.wpui-refresh').trigger('click');
    } else if (json && json.status==='no_items'){
      uiToast('Sem estrutura suficiente (mín. 3 itens).', 'error');
    } else {
      uiToast('Falha ao indexar.', 'error');
    }
    $('.wpui-id-st, .wpui-url-st').val('');
  }

  // Expandir: carregar itens por post_id e mostrar modal
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

  async function saveSyn($btn){
    const pid = $btn.data('post');
    const iid = $btn.data('item');
    const $inp = $('.wpui-syn[data-post="'+pid+'"][data-item="'+iid+'"]');
    const val = ($inp.val() || '').trim();

    const fd = new FormData();
    fd.append('action','wpui_structure_update_synonyms');
    fd.append('nonce', WPUI.ajax_nonce);
    fd.append('post_id', pid);
    fd.append('item_id', iid);
    fd.append('synonyms', val);

    const r = await fetch(WPUI.ajax, { method:'POST', body: fd });
    const j = await r.json();
    if (j && j.success){ uiToast(WPUI.i18n.saved); } else { uiToast(WPUI.i18n.error, 'error'); }
  }

  async function delSyn($btn){
    const pid = $btn.data('post');
    const iid = $btn.data('item');

    const fd = new FormData();
    fd.append('action','wpui_structure_delete_item');
    fd.append('nonce', WPUI.ajax_nonce);
    fd.append('post_id', pid);
    fd.append('item_id', iid);

    const r = await fetch(WPUI.ajax, { method:'POST', body: fd });
    const j = await r.json();
    if (j && j.success){
      uiToast(WPUI.i18n.deleted);
      $('.wpui-refresh').trigger('click');
    } else {
      uiToast(WPUI.i18n.error, 'error');
    }
  }

  function bind(){
    // Indexar tudo
    $('.wpui-index-all[data-kind="st"]').off('click').on('click', function(e){
      e.preventDefault();
      if ($(this).hasClass('busy')) return;
      loopIndexAll($(this));
    });

    // Manual
    $('.wpui-index-manual[data-kind="st"]').off('click').on('click', function(e){
      e.preventDefault();
      if ($(this).hasClass('busy')) return;
      doManual($(this));
    });

    // Export CSV
    $('.wpui-export[data-kind="st"]').off('click').on('click', function(e){
      e.preventDefault();
      const url = WPUI.ajax + '?action=wpui_export&type=st&nonce='+encodeURIComponent(WPUI.ajax_nonce);
      window.location.href = url;
    });

    // Expandir (se existir botão/elemento com data-post)
    $(document).on('click', '.wpui-expand', function(e){
      e.preventDefault();
      const pid = $(this).data('post');
      if (!pid){ uiToast('ID inválido.', 'error'); return; }
      expandForPost(pid);
    });

    // Sinônimos
    $(document).on('click', '.wpui-syn-save', function(e){ e.preventDefault(); saveSyn($(this)); });
    $(document).on('click', '.wpui-syn-del',  function(e){ e.preventDefault(); delSyn($(this)); });

    // Refresh
    $('.wpui-refresh').off('click').on('click', function(e){
      e.preventDefault();
      window.location.reload();
    });

    // Search as you type
    $('#wpui-st-search-input').on('keyup', function(){
      const q = $(this).val().toLowerCase();
      $('table.wp-list-table tbody tr').each(function(){
        const t = $(this).find('td.column-post_title').text().toLowerCase();
        $(this).toggle(t.indexOf(q) !== -1);
      });
    });
  }

  $(document).ready(bind);
})(jQuery);
