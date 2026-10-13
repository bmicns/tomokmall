<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

function render_header(string $title, string $mode = 'normal'): void
{
    global $config;

    $path    = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

    // DB 기반 3단계 메뉴 구축 (대분류 → 중분류 → 소분류)
    $megaMenu = [];
    if (db()) {
        try {
            $rows = db()->query(
                'SELECT c.id, c.name, c.parent_id,
                    (SELECT p.image_url FROM products p
                     WHERE p.category_id = c.id AND p.is_active = 1
                       AND p.image_url IS NOT NULL AND p.image_url <> \'\'
                     LIMIT 1) AS thumb
                 FROM categories c
                 WHERE c.is_active = 1
                   AND (
                     (c.parent_id IS NULL AND c.id IN (11,12,13,14,15))
                     OR c.parent_id IN (11,12,13,14,15)
                     OR c.parent_id IN (
                         SELECT id FROM categories
                         WHERE parent_id IN (11,12,13,14,15) AND is_active = 1
                     )
                   )
                 ORDER BY c.parent_id IS NULL DESC, c.parent_id, c.sort_order, c.id'
            )->fetchAll();
            $roots = $midsByRoot = $subsByMid = [];
            foreach ($rows as $r) {
                if ($r['parent_id'] === null)                   $roots[$r['id']] = $r;
                elseif (isset($roots[$r['parent_id']]))         $midsByRoot[$r['parent_id']][] = $r;
                else                                            $subsByMid[$r['parent_id']][] = $r;
            }
            foreach ($roots as $rootId => $root) {
                $mids = [];
                foreach (($midsByRoot[$rootId] ?? []) as $mid) {
                    $mids[] = ['mid' => $mid, 'subs' => $subsByMid[$mid['id']] ?? []];
                }
                $megaMenu[] = ['root' => $root, 'mids' => $mids];
            }
        } catch (\Throwable $e) {}
    }

    // ── mega-tabs HTML 캡처 (일반/채팅 공용) ──────────────────
    ob_start();
    ?>
    <div class="mega-tabs">
        <?php foreach ($megaMenu as $entry):
            $root = $entry['root'];
            $mids = $entry['mids'];
            $displayName = preg_replace('/제품$/u', '', $root['name']);
            // 1단계 링크 → 2단계 첫번째의 3단계 첫번째 카테고리로
            $firstMid  = $mids[0] ?? null;
            $tabTarget = $firstMid['subs'][0]['id'] ?? ($firstMid['mid']['id'] ?? $root['id']);
        ?>
        <div class="mega-item">
            <a href="/products.php?category=<?= $tabTarget ?>" class="mega-tab"><?= e($displayName) ?></a>
            <?php if ($mids): ?>
            <div class="mega-panel">
                <div class="mega-panel-inner">
                    <?php
                    // 고강성(276)+HDPE(277), THP(279)+기타(281) 추출 → PE배수 뒤에 배치
                    $hsGroup = []; $thpGroup = []; $remainMids = [];
                    foreach ($mids as $m) {
                        $mid = (int)$m['mid']['id'];
                        if (in_array($mid, [276, 277]))      $hsGroup[]   = $m;
                        elseif (in_array($mid, [279, 281]))  $thpGroup[]  = $m;
                        else                                  $remainMids[] = $m;
                    }
                    // 나머지로 colGroups 구성 (그레이팅(266)은 절반씩 2컬럼)
                    $colGroups = []; $fewBuf = [];
                    foreach ($remainMids as $m) {
                        if ((int)$m['mid']['id'] === 266 && count($m['subs']) > 4) {
                            if ($fewBuf) { $colGroups[] = $fewBuf; $fewBuf = []; }
                            // id 126(안전중하중그레이팅수로용) 기준으로 분할
                            $half = count($m['subs']); // fallback
                            foreach ($m['subs'] as $si => $sb) { if ((int)$sb['id'] === 126) { $half = $si; break; } }
                            $m1 = $m; $m1['subs'] = array_slice($m['subs'], 0, $half);
                            $m2 = $m; $m2['subs'] = array_slice($m['subs'], $half); $m2['hide_title'] = true;
                            $colGroups[] = [$m1];
                            $colGroups[] = [$m2];
                        } elseif (count($m['subs']) <= 2) {
                            $fewBuf[] = $m;
                        } else {
                            if ($fewBuf) { $colGroups[] = $fewBuf; $fewBuf = []; }
                            $colGroups[] = [$m];
                        }
                    }
                    if ($fewBuf) $colGroups[] = $fewBuf;
                    // PE배수 뒤에 고강성+HDPE, THP+기타 순서로 추가
                    if ($hsGroup)  $colGroups[] = $hsGroup;
                    if ($thpGroup) $colGroups[] = $thpGroup;
                    ?>
                    <?php foreach ($colGroups as $group): ?>
                    <div class="mega-col">
                        <?php foreach ($group as $gi => $midEntry): ?>
                        <?php if ($gi > 0): ?><hr class="mega-col-divider"><?php endif; ?>
                        <a href="/products.php?category=<?= $midEntry['mid']['id'] ?>" class="mega-col-title-link" <?= !empty($midEntry['hide_title']) ? 'style="visibility:hidden"' : '' ?>><?= e($midEntry['mid']['name']) ?></a>
                        <div class="mega-col-items">
                            <?php foreach ($midEntry['subs'] as $sub): ?>
                            <a href="/products.php?category=<?= $sub['id'] ?>" class="mega-3item">
                                <?php if ($sub['thumb']): ?>
                                    <img src="<?= e($sub['thumb']) ?>" alt="" class="mega-3item-img">
                                <?php else: ?>
                                    <span class="mega-3item-thumb"></span>
                                <?php endif; ?>
                                <?= e($sub['name']) ?>
                            </a>
                            <?php endforeach; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php
    $megaTabsHtml = ob_get_clean();
    ?>
<!doctype html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> | <?= e($config['app_name']) ?></title>
    <?php
        // 캐시버스팅: 파일 수정시각을 버전으로 붙여 CSS/JS 갱신이 즉시 반영되게 함
        $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
        $assetV  = static function (string $path) use ($docRoot): string {
            $m = $docRoot ? @filemtime($docRoot . $path) : false;
            return $path . '?v=' . ($m ?: '1');
        };
    ?>
    <link rel="stylesheet" href="<?= e($assetV('/assets/styles.css')) ?>">
    <script src="<?= e($assetV('/assets/search-suggest.js')) ?>" defer></script>
    <script>
    // 깨진/누락 이미지 → 브라우저 기본 깨진아이콘 대신 중립 플레이스홀더로 대체
    window.addEventListener('error', function (e) {
      var t = e.target;
      if (t && t.tagName === 'IMG' && !t.dataset.imgfb) {
        t.dataset.imgfb = '1';
        t.src = "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Crect width='64' height='64' fill='%23f0f0f0'/%3E%3Cg fill='%23cccccc'%3E%3Ccircle cx='23' cy='23' r='6'/%3E%3Cpath d='M10 52l16-18 11 13 8-8 9 13z'/%3E%3C/g%3E%3C/svg%3E";
      }
    }, true);
    </script>
</head>
<body class="mode-<?= $mode === 'chat' ? 'chat' : 'normal' ?>">

<!-- ── 상단 헤더 + GNB ───────────────────────────────────── -->
<header class="site-header">
    <div class="header-inner">
        <a class="brand" href="/index.php">
            <span class="brand-mark">TM</span>
            <span class="brand-name">비엠아이토목건축자재</span>
        </a>
        <nav class="header-nav">
            <?php if ($mode === 'chat') echo $megaTabsHtml; ?>
            <button class="btn-header-search btn-header-search--text" onclick="var w=document.getElementById('header-search-wrap');w.classList.toggle('open');if(w.classList.contains('open'))document.getElementById('header-search-input').focus();">검색</button>
            <a href="/real-estate-safety.php" class="nav-link<?= $path === '/real-estate-safety.php' ? ' active' : '' ?>">부동산안전</a>
            <?php if (is_logged_in()): ?>
            <a href="/chat.php" class="btn-header-ai<?= $path === '/chat.php' ? ' active' : '' ?>">AI 상담</a>
            <a href="/estimate.php" class="btn-header-est<?= $path === '/estimate.php' ? ' active' : '' ?>">견적보기</a>
            <a href="/mypage.php" class="nav-link<?= $path === '/mypage.php' ? ' active' : '' ?>">마이페이지</a>
            <a href="#" class="nav-link" onclick="event.preventDefault();fetch('/api/auth/logout.php',{method:'POST'}).then(function(){location.href='/'});">로그아웃</a>
            <?php else: ?>
            <a href="/register.php" class="nav-link" onclick="openAuthModal('register');return false;">회원가입</a>
            <a href="/login.php" class="nav-link" onclick="openAuthModal('login');return false;">로그인</a>
            <a href="/chat.php" class="btn-header-ai<?= $path === '/chat.php' ? ' active' : '' ?>">AI 상담</a>
            <a href="#" class="btn-header-est" onclick="event.preventDefault();openAuthModal('login');">견적보기</a>
            <?php endif; ?>
        </nav>
    </div>
    <?php if ($mode !== 'chat'): ?>
    <div class="gnb-bar">
        <?= $megaTabsHtml ?>
    </div>
    <?php endif; ?>
</header>
<div id="header-search-wrap" class="header-search-wrap">
    <form action="/products.php" method="get" class="header-search-form">
        <input type="text" name="q" id="header-search-input" class="header-search-input" placeholder="제품명, 규격 검색…" autocomplete="off" data-suggest>
        <button type="submit" class="header-search-submit" aria-label="검색">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        </button>
    </form>
</div>

<!-- ── 로그인 모달 ───────────────────────────────────── -->
<div class="auth-overlay" id="modal-login" onclick="if(event.target===this)closeAuthModal()">
  <div class="auth-modal">
    <button class="auth-modal-close" onclick="closeAuthModal()">×</button>
    <div class="auth-modal-title">로그인</div>
    <div class="auth-modal-tabs">
      <button class="auth-modal-tab active" onclick="switchAuthTab(this)">개인회원</button>
      <button class="auth-modal-tab" onclick="switchAuthTab(this)">기업회원</button>
    </div>
    <div class="auth-modal-field">
      <label class="auth-modal-label">이메일</label>
      <input id="m-login-email" type="email" class="auth-modal-input" placeholder="hong@example.com" autocomplete="email">
    </div>
    <div class="auth-modal-field">
      <label class="auth-modal-label">비밀번호</label>
      <input id="m-login-pw" type="password" class="auth-modal-input" placeholder="비밀번호" autocomplete="current-password"
        onkeydown="if(event.key==='Enter')doAuthLogin()">
    </div>
    <p class="auth-modal-err" id="m-login-err"></p>
    <button class="auth-modal-btn" id="m-login-btn" onclick="doAuthLogin()">로그인</button>
    <p class="auth-modal-switch">계정이 없으신가요? <a onclick="openAuthModal('register')">회원가입</a></p>
  </div>
</div>

<!-- ── 회원가입 모달 ─────────────────────────────────── -->
<div class="auth-overlay" id="modal-register" onclick="if(event.target===this)closeAuthModal()">
  <div class="auth-modal">
    <button class="auth-modal-close" onclick="closeAuthModal()">×</button>
    <div class="auth-modal-title">회원가입</div>
    <div class="auth-modal-tabs">
      <button class="auth-modal-tab active" onclick="switchRegTab(this,'customer')">개인회원</button>
      <button class="auth-modal-tab" onclick="switchRegTab(this,'corporate')">기업회원</button>
    </div>
    <input type="hidden" id="m-reg-role" value="customer">
    <div class="auth-modal-field">
      <label class="auth-modal-label" id="m-reg-name-label">이름</label>
      <input id="m-reg-name" type="text" class="auth-modal-input" placeholder="홍길동" maxlength="80" autocomplete="name">
    </div>
    <div class="auth-modal-field" id="m-reg-company-wrap" style="display:none">
      <label class="auth-modal-label">회사명</label>
      <input id="m-reg-company" type="text" class="auth-modal-input" placeholder="(주)비엠아이씨앤에스" maxlength="120" autocomplete="organization">
    </div>
    <div class="auth-modal-field">
      <label class="auth-modal-label">전화번호 <span class="auth-opt-tag">(선택)</span></label>
      <input id="m-reg-phone" type="tel" class="auth-modal-input" placeholder="010-0000-0000" maxlength="40" autocomplete="tel">
    </div>
    <div class="auth-modal-field">
      <label class="auth-modal-label">주소 <span class="auth-opt-tag">(선택)</span></label>
      <div style="display:flex;gap:6px">
        <input id="m-reg-zip" type="text" class="auth-modal-input" placeholder="우편번호" style="flex:0 0 110px" readonly>
        <button type="button" onclick="openDaumPost('reg')" style="padding:0 12px;background:var(--color-primary);color:#fff;border:none;font-size:12px;font-weight:600;cursor:pointer;white-space:nowrap;font-family:var(--font)">주소 찾기</button>
      </div>
    </div>
    <div class="auth-modal-field">
      <input id="m-reg-addr" type="text" class="auth-modal-input" placeholder="기본주소" readonly>
    </div>
    <div class="auth-modal-field">
      <input id="m-reg-addr-detail" type="text" class="auth-modal-input" placeholder="상세주소 (동/호수 등)" maxlength="255">
    </div>
    <hr class="auth-modal-divider">
    <div class="auth-modal-field">
      <label class="auth-modal-label">이메일</label>
      <input id="m-reg-email" type="email" class="auth-modal-input" placeholder="hong@example.com" maxlength="160" autocomplete="email">
    </div>
    <div class="auth-modal-field">
      <label class="auth-modal-label">비밀번호 <span class="auth-opt-tag">(8자 이상)</span></label>
      <input id="m-reg-pw" type="password" class="auth-modal-input" placeholder="비밀번호" maxlength="100" autocomplete="new-password">
    </div>
    <div class="auth-modal-field">
      <label class="auth-modal-label">비밀번호 확인</label>
      <input id="m-reg-pw2" type="password" class="auth-modal-input" placeholder="비밀번호 재입력" maxlength="100" autocomplete="new-password"
        onkeydown="if(event.key==='Enter')doAuthRegister()">
    </div>
    <p class="auth-modal-err" id="m-reg-err"></p>
    <p class="auth-modal-ok" id="m-reg-ok">가입 완료! 로그인 화면으로 이동합니다...</p>
    <button class="auth-modal-btn" id="m-reg-btn" onclick="doAuthRegister()">가입하기</button>
    <p class="auth-modal-switch">이미 계정이 있으신가요? <a onclick="openAuthModal('login')">로그인</a></p>
  </div>
</div>

<script>
function openAuthModal(type) {
  ['login','register'].forEach(function(t){ document.getElementById('modal-'+t).classList.remove('open'); });
  document.getElementById('modal-'+type).classList.add('open');
  document.body.style.overflow='hidden';
  setTimeout(function(){ var el=document.getElementById(type==='login'?'m-login-email':'m-reg-name'); if(el)el.focus(); },80);
}
function closeAuthModal() {
  ['login','register'].forEach(function(t){ document.getElementById('modal-'+t).classList.remove('open'); });
  document.body.style.overflow='';
}
document.addEventListener('keydown',function(e){ if(e.key==='Escape')closeAuthModal(); });

function switchAuthTab(btn) {
  btn.closest('.auth-modal-tabs').querySelectorAll('.auth-modal-tab').forEach(function(b){ b.classList.remove('active'); });
  btn.classList.add('active');
}
function switchRegTab(btn, role) {
  btn.closest('.auth-modal-tabs').querySelectorAll('.auth-modal-tab').forEach(function(b){ b.classList.remove('active'); });
  btn.classList.add('active');
  document.getElementById('m-reg-role').value = role;
  var isCorp = role === 'corporate';
  document.getElementById('m-reg-company-wrap').style.display = isCorp ? '' : 'none';
  document.getElementById('m-reg-name-label').textContent = isCorp ? '담당자명' : '이름';
}

async function doAuthLogin() {
  var email=document.getElementById('m-login-email').value.trim();
  var pw=document.getElementById('m-login-pw').value;
  var err=document.getElementById('m-login-err');
  var btn=document.getElementById('m-login-btn');
  if(!email||!pw){authShowErr(err,'이메일과 비밀번호를 입력해주세요.');return;}
  btn.disabled=true;btn.textContent='로그인 중...';err.style.display='none';
  try{
    var r=await fetch('/api/auth/login.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({email:email,password:pw})});
    var d=await r.json();
    if(d.ok){location.reload();}
    else{authShowErr(err,d.error||'로그인에 실패했습니다.');btn.disabled=false;btn.textContent='로그인';}
  }catch{authShowErr(err,'네트워크 오류가 발생했습니다.');btn.disabled=false;btn.textContent='로그인';}
}

async function doAuthRegister() {
  var role=document.getElementById('m-reg-role').value;
  var name=document.getElementById('m-reg-name').value.trim();
  var co=role==='corporate'?document.getElementById('m-reg-company').value.trim():'';
  var phone=document.getElementById('m-reg-phone').value.trim();
  var zip=document.getElementById('m-reg-zip').value.trim();
  var addr=document.getElementById('m-reg-addr').value.trim();
  var addrDetail=document.getElementById('m-reg-addr-detail').value.trim();
  var email=document.getElementById('m-reg-email').value.trim();
  var pw=document.getElementById('m-reg-pw').value;
  var pw2=document.getElementById('m-reg-pw2').value;
  var err=document.getElementById('m-reg-err');
  var btn=document.getElementById('m-reg-btn');
  if(!name){authShowErr(err,'이름을 입력해주세요.');return;}
  if(role==='corporate'&&!co){authShowErr(err,'회사명을 입력해주세요.');return;}
  if(!email){authShowErr(err,'이메일을 입력해주세요.');return;}
  if(pw.length<8){authShowErr(err,'비밀번호는 8자 이상이어야 합니다.');return;}
  if(pw!==pw2){authShowErr(err,'비밀번호가 일치하지 않습니다.');return;}
  btn.disabled=true;btn.textContent='처리 중...';err.style.display='none';
  try{
    var r=await fetch('/api/auth/register.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({role,name,company_name:co,phone,email,password:pw,address_zip:zip,address:addr,address_detail:addrDetail})});
    var d=await r.json();
    if(d.ok){
      btn.style.display='none';
      document.getElementById('m-reg-ok').style.display='block';
      setTimeout(function(){openAuthModal('login');},1800);
    }else{authShowErr(err,d.error||'가입에 실패했습니다.');btn.disabled=false;btn.textContent='가입하기';}
  }catch{authShowErr(err,'네트워크 오류가 발생했습니다.');btn.disabled=false;btn.textContent='가입하기';}
}
function authShowErr(el,msg){el.textContent=msg;el.style.display='block';}

function openDaumPost(target) {
  function _open() {
    new daum.Postcode({
      oncomplete: function(data) {
        var zip = data.zonecode;
        var addr = data.roadAddress || data.jibunAddress;
        if (data.buildingName && data.apartment === 'Y') addr += ' (' + data.buildingName + ')';
        if (target === 'reg') {
          document.getElementById('m-reg-zip').value = zip;
          document.getElementById('m-reg-addr').value = addr;
          document.getElementById('m-reg-addr-detail').focus();
        } else {
          document.getElementById('p-zip').value = zip;
          document.getElementById('p-addr').value = addr;
          document.getElementById('p-addr-detail').focus();
        }
      }
    }).open();
  }
  if (window.daum && window.daum.Postcode) { _open(); return; }
  var s = document.createElement('script');
  s.src = 'https://t1.daumcdn.net/mapjsapi/bundle/postcode/prod/postcode.v2.js';
  s.onload = _open;
  document.head.appendChild(s);
}
</script>

<main>
    <?php
}

function render_footer(): void
{
    ?>
</main>

<!-- ── 푸터 ──────────────────────────────────────────────── -->
<footer class="site-footer">
    <div class="footer-inner">
        <div class="footer-company">
            <strong>BMI C&amp;S Co., Ltd.</strong>
            <p>대표이사 김동의 &nbsp; 주소 서울시 강남구 강남대로 354 (역삼동, 혜천빌딩 1126-5호)</p>
            <p>전화 010-8490-9271 &nbsp; 이메일 bmicns@gmail.com</p>
            <p>사업자등록번호 263-87-03481 &nbsp; 통신판매신고번호 2025-경기광명-0189</p>
            <p>영업시간 평일 09:00 ~ 18:00</p>
            <p>Copyright &copy; 2026 BMI C&amp;S Co., Ltd. All rights reserved.</p>
        </div>
    </div>
</footer>

<script>
document.addEventListener('DOMContentLoaded', () => {
    // 컬럼 너비 균등 조정
    document.querySelectorAll('.mega-panel').forEach(panel => {
        panel.style.cssText = 'display:block;visibility:hidden;pointer-events:none';
        let maxW = 0;
        panel.querySelectorAll('.mega-col').forEach(col => {
            maxW = Math.max(maxW, col.offsetWidth);
        });
        panel.style.cssText = '';
        if (maxW > 0) {
            panel.querySelectorAll('.mega-col').forEach(col => {
                col.style.width  = maxW + 'px';
                col.style.minWidth = maxW + 'px';
            });
        }
    });

    // 메가메뉴 hover 딜레이 — 대각선 이동 시 패널이 바로 닫히는 문제 해결
    var allItems = Array.from(document.querySelectorAll('.mega-item'));
    allItems.forEach(function(item) {
        var panel = item.querySelector('.mega-panel');

        function open() {
            // 다른 항목 닫기 (타이머 취소 포함)
            allItems.forEach(function(other) {
                if (other !== item) {
                    clearTimeout(other._mTimer);
                    other.classList.remove('is-open');
                }
            });
            clearTimeout(item._mTimer);
            item.classList.add('is-open');
        }
        function startClose() {
            item._mTimer = setTimeout(function() {
                item.classList.remove('is-open');
            }, 200);
        }

        item.addEventListener('mouseenter', open);
        item.addEventListener('mouseleave', startClose);
        if (panel) {
            panel.addEventListener('mouseenter', open);
            panel.addEventListener('mouseleave', startClose);
        }
    });
});
</script>
</body>
</html>
    <?php
}
