export function createPicker(input, select) {
  const panel=document.createElement('div');panel.className='picker-options';panel.id=input.id+'-options';panel.hidden=true;panel.setAttribute('role','listbox');
  input.insertAdjacentElement('afterend',panel);
  input.setAttribute('role','combobox');input.setAttribute('aria-autocomplete','list');input.setAttribute('aria-controls',panel.id);input.setAttribute('aria-expanded','false');
  select.hidden=true;select.required=false;let active=-1,editing=false;
  function close(){panel.hidden=true;input.setAttribute('aria-expanded','false');input.removeAttribute('aria-activedescendant');active=-1;editing=false;}
  function choose(value){select.value=value;input.value=select.selectedOptions[0]?.textContent || '';close();select.dispatchEvent(new Event('change',{bubbles:true}));}
  function render(){
    panel.replaceChildren();const query=input.value.toLocaleLowerCase('ru'),selected=select.selectedOptions[0];
    const items=[...select.options].filter(o=>o.value && (!query || query===selected?.textContent.toLocaleLowerCase('ru') || o.textContent.toLocaleLowerCase('ru').includes(query)));
    for(const option of items){const button=document.createElement('button');button.type='button';button.setAttribute('role','option');button.id=panel.id+'-'+panel.children.length;button.textContent=option.textContent;button.dataset.value=option.value;button.addEventListener('pointerdown',e=>e.preventDefault());button.addEventListener('click',()=>choose(option.value));panel.append(button);}
    if(!items.length){const text=document.createElement('span');text.textContent=select.options[0]?.textContent || 'Нет результатов';panel.append(text);}
    panel.hidden=false;input.setAttribute('aria-expanded','true');active=-1;
  }
  input.addEventListener('focus',()=>{if(!input.readOnly){editing=true;render();}});
  input.addEventListener('input',()=>{editing=true;select.value='';render();});
  input.addEventListener('blur',()=>setTimeout(close,100));
  input.addEventListener('keydown',e=>{
    if(e.key==='Escape'){close();return;}
    if(e.key==='ArrowDown'||e.key==='ArrowUp'){e.preventDefault();if(panel.hidden)render();const items=[...panel.querySelectorAll('[role=option]')];if(!items.length)return;active=(active+(e.key==='ArrowDown'?1:-1)+items.length)%items.length;items.forEach((el,i)=>el.setAttribute('aria-selected',String(i===active)));input.setAttribute('aria-activedescendant',items[active].id);items[active].scrollIntoView({block:'nearest'});}
    if(e.key==='Enter'&&!panel.hidden){e.preventDefault();const items=panel.querySelectorAll('[role=option]');if(items.length)choose(items[Math.max(active,0)].dataset.value);}
  });
  const sync=()=>{if(select.value && (document.activeElement!==input || !editing))input.value=select.selectedOptions[0]?.textContent || '';if(document.activeElement===input&&editing&&!input.readOnly)render();};
  new MutationObserver(sync).observe(select,{childList:true,subtree:true});
  select.addEventListener('change',()=>{input.value=select.selectedOptions[0]?.textContent || '';close();});
  return {sync,close};
}
