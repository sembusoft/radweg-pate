'use strict';
const $ = (s, root=document) => root.querySelector(s);
const $$ = (s, root=document) => [...root.querySelectorAll(s)];
const state = { csrf:'', routes:[], entries:[], installed:false, loggedIn:false, tab:'map', coords:null, editing:null, mapData:null, leafletMap:null, layerGroups:{},selectedRoute:'01',visibleRoutes:null,mapShown:false,startTourIds:[] };
// Externe Karten-Ziellinks verwenden ausschließlich echte KML-Wendepunkt-Placemarks. Keine KML-Route wird extern versprochen.
const STATUSES = { gut:'In Ordnung', hinweis:'Hinweis', mangel:'Mangel' };
// Tabler-Icon aus dem Sprite (assets/icons.svg). Nur Namen verwenden, die in scripts/build-icons.mjs stehen –
// scripts/check.mjs prüft das.
const ic=n=>`<svg class="ic" aria-hidden="true"><use href="assets/icons.svg#${n}"/></svg>`;
function esc(x=''){return String(x??'').replace(/[&<>"']/g, c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));}
function textDate(iso){if(!iso) return '';const [y,m,d]=iso.split('-');return `${d}.${m}.${y}`;}
function todayLocal(){const now=new Date();return `${now.getFullYear()}-${String(now.getMonth()+1).padStart(2,'0')}-${String(now.getDate()).padStart(2,'0')}`;}
function toast(message,err=false){const el=$('#toast');el.textContent=message;el.classList.toggle('toast-error',!!err);el.hidden=false;clearTimeout(toast.timer);toast.timer=setTimeout(()=>el.hidden=true,4200);}
async function api(action, method='GET', payload=null){
  const opts={method,credentials:'same-origin',headers:{'X-CSRF-Token':state.csrf}};
  if(payload instanceof FormData){opts.body=payload;}
  else if(method!=='GET'){opts.headers['Content-Type']='application/json';opts.body=JSON.stringify(payload||{});}
  let response;
  try { response=await fetch(`api.php?action=${encodeURIComponent(action)}`,opts); }
  catch(e){throw new Error('Keine Verbindung zum Server. Bitte Internetverbindung prüfen.');}
  const ct=response.headers.get('content-type')||'';
  const data=ct.includes('application/json')?await response.json():null;
  if(!response.ok || !data?.ok){ if(response.status===401){state.loggedIn=false;showAuth(true);} throw new Error(data?.error||'Serverfehler: '+response.status); }
  return data;
}
async function boot(){
  try{
    const s=await api('status');state.csrf=s.csrf;state.installed=s.installed;state.loggedIn=s.loggedIn;
    if(s.loggedIn){await loadData();showMain();await loadMap();}else showAuth(false);
  }catch(err){showAuth(false);$('#auth-error').textContent=err.message;$('#auth-error').hidden=false;}
}
function showAuth(force){$('#auth').hidden=false;$('#main').hidden=true;
 const setup=!state.installed;
 $('#auth-title').textContent=setup?'Einmalig einrichten':'Willkommen zurück';
 $('#auth-desc').textContent=setup?'Einrichtungscode eingeben und dein persönliches Passwort festlegen.':'Melde dich an, um Touren und Tagebuch zu öffnen.';
 $('#setup-code-wrap').hidden=!setup;$('#setup-code').required=setup;
 $('#auth-password').setAttribute('autocomplete',setup?'new-password':'current-password');
 $('#auth-submit').textContent=setup?'App einrichten':'Anmelden';
 if(force)toast('Bitte erneut anmelden.',true);
}
function showMain(){ $('#auth').hidden=true;$('#main').hidden=false;render();selectTab(state.tab); }
async function loadData(){const data=await api('bootstrap');state.routes=data.routes;state.entries=data.entries;state.entries.sort((a,b)=>b.date.localeCompare(a.date)||b.createdAt.localeCompare(a.createdAt));}
async function refresh(){await loadData();render();}
function selectTab(tab){state.tab=tab;setTimeout(updateTripBanner);state.mapShown=tab==='map';$$('.tab').forEach(b=>{const on=b.dataset.tab===tab;b.classList.toggle('active',on);if(on)b.setAttribute('aria-current','page');else b.removeAttribute('aria-current');});$$('.view').forEach(v=>v.hidden=v.id!==tab+'-view');window.scrollTo({top:0});if(tab==='map'&&state.mapData){renderMap();setTimeout(()=>{state.leafletMap?.invalidateSize();zoomMap();},90);}}
function entryRoutes(e){return Array.isArray(e.tourIds)&&e.tourIds.length?e.tourIds:[e.tourId];}
function entryIssues(e){return Array.isArray(e.issues)?e.issues:((e.status==='mangel'||e.status==='hinweis'||e.issueKind||e.place)?[{id:'i_'+e.id,tourId:e.tourId,kind:ISSUE_KINDS.includes(e.issueKind)?e.issueKind:'Sonstiges',place:e.place||'',description:'',status:'offen',coords:e.coords||null,photos:[]}]:[]);}
function counts(tourId){return state.entries.filter(e=>entryRoutes(e).includes(tourId));}
const ISSUE_KINDS=['Belag','Beschilderung','Hindernis','Sicherheit','Verschmutzung','Bewuchs','Sonstiges'];
const ISSUE_STATES={offen:'Offen',gemeldet:'Gemeldet',bearbeitung:'In Bearbeitung',erledigt:'Erledigt'};
function issueCount(e){return entryIssues(e).length;}
function isPending(issue){return (issue.status||'offen')!=='erledigt';}
function pendingIssues(ids){
  const chosen=new Set(ids),out=[];
  for(const entry of state.entries)for(const issue of entryIssues(entry)){
    if(chosen.has(issue.tourId) && isPending(issue))out.push({entry,issue});
  }
  return out.sort((a,b)=>a.entry.date.localeCompare(b.entry.date));
}
function allIssues(){return state.entries.flatMap(e=>entryIssues(e).map(issue=>({entry:e,issue})));}

function newIssueId(){return 'i_'+[...crypto.getRandomValues(new Uint8Array(12))].map(b=>b.toString(16).padStart(2,'0')).join('');}
function routeCard(r){
 const entries=counts(r.id), recent=entries[0], tourIssueCount=entries.reduce((n,e)=>n+entryIssues(e).filter(i=>i.tourId===r.id).length,0),openCount=pendingIssues([r.id]).length;
 const hasMap=typeof routeLines==='function' && routeLines(r.id).length>0;
 const mapLinks=hasMap?`<button class="btn outline small" type="button" data-map-choose="${r.id}">${ic('map')} In Karte zeigen</button><a class="btn outline small" href="${mapDownloadUrl(r.id)}">${ic('download')} GPX für Naviki</a>`:'<p class="map-empty">Noch keine KML-Linie zugeordnet</p>';
 return `<article class="route-card">
  <div class="route-top"><div class="route-icon ${r.type==='runde'?'pink':''}">${ic(r.type==='runde'?'route':'bike')}</div><div class="route-heading"><span class="route-num">TOUR ${esc(r.id)}</span><h3>${esc(r.name)}</h3><p>${esc(r.area)}</p></div><button type="button" class="icon-button small route-edit" data-route-edit="${r.id}" aria-label="Tour ${esc(r.id)} bearbeiten" title="Tour bearbeiten">${ic('pencil')}</button></div>
  <div class="route-meta"><span class="chip ${r.type==='runde'?'pink':''}">${r.type==='runde'?'Rundtour':'Abfahrt bis Gemeindegrenze'}</span><span class="chip">${r.km?`${Number(r.km).toLocaleString('de-DE')} km (manuell)`:(hasMap?`KML: ca. ${routeLineKm(r.id).toFixed(1).replace('.',',')} km`:'km ungeprüft')}</span><span class="chip">${entries.length} ${entries.length===1?'Prüfung':'Prüfungen'}</span>${tourIssueCount?`<span class="chip">${tourIssueCount} Mängel gesamt</span>`:''}${openCount?`<span class="chip">${openCount} offen</span>`:''}</div>
  <div class="route-actions"><button type="button" class="btn primary small" data-tour-start="${r.id}">${ic('bike')} Tour starten</button><button type="button" class="btn outline small" data-new-entry="${r.id}">${ic('plus')} Fahrt eintragen</button>${mapLinks}</div>
  ${recent?`<p class="route-note">Zuletzt geprüft: ${textDate(recent.date)} · ${esc(STATUSES[recent.status])}</p>`:`<p class="route-note">Noch kein Eintrag · Startpunkt Flurweg 7</p>`}
 </article>`;
}
function render(){
 $('#stat-routes').textContent=state.routes.length;
 $('#stat-entries').textContent=state.entries.length;
 $('#stat-entries-label').textContent=state.entries.length===1?'Prüfung':'Prüfungen';
 const openIssues=allIssues().filter(item=>isPending(item.issue)).length;
 $('#stat-issues').textContent=openIssues;
 $('#kpi-issues').classList.toggle('kpi-warn',openIssues>0);
 $('#kpi-issues').classList.toggle('kpi-ok',openIssues===0);
 $('#route-list').innerHTML=state.routes.map(routeCard).join('');
 const choices=state.routes.map(r=>`<option value="${r.id}">${esc(r.id)} · ${esc(r.name)}</option>`).join('');
 const previous=$('#filter-tour').value;
 $('#filter-tour').innerHTML='<option value="">Alle Touren</option>'+choices;
 $('#filter-tour').value=previous;
 $('#entry-routes').innerHTML=state.routes.map(r=>`<label class="entry-route-option"><input type="checkbox" name="tourIds" value="${esc(r.id)}"><span>${esc(r.id)} · ${esc(r.name)}</span></label>`).join('');
 if(state.mapData)renderMapSettings();
 renderDiary();if(state.mapData)renderMap();
 if(state.tab==='trip')renderTrip();
 updateTripBanner();
}
function renderDiary(){
 const tour=$('#filter-tour').value,status=$('#filter-status').value;
 const statusMatch=e=>{const issues=entryIssues(e);
   if(status==='offen')return issues.some(isPending);
   if(status==='mangel')return issues.length>0;
   if(status==='gut')return issues.length===0;
   return true;};
 const matches=state.entries.filter(e=>(!tour||entryRoutes(e).includes(tour))&&statusMatch(e));
 const routes=new Map(state.routes.map(r=>[r.id,r]));
 $('#diary-list').innerHTML=matches.length?matches.map(e=>{
   const ids=entryRoutes(e),issues=entryIssues(e),pendingCount=issues.filter(isPending).length;
   const routeNames=ids.map(id=>esc(id+' · '+(routes.get(id)?.name||'Tour'))).join(' · ');
   const photoHTML=(e.photos||[]).length?`<div class="photos-row">${e.photos.map(p=>`<a href="api.php?action=photo&id=${p.id}" target="_blank" rel="noopener noreferrer"><img src="api.php?action=photo&id=${p.id}&thumb=1" alt="Foto: ${esc(p.name)}" width="102" height="88" loading="lazy"></a>`).join('')}</div>`:'';
   const pos=e.coords?`<a class="text-btn" href="https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(e.coords.lat+','+e.coords.lon)}" rel="noopener noreferrer" target="_blank">${ic('map-pin')} Fahrt-GPS</a>`:'';
   const issueTable=issues.length?`<div class="issue-list"><strong>${issues.length} ${issues.length===1?'Mangel / Beobachtung':'Mängel / Beobachtungen'}</strong>${issues.map((issue,i)=>{
     const point=issue.coords?`<a class="text-btn" target="_blank" rel="noopener noreferrer" href="https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(issue.coords.lat+','+issue.coords.lon)}">${ic('map-pin')} GPS</a>`:'';
     const pics=(issue.photos||[]).length?`<div class="photos-row">${issue.photos.map(photo=>`<a href="api.php?action=photo&id=${photo.id}" target="_blank" rel="noopener noreferrer"><img src="api.php?action=photo&id=${photo.id}&thumb=1" width="86" height="72" loading="lazy" alt="Mängelfoto ${esc(photo.name)}"></a>`).join('')}</div>`:'';
     const isDone=!isPending(issue);
     const quickAction=`<button class="btn outline small issue-quick-btn" type="button" data-issue-toggle="${esc(e.id)}:${esc(issue.id)}" data-target-status="${isDone?'offen':'erledigt'}">${isDone?ic('arrow-back-up')+' Wieder öffnen':ic('check')+' Als erledigt markieren'}</button>`;
     return `<div class="issue-list-row"><div class="issue-list-head"><span><b>${i+1}. ${issue.time?esc(issue.time)+' · ':''}${esc(issue.tourId)} · ${esc(issue.kind)}</b> — ${esc(issue.place||'Ort nicht angegeben')}</span><span class="issue-state ${esc(issue.status||'offen')}">${esc(ISSUE_STATES[issue.status]||'Offen')}</span></div>${issue.description?`<p class="entry-notes">${esc(issue.description)}</p>`:''}${issue.completedAt&&isDone?`<p class="smallnote">Erledigt: ${new Date(issue.completedAt).toLocaleDateString('de-DE')}</p>`:''}${point}${pics}<div class="issue-inline-actions">${quickAction}</div></div>`;
   }).join('')}</div>`:'';
   return `<article class="entry-card"><div class="entry-head"><div><span class="route-num">${textDate(e.date)}${e.startTime?' · '+esc(timeSpan(e)):''} · ${ids.length} ${ids.length===1?'TOUR':'TOUREN'}</span><h3>${routeNames}</h3><p class="entry-sub">Kontrollfahrt · ${issues.length} ${issues.length===1?'Einzelmangel':'Einzelmängel'}</p></div>${e.running?`<span class="status hinweis">${ic('bike')} läuft</span>`:''}<span class="status ${pendingCount?'mangel':'gut'}">${pendingCount?ic('alert-triangle')+' '+pendingCount+' offen':ic('circle-check')+(issues.length?' Alle erledigt':' In Ordnung')}</span></div>${e.notes?`<p class="entry-notes">${esc(e.notes)}</p>`:''}${issueTable}${photoHTML}${pos}<div class="entry-bottom">${e.running?`<button class="btn primary small" type="button" data-trip-open="${e.id}">${ic('bike')} Fortsetzen</button>`:''}<button class="text-btn" type="button" data-entry-edit="${e.id}">${ic('pencil')} Bearbeiten</button><button class="text-btn" type="button" data-entry-delete="${e.id}">${ic('trash')} Löschen</button></div></article>`;
 }).join(''):'<div class="empty">Hier stehen deine Fahrten, sobald du die erste Strecke dokumentiert hast.</div>';
}
// ── Laufende Kontrollfahrt ─────────────────────────────────────────────
// Eine Fahrt wird beim Start angelegt (running=true); Mängel werden einzeln gespeichert
// (issueSave), die Seite bleibt offen. "Fahrt beenden" setzt Endzeit und Notiz.
const nowTime=()=>{const d=new Date();return `${String(d.getHours()).padStart(2,'0')}:${String(d.getMinutes()).padStart(2,'0')}`;};
const mapsLink=c=>`https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(c.lat+','+c.lon)}`;
function runningTrip(){return state.entries.find(e=>e.running);}
function tripEntry(){return state.entries.find(e=>e.id===state.trip);}
function tourLabel(ids){return ids.map(id=>`${id} · ${routeById(id)?.name||'Tour'}`).join(' / ');}
function timeSpan(e){return e.startTime?(e.endTime?`${e.startTime}–${e.endTime}`:`ab ${e.startTime}`):'';}
function tripNavigationHtml(ids){
 if(ids.length===1){
   const maps=routeMaps(ids[0]);
   return `<div class="start-navigation-links">${maps?`<a class="btn outline small" target="_blank" rel="noopener noreferrer" href="${esc(maps.google)}">${ic('navigation')} Google · Ziel</a><a class="btn outline small" target="_blank" rel="noopener noreferrer" href="${esc(maps.apple)}">${ic('navigation')} Apple · Ziel</a>`:''}<a class="btn outline small" href="${mapDownloadUrl(ids[0])}">${ic('download')} GPX · Naviki</a><button class="btn outline small" type="button" data-show-map-route="${esc(ids[0])}">${ic('map')} Auf der Karte</button></div>${maps?'<p class="smallnote">Google/Apple berechnen eine eigene Route zum Wendepunkt, nicht die Original-KML-Strecke.</p>':''}`;
 }
 const check=comboCheck(ids);
 return `<div class="start-navigation-links">${check.ok?`<a class="btn outline small" href="api.php?action=gpx&ids=${encodeURIComponent(ids.join(','))}">${ic('download')} Kombinierte GPX für Naviki</a>`:'<span class="smallnote">GPX-Kombination nur möglich, wenn die Strecken ohne erfundene Übergänge zusammenpassen.</span>'}<button class="btn outline small" type="button" data-show-selected-map>${ic('map')} Touren auf der Karte</button></div>`;
}
function photoThumbs(photos,max=5){return (photos||[]).slice(0,max).map(p=>`<a href="api.php?action=photo&id=${esc(p.id)}" target="_blank" rel="noopener noreferrer"><img src="api.php?action=photo&id=${esc(p.id)}&thumb=1" loading="lazy" alt="Foto ${esc(p.name)}" width="96" height="76"></a>`).join('');}
function openIssueCard(entry,issue){
 const gps=issue.coords?`<a class="text-btn" target="_blank" rel="noopener noreferrer" href="${mapsLink(issue.coords)}">${ic('map-pin')} Standort</a>`:'<span class="smallnote">Kein GPS-Punkt</span>';
 const pics=photoThumbs(issue.photos);
 return `<article class="pending-card"><div class="pending-top"><strong>${esc(issue.tourId)} · ${esc(issue.kind||'Sonstiges')}</strong><span class="issue-state ${esc(issue.status||'offen')}">${esc(ISSUE_STATES[issue.status]||'Offen')}</span></div><p class="pending-place">${esc(issue.place||'Ort nicht angegeben')}</p>${issue.description?`<p class="pending-desc">${esc(issue.description)}</p>`:''}<p class="smallnote">Erfasst am ${textDate(entry.date)}${issue.time?' · '+esc(issue.time)+' Uhr':''}</p>${pics?`<div class="photos-row pending-photos">${pics}</div>`:''}<div class="pending-bottom">${gps}<button class="btn primary small" type="button" data-issue-toggle="${esc(entry.id)}:${esc(issue.id)}" data-target-status="erledigt">${ic('check')} Erledigt</button></div></article>`;
}
function tripIssueCard(entry,issue){
 const gps=issue.coords?`<a class="text-btn" target="_blank" rel="noopener noreferrer" href="${mapsLink(issue.coords)}">${ic('map-pin')} Standort</a>`:'<span class="smallnote">Kein GPS-Punkt</span>';
 const pics=photoThumbs(issue.photos);
 return `<article class="pending-card trip-issue"><div class="pending-top"><strong>${issue.time?`<span class="issue-time">${ic('clock')} ${esc(issue.time)}</span> `:''}${esc(issue.tourId)} · ${esc(issue.kind||'Sonstiges')}</strong><button type="button" class="icon-button small" data-trip-issue-edit="${esc(issue.id)}" aria-label="Mangel bearbeiten" title="Mangel bearbeiten">${ic('pencil')}</button></div><p class="pending-place">${esc(issue.place||'Ort nicht angegeben')}</p>${issue.description?`<p class="pending-desc">${esc(issue.description)}</p>`:''}${pics?`<div class="photos-row pending-photos">${pics}</div>`:''}<div class="pending-bottom">${gps}<span class="issue-state ${esc(issue.status||'offen')}">${esc(ISSUE_STATES[issue.status]||'Offen')}</span></div></article>`;
}
function renderTrip(){
 const e=tripEntry();
 if(!e){if(state.tab==='trip')selectTab('diary');return;}
 const ids=entryRoutes(e);
 $('#trip-state').textContent=e.running?'Kontrollfahrt läuft':'Kontrollfahrt abgeschlossen';
 $('#trip-title').textContent=tourLabel(ids);
 if(document.activeElement!==$('#trip-date'))$('#trip-date').value=e.date||'';
 if(document.activeElement!==$('#trip-start'))$('#trip-start').value=e.startTime||'';
 $('#trip-navigation').innerHTML=tripNavigationHtml(ids);
 const own=entryIssues(e).slice().sort((a,b)=>(b.time||'').localeCompare(a.time||''));
 $('#trip-new-count').textContent=own.length;
 $('#trip-issue-list').innerHTML=own.length?own.map(i=>tripIssueCard(e,i)).join(''):'<p class="smallnote">Noch nichts erfasst. „Mangel erfassen“ unten legt den ersten an – Uhrzeit und Standort werden dabei gleich übernommen.</p>';
 const open=pendingIssues(ids).filter(x=>x.entry.id!==e.id);
 $('#trip-open-count').textContent=`${open.length} offen`;$('#trip-open-count').classList.toggle('none',open.length===0);
 $('#trip-open-list').innerHTML=open.length?open.map(({entry,issue})=>openIssueCard(entry,issue)).join(''):'<div class="no-pending">'+ic('circle-check')+' Für diese Touren sind keine älteren Mängel offen.</div>';
 $('#trip-finish').hidden=!e.running;$('#trip-add-issue').hidden=!e.running;
}
function updateTripBanner(){
 const run=runningTrip(),b=$('#trip-banner');
 b.hidden=!run||(state.tab==='trip'&&state.trip===run.id);
 if(run)$('#trip-banner-text').textContent=`Kontrollfahrt läuft seit ${run.startTime||'?'} · ${entryRoutes(run).join(', ')}`;
}
function openTrip(id){state.trip=id;renderTrip();selectTab('trip');updateTripBanner();}
// Einstieg über (+), "Fahrt eintragen", Tagebuch: erst Touren/Datum/Startzeit wählen
function openTripStart(ids=[]){
 const run=runningTrip();
 if(run){openTrip(run.id);toast('Es läuft noch eine Kontrollfahrt – erst beenden, dann eine neue beginnen.',true);return;}
 $('#trip-start-routes').innerHTML=state.routes.map(r=>`<label class="entry-route-option"><input type="checkbox" name="tripTours" value="${esc(r.id)}" ${ids.includes(r.id)?'checked':''}><span>${esc(r.id)} · ${esc(r.name)}</span></label>`).join('');
 $('#trip-start-date').value=todayLocal();$('#trip-start-time').value=nowTime();
 $('#trip-start-dialog').showModal();
}
async function startTrip(ids,date=todayLocal(),time=nowTime()){
 const run=runningTrip();
 if(run){openTrip(run.id);toast('Es läuft noch eine Kontrollfahrt – erst beenden, dann eine neue beginnen.',true);return;}
 const picked=[...new Set(ids)].filter(id=>state.routes.some(r=>r.id===id));
 if(!picked.length){toast('Bitte mindestens eine Tour auswählen.',true);return;}
 try{const r=await api('tripStart','POST',{tourIds:picked,date,startTime:time});await refresh();openTrip(r.entry.id);toast(`Kontrollfahrt gestartet · ${time} Uhr`);}
 catch(err){toast(err.message,true);}
}
// "Tour starten" (Tourkarte, Karte): sofort beginnen
function startTour(ids){startTrip(ids);}
// Mangel-Blatt: neu (Uhrzeit = jetzt, Standort sofort) oder bearbeiten
let issueCoords=null;
function issueGpsLabel(){$('#issue-gps-label').textContent=issueCoords?`GPS: ${issueCoords.lat.toFixed(6)}, ${issueCoords.lon.toFixed(6)}`:'GPS: nicht erfasst';$('#issue-gps-clear').hidden=!issueCoords;}
function captureIssueGps(){
 if(!navigator.geolocation){$('#issue-gps-label').textContent='GPS auf diesem Gerät nicht verfügbar';return;}
 $('#issue-gps-label').textContent='Standort wird erfasst …';
 navigator.geolocation.getCurrentPosition(pos=>{issueCoords={lat:pos.coords.latitude,lon:pos.coords.longitude};issueGpsLabel();},()=>{issueGpsLabel();$('#issue-gps-label').textContent+=' (Standortfreigabe fehlt?)';},{enableHighAccuracy:true,timeout:15000,maximumAge:10000});
}
function renderIssuePhotos(issue){$('#issue-existing-photos').innerHTML=(issue?.photos||[]).map(p=>`<div class="photo-tile"><a href="api.php?action=photo&id=${p.id}" target="_blank" rel="noopener noreferrer"><img src="api.php?action=photo&id=${p.id}&thumb=1" alt="${esc(p.name)}"></a><button type="button" title="Foto entfernen" aria-label="Foto entfernen" data-photo-delete="${p.id}">×</button></div>`).join('');}
function openIssueSheet(issueId=''){
 const e=tripEntry();if(!e)return;
 const ids=entryRoutes(e),issue=issueId?entryIssues(e).find(i=>i.id===issueId):null;
 $('#issue-id').value=issue?.id||newIssueId();
 $('#issue-eyebrow').textContent=issue?'Mangel bearbeiten':'Neuer Mangel';
 $('#issue-heading').textContent=issue?`${issue.tourId} · ${issue.kind}`:'Mangel erfassen';
 $('#issue-tour').innerHTML=ids.map(id=>`<option value="${esc(id)}">${esc(id)} · ${esc(routeById(id)?.name||'')}</option>`).join('');
 $('#issue-tour').value=issue?.tourId||ids[0];$('#issue-tour-wrap').hidden=ids.length===1&&!issue;
 $('#issue-kind').innerHTML=ISSUE_KINDS.map(k=>`<option value="${esc(k)}">${esc(k)}</option>`).join('');$('#issue-kind').value=issue?.kind||'Sonstiges';
 $('#issue-status').innerHTML=Object.entries(ISSUE_STATES).map(([k,l])=>`<option value="${k}">${l}</option>`).join('');$('#issue-status').value=issue?.status||'offen';
 $('#issue-time').value=issue?.time||nowTime();
 $('#issue-place').value=issue?.place||'';$('#issue-description').value=issue?.description||'';$('#issue-photos').value='';
 issueCoords=issue?.coords||null;issueGpsLabel();renderIssuePhotos(issue);
 $('#issue-delete').hidden=!issue;
 $('#issue-dialog').showModal();
 if(!issue)captureIssueGps();
}
async function saveIssue(ev){
 ev.preventDefault();
 const e=tripEntry();if(!e)return;
 const id=$('#issue-id').value,existing=entryIssues(e).find(i=>i.id===id),files=[...$('#issue-photos').files];
 if((existing?.photos?.length||0)+files.length>5){toast('Maximal 5 Fotos je Mangel.',true);return;}
 const issue={id,tourId:$('#issue-tour').value,kind:$('#issue-kind').value,status:$('#issue-status').value,time:$('#issue-time').value,place:$('#issue-place').value,description:$('#issue-description').value,coords:issueCoords};
 const btn=$('#issue-save');btn.disabled=true;btn.innerHTML=ic('refresh')+' Speichere …';
 try{
   await api('issueSave','POST',{entryId:e.id,issue});
   let failed=0;
   for(const file of files){const resized=await compressPhoto(file);const fd=new FormData();fd.set('entryId',e.id);fd.set('issueId',id);fd.set('file',resized,resized.name);try{await api('upload','POST',fd);}catch(err){failed++;}}
   $('#issue-dialog').close();await refresh();
   toast(failed?`Mangel gespeichert, ${failed} Foto(s) fehlgeschlagen.`:'Mangel gespeichert.',!!failed);
 }catch(err){toast(err.message,true);}
 finally{btn.disabled=false;btn.innerHTML=ic('check')+' Speichern';}
}
async function deleteIssue(){
 const e=tripEntry(),id=$('#issue-id').value;
 if(!e||!window.confirm('Diesen Mangel samt Fotos löschen?'))return;
 try{await api('issueDelete','POST',{entryId:e.id,issueId:id});$('#issue-dialog').close();await refresh();toast('Mangel gelöscht.');}catch(err){toast(err.message,true);}
}
function openTripFinish(){
 const e=tripEntry();if(!e)return;
 const n=entryIssues(e).length;
 $('#trip-finish-summary').textContent=`${tourLabel(entryRoutes(e))} · ${textDate(e.date)} ab ${e.startTime||'?'} · ${n} ${n===1?'Mangel':'Mängel'} erfasst`;
 $('#trip-end-time').value=nowTime();$('#trip-notes').value=e.notes||'';$('#trip-photos').value='';
 $('#trip-discard').hidden=n>0||(e.photos||[]).length>0;
 $('#trip-finish-dialog').showModal();
}
async function finishTrip(ev){
 ev.preventDefault();
 const e=tripEntry();if(!e)return;
 const files=[...$('#trip-photos').files];
 if((e.photos?.length||0)+files.length>8){toast('Maximal 8 allgemeine Fotos je Kontrollfahrt.',true);return;}
 const btn=$('#trip-finish-save');btn.disabled=true;
 try{
   await api('tripUpdate','POST',{entryId:e.id,endTime:$('#trip-end-time').value,notes:$('#trip-notes').value,running:false});
   for(const file of files){const resized=await compressPhoto(file);const fd=new FormData();fd.set('entryId',e.id);fd.set('file',resized,resized.name);try{await api('upload','POST',fd);}catch(err){}}
   $('#trip-finish-dialog').close();await refresh();selectTab('diary');toast('Kontrollfahrt abgeschlossen.');
 }catch(err){toast(err.message,true);}finally{btn.disabled=false;}
}
async function discardTrip(){
 const e=tripEntry();if(!e||!window.confirm('Diese Fahrt verwerfen? Es wurde noch nichts erfasst.'))return;
 try{await api('deleteEntry','POST',{id:e.id});$('#trip-finish-dialog').close();state.trip=null;await refresh();selectTab('map');toast('Fahrt verworfen.');}catch(err){toast(err.message,true);}
}
async function saveTripMeta(field,value){
 const e=tripEntry();if(!e||!value)return;
 try{await api('tripUpdate','POST',{entryId:e.id,[field]:value});await refresh();}catch(err){toast(err.message,true);renderTrip();}
}
async function changeIssueStatus(entryId,issueId,status){
 if(!['offen','erledigt'].includes(status))return;
 const item=state.entries.find(e=>e.id===entryId);
 const issue=item?entryIssues(item).find(i=>i.id===issueId):null;
 if(!issue){toast('Mangel nicht gefunden.',true);return;}
 if(status==='erledigt'&&!window.confirm('Diesen Mangel als erledigt kennzeichnen?'))return;
 try {
   await api('issueStatus','POST',{entryId,issueId,status});
   await refresh();
   if(state.tab==='trip')renderTrip();
   toast(status==='erledigt'?'Mangel als erledigt dokumentiert.':'Mangel wieder geöffnet.');
 } catch(error){toast(error.message,true);}
}
function routeById(id){return state.routes.find(r=>r.id===id);}
function openRoute(id){const r=routeById(id);if(!r)return;
 $('#route-id').value=r.id;$('#route-name').value=r.name;$('#route-area').value=r.area;$('#route-km').value=r.km||'';$('#route-note').value=r.note||'';
 $('#route-dialog').showModal();
}
function gpsLabel(){const c=state.coords;$('#gps-label').textContent=c?`Fahrt-GPS: ${c.lat.toFixed(6)}, ${c.lon.toFixed(6)}`:'GPS-Position der Kontrollfahrt: nicht erfasst';$('#clear-position').hidden=!c;}
function selectedEntryTours(){return $$('input[name="tourIds"]:checked').map(n=>n.value);}
function issuePoint(row){return row.dataset.lat&&row.dataset.lon?{lat:Number(row.dataset.lat),lon:Number(row.dataset.lon)}:null;}
function makeIssueRow(issue={}){
 const id=issue.id||newIssueId();
 const ids=selectedEntryTours(),value=ids.includes(issue.tourId)?issue.tourId:(ids[0]||issue.tourId||'01');
 const opt=ids.map(id=>`<option value="${esc(id)}" ${value===id?'selected':''}>${esc(id)} · ${esc(routeById(id)?.name||'')}</option>`).join('');
 const photos=(issue.photos||[]).map(p=>`<div class="photo-tile"><a href="api.php?action=photo&id=${p.id}" target="_blank" rel="noopener noreferrer"><img src="api.php?action=photo&id=${p.id}&thumb=1" alt="${esc(p.name)}"></a><button type="button" title="Foto entfernen" data-photo-delete="${p.id}">×</button></div>`).join('');
 const el=document.createElement('div');el.className='issue-row';el.dataset.issueId=id;
 if(issue.coords){el.dataset.lat=String(issue.coords.lat);el.dataset.lon=String(issue.coords.lon);}
 el.innerHTML=`<div class="issue-row-title"><strong>Einzelmangel</strong><button type="button" class="text-btn" data-remove-issue>${ic('trash')} Entfernen</button></div>
  <div class="issue-fields"><label>Tour<select data-issue="tourId" required>${opt}</select></label>
  <label>Uhrzeit<input type="time" data-issue="time" value="${esc(issue.time||'')}"></label>
  <label>Art<select data-issue="kind">${ISSUE_KINDS.map(k=>`<option value="${esc(k)}" ${k===(issue.kind||'Sonstiges')?'selected':''}>${esc(k)}</option>`).join('')}</select></label>
  <label>Status<select data-issue="status">${Object.entries(ISSUE_STATES).map(([k,label])=>`<option value="${k}" ${k===(issue.status||'offen')?'selected':''}>${label}</option>`).join('')}</select></label>
  <label class="issue-wide">Ort / Abschnitt<input data-issue="place" maxlength="180" value="${esc(issue.place||'')}" placeholder="z. B. Brücke, Straßenname, Kreuzung"></label>
  <label class="issue-wide">Beschreibung<textarea data-issue="description" maxlength="3000" rows="2" placeholder="Was ist defekt oder aufgefallen?">${esc(issue.description||'')}</textarea></label></div>
  <div class="issue-media"><span class="smallnote" data-issue-pos>${issue.coords?`GPS: ${Number(issue.coords.lat).toFixed(6)}, ${Number(issue.coords.lon).toFixed(6)}`:'GPS: nicht erfasst'}</span><button type="button" class="btn outline small" data-issue-gps>${ic('current-location')} GPS erfassen</button><button type="button" class="link-btn" data-issue-clear ${issue.coords?'':'hidden'}>Entfernen</button></div>
  <label class="issue-file"><span>${ic('camera')} Fotos zu diesem Mangel (max. 5)</span><input type="file" data-issue-photos accept="image/*" multiple></label>
  <div class="photos-row">${photos}</div>`;
 $('#entry-issues').append(el);return el;
}
function refreshIssueTourOptions(){const ids=selectedEntryTours();for(const row of $$('.issue-row')){const select=$('[data-issue="tourId"]',row),prev=select.value;select.innerHTML=ids.map(id=>`<option value="${id}">${esc(id)} · ${esc(routeById(id)?.name||'')}</option>`).join('');if(ids.includes(prev))select.value=prev;}}
function openEntry(tour='01',editId='',prefilled=null){
 const e=editId?state.entries.find(x=>x.id===editId):null;
 if(editId&&!e){toast('Eintrag nicht gefunden.',true);return;}
 $('#entry-form').reset();state.editing=e?.id||null;state.coords=e?.coords||null;
 $('#entry-heading').textContent=e?'Streckenprüfung bearbeiten':'Neue Streckenprüfung';
 $('#entry-id').value=e?.id||'';$('#entry-date').value=e?.date||todayLocal();$('#entry-start').value=e?.startTime||'';$('#entry-end').value=e?.endTime||'';
 const ids=e?entryRoutes(e):(prefilled?.length?prefilled:[tour]);
 for(const box of $$('input[name="tourIds"]'))box.checked=ids.includes(box.value);
 $('#entry-notes').value=e?.notes||'';$('#entry-photos').value='';
 $('#entry-issues').innerHTML='';
 for(const issue of (e?entryIssues(e):[]))makeIssueRow(issue);
 gpsLabel();renderExistingPhotos(e);$('#entry-dialog').showModal();
}
function renderExistingPhotos(e){$('#existing-photos').innerHTML=e?.photos?.length?e.photos.map(p=>`<div class="photo-tile"><a href="api.php?action=photo&id=${p.id}" target="_blank"><img src="api.php?action=photo&id=${p.id}&thumb=1" alt="${esc(p.name)}"></a><button type="button" title="Foto entfernen" aria-label="Foto entfernen" data-photo-delete="${p.id}">×</button></div>`).join(''):'';}
async function compressPhoto(file){
 // Jedes Foto wird neu als JPG (max. 1600 px) kodiert: spart Webspace und entfernt
 // EXIF-Daten wie GPS-Position und Gerät. Die Ausrichtung wird dabei übernommen.
 // Klappt das nicht (alter Browser), geht das Original hoch – der Server entfernt
 // bei JPG die Metadaten dann selbst.
 if(!file.type.startsWith('image/'))return file;
 try{
   const bmp=await createImageBitmap(file,{imageOrientation:'from-image'});
   const ratio=Math.min(1,1600/Math.max(bmp.width,bmp.height));
   const canvas=document.createElement('canvas');canvas.width=Math.round(bmp.width*ratio);canvas.height=Math.round(bmp.height*ratio);
   const ctx=canvas.getContext('2d');ctx.fillStyle='#fff';ctx.fillRect(0,0,canvas.width,canvas.height);ctx.drawImage(bmp,0,0,canvas.width,canvas.height);bmp.close?.();
   const blob=await new Promise(resolve=>canvas.toBlob(resolve,'image/jpeg',.82));
   return blob?new File([blob],file.name.replace(/\.[^.]+$/,'')+'.jpg',{type:'image/jpeg'}):file;
 }catch(err){return file;}
}
async function saveEntry(event){event.preventDefault();const form=$('#entry-form'),fd=new FormData(form),files=[...$('#entry-photos').files];
 const tourIds=selectedEntryTours();if(!tourIds.length){toast('Bitte mindestens eine Tour auswählen.',true);return;}
 const rows=$$('.issue-row');const issues=rows.map(row=>({id:row.dataset.issueId,time:$('[data-issue="time"]',row).value||null,tourId:$('[data-issue="tourId"]',row).value,kind:$('[data-issue="kind"]',row).value,status:$('[data-issue="status"]',row).value,place:$('[data-issue="place"]',row).value,description:$('[data-issue="description"]',row).value,coords:issuePoint(row)}));
 const issueUploads=rows.flatMap(row=>[...$('[data-issue-photos]',row).files].map(file=>({issueId:row.dataset.issueId,file})));
 const existing=state.editing?state.entries.find(e=>e.id===state.editing):null;
 if((existing?.photos?.length||0)+files.length>8){toast('Maximal 8 allgemeine Fotos je Kontrollfahrt.',true);return;}
 for(const row of rows){const id=row.dataset.issueId;const old=entryIssues(existing||{}).find(i=>i.id===id);if((old?.photos?.length||0)+[...$('[data-issue-photos]',row).files].length>5){toast('Maximal 5 Fotos pro Mangel.',true);return;}}
 const data={id:fd.get('id')||'',tourIds,date:fd.get('date'),startTime:fd.get('startTime')||null,endTime:fd.get('endTime')||null,notes:fd.get('notes'),coords:state.coords,issues};
 const button=$('#save-entry');button.disabled=true;button.textContent='Speichere …';
 try{
   const saved=await api('entry','POST',data);let failed=[];
   for(const upload of [...files.map(file=>({issueId:'',file})),...issueUploads]){
     const resized=await compressPhoto(upload.file);const photos=new FormData();photos.set('entryId',saved.entry.id);if(upload.issueId)photos.set('issueId',upload.issueId);photos.set('file',resized,resized.name);
     try{await api('upload','POST',photos);}catch(error){failed.push(`${upload.file.name}: ${error.message}`);}
   }
   if(failed.length)toast(`Eintrag gespeichert, ${failed.length} Foto(s) fehlgeschlagen. ${failed[0]}`,true);
   form.closest('dialog').close();await refresh();if(!failed.length)toast('Streckenprüfung mit allen Mängeln gespeichert.');
 }catch(error){toast(error.message,true);}finally{button.disabled=false;button.textContent='Eintrag speichern';}
}
async function saveRoute(event){event.preventDefault();const id=$('#route-id').value;
 try{await api('route','POST',{id,name:$('#route-name').value,area:$('#route-area').value,km:$('#route-km').value||null,note:$('#route-note').value});$('#route-dialog').close();await refresh();toast('Tourdetails aktualisiert.');}
 catch(err){toast(err.message,true);}
}
function exportCsv(){
 const cols=['Prüfung-ID','Kontrollierte Touren','Datum','Start','Ende','Fahrtnotiz','Fahrt-GPS Breitengrad','Fahrt-GPS Längengrad','Allgemeine Fotos','Mangel-ID','Mangel-Uhrzeit','Mangel-Tour','Mangelart','Mangelort','Mangelbeschreibung','Bearbeitungsstatus','Mangel-GPS Breitengrad','Mangel-GPS Längengrad','Mängelfotos','Erledigt am'];
 const rows=[];
 for(const e of state.entries){const prefix=[e.id,entryRoutes(e).join(', '),e.date,e.startTime||'',e.endTime||'',e.notes||'',e.coords?.lat??'',e.coords?.lon??'',(e.photos||[]).length];
  const issues=entryIssues(e);
  if(!issues.length)rows.push([...prefix,'','','','','','','','','',0,'']);
  for(const i of issues)rows.push([...prefix,i.id,i.time||'',i.tourId,i.kind,i.place,i.description,i.status,i.coords?.lat??'',i.coords?.lon??'',(i.photos||[]).length,i.completedAt||'']);
 }
 function quote(v){let value=String(v??'');if(/^[=+@-]/.test(value)&&!/^[-]?\d+(\.\d+)?$/.test(value))value="'"+value;return '"'+value.replaceAll('"','""')+'"';}
 const csv='\uFEFF'+[cols,...rows].map(row=>row.map(quote).join(';')).join('\r\n');
 const blob=new Blob([csv],{type:'text/csv;charset=utf-8'}),url=URL.createObjectURL(blob),a=document.createElement('a');a.href=url;a.download=`radwegpate-maengel-${todayLocal()}.csv`;a.click();setTimeout(()=>URL.revokeObjectURL(url),2000);
}
// Darstellung (Farbschema, Schriftgröße) – je Gerät im localStorage. theme-init.js setzt beides
// schon vor dem ersten Zeichnen; hier nur Auswahl, Umschalten und 'auto' bei Systemwechsel.
const THEME_COLORS={light:'#f3f7f4',dark:'#0d1612','hc-light':'#ffffff','hc-dark':'#000000'};
function storedPref(key,fallback){try{return localStorage.getItem(key)??fallback;}catch(e){return fallback;}}
function storePref(key,value){try{localStorage.setItem(key,value);}catch(e){}}
function effectiveTheme(choice){if(choice!=='auto')return choice;return matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light';}
function applyAppearance(){
 const choice=storedPref('radwegpate-theme','auto'),fs=storedPref('radwegpate-fs','');
 const theme=effectiveTheme(choice),root=document.documentElement;
 root.setAttribute('data-theme',theme);
 root.classList.toggle('fs-lg',fs==='fs-lg');root.classList.toggle('fs-xl',fs==='fs-xl');
 document.querySelector('meta[name="theme-color"]')?.setAttribute('content',THEME_COLORS[theme]||THEME_COLORS.light);
 for(const r of $$('input[name="theme"]'))r.checked=r.value===choice;
 for(const r of $$('input[name="fontsize"]'))r.checked=r.value===fs;
 state.leafletMap?.invalidateSize();
}
function setupAppearance(){
 for(const r of $$('input[name="theme"]'))r.addEventListener('change',()=>{storePref('radwegpate-theme',r.value);applyAppearance();});
 for(const r of $$('input[name="fontsize"]'))r.addEventListener('change',()=>{storePref('radwegpate-fs',r.value);applyAppearance();});
 matchMedia('(prefers-color-scheme: dark)').addEventListener?.('change',()=>{if(storedPref('radwegpate-theme','auto')==='auto')applyAppearance();});
 applyAppearance();
}
function setupEvents(){
 $('#auth-form').addEventListener('submit',async e=>{
   e.preventDefault();const box=$('#auth-error');box.hidden=true;
   try{
     const setup=!state.installed;
     const payload=setup?{code:$('#setup-code').value,password:$('#auth-password').value}:{password:$('#auth-password').value};
     await api(setup?'setup':'login','POST',payload);state.installed=true;state.loggedIn=true;$('#auth-password').value='';await loadData();showMain();await loadMap();toast(setup?'Einrichtung abgeschlossen.':'Willkommen zurück.');
   }catch(err){box.textContent=err.message;box.hidden=false;}
 });
 $$('.tab').forEach(t=>t.addEventListener('click',()=>selectTab(t.dataset.tab)));
 $('#settings-button').addEventListener('click',()=>{applyAppearance();$('#prefs-dialog').showModal();});
 $('#new-entry-diary').addEventListener('click',()=>openTripStart([]));
 // Plus in der Tab-Leiste: auf der Karte mit den gewählten Touren vorbelegt.
 $('#mobile-add').addEventListener('click',()=>{
   if(state.tab==='trip'&&tripEntry()?.running){openIssueSheet();return;} // während der Fahrt: Mangel erfassen
   openTripStart(state.tab==='map'?selectedMapTours():[]);});
 // Laufende Kontrollfahrt
 $('#trip-banner').addEventListener('click',()=>{const r=runningTrip();if(r)openTrip(r.id);});
 $('#trip-start-form').addEventListener('submit',ev=>{ev.preventDefault();const ids=$$('input[name="tripTours"]:checked').map(c=>c.value);if(!ids.length){toast('Bitte mindestens eine Tour auswählen.',true);return;}$('#trip-start-dialog').close();startTrip(ids,$('#trip-start-date').value,$('#trip-start-time').value);});
 $('#trip-add-issue').addEventListener('click',()=>openIssueSheet());
 $('#issue-form').addEventListener('submit',saveIssue);
 $('#issue-delete').addEventListener('click',deleteIssue);
 $('#issue-gps').addEventListener('click',captureIssueGps);
 $('#issue-gps-clear').addEventListener('click',()=>{issueCoords=null;issueGpsLabel();});
 $('#trip-finish').addEventListener('click',openTripFinish);
 $('#trip-finish-form').addEventListener('submit',finishTrip);
 $('#trip-discard').addEventListener('click',discardTrip);
 $('#trip-date').addEventListener('change',ev=>saveTripMeta('date',ev.target.value));
 $('#trip-start').addEventListener('change',ev=>saveTripMeta('startTime',ev.target.value));
 for(const k of $$('.kpi'))k.addEventListener('click',()=>{if(k.dataset.goto==='diary'){$('#filter-tour').value='';$('#filter-status').value=k.dataset.filter||'';renderDiary();}selectTab(k.dataset.goto);});
 setupAppearance();
 $('#add-issue').addEventListener('click',()=>{if(!selectedEntryTours().length){toast('Zuerst eine Tour auswählen.',true);return;}if($$('.issue-row').length>=25){toast('Maximal 25 Mängel.',true);return;}makeIssueRow();});
 $('#entry-routes').addEventListener('change',refreshIssueTourOptions);
 $('#entry-issues').addEventListener('click',event=>{
  const row=event.target.closest('.issue-row');if(!row)return;
  if(event.target.closest('[data-remove-issue]')){if(($$('[data-photo-delete]',row)).length&&!confirm('Mangelzeile mit bereits gespeicherten Fotos entfernen? Die Fotos werden beim Speichern gelöscht.'))return;row.remove();return;}
  if(event.target.closest('[data-issue-clear]')){delete row.dataset.lat;delete row.dataset.lon;$('[data-issue-pos]',row).textContent='GPS: nicht erfasst';$('[data-issue-clear]',row).hidden=true;}
  if(event.target.closest('[data-issue-gps]')){if(!navigator.geolocation){toast('GPS nicht verfügbar.',true);return;}$('[data-issue-pos]',row).textContent='GPS wird erfasst …';navigator.geolocation.getCurrentPosition(pos=>{row.dataset.lat=String(pos.coords.latitude);row.dataset.lon=String(pos.coords.longitude);$('[data-issue-pos]',row).textContent=`GPS: ${pos.coords.latitude.toFixed(6)}, ${pos.coords.longitude.toFixed(6)}`;$('[data-issue-clear]',row).hidden=false;},()=>{$('[data-issue-pos]',row).textContent='GPS nicht verfügbar';toast('Standortzugriff fehlgeschlagen.',true);},{enableHighAccuracy:true,timeout:15000,maximumAge:0});}
 });
 document.addEventListener('click',e=>{
   const edit=e.target.closest('[data-route-edit]');if(edit)openRoute(edit.dataset.routeEdit);
   const create=e.target.closest('[data-new-entry]');if(create)openTripStart([create.dataset.newEntry]);
   const tripEdit=e.target.closest('[data-trip-issue-edit]');if(tripEdit)openIssueSheet(tripEdit.dataset.tripIssueEdit);
   const resume=e.target.closest('[data-trip-open]');if(resume)openTrip(resume.dataset.tripOpen);
   const entryEdit=e.target.closest('[data-entry-edit]');if(entryEdit)openEntry('01',entryEdit.dataset.entryEdit);
   const del=e.target.closest('[data-entry-delete]');if(del)deleteEntry(del.dataset.entryDelete);
   const start=e.target.closest('[data-tour-start]');if(start)startTour([start.dataset.tourStart]);
   const toggle=e.target.closest('[data-issue-toggle]');if(toggle){const [entryId,issueId]=toggle.dataset.issueToggle.split(':');changeIssueStatus(entryId,issueId,toggle.dataset.targetStatus);}
   const osmAll=e.target.closest('[data-show-selected-map]');if(osmAll){
      const tids=state.tab==='trip'&&tripEntry()?entryRoutes(tripEntry()):selectedMapTours();
      selectTab('map');setMapActions(false);
      state.visibleRoutes=new Set(tids);saveVisibleRoutes();renderMap();zoomMap();
      showMapStage();
      toast('Ausgewählte Originalstrecken auf der OSM-Karte angezeigt.');
   }
   const osm=e.target.closest('[data-show-map-route]');if(osm){
      selectTab('map');setMapActions(false);
      const id=osm.dataset.showMapRoute;
      state.visibleRoutes=new Set([id]);saveVisibleRoutes();renderMap();zoomMap(id);
      showMapStage();
      toast('KML-Originalstrecke auf der OpenStreetMap-Karte angezeigt.');
   }
   const delPhoto=e.target.closest('[data-photo-delete]');if(delPhoto)deletePhoto(delPhoto.dataset.photoDelete);
   if(e.target.closest('[data-close]'))e.target.closest('dialog')?.close();
 });
 $('#filter-tour').addEventListener('change',renderDiary);$('#filter-status').addEventListener('change',renderDiary);
 $('#entry-form').addEventListener('submit',saveEntry);$('#route-form').addEventListener('submit',saveRoute);
 $('#get-position').addEventListener('click',()=>{
   if(!('geolocation'in navigator)){toast('GPS nicht verfügbar.',true);return;}
   $('#gps-label').textContent='GPS wird abgefragt …';
   navigator.geolocation.getCurrentPosition(p=>{state.coords={lat:p.coords.latitude,lon:p.coords.longitude};gpsLabel();toast('GPS-Standort übernommen.');},error=>{gpsLabel();toast('GPS nicht verfügbar oder Standortfreigabe verweigert.',true);},{enableHighAccuracy:true,timeout:15000,maximumAge:0});
 });
 $('#clear-position').addEventListener('click',()=>{state.coords=null;gpsLabel();});
 $('#export-csv').addEventListener('click',exportCsv);
 $('#password-form').addEventListener('submit',async e=>{
   e.preventDefault();const fd=new FormData(e.target);
   try{await api('password','POST',{old:fd.get('old'),new:fd.get('new')});e.target.reset();toast('Passwort geändert.');}catch(err){toast(err.message,true);}
 });
 $('#logout-button').addEventListener('click',async()=>{try{await api('logout','POST');$('#prefs-dialog').close();state.loggedIn=false;state.entries=[];state.mapData=null;if(state.leafletMap){state.leafletMap.remove();state.leafletMap=null;}showAuth(false);toast('Abgemeldet.');}catch(err){toast(err.message,true);}});
 for(const dialog of $$('.modal'))dialog.addEventListener('click',e=>{if(e.target===dialog)dialog.close();});
}
async function deleteEntry(id){if(!window.confirm('Diesen Tagebucheintrag samt Fotos wirklich löschen?'))return;try{await api('deleteEntry','POST',{id});await refresh();toast('Eintrag gelöscht.');}catch(err){toast(err.message,true);}}
async function deletePhoto(id){if(!window.confirm('Dieses Foto endgültig löschen?'))return;try{await api('deletePhoto','POST',{id});const node=$$('[data-photo-delete]').find(b=>b.dataset.photoDelete===id)?.closest('.photo-tile');node?.remove();await refresh();const e=state.entries.find(x=>x.id===state.editing);renderExistingPhotos(e);if($('#issue-dialog').open)renderIssuePhotos(entryIssues(tripEntry()||{}).find(i=>i.id===$('#issue-id').value));toast('Foto gelöscht.');}catch(err){toast(err.message,true);}}
// KML-Routenverwaltung – die Linien werden niemals automatisch neu berechnet.
const MAP_COLORS={'01':'#1463b1','02':'#057ba4','03':'#9c5b26','04':'#1d7b51','05':'#6f55bb','06':'#bd7422','07':'#d94b89'};
const DEFAULT_CENTER=[48.323,10.042]; // Nur initialer Kartenausschnitt Senden, keine Streckenkoordinaten.
function mapEmpty(){return {sources:[],assignments:{},importedAt:null,fileName:''};}
function associatedSources(id){return (state.mapData?.sources||[]).filter(s=>state.mapData?.assignments?.[s.id]===id);}
function routeLines(id){return associatedSources(id).flatMap(s=>s.lines||[]);}
function mapPoints(id){return routeLines(id).flat();}
function kmLine(points){let km=0;for(let i=1;i<points.length;i++){
  const a=points[i-1],b=points[i],r=Math.PI/180,da=(b[1]-a[1])*r,dl=(b[0]-a[0])*r;
  const h=Math.sin(da/2)**2+Math.cos(a[1]*r)*Math.cos(b[1]*r)*Math.sin(dl/2)**2;
  km+=6371*2*Math.atan2(Math.sqrt(h),Math.sqrt(1-h));
 }return km;}
function routeLineKm(id){return routeLines(id).reduce((n,line)=>n+kmLine(line),0);}
function mapDownloadUrl(id){return 'api.php?action=gpx&id='+encodeURIComponent(id);}
function startCoordinate(id){let lines=routeLines(id);return lines[0]?.[0]||null;}
function endCoordinate(id){let lines=routeLines(id);return lines[lines.length-1]?.at(-1)||null;}
// Das Ende jeder Hin-und-Rueck-KML ist der START und darf NIE als Gemeindegrenze dienen.
// Ein Ziel wird nur aus einem ausdrücklich in My Maps markierten Wendepunkt verwendet.
// Prüfen, ob der Punkt wirklich auf der importierten KML-Linie liegt. Kein Schätzwert.
function routeTurnpoint(id){
 const matches=[];
 for(const source of associatedSources(id))for(const marker of (source.turnpoints||[])){
   const point=marker?.point;
   if(!Array.isArray(point)||point.length!==2||!point.every(Number.isFinite))continue;
   if(!(source.lines||[]).some(line=>line.some(v=>sameKmlPoint(v,point))))continue;
   matches.push(point);
 }
 if(!matches.length)return null;
 if(matches.some(p=>!sameKmlPoint(p,matches[0])))return null; // Mehrere unterschiedliche Ziele -> prüfen
 return matches[0];
}
function routeMaps(id){
 if(routeById(id)?.type==='runde')return null; // Eine Rundtour hat keinen einzelnen Wendepunkt
 const p=routeTurnpoint(id),start=startCoordinate(id);
 if(!p||!start)return null;
 const toLatLon=v=>`${v[1]},${v[0]}`;
 const from=toLatLon(start),where=toLatLon(p);
 return {
  point:p,
  google:`https://www.google.com/maps/dir/?api=1&origin=${encodeURIComponent(from)}&destination=${encodeURIComponent(where)}&travelmode=bicycling`,
  apple:`https://maps.apple.com/?saddr=${encodeURIComponent(from)}&daddr=${encodeURIComponent(where)}`,
  osm:`https://www.openstreetmap.org/?mlat=${p[1]}&mlon=${p[0]}#map=16/${p[1]}/${p[0]}`
 };
}
async function loadMap(){
 try{let response=await api('mapData');state.mapData=response.map||mapEmpty();}
 catch(error){state.mapData=mapEmpty();toast('Karte konnte nicht geladen werden: '+error.message,true);}
 render();renderMapSettings();
}
function initMap(){
 if(state.leafletMap)return;
 if(!window.L){$('#map-placeholder').hidden=false;$('#map-placeholder').textContent='Kartenbibliothek konnte nicht geladen werden. Bitte Internetverbindung prüfen.';return;}
 state.leafletMap=L.map('pate-map',{scrollWheelZoom:false,zoomControl:false}).setView(DEFAULT_CENTER,12);
 L.control.zoom({position:'bottomleft'}).addTo(state.leafletMap); // oben liegt die Auswahl-Leiste
 L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png',{attribution:'© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>-Mitwirkende',maxZoom:19}).addTo(state.leafletMap);
}
function redrawMap(){
 initMap();if(!state.leafletMap)return;
 for(const group of Object.values(state.layerGroups||{}))state.leafletMap.removeLayer(group);
 state.layerGroups={};let valid=0;
 for(const route of state.routes){
   const lines=routeLines(route.id);
   if(!lines.length)continue;
   valid++;
   const group=L.featureGroup();
   for(const line of lines){
      if(line.length<2)continue;
      L.polyline(line.map(([lon,lat])=>[lat,lon]),{color:MAP_COLORS[route.id],weight:5,opacity:.85}).bindTooltip(esc(route.name)).addTo(group);
   }
   // Auch in der WebApp den aus der KML importierten echten Wendepunkt markieren.
   const turn=routeTurnpoint(route.id);
   if(turn)L.circleMarker([turn[1],turn[0]],{radius:7,color:MAP_COLORS[route.id],weight:3,fillColor:'#fff',fillOpacity:1})
     .bindPopup('KML-Wendepunkt · '+esc(route.id)+' · '+esc(route.name)).addTo(group);
   state.layerGroups[route.id]=group;
   if(state.visibleRoutes.has(route.id))group.addTo(state.leafletMap);
 }
 $('#map-placeholder').hidden=valid>0 && !!state.leafletMap;
 $('#map-placeholder').textContent=state.leafletMap?(valid>0?'':'Noch keine zugeordneten Linien. KML importieren und die Ebenen zuordnen.'):'Kartenbibliothek ist nicht verfügbar. Bitte Internetverbindung prüfen.';
 $('#map-count').textContent=`${[...state.visibleRoutes].filter(id=>routeLines(id).length).length} / ${valid} sichtbar`;
}
function zoomMap(id=null){
 if(!state.leafletMap)return;
 let bounds=L.latLngBounds([]);
 const active=id?[id]:[...state.visibleRoutes];
 for(const key of active){const g=state.layerGroups[key];if(g){bounds.extend(g.getBounds());}}
 if(bounds.isValid())state.leafletMap.fitBounds(bounds.pad(.09),{maxZoom:15});
}
function ensureSelectedRoute(){
 if(state.selectedRoute&&routeLines(state.selectedRoute).length)return;
 state.selectedRoute=state.routes.find(r=>routeLines(r.id).length)?.id||'01';
}
function renderMap(){
 if(!state.mapData)state.mapData=mapEmpty();
 if(!state.visibleRoutes)state.visibleRoutes=new Set(state.routes.map(r=>r.id));
 ensureSelectedRoute();
 $('#map-route-picker').innerHTML=state.routes.map(r=>{
  const km=routeLineKm(r.id),has=routeLines(r.id).length>0;
  return `<label class="map-tour ${has?'':'no-line'} ${state.visibleRoutes.has(r.id)?'selected':''}">
   <input type="checkbox" data-map-toggle="${esc(r.id)}" ${state.visibleRoutes.has(r.id)?'checked':''} ${has?'':'disabled'} aria-label="Tour ${esc(r.id)} anzeigen">
   <span class="map-dot tour-c${esc(r.id)}"></span><span class="tour-name">${esc(r.id)} · ${esc(r.name)}</span><span class="map-distance">${has?`${km.toFixed(1).replace('.',',')} km`:'keine KML'}</span></label>`;
 }).join('');
 redrawMap();renderSelectionSummary();
 if(state.mapShown&&state.leafletMap){setTimeout(()=>state.leafletMap.invalidateSize(),50);}
}
// Auswahl aus 1–7 Originalrouten. Die Hinfahrt+Rückfahrt jeder Route startet
// laut importierter KML am selben Ort; verbunden wird nur bei exakt gleichen Endpunkten.
function sameKmlPoint(a,b){return !!a&&!!b&&Math.abs(a[0]-b[0])<1e-7&&Math.abs(a[1]-b[1])<1e-7;}
function comboCheck(ids){
 if(ids.length<1||ids.length>7)return {ok:false,message:'Bitte mindestens eine Tour wählen.'};
 let last=null;
 for(const id of ids){
   const segments=routeLines(id);
   if(!segments.length)return {ok:false,message:`Für Tour ${id} sind keine KML-Linien vorhanden.`};
   for(const seg of segments){
     if(seg.length<2)return {ok:false,message:`Tour ${id} hat eine unvollständige Linie.`};
     if(last&&!sameKmlPoint(last,seg[0]))return {ok:false,message:`Tour ${id} schließt nicht unmittelbar an die vorige Strecke an. Es wird kein Verbindungsweg erfunden.`};
     last=seg[seg.length-1];
   }
 }
 return {ok:true,message:'Die GPS-Strecken schließen ohne zusätzliche Verbindungspunkte aneinander an.'};
}
function selectedMapTours(){
 return [...(state.visibleRoutes||[])].filter(id=>routeLines(id).length>0);
}
function saveVisibleRoutes(){localStorage.setItem('radwegpate-visible-routes',JSON.stringify([...state.visibleRoutes]));}
function renderSelectionSummary(){
 const ids=selectedMapTours();
 const n=ids.length;
 const total=ids.reduce((sum,id)=>sum+routeLineKm(id),0);
 const label=$('#selection-count');label.textContent=n?`${n===1?'1 Tour':n+' Touren'} · ${total.toFixed(1).replace('.',',')} km`:'keine';
 $('#selection-summary').textContent=n?ids.map(id=>`${id} · ${routeById(id)?.name||''}`).join('\n'):'Noch keine Tour ausgewählt – unten in der Liste anwählen.';
 const check=n>1?comboCheck(ids):{ok:n===1,message:''};
 // Über der Karte steht nur, was die Auswahl verhindert (keine lückenlose GPX-Kombination).
 const warning=$('#selection-warning');
 warning.textContent=n>1&&!check.ok?check.message:'';
 warning.hidden=!(n>1&&!check.ok);
 updateSelectionToggle();
 $('#map-fab').hidden=n===0;
 if(n===0)setMapActions(false);
 const link=$('#selection-gpx');link.hidden=!check.ok;
 if(check.ok){link.href=n===1?mapDownloadUrl(ids[0]):'api.php?action=gpx&ids='+encodeURIComponent(ids.join(','));link.innerHTML=ic('download')+(n===1?' Tour als GPX für Naviki':' Kombination als GPX für Naviki');}
 else link.removeAttribute('href');
 $('#selection-start').disabled=n===0;
 $('#selection-start').innerHTML=ic('bike')+(n?` Tour starten · ${pendingIssues(ids).length} offene Mängel`:' Tour starten · Mängel prüfen');
 const nav=$('#selection-navigation');
 if(n===1){
   const id=ids[0], maps=routeMaps(id);
   if(maps){
     const target=maps.point;
     nav.innerHTML=`<p class="smallnote"><b>Ziel: KML-Wendepunkt an der Gemeindegrenze</b> (${target[1].toFixed(6)}, ${target[0].toFixed(6)}). Google und Apple berechnen ihre eigene Route dorthin. Die Originalstrecke zeigen nur unsere Karte und die GPX.</p><div class="navigation-buttons">
       <a class="btn outline small" target="_blank" rel="noopener noreferrer" href="${esc(maps.google)}">${ic('navigation')} Google Maps · Ziel</a>
       <a class="btn outline small" target="_blank" rel="noopener noreferrer" href="${esc(maps.apple)}">${ic('navigation')} Apple Karten · Ziel</a></div>`;
   } else if(routeById(id)?.type==='runde'){
     nav.innerHTML='<p class="smallnote">Rundtour: Start und Ziel sind identisch. Für die <b>vollständige Streckenführung</b> bitte GPX herunterladen und in Naviki importieren. Google/Apple/OSM können diese KML-Rundtour nicht per Link übernehmen.</p>';
   } else {
     nav.innerHTML='<p class="smallnote"><b>Wendepunkt fehlt im gespeicherten Kartenimport.</b> Bitte unter <b>Einstellungen → KML importieren</b> deine ursprüngliche vollständige My-Maps-KML einmal erneut einlesen. Dann erscheinen die Ziel-Buttons mit dem tatsächlichen Wendepunkt, statt versehentlich Flurweg 7 als Ziel zu verwenden.</p>';
   }
 }else if(n>1)nav.innerHTML='<p class="smallnote">Für mehrere Touren den gemeinsamen GPX-Export nutzen. Google/Apple Maps und die OSM-Webseite übernehmen keine Original-KML-Kombination über einen einfachen Link.</p>';
 else nav.innerHTML='';
}
function renderMapSettings(){
 const m=state.mapData||mapEmpty();
 const count=(m.sources||[]).length;
 $('#map-import-info').textContent=count?`${m.fileName||'KML'} · importiert ${new Date(m.importedAt).toLocaleString('de-DE')} · ${count} Strecken-Ebenen · ${(m.sources||[]).reduce((n,s)=>n+(s.turnpoints||[]).length,0)} Wendepunkt-Marker. Erneuter Import ersetzt nur die KML-Daten, nicht dein Tagebuch.`:'Noch keine KML-Datei importiert.';
 $('#source-assignments').innerHTML=count?m.sources.map(src=>{
  const current=m.assignments?.[src.id]||'';
  const options='<option value="">Nicht zuordnen</option>'+state.routes.map(r=>`<option value="${r.id}" ${current===r.id?'selected':''}>${esc(r.id)} · ${esc(r.name)}</option>`).join('');
  const km=(src.lines||[]).reduce((v,line)=>v+kmLine(line),0);
  const route=routeById(current),lines=src.lines.length;
  const title=route?`${esc(route.id)} · ${esc(route.name)}`:'Keiner Tour zugeordnet';
  const edit=route?`<button type="button" class="icon-button small" data-route-edit="${esc(route.id)}" aria-label="Tour ${esc(route.id)} bearbeiten: Name, Bereich, Länge, Hinweis" title="Tour bearbeiten">${ic('pencil')}</button>`:'';
  return `<div class="source-row"><div class="source-head"><strong class="${route?'':'muted'}">${title}</strong>${edit}</div><small>KML-Ebene: ${esc(src.name)} · ${lines} ${lines===1?'Linie':'Linien'} · ${km.toFixed(1).replace('.',',')} km</small><label class="sr-only" for="assign-${src.id}">Tour für KML-Ebene ${esc(src.name)}</label><select id="assign-${src.id}" data-source-assign="${src.id}">${options}</select></div>`;
 }).join(''):'<p class="smallnote">Nach dem Import erscheinen die Ebenen hier.</p>';
 $('#save-assignments').disabled=!count;
}
function kmlGetName(node){
 for(const ch of node.children||[]){if(ch.localName==='name')return ch.textContent.trim();}
 return '';
}
function parseKmlCoord(text){
 const out=[];
 for(const tuple of text.trim().split(/\s+/)){
   const parts=tuple.trim().split(',');if(parts.length<2)continue;
   const lon=Number(parts[0]),lat=Number(parts[1]);
   if(Number.isFinite(lon)&&Number.isFinite(lat)&&Math.abs(lon)<=180&&Math.abs(lat)<=90)out.push([lon,lat]);
 }
 return out;
}
function guessTourFromName(name){
 const m=name.trim().match(/^(?:tour|route|entwurf)?\s*0?([1-7])(?:\b|[_/\.:-])/i);
 if(m)return m[1].padStart(2,'0');
 if(/gartenparadies/i.test(name))return '07';
 return '';
}
function importKmlText(xml){
 const d=new DOMParser().parseFromString(xml,'application/xml');
 if(d.getElementsByTagName('parsererror').length)throw new Error('Die KML-Datei ist kein gültiges XML.');
 const placemarks=[...d.getElementsByTagName('*')].filter(x=>x.localName==='Placemark');
 const groups=new Map();let totalPoints=0,totalLines=0;
 for(const p of placemarks){
   let folder=null,namedFolder=null,node=p.parentElement;
   while(node){if(node.localName==='Folder'){folder=node;if(!namedFolder&&guessTourFromName(kmlGetName(node)))namedFolder=node;}node=node.parentElement;}
   const key=kmlGetName(namedFolder||folder)||kmlGetName(p)||'Nicht benannte Ebene';
   const group=groups.get(key)||{lines:[],turnpoints:[]};
   const strings=[...p.getElementsByTagName('*')].filter(x=>x.localName==='LineString');
   for(const lineTag of strings){
     const coordinates=[...lineTag.children].find(n=>n.localName==='coordinates');
     if(!coordinates)continue;
     const points=parseKmlCoord(coordinates.textContent||'');
     if(points.length<2)continue;
     group.lines.push(points);totalPoints+=points.length;totalLines++;
     if(totalPoints>110000||totalLines>1500)throw new Error('Zu viele GPS-Punkte oder Linien für einen Import.');
   }
   // Der spezielle My-Maps-Point 'Wendepunkt an digitaler Gemeindegrenze' liegt
   // in demselben Folder wie die Streckenlinie. Andere Marker nicht als Ziel annehmen.
   if(/wendepunkt/i.test(kmlGetName(p))){
     const pointTag=[...p.getElementsByTagName('*')].find(x=>x.localName==='Point');
     const coordinates=pointTag&&[...pointTag.children].find(n=>n.localName==='coordinates');
     if(coordinates){
       const point=parseKmlCoord(coordinates.textContent||'')[0];
       if(point&&group.turnpoints.length<12)group.turnpoints.push({name:kmlGetName(p),point});
     }
   }
   if(group.lines.length||group.turnpoints.length)groups.set(key,group);
 }
 if(groups.size===0)throw new Error('In dieser KML sind keine Strecken enthalten. Bitte My Maps als KML exportieren.');
 if(groups.size>250)throw new Error('Diese KML enthält zu viele Ebenen.');
 return [...groups].filter(([name,group])=>group.lines.length).map(([name,group])=>({name,lines:group.lines,turnpoints:group.turnpoints}));
}
async function withIds(srcs){
 const out=[];const counts={};
 for(const src of srcs){
   const norm=src.name.trim(),count=(counts[norm]||0)+1;counts[norm]=count;
   const digest=await crypto.subtle.digest('SHA-256',new TextEncoder().encode(norm+'::'+count));
   const id=[...new Uint8Array(digest)].slice(0,8).map(x=>x.toString(16).padStart(2,'0')).join('');
   out.push({...src,id});
 }return out;
}
async function sendKmlImport(ev){ev.preventDefault();const file=$('#kml-file').files?.[0];if(!file)return;
 if(file.size>8*1024*1024){toast('KML zu groß (maximal 8 MB).',true);return;}
 if(!/\.kml$/i.test(file.name)){toast('Bitte eine .kml-Datei auswählen (nicht .kmz).',true);return;}
 const button=ev.submitter;button.disabled=true;button.innerHTML=ic('refresh')+' Lese KML …';
 try{
   const text=await file.text();const sources=await withIds(importKmlText(text));
   const assignments={};for(const src of sources){assignments[src.id]=state.mapData.assignments?.[src.id]||guessTourFromName(src.name);}
   if(!window.confirm(`${sources.length} KML-Ebenen mit ${sources.reduce((n,s)=>n+s.lines.length,0)} Linien und ${sources.reduce((n,s)=>n+s.turnpoints.length,0)} Wendepunkten importieren? Fahrten und Fotos bleiben erhalten.`))return;
   await api('mapImport','POST',{sources,assignments,fileName:file.name});
   await loadMap();zoomMap();toast(`${sources.length} Ebenen importiert. Bitte Zuordnung prüfen.`);
 }catch(e){toast('KML-Import: '+e.message,true);}finally{button.disabled=false;button.innerHTML=ic('upload')+' KML importieren';}
}
async function saveMapAssignments(){
 const assignments={};for(const sel of $$('[data-source-assign]'))assignments[sel.dataset.sourceAssign]=sel.value;
 try{await api('mapAssign','POST',{assignments});await loadMap();zoomMap();toast('Ebenen zugeordnet.');}
 catch(e){toast(e.message,true);}
}
function routesWithLines(){return state.routes.filter(r=>routeLines(r.id).length).map(r=>r.id);}
function updateSelectionToggle(){
 const all=routesWithLines(),btn=$('#selection-toggle');
 const allOn=all.length>0&&all.every(id=>state.visibleRoutes?.has(id));
 btn.innerHTML=allOn?ic('square-x')+' Alle abwählen':ic('square-check')+' Alle anwählen';
 btn.disabled=all.length===0;
}
// Aktionen zur Auswahl: die Karte gleitet nach rechts hinaus, die Aktionen kommen von links.
function setMapActions(open){
 const stage=$('#map-stage'),fab=$('#map-fab');
 if(!stage)return;
 stage.classList.toggle('show-actions',open);
 $('#selection-actions').inert=!open;
 $('.map-pane').inert=open;
 fab.setAttribute('aria-expanded',String(open));
 fab.innerHTML=ic(open?'map':'list-check');
 fab.setAttribute('aria-label',open?'Zurück zur Karte':'Aktionen zur Auswahl zeigen');
 fab.title=open?'Zurück zur Karte':'Aktionen zur Auswahl';
 if(open)$('#selection-actions').scrollTop=0;
}
// Höhen der klebenden Teile als CSS-Variablen: die Karte bleibt direkt unter der Kopfleiste stehen,
// und automatisches Scrollen (Fokus, Sprungziele) landet nicht hinter Karte oder Kopfleiste.
function trackTopbarHeight(){
 const bar=$('.topbar'),stage=$('#map-stage'),root=document.documentElement;
 if(!bar||!window.ResizeObserver)return;
 const ro=new ResizeObserver(()=>{root.style.setProperty('--topbar-h',bar.offsetHeight+'px');root.style.setProperty('--stage-h',(stage?.offsetHeight||0)+'px');});
 ro.observe(bar);if(stage)ro.observe(stage);
}
// Zur Karte springen: Seite so weit scrollen, dass die Karte unter der Kopfleiste steht.
// Kein scrollIntoView() auf der Karte – das verschiebt auch den geklippten Rahmen und die Karte rutscht aus dem Bild.
function showMapStage(){
 const stage=$('#map-stage');if(!stage)return;
 stage.scrollLeft=0;
 const y=window.scrollY+stage.getBoundingClientRect().top-$('.topbar').offsetHeight;
 window.scrollTo({top:Math.max(0,y),behavior:'smooth'});
}
function mapEvents(){
 trackTopbarHeight();
 $('#selection-toggle').addEventListener('click',()=>{
   const all=routesWithLines();
   state.visibleRoutes=all.every(id=>state.visibleRoutes.has(id))?new Set():new Set(all);
   saveVisibleRoutes();renderMap();if(state.visibleRoutes.size)zoomMap();
 });
 // Schließen = zurück zur Karte und auf die Auswahl zoomen (ersetzt "Auswahl auf Karte zeigen").
 $('#map-fab').addEventListener('click',()=>{const open=!$('#map-stage').classList.contains('show-actions');setMapActions(open);if(!open)zoomMap();});
 // Auswahl-Leiste auf der Karte auf-/zuklappen; Zustand je Gerät.
 const setOverlay=open=>{$('#overlay-toggle').setAttribute('aria-expanded',String(open));$('#overlay-body').hidden=!open;$('#map-overlay').classList.toggle('open',open);};
 setOverlay(storedPref('radwegpate-overlay','')==='open');
 $('#overlay-toggle').addEventListener('click',()=>{const open=$('#overlay-toggle').getAttribute('aria-expanded')!=='true';setOverlay(open);storePref('radwegpate-overlay',open?'open':'');});
 $('#selection-start').addEventListener('click',()=>startTour(selectedMapTours()));
 $('#kml-form').addEventListener('submit',sendKmlImport);
 $('#save-assignments').addEventListener('click',saveMapAssignments);
 $('#map-zoom').addEventListener('click',()=>zoomMap());
 document.addEventListener('change',event=>{
   const cb=event.target.closest('[data-map-toggle]');if(!cb)return;
   if(cb.checked)state.visibleRoutes.add(cb.dataset.mapToggle);else state.visibleRoutes.delete(cb.dataset.mapToggle);
   saveVisibleRoutes();renderMap();zoomMap();
 });
 document.addEventListener('click',event=>{
   const btn=event.target.closest('[data-map-choose]');if(!btn)return;
   state.selectedRoute=btn.dataset.mapChoose;
   if(btn.closest('.route-card'))selectTab('map');
   if(!state.visibleRoutes.has(state.selectedRoute))state.visibleRoutes.add(state.selectedRoute);
   saveVisibleRoutes();renderMap();zoomMap(state.selectedRoute);
 });
}
mapEvents();

try{const stored=JSON.parse(localStorage.getItem('radwegpate-visible-routes'));if(Array.isArray(stored))state.visibleRoutes=new Set(stored.filter(x=>/^0[1-7]$/.test(x)));}catch(e){}
setupEvents();boot();
if('serviceWorker'in navigator)window.addEventListener('load',()=>navigator.serviceWorker.register('sw.js').catch(()=>{}));
