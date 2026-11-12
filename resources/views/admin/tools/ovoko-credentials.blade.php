<!doctype html>
<html lang="pl">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}"><meta name="robots" content="noindex,nofollow">
<title>Odczyt zapisanych danych Ovoko</title>
<style>body{font:16px system-ui;max-width:800px;margin:40px auto;padding:20px}button{font:inherit;padding:10px;margin:8px 8px 8px 0}pre{white-space:pre-wrap;overflow-wrap:anywhere;background:#f3f4f6;padding:16px}#error{color:#a12222}</style>
</head>
<body>
<a href="/admin">← Panel GPSwiss</a>
<h1>Odczyt zapisanych danych API Ovoko</h1>
<p>Dostęp tylko dla OwnerAdmin. Kliknięcie odszyfruje zapisany username, password i user_token. Nie zapisuje zmian ani nie łączy się z Ovoko.</p>
<button type="button" id="reveal">Pokaż zapisane dane</button>
<button type="button" id="hide" disabled>Ukryj i wyczyść</button>
<p id="error" role="alert"></p><pre id="values" hidden></pre>
<script>
const reveal=document.getElementById('reveal'),hide=document.getElementById('hide'),values=document.getElementById('values'),error=document.getElementById('error');
function clearValues(){values.textContent='';values.hidden=true;hide.disabled=true;}
hide.onclick=clearValues;
window.addEventListener('pagehide',clearValues);
reveal.onclick=async()=>{
 clearValues();error.textContent='';reveal.disabled=true;
 try{
  const response=await fetch(@json(route('admin.tools.ovoko-credentials', [], false)),{method:'POST',cache:'no-store',credentials:'same-origin',headers:{Accept:'application/json','Content-Type':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content},body:JSON.stringify({action:'read_saved_credentials'})});
  const data=await response.json();
  if(!response.ok){const messages={ovoko_account_not_found:'Nie znaleziono konta ovoko_main.',ovoko_credentials_cannot_be_decrypted:'Nie można odszyfrować danych obecną konfiguracją aplikacji.',ovoko_credentials_invalid_format:'Zapisane dane mają nieprawidłowy format.'};error.textContent=messages[data.error]||'Nie udało się odczytać danych.';return;}
  values.textContent=JSON.stringify(data.credentials,null,2);values.hidden=false;hide.disabled=false;
 }catch(e){error.textContent='Błąd odczytu. Odśwież stronę i spróbuj ponownie.';}
 finally{reveal.disabled=false;}
};
</script>
</body></html>
