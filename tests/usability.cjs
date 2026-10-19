const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict'),{JSDOM}=require('jsdom');
(async()=>{
 for(const original of [false,true]){
  const fields=[{path:['title'],name:'title',label:'Titel',type:'text',acf_type:'text',value:'Nieuwe titel',original_value:'Originele titel',editable:true},{path:['image'],name:'image',label:'Achtergrond',type:'image',value:2,url:'/2.jpg',editable:true}];
  let pending=[{id:'title',section:1,row_index:0,layout_index:0,field_path:['title'],field_name:'title',type:'text',old_value:'Originele titel',new_value:'Nieuwe titel'},{id:'image',section:1,row_index:0,layout_index:0,field_path:['image'],field_name:'image',type:'image',old_value:1,new_value:2,old_url:'/1.jpg',new_url:'/2.jpg'}];
  let writes=0;const context=()=>({post:{id:123,url:'/',modified:'version'},sections:[{number:1,row_index:0,layout_index:0,layout:'test',label:'Test',selectors:['test-layout'],fields}],changes:pending,link_targets:[]});
  const dom=new JSDOM(`<div class="flex-repeater"><div class="flex-content"><section class="flex-layout test-layout"><h1>${original?'Originele titel':'Nieuwe titel'}</h1><img src="/${original?1:2}.jpg"></section></div></div>`,{url:'http://localhost:10115/test/'+(original?'?pkca_original=1':''),runScripts:'outside-only',pretendToBeVisual:true});
  const w=dom.window,d=w.document;w.structuredClone=structuredClone;w.scrollTo=()=>{};w.HTMLElement.prototype.scrollIntoView=function(){w.scrolled=true;};w.HTMLDialogElement.prototype.showModal=function(){this.open=true;};w.HTMLDialogElement.prototype.close=function(){this.open=false;this.dispatchEvent(new w.Event('close'));};w.PKContentAgent={postId:123,title:'Test',restUrl:'/wp-json/pk-content-agent/v1/'};
  w.fetch=async(url,options={})=>{if(options.method){writes++;return {ok:false,json:async()=>({message:'Reservekopie mislukt',code:'pkca_revision_failed'})};}return {ok:true,json:async()=>structuredClone(url.includes('media?')?{items:[{id:3,url:'/3.jpg',title:'Foto'}],pages:1,total:1}:context())};};
  w.eval(fs.readFileSync(path.resolve(__dirname,'../assets/agent.js'),'utf8').replaceAll('window.location.assign(url.href);','window.navigation=url.href;'));
  const wait=()=>new Promise(r=>setTimeout(r,25));await wait();assert(!d.querySelector('.pkca__browse').hidden);
  if(original){assert.equal(d.querySelector('h1').textContent,'Originele titel');assert.equal(d.querySelectorAll('[data-pkca-preview]').length,0);d.querySelector('[data-view-preview]').click();assert(!w.navigation.includes('pkca_original'));assert.equal(writes,0);dom.window.close();continue;}
  d.querySelector('[data-change-next]').click();assert(d.querySelector('[data-change-location]').textContent.startsWith('2/2'));assert(w.scrolled);assert.equal(writes,0,'Navigating changes never saves anything');
  assert.equal(d.querySelector('.pkca__image-toggle'),null,'Separate image editing mode is removed');
  d.querySelector('.pkca__review-open').click();d.querySelector('[data-review-publish]').click();await wait();assert(d.querySelector('.pkca__review-status').textContent.includes('Je wijzigingen staan nog klaar'));assert.equal(pending.length,2);assert.equal(writes,1);assert(d.querySelector('.pkca__review').open);assert(!d.querySelector('[data-review-publish]').disabled);
  d.querySelector('.pkca__review-status button').click();await wait();assert.equal(writes,2,'Failure offers a working explicit retry');
  d.querySelector('[data-review-close]').click();d.querySelector('[data-view-original]').click();assert(w.navigation.includes('pkca_original=1'));assert.equal(writes,2,'Comparing the original never publishes or discards content');dom.window.close();
 }
 console.log('PASS: removed image mode, change navigation, original/preview mode and actionable publish failures preserve pending edits.');
})().catch(e=>{console.error(e);process.exitCode=1;});
