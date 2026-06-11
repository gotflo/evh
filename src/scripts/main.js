/* ============================================================
   ÉGLISE VASES D'HONNEUR, Interactions globales
   ============================================================ */

(() => {
  'use strict';

  /* --------- Navbar : shrink au scroll --------- */
  const nav = document.querySelector('.nav');
  if (nav) {
    const onScroll = () => {
      if (window.scrollY > 24) nav.classList.add('scrolled');
      else nav.classList.remove('scrolled');
    };
    onScroll();
    window.addEventListener('scroll', onScroll, { passive: true });
  }

  /* --------- Menu mobile burger --------- */
  const burger = document.querySelector('.nav-burger');
  const mobileMenu = document.querySelector('.nav-mobile');
  if (burger && mobileMenu) {
    const toggle = () => {
      const open = burger.classList.toggle('open');
      mobileMenu.classList.toggle('open', open);
      document.body.style.overflow = open ? 'hidden' : '';
      burger.setAttribute('aria-expanded', String(open));
    };
    burger.addEventListener('click', toggle);
    mobileMenu.querySelectorAll('a').forEach(a => {
      a.addEventListener('click', () => {
        burger.classList.remove('open');
        mobileMenu.classList.remove('open');
        document.body.style.overflow = '';
        burger.setAttribute('aria-expanded', 'false');
      });
    });
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && mobileMenu.classList.contains('open')) toggle();
    });
  }

  /* --------- Scroll reveal --------- */
  const revealEls = document.querySelectorAll('.reveal');
  if ('IntersectionObserver' in window && revealEls.length) {
    const io = new IntersectionObserver((entries) => {
      entries.forEach(entry => {
        if (entry.isIntersecting) {
          entry.target.classList.add('in-view');
          io.unobserve(entry.target);
        }
      });
    }, { threshold: 0.12, rootMargin: '0px 0px -40px 0px' });
    revealEls.forEach(el => io.observe(el));
  } else {
    revealEls.forEach(el => el.classList.add('in-view'));
  }

  /* --------- Année dynamique footer --------- */
  document.querySelectorAll('[data-year]').forEach(el => {
    el.textContent = String(new Date().getFullYear());
  });

  /* --------- Hero carousel --------- */
  const heroBg = document.querySelector('.hero-bg');
  const heroDotsWrap = document.querySelector('.hero-dots');
  if (heroBg) {
    const slides = heroBg.querySelectorAll('img');
    if (slides.length > 1) {
      let current = 0;
      slides[0].classList.add('active');

      const dots = [];
      if (heroDotsWrap) {
        slides.forEach((_, i) => {
          const dot = document.createElement('button');
          dot.className = 'hero-dot' + (i === 0 ? ' active' : '');
          dot.setAttribute('aria-label', `Image ${i + 1}`);
          dot.addEventListener('click', () => go(i));
          heroDotsWrap.appendChild(dot);
          dots.push(dot);
        });
      }

      const go = (i) => {
        slides[current].classList.remove('active');
        if (dots[current]) dots[current].classList.remove('active');
        current = i % slides.length;
        slides[current].classList.add('active');
        if (dots[current]) dots[current].classList.add('active');
      };

      const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
      if (!reduced) {
        setInterval(() => go(current + 1), 6000);
      }
    } else if (slides.length === 1) {
      slides[0].classList.add('active');
    }
  }

  /* --------- Lite YouTube --------- */
  document.querySelectorAll('.lite-youtube[data-yt]').forEach((el) => {
    const id = el.getAttribute('data-yt');
    if (!id) return;
    const activate = () => {
      const iframe = document.createElement('iframe');
      iframe.src = `https://www.youtube-nocookie.com/embed/${id}?autoplay=1&rel=0`;
      iframe.setAttribute('allow', 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share');
      iframe.setAttribute('allowfullscreen', '');
      iframe.setAttribute('title', el.getAttribute('aria-label') || 'Vidéo YouTube');
      el.innerHTML = '';
      el.appendChild(iframe);
    };
    el.addEventListener('click', activate);
    el.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); activate(); }
    });
  });

  /* --------- Lightbox galerie --------- */
  const galleryItems = document.querySelectorAll('.gallery-item[data-full]');
  if (galleryItems.length) {
    const lb = document.createElement('div');
    lb.className = 'lightbox';
    lb.setAttribute('role', 'dialog');
    lb.setAttribute('aria-modal', 'true');
    lb.innerHTML = `
      <button class="lightbox-close" aria-label="Fermer">×</button>
      <img alt="" />
    `;
    document.body.appendChild(lb);
    const lbImg = lb.querySelector('img');
    const lbClose = lb.querySelector('.lightbox-close');

    const close = () => {
      lb.classList.remove('open');
      document.body.style.overflow = '';
    };
    const open = (src, alt) => {
      lbImg.src = src;
      lbImg.alt = alt || '';
      lb.classList.add('open');
      document.body.style.overflow = 'hidden';
    };

    galleryItems.forEach(item => {
      item.addEventListener('click', () => {
        const full = item.getAttribute('data-full');
        const img = item.querySelector('img');
        open(full, img ? img.alt : '');
      });
    });
    lbClose.addEventListener('click', close);
    lb.addEventListener('click', (e) => { if (e.target === lb) close(); });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') close(); });
  }
})();
