/**
 * ACS-Lite Shared Components
 * Sidebar, Header, and common functions
 */

// Current page detection
const currentPage = window.location.pathname.split('/').pop() || 'dashboard.html';

// Sidebar HTML Template
function getSidebarHTML() {
    return `
    <div class="sidebar-header">
        <a href="dashboard.html" class="sidebar-brand">
            <div class="sidebar-brand-icon"><i class="fas fa-bolt"></i></div>
            <div class="sidebar-brand-text">ACS-Lite<small>ISP Billing System</small></div>
        </a>
    </div>
    <nav class="sidebar-menu">
        <div class="menu-label">Menu Utama</div>
        <a href="dashboard.html" class="menu-item ${currentPage === 'dashboard.html' ? 'active' : ''}">
            <i class="fas fa-home"></i><span>Dashboard</span>
        </a>
        <a href="customers.html" class="menu-item ${currentPage === 'customers.html' ? 'active' : ''}">
            <i class="fas fa-users"></i><span>Pelanggan</span>
        </a>
        <a href="invoices.html" class="menu-item ${currentPage === 'invoices.html' ? 'active' : ''}">
            <i class="fas fa-file-invoice-dollar"></i><span>Invoice</span>
        </a>
        <a href="payments.html" class="menu-item ${currentPage === 'payments.html' ? 'active' : ''}">
            <i class="fas fa-money-bill-wave"></i><span>Pembayaran</span>
        </a>

        <div class="menu-label">Manajemen Jaringan</div>
        <a href="acs.html" class="menu-item ${currentPage === 'acs.html' ? 'active' : ''}">
            <i class="fas fa-microchip"></i><span>ACS / TR-069</span>
        </a>
        <a href="mikrotik.html" class="menu-item ${currentPage === 'mikrotik.html' ? 'active' : ''}">
            <i class="fas fa-server"></i><span>MikroTik PPPoE</span>
        </a>
        <a href="map.html" class="menu-item ${currentPage === 'map.html' ? 'active' : ''}">
            <i class="fas fa-map-marker-alt"></i><span>Peta Jaringan</span>
        </a>

        <div class="menu-label">Data & Laporan</div>
        <a href="check_database.html" class="menu-item ${currentPage === 'check_database.html' ? 'active' : ''}">
            <i class="fas fa-list-alt"></i><span>Data Viewer</span>
        </a>
        <a href="reports.html" class="menu-item ${currentPage === 'reports.html' ? 'active' : ''}">
            <i class="fas fa-chart-bar"></i><span>Laporan</span>
        </a>

        <div class="menu-label">Sistem</div>
        <a href="settings.html" class="menu-item ${currentPage === 'settings.html' ? 'active' : ''}">
            <i class="fas fa-cog"></i><span>Pengaturan</span>
        </a>
        <a href="db_admin.html" class="menu-item ${currentPage === 'db_admin.html' ? 'active' : ''}">
            <i class="fas fa-database"></i><span>Database Admin</span>
        </a>
        <a href="#" class="menu-item" onclick="logout(); return false;">
            <i class="fas fa-sign-out-alt"></i><span>Logout</span>
        </a>
    </nav>
    `;
}

