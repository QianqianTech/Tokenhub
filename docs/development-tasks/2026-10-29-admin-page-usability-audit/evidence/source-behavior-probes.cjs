// Local source probes only. This script never controls a browser or calls a service.
// Run from the repository root: node docs/development-tasks/2026-10-29-admin-page-usability-audit/evidence/source-behavior-probes.cjs
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const root = path.resolve(__dirname, '../../../..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8').replace(/^\uFEFF/, '');
const sources = Object.fromEntries(['resources', 'domains', 'app', 'table', 'dom', 'dashboard'].map(key => [key, read(`public/admin/assets/js/${key}.js`)]));
const context = vm.createContext({window: {location: {hash: ''}}, console});
function declaration(source, name) {
  const start = source.indexOf(`const ${name} = `);
  if (start < 0) throw new Error(`Missing constant ${name}`);
  const tail = source.slice(start);
  const end = tail.search(/^\s*[}\]];/m);
  if (end < 0) throw new Error(`Cannot locate end of ${name}`);
  return tail.slice(0, end) + tail.slice(end).split('\n')[0];
}
function func(source, name) {
  const pattern = new RegExp(`^(?:export )?(?:async )?function ${name}\\([^]*?^}`, 'm');
  const match = source.match(pattern);
  if (!match) throw new Error(`Missing function ${name}`);
  return match[0].replace(/^export /, '');
}
const cleanModule = source => source.replace(/^import .*;\n/gm, '').replace(/^export /gm, '');
vm.runInContext(cleanModule(sources.resources), context);
vm.runInContext(cleanModule(sources.domains), context);
for (const name of ['RAG_CATEGORY_TABS', 'SETTINGS_SECTION_PAGES', 'SETTINGS_SECTION_CATEGORIES']) {
  vm.runInContext(declaration(sources.table, name), context);
}
vm.runInContext(`globalThis.inventory = {
  resources: Object.entries(resources).map(([key,value])=>({key,label:value.label,group:value.group,form:!!value.form,readOnly:value.readOnly===true,columns:value.columns||[],filters:filterFields(key)})),
  domains: adminDomains,
  ragTabs: RAG_CATEGORY_TABS,
  settingsCategories: SETTINGS_SECTION_CATEGORIES,
  permissions: adminPermissionOptions
};
globalThis.auditUser = {role:'super_admin', permissions:adminPermissionOptions.map(([key])=>key)};`, context);
const results = [];
function record(id, observed, reproduced) { results.push({id, reproduced: !!reproduced, observed}); }
vm.runInContext(`window.location.hash = '#/rag/rag-knowledge-bases'; globalThis.hiddenRoute = parseAdminHash(auditUser);`, context);
record('PROBE-01', context.hiddenRoute, context.hiddenRoute.tabKey === 'rag-build');

vm.runInContext(`
const adminState = {page:'dashboard',domain:'operations',filters:{},user:auditUser};
const adminSidebarState = {mobileOpen:false};
let renderCount=0;
function adminRender(){renderCount++;}
function adminRecordRecentPage(){}
function adminVisibleNavSections(){return [];}
function adminPrimarySectionKeyForPage(){return '';}
function adminCaptureNavScroll(){}
function adminCloseMobileNav(){}
${func(sources.app, 'adminDomainForTab')}
${func(sources.app, 'adminNavigate')}
${func(sources.app, 'adminNavigateDomain')}
`, context);

function node(tag, attrs={}, children=[]) {
  const dataset={...(attrs.dataset||{})};
  for(const [key,value] of Object.entries(attrs)){
    if(key.startsWith('data-'))dataset[key.slice(5).replace(/-([a-z])/g,(_,c)=>c.toUpperCase())]=value;
  }
  const n = {tag, attrs, children:[].concat(children).filter(Boolean), dataset, hidden:!!attrs.hidden,
    classList:{toggle(){},add(){}}, append(...items){this.children.push(...items.filter(Boolean));},
    replaceChildren(...items){this.children=items.filter(Boolean);}};
  return n;
}
context.el = node;
context.clear = n => n.replaceChildren();
context.statusMessage = (text,tone='') => node('p',{text,tone});
context.document = {getElementById:()=>({textContent:''})};
context.settingsFormValues = value=>value;
context.settingsFormPanel = ()=>node('form');
context.createCertificateSettingsPage = ()=>({element:node('form'),statusPanel:null});
context.renderCertificateStatus = async ()=>{};
context.api = async ()=>({data:{sections:{}}});
vm.runInContext(func(sources.table, 'renderSettingsCenterPage'), context);
vm.runInContext(func(sources.dom, 'readFields'), context);
context.emptyForm = {querySelectorAll:()=>[]};
vm.runInContext(`globalThis.clearedPermissionPayload=readFields(emptyForm,[{name:'permissions',type:'multiselect',optional:true}]);`, context);
record('PROBE-04', context.clearedPermissionPayload, !Object.hasOwn(context.clearedPermissionPayload, 'permissions'));
vm.runInContext(`globalThis.ragResourcesWithoutEntry=Object.keys(resources).filter(key=>key.startsWith('rag-')&&!adminDomains.some(d=>d.tabs.includes(key))&&!Object.values(RAG_CATEGORY_TABS).some(tabs=>tabs.some(([tab])=>tab===key)));`, context);
record('PROBE-05', [...context.ragResourcesWithoutEntry], context.ragResourcesWithoutEntry.includes('rag-settings'));
vm.runInContext(`globalThis.unknownCapabilityLabels=['music_generation','realtime'].map(value=>({value,label:valueLabel('capability',value)}));`, context);
record('PROBE-06', context.unknownCapabilityLabels, context.unknownCapabilityLabels.every(row=>row.value===row.label));

