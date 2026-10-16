// Run with jsdom installed: node tests/archive-gallery.cjs.
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict'),{JSDOM}=require('jsdom');
(async()=>{
 const target='archive:job';
 const gallery={id:'gallery',section:1,row_index:0,layout_index:0,type:'gallery',field_path:['images'],field_name:'images',old_value:[1,2],new_value:[2,1],old_images:[{id:1,url:'/1.jpg'},{id:2,url:'/2.jpg'}],new_images:[{id:2,url:'/2.jpg'},{id:1,url:'/1.jpg'}]};
 const context={post:{id:target,url:'https://example.test/vacatures/'},sections:[{number:1,row_index:0,layout_index:0,layout:'image_swiper',label:'Afbeeldingen - Swiper',selectors:['image_swiper'],fields:[{path:['images'],type:'gallery',name:'images',label:'Afbeeldingen',value:[2,1],editable:true}]}],changes:[gallery],link_targets:[]};
 const boot=async()=>{
  const dom=new JSDOM('<div class="flex-repeater"><div class="flex-content"><div class="flex-layout image_swiper"><div class="swiper"><img src="/2.jpg"><img src="/1.jpg"></div></div></div></div>',{url:'https://example.test/vacatures/',runScripts:'outside-only',pretendToBeVisual:true});
  const w=dom.window;w.structuredClone=structuredClone;w.HTMLDialogElement.prototype.showModal=function(){this.open=true;};w.HTMLDialogElement.prototype.close=function(){this.open=false;};
  w.PKContentAgent={postId:target,title:'Vacatures',restUrl:'/wp-json/pk-content-agent/v1/'};
  w.fetch=async(url,options={})=>{
   if(options.method==='POST'){
    assert(url.endsWith('publish'));assert.equal(JSON.parse(options.body).post_id,target);
    context.changes=[];w.published=true;
    return {ok:true,json:async()=>({published:1,url:context.post.url})};
   }
   assert.equal(new URL(url,w.location.origin).searchParams.get('post_id'),target);
   return {ok:true,json:async()=>structuredClone(context)};
  };
  w.eval(fs.readFileSync(path.resolve(__dirname,'../assets/agent.js'),'utf8').replace('window.location.assign(destination);','window.testNavigation=destination;'));
  await new Promise(r=>setTimeout(r,20));
  return dom;
 };
 const first=await boot();assert.equal(first.window.document.querySelector('.pkca__changes').hidden,false);first.window.close();
 const second=await boot(),w=second.window,d=w.document;
 assert.equal(d.querySelector('.pkca__changes').hidden,false,'Pending gallery survives rebuilding the page UI');
 assert(d.querySelector('.pkca__changes').textContent.includes('1 wijziging'));
 d.querySelector('.pkca__review-open').click();assert(d.querySelector('.pkca__review').open);
 d.querySelector('[data-review-publish]').click();await new Promise(r=>setTimeout(r,20));
 assert.equal(w.published,true);assert.equal(w.testNavigation,'/vacatures/');
 console.log('PASS: archive gallery exposes review and save after reload, publishes to archive:job, and returns to the archive URL.');
 second.window.close();
})().catch(error=>{console.error(error);process.exitCode=1;});
