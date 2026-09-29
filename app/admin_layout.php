<?php
declare(strict_types=1);

function render_admin_header(string $title, string $activeMenu = ''): void
{
    $menus = [
        ['section' => '현황'],
        ['key' => 'dashboard',  'label' => '대시보드',   'url' => '/admin.php'],
        ['key' => 'stats',      'label' => '통계',       'url' => '/admin-stats.php'],
        ['section' => '운영'],
        ['key' => 'users',      'label' => '회원관리',   'url' => '/admin-users.php'],
        ['key' => 'orders',     'label' => '주문관리',   'url' => '/admin-orders.php'],
        ['key' => 'estimates',  'label' => '견적관리',   'url' => '/admin-estimates.php'],
        ['section' => '콘텐츠'],
        ['key' => 'products',   'label' => '제품관리',   'url' => '/admin-products.php'],
        ['key' => 'categories', 'label' => '카테고리',   'url' => '/admin-categories.php'],
        ['section' => 'AI/데이터'],
        ['key' => 'chat-logs',  'label' => 'AI 상담로그','url' => '/admin-chat-logs.php'],
        ['key' => 'rag-docs',   'label' => 'RAG 문서',   'url' => '/admin-rag-docs.php'],
    ];
    ?>
<!doctype html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= htmlspecialchars($title, ENT_QUOTES) ?> — 관리자</title>
    <style>
    *{box-sizing:border-box;margin:0;padding:0}
    body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#f0f2f5;color:#1a2035}
    .adm-layout{display:flex;min-height:100vh}
    /* 사이드바 */
    .adm-sidebar{width:220px;background:#1a2035;flex-shrink:0;display:flex;flex-direction:column;position:sticky;top:0;height:100vh;overflow-y:auto}
    .adm-logo{padding:22px 20px 18px;border-bottom:1px solid rgba(255,255,255,.08)}
    .adm-logo-mark{font-size:18px;font-weight:800;color:#fff;letter-spacing:-.5px}
    .adm-logo-sub{font-size:10px;color:rgba(255,255,255,.35);margin-top:3px;letter-spacing:1.5px;text-transform:uppercase}
    .adm-nav{padding:10px 0;flex:1}
    .adm-nav-section{font-size:10px;font-weight:700;color:rgba(255,255,255,.22);padding:14px 20px 5px;letter-spacing:1px;text-transform:uppercase}
    .adm-nav a{display:flex;align-items:center;gap:10px;padding:11px 20px;font-size:13px;font-weight:600;color:rgba(255,255,255,.5);text-decoration:none;border-left:3px solid transparent;transition:all .15s}
    .adm-nav a:hover{color:rgba(255,255,255,.9);background:rgba(255,255,255,.04)}
    .adm-nav a.active{color:#fff;background:rgba(0,78,161,.4);border-left-color:#004EA1}
    .adm-nav a svg{flex-shrink:0;opacity:.7}
    .adm-nav a.active svg{opacity:1}
    .adm-sidebar-foot{padding:16px 20px;border-top:1px solid rgba(255,255,255,.07)}
    .adm-sidebar-foot a{font-size:12px;color:rgba(255,255,255,.35);text-decoration:none;display:flex;align-items:center;gap:6px}
    .adm-sidebar-foot a:hover{color:rgba(255,255,255,.65)}
    /* 메인 */
    .adm-main{flex:1;min-width:0;display:flex;flex-direction:column}
    .adm-topbar{background:#fff;border-bottom:1px solid #e8eaed;padding:0 28px;height:52px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:100}
    .adm-topbar-title{font-size:14px;font-weight:700;color:#1a2035}
    .adm-topbar-user{font-size:12px;color:#888}
    .adm-content{padding:24px 28px;flex:1}
    /* 공통 컴포넌트 */
    .page-heading{font-size:20px;font-weight:800;color:#1a2035;margin-bottom:20px}
    .card{background:#fff;border-radius:10px;border:1px solid #e8eaed;overflow:hidden}
    .card-header{display:flex;align-items:center;justify-content:space-between;padding:14px 20px;border-bottom:1px solid #e8eaed}
    .card-title{font-size:14px;font-weight:700;color:#1a2035}
    .adm-table{width:100%;border-collapse:collapse;font-size:13px}
    .adm-table th{background:#f8f9fb;padding:9px 14px;text-align:left;font-weight:600;color:#555;border-bottom:1px solid #e8eaed;white-space:nowrap}
    .adm-table td{padding:9px 14px;border-bottom:1px solid #f0f0f0;color:#333;vertical-align:middle}
    .adm-table tr:last-child td{border-bottom:none}
    .adm-table tr:hover td{background:#f8f9fb}
    .badge{display:inline-block;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:600}
    .badge-on{background:#e8f5e9;color:#2e7d32}
    .badge-off{background:#fce4ec;color:#c62828}
    .badge-pending{background:#fff3e0;color:#e65100}
    .badge-done{background:#e8f5e9;color:#2e7d32}
    .btn{height:36px;padding:0 16px;border:none;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer}
    .btn-primary{background:#004EA1;color:#fff}
    .btn-primary:hover{background:#003A7A}
    .btn-secondary{background:#f0f2f5;color:#444}
    .btn-secondary:hover{background:#e4e6ea}
    .btn-danger{background:#fce4ec;color:#c62828}
    .btn-sm{height:28px;padding:0 10px;font-size:12px;border:none;border-radius:4px;cursor:pointer;font-weight:600}
    .btn-sm-edit{background:#e8f0fe;color:#004EA1}
    .btn-sm-del{background:#fce4ec;color:#c62828}
    /* 툴바 */
    .toolbar{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:16px}
    .toolbar input,.toolbar select{height:36px;border:1px solid #e8eaed;border-radius:6px;padding:0 12px;font-size:13px;background:#fff;outline:none}
    .toolbar input:focus,.toolbar select:focus{border-color:#004EA1}
    .toolbar input{min-width:200px}
    /* 모달 */
    .modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9000;align-items:center;justify-content:center}
    .modal-overlay.open{display:flex}
    .modal-box{background:#fff;border-radius:12px;padding:28px;width:560px;max-width:95vw;max-height:90vh;overflow-y:auto;position:relative}
    .modal-box h3{font-size:16px;font-weight:700;margin-bottom:20px}
    .modal-close{position:absolute;top:14px;right:16px;background:none;border:none;cursor:pointer;color:#888;line-height:1}
    .form-field{display:flex;flex-direction:column;gap:4px;margin-bottom:14px}
    .form-field label{font-size:12px;font-weight:600;color:#666}
    .form-field input,.form-field select,.form-field textarea{border:1px solid #e8eaed;border-radius:6px;padding:8px 10px;font-size:13px;width:100%;background:#fff;outline:none}
    .form-field input:focus,.form-field select:focus,.form-field textarea:focus{border-color:#004EA1}
    .form-2col{display:grid;grid-template-columns:1fr 1fr;gap:12px}
    .form-3col{display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px}
    .modal-footer{display:flex;justify-content:flex-end;gap:10px;margin-top:20px}
    /* 페이지네이션 */
    .pagination{display:flex;gap:5px;justify-content:center;align-items:center;margin-top:16px;flex-wrap:wrap}
    .page-btn{min-width:32px;height:32px;padding:0 6px;border:1px solid #e8eaed;border-radius:6px;background:#fff;cursor:pointer;font-size:13px;color:#444}
    .page-btn.active{background:#004EA1;color:#fff;border-color:#004EA1}
    .page-btn.page-nav{padding:0 12px}
    .page-btn:disabled{opacity:.4;cursor:default}
    .page-gap{align-self:center;padding:0 2px;color:#aaa;font-size:13px}
    </style>
</head>
<body>
<div class="adm-layout">
    <aside class="adm-sidebar">
        <div class="adm-logo">
            <div class="adm-logo-mark">비엠아이</div>
            <div class="adm-logo-sub">Admin Panel</div>
        </div>
        <nav class="adm-nav">
            <?php foreach ($menus as $m): ?>
            <?php if (isset($m['section'])): ?>
            <div class="adm-nav-section"><?= $m['section'] ?></div>
            <?php else: ?>
            <a href="<?= $m['url'] ?>" class="<?= $activeMenu === $m['key'] ? 'active' : '' ?>">
                <?= _adm_icon($m['key']) ?><?= $m['label'] ?>
            </a>
            <?php endif; ?>
            <?php endforeach; ?>
        </nav>
        <div class="adm-sidebar-foot">
            <a href="/index.php">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
                사이트로 돌아가기
            </a>
        </div>
    </aside>
    <main class="adm-main">
        <div class="adm-topbar">
            <span class="adm-topbar-title"><?= htmlspecialchars($title, ENT_QUOTES) ?></span>
        </div>
        <div class="adm-content">
<?php
}

function _adm_icon(string $key): string
{
    return match($key) {
        'dashboard'  => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" style="flex-shrink:0"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>',
        'products'   => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" style="flex-shrink:0"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>',
        'categories' => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" style="flex-shrink:0"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>',
        'estimates'  => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" style="flex-shrink:0"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14,2 14,8 20,8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>',
        'users'      => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" style="flex-shrink:0"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
        'orders'     => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" style="flex-shrink:0"><rect x="1" y="3" width="15" height="13" rx="1"/><path d="M16 8h4l3 5v3h-7V8z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>',
        'stats'      => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" style="flex-shrink:0"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>',
        'chat-logs'  => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" style="flex-shrink:0"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>',
        'rag-docs'   => '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" style="flex-shrink:0"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>',
        default      => '',
    };
}

function render_admin_footer(): void
{
    ?>
        </div>
    </main>
</div>
</body>
</html>
<?php
}