(async () => {
  await vm.runInContext(`(async()=>{await adminNavigate('usage-records',{user_id:7});const r=parseAdminHash(auditUser);await adminNavigateDomain(r.domainKey,r.tabKey,true);globalThis.navigationResult={renderCount,filters:adminState.filters};})()`, context);
  record('PROBE-02', context.navigationResult, context.navigationResult.renderCount===2 && Object.keys(context.navigationResult.filters).length===0);
  const content=node('root');
  await context.renderSettingsCenterPage(content);
  const outer=content.children[0], tabs=outer.children[0].children, panels=outer.children[1].children;
  const blank=[];
  for(let i=1;i<tabs.length;i++){
    tabs[i].attrs.onClick();
    if(!panels[i].hidden&&panels[i].children[1].children.every(p=>p.hidden))blank.push(tabs[i].attrs.text);
  }
  record('PROBE-03', {blankCategories:blank}, blank.length===3);

  context.renderContext=async()=>({});
  context.renderTable=(rows,config)=>node('table',{rows,columns:config.columns});
  context.adminErrorMessage=error=>error.message;
  context.api=async()=>{throw new Error('Injected offline error');};
  vm.runInContext(func(sources.table,'renderRows'),context);
  const list=node('list');
  await context.renderRows(list,'users',{columns:['id','username']},{filters:{limit:50}});
  const rendered=list.children[0];
  record('PROBE-07',{tag:rendered.tag,columns:rendered.attrs.columns,row:rendered.attrs.rows?.[0]},rendered.tag==='table'&&!!rendered.attrs.rows?.[0]?.message);
  context.button=(label,onClick)=>node('button',{text:label,onClick});
  vm.runInContext(func(sources.table,'renderRagPreviewPage'),context);
  const preview=context.renderRagPreviewPage({items:[],total:45,page:1,type:'text'},()=>{});
  const flatten=n=>[n,...(n.children||[]).flatMap(flatten)];
  const controls=flatten(preview).filter(n=>n.tag==='button');
  record('PROBE-08',{total:45,page:1,buttons:controls.length},controls.length===0);

  context.renderRagUploadStatusCard=()=>node('status');
  context.renderRagPreviewPage=()=>node('preview');
  vm.runInContext(func(sources.table,'refreshRagUploadDetail'),context);
  const actions={approveButton:{},rejectButton:{},vectorizeButton:{}};
  const state={uploadId:'fixture-upload',previewType:'text',page:1,pageSize:20};
  context.api=async url=>({data:url.endsWith('/preview')?{items:[]}:{review_status:'pending',vectorize_status:'not_started'}});
  await context.refreshRagUploadDetail(state,node('status'),node('preview'),node('output'),()=>{},actions);
  const pending={approveDisabled:actions.approveButton.disabled,rejectDisabled:actions.rejectButton.disabled};
  context.api=async url=>({data:url.endsWith('/preview')?{items:[]}:{review_status:'not_required',preview_status:'ready',vectorize_status:'not_started'}});
  await context.refreshRagUploadDetail(state,node('status'),node('preview'),node('output'),()=>{},actions);
  record('PROBE-09',{pending,reviewNotRequired:{vectorizeDisabled:actions.vectorizeButton.disabled}},pending.approveDisabled&&pending.rejectDisabled&&actions.vectorizeButton.disabled);

  vm.runInContext(func(sources.table,'certificateRequestIsDeployed'),context);
  context.api=async()=>({data:{certificates:[{domains:['audit-fixture.invalid'],ip_addresses:[],status:'expired',valid:false}]}});
  const accepted=await context.certificateRequestIsDeployed({certificate_request_type:'domain',request_domain_names:'audit-fixture.invalid'});
  record('PROBE-10',{matchingExpiredFixtureAccepted:accepted},accepted);

  for(const name of ['numberValue','formatIntegerCost','metricGrid'])vm.runInContext(func(sources.dashboard,name),context);
  const metrics=context.metricGrid({total_cost:0.21});
  const cost=metrics.children.find(n=>n.children?.[0]?.attrs.text==='成本');
  record('PROBE-11',{inputCost:0.21,display:cost.children[1].attrs.text},cost.children[1].attrs.text==='未配置');

  const inventory=JSON.parse(JSON.stringify(context.inventory));
  const evidence={method:'Local execution of extracted, unmodified source functions with fake DOM/data. No browser rendering, authenticated APIs, production mutations, or real data.',results};
  fs.writeFileSync(path.join(__dirname,'source-inventory.json'),JSON.stringify(inventory,null,2)+'\n');
  fs.writeFileSync(path.join(__dirname,'source-behavior-probes.json'),JSON.stringify(evidence,null,2)+'\n');
  console.log(JSON.stringify({resources:inventory.resources.length,domains:inventory.domains.length,menuPages:inventory.domains.reduce((n,d)=>n+d.tabs.length,0),results},null,2));
  if(results.some(r=>!r.reproduced))process.exitCode=1;
})().catch(error=>{console.error(error);process.exitCode=1;});
