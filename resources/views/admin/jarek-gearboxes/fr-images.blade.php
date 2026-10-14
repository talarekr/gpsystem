<!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="csrf-token" content="{{ csrf_token() }}"><title>Jarek FR — lokalne warianty zdjęć</title><style>body{font:15px/1.5 system-ui;max-width:1000px;margin:24px auto;padding:16px;color:#18283f}button,input{font:inherit;padding:9px;margin:6px}pre{white-space:pre-wrap;overflow-wrap:anywhere;background:#f3f5f8;padding:16px}input{max-width:100%;width:450px}</style></head><body>
<h1>Jarek FR — lokalne warianty zdjęć</h1>
<p><a href="{{ url('/admin/tools/storefront/fr-translations') }}">← Narzędzia FR</a></p>
<p>Ten sam procesor crop/canvas co dla zwykłych produktów: listing 522×336, produkt 900×675. Przygotowanie dotyczy wyłącznie istniejących lokalnych zdjęć aktywnych ekspozycji Jarka. Bez pobierania z sieci, tworzenia Part i zapisów w bazie. Oryginały pozostają zachowane.</p>
<p>Podgląd nie zapisuje plików. Przygotowanie zapisuje tylko pochodne JPG, manifesty oraz log procesora. Gotowe warianty są pomijane. Kursor można wkleić po ponownym otwarciu strony; od zera przejdziesz też przez wcześniej przygotowane pliki.</p>
<label>Źródłowe ID kursora <input id="after-id" type="number" min="0" value="0"></label><br><label>Numer zdjęcia kursora (0–60) <input id="after-image" type="number" min="0" max="60" value="0"></label><br>
<label>Wpisz PREPARE-JAREK-FR-LOCAL-IMAGES <input id="confirm" autocomplete="off"></label><br>
<button id="preview" type="button">Podgląd następnej partii</button><button id="start" type="button">Przygotuj lokalne warianty od kursora</button><button id="stop" type="button" disabled>Zatrzymaj po bieżącej partii</button>
<p id="status" role="status">Oczekiwanie. Strona nie uruchamia przygotowania automatycznie.</p><pre id="result"></pre>
<script>
(() => {
 const el=id=>document.getElementById(id); let running=false, busy=false, timer=null;
 const buttons=()=>{el('preview').disabled=busy||running;el('start').disabled=busy||running;el('stop').disabled=!running;el('after-id').disabled=busy||running;el('after-image').disabled=busy||running;};
 async function batch(apply){
  if(busy)return;busy=true;buttons();
  try{
   const response=await fetch(window.location.pathname,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content},body:JSON.stringify({action:apply?'prepare':'preview',after_id:Number(el('after-id').value),after_image:Number(el('after-image').value),confirm:el('confirm').value})});
   const data=await response.json();if(!response.ok)throw new Error(data.message||'Błąd HTTP '+response.status);
   el('result').textContent=JSON.stringify(data,null,2);
   if(apply){el('after-id').value=data.cursor.after_id;el('after-image').value=data.cursor.after_image;}
   const blocked=data.results.some(row=>row.status==='error'||row.status==='no_local_images'||row.status==='missing_local_source');
   if(data.done||blocked)running=false;
   el('status').textContent=blocked?'Sprawdź błąd lub brak lokalnych zdjęć. '+(apply?'Kursor zapisano; możesz kontynuować po sprawdzeniu raportu.':'Podgląd bez zapisów i bez zmiany kursora.'):data.done?'Zakończono przebieg. Sprawdź raport partii.':apply?'Partia zakończona.':'Podgląd: brak zapisów. Kursor bez zmian.';
  }catch(error){running=false;el('status').textContent=error.message;}
  finally{busy=false;buttons();if(running)timer=setTimeout(()=>batch(true),3000);}
 }
 el('preview').addEventListener('click',()=>batch(false));
 el('start').addEventListener('click',()=>{running=true;batch(true);});
 el('stop').addEventListener('click',()=>{running=false;clearTimeout(timer);buttons();el('status').textContent='Zatrzymano. Bieżąca partia może zakończyć przygotowanie.';});
})();
</script></body></html>
