// Run: npm install --prefix tests && npm run --prefix tests test:preview (Node 22+).
const fs=require('fs'),assert=require('node:assert/strict'),{JSDOM}=require('jsdom');
const base=require('node:path').resolve(__dirname,'..')+'/';
const media=(id,name)=>({id,url:`http://localhost:10115/${name}-150x150.webp`,title:name,preview_html:`<img src="http://localhost:10115/${name}.webp" alt="${name}">`});
const one=media(1,'first'),two=media(2,'second'),three=media(3,'third');
const original={post:{id:1,url:'http://localhost:10115/test/'},link_targets:[],sections:[{number:1,row_index:0,layout_index:0,layout:'proces',label:'Proces',selectors:['proces-layout'],fields:[{path:['images'],name:'images',type:'gallery',label:'Images',value:[1,3],editable:true}]}],changes:[{id:'gallery',section:1,row_index:0,layout_index:0,type:'gallery',field_path:['images'],field_name:'images',old_value:[1,2],new_value:[1,3],old_images:[one,two],new_images:[one,three]}]};
const html='<main><div class="flex-repeater"><div class="flex-content"><div class="flex-layout proces-layout"><div class="cta-images"><div class="cta-image"><img src="http://localhost:10115/first-scaled.webp"></div><div class="cta-image"><img src="http://localhost:10115/second.webp"></div></div><div class="proces-col">Other process rows stay in place</div></div></div></div></main>';
(async()=>{
 const dom=new JSDOM(html,{url:'http://localhost:10115/voor-assistants/talent-program/',runScripts:'outside-only',pretendToBeVisual:true});const w=dom.window,d=w.document;
 w.structuredClone=structuredClone;w.HTMLDialogElement.prototype.close=function(){this.open=false};w.HTMLDialogElement.prototype.showModal=function(){this.open=true};
 let context=structuredClone(original),posts=0,reads=0,chatReply=null;
 const gallery=context.changes.find(e=>e.type==='gallery');assert(gallery);
 const library=new Map([...gallery.old_images,...gallery.new_images].map(img=>[img.id,img]));
 w.fetch=async(url,options)=>{
  if(!options?.method || options.method==='GET'){reads++;return {ok:true,json:async()=>structuredClone(context)}}
  posts++;
  if(url.endsWith('chat')){context.changes=chatReply.changes;return {ok:true,json:async()=>structuredClone(chatReply)}}
  if(url.endsWith('change')){
   const edits=JSON.parse(options.body).changes;
   for(const edit of edits){
    const change={...gallery,id:'test-gallery',...edit,new_value:edit.value,new_images:edit.value.map(id=>library.get(id))};
    context.changes=[change];
   }
   return {ok:true,json:async()=>({changes:structuredClone(context.changes),queued:1})};
  }
  if(url.endsWith('publish')||url.endsWith('discard')){context.changes=[];return {ok:true,json:async()=>({published:1,url:context.post.url})}}
  throw Error(url);
 };
 w.PKContentAgent={restUrl:'/wp-json/pk-content-agent/v1/',postId:context.post.id,title:'Test'};
 w.eval(fs.readFileSync(base+'assets/agent.js','utf8').replaceAll('window.location.reload();','window.testReloads=(window.testReloads||0)+1;').replace('window.location.assign(destination);','window.testNavigations=(window.testNavigations||0)+1;').replace(/\}\)\(\);\s*$/,'window.testApi={state,openLayoutEditor,queueLayoutChanges,applyPreview,previewKey,finish,discardChange,getSectionByNumber,reversePreviewChange};})();'));
 await new Promise(r=>setTimeout(r,30));const api=w.testApi;
 const section=api.getSectionByNumber(gallery.section);const strip=section.querySelector('.cta-images');assert(strip);
 const identity=strip;const unrelated=section.querySelector('.proces-col');const initialFirst=strip.firstElementChild;
 assert(api.applyPreview(context.changes).has(api.previewKey(gallery)),'Existing gallery preview applied locally');
 assert.equal(strip.firstElementChild,initialFirst,'Unchanged image node preserved');
 const field=api.state.context.sections[gallery.section-1].fields.find(f=>f.type==='gallery');
 const sequences=[gallery.new_value.slice(0,2),[...gallery.new_value].reverse(),[],gallery.old_value];
 for(const ids of sequences){
  api.openLayoutEditor(gallery.section-1);
  const beforeReads=reads,beforePosts=posts;
  await api.queueLayoutChanges([{section:gallery.section,field_path:field.path,field_name:field.name,type:'gallery',value:ids}],'Galerij aangepast.',{keepEditor:true});
  assert.equal(api.state.layoutNeedsRefresh,false,'No deferred reload after direct gallery update');
  assert.equal(posts,beforePosts+1);assert.equal(reads,beforeReads,'No extra HTML/context request per image edit');
  assert.equal(strip.querySelectorAll('img').length,ids.length);
  assert.equal(section.querySelector('.cta-images'),identity);assert.equal(section.querySelector('.proces-col'),unrelated);
  d.querySelector('.pkca__layout-form').dispatchEvent(new w.Event('submit',{bubbles:true,cancelable:true}));
  assert.equal(w.testReloads,undefined,'Preview button does not reload');
 }
 await api.finish('publish');assert.equal(w.testNavigations,undefined);assert.equal(api.state.context.changes.length,0);assert.equal(api.state.busy,false);assert(!d.querySelector('[data-pkca-preview]'),'Preview highlights clear after saving');
 // Roll back an image replacement without loading the page again.
 context.changes=[structuredClone(gallery)];api.state.context.changes=context.changes;api.applyPreview(context.changes);
 await api.discardChange(gallery.id);assert.equal(w.testReloads,undefined);assert.equal(api.state.context.changes.length,0);
 assert.equal(strip.querySelectorAll('img').length,gallery.old_images.length);
 // Rich text changes and subsequent clearing also report a completed local update.
 const box=d.createElement('div');box.className='pk-text';box.innerHTML='<p>Original intro</p>';section.append(box);
 const text={section:gallery.section,row_index:gallery.row_index,layout_index:gallery.layout_index,type:'text',field_path:['test_text'],old_value:'<p>Original intro</p>',new_value:'<p><strong>Updated intro</strong></p>'};
 assert(api.applyPreview([text]).has(api.previewKey(text)));assert.equal(box.innerHTML,text.new_value);
 assert(api.applyPreview([{...text,new_value:''}]).has(api.previewKey(text)));assert.equal(box.textContent,'');
 console.log('PASS: gallery replace/remove/reorder/clear/restore update locally with one POST and no additional render requests.');
 console.log('PASS: unchanged images, layout, process items stay in place; preview, save and discard avoid page reloads.');
 console.log('PASS: rich text updates and clearing are applied in place.');
 context.changes=[structuredClone(gallery)];api.state.context.changes=context.changes;api.applyPreview(context.changes);
 const restore=()=>{const event=new w.Event('pageshow');Object.defineProperty(event,'persisted',{value:true});w.dispatchEvent(event)};
 restore();await new Promise(r=>setTimeout(r,15));assert.equal(w.testReloads,undefined,'Back navigation restores supported previews in place');
 context.changes=[];restore();await new Promise(r=>setTimeout(r,15));assert.equal(w.testReloads,undefined,'Changes removed elsewhere roll back cached gallery without reload');
 const unsupported={...gallery,type:'taxonomy',field_path:['filters'],old_value:[],new_value:[1]};
 assert(!api.applyPreview([unsupported]).has(api.previewKey(unsupported)),'Unsupported structural updates remain eligible for the server fallback');
 console.log('PASS: back navigation and external discard restore supported previews locally; structural changes retain fallback.');
 const standalone=d.createElement('img');standalone.src='http://localhost:10115/single-old.webp';section.append(standalone);
 const image={...gallery,id:'single',type:'image',field_path:['single'],old_value:4,new_value:5,old_url:standalone.src,new_url:'http://localhost:10115/single-new.webp',new_html:'<img src="http://localhost:10115/single-new.webp">',old_html:'<img src="http://localhost:10115/single-old.webp">'};
 context.changes=[image];api.state.context.changes=context.changes;
 assert(api.applyPreview([image]).has(api.previewKey(image)));
 await api.finish('discard');assert.equal(w.testNavigations,undefined);assert.equal(section.lastElementChild.getAttribute('src'),'http://localhost:10115/single-old.webp');
 const chat={...text,id:'chat',old_value:'',new_value:'<p>Direct chat preview</p>'};chatReply={message:'Tekst aangepast',change:chat,changes:[chat]};
 d.querySelector('.pkca__form textarea').value='Pas de tekst aan';d.querySelector('.pkca__form').dispatchEvent(new w.Event('submit',{bubbles:true,cancelable:true}));
 await new Promise(r=>setTimeout(r,15));assert.equal(box.textContent,'Direct chat preview');assert.equal(w.testReloads,undefined);
 console.log('PASS: individual image rollback and chat text changes avoid navigation and reloads.');
dom.window.close();
})().catch(e=>{console.error(e);process.exitCode=1});
