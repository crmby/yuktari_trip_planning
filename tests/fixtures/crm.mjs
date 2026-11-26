import { CONTACT_FIELDS, DEAL_FIELDS } from '../../src/bitrix/fields.js';
import { createCrm } from '../../src/bitrix/crm.js';
import { FUNNELS, createDemoState } from '../../public/js/planner.js';

export const context = { id:'7', name:'Тестовый менеджер', isAdmin:true, portal:'example.bitrix24.by', authorization:'Bearer vibe_session_test' };
const canonical = code => 'ufCrm' + code.replace('UF_CRM_', '').replaceAll('_', '');
export function fixture() {
  const contactKeys = Object.fromEntries(Object.entries(CONTACT_FIELDS).map(([p,c])=>[p,canonical(c)]));
  const dealKeys = Object.fromEntries(Object.entries(DEAL_FIELDS).map(([p,c])=>[p,canonical(c)]));
  const schema = keys => ({fields:Object.fromEntries(Object.entries(keys).map(([p,key])=>[key,{type:p==='duration'||p==='years'?'double':p==='date'?'date':'string'}]))});
  const deals = new Map(['101','102'].map((id,i)=>[id,{id, title:`Тестовая сделка ${id}`, categoryId:'0',contactId:String(201+i),currency:'BYN',stageSemanticId:'P',...Object.fromEntries(Object.entries(dealKeys).map(([p,key])=>[key,({date:'',windowStart:'',windowEnd:'',duration:30,comment:'Отдельный комментарий',years:3})[p]]))}]));
  const contacts = new Map(['201','202'].map(id=>[id,{id,name:'Тестовый клиент',phone:'+375000000000',...Object.fromEntries(Object.entries(contactKeys).map(([p,key])=>[key,({address:'Тестовый адрес',apartment:'14',route:81})[p]]))}]));
  const writes = [];
  let failDealWrite = false, loseDealReply = false;
  async function call(path, options={}) {
    const url = new URL(path,'https://test.invalid'), parts=url.pathname.split('/').filter(Boolean), [entity,id,related]=parts;
    if (path==='/me') return {success:true,data:{portal:context.portal,type:'oauth_app',currentUser:{bitrixUserId:7}}};
    if (path==='/users/me') return {success:true,data:{id:7,name:'Менеджер',active:true,isAdmin:true}};
    if (path==='/deals/fields') return {data:schema(dealKeys)};
    if (path==='/contacts/fields') return {data:schema(contactKeys)};
    if (path==='/userfields/contacts') return {data:[{fieldName:CONTACT_FIELDS.route,settings:{IBLOCK_ID:55},multiple:'N'}]};
    if (path.startsWith('/categories/2?')) return {data:FUNNELS.map((name,i)=>({id:[6,0,8,10][i],name}))};
    if (entity==='lists' && related==='type') return {data:{iblockTypeId:'lists'}};
    if (entity==='lists') return url.searchParams.get('start')==='0' ? {data:[{ID:'81',NAME:'Тестовый маршрут'}],meta:{next:50}} : {data:[{ID:'82',NAME:'Другой маршрут'}],meta:{}};
    if (entity==='users') return {data:id?{id:Number(id),lastName:'Сотрудник',name:'Иван',active:true}:[{id:8,lastName:'Сотрудник',name:'Иван',active:true}]};
    if (entity==='placements') { writes.push({path,...options}); return {success:true,data:{}}; }
    if (entity==='deals' && related==='products') return {data:[{productName:'Услуга 1',price:35,quantity:2},{productName:'Услуга 2',price:15,quantity:1}]};
    if (!id && entity==='deals') {
      const params=url.searchParams,filter=params.has('filter')?JSON.parse(params.get('filter')):{};
      let items=[...deals.values()].filter(d=>{
        if(filter.categoryId?.$in && !filter.categoryId.$in.includes(Number(d.categoryId)))return false;
        const allowed=params.getAll('filter[categoryId][$in][0]').length ? Array.from({length:4},(_,i)=>params.get('filter[categoryId][$in]['+i+']')) : null;
        if(allowed && !allowed.includes(String(d.categoryId)))return false;
        if(filter.closed===false && d.closed===true)return false;
        if(filter.title?.$contains && !d.title.toLowerCase().includes(filter.title.$contains.toLowerCase()))return false;
        if(typeof filter.id==='number' && Number(d.id)!==filter.id)return false;
        if(filter.id?.$gt && Number(d.id)<=filter.id.$gt)return false;
        return !params.has('filter['+dealKeys.date+']') || d[dealKeys.date]===params.get('filter['+dealKeys.date+']');
      }).sort((a,b)=>Number(a.id)-Number(b.id));
      const limit=Number(params.get('limit') || 50),hasMore=items.length>limit;items=items.slice(0,limit);
      return {data:structuredClone(items),meta:{hasMore,...(hasMore?{nextAfterId:items.at(-1).id}:{})}};
    }

    const value=(entity==='deals'?deals:contacts).get(id);
    if (!value) throw new Error('Unknown fixture path: '+path);
    if (options.method==='PATCH') {
      writes.push({path,...options});
      if (entity==='deals' && failDealWrite) throw new Error('simulated unavailable CRM');
      Object.assign(value,options.body);
      if (entity==='deals' && loseDealReply) throw new Error('simulated lost response');
    }
    return {data:structuredClone(value)};
  }
  const masters = [{...createDemoState().masters[0],id:'m1',bitrixUserId:'8',groupIds:['g1'],directions:['81']}];
  const groups = [{id:'g1',name:'Тестовая группа',routes:['81'],types:['individual','company'],active:true}];
  return {call,crm:createCrm(call),deals,contacts,contactKeys,dealKeys,writes,masters,groups,
    setFailure(value){failDealWrite=value;},setLostReply(value){loseDealReply=value;}};
}
