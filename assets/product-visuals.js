(function(){
  function meta(name){
    var n=(name||'').toLowerCase();
    if(/капуч|латте|раф|америк|эспресс|флэт|кофе|мокк|какао/.test(n)) return {icon:'☕',cls:'coffee'};
    if(/чай|эрл|матча/.test(n)) return {icon:'🍵',cls:'tea'};
    if(/лимонад|тоник|сок|вода|газ|морс/.test(n)) return {icon:'🥤',cls:'cold'};
    if(/чизкейк|десерт|торт|круас|печен|морож/.test(n)) return {icon:'🍰',cls:'dessert'};
    if(/сироп|молоко|сливк|добав/.test(n)) return {icon:'🥛',cls:'cold'};
    return {icon:'◦',cls:''};
  }
  function decorate(cell){
    if(!cell || cell.querySelector('.product-visual')) return;
    var text=(cell.textContent||'').trim();
    if(!text) return;
    var m=meta(text), wrap=document.createElement('span'), icon=document.createElement('span'), label=document.createElement('span');
    wrap.className='product-cell-decorated';icon.className='product-visual '+m.cls;icon.textContent=m.icon;label.className='product-name-text';label.textContent=text;
    while(cell.firstChild) cell.removeChild(cell.firstChild);
    wrap.appendChild(icon);wrap.appendChild(label);cell.appendChild(wrap);
  }
  function byHeading(headingText,cellIndex){
    document.querySelectorAll('h2').forEach(function(h){
      if((h.textContent||'').trim()!==headingText) return;
      var card=h.closest('.card'); if(!card) return;
      card.querySelectorAll('tbody tr').forEach(function(tr){decorate(tr.children[cellIndex]);});
    });
  }
  var path=(location.pathname.split('/').pop()||'index.php').toLowerCase();
  if(path==='products.php') document.querySelectorAll('tbody tr').forEach(function(tr){decorate(tr.children[0]);});
  if(path==='sales.php') document.querySelectorAll('.sales-history tbody tr').forEach(function(tr){decorate(tr.children[1]);});
  if(path==='index.php') byHeading('Самые прибыльные позиции',1);
  if(path==='economics.php'){
    byHeading('ABC/XYZ меню',1);byHeading('Прибыльность категорий',0);
  }
})();
