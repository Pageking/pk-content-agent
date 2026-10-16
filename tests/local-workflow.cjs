// Local frontend workflow regression: inline editing, undo, gallery controls and revision UI.
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict'),{JSDOM}=require('jsdom');
(async()=>{
 const fields=[{name:'title',path:['title'],type:'text',acf_type:'text',label:'Titel',value:'Onze titel',editable:true},{name:'text',path:['text'],type:'text',acf_type:'wysiwyg',label:'Tekst',value:'<p>Onze <strong>tekst</strong></p>',editable:true},{name:'images',path:['images'],type:'gallery',label:'Afbeeldingen',value:[1,2,3],images:[1,2,3].map(id=>({id,url:`/${id}.jpg`})),editable:true}];
 const section={number:1,row_index:0,layout_index:0,layout:'test',label:'Test',selectors:['test-layout'],fields};
 let pending=[],fail=false,submitted=[],restored;
 const context=()=>({post:{id:123,modified:'source-version'},sections:[section],changes:pending,link_targets:[]});
 const dom=new JSDOM('<div class="flex-repeater"><div class="flex-content"><div class="flex-layout test-layout"><h1>Onze titel</h1><div class="pk-text"><p>Onze <strong>tekst</strong></p></div><div class="cta-images"><img src="/1.jpg"><img src="/2.jpg"><img src="/3.jpg"></div></div></div></div>',{url:'http://localhost:10115/',runScripts:'outside-only',pretendToBeVisual:true});
 const w=dom.window,d=w.document;w.structuredClone=structuredClone;
 w.HTMLDialogElement.prototype.showModal=function(){this.open=true;};w.HTMLDialogElement.prototype.close=function(){this.open=false;this.dispatchEvent(new w.Event('close'));};
 w.PKContentAgent={postId:123,title:'Home',restUrl:'/wp-json/pk-content-agent/v1/'};
 w.fetch=async(url,options={})=>{
  let data=context();
  if(url.includes('media?'))data={items:[{id:4,url:'/4.jpg',title:'Nieuwe foto'},{id:2,url:'/2.jpg',title:'Bestaande foto'}],pages:1,total:2};
  if(url.includes('revision_id='))data={base_version:'source-version',changes:[{label:'Test',field:{label:'Titel'},type:'text',old_value:'Onze titel',new_value:'Vorige titel'}]};
  else if(url.includes('revisions?'))data={available:true,revisions:[{id:77,date:'2026-10-16T10:00:00',author:'Editor'}]};
  if(options.method==='POST'){
   const body=JSON.parse(options.body);submitted.push({url,body});
   if(fail)return {ok:false,json:async()=>({message:'Bewaren mislukt'})};
   if(url.endsWith('change')){
    for(const edit of body.changes){const field=fields.find(f=>f.path.join('.')===edit.field_path.join('.'));const change={...edit,id:field.name,old_value:field.value,new_value:edit.value,row_index:0,layout_index:0};if(edit.type==='gallery'){change.old_images=field.value.map(id=>({id,url:`/${id}.jpg`,preview_html:`<img src="/${id}.jpg">`}));change.new_images=edit.value.map(id=>({id,url:`/${id}.jpg`,preview_html:`<img src="/${id}.jpg">`}));}pending=pending.filter(c=>c.id!==change.id);pending.push(change);}
    data={queued:body.changes.length,changes:pending};
   }
   if(url.endsWith('discard')){pending=pending.filter(c=>c.id!==body.change_id);data={changes:pending};}
   if(url.endsWith('restore')){restored=body;data={url:'/'};}
  }
  return {ok:true,json:async()=>structuredClone(data)};
 };
 w.eval(fs.readFileSync(path.resolve(__dirname,'../assets/agent.js'),'utf8').replaceAll('window.location.reload();','window.testReloads=true;').replaceAll('window.location.assign(destination);','window.testNavigation=destination;').replace(/\}\)\(\);\s*$/,'window.testApi={state,openLayoutEditor};})();'));
 const wait=()=>new Promise(r=>setTimeout(r,20));await wait();const api=w.testApi;
 d.querySelector('.pkca__inline-toggle').click();let title=d.querySelector('h1');assert(title.classList.contains('pkca-inline-editable'));
 title.click();title=d.querySelector('h1');assert.equal(title.getAttribute('contenteditable'),'true');title.textContent='Nieuwe titel';d.querySelector('[data-inline-save]').click();await wait();
 assert.equal(title.textContent,'Nieuwe titel');assert.equal(submitted.at(-1).body.changes.length,1);assert.equal(submitted.at(-1).body.changes[0].field_path[0],'title');
 assert(d.querySelector('.pkca__review-open').textContent==='Wijzigingen controleren');assert(d.querySelector('.pkca__changes').textContent.includes('maar staan nog niet online'));
 d.querySelector('.pkca__review-open').click();d.querySelector('[data-review-discard]').click();await wait();assert.equal(title.textContent,'Onze titel');assert.equal(pending.length,0);
 assert.equal(d.querySelector('.pkca__undo'),null,'No ambiguous one-click undo remains in the chat panel');
 title.click();title=d.querySelector('h1');title.textContent='Titel voor controle';d.querySelector('[data-inline-save]').click();await wait();
 let second=d.querySelector('.pk-text');second.click();second=d.querySelector('.pk-text');second.innerHTML='<p>Tweede wijziging</p>';d.querySelector('[data-inline-save]').click();await wait();
 d.querySelector('.pkca__review-open').click();assert.equal(d.querySelectorAll('[data-review-discard]').length,2);
 d.querySelector('[data-review-discard="title"]').click();await wait();
 assert.equal(pending.length,1);assert.equal(pending[0].id,'text');assert.equal(d.querySelectorAll('[data-review-discard]').length,1,'Review stays open with the remaining change');
 assert.equal(d.querySelector('h1').textContent,'Onze titel');assert(!d.querySelector('h1').classList.contains('pkca-preview-target'),'Undone field loses its highlight');
 assert(d.querySelector('.pk-text').classList.contains('pkca-preview-target'),'Other pending field retains its highlight');
 d.querySelector('[data-review-discard="text"]').click();await wait();assert(!d.querySelector('.pkca__review').open);assert.equal(pending.length,0);assert.equal(d.querySelectorAll('[data-pkca-preview]').length,0);
 title=d.querySelector('h1');
 title.click();title=d.querySelector('h1');title.textContent='Annuleren';d.dispatchEvent(new w.KeyboardEvent('keydown',{key:'Escape',bubbles:true}));title=d.querySelector('h1');assert.equal(title.textContent,'Onze titel');
 fail=true;title.click();title=d.querySelector('h1');title.textContent='Fout';d.querySelector('[data-inline-save]').click();await wait();title=d.querySelector('h1');assert.equal(title.textContent,'Onze titel');fail=false;
 let rich=d.querySelector('.pk-text');rich.click();rich=d.querySelector('.pk-text');rich.innerHTML='<p>Nieuwe <strong>inhoud</strong></p>';d.querySelector('[data-inline-save]').click();await wait();assert(submitted.at(-1).body.changes[0].value.includes('<strong>inhoud</strong>'));
 d.querySelector('.pkca__review-open').click();d.querySelector('[data-review-discard]').click();await wait();assert(rich.innerHTML.includes('<strong>tekst</strong>'));
 api.openLayoutEditor(0);
 d.querySelector('[data-gallery-move="1"][data-direction="1"]').click();await wait();assert.deepEqual(submitted.at(-1).body.changes[0].value,[2,1,3]);
 assert.deepEqual([...d.querySelectorAll('.pkca__gallery-position')].map(e=>e.textContent),['1','2','3']);
 d.querySelector('[data-gallery-replace="1"]').click();await wait();const picker=d.querySelector('.pkca__media');picker.querySelector('[data-media-id="4"]').click();picker.querySelector('[data-media-confirm]').click();await wait();assert.deepEqual(submitted.at(-1).body.changes[0].value,[2,4,3],'Replacement preserves the original position and gallery length');
 d.querySelector('.pkca__review-open').click();d.querySelector('[data-review-discard]').click();await wait();assert.equal(pending.length,0);
 d.querySelector('.pkca__history-open').click();await wait();assert(d.querySelector('.pkca__history').open);d.querySelector('[data-restore-version="77"]').click();await wait();assert(d.querySelector('.pkca__history-body').textContent.includes('direct online'));assert(d.querySelector('.pkca__history-body').textContent.includes('Vorige titel'));assert(d.querySelector('.pkca__history-body').textContent.includes('Na terugzetten'));assert.equal(restored,undefined,'Choosing a revision first shows a confirmation');
 d.querySelector('[data-history-restore]').click();await wait();assert.equal(restored.revision_id,77);assert.equal(restored.base_version,'source-version');assert.equal(w.testNavigation,'/');
 assert.equal(w.testReloads,undefined);
 console.log('PASS: inline text/rich text save, cancel, failed save rollback, undo, human labels, keyboard gallery moves, same-position replacement and explicit revision restore confirmation.');
 dom.window.close();
})().catch(e=>{console.error(e);process.exitCode=1;});
