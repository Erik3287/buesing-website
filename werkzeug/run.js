global.FS=require('fs');
// Faehrt die App-Logik + einen Testschnipsel im SELBEN Scope (wegen let/const)
const fs=require('fs');
const src=fs.readFileSync(__dirname+'/../Buesing_LV_App_v2.html','utf8');
const blocks=[...src.matchAll(/<script[^>]*>([\s\S]*?)<\/script>/g)].map(m=>m[1]);
const stubEl=new Proxy({style:{},classList:{add(){},remove(){}},value:'',textContent:'',innerHTML:'',
  querySelector:()=>stubEl,querySelectorAll:()=>[],appendChild(){},removeChild(){},remove(){},addEventListener(){},focus(){},click(){}},
  {get:(t,k)=> k in t?t[k]:stubEl});
// JEDE id bekommt ihr eigenes Element. Vorher lieferte getElementById immer
// dasselbe Objekt zurueck - wer 'lv-tit' setzte und 'lv-sum' las, bekam den
// zuletzt geschriebenen Wert. Tests, die mehrere Felder pruefen, waren damit
// wertlos (aufgefallen bei t295 am 01.09.2026).
const _el={};
function neuesEl(){
  return new Proxy({style:{},classList:{add(){},remove(){},toggle(){}},value:'',textContent:'',innerHTML:'',
    querySelector:()=>stubEl,querySelectorAll:()=>[],appendChild(){},removeChild(){},remove(){},
    addEventListener(){},focus(){},click(){}},
    {get:(t,k)=> k in t?t[k]:stubEl});
}
global.document={
  getElementById:(id)=>{ if(!(id in _el)) _el[id]=neuesEl(); return _el[id]; },
  querySelector:()=>stubEl, querySelectorAll:()=>[], addEventListener(){},
  createElement:()=>neuesEl(), body:stubEl};
global.window={addEventListener(){},print(){},location:{href:''}};
// Ein echter Speicher im Arbeitsspeicher - vorher gab getItem immer null
// zurueck, dadurch setzte werteSetzen() die Werte sofort wieder auf den
// Auslieferungsstand und Tests zum Haupt-Kalkulator liefen ins Leere.
const _mem={};
global.localStorage=global.sessionStorage={
  getItem:k=>(k in _mem?_mem[k]:null), setItem(k,v){_mem[k]=String(v);},
  removeItem(k){delete _mem[k];}, clear(){for(const k in _mem) delete _mem[k];}};
global.fetch=()=>new Promise(()=>{});          // haengt still, statt Fehler zu spucken
global.alert=()=>{};global.confirm=()=>true;global.navigator={userAgent:'node'};
global.LVDATEN=JSON.parse(require('fs').readFileSync(__dirname+'/lv_positionen.json','utf8'));
global.pdfjsLib={GlobalWorkerOptions:{},getDocument:()=>({promise:new Promise(()=>{})})};
global.schreibJSON=(f,o)=>fs.writeFileSync(f, JSON.stringify(o,null,1));
global.readJSON=f=>JSON.parse(fs.readFileSync(f,'utf8'));
const test=fs.readFileSync(process.argv[2],'utf8');
(0,eval)(blocks.join('\n;\n')+'\n;\n'+test);
