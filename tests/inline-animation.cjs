// Animated DOM is presentation, not the source for editable ACF content.
const fs=require('node:fs'),path=require('node:path'),assert=require('node:assert/strict'),{JSDOM}=require('jsdom');
(async()=>{
 const text='Droom jij van een assistant functie?';
 const split=value=>value.split(' ').map(word=>`<div class="word" style="position: relative; display: inline-block;">${[...word].map(char=>`<div class="char" style="position: relative; display: inline-block;" aria-hidden="true">${char}</div>`).join('')}</div>`).join(' ');
 const fields=[{name:'title',path:['title'],label:'Titel',type:'text',acf_type:'text',value:text,editable:true},{name:'subtitle',path:['subtitle'],label:'Subtitel',type:'text',acf_type:'textarea',value:'Eerste regel\nTweede regel',editable:true},{name:'text',path:['text'],label:'Tekst',type:'text',acf_type:'wysiwyg',value:'<p>Behoud <strong>de opmaak</strong> en <a href="/contact/">de link</a>.</p>',editable:true}];
 const section={number:1,row_index:0,layout_index:0,layout:'text_reveal',selectors:['text_reveal-layout'],label:'Onthullende tekst',fields};
 let pending=[],posts=[];
 const context=()=>({post:{id:123},sections:[section],changes:pending,link_targets:[]});
 const dom=new JSDOM(`<div class="flex-repeater"><div class="flex-content"><div class="flex-layout text_reveal-layout"><h2 class="pk-heading"><span class="pk-heading-text" aria-label="${text}">${split(text)}</span></h2><div class="subtitle">Eerste regel<br>Tweede regel</div><div class="pk-text">${fields[2].value}</div></div></div></div>`,{url:'http://localhost:10115/',runScripts:'outside-only',pretendToBeVisual:true});
 const w=dom.window,d=w.document;w.structuredClone=structuredClone;
 w.HTMLDialogElement.prototype.showModal=function(){this.open=true;};w.HTMLDialogElement.prototype.close=function(){this.open=false;};
 w.PKContentAgent={postId:123,title:'Test',restUrl:'/wp-json/pk-content-agent/v1/'};
 w.fetch=async(url,options={})=>{
  if(!options.method)return {ok:true,json:async()=>structuredClone(context())};
  const body=JSON.parse(options.body);posts.push({url,body});
  if(url.endsWith('change')){const edit=body.changes[0],field=fields.find(f=>f.path[0]===edit.field_path[0]);pending=[{...edit,id:field.name,row_index:0,layout_index:0,old_value:field.value,new_value:edit.value}];return {ok:true,json:async()=>({queued:1,changes:structuredClone(pending)})};}
  pending=[];return {ok:true,json:async()=>({changes:[]})};
 };
 w.eval(fs.readFileSync(path.resolve(__dirname,'../assets/agent.js'),'utf8').replaceAll('window.location.reload();','window.testReloads=true;'));
 const wait=()=>new Promise(r=>setTimeout(r,15));await wait();d.querySelector('.pkca__inline-toggle').click();
 const original=d.querySelector('.pk-heading-text'),originalHTML=original.innerHTML;original.querySelector('.char').click();
 let editor=d.querySelector('[contenteditable=true]');assert(editor);assert.notEqual(editor,original);assert(editor.classList.contains('pk-heading-text'),'Keep the theme heading wrapper and styling');
 assert.equal(editor.textContent,text);assert.equal(editor.querySelectorAll('.char,.word').length,0);assert.equal(editor.getAttribute('aria-label'),null);
 d.querySelector('[data-inline-save]').click();await wait();assert.equal(posts.length,0,'Opening a split heading and choosing Done is not a change');assert.equal(d.querySelector('.pk-heading-text'),original);assert.equal(original.innerHTML,originalHTML);
 original.click();editor=d.querySelector('[contenteditable=true]');editor.append('!');
 // A delayed GSAP revert/resize still holds the detached original, not our surface.
 original.innerHTML=split(text);w.dispatchEvent(new w.Event('resize'));
 assert.equal(editor.textContent,text+'!');d.querySelector('[data-inline-save]').click();await wait();
 assert.equal(posts.at(-1).body.changes[0].value,text+'!');assert(!posts.at(-1).body.changes[0].value.includes('\n'));
 d.querySelector('.pk-heading-text').innerHTML=split(text+'!');await wait();
 assert(d.querySelector('h2').classList.contains('pkca-preview-target'),'Animated heading keeps its whole-field highlight after a SplitText rebuild');
 assert(d.querySelector('.pk-heading-text .char'),'Highlighting does not replace animation markup');
 assert(!d.querySelector('.subtitle').classList.contains('pkca-preview-target'),'Unchanged fields are not highlighted');
 d.querySelector('.pkca__review-open').click();assert.equal(d.querySelectorAll('.pkca__review-text')[1].textContent,text+'!');d.querySelector('[data-review-close]').click();
 d.querySelector('.pkca__review-open').click();d.querySelector('[data-review-discard]').click();await wait();assert.equal(d.querySelector('.pk-heading-text').textContent,text);
 d.querySelector('.subtitle').click();editor=d.querySelector('[contenteditable=true]');assert.equal(editor.innerHTML,'Eerste regel<br>Tweede regel');editor.append('!');d.querySelector('[data-inline-save]').click();await wait();assert.equal(posts.at(-1).body.changes[0].value,'Eerste regel\nTweede regel!','Real textarea line breaks are retained');
 d.querySelector('.pkca__review-open').click();d.querySelector('[data-review-discard]').click();await wait();
 d.querySelector('.pk-text').click();editor=d.querySelector('[contenteditable=true]');assert(editor.querySelector('strong'));assert(editor.querySelector('a'));editor.querySelector('strong').append('!');d.querySelector('[data-inline-save]').click();await wait();assert.equal(posts.at(-1).body.changes[0].value,'<p>Behoud <strong>de opmaak!</strong> en <a href="/contact/">de link</a>.</p>');
 d.querySelector('.pkca__review-open').click();d.querySelector('[data-review-discard]').click();await wait();
 const clean=d.querySelector('.pk-heading-text');clean.click();editor=d.querySelector('[contenteditable=true]');editor.textContent='Annuleren';d.querySelector('[data-inline-cancel]').click();assert.equal(d.querySelector('.pk-heading-text'),clean);assert.equal(clean.textContent,text);
 d.querySelector('.pkca__inline-toggle').click();assert.equal(d.querySelectorAll('.pkca-inline-editable,[contenteditable=true]').length,0,'Stopping edit mode clears every temporary marker');
 console.log('PASS: animated character/word wrappers never enter content, no-op and cancel retain original DOM, resize cannot overwrite input, real line breaks and rich text remain intact.');
 dom.window.close();
})().catch(e=>{console.error(e);process.exitCode=1;});
