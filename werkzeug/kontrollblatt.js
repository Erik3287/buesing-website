// ============================================================
// KONTROLLBLATT - was wuerde sich an Eriks fertigen LVs aendern?
// Erik am 01.09.2026: "ich habe angst, dass wir durch neue Anpassungen den
// Stand, den wir beim letzten LV hatten, kaputt machen. Dann passt es zwar
// fuer das aktuelle LV, aber aeltere und neuere LVs werden kaum korrekt
// erkannt bzw. wird alles durcheinander gewuerfelt."
// Dieses Blatt nimmt seine echten, fertig gerechneten LVs, laesst das
// AKTUELLE Regelwerk jede Position noch einmal rechnen und meldet jede
// Abweichung zum gespeicherten Preis. Es aendert nichts - es misst.
// Aufruf:  node run.js kontrollblatt.js
// ============================================================
const AKTEN=[
  ['LV-14  Hainhölzer Str. 8', 'LV14_vollstaendig.json'],
  ['LV-15  Gandistr. 7',       'LV15_NEU_korrigiert_31082026.json'],
];
const f2=n=>Number(n).toLocaleString('de-DE',{minimumFractionDigits:2,maximumFractionDigits:2});
let gAbw=0, gPos=0, gDelta=0, gTyp=0;

console.log('================= KONTROLLBLATT =================');
console.log('App-Stand: '+APP_STAND);

AKTEN.forEach(function(a){
  let d;
  try{ d=readJSON(a[1]); }catch(e){ console.log('\n--- '+a[0]+': Datei fehlt'); return; }
  const pos=(d.positionen||d.pos||[]).map(function(p){
    return Object.assign({}, p, {desc:p.titel||p.desc||''});
  });
  LV={titel:d.titel||'', system:d.system||'sakret', pos:pos};
  delete LV.werte; delete LV.werteStart;
  const abwPreis=[], abwTyp=[];
  let geprueft=0, delta=0;
  pos.forEach(function(p){
    if(p.typ==='ueberschrift') return;
    // 1. Wuerde die Typ-Erkennung diese Position heute anders einordnen?
    const tNeu=erkenneTyp(p.desc, p.langtext, p.einheit);
    if(p.typ && tNeu!=='unbekannt' && tNeu!==p.typ && !p._typHand){
      abwTyp.push({nr:p.nr||'', alt:p.typ, neu:tNeu, titel:p.desc});
    }
    // 2. Wuerde das Regelwerk heute einen anderen Preis rechnen?
    const alt=parseFloat(p.ep)||0;
    if(!(alt>0) || p._epHand) return;
    geprueft++;
    let neu;
    try{ neu=Math.round(((berechneEP(p, LV.system)||{}).ep||0)*100)/100; }catch(e){ return; }
    if(!isFinite(neu)) return;
    if(Math.abs(neu-alt)>0.005){
      abwPreis.push({nr:p.nr||'', titel:p.desc, alt:alt, neu:neu, menge:parseFloat(p.menge)||0});
      delta += (neu-alt)*(parseFloat(p.menge)||0);
    }
  });
  gAbw+=abwPreis.length; gPos+=geprueft; gDelta+=delta; gTyp+=abwTyp.length;

  console.log('\n--- '+a[0]+'   ('+geprueft+' Positionen mit gerechnetem Preis, System '+(d.system||'sakret')+')');
  if(!abwPreis.length){
    console.log('    Preise: keine Abweichung – das Regelwerk rechnet alles wie gespeichert');
  } else {
    console.log('    Preise: '+abwPreis.length+' Position(en) anders   ('+(delta>=0?'+':'')+f2(delta)+' EUR im Ganzen)');
    abwPreis.slice(0,30).forEach(function(x){
      console.log('      '+String(x.nr).padEnd(13)+f2(x.alt).padStart(9)+' ->'+f2(x.neu).padStart(9)
        +'  '+((x.neu-x.alt)>=0?'+':'')+f2(x.neu-x.alt).padStart(8)+'   '+String(x.titel).slice(0,44));
    });
    if(abwPreis.length>30) console.log('      … und '+(abwPreis.length-30)+' weitere');
  }
  if(abwTyp.length){
    console.log('    Typen:  '+abwTyp.length+' Position(en) wuerden heute anders eingeordnet');
    abwTyp.slice(0,15).forEach(function(x){
      console.log('      '+String(x.nr).padEnd(13)+String(x.alt).padEnd(17)+'-> '+String(x.neu).padEnd(17)+String(x.titel).slice(0,40));
    });
    if(abwTyp.length>15) console.log('      … und '+(abwTyp.length-15)+' weitere');
  } else {
    console.log('    Typen:  unveraendert');
  }
});

console.log('\n================================================');
console.log(gAbw
  ? ('ACHTUNG: '+gAbw+' von '+gPos+' Positionen wuerden anders gerechnet · Summe '+(gDelta>=0?'+':'')+f2(gDelta)+' EUR')
  : ('PREISE UNVERAENDERT: '+gPos+' Positionen, kein Cent Unterschied'));
console.log(gTyp ? ('Typ-Erkennung: '+gTyp+' Position(en) wuerden anders eingeordnet') : 'Typ-Erkennung: unveraendert');
console.log('\nWichtig: gespeicherte LVs werden von der App NICHT neu gerechnet.');
console.log('Das Blatt zeigt, was passieren WUERDE, wenn man sie neu rechnen liesse.');

// Wie oft kommt welcher Unterschied vor? Das zeigt, ob es EINE Ursache ist.
