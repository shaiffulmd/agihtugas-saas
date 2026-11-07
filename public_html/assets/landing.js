/* Halaman awam platform: menu mudah alih, pautan semasa, pendedahan skrol, galeri bertab, tagline perkataan demi
 * perkataan, dan bantuan borang pendaftaran (kemajuan, kod KPM, kekuatan kata laluan, semakan sisi klien).
 * Penambahbaikan progresif sahaja: tanpa JS, halaman & borang berfungsi seperti biasa (semakan di pelayan). */
(function(){
  'use strict';
  var reduce=window.matchMedia&&window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  function $(s,r){return (r||document).querySelector(s)}
  function $$(s,r){return Array.prototype.slice.call((r||document).querySelectorAll(s))}

  function init(){
    /* Menu mudah alih */
    var btn=$('.lx-menu'), sheet=$('#lx-sheet');
    if(btn&&sheet){
      var setMenu=function(open){
        btn.setAttribute('aria-expanded',open?'true':'false'); btn.setAttribute('aria-label',open?'Tutup menu':'Buka menu');
        sheet.classList.toggle('is-open',open); sheet.setAttribute('aria-hidden',open?'false':'true');
        document.body.classList.toggle('lx-locked',open);
      };
      btn.addEventListener('click',function(){setMenu(btn.getAttribute('aria-expanded')!=='true')});
      sheet.addEventListener('click',function(e){if(e.target.closest('a'))setMenu(false)});
      document.addEventListener('keydown',function(e){if(e.key==='Escape')setMenu(false)});
    }

    /* Pautan nav semasa (seksyen halaman utama) */
    var links=$$('.lx-links a[href^="/#"]');
    if(links.length&&'IntersectionObserver' in window&&location.pathname==='/'){
      var nio=new IntersectionObserver(function(es){es.forEach(function(e){if(!e.isIntersecting)return;var id='/#'+e.target.id;
        links.forEach(function(a){if(a.getAttribute('href')===id)a.setAttribute('aria-current','page');else a.removeAttribute('aria-current')})})},{rootMargin:'-45% 0px -50% 0px'});
      links.forEach(function(a){var s=document.getElementById(a.getAttribute('href').slice(2));if(s)nio.observe(s)});
    }

    /* Pendedahan seksyen: hanya elemen di bawah skrin awal, sekali sahaja */
    if(!reduce&&'IntersectionObserver' in window){
      var io=new IntersectionObserver(function(es){es.forEach(function(e){if(e.isIntersecting){e.target.classList.remove('is-pending');io.unobserve(e.target)}})},{rootMargin:'0px 0px -8% 0px'});
      $$('.lx-rv').forEach(function(el){if(el.getBoundingClientRect().top>window.innerHeight){el.classList.add('is-pending');io.observe(el)}});
    }

    /* Galeri skrin bertab */
    var tabs=$$('.lx-tabs [role="tab"]');
    function sel(t,focus){tabs.forEach(function(b){var on=b===t;b.setAttribute('aria-selected',on?'true':'false');b.tabIndex=on?0:-1;
      var p=document.getElementById(b.getAttribute('aria-controls'));if(p){p.hidden=!on;p.classList.toggle('is-in',on)}});if(focus)t.focus()}
    tabs.forEach(function(b,i){b.addEventListener('click',function(){sel(b)});
      b.addEventListener('keydown',function(e){var d=e.key==='ArrowRight'?1:e.key==='ArrowLeft'?-1:0;if(d){e.preventDefault();sel(tabs[(i+d+tabs.length)%tabs.length],true)}})});

    /* Tagline: setiap perkataan bertukar warna mengikut skrol */
    var tg=$('.lx-tagline p');
    if(tg&&!reduce){
      var acc=(tg.getAttribute('data-acc')||'').toLowerCase(), lines=[], cur=[];
      Array.prototype.forEach.call(tg.childNodes,function(n){if(n.nodeName==='BR'){lines.push(cur.join(' '));cur=[]}else cur.push(n.textContent)});
      lines.push(cur.join(' ')); tg.textContent='';
      lines.forEach(function(line,li){
        line.trim().split(/\s+/).forEach(function(w){if(!w)return;var s=document.createElement('span');s.className='lx-w'+(acc&&w.toLowerCase().indexOf(acc)===0?' is-acc':'');s.textContent=w;tg.appendChild(s);tg.appendChild(document.createTextNode(' '))});
        if(li<lines.length-1)tg.appendChild(document.createElement('br'));
      });
      tg.parentNode.closest('.lx-tagline').classList.add('is-live');
      var words=$$('.lx-w',tg), ticking=false;
      var upd=function(){ticking=false;var r=tg.getBoundingClientRect(),vh=window.innerHeight;
        var p=Math.max(0,Math.min(1,(vh*0.85-r.top)/(r.height+vh*0.35))),n=Math.round(p*words.length);
        for(var i=0;i<n;i++)words[i].classList.add('on')};
      window.addEventListener('scroll',function(){if(!ticking){ticking=true;requestAnimationFrame(upd)}},{passive:true});
      upd();
    }

    initRegistration();
  }

  /* ---------- Borang pendaftaran ---------- */
  function initRegistration(){
    var form=$('#lx-daftar'); if(!form)return;
    var touched={};
    var rules={
      kod:function(v){return /^[A-Za-z]{3}[0-9]{4}$/.test(v.trim())},
      nama:function(v){return v.trim().length>0&&v.length<=150&&!/(https?:\/\/|www\.)/i.test(v)},
      negeri:function(v){return v!==''},
      pic_nama:function(v){return v.trim().length>0},
      pic_emel:function(v){return /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(v.trim())},
      pic_telefon:function(v){var d=v.replace(/[^0-9+]/g,'');return d.length>=9&&d.length<=15},
      password:function(v){return v.length>=12&&v.length<=128},
      password_confirm:function(v){return v.length>0&&v===form.elements.password.value},
      setuju:function(){return form.elements.setuju.checked}
    };
    function val(n){var el=form.elements[n];return !el?'':el.type==='checkbox'?'':el.value}
    function check(n,show){
      var f=form.querySelector('[data-f="'+n+'"]'), ok=rules[n](val(n)); if(!f)return ok;
      f.classList.toggle('is-valid',ok&&n!=='setuju'&&n!=='negeri');
      if(show||touched[n]){f.classList.toggle('is-invalid',!ok);var el=form.elements[n];if(el&&el.setAttribute)el.setAttribute('aria-invalid',ok?'false':'true')}
      return ok;
    }
    function progress(){
      $$('fieldset[data-pg]',form).forEach(function(fs){
        var names=$$('[data-f]',fs).map(function(f){return f.getAttribute('data-f')}).filter(function(n){return rules[n]});
        var done=names.filter(function(n){return rules[n](val(n))}).length, p=names.length?done/names.length:1;
        var pg=document.getElementById(fs.getAttribute('data-pg')); if(!pg)return;
        var bar=$('.lx-bar i',pg); if(bar)bar.style.setProperty('--p',String(p)); pg.classList.toggle('is-done',p===1);
      });
    }
    var kod=form.elements.kod, prev=$('#lx-url'), prevKod=$('#lx-url-kod');
    if(kod)kod.addEventListener('input',function(){var p=kod.selectionStart;kod.value=kod.value.toUpperCase().replace(/\s+/g,'');try{kod.setSelectionRange(p,p)}catch(e){}
      if(prev){var ok=rules.kod(kod.value);prev.hidden=!ok;if(prevKod)prevKod.textContent=kod.value}});
    var pw=form.elements.password, meter=$('#lx-meter');
    function strength(){if(!pw||!meter)return;var v=pw.value,r={len:v.length>=12,'case':/[a-z]/.test(v)&&/[A-Z]/.test(v),num:/\d/.test(v),sym:/[^A-Za-z0-9]/.test(v)};
      $$('#lx-pw-rules li').forEach(function(li){li.classList.toggle('is-ok',!!r[li.getAttribute('data-r')])});
      var s=v?Object.keys(r).filter(function(k){return r[k]}).length:0;if(!r.len)s=Math.min(s,1);meter.setAttribute('data-s',String(s))}
    if(pw)pw.addEventListener('input',function(){strength();if(touched.password_confirm)check('password_confirm')});
    $$('.lx-tog',form).forEach(function(b){b.addEventListener('click',function(){var i=document.getElementById(b.getAttribute('data-for'));if(!i)return;var show=i.type==='password';
      i.type=show?'text':'password';b.setAttribute('aria-label',show?'Sembunyi kata laluan':'Tunjuk kata laluan');b.setAttribute('aria-pressed',show?'true':'false');
      var u=$('use',b);if(u)u.setAttribute('href',show?'#i-eye-off':'#i-eye')})});
    Object.keys(rules).forEach(function(n){var el=form.elements[n];if(!el||!el.addEventListener)return;
      el.addEventListener('input',function(){check(n);progress()});
      el.addEventListener('change',function(){touched[n]=true;check(n);progress()});
      el.addEventListener('blur',function(){if(el.value!==''){touched[n]=true;check(n)}});
    });
    form.addEventListener('submit',function(e){
      var first=null;Object.keys(rules).forEach(function(n){touched[n]=true;if(!check(n,true)&&!first)first=form.elements[n]});
      progress(); var err=$('#lx-form-err'); if(err)err.hidden=!first;
      if(first){e.preventDefault();if(first.focus)first.focus();return}
      var go=$('#lx-go'); if(go){go.classList.add('is-loading');go.setAttribute('aria-busy','true');var t=$('.lx-go-tx',go);if(t)t.textContent='Menghantar pendaftaran'}
    });
    progress(); strength();
    if(kod&&prev&&rules.kod(kod.value)){prev.hidden=false;if(prevKod)prevKod.textContent=kod.value.toUpperCase()}
    var rk=$('#lx-r-kod'); if(rk)rk.addEventListener('input',function(){rk.value=rk.value.toUpperCase()});
  }

  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);else init();
})();
