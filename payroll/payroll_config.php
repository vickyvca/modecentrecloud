<?php
/* ==================== CONFIG PAYROLL ==================== */
$PAYROLL_BASE_DIR    = __DIR__;
$PAYROLL_DATA_DIR    = $PAYROLL_BASE_DIR . '/data/payroll';
$PAYROLL_BRAND_NAME  = 'Payroll';
if (!is_dir($PAYROLL_BASE_DIR . '/data')) @mkdir($PAYROLL_BASE_DIR . '/data', 0777, true);
if (!is_dir($PAYROLL_DATA_DIR)) @mkdir($PAYROLL_DATA_DIR, 0777, true);

/* Guard untuk fungsi util agar tidak redeclare */
if (!function_exists('ym_now')) {
    function ym_now(): string { return date('Y-m'); }
}
if (!function_exists('payroll_dark_css')) {
    function payroll_dark_css(): string {
        return <<<CSS
:root{--brand-bg:#d32f2f;--brand-text:#fff;--bg:#121218;--card:#1e1e24;--text:#e0e0e0;--muted:#8b8b9a;--border:#33333d}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Cantarell,Noto Sans,sans-serif}
a{color:#a0c8ff;text-decoration:none}a:hover{text-decoration:underline}
.header{display:flex;align-items:center;gap:16px;padding:16px;background:var(--card);border-bottom:1px solid var(--border)}
.header .brand{font-weight:700}
.btn{display:inline-flex;align-items:center;gap:8px;padding:10px 14px;border:1px solid var(--border);background:#22222a;color:var(--text);border-radius:10px;cursor:pointer}
.btn:hover{border-color:#4a4a58}
.input,select{background:#18181f;color:var(--text);border:1px solid var(--border);border-radius:10px;padding:10px}
.table{width:100%;border-collapse:collapse;margin-top:12px}
.table th,.table td{border:1px solid var(--border);padding:10px}
.card{background:var(--card);border:1px solid var(--border);border-radius:16px;padding:16px}
.grid{display:grid;gap:16px}.grid-3{grid-template-columns:repeat(3,minmax(0,1fr))}
.badge{background:#2a2a34;border:1px solid var(--border);border-radius:999px;padding:2px 10px;font-size:12px}
.note{color:var(--muted);font-size:13px}
.print-a4{max-width:210mm;margin:0 auto;padding:12mm}
@media print{.noprint{display:none}body{background:#fff;color:#000}.card{border:none}}
CSS;
    }
}
