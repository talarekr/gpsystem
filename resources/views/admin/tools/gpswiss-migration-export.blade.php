<!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="csrf-token" content="{{ csrf_token() }}"><title>Eksport GPSwiss → Wystawczesc</title>
<style>body{max-width:1100px;margin:30px auto;padding:20px;font:16px system-ui}button,a{margin:8px;padding:10px}pre{white-space:pre-wrap;background:#f1f5f4;padding:18px}</style></head><body>
<h1>Eksport części i oryginałów GPSwiss → Wystawczesc</h1>
<p>Eksport tworzy prywatne pliki. Nie zmienia części, zdjęć, cen, stanów, ofert ani synchronizacji. Nie wykonuje importu ani API Allegro/eBay/Ovoko.</p>
<p>Jeden spójny snapshot bazy → próbka do 5 rzeczywistych części i pełna paczka w tym samym formacie. Nazwa oryginału pozostaje w manifeście; w paczce używamy kodu części + ID części i zdjęcia. Brakujące/zmienione pliki są raportowane.</p>
<p>Paczka zawiera kody, kategorię, jakość, pozycję, magazyn, opis, lokalne/Ovoko ID auta, ceny sklep/Allegro/Ovoko/eBay, Kurier Allegro, lokalne parametry i linki ofert. Nie zawiera klientów, zamówień ani credentials. Przed importem sprawdź publiczne opisy i metadane.</p>
<p>Snapshot bazy nie jest globalnym snapshotem filesystemu. Kopie plików są weryfikowane podczas kopiowania; finalny cutover wymaga osobnej procedury freeze/snapshotu. Nie zatrzymujemy synchronizacji automatycznie.</p>
<button id="start">Przygotuj próbkę i pełny eksport</button><button id="resume">Wznów ten sam eksport</button><button id="pause">Zatrzymaj lokalną pętlę</button>
<p id="message" role="status"></p><pre id="state"></pre>
<a id="sample" hidden>Pobierz próbkę ZIP</a><a id="full" hidden>Pobierz pełny ZIP</a>
<p>Dla dużego archiwum zalecane CLI: <code>php artisan gps:migration-export --owner-id=ID_OPERATORA</code>. Wznowienie: <code>--run=UUID</code>. Nie uruchamiaj drugiego eksportu, gdy trwa pierwszy.</p>
<script>
(()=>{
 const el=id=>document.getElementById(id),base=window.location.pathname.replace(/\/$/,'');
 let id=new URLSearchParams(location.hash.slice(1)).get('run'),active=false,working=false;
 async function request(action){
  const url=base+(id?'/'+encodeURIComponent(id):'');
  const response=await fetch(url,{method:action?'POST':'GET',credentials:'same-origin',cache:'no-store',redirect:'error',headers:{Accept:'application/json',...(action?{'Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content}:{})},...(action?{body:JSON.stringify({action})}:{})});
  const data=await response.json();if(!response.ok)throw new Error(data.reason_code ?? 'HTTP '+response.status);
  id=data.run_id;history.replaceState(null,'','#run='+id);el('state').textContent=JSON.stringify(data,null,2);
  for(const kind of ['sample','full']){el(kind).hidden=data.state!=='completed';el(kind).href=base+'/'+id+'?download='+kind;}
  return data;
 }
 async function drive(start){
  if(working)return;working=true;active=true;el('start').disabled=true;el('resume').disabled=true;
  try{
   if(start){id=crypto.randomUUID();history.replaceState(null,'','#run='+id);await request('start');}
   if(!id)throw new Error('Brak run_id; rozpocznij eksport.');
   let state=await request();
   while(active && ['photos','archive'].includes(state.state)){
    state=await request('tick');if(state.last_error)throw new Error(state.last_error);
    await new Promise(resolve=>setTimeout(resolve,state.busy?1000:150));
   }
   el('message').textContent=state.state==='completed'?'Obie paczki gotowe. Sprawdź manifest i missing-originals.json.':'Stan zachowany; możesz wznowić ten sam eksport.';
  }catch(error){el('message').textContent='Eksport przerwany: '+error.message+'. Jeśli run_id jest w adresie, kliknij Wznów; nie twórz nowego eksportu.';}
  finally{working=false;active=false;el('start').disabled=false;el('resume').disabled=false;}
 }
 el('start').onclick=()=>drive(true);el('resume').onclick=()=>drive(false);el('pause').onclick=()=>{active=false;};
 if(id)request().catch(()=>el('message').textContent='Nie udało się odczytać eksportu. Wznów po odzyskaniu sesji/sieci.');
})();
</script></body></html>
