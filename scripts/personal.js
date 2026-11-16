// Keep fixed navigation away from the on-screen keyboard and modal forms.
(()=>{
 const viewport=window.visualViewport;
 const update=()=>{
  const height=viewport?viewport.height:window.innerHeight;
  document.documentElement.style.setProperty('--viewport-height',`${height}px`);
  const editing=/^(INPUT|TEXTAREA|SELECT)$/.test(document.activeElement?.tagName||'');
  document.body.classList.toggle('keyboard-open',editing && window.innerHeight-height>120);
 };
 viewport?.addEventListener('resize',update);
 window.addEventListener('resize',update);
 document.addEventListener('focusin',update);
 document.addEventListener('focusout',()=>setTimeout(update,100));
 update();
})();
