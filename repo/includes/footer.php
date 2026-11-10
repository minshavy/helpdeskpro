<?php if (is_logged_in()): ?>
    </div> <!-- /.container-fluid page content -->
    <footer class="text-center text-muted py-3 border-top mt-4">
        <small>&copy; <?= date('Y') ?> HelpDeskPro &mdash; IT Helpdesk &amp; Ticket Management System</small>
    </footer>
  </div> <!-- /.app-main -->
</div> <!-- /.app-shell -->
<?php else: ?>
</div> <!-- /.container -->
<footer class="text-center text-muted py-3">
    <small>&copy; <?= date('Y') ?> HelpDeskPro &mdash; IT Helpdesk &amp; Ticket Management System</small>
</footer>
<?php endif; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Mobile sidebar toggle
(function () {
    var toggle = document.getElementById('sidebarToggle');
    var sidebar = document.getElementById('appSidebar');
    var overlay = document.getElementById('sidebarOverlay');
    if (!toggle || !sidebar || !overlay) return;

    function closeSidebar() {
        sidebar.classList.remove('show');
        overlay.classList.remove('show');
    }
    toggle.addEventListener('click', function () {
        sidebar.classList.toggle('show');
        overlay.classList.toggle('show');
    });
    overlay.addEventListener('click', closeSidebar);
})();

// Show / hide password ("eye") toggle
// ---------------------------------------------------------
// Added once here so EVERY password field in the app gets it automatically,
// including ones added in future. Written in plain ES5 so it runs on older
// browsers too.
(function () {
    var EYE = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>';
    var EYE_OFF = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"></path><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"></path><path d="M14.12 14.12a3 3 0 1 1-4.24-4.24"></path><path d="M1 1l22 22"></path></svg>';

    function setVisible(input, btn, visible) {
        input.type = visible ? 'text' : 'password';
        btn.innerHTML = visible ? EYE_OFF : EYE;
        btn.setAttribute('aria-label', visible ? 'Hide password' : 'Show password');
        btn.setAttribute('title', visible ? 'Hide password' : 'Show password');
        btn.setAttribute('aria-pressed', visible ? 'true' : 'false');
    }

    function attach(input) {
        if (input.getAttribute('data-eye') === '1') return;
        input.setAttribute('data-eye', '1');

        // Put the input inside an input-group (if it isn't already) so the
        // button sits flush against the field.
        var group = input.parentNode;
        if (!group.classList || !group.classList.contains('input-group')) {
            group = document.createElement('div');
            group.className = 'input-group';
            // spacing classes belong on the wrapper, not the input inside it
            var spacing = (input.className.match(/\bm[btsexy]?-\d\b/g) || []);
            for (var i = 0; i < spacing.length; i++) {
                input.classList.remove(spacing[i]);
                group.classList.add(spacing[i]);
            }
            input.parentNode.insertBefore(group, input);
            group.appendChild(input);
        }

        var btn = document.createElement('button');
        btn.type = 'button'; // essential: a plain <button> would SUBMIT the form
        btn.className = 'btn btn-outline-secondary password-toggle';
        group.appendChild(btn);
        setVisible(input, btn, false);

        btn.addEventListener('click', function () {
            var show = input.type === 'password';
            setVisible(input, btn, show);
            // keep the cursor where it was so typing can continue
            var pos = input.value.length;
            input.focus();
            try { input.setSelectionRange(pos, pos); } catch (e) {}
        });

        // Always submit as a password field, so browsers never store the
        // value as ordinary text in form history.
        if (input.form) {
            input.form.addEventListener('submit', function () {
                setVisible(input, btn, false);
            });
        }
    }

    var fields = document.querySelectorAll('input[type="password"]');
    for (var i = 0; i < fields.length; i++) {
        attach(fields[i]);
    }
})();

// Dark mode toggle
(function () {
    var btn = document.getElementById('themeToggle');
    var icon = document.getElementById('themeIcon');
    if (!btn || !icon) return;

    function applyIcon(theme) {
        icon.className = theme === 'dark' ? 'bi bi-sun' : 'bi bi-moon-stars';
    }
    applyIcon(document.documentElement.getAttribute('data-bs-theme') || 'light');

    btn.addEventListener('click', function () {
        var current = document.documentElement.getAttribute('data-bs-theme') || 'light';
        var next = current === 'dark' ? 'light' : 'dark';
        document.documentElement.setAttribute('data-bs-theme', next);
        localStorage.setItem('hdp-theme', next);
        applyIcon(next);
    });
})();
</script>
</body>
</html>
