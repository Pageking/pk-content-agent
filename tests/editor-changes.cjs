// Run with the jsdom dev dependency: npm install --prefix tests && node tests/editor-changes.cjs.
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict'),{JSDOM}=require('jsdom');
const root=path.resolve(__dirname,'..');
(async()=>{
 const fields=[
  {path:['heading','text'],name:'text',label:'Titel',type:'text',acf_type:'text',value:'Jouw richting vooruit',editable:true},
  {path:['subtitle'],name:'subtitle',label:'Subtitel',type:'text',acf_type:'textarea',value:'Zoveel expertise.\r\nZo dichtbij.',editable:true},
  {path:['html_title'],name:'html_title',label:'Kop met regelafbreking',type:'text',acf_type:'text',value:'Eerste regel<br>Tweede regel',editable:true},
  {path:['optional'],name:'optional',label:'Optie',type:'option',acf_type:'select',value:'',choices:{first:'Eerste',second:'Tweede'},editable:true}
 ];
 const section={number:1,row_index:0,layout_index:0,layout:'hero',label:'Hero',selectors:['hero-layout'],fields};
 const context={post:{id:123,title:'Home'},sections:[section],changes:[],link_targets:[]};
 const dom=new JSDOM('<div class="flex-repeater"><div class="flex-content"><div class="flex-layout hero-layout"><h1 class="pk-heading-text">Jouw richting vooruit</h1><div class="subtitle">Zoveel expertise.<br>Zo dichtbij.</div></div></div></div>',{url:'http://localhost:10115/',runScripts:'outside-only',pretendToBeVisual:true});
 const w=dom.window,d=w.document;w.structuredClone=structuredClone;let submitted;
 w.fetch=async(url,options)=>{
  if(!options?.method)return {ok:true,json:async()=>structuredClone(context)};
  submitted=JSON.parse(options.body).changes;
  const edit=submitted.find(e=>e.field_path.join('.')==='heading.text');
  const change={...edit,id:'title',old_value:fields[0].value,new_value:edit.value,row_index:0,layout_index:0};
  return {ok:true,json:async()=>({queued:1,unchanged:submitted.length-1,changes:[change]})};
 };
 w.PKContentAgent={restUrl:'/wp-json/pk-content-agent/v1/',postId:123,title:'Home'};
 w.eval(fs.readFileSync(path.join(root,'assets/agent.js'),'utf8').replace(/\}\)\(\);\s*$/,'window.testApi={state,openLayoutEditor,collectLayoutEdits,queueLayoutChanges};})();'));
 await new Promise(r=>setTimeout(r,20));const api=w.testApi;api.openLayoutEditor(0);
 assert.equal(api.collectLayoutEdits(section,fields).length,0,'Opening editor must not change fields');
 const subtitle=d.querySelector('[data-field-index="1"][data-original]');
 assert.equal(subtitle.tagName,'TEXTAREA');assert.equal(subtitle.value,'Zoveel expertise.\nZo dichtbij.');
 const htmlTitle=d.querySelector('[data-field-index="2"][data-original]');assert.equal(htmlTitle.value,'Eerste regel<br>Tweede regel');
 const title=d.querySelector('[data-field-index="0"][data-original]');title.value+='!';title.dispatchEvent(new w.Event('input',{bubbles:true}));
 const edits=api.collectLayoutEdits(section,fields);assert.equal(edits.length,1);assert.equal(edits[0].field_path.join('.'),'heading.text');
 d.querySelector('.pkca__layout-form').dispatchEvent(new w.Event('submit',{bubbles:true,cancelable:true}));
 await new Promise(r=>setTimeout(r,20));assert.equal(submitted.length,1);assert.equal(api.state.context.changes.length,1);
 assert([...d.querySelectorAll('.pkca__message')].some(el=>el.textContent==='1 veld uit layout 1 aangepast.'));
 assert.equal(d.querySelector('.subtitle').innerHTML,'Zoveel expertise.<br>Zo dichtbij.');
 // A batch can contain unchanged values; report the backend's queued count.
 await api.queueLayoutChanges([{section:1,field_path:['heading','text'],type:'text',value:'Jouw richting vooruit!'},{section:1,field_path:['subtitle'],type:'text',value:fields[1].value}],count=>`${count} werkelijk gewijzigd`);
 assert([...d.querySelectorAll('.pkca__message')].some(el=>el.textContent==='1 werkelijk gewijzigd'));
 console.log('PASS: only adding ! submits one title edit; multiline subtitle, HTML line breaks and default select remain unchanged.');
 console.log('PASS: confirmation counts backend-queued edits, excluding unchanged submitted fields.');dom.window.close();
})().catch(error=>{console.error(error);process.exitCode=1});
