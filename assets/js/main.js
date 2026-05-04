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

    // Form submit verloopt server-side via action="contact-form.php" method="POST".
  });
})();
