(function () {
  document.addEventListener('DOMContentLoaded', function () {
    var navs = document.querySelectorAll('nav[aria-label="Hoofdnavigatie"]');

    navs.forEach(function (nav) {
      var toggle = nav.querySelector('.nav-menu-toggle');
      if (!toggle) return;

      var closeMenu = function () {
        nav.classList.remove('is-open');
        toggle.setAttribute('aria-expanded', 'false');
        toggle.setAttribute('aria-label', 'Open menu');
      };

      toggle.addEventListener('click', function () {
        var isOpen = nav.classList.toggle('is-open');
        toggle.setAttribute('aria-expanded', String(isOpen));
        toggle.setAttribute('aria-label', isOpen ? 'Sluit menu' : 'Open menu');
      });

      nav.querySelectorAll('.nav-links a').forEach(function (link) {
        link.addEventListener('click', function () {
          if (window.innerWidth <= 1100) {
            closeMenu();
          }
        });
      });

      document.addEventListener('click', function (event) {
        if (!nav.contains(event.target) && window.innerWidth <= 1100) {
          closeMenu();
        }
      });

      window.addEventListener('resize', function () {
        if (window.innerWidth > 1100) {
          closeMenu();
        }
      });
    });

    var params = new URLSearchParams(window.location.search);
    if (params.has('workshop')) {
      var contact = document.getElementById('contact');
      var ta = document.getElementById('bericht');
      var cat = document.getElementById('categorie');

      if (cat) {
        cat.value = 'workshop';
      }
      if (ta && !ta.value.trim()) {
        ta.value = 'Ik heb een vraag over de workshops bij De Lijsterij:\n\n';
      }
      if (contact) {
        contact.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }
      if (ta) {
        requestAnimationFrame(function () {
          ta.focus({ preventScroll: true });
        });
      }
    }

    var form = document.querySelector('#contact form.form');
    if (!form) return;

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      if (!form.reportValidity()) return;

      var naam = (document.getElementById('naam') && document.getElementById('naam').value.trim()) || '';
      var telefoon =
        (document.getElementById('telefoon') && document.getElementById('telefoon').value.trim()) || '';
      var email = (document.getElementById('email') && document.getElementById('email').value.trim()) || '';
      var bericht = (document.getElementById('bericht') && document.getElementById('bericht').value.trim()) || '';
      var catEl = document.getElementById('categorie');
      var categorie = (catEl && catEl.value && catEl.value.trim()) || '';

      var lines = [];
      lines.push('Bericht via contactformulier op delijsterij.nl');
      lines.push('');
      lines.push('Naam: ' + naam);
      lines.push('Telefoon: ' + telefoon);
      lines.push('E-mail: ' + email);
      lines.push('Categorie: ' + (categorie || '—'));
      lines.push('');
      lines.push(bericht);

      var subject = 'Contact website — ' + (naam || 'De Lijsterij');
      var body = lines.join('\n');
      var mailto =
        'mailto:info@delijsterij.nl?subject=' +
        encodeURIComponent(subject) +
        '&body=' +
        encodeURIComponent(body);

      window.location.href = mailto;
    });
  });
})();
