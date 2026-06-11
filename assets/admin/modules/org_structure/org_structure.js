(function(){
  'use strict';
  var state = {scale:1, x:0, y:0, dragging:false, sx:0, sy:0, ox:0, oy:0, mobileIndex:0, touchX:0, touchY:0, editMode:false};
  function $(id){return document.getElementById(id);} 
  function nodes(){try{return JSON.parse(($('orgNodesData')||{}).textContent||'[]');}catch(e){return [];}}
  function nodeById(id){id=parseInt(id,10); return nodes().find(function(n){return parseInt(n.id,10)===id;}) || null;}
  function apply(){var c=$('orgCanvas'); if(c){c.style.transform='translate('+state.x+'px,'+state.y+'px) scale('+state.scale+')';} requestAnimationFrame(redrawConnectors);}
  function clampScale(v){return Math.max(.35, Math.min(2.2, v));}
  function openModal(el){if(el){el.classList.add('is-open'); el.setAttribute('aria-hidden','false');}}
  function closeModal(el){if(el){el.classList.remove('is-open'); el.setAttribute('aria-hidden','true');}}
  function setVal(id,v){var el=$(id); if(el){el.value=v == null ? '' : String(v);}}
  function setText(id,v){var el=$(id); if(el){el.textContent=v;}}
  function page(){return document.querySelector('.org-scheme-page');}
  function isEditing(){var p=page(); return !!(p && p.getAttribute('data-org-mode') === 'edit');}
  function canvasPoint(el, root){
    if(!el || !root){return null;}
    var x=0, y=0, n=el;
    while(n && n !== root){
      x += n.offsetLeft || 0;
      y += n.offsetTop || 0;
      n = n.offsetParent;
    }
    if(n !== root){
      var er=el.getBoundingClientRect();
      var rr=root.getBoundingClientRect();
      var scale=state.scale || 1;
      return {left:(er.left-rr.left)/scale, top:(er.top-rr.top)/scale, width:er.width/scale, height:er.height/scale};
    }
    return {left:x, top:y, width:el.offsetWidth || 0, height:el.offsetHeight || 0};
  }
  function centerTop(box){return {x:box.left + box.width/2, y:box.top};}
  function centerBottom(box){return {x:box.left + box.width/2, y:box.top + box.height};}
  function addPath(svg, points){
    if(!svg || !points || points.length < 2){return;}
    var d='M '+points[0].x+' '+points[0].y;
    for(var i=1;i<points.length;i++){d+=' L '+points[i].x+' '+points[i].y;}
    var path=document.createElementNS('http://www.w3.org/2000/svg','path');
    path.setAttribute('class','org-connector-line');
    path.setAttribute('d',d);
    svg.appendChild(path);
  }
  function redrawConnectors(){
    var canvas=$('orgCanvas');
    var svg=$('orgConnectorSvg');
    if(!canvas || !svg){return;}
    svg.innerHTML='';
    var width=Math.max(canvas.scrollWidth || 0, canvas.offsetWidth || 0, 1600);
    var height=Math.max(canvas.scrollHeight || 0, canvas.offsetHeight || 0, 900);
    svg.setAttribute('viewBox','0 0 '+width+' '+height);
    svg.style.width=width+'px';
    svg.style.height=height+'px';

    var founders=[].slice.call(canvas.querySelectorAll('.org-founder-card:not(.is-empty)'));
    var executiveCards=[].slice.call(canvas.querySelectorAll('.org-top-zone .org-top-card:not(.is-empty)'));
    var managedCards=[].slice.call(canvas.querySelectorAll('.org-managed-top-card:not(.is-empty)'));
    var divisions=[].slice.call(canvas.querySelectorAll('.org-division-card'));
    if(!executiveCards.length && !managedCards.length && !divisions.length){return;}

    var divisionBoxes=divisions.map(function(el){return {el:el, box:canvasPoint(el, canvas)};}).filter(function(x){return !!x.box;});
    var divisionTops=divisionBoxes.map(function(x){return centerTop(x.box);});

    // Учредители идут отдельной вертикальной веткой к левому отделению.
    if(founders.length && divisionBoxes.length){
      var founderBox=canvasPoint(founders[0], canvas);
      var firstDivision=divisionBoxes[0].box;
      if(founderBox && firstDivision){
        var f=centerBottom(founderBox);
        var hitY=centerTop(firstDivision).y;
        addPath(svg,[f,{x:f.x,y:hitY}]);
      }
    }

    if(!executiveCards.length || !divisionBoxes.length){return;}

    var executiveBoxes=executiveCards.map(function(el){return canvasPoint(el, canvas);}).filter(Boolean);
    var executiveBottoms=executiveBoxes.map(centerBottom);
    var managedBoxes=managedCards.map(function(el){var group=el.closest ? el.closest('.org-managed-top-group') : null; var cardBox=canvasPoint(el, canvas); var groupBox=canvasPoint(group || el, canvas); return {el:el, group:group||el, box:cardBox, groupBox:groupBox};}).filter(function(x){return !!x.box && !!x.groupBox;});
    var managedTops=managedBoxes.map(function(x){return centerTop(x.box);});

    // Управленческая шина теперь идёт не напрямую ко всем отделениям,
    // а сначала к топ-руководителям и только к свободным отделениям.
    // Так блок топ-руководителя становится отдельным уровнем, как в старой табличной оргсхеме.
    var executiveMaxBottomY=Math.max.apply(null, executiveBottoms.map(function(p){return p.y;}));
    var firstTargetY=divisionTops.length ? Math.min.apply(null, divisionTops.map(function(p){return p.y;})) : executiveMaxBottomY + 120;
    if(managedTops.length){
      firstTargetY=Math.min(firstTargetY, Math.min.apply(null, managedTops.map(function(p){return p.y;})));
    }
    var busY=Math.round(executiveMaxBottomY + Math.max(28, Math.min(76, (firstTargetY - executiveMaxBottomY) * .42)));

    var busTargets=[];
    managedTops.forEach(function(p){busTargets.push(p);});
    divisionBoxes.forEach(function(x){
      var managerId=parseInt(x.el.getAttribute('data-manager-id') || '0', 10) || 0;
      if(!managerId){busTargets.push(centerTop(x.box));}
    });
    if(!busTargets.length){busTargets=divisionTops.slice();}
    var busLeft=Math.min.apply(null, busTargets.map(function(p){return p.x;}));
    var busRight=Math.max.apply(null, busTargets.map(function(p){return p.x;}));
    executiveBottoms.forEach(function(p){addPath(svg,[p,{x:p.x,y:busY}]);});
    addPath(svg,[{x:busLeft,y:busY},{x:busRight,y:busY}]);

    managedBoxes.forEach(function(item){
      var managerId=parseInt(item.el.getAttribute('data-managed-top-id') || '0', 10) || 0;
      var top=centerTop(item.box);
      var bottom=centerBottom(item.groupBox || item.box);
      addPath(svg,[{x:top.x,y:busY},top]);
      var owned=divisionBoxes.filter(function(x){return (parseInt(x.el.getAttribute('data-manager-id') || '0', 10) || 0) === managerId;});
      if(!owned.length){return;}
      var ownedTops=owned.map(function(x){return centerTop(x.box);});
      var ownedY=Math.min.apply(null, ownedTops.map(function(p){return p.y;}));
      var localBusY=Math.round(bottom.y + Math.max(26, Math.min(64, (ownedY - bottom.y) * .42)));
      var leftX=Math.min.apply(null, ownedTops.map(function(p){return p.x;}));
      var rightX=Math.max.apply(null, ownedTops.map(function(p){return p.x;}));
      addPath(svg,[bottom,{x:bottom.x,y:localBusY}]);
      addPath(svg,[{x:leftX,y:localBusY},{x:rightX,y:localBusY}]);
      ownedTops.forEach(function(p){addPath(svg,[{x:p.x,y:localBusY},p]);});
    });

    divisionBoxes.forEach(function(x){
      var managerId=parseInt(x.el.getAttribute('data-manager-id') || '0', 10) || 0;
      if(managerId){return;}
      var p=centerTop(x.box);
      addPath(svg,[{x:p.x,y:busY},p]);
    });
  }
  function setEditMode(enabled){var p=page(); if(!p){return;} state.editMode=!!enabled; p.setAttribute('data-org-mode', enabled ? 'edit' : 'view'); p.classList.toggle('is-editing', !!enabled); p.classList.toggle('is-viewing', !enabled);}

  function validHex(v){return /^#[0-9a-fA-F]{6}$/.test(String(v||'').trim());}
  function setPaletteValue(inputId, value){
    value = String(value || '').trim().toUpperCase();
    if(value && value.charAt(0) !== '#'){value = '#'+value;}
    if(value && !validHex(value)){value='';}
    setVal(inputId, value);
    var palette=document.querySelector('[data-org-palette-for="'+inputId+'"]');
    if(!palette){return;}
    [].slice.call(palette.querySelectorAll('.org-color-dot')).forEach(function(btn){
      btn.classList.toggle('is-selected', !!value && String(btn.getAttribute('data-org-color')||'').toUpperCase()===value);
    });
    var custom=$(inputId+'Custom');
    if(custom){custom.value=value;}
  }
  function bindPalettes(root){
    root = root || document;
    [].slice.call(root.querySelectorAll('[data-org-palette-for]')).forEach(function(palette){
      if(palette.getAttribute('data-org-bound')==='1'){return;}
      palette.setAttribute('data-org-bound','1');
      var inputId=palette.getAttribute('data-org-palette-for');
      palette.addEventListener('click',function(e){
        var colorBtn=e.target.closest('[data-org-color]');
        if(colorBtn){e.preventDefault(); setPaletteValue(inputId, colorBtn.getAttribute('data-org-color')); return;}
        var customBtn=e.target.closest('[data-org-custom-color]');
        if(customBtn){e.preventDefault(); palette.classList.toggle('is-custom-open'); var c=$(inputId+'Custom'); if(c){setTimeout(function(){c.focus();},30);} }
      });
      var custom=$(inputId+'Custom');
      if(custom){
        custom.addEventListener('input',function(){setPaletteValue(inputId, custom.value);});
      }
    });
  }

  function syncNodeModalType(){
    var typeEl=$('orgNodeType');
    var form=typeEl ? typeEl.closest('form') : null;
    var label=$('orgNodeColorLabel');
    var color=$('orgNodeColor');
    var meta=$('orgNodeMetaGrid');
    var sort=$('orgNodeSort');
    var planned=$('orgNodePlanned');
    var isDeputy=!!(typeEl && typeEl.value==='deputy');
    if(form){form.classList.toggle('org-node-modal-is-deputy', isDeputy);}
    if(label){label.hidden=isDeputy; label.style.display=isDeputy?'none':''; label.classList.toggle('org-node-deputy-hidden', isDeputy);}
    if(color){color.disabled=isDeputy; if(isDeputy){color.value='';}}
    if(meta){meta.hidden=isDeputy; meta.style.display=isDeputy?'none':''; meta.classList.toggle('org-node-deputy-hidden', isDeputy);}
    if(sort){sort.disabled=isDeputy; if(isDeputy){sort.value='100';}}
    if(planned){planned.disabled=isDeputy; if(isDeputy){planned.value='';}}
  }

  function bindOrgTooltips(){
    if(document.getElementById('orgFloatingTooltip')){return;}
    var tip=document.createElement('div');
    tip.id='orgFloatingTooltip';
    tip.className='org-floating-tooltip';
    document.body.appendChild(tip);
    function show(el){
      var text=el && el.getAttribute('data-org-tooltip');
      if(!text){return;}
      tip.textContent=text;
      tip.classList.add('is-visible');
      var r=el.getBoundingClientRect();
      var maxLeft=window.innerWidth-24;
      var left=Math.min(Math.max(12, r.left + r.width/2), maxLeft);
      var top=r.top-12;
      tip.style.left=left+'px';
      tip.style.top=top+'px';
      tip.style.transform='translate(-50%, -100%)';
    }
    function hide(){tip.classList.remove('is-visible');}
    document.addEventListener('mouseenter',function(e){
      var el=e.target && e.target.closest ? e.target.closest('.org-node-title[data-org-tooltip]') : null;
      if(el){show(el);}
    },true);
    document.addEventListener('mousemove',function(e){
      var el=e.target && e.target.closest ? e.target.closest('.org-node-title[data-org-tooltip]') : null;
      if(el){show(el);}
    },true);
    document.addEventListener('mouseleave',function(e){
      var el=e.target && e.target.closest ? e.target.closest('.org-node-title[data-org-tooltip]') : null;
      if(el){hide();}
    },true);
    document.addEventListener('scroll',hide,true);
  }

  function syncDirectorAddRequired(){
    var kindEl=$('orgDirectorAddKind');
    var kind=kindEl ? kindEl.value : 'division';
    [].slice.call(document.querySelectorAll('#orgDirectorAddModal [data-director-kind]')).forEach(function(block){
      var active=block.getAttribute('data-director-kind')===kind;
      block.hidden=!active;
      [].slice.call(block.querySelectorAll('input,textarea,select')).forEach(function(field){
        if(field.name==='create_kind'){return;}
        field.disabled=!active;
      });
    });
  }
  window.OrgStructure = window.OrgStructure || {};
  Object.assign(window.OrgStructure, {
    zoomIn:function(){state.scale=clampScale(state.scale+.1);apply();},
    zoomOut:function(){state.scale=clampScale(state.scale-.1);apply();},
    resetCanvas:function(){state={scale:1,x:0,y:0,dragging:false,sx:0,sy:0,ox:0,oy:0,mobileIndex:state.mobileIndex||0,touchX:0,touchY:0};apply();},
    fitCanvas:function(){var shell=$('orgCanvasShell'); var canvas=$('orgCanvas'); if(!shell || !canvas){this.resetCanvas();return;} var target=canvas.querySelector('.org-management-layer') && canvas.querySelector('.org-division-row') ? canvas : (canvas.querySelector('.org-division-row') || canvas); var rect=target.getBoundingClientRect(); var shellRect=shell.getBoundingClientRect(); var rawW=target.scrollWidth || rect.width || 1; var rawH=target.scrollHeight || rect.height || 1; var pad=72; var scale=Math.min((shellRect.width-pad)/rawW,(shellRect.height-pad)/rawH,1.25); state.scale=clampScale(scale); state.x=Math.max(20,(shellRect.width-rawW*state.scale)/2); state.y=Math.max(20,(shellRect.height-rawH*state.scale)/2); apply();},
    openStats:function(){alert('Статистики оргсхемы настроим отдельным шагом.');},
    openDownload:function(){alert('Скачивание оргсхемы настроим отдельным шагом.');},
    openSchemeCkp:function(){if(!isEditing()){return;} openModal($('orgSchemeCkpModal'));},
    closeSchemeCkp:function(){closeModal($('orgSchemeCkpModal'));},
    openRoleEdit:function(id,role){if(!isEditing()){return;} var n=nodeById(id); if(!n){return;} setVal('orgRoleId',n.id);setVal('orgNodeDeleteId',n.id);setVal('orgRoleType',n.type||role||'top_manager');setVal('orgRoleParent',n.parent_id||0);setVal('orgRoleSort',n.sort_order||100);setVal('orgRolePlanned',n.planned_count||'');setVal('orgRoleTitle',n.title||'');setVal('orgRolePerson',n.person_name||'');setVal('orgRoleCkp',n.ckp_text||'');setVal('orgRoleDescription',n.description||'');setPaletteValue('orgRoleColor',n.color||'');var title='Редактировать блок'; if(role==='founder'){title='Учредитель / Совет учредителей';} else if(role==='top_manager'){title='Исполнительный директор';} else if(role==='managed_top'){title='Топ-руководитель';} setText('orgRoleModalTitle',title);var isManaged=(role==='managed_top' && n.type==='top_manager');[].slice.call(document.querySelectorAll('#orgRoleModal .org-managed-role-only')).forEach(function(el){el.hidden=!isManaged; [].slice.call(el.querySelectorAll('input,textarea,select,button')).forEach(function(f){f.disabled=!isManaged;});});var sel=$('orgRoleManagedDivisions'); if(sel){[].slice.call(sel.options).forEach(function(o){var managerId=parseInt(o.getAttribute('data-manager-id')||'0',10)||0; var belongs=managerId===parseInt(n.id,10); o.hidden=!!managerId && !belongs; o.disabled=!!managerId && !belongs; o.selected=belongs;});}openModal($('orgRoleModal'));},
    closeRoleModal:function(){closeModal($('orgRoleModal'));},
    deleteRoleNode:function(){var id=$('orgRoleId') ? $('orgRoleId').value : ''; if(!id){return;} setVal('orgNodeDeleteId',id); var form=$('orgNodeDeleteForm'); if(form && confirm('Удалить топ-руководителя? Отделения останутся на схеме и станут свободными для выбора.')){form.submit();}},
    openDirectorAdd:function(parentId){if(!isEditing()){return;} setVal('orgDirectorAddParent',parentId||0);setVal('orgDirectorAddKind','division');setVal('orgDivisionNumber','');setVal('orgDivisionTitle','');setVal('orgDivisionDepartmentCount','0');setVal('orgDivisionDescription','');setVal('orgDivisionCkp','');setVal('orgDivisionSort','100');setPaletteValue('orgDivisionColor','#FFFFFF');setVal('orgTopTitle','');setVal('orgTopPerson','');setVal('orgTopCkp','');setVal('orgTopDescription','');setVal('orgTopSort','100');setPaletteValue('orgTopColor','#9FC5E8');var sel=$('orgTopDivisions'); if(sel){[].slice.call(sel.options).forEach(function(o){o.selected=false;});} syncDirectorAddRequired();openModal($('orgDirectorAddModal'));},
    closeDirectorAdd:function(){closeModal($('orgDirectorAddModal'));},
    toggleDirectorAddKind:function(){syncDirectorAddRequired();},
    toggleEditMode:function(){setEditMode(!isEditing());},
    saveEditing:function(){setEditMode(false);},
    printScheme:function(){window.print();},
    exportPdf:function(){var el=$('orgCanvas'); if(!el || !window.html2pdf){window.print();return;} var clone=el.cloneNode(true); clone.style.transform='none'; clone.style.padding='20px'; var wrap=document.createElement('div'); wrap.style.background='#fff'; wrap.style.padding='20px'; wrap.appendChild(clone); window.html2pdf().set({margin:8,filename:'org-scheme.pdf',image:{type:'jpeg',quality:.96},html2canvas:{scale:2,useCORS:true},jsPDF:{unit:'mm',format:'a3',orientation:'landscape'}}).from(wrap).save();},
    exportPng:function(){var el=$('orgCanvas'); if(!el){return;} if(typeof window.html2canvas !== 'function'){alert('Экспорт PNG недоступен: html2canvas не загрузился. PDF и печать доступны.');return;} var old=el.style.transform; el.style.transform='none'; window.html2canvas(el,{scale:2,backgroundColor:'#ffffff'}).then(function(canvas){el.style.transform=old; var a=document.createElement('a'); a.download='org-scheme.png'; a.href=canvas.toDataURL('image/png'); a.click();}).catch(function(){el.style.transform=old; alert('Не удалось собрать PNG. Попробуйте PDF.');});},
    openSchemeCreate:function(){setVal('orgSchemeAction','create_scheme');setVal('orgSchemeId','');setVal('orgSchemeTitle','');setVal('orgSchemeDescription','');setVal('orgSchemeParent','0');setVal('orgSchemeStatus','draft');setVal('orgSchemeCkp','');setText('orgSchemeModalTitle','Создать оргсхему');openModal($('orgSchemeModal'));},
    closeSchemeModal:function(){closeModal($('orgSchemeModal'));},
    openSchemeEdit:function(id){var list=window.OrgStructureSchemes||[]; var s=list.find(function(x){return parseInt(x.id,10)===parseInt(id,10);}); if(!s){return;} setVal('orgSchemeAction','update_scheme');setVal('orgSchemeId',s.id);setVal('orgSchemeTitle',s.title||'');setVal('orgSchemeDescription',s.description||'');setVal('orgSchemeParent',s.parent_id||0);setVal('orgSchemeStatus',s.status||'draft');setVal('orgSchemeCkp',s.ckp_text||'');setText('orgSchemeModalTitle','Редактировать оргсхему');openModal($('orgSchemeModal'));},
    openNodeCreate:function(parentId,type){if(!isEditing()){return;} setVal('orgNodeAction','create_node');setVal('orgNodeId','');setVal('orgNodeDeleteId','');setVal('orgNodeParent',parentId||0);setVal('orgNodeType',type||'division');setVal('orgNodeTitle','');setVal('orgNodePerson','');setVal('orgNodeDescription','');setVal('orgNodeCkp','');setVal('orgNodeSort','100');setVal('orgNodePlanned','');setVal('orgNodeColor','');setText('orgNodeModalTitle','Добавить элемент');syncNodeModalType();openModal($('orgNodeModal'));},
    openNodeEdit:function(id){if(!isEditing()){return;} var n=nodeById(id); if(!n){return;} setVal('orgNodeAction','update_node');setVal('orgNodeId',n.id);setVal('orgNodeDeleteId',n.id);setVal('orgNodeParent',n.parent_id||0);setVal('orgNodeType',n.type||'division');setVal('orgNodeTitle',n.title||'');setVal('orgNodePerson',n.person_name||'');setVal('orgNodeDescription',n.description||'');setVal('orgNodeCkp',n.ckp_text||'');setVal('orgNodeSort',n.sort_order||100);setVal('orgNodePlanned',n.planned_count||'');setVal('orgNodeColor',n.color||'');setText('orgNodeModalTitle','Редактировать элемент');syncNodeModalType();openModal($('orgNodeModal'));},
    closeNodeModal:function(){closeModal($('orgNodeModal'));},
    deleteCurrentNode:function(){var id=$('orgNodeDeleteId') ? $('orgNodeDeleteId').value : ''; if(!id){alert('Сначала сохраните элемент.');return;} var form=$('orgNodeDeleteForm'); if(form && confirm('Удалить элемент и все вложенные элементы?')){form.submit();}},
    mobileShow:function(i){var slides=[].slice.call(document.querySelectorAll('[data-mobile-division]')); if(!slides.length){return;} state.mobileIndex=(i+slides.length)%slides.length; slides.forEach(function(s,idx){s.classList.toggle('is-active',idx===state.mobileIndex);}); var c=$('orgMobileCounter'); if(c){c.textContent=(state.mobileIndex+1)+' из '+slides.length;}},
    mobileNext:function(){this.mobileShow(state.mobileIndex+1);},
    mobilePrev:function(){this.mobileShow(state.mobileIndex-1);},
    redrawConnectors:redrawConnectors
  });
  document.addEventListener('DOMContentLoaded',function(){
    bindPalettes(document);
    bindOrgTooltips();
    syncDirectorAddRequired();
    syncNodeModalType();
    var nodeTypeEl=$('orgNodeType'); if(nodeTypeEl){nodeTypeEl.addEventListener('change', syncNodeModalType);}
    setEditMode(false);
    requestAnimationFrame(redrawConnectors);
    setTimeout(redrawConnectors, 120);
    window.addEventListener('resize', redrawConnectors);
    var shell=$('orgCanvasShell');
    if(shell){
      shell.addEventListener('wheel',function(e){e.preventDefault(); var d=e.deltaY>0?-0.08:0.08; state.scale=clampScale(state.scale+d); apply();},{passive:false});
      shell.addEventListener('mousedown',function(e){if(e.button!==0){return;} state.dragging=true; state.sx=e.clientX; state.sy=e.clientY; state.ox=state.x; state.oy=state.y; shell.classList.add('is-dragging');});
      window.addEventListener('mousemove',function(e){if(!state.dragging){return;} state.x=state.ox+(e.clientX-state.sx); state.y=state.oy+(e.clientY-state.sy); apply();});
      window.addEventListener('mouseup',function(){state.dragging=false; shell.classList.remove('is-dragging');});
    }
    var slides=$('orgMobileSlides');
    if(slides){
      slides.addEventListener('touchstart',function(e){var t=e.touches[0]; state.touchX=t.clientX; state.touchY=t.clientY;},{passive:true});
      slides.addEventListener('touchend',function(e){var t=e.changedTouches[0]; var dx=t.clientX-state.touchX; var dy=t.clientY-state.touchY; if(Math.abs(dx)>70 && Math.abs(dx)>Math.abs(dy)*1.35){dx<0?window.OrgStructure.mobileNext():window.OrgStructure.mobilePrev();}},{passive:true});
      window.OrgStructure.mobileShow(0);
    }
  });
})();
