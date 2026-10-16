// Run with the jsdom dev dependency: node tests/numeric-values.cjs.
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict'),{JSDOM}=require('jsdom');
(async()=>{
 const rows=['10.000','1.700','9','60'].map(number=>({number,suffix:'+'}));
 const field={path:['statistics'],name:'statistics',label:'Cijfers',type:'repeater',acf_type:'repeater',value:rows,editable:true,row_fields:[{path:['number'],name:'number',label:'Getal',type:'number',acf_type:'number'},{path:['suffix'],label:'Achtervoegsel',type:'text'}]};
 const section={number:1,row_index:0,layout_index:0,layout:'statistics',label:'Statistieken',selectors:['statistics'],fields:[field]};
 const context={post:{id:123,title:'Home'},sections:[section],changes:[],link_targets:[]};
 const dom=new JSDOM('<div class="flex-repeater"><div class="flex-content"><div class="flex-layout statistics"></div></div></div>',{url:'http://localhost:10115/',runScripts:'outside-only',pretendToBeVisual:true});
 const w=dom.window,d=w.document;w.structuredClone=structuredClone;
 w.fetch=async()=>({ok:true,json:async()=>structuredClone(context)});
 w.PKContentAgent={restUrl:'/wp-json/pk-content-agent/v1/',postId:123,title:'Home'};
 w.eval(fs.readFileSync(path.resolve(__dirname,'../assets/agent.js'),'utf8').replace(/\}\)\(\);\s*$/,'window.testApi={openLayoutEditor,collectLayoutEdits,reviewValue};})();'));
 await new Promise(r=>setTimeout(r,20));const api=w.testApi;api.openLayoutEditor(0);
 assert.equal(api.collectLayoutEdits(section,[field]).length,0);
 const first=d.querySelector('[data-repeater-input][data-repeater-path="0.number"]');
 first.value='10001';first.dispatchEvent(new w.Event('input',{bubbles:true}));
 const edits=api.collectLayoutEdits(section,[field]);assert.equal(edits.length,1);
 assert.deepEqual(Array.from(edits[0].value,row=>row.number),['10001','1.700','9','60']);
 const item={type:'repeater',old_value:rows,new_value:edits[0].value};
 const review=d.createElement('div');review.innerHTML=api.reviewValue(item,'old',field)+api.reviewValue(item,'new',field);
 assert.deepEqual(Array.from(review.querySelectorAll('li>strong'),el=>el.textContent),['Rij 1','Rij 1']);
 assert(!review.textContent.includes('1.700'));assert(!review.textContent.includes('Rij 2'));
 console.log('PASS: only row 1 changes; 1.700, 9 and 60 remain exact and stay out of the review.');
 dom.window.close();
})().catch(error=>{console.error(error);process.exitCode=1;});
