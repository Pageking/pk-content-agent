// Nested repeater content, media and inline list editing use actual field paths.
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict'),{JSDOM}=require('jsdom');
(async()=>{
 const image={ID:1,id:1,url:'/1.jpg',title:'Achtergrond'};
 const rows=[{title:'Samen verder',text:'<p>We <strong>helpen</strong> elkaar.</p>',benefits:[{text:'Persoonlijke aandacht'},{text:'Ruimte voor initiatief'}],background:image}];
 const benefit={name:'text',path:['text'],type:'text',acf_type:'text',label:'Tekst',value:'',editable:true};
 const row_fields=[{...benefit,name:'title',path:['title'],label:'Titel'},{...benefit,name:'text',acf_type:'wysiwyg',label:'Tekst'},{name:'benefits',path:['benefits'],type:'repeater',label:'Pluspunten',row_template:{text:''},row_fields:[benefit],min:1,max:3,editable:true},{name:'background',path:['background'],type:'image',label:'Eigen achtergrondafbeelding',editable:true,instructions:'Optioneel. Vervangt de standaardachtergrond.'}];
 const field={name:'cards',path:['cards'],type:'repeater',label:'Pijlers',value:structuredClone(rows),row_template:{title:'',text:'',benefits:[],background:false},row_fields,editable:true};
 const leaf={...benefit,path:['cards','0','benefits','0','text'],value:'Persoonlijke aandacht'};
 const section={number:1,row_index:0,layout_index:0,layout:'test',label:'Pijlers',selectors:['test-layout'],fields:[field,leaf]};
 let pending=[],posts=[];
 const context=()=>({post:{id:123,modified:'source'},sections:[section],changes:pending,link_targets:[]});
 const dom=new JSDOM('<div class="flex-repeater"><div class="flex-content"><div class="flex-layout test-layout"><h3>Samen verder</h3><p>We <strong>helpen</strong> elkaar.</p><ul><li>Persoonlijke aandacht</li><li>Ruimte voor initiatief</li></ul><img src="/1.jpg"></div></div></div>',{url:'http://localhost:10115/',runScripts:'outside-only',pretendToBeVisual:true});
 const w=dom.window,d=w.document;w.structuredClone=structuredClone;w.HTMLDialogElement.prototype.showModal=function(){this.open=true;};w.HTMLDialogElement.prototype.close=function(){this.open=false;this.dispatchEvent(new w.Event('close'));};w.PKContentAgent={postId:123,title:'Test',restUrl:'/wp-json/pk-content-agent/v1/'};
 w.fetch=async(url,options={})=>{
  let data=context();if(url.includes('media?'))data={items:[{id:2,url:'/2.jpg',title:'Nieuwe achtergrond'}],pages:1,total:1};
  if(options.method==='POST'){const body=JSON.parse(options.body);posts.push(body);if(url.endsWith('change')){pending=body.changes.map(edit=>({...edit,id:'nested',row_index:0,layout_index:0,old_value:field.value,new_value:edit.value}));data={queued:1,changes:pending};}if(url.endsWith('discard')){pending=[];data={changes:[]};}}
  return {ok:true,json:async()=>structuredClone(data)};
 };
 w.eval(fs.readFileSync(path.resolve(__dirname,'../assets/agent.js'),'utf8').replaceAll('window.location.reload();','window.testReloads=true;').replace(/\}\)\(\);\s*$/,'window.api={openLayoutEditor};})();'));
 const wait=()=>new Promise(r=>setTimeout(r,25));await wait();w.api.openLayoutEditor(0);
 assert(d.querySelector('.pkca__nested-repeater').textContent.includes('Pluspunten'));assert(!d.querySelector('.pkca__layout-editor').textContent.includes('[object Object]'));
 assert(d.querySelector('.pkca__nested-image img').src.endsWith('/1.jpg'));assert(d.querySelector('.pkca__repeater-fields .pkca__wysiwyg-editor strong'));
 const rich=d.querySelector('.pkca__repeater-fields .pkca__wysiwyg-editor');rich.querySelector('strong').append('!');rich.dispatchEvent(new w.Event('input',{bubbles:true}));
 const plus=d.querySelector('[data-repeater-path="0.benefits.0.text"]');plus.value='Echte persoonlijke aandacht';plus.dispatchEvent(new w.Event('input',{bubbles:true}));
 d.querySelector('[data-repeater-action="add"][data-nested-path="0.benefits"]').click();assert.equal(JSON.parse(d.querySelector('[data-repeater-value]').value)[0].benefits.length,3);assert(d.querySelector('[data-repeater-action="add"][data-nested-path="0.benefits"]').disabled);
 d.querySelector('[data-repeater-action="remove"][data-nested-path="0.benefits"][data-row="2"]').click();
 d.querySelector('[data-nested-media="0.background"]').click();await wait();d.querySelector('[data-media-id="2"]').click();d.querySelector('[data-media-confirm]').click();await wait();
 let current=JSON.parse(d.querySelector('[data-repeater-value]').value);assert.equal(current[0].background.ID,2);assert.equal(current[0].benefits[0].text,'Echte persoonlijke aandacht');assert.equal(current[0].benefits[1].text,rows[0].benefits[1].text);assert.equal(current[0].text,'<p>We <strong>helpen!</strong> elkaar.</p>');
 d.querySelector('.pkca__layout-save').click();await wait();assert.equal(posts.at(-1).changes.length,1);assert.equal(posts.at(-1).changes[0].value[0].background.ID,2);assert.equal(posts.at(-1).changes[0].value[0].benefits.length,2);
 // Reopen the unchanged source to test optional media removal and inline leaves.
 pending=[];w.api.openLayoutEditor(0);d.querySelector('[data-nested-clear="0.background"]').click();assert.equal(JSON.parse(d.querySelector('[data-repeater-value]').value)[0].background,false);
 d.querySelector('.pkca__layout-editor-header button').click();d.querySelector('.pkca__inline-toggle').click();const li=d.querySelector('li');assert(li.classList.contains('pkca-inline-editable'),'List items from nested repeaters are editable inline');li.click();const editor=d.querySelector('[contenteditable=true].pkca-inline-active');editor.append('!');d.querySelector('[data-inline-save]').click();await wait();assert.deepEqual(posts.at(-1).changes[0].field_path,['cards','0','benefits','0','text']);
 dom.window.close();console.log('PASS: nested pluspoints add/remove/edit, rich text, image picker and removal preserve sibling content; inline list edits use the full nested path.');
})().catch(e=>{console.error(e);process.exitCode=1;});
