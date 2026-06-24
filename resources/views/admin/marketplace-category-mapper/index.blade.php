<!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Mapper kategorii marketplace</title>
    <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <style>
        body{margin:0;background:#f6f7fb;color:#172033;font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.wrap{padding:24px}.top{display:flex;gap:16px;align-items:flex-start;justify-content:space-between;margin-bottom:18px}.back-link{display:inline-flex;align-items:center;margin-bottom:12px;color:#2563eb;text-decoration:none;font-weight:800}.back-link:hover{text-decoration:underline}.title h1{margin:0 0 6px;font-size:26px}.title p{margin:0;color:#667085}.summary{background:#fff;border:1px solid #d9e0ea;border-radius:16px;padding:14px;min-width:320px}.summary strong{display:block}.summary small{color:#667085}.grid{display:grid;grid-template-columns:repeat(4,minmax(240px,1fr));gap:14px}.panel{background:#fff;border:1px solid #d9e0ea;border-radius:16px;overflow:hidden;min-height:620px;display:flex;flex-direction:column}.panel header{padding:14px 16px;border-bottom:1px solid #e7ebf2;background:#fbfcfe}.panel h2{margin:0;font-size:16px}.panel .search{padding:12px 14px;border-bottom:1px solid #edf0f5}.panel input{width:100%;box-sizing:border-box;border:1px solid #ccd5e1;border-radius:10px;padding:10px}.crumbs{display:flex;gap:6px;flex-wrap:wrap;padding:10px 14px;border-bottom:1px solid #edf0f5;color:#667085;font-size:12px}.list{padding:10px;overflow:auto}.row{width:100%;display:flex;align-items:center;justify-content:space-between;gap:10px;border:1px solid transparent;background:#fff;border-radius:12px;padding:10px;text-align:left;cursor:pointer}.row:hover{background:#f4f7fb}.row.is-selected{border-color:#2563eb;background:#eff6ff}.row strong{display:block;font-size:14px}.row small{display:block;color:#667085;margin-top:2px}.actions{padding:14px;border-top:1px solid #edf0f5;margin-top:auto;display:flex;gap:8px;flex-wrap:wrap}.btn{border:0;border-radius:10px;padding:10px 14px;font-weight:700;cursor:pointer}.btn.primary{background:#2563eb;color:#fff}.btn.secondary{background:#eef2f7;color:#172033}.btn:disabled{opacity:.45;cursor:not-allowed}.badge{display:inline-flex;border-radius:999px;padding:4px 8px;font-size:12px;font-weight:700}.badge.none{background:#f2f4f7;color:#667085}.badge.set{background:#ecfdf3;color:#027a48}.badge.changed{background:#fff7ed;color:#c2410c}.placeholder{margin:10px 14px;padding:10px;border-radius:10px;background:#fff7ed;color:#9a3412;font-size:13px}.flash{margin:0 0 14px;padding:12px 14px;border-radius:12px}.flash.ok{background:#ecfdf3;color:#027a48}.flash.err{background:#fef2f2;color:#b42318}@media(max-width:1200px){.grid{grid-template-columns:1fr 1fr}}@media(max-width:760px){.grid{grid-template-columns:1fr}.top{display:block}.summary{margin-top:12px;min-width:0}}
    </style>
</head>
<body>
<div class="wrap" x-data="mapper()" x-init="init()">
    <div class="top">
        <div class="title">
            <a class="back-link" href="/admin">← Powrót</a>
            <h1>Mapper kategorii marketplace</h1>
            <p>Obsługa wybiera kategorie z drzew. Identyfikatory techniczne zapisują się automatycznie w lokalnych mapowaniach.</p>
        </div>
        <div class="summary">
            <strong x-text="selectedLocal ? selectedLocal.path : 'Wybierz kategorię sklepu'"></strong>
            <small x-show="!selectedLocal">Po wyborze lokalnej kategorii zobaczysz status Allegro / Ovoko / eBay.</small>
            <template x-if="selectedLocal"><div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap"><template x-for="channel in channelCodes" :key="channel"><span class="badge" :class="status(channel)" x-text="labels[channel]+': '+statusLabel(channel)"></span></template></div></template>
        </div>
    </div>

    <div class="flash ok" x-show="message" x-text="message"></div>
    <div class="flash err" x-show="error" x-text="error"></div>

    <div class="grid">
        <template x-for="column in columns" :key="column.code">
            <section class="panel">
                <header><h2 x-text="column.label"></h2></header>
                <div class="search"><input type="search" x-model.debounce.350ms="column.search" @input="load(column, true)" placeholder="Szukaj kategorii..."></div>
                <div class="crumbs"><button class="btn secondary" type="button" x-show="column.stack.length" @click="back(column)">← Wstecz</button><span x-text="column.stack.length ? column.stack.map(c => c.name).join(' / ') : 'Poziom główny'"></span></div>
                <div class="placeholder" x-show="column.placeholder" x-text="column.placeholderMessage"></div>
                <div class="list">
                    <template x-for="item in column.items" :key="column.code+'-'+item.id">
                        <button type="button" class="row" :class="{'is-selected': isSelected(column.code,item)}" @click="choose(column,item)">
                            <span><strong x-text="item.name"></strong><small x-text="item.products_count !== undefined && item.products_count !== null ? item.products_count+' produktów' : item.path"></small><small x-show="column.code !== 'local' && item.id" style="font-size:11px;opacity:.65" x-text="'ID: '+item.id"></small></span>
                            <span x-text="item.has_children ? '›' : 'Wybierz'"></span>
                        </button>
                    </template>
                    <p x-show="!column.loading && column.items.length === 0" style="color:#667085;padding:8px">Brak kategorii.</p>
                    <p x-show="column.loading" style="color:#667085;padding:8px">Ładowanie...</p>
                </div>
                <div class="actions" x-show="column.code !== 'local'">
                    <button class="btn secondary" type="button" @click="clearDraft(column.code)" :disabled="!drafts[column.code]">Wyczyść wybór</button>
                    <span class="badge" :class="status(column.code)" x-text="statusLabel(column.code)"></span>
                </div>
            </section>
        </template>
    </div>

    <div style="margin-top:18px;display:flex;justify-content:flex-end"><button class="btn primary" type="button" @click="save()" :disabled="!selectedLocal || saving">Zapisz mapowanie</button></div>
</div>
<script>
function mapper(){return{endpoints:@js($endpoints),labels:{allegro_main:'Allegro',ovoko:'Ovoko',ebay:'eBay'},channelCodes:['allegro_main','ovoko','ebay'],columns:[],selectedLocal:null,current:{},drafts:{},message:'',error:'',saving:false,init(){this.columns=[{code:'local',label:'Kategorie sklepu GPS / Laravel',items:[],stack:[],search:'',loading:false},{code:'allegro_main',label:'Kategorie Allegro',items:[],stack:[],search:'',loading:false},{code:'ovoko',label:'Kategorie Ovoko',items:[],stack:[],search:'',loading:false},{code:'ebay',label:'Kategorie eBay',items:[],stack:[],search:'',loading:false}];this.columns.forEach(c=>this.load(c,false));},url(column){if(column.code==='local')return this.endpoints.localTree;return this.endpoints.channelTree.replace('__CHANNEL__',column.code)},async load(column,reset){column.loading=true;if(reset)column.stack=[];let params=new URLSearchParams();if(column.search.trim())params.set('q',column.search.trim());else if(column.stack.length){params.set(column.code==='local'?'parent_id':'parent_external_id',column.stack[column.stack.length-1].id)}let res=await fetch(this.url(column)+'?'+params,{headers:{Accept:'application/json'}});let data=await res.json();column.items=data.items||[];column.placeholder=!!data.placeholder;column.placeholderMessage=data.message||'';column.loading=false;},async choose(column,item){this.message='';this.error='';if(item.has_children&&!column.search.trim()){column.stack.push(item);await this.load(column,false);return}if(column.code==='local'){this.selectedLocal=item;this.drafts={};await this.loadMapping(item.id);return}if(!this.selectedLocal){this.error='Najpierw wybierz lokalną kategorię sklepu.';return}this.drafts[column.code]={channel:column.code,external_category_id:String(item.id),external_category_name:item.name,external_category_path:item.path||item.name};},async loadMapping(id){let res=await fetch(this.endpoints.mapping.replace('__ID__',id),{headers:{Accept:'application/json'}});let data=await res.json();this.selectedLocal=data.local_category;this.current=data.mappings||{};},back(column){column.stack.pop();this.load(column,false)},isSelected(code,item){if(code==='local')return this.selectedLocal&&String(this.selectedLocal.id)===String(item.id);let d=this.drafts[code];let c=this.current[code];let id=d?d.external_category_id:(c?c.external_category_id:null);return id&&String(id)===String(item.id)},status(code){if(this.drafts[code])return 'changed';if(this.current[code])return 'set';return 'none'},statusLabel(code){if(this.drafts[code])return 'zmienione';if(this.current[code])return 'ustawione';return 'brak mapowania'},clearDraft(code){if(confirm('Wyczyścić niezapisany wybór dla tego kanału?'))delete this.drafts[code]},async save(){this.message='';this.error='';let mappings=Object.values(this.drafts);if(!mappings.length){this.error='Brak zmian do zapisania.';return}this.saving=true;try{let res=await fetch(this.endpoints.save.replace('__ID__',this.selectedLocal.id),{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':document.querySelector('meta[name=csrf-token]').content},body:JSON.stringify({mappings})});let data=await res.json();if(!res.ok||!data.ok)throw new Error(data.message||'Nie udało się zapisać mapowania.');this.message='Mapowanie zapisane lokalnie.';this.drafts={};await this.loadMapping(this.selectedLocal.id)}catch(e){this.error=String(e.message||e)}finally{this.saving=false}}}}
</script>
</body>
</html>
