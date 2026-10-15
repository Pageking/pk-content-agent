// Run after npm install --prefix tests: node tests/media-picker.cjs.
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict'),{JSDOM}=require('jsdom');
const pause=ms=>new Promise(resolve=>setTimeout(resolve,ms));
(async()=>{
 const image={name:'image',label:'Afbeelding',path:['image'],type:'image',value:1,url:'/old.jpg',editable:true};
 const gallery={name:'images',label:'Images',path:['images'],type:'gallery',value:[5],images:[{id:5,url:'/five.jpg'}],editable:true};
 const context={post:{id:123},sections:[{number:1,row_index:0,layout_index:0,layout:'test',selectors:['test-layout'],fields:[image,gallery]}],changes:[],link_targets:[]};
 const dom=new JSDOM('<div class="flex-repeater"><div class="flex-content"><div class="flex-layout test-layout"><img src="http://localhost/old.jpg"><div class="cta-images"><div class="cta-image"><img src="http://localhost/five.jpg"></div></div></div></div></div>',{url:'http://localhost/',runScripts:'outside-only',pretendToBeVisual:true});
 const w=dom.window,d=w.document;w.structuredClone=structuredClone;
 w.HTMLDialogElement.prototype.showModal=function(){this.open=true};w.HTMLDialogElement.prototype.close=function(){this.open=false;this.dispatchEvent(new w.Event('close'))};
 let submitted,mediaCalls=0,fail=false,slowResolve;
 w.fetch=async(url,options)=>{
  if(url.includes('media?')){
   mediaCalls++;const q=new URL(url,'http://localhost/').searchParams;
   if(q.get('search')==='slow')return new Promise(resolve=>{slowResolve=()=>resolve({ok:true,json:async()=>({items:[{id:99,url:'/stale.jpg',title:'STALE'}],total:1,pages:1})})});
   if(fail)return {ok:false,json:async()=>({message:'Geen toegang tot de mediabibliotheek.'})};
   const page=Number(q.get('page'));
   return {ok:true,json:async()=>({items:q.get('search')==='empty'?[]:[{id:page===2?3:2,url:'/pick.jpg',title:q.get('search')||'Bestaande foto'}],total:25,pages:2})};
  }
  if(url.endsWith('change')){
   submitted=JSON.parse(options.body).changes;
   const edit=submitted[0];
   const c={...edit,id:'picked',row_index:0,layout_index:0,old_value:edit.type==='image'?1:[5],new_value:edit.value,old_url:'/old.jpg',new_url:'/pick.jpg',new_html:'<img src="/pick.jpg">',old_images:[{id:5,url:'/five.jpg'}],new_images:edit.type==='gallery'?edit.value.map(id=>({id,url:`/${id}.jpg`,preview_html:`<img src="/${id}.jpg">`})) : []};
   return {ok:true,json:async()=>({queued:1,changes:[c]})};
  }
  if(url.endsWith('upload'))return {ok:true,json:async()=>({id:17})};
  return {ok:true,json:async()=>structuredClone(context)};
 };
 w.PKContentAgent={postId:123,restUrl:'/wp-json/pk-content-agent/v1/',title:'Test'};
 w.eval(fs.readFileSync(path.resolve(__dirname,'../assets/agent.js'),'utf8').replace(/\}\)\(\);\s*$/,'window.testApi={state,openLayoutEditor,chooseFromMedia};})();'));
 await pause(20);const api=w.testApi;api.openLayoutEditor(0);
 const pick=index=>d.querySelector(`[data-media-field="${index}"]`).click();
 w.Function=function(){throw new EvalError('CSP forbids unsafe-eval')};w.eval=function(){throw new EvalError('CSP forbids unsafe-eval')};w.wp={media(){throw Error('Must not use WordPress eval-based media')}};
 assert.equal(mediaCalls,0,'No media request before opening picker');
 pick(0);await pause(15);let dialog=d.querySelector('.pkca__media');assert(dialog.open);assert.equal(mediaCalls,1);
 dialog.querySelector('[data-media-next]').click();await pause(15);assert.equal(dialog.querySelector('[data-media-id]').dataset.mediaId,'3');
 dialog.querySelector('[data-media-id]').click();dialog.querySelector('[data-media-confirm]').click();await pause(20);
 assert.equal(submitted[0].value,3);assert(!d.querySelector('.pkca__media'));
 api.openLayoutEditor(0);pick(1);await pause(15);dialog=d.querySelector('.pkca__media');
 dialog.querySelector('[data-media-id]').click();dialog.querySelector('[data-media-next]').click();await pause(15);dialog.querySelector('[data-media-id]').click();
 dialog.querySelector('[data-media-confirm]').click();await pause(20);assert.deepEqual(Array.from(submitted[0].value),[5,2,3]);
 pick(1);await pause(15);dialog=d.querySelector('.pkca__media');const search=dialog.querySelector('[data-media-search]');
 search.value='slow';search.dispatchEvent(new w.Event('input'));await pause(280);assert(slowResolve);
 search.value='latest';search.dispatchEvent(new w.Event('input'));await pause(280);slowResolve();await pause(15);
 assert(dialog.querySelector('.pkca__media-grid').textContent.includes('latest'));assert(!dialog.textContent.includes('STALE'));
 search.value='empty';search.dispatchEvent(new w.Event('input'));await pause(280);assert(dialog.textContent.includes('Geen afbeeldingen gevonden'));
 fail=true;search.value='denied';search.dispatchEvent(new w.Event('input'));await pause(280);assert(dialog.textContent.includes('Geen toegang'));
 dialog.querySelector('[data-media-close]').click();assert(!d.querySelector('.pkca__media'));
 fail=false;pick(1);await pause(15);dialog=d.querySelector('.pkca__media');const upload=dialog.querySelector('input[type=file]');Object.defineProperty(upload,'files',{value:[new w.File(['test'],'test.jpg',{type:'image/jpeg'})]});upload.dispatchEvent(new w.Event('change'));await pause(20);assert(submitted[0].value.includes(17));assert(!d.querySelector('.pkca__media'));
 console.log('PASS: media picker opens with eval and WordPress media disabled, loads only on demand, pages and selects single images.');
 console.log('PASS: gallery selections persist across pages, add to existing images, and upload uses the existing upload route.');
 console.log('PASS: stale searches cannot overwrite new results; empty/denied results and close cleanup are handled.');dom.window.close();
})().catch(error=>{console.error(error);process.exitCode=1});
