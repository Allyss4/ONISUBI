
document.addEventListener('click',e=>{
 const eye=e.target.closest('[data-detail]');
 if(eye){alert('UI demo only — this page has no database connection.\\n\\n'+eye.dataset.detail);}
});
document.querySelectorAll('[data-nav]').forEach(a=>a.addEventListener('click',()=>localStorage.setItem('adminLastPage',a.dataset.nav)));
