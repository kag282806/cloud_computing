const kind=document.querySelector('#kind'), category=document.querySelector('#category');
if(kind && category){
 const sync=()=>{for(const o of category.options){o.disabled=o.hidden=o.dataset.kind!==kind.value}
 if(category.selectedOptions[0]?.disabled) category.selectedIndex=[...category.options].findIndex(o=>!o.disabled);};
 kind.addEventListener('change',sync);sync();
}
for(const f of document.querySelectorAll('.delete')) f.addEventListener('submit',e=>{if(!confirm('Xóa giao dịch này?'))e.preventDefault();});
