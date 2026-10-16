// Requires jsdom and Swiper 12; exercises the real slider rather than a Swiper mock.
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict'),{JSDOM}=require('jsdom');
(async()=>{
 const dom=new JSDOM('<div class="flex-repeater"><div class="flex-content"><div class="flex-layout swiper_images"><div class="images-swiper"><div class="swiper-wrapper"></div></div><div class="controls-row"><div class="images-pagination"></div></div></div></div></div>',{url:'https://example.test/vacatures/',runScripts:'outside-only',pretendToBeVisual:true});
 const w=dom.window,d=w.document;
 for(const key of ['window','document','HTMLElement','Element','HTMLSlotElement','CustomEvent'])global[key]=key==='window'?w:key==='document'?d:w[key];
 global.requestAnimationFrame=w.requestAnimationFrame.bind(w);global.cancelAnimationFrame=w.cancelAnimationFrame.bind(w);
 const {default:Swiper}=await import(require('node:url').pathToFileURL(require.resolve('swiper/bundle')));w.Swiper=Swiper;w.structuredClone=structuredClone;
 const media=id=>({id,url:`https://example.test/${id}.jpg`,preview_html:`<img src="https://example.test/${id}.jpg">`});
 const ids=[1,2,3,4,5,6,7];let pending=[],posts=[];
 const field={path:['images'],name:'images',type:'gallery',label:'Afbeeldingen',value:ids,images:ids.map(media),editable:true};
 const section={number:1,row_index:0,layout_index:0,layout:'swiper_images',label:'Afbeeldingen - Swiper',selectors:['swiper_images'],fields:[field]};
 const ctx=()=>({post:{id:'archive:job'},sections:[section],changes:pending,link_targets:[]});
 const slider=d.querySelector('.images-swiper'),wrapper=slider.firstElementChild;
 Object.defineProperties(slider,{clientWidth:{get:()=>900},offsetWidth:{get:()=>900}});
 wrapper.innerHTML=ids.map(id=>`<div class="swiper-slide"><img src="https://example.test/${id}.jpg"></div>`).join('');
 let boundCount=0;
 w.novaPaginationConfig=(el,count)=>count>6?false:{el,clickable:true};
 w.novaBindPagination=(swiper,el,count)=>{boundCount=count;if(count>6){el.innerHTML='<button data-last>Laatste</button>';el.querySelector('button').addEventListener('click',()=>swiper.slideToLoop(count-1,0));}};
 const first=new Swiper(slider,{width:900,slidesPerView:1,loop:true,pagination:w.novaPaginationConfig(d.querySelector('.images-pagination'),ids.length)});w.novaBindPagination(first,d.querySelector('.images-pagination'),ids.length);
 w.fetch=async(url,options={})=>{
  if(options.method==='POST'){
   const body=JSON.parse(options.body);posts.push({url,body});
   if(url.endsWith('change')){const edit=body.changes[0];pending=[{...edit,id:'gallery',row_index:0,layout_index:0,old_value:ids,new_value:edit.value,old_images:ids.map(media),new_images:edit.value.map(media)}];return {ok:true,json:async()=>({queued:1,changes:structuredClone(pending)})};}
   if(url.endsWith('publish')){pending=[];return {ok:true,json:async()=>({published:1,url:'/vacatures/'})};}
  }
  return {ok:true,json:async()=>structuredClone(ctx())};
 };
 w.HTMLDialogElement.prototype.showModal=function(){this.open=true;};w.HTMLDialogElement.prototype.close=function(){this.open=false;};
 w.PKContentAgent={postId:'archive:job',title:'Vacatures',restUrl:'/wp-json/pk-content-agent/v1/'};
 w.eval(fs.readFileSync(path.resolve(__dirname,'../assets/agent.js'),'utf8').replaceAll('window.location.reload();','window.testReloads=(window.testReloads||0)+1;').replaceAll('window.location.assign(destination);','window.testNavigated=true;').replace(/\}\)\(\);\s*$/,'window.testApi={state,openLayoutEditor,applyPreview,previewKey,reversePreviewChange};})();'));
 const wait=()=>new Promise(r=>setTimeout(r,35));await wait();const api=w.testApi;api.openLayoutEditor(0);
 const event=(name,target,x=0)=>{const e=new w.Event(name,{bubbles:true,cancelable:true});e.dataTransfer={setData(){},effectAllowed:'',dropEffect:''};e.clientX=x;target.dispatchEvent(e);};
 let gallery=d.querySelector('.pkca__layout-gallery'),figures=[...gallery.children];figures.forEach(el=>el.getBoundingClientRect=()=>({left:0,width:100}));
 event('dragstart',figures[0].querySelector('img'));event('dragover',figures[1],100);event('drop',gallery);event('dragend',figures[0]);await wait();
 assert.equal(posts.length,1,'Dropping in the gap queues exactly once');assert.deepEqual(posts[0].body.changes[0].value,[2,1,3,4,5,6,7]);
 assert.equal(d.querySelector('.pkca__changes').hidden,false);assert.equal(api.state.layoutNeedsRefresh,false);assert.equal(w.testReloads,undefined);
 const order=()=>Array.from(slider.querySelectorAll('.swiper-slide')).sort((a,b)=>Number(a.dataset.swiperSlideIndex||0)-Number(b.dataset.swiperSlideIndex||0)).map(el=>Number(el.querySelector('img').src.match(/\/(\d+)\.jpg/)[1]));
 assert.deepEqual(order(),[2,1,3,4,5,6,7]);assert.equal(first.destroyed,true);assert.equal(boundCount,7);
 gallery=d.querySelector('.pkca__layout-gallery');figures=[...gallery.children];figures.forEach(el=>el.getBoundingClientRect=()=>({left:0,width:100}));
 event('dragstart',figures[0]);event('dragover',figures[1],100);event('dragend',figures[0]);assert.deepEqual([...gallery.children].map(el=>Number(el.dataset.galleryId)),[2,1,3,4,5,6,7]);assert.equal(posts.length,1,'Cancelled drag queues nothing and restores visible order');
 const change=pending[0];
 for(const next of [[2,1,3,4,5,6,7,8],[2,1],[2],[],[1,2,3,4,5,6,7]]){
  const edit={...change,new_value:next,new_images:next.map(media)};
  assert(api.applyPreview([edit]).has(api.previewKey(edit)));assert.deepEqual(order(),next);assert.equal(boundCount,next.length);assert.equal(slider.swiper.params.loop,next.length>2);assert.equal(d.querySelector('.controls-row').hidden,next.length<=1);
 }
 api.applyPreview([change]);api.applyPreview([api.reversePreviewChange(change)]);assert.deepEqual(order(),ids,'Undo restores original logical order');
 api.applyPreview([change]);
 d.querySelector('.pkca__layout-form').dispatchEvent(new w.Event('submit',{bubbles:true,cancelable:true}));await wait();assert.equal(w.testReloads,undefined,'Order-only preview does not reload');
 d.querySelector('.pkca__review-open').click();d.querySelector('[data-review-publish]').click();await wait();
 assert.equal(posts.at(-1).url.endsWith('publish'),true);assert.equal(posts.at(-1).body.post_id,'archive:job');assert.equal(w.testNavigated,undefined);
 console.log('PASS: real Swiper reorder, gap drop, cancelled drag, add/remove/clear/restore, loop/pagination counts and order-only save work without reload.');
 slider.swiper.destroy(true,true);dom.window.close();
})().catch(e=>{console.error(e);process.exitCode=1;});
