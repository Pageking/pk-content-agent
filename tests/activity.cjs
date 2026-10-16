// Verify loading feedback throughout a delayed chat request and its error path.
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict'),{JSDOM}=require('jsdom');
(async()=>{
 const dom=new JSDOM('<main>Pagina blijft beschikbaar</main>',{url:'https://example.test/contact/',runScripts:'outside-only',pretendToBeVisual:true});
 const w=dom.window,d=w.document;let resolveChat;w.structuredClone=structuredClone;
 w.PKContentAgent={postId:123,title:'Contact',restUrl:'/wp-json/pk-content-agent/v1/'};
 w.fetch=(url,options={})=>options.method==='POST'?new Promise(resolve=>{resolveChat=resolve;}):Promise.resolve({ok:true,json:async()=>({post:{id:123},sections:[],changes:[]})});
 w.eval(fs.readFileSync(path.resolve(__dirname,'../assets/agent.js'),'utf8'));
 await new Promise(r=>setTimeout(r,10));
 const status=d.querySelector('.pkca__activity'),form=d.querySelector('.pkca__form'),input=form.querySelector('textarea');
 assert(status.hidden);assert(status.closest('.pkca__panel'));assert.equal(status.getAttribute('role'),'status');
 const submit=()=>{input.value='Vertaal deze pagina';form.dispatchEvent(new w.Event('submit',{bubbles:true,cancelable:true}));};
 submit();assert.equal(status.hidden,false);assert(status.textContent.includes('Even nadenken…'));assert.equal(form.getAttribute('aria-busy'),'true');assert(input.disabled);
 assert.equal(d.querySelector('main').textContent,'Pagina blijft beschikbaar');
 resolveChat({ok:true,json:async()=>({message:'Klaar'})});await new Promise(r=>setTimeout(r,10));
 assert(status.hidden);assert.equal(status.textContent,'');assert.equal(form.getAttribute('aria-busy'),'false');assert.equal(input.disabled,false);
 submit();assert.equal(status.hidden,false);
 resolveChat({ok:false,json:async()=>({message:'Probeer het opnieuw'})});await new Promise(r=>setTimeout(r,10));
 assert(status.hidden);assert.equal(input.disabled,false);assert.equal(input.value,'Vertaal deze pagina');assert(d.querySelector('.pkca__message--error').textContent.includes('Probeer het opnieuw'));
 console.log('PASS: local activity indicator stays visible during chat and clears on success/error; controls recover and retry input is retained.');
 dom.window.close();
})().catch(e=>{console.error(e);process.exitCode=1;});
