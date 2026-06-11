/**
 * mobile_nav.js
 * Drop this file into Website/js/ and include it in every page that has
 * a .navbar-links + hamburger button.
 *
 * In each PHP page that uses admind.css, teacherd.css, or userm.css,
 * add two things:
 *
 * 1. Inside the <nav class="navbar">, BEFORE .navbar-right, add:
 *    <button class="nav-hamburger" id="navHamburger" onclick="toggleMobileNav()" aria-label="Menu">☰</button>
 *
 * 2. Just AFTER the closing </nav> tag, add the drawer with the same links:
 *    <div class="mobile-nav-drawer" id="mobileNavDrawer">
 *        <a href="admin_dashboard.php">🏠 Dashboard</a>
 *        <a href="tuition_assessment.php">📂 Tuition</a>
 *        ... (copy your navbar-links here)
 *        <a href="php/logout.php" style="color:#ff6b6b;">🚪 Logout</a>
 *    </div>
 *
 * 3. Include this script at the bottom of <body>:
 *    <script src="js/mobile_nav.js"></script>
 */

function toggleMobileNav() {
    const drawer = document.getElementById('mobileNavDrawer');
    const btn    = document.getElementById('navHamburger');
    if (!drawer) return;
    const isOpen = drawer.classList.toggle('open');
    btn.textContent = isOpen ? '✕' : '☰';
    btn.setAttribute('aria-expanded', isOpen);
}

// Close drawer when clicking outside
document.addEventListener('click', function(e) {
    const drawer = document.getElementById('mobileNavDrawer');
    const btn    = document.getElementById('navHamburger');
    if (!drawer || !btn) return;
    if (!drawer.contains(e.target) && !btn.contains(e.target)) {
        drawer.classList.remove('open');
        btn.textContent = '☰';
    }
});

// Close drawer on window resize back to desktop
window.addEventListener('resize', function() {
    if (window.innerWidth > 900) {
        const drawer = document.getElementById('mobileNavDrawer');
        const btn    = document.getElementById('navHamburger');
        if (drawer) drawer.classList.remove('open');
        if (btn)    btn.textContent = '☰';
    }
});