// Shared CSS for sidebar layout
function getSharedCSS() {
    return `
    <style>
        :root {
            --primary: #6366f1;
            --primary-light: #818cf8;
            --primary-dark: #4f46e5;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #3b82f6;
            --dark: #1e293b;
            --gray: #64748b;
            --light: #f1f5f9;
            --sidebar-width: 260px;
        }

        * { font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif; box-sizing: border-box; }
        body { background: #f8fafc; min-height: 100vh; margin: 0; }

        .sidebar {
            position: fixed;
            left: 0; top: 0; bottom: 0;
            width: var(--sidebar-width);
            background: linear-gradient(180deg, var(--dark) 0%, #0f172a 100%);
            z-index: 1000;
            transition: all 0.3s ease;
            overflow-y: auto;
        }

        .sidebar-header {
            padding: 20px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }

        .sidebar-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            color: white;
            text-decoration: none;
        }

        .sidebar-brand-icon {
            width: 40px; height: 40px;
            background: linear-gradient(135deg, var(--primary), var(--primary-light));
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }

        .sidebar-brand-text { font-weight: 700; font-size: 1.2rem; }
        .sidebar-brand-text small { display: block; font-size: 0.7rem; font-weight: 400; opacity: 0.7; }

        .sidebar-menu { padding: 15px 10px; }

        .menu-label {
            color: var(--gray);
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            padding: 10px 15px 5px;
        }

        .menu-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 15px;
            color: rgba(255,255,255,0.7);
            text-decoration: none;
            border-radius: 10px;
            margin-bottom: 4px;
            transition: all 0.2s;
        }

        .menu-item:hover { background: rgba(255,255,255,0.1); color: white; }
        .menu-item.active { background: linear-gradient(135deg, var(--primary), var(--primary-dark)); color: white; }
        .menu-item i { width: 20px; text-align: center; }

        .main-content {
            margin-left: var(--sidebar-width);
            min-height: 100vh;
        }

        .top-header {
            background: white;
            padding: 15px 25px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid #e2e8f0;
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .page-content { padding: 25px; }
        .page-title { font-weight: 700; color: var(--dark); margin-bottom: 5px; }
        .page-subtitle { color: var(--gray); font-size: 0.9rem; margin-bottom: 25px; }

        .card {
            background: white;
            border: none;
            border-radius: 15px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            margin-bottom: 20px;
        }

        .card-header {
            background: white;
            border-bottom: 1px solid #e2e8f0;
            padding: 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 15px;
            border-radius: 15px 15px 0 0;
        }

        .card-title {
            font-weight: 600;
            color: var(--dark);
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .card-title i { color: var(--primary); }

        .menu-toggle {
            display: none;
            background: none;
            border: none;
            font-size: 1.5rem;
            color: var(--dark);
            cursor: pointer;
        }

        .sidebar-overlay {
            display: none;
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 999;
        }

        .sidebar-overlay.show { display: block; }

        @media (max-width: 992px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.show { transform: translateX(0); }
            .main-content { margin-left: 0; }
            .menu-toggle { display: block; }
        }

        /* Common Components */
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
        }

        .status-badge.active, .status-badge.paid { background: rgba(16,185,129,0.1); color: var(--success); }
        .status-badge.isolir, .status-badge.pending, .status-badge.sent { background: rgba(245,158,11,0.1); color: var(--warning); }
        .status-badge.suspended, .status-badge.overdue, .status-badge.cancelled { background: rgba(239,68,68,0.1); color: var(--danger); }
        .status-badge.draft { background: rgba(100,116,139,0.1); color: var(--gray); }

        .btn-action {
            width: 32px; height: 32px;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            background: white;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: var(--gray);
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-action:hover { background: var(--primary); border-color: var(--primary); color: white; }
        .btn-action.danger:hover { background: var(--danger); border-color: var(--danger); }
        .btn-action.success:hover { background: var(--success); border-color: var(--success); }
        .btn-action.warning:hover { background: var(--warning); border-color: var(--warning); }

        .data-table { width: 100%; }
        .data-table th {
            font-weight: 600;
            font-size: 0.75rem;
            text-transform: uppercase;
            color: var(--gray);
            padding: 12px 15px;
            border-bottom: 1px solid #e2e8f0;
            white-space: nowrap;
        }
        .data-table td {
            padding: 15px;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
        }
        .data-table tr:hover { background: #fafafa; }

        .stats-row {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 15px;
            margin-bottom: 20px;
        }

        .stat-mini {
            background: white;
            border-radius: 12px;
            padding: 15px 20px;
            display: flex;
            align-items: center;
            gap: 15px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }

        .stat-mini-icon {
            width: 45px; height: 45px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }

        .stat-mini-icon.primary { background: rgba(99,102,241,0.1); color: var(--primary); }
        .stat-mini-icon.success { background: rgba(16,185,129,0.1); color: var(--success); }
        .stat-mini-icon.warning { background: rgba(245,158,11,0.1); color: var(--warning); }
        .stat-mini-icon.danger { background: rgba(239,68,68,0.1); color: var(--danger); }

        .stat-mini-value { font-size: 1.5rem; font-weight: 700; color: var(--dark); }
        .stat-mini-label { font-size: 0.8rem; color: var(--gray); }

        .filter-bar { display: flex; gap: 10px; flex-wrap: wrap; }

        .filter-btn {
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 0.85rem;
            border: 1px solid #e2e8f0;
            background: white;
            color: var(--gray);
            cursor: pointer;
            transition: all 0.2s;
        }

        .filter-btn:hover { border-color: var(--primary); color: var(--primary); }
        .filter-btn.active { background: var(--primary); color: white; border-color: var(--primary); }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--gray);
        }
        .empty-state i { font-size: 4rem; margin-bottom: 20px; opacity: 0.3; }

        .modal-header {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
        }
        .btn-close-white { filter: invert(1); }

        @media (max-width: 992px) {
            .stats-row { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 576px) {
            .stats-row { grid-template-columns: 1fr; }
            .page-content { padding: 15px; }
        }
    </style>
    `;
}

// Initialize sidebar
function initSidebar() {
    // Add overlay if not exists
    if (!document.getElementById('sidebar-overlay')) {
        const overlay = document.createElement('div');
        overlay.className = 'sidebar-overlay';
        overlay.id = 'sidebar-overlay';
        overlay.onclick = toggleSidebar;
        document.body.insertBefore(overlay, document.body.firstChild);
    }

    // Populate sidebar
    const sidebar = document.getElementById('sidebar');
    if (sidebar && !sidebar.innerHTML.trim()) {
        sidebar.innerHTML = getSidebarHTML();
    }
}

// Toggle sidebar for mobile
function toggleSidebar() {
    document.getElementById('sidebar')?.classList.toggle('show');
    document.getElementById('sidebar-overlay')?.classList.toggle('show');
}

// Logout function
function logout() {
    if (confirm('Yakin ingin logout?')) {
        sessionStorage.removeItem('acs_session');
        localStorage.removeItem('acs_user');
        window.location.href = 'login.html';
    }
}

// Format currency
function formatRupiah(amount) {
    return 'Rp ' + parseInt(amount || 0).toLocaleString('id-ID');
}

// Format date
function formatDate(dateStr) {
    if (!dateStr) return '-';
    const date = new Date(dateStr);
    return date.toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' });
}

// API Base URLs
const API_BASE = '/api';
const PHP_API = `http://${window.location.hostname}:8888/api`;
const BILLING_API = `${PHP_API}/billing_api.php`;
const MIKROTIK_API = `${PHP_API}/mikrotik_api.php`;
const SETTINGS_API = `${PHP_API}/settings_api.php`;

// Auto-initialize on DOM ready
document.addEventListener('DOMContentLoaded', () => {
    initSidebar();
});
