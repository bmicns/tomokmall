<?php
declare(strict_types=1);

/**
 * 직원 레이아웃 (app/staff_layout.php)
 *
 * 책임: 직원 전용 페이지 공통 헤더/푸터 렌더링
 * 금지: 비즈니스 로직, DB 쿼리
 */

function render_staff_header(string $title, string $activeMenu = ''): void
{
    $menus = [
        ['key' => 'estimates', 'label' => '견적 목록',   'url' => '/staff/index.php'],
        ['key' => 'inventory', 'label' => '재고현황',    'url' => '/staff/inventory.php'],
        ['key' => 'vendors',   'label' => '품목별 업체', 'url' => '/staff/vendors.php'],
    ];

    $user = current_user();
    ?>
<!doctype html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= htmlspecialchars($title, ENT_QUOTES) ?> — 직원</title>
    <style>
    *{box-sizing:border-box;margin:0;padding:0}
    body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#f5f6fa;color:#1a2035}
    /* 레이아웃 */
    .stf-layout{display:flex;min-height:100vh}
    /* 사이드바 */
    .stf-sidebar{width:210px;background:#003580;flex-shrink:0;display:flex;flex-direction:column;position:sticky;top:0;height:100vh;overflow-y:auto}
    .stf-logo{padding:20px 18px 16px;border-bottom:1px solid rgba(255,255,255,.1)}
    .stf-logo-mark{font-size:16px;font-weight:800;color:#fff;letter-spacing:-.5px}
    .stf-logo-sub{font-size:10px;color:rgba(255,255,255,.38);margin-top:3px;letter-spacing:1.2px;text-transform:uppercase}
    .stf-nav{padding:8px 0;flex:1}
    .stf-nav-section{font-size:10px;font-weight:700;color:rgba(255,255,255,.22);padding:12px 18px 4px;letter-spacing:1px;text-transform:uppercase}
    .stf-nav a{display:flex;align-items:center;gap:9px;padding:10px 18px;font-size:13px;font-weight:600;color:rgba(255,255,255,.52);text-decoration:none;border-left:3px solid transparent;transition:all .15s}
    .stf-nav a:hover{color:rgba(255,255,255,.88);background:rgba(255,255,255,.05)}
    .stf-nav a.active{color:#fff;background:rgba(255,255,255,.1);border-left-color:#60a5fa}
    .stf-nav a svg{flex-shrink:0;opacity:.75}
    .stf-nav a.active svg{opacity:1}
    .stf-sidebar-foot{padding:14px 18px;border-top:1px solid rgba(255,255,255,.08)}
    .stf-sidebar-foot a{font-size:12px;color:rgba(255,255,255,.35);text-decoration:none;display:flex;align-items:center;gap:6px}
    .stf-sidebar-foot a:hover{color:rgba(255,255,255,.65)}
    /* 메인 */
    .stf-main{flex:1;min-width:0;display:flex;flex-direction:column}
    .stf-topbar{background:#fff;border-bottom:1px solid #e8eaed;padding:0 24px;height:50px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:100;gap:12px}
    .stf-topbar-title{font-size:14px;font-weight:700;color:#1a2035;white-space:nowrap}
    .stf-topbar-right{display:flex;align-items:center;gap:12px}
    .stf-user-tag{font-size:12px;color:#888;background:#f0f2f5;padding:4px 10px;border-radius:20px}
    .stf-content{padding:22px 24px;flex:1}
    /* 공통 컴포넌트 */
    .page-heading{font-size:19px;font-weight:800;color:#1a2035;margin-bottom:18px}
    .card{background:#fff;border-radius:10px;border:1px solid #e8eaed;overflow:hidden}
    .card-header{display:flex;align-items:center;justify-content:space-between;padding:13px 18px;border-bottom:1px solid #e8eaed}
    .card-title{font-size:14px;font-weight:700;color:#1a2035}
    .stf-table{width:100%;border-collapse:collapse;font-size:13px}
    .stf-table th{background:#f8f9fb;padding:9px 12px;text-align:left;font-weight:600;color:#555;border-bottom:1px solid #e8eaed;white-space:nowrap}
    .stf-table td{padding:9px 12px;border-bottom:1px solid #f0f0f0;color:#333;vertical-align:middle}
    .stf-table tr:last-child td{border-bottom:none}
    .stf-table tr:hover td{background:#f8f9fb}
    .badge{display:inline-block;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:600}
    .badge-requested{background:#e3f2fd;color:#1565c0}
    .badge-working{background:#fff3e0;color:#e65100}
    .badge-sent{background:#e8f5e9;color:#2e7d32}
    .badge-approved{background:#f3e8ff;color:#6d28d9}
    .badge-canceled{background:#f5f5f5;color:#999}
    .badge-low{background:#fce4ec;color:#c62828}
    .badge-ok{background:#e8f5e9;color:#2e7d32}
    .btn{height:34px;padding:0 14px;border:none;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer;transition:background .15s}
    .btn-primary{background:#003580;color:#fff}
    .btn-primary:hover{background:#002460}
    .btn-secondary{background:#f0f2f5;color:#444;border:1px solid #e0e0e0}
    .btn-secondary:hover{background:#e4e6ea}
    .btn-success{background:#2e7d32;color:#fff}
    .btn-success:hover{background:#1b5e20}
    .btn-sm{height:26px;padding:0 9px;font-size:11px;border:none;border-radius:4px;cursor:pointer;font-weight:600}
    .btn-sm-view{background:#e8f0fe;color:#003580}
    .btn-sm-edit{background:#e8f5e9;color:#2e7d32}
    /* 툴바 */
    .toolbar{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:14px}
    .toolbar input,.toolbar select{height:34px;border:1px solid #e8eaed;border-radius:6px;padding:0 10px;font-size:13px;background:#fff;outline:none}
    .toolbar input:focus,.toolbar select:focus{border-color:#003580}
    .toolbar input{min-width:180px}
    /* 탭 */
    .tab-bar{display:flex;gap:0;border-bottom:2px solid #e8eaed;margin-bottom:18px}
    .tab-btn{padding:10px 18px;font-size:13px;font-weight:600;color:#888;background:none;border:none;cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-2px;transition:all .15s}
    .tab-btn.active{color:#003580;border-bottom-color:#003580}
    .tab-btn:hover{color:#1a2035}
    /* 페이지네이션 */
    .pagination{display:flex;gap:4px;justify-content:flex-end;margin-top:12px}
    .page-btn{width:30px;height:30px;border:1px solid #e8eaed;border-radius:5px;background:#fff;cursor:pointer;font-size:12px;color:#444;display:inline-flex;align-items:center;justify-content:center;text-decoration:none}
    .page-btn.active{background:#003580;color:#fff;border-color:#003580}
    /* 모달 */
    .modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9000;align-items:center;justify-content:center}
    .modal-overlay.open{display:flex}
    .modal-box{background:#fff;border-radius:12px;padding:26px;width:540px;max-width:95vw;max-height:90vh;overflow-y:auto;position:relative}
    .modal-box h3{font-size:15px;font-weight:700;margin-bottom:18px}
    .modal-close{position:absolute;top:13px;right:14px;background:none;border:none;cursor:pointer;color:#888;font-size:18px;line-height:1}
    .form-field{display:flex;flex-direction:column;gap:4px;margin-bottom:12px}
    .form-field label{font-size:12px;font-weight:600;color:#666}
    .form-field input,.form-field select,.form-field textarea{border:1px solid #e8eaed;border-radius:6px;padding:7px 10px;font-size:13px;width:100%;background:#fff;outline:none}
    .form-field input:focus,.form-field select:focus,.form-field textarea:focus{border-color:#003580}
    .form-2col{display:grid;grid-template-columns:1fr 1fr;gap:10px}
    .modal-footer{display:flex;justify-content:flex-end;gap:8px;margin-top:18px}
    /* KPI */
    .kpi-row{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:14px;margin-bottom:20px}
    .kpi-card{background:#fff;border-radius:10px;padding:16px 18px;border:1px solid #e8eaed}
    .kpi-val{font-size:26px;font-weight:800;color:#1a2035;line-height:1.1}
    .kpi-label{font-size:11px;color:#888;margin-top:4px}
    .kpi-val.urgent{color:#c62828}
    </style>
</head>
<body>
<div class="stf-layout">
    <aside class="stf-sidebar">
        <div class="stf-logo">
            <div class="stf-logo-mark">비엠아이</div>
            <div class="stf-logo-sub">Staff Panel</div>
        </div>
        <nav class="stf-nav">
            <div class="stf-nav-section">업무</div>
            <?php foreach ($menus as $m): ?>
            <a href="<?= $m['url'] ?>" class="<?= $activeMenu === $m['key'] ? 'active' : '' ?>">
                <?= _stf_icon($m['key']) ?><?= $m['label'] ?>
            </a>
            <?php endforeach; ?>
        </nav>
        <div class="stf-sidebar-foot">
            <a href="/index.php">
                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
                사이트로 돌아가기
            </a>
        </div>
    </aside>
    <main class="stf-main">
        <div class="stf-topbar">
            <span class="stf-topbar-title"><?= htmlspecialchars($title, ENT_QUOTES) ?></span>
            <div class="stf-topbar-right">
                <?php if ($user): ?>
                <span class="stf-user-tag"><?= htmlspecialchars($user['name'], ENT_QUOTES) ?></span>
                <?php endif; ?>
                <a href="/api/auth/logout.php" style="font-size:12px;color:#888;text-decoration:none">로그아웃</a>
            </div>
        </div>
        <div class="stf-content">
<?php
}

function _stf_icon(string $key): string
{
    return match($key) {
        'estimates' => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" style="flex-shrink:0"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14,2 14,8 20,8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>',
        'inventory'  => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" style="flex-shrink:0"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2"/><line x1="12" y1="12" x2="12" y2="16"/><line x1="10" y1="14" x2="14" y2="14"/></svg>',
        'vendors'    => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" style="flex-shrink:0"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
        default      => '',
    };
}

function render_staff_footer(): void
{
    ?>
        </div><!-- /.stf-content -->
    </main>
</div>
</body>
</html>
<?php
}
