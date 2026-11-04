<?php
declare(strict_types=1);
/**
 * 관리자 — 상담 요청 상세 ('상담 요청 — 관리자 상세' 시안)
 * GET ?id=N
 * 왼쪽: 요청 정보 · 문의 내용 · AI 상담 내역 전체(같은 상담 키의 chat_logs 전 턴 + 상품 추천표)
 * 오른쪽: 상태 변경 · 메모 · 처리 이력  → POST /api/admin/consults.php
 */
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/auth.php';
require __DIR__ . '/../app/admin_layout.php';
require __DIR__ . '/../app/consult.php';
require_admin();

$id  = max(0, (int) ($_GET['id'] ?? 0));
$req = null;
$events = [];
$history = ['turns' => [], 'source' => 'none'];
$dbError = false;
try {
    $pdo = db();
    if (!$pdo) {
        throw new RuntimeException('db');
    }
    $req = $id > 0 ? consult_get($pdo, $id) : null;
    if ($req) {
        $events  = consult_events($pdo, $id);
        $history = consult_chat_history($pdo, $req['session_key']);
    }
} catch (\Throwable $e) {
    error_log('[admin-consult] 상세 조회 실패: ' . get_class($e));
    $dbError = true;
}

$statuses = consult_statuses();
$csrf     = consult_csrf_token();
$fmtWon   = static fn(int $n): string => '₩' . number_format($n);

render_admin_header('상담 요청', 'consults');

if (!$req): ?>
<p style="margin-bottom:12px"><a href="/admin-consults.php" style="color:#666;font-size:13px">‹ 상담 요청 목록</a></p>
<div class="card" style="padding:40px 20px;text-align:center;color:#888"><?= $dbError ? '상담 요청을 불러오지 못했습니다.' : '상담 요청을 찾을 수 없습니다.' ?></div>
<?php render_admin_footer(); return; endif;

$status  = isset($statuses[$req['status']]) ? $req['status'] : 'pending';
$closed  = $status === 'closed';
$dotOf   = ['pending' => '#e65100', 'contact' => '#004EA1', 'closed' => '#999'];
$reqAt   = (string) $req['created_at'];
?>
<style>
.cd .badge{white-space:nowrap}
.badge-contact{background:#e8f0fe;color:#004EA1}
.badge-closed{background:#f0f2f5;color:#777}
.cd-back{display:inline-flex;align-items:center;gap:4px;min-height:32px;font-size:12.5px;color:#666;margin-bottom:6px;text-decoration:none}
.cd-back:hover{color:#004EA1}
.cd-head{display:flex;align-items:center;gap:10px;margin-bottom:16px}
.cd-head .page-heading{margin:0}
.cd-grid{display:grid;grid-template-columns:minmax(0,1fr) 360px;gap:16px;align-items:stretch}
.cd-col{display:flex;flex-direction:column;gap:16px;min-width:0}
.cd-info{display:grid;grid-template-columns:110px minmax(0,1fr);margin:0;font-size:13px}
.cd-info dt{padding:12px 20px;color:#777;border-bottom:1px solid #f0f0f0;display:flex;align-items:center;white-space:nowrap}
.cd-info dd{margin:0;padding:12px 20px 12px 0;border-bottom:1px solid #f0f0f0;display:flex;align-items:center;gap:8px;min-width:0}
.cd-info dt.last,.cd-info dd.last{border-bottom:none}
.cd-pii-gone{color:#bbb !important;font-weight:600}
.cd-terms-btn{margin-left:auto;flex-shrink:0;min-height:30px;padding:0 4px;background:none;border:none;font-size:12px;color:#004EA1;text-decoration:underline;font-family:inherit;cursor:pointer}
.cd-info dd.cd-terms{grid-column:1/-1;margin:0;padding:0 20px 14px;border:none;display:block}
.cd-info dd.cd-terms[hidden]{display:none}
.cd-terms dl{margin:0;padding:12px 14px;background:#f8f9fb;border:1px solid #eef0f3;border-radius:6px;display:grid;grid-template-columns:104px minmax(0,1fr);gap:4px 0;font-size:12px;line-height:1.7;color:#555}
.cd-terms dl dt{color:#888;padding:0;border:none;display:block}
.cd-terms dl dd{margin:0;padding:0;border:none;display:block}
.cd-msg{margin:0;padding:16px 20px;font-size:14px;line-height:1.75;color:#222;white-space:pre-line;word-break:break-word}
.cd-chat-card{flex:1 1 auto;display:flex;flex-direction:column;min-height:0}
.cd-chat-meta{font-size:12px;color:#888}
.cd-chat{flex:1 1 0;min-height:600px;max-height:900px;overflow-y:auto;padding:16px 20px;display:flex;flex-direction:column;gap:12px;font-size:13px;line-height:1.65;background:#fcfcfd;outline:none}
.cd-chat:focus-visible{box-shadow:inset 0 0 0 2px #004EA1}
.cd-chat-start,.cd-chat-event{align-self:center;font-size:11px;color:#999}
.cd-chat-event{padding:3px 10px;border-radius:10px;background:#fff3e0;color:#e65100;font-weight:600}
.cd-m{display:flex;flex-direction:column;align-items:flex-start}
.cd-m.me{align-items:flex-end}
.cd-who{font-size:11px;color:#999;margin-bottom:3px}
.cd-bubble{max-width:82%;padding:10px 14px;border-radius:10px;background:#fff;color:#222;border:1px solid #e8eaed;white-space:pre-line;word-break:break-word}
.cd-m.me .cd-bubble{background:#004EA1;color:#fff;border-color:#004EA1}
.cd-prods{width:82%;margin-top:6px;border:1px solid #e8eaed;border-radius:10px;background:#fff;overflow:hidden}
.cd-prods-sum{padding:9px 12px;background:#f8f9fb;border-bottom:1px solid #e8eaed;font-size:12px;color:#555}
.cd-prods-sum b{color:#004EA1}
.cd-prod{display:grid;grid-template-columns:52px minmax(0,1fr) auto;gap:12px;align-items:center;padding:10px 12px;border-bottom:1px solid #f0f0f0}
.cd-prod:last-child{border-bottom:none}
.cd-prod img,.cd-prod .ph{width:52px;height:52px;object-fit:contain;background:#f8f9fb}
.cd-prod-name{font-weight:600}
.cd-prod-name a{color:inherit;text-decoration:none}
.cd-prod-name a:hover{text-decoration:underline}
.cd-prod-spec{font-size:12px;color:#777}
.cd-prod-price{text-align:right;white-space:nowrap}
.cd-prod-price b{color:#004EA1}
.cd-prod-price div{font-size:12px;color:#555}
.cd-empty{margin:auto;color:#999;font-size:13px;text-align:center}
.cd-sec{padding:16px 20px}
.cd-sec + .cd-sec{border-top:1px solid #f0f0f0}
.cd-lbl{display:block;font-size:12px;font-weight:600;color:#666;margin-bottom:6px}
.cd-status{display:grid;grid-template-columns:repeat(3,1fr);gap:6px}
.cd-status button{height:40px;border-radius:6px;border:1px solid #e8eaed;background:#fff;color:#444;font-size:13px;font-weight:600;font-family:inherit;cursor:pointer}
.cd-status button[aria-checked="true"]{border-color:#004EA1;background:#004EA1;color:#fff}
.cd-status button:disabled{cursor:not-allowed}
.cd-status button:disabled:not([aria-checked="true"]){color:#bbb}
.cd-warn{margin-top:8px;padding:10px;border-radius:6px;background:#fce4ec;color:#c62828;font-size:12px;line-height:1.6}
.cd-warn-btns{display:flex;gap:6px;margin-top:8px}
.cd-warn-btns .btn{flex:1;height:34px}
.cd-ta{width:100%;border:1px solid #e8eaed;border-radius:6px;padding:9px 10px;font-size:13px;line-height:1.6;font-family:inherit;resize:vertical;outline:none;color:#1a2035}
.cd-ta:focus{border-color:#004EA1}
.cd-act{width:100%;height:40px;margin-top:10px}
.cd-act:disabled{background:#f0f2f5 !important;color:#bbb !important;cursor:not-allowed}
.cd-msgline{margin-top:8px;font-size:12px;line-height:1.5}
.cd-msgline.err{color:#c62828}
.cd-msgline.ok{color:#2e7d32}
.cd-hist{list-style:none;margin:0;padding:14px 20px}
.cd-hist li{position:relative;padding:0 0 14px 18px;font-size:12.5px;line-height:1.6}
.cd-hist li:last-child{padding-bottom:0}
.cd-hist .line{position:absolute;left:3px;top:16px;bottom:-4px;width:2px;background:#e3e6ea}
.cd-hist li:last-child .line{display:none}
.cd-hist .dot{position:absolute;left:0;top:6px;width:8px;height:8px;border-radius:50%;box-shadow:0 0 0 3px #fff}
.cd-hist .t{color:#222;font-weight:600}
.cd-hist .m{color:#888}
.cd-hist .x{margin-top:4px;padding:6px 8px;border-radius:6px;background:#f8f9fb;color:#555;white-space:pre-line;word-break:break-word;max-height:7.5em;overflow:auto}
@media (max-width:1100px){.cd-grid{grid-template-columns:minmax(0,1fr)}.cd-chat{min-height:420px}}
@media (max-width:640px){.cd-info{grid-template-columns:90px minmax(0,1fr)}.cd-info dt{padding:12px 12px}.cd-bubble,.cd-prods{max-width:100%;width:100%}.cd-status button,.cd-act{height:44px}}
</style>
<div class="cd">
  <a class="cd-back" href="/admin-consults.php"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="15 18 9 12 15 6"></polyline></svg>상담 요청 목록</a>
  <div class="cd-head">
    <h1 class="page-heading">상담 요청 #<?= (int) $req['id'] ?></h1>
    <span class="badge <?= e($statuses[$status][1]) ?>" style="font-size:12px;padding:3px 10px"><?= e($statuses[$status][0]) ?></span>
  </div>

  <div class="cd-grid">
    <!-- 왼쪽: 고객이 남긴 것 + 그 전에 나눈 AI 대화 -->
    <div class="cd-col">
      <section class="card" aria-labelledby="h-info">
        <div class="card-header"><h2 class="card-title" id="h-info">요청 정보</h2></div>
        <dl class="cd-info">
          <dt>접수일시</dt><dd style="font-variant-numeric:tabular-nums"><?= e(consult_fmt_dt($reqAt)) ?></dd>
          <dt>이름</dt><dd style="font-weight:600"<?= $closed ? ' class="cd-pii-gone"' : '' ?>><?= $closed ? '파기됨' : e((string) $req['name']) ?></dd>
          <dt>연락처</dt><dd><span style="font-weight:600;font-variant-numeric:tabular-nums"<?= $closed ? ' class="cd-pii-gone"' : '' ?>><?= $closed ? '파기됨' : e((string) $req['tel']) ?></span></dd>
          <dt class="last">개인정보 동의</dt>
          <dd class="last" style="color:#555">
            <span class="badge badge-contact">동의</span>
            <span style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;min-width:0">[필수] 개인정보 수집·이용 동의</span>
            <button type="button" class="cd-terms-btn" id="cd-terms-btn" aria-expanded="false" aria-controls="cd-terms">펼치기</button>
          </dd>
          <dd class="cd-terms" id="cd-terms" hidden>
            <dl>
              <dt>동의 일시</dt><dd><?= e(consult_fmt_dt((string) $req['agreed_at'])) ?></dd>
              <dt>수집 항목</dt><dd>이름, 연락처, 문의 내용</dd>
              <dt>수집·이용 목적</dt><dd>상담 문의 접수 및 답변 안내</dd>
              <dt>보유·이용 기간</dt><dd>상담 완료 후 지체 없이 파기<?= $req['pii_destroyed_at'] ? ' (파기 ' . e(consult_fmt_dt((string) $req['pii_destroyed_at'])) . ')' : '' ?></dd>
            </dl>
          </dd>
        </dl>
      </section>

      <section class="card" aria-labelledby="h-msg">
        <div class="card-header"><h2 class="card-title" id="h-msg">문의 내용</h2></div>
        <p class="cd-msg"><?= e((string) $req['message']) ?></p>
      </section>

      <section class="card cd-chat-card" aria-labelledby="h-chat">
        <div class="card-header">
          <h2 class="card-title" id="h-chat">AI 상담 내역</h2>
          <?php if ($history['turns']): ?><span class="cd-chat-meta">질문 <?= count($history['turns']) ?>건<?= $history['source'] === 'ai_sessions' ? ' · 추천표 기록 없음(최근 대화만)' : '' ?></span><?php endif; ?>
        </div>
        <div class="cd-chat" id="cd-chat" tabindex="0" aria-label="AI 상담 대화 (스크롤)">
          <?php if (!$history['turns']): ?>
          <p class="cd-empty">연결된 AI 상담 내역이 없습니다.</p>
          <?php else:
            $first = $history['turns'][0]['at'] ?: $reqAt;
            $shownReq = false;
            $reqTs = strtotime($reqAt) ?: 0;
          ?>
          <div class="cd-chat-start"><?= e(substr(consult_fmt_dt($first), 0, 10)) ?> · 대화 시작</div>
          <?php foreach ($history['turns'] as $t):
            $ts = $t['at'] !== '' ? (strtotime($t['at']) ?: 0) : 0;
            if (!$shownReq && $ts > 0 && $ts > $reqTs): $shownReq = true; ?>
          <div class="cd-chat-event"><?= e(date('H:i', $reqTs)) ?> · 상담원 문의 요청</div>
          <?php endif;
            $time = $ts > 0 ? date('H:i', $ts) : '';
          ?>
          <?php if ($t['user'] !== ''): ?>
          <div class="cd-m me"><span class="cd-who">고객<?= $time !== '' ? ' · ' . e($time) : '' ?></span><div class="cd-bubble"><?= e($t['user']) ?></div></div>
          <?php endif; ?>
          <?php if ($t['ai'] !== '' || $t['products']): ?>
          <div class="cd-m">
            <span class="cd-who">AI<?= $time !== '' ? ' · ' . e($time) : '' ?></span>
            <?php if (trim($t['ai']) !== ''): ?><div class="cd-bubble"><?= e($t['ai']) ?></div><?php endif; ?>
            <?php if ($t['products']):
              $costs = array_values(array_filter(array_column($t['products'], 'est_cost'), static fn($c) => $c > 0));
            ?>
            <div class="cd-prods">
              <?php if ($costs): ?>
              <div class="cd-prods-sum">입력하신 면적·길이 기준 예상 자재비 <b><?= e($fmtWon(min($costs))) ?><?= min($costs) !== max($costs) ? ' ~ ' . e($fmtWon(max($costs))) : '' ?></b> <span style="color:#999">· 상담 당시 판매가 기준, 운반비·시공비 별도</span></div>
              <?php else: ?>
              <div class="cd-prods-sum">추천 상품 <?= count($t['products']) ?>개 <span style="color:#999">· 상담 당시 판매가 기준</span></div>
              <?php endif; ?>
              <?php foreach ($t['products'] as $p): ?>
              <div class="cd-prod">
                <?php if ($p['image'] !== ''): ?><img src="<?= e($p['image']) ?>" alt="<?= e($p['name']) ?>" loading="lazy"><?php else: ?><span class="ph" aria-hidden="true"></span><?php endif; ?>
                <div style="min-width:0">
                  <div class="cd-prod-name"><?php if ($p['id'] > 0): ?><a href="/product.php?id=<?= (int) $p['id'] ?>" target="_blank" rel="noopener"><?= e($p['name']) ?></a><?php else: ?><?= e($p['name']) ?><?php endif; ?></div>
                  <div class="cd-prod-spec"><?= e($p['spec']) ?><?= $p['qty'] > 0 ? ' · 권장 ' . number_format($p['qty']) . ($p['unit'] !== '' ? e($p['unit']) : '') : '' ?></div>
                </div>
                <div class="cd-prod-price">
                  <b><?= $p['price'] > 0 ? e($fmtWon($p['price'])) . ($p['unit'] !== '' ? '/' . e($p['unit']) : '') : '가격 문의' ?></b>
                  <?php if ($p['est_cost'] > 0): ?><div>예상 <?= e($fmtWon($p['est_cost'])) ?></div><?php endif; ?>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>
          </div>
          <?php endif; ?>
          <?php endforeach; ?>
          <?php if (!$shownReq): ?>
          <div class="cd-chat-event"><?= e(date('H:i', $reqTs)) ?> · 상담원 문의 요청</div>
          <?php endif; ?>
          <?php endif; ?>
        </div>
      </section>
    </div>

    <!-- 오른쪽: 처리 -->
    <div class="cd-col">
      <section class="card" aria-labelledby="h-status">
        <div class="card-header"><h2 class="card-title" id="h-status">처리 상태</h2></div>
        <div class="cd-sec">
          <div class="cd-lbl" id="cd-status-lbl">상태</div>
          <div class="cd-status" role="radiogroup" aria-labelledby="cd-status-lbl">
            <?php foreach ($statuses as $k => [$label]): ?>
            <button type="button" role="radio" data-status="<?= e($k) ?>" aria-checked="<?= $k === $status ? 'true' : 'false' ?>"<?= $closed ? ' disabled' : '' ?>><?= e($label) ?></button>
            <?php endforeach; ?>
          </div>
          <div class="cd-warn" id="cd-warn" role="alert" hidden>
            완료하면 이름·연락처가 파기되어 다시 볼 수 없습니다. 문의 내용과 처리 이력은 남습니다.
            <div class="cd-warn-btns">
              <button type="button" class="btn btn-secondary" style="background:#fff" id="cd-warn-cancel">취소</button>
              <button type="button" class="btn" style="background:#c62828;color:#fff" id="cd-warn-ok">완료 처리</button>
            </div>
          </div>
          <p class="cd-msgline err" id="cd-status-err" role="alert" hidden></p>
        </div>
      </section>

      <section class="card" aria-labelledby="h-memo">
        <div class="card-header"><h2 class="card-title" id="h-memo">메모</h2><span style="font-size:12px;color:#888" id="cd-memo-saved"><?= $req['memo_updated_at'] ? e(consult_fmt_short((string) $req['memo_updated_at'])) . ' 저장' : '' ?></span></div>
        <div class="cd-sec">
          <label for="cd-memo" class="cd-lbl">처리 메모</label>
          <textarea id="cd-memo" class="cd-ta" style="height:96px" maxlength="2000" placeholder="통화 내용, 다음 할 일 등 (고객에게는 보이지 않습니다)"><?= e((string) $req['memo']) ?></textarea>
          <button type="button" class="btn btn-primary cd-act" id="cd-memo-save" disabled>메모 저장</button>
          <p class="cd-msgline err" id="cd-memo-err" role="alert" hidden></p>
        </div>
      </section>

      <section class="card" aria-labelledby="h-hist">
        <div class="card-header"><h2 class="card-title" id="h-hist">처리 이력</h2></div>
        <ol class="cd-hist">
          <?php foreach ($events as $ev):
            $at  = consult_fmt_short((string) $ev['created_at']);
            $who = $ev['admin_name'] ? ' · ' . $ev['admin_name'] : '';
            if ($ev['type'] === 'created') {
                [$title, $meta, $dot, $text] = ['접수됨', $at . ' · 고객 요청', $dotOf['pending'], ''];
            } else {
                $s = isset($statuses[$ev['status']]) ? $ev['status'] : 'pending';
                [$title, $meta, $dot, $text] = [$statuses[$s][0], $at . ' · ' . ($ev['detail'] ?: '상태 변경') . $who, $dotOf[$s], ''];
            }
          ?>
          <li>
            <span class="line" aria-hidden="true"></span>
            <span class="dot" aria-hidden="true" style="background:<?= e($dot) ?>"></span>
            <div class="t"><?= e($title) ?></div>
            <div class="m"><?= e($meta) ?></div>
            <?php if ($text !== ''): ?><div class="x"><?= e($text) ?></div><?php endif; ?>
          </li>
          <?php endforeach; ?>
        </ol>
      </section>
    </div>
  </div>
</div>
<script>
(function () {
  const ID = <?= (int) $req['id'] ?>;
  const CSRF = <?= json_encode($csrf) ?>;
  const STATUS = <?= json_encode($status) ?>;
  const call = async (body) => {
    try {
      const r = await fetch('/api/admin/consults.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
        body: JSON.stringify(Object.assign({ id: ID }, body)),
      });
      const d = await r.json().catch(() => null);
      return d || { ok: false, error: '처리하지 못했습니다. 잠시 후 다시 시도해 주세요.' };
    } catch { return { ok: false, error: '서버에 연결하지 못했습니다.' }; }
  };
  const show = (el, text, cls) => { el.textContent = text; el.hidden = !text; if (cls) el.className = 'cd-msgline ' + cls; };

  // 개인정보 동의 펼치기/접기
  const tBtn = document.getElementById('cd-terms-btn'), tBox = document.getElementById('cd-terms');
  tBtn.addEventListener('click', () => {
    const open = tBox.hidden;
    tBox.hidden = !open;
    tBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
    tBtn.textContent = open ? '접기' : '펼치기';
  });

  // 상태: 누르면 바로 반영, '완료'만 개인정보 파기 확인을 한 번 더
  const radios = [...document.querySelectorAll('.cd-status [role=radio]')];
  const warn = document.getElementById('cd-warn'), sErr = document.getElementById('cd-status-err');
  const mark = (k) => radios.forEach(b => b.setAttribute('aria-checked', b.dataset.status === k ? 'true' : 'false'));
  const apply = async (k) => {
    radios.forEach(b => b.disabled = true);
    const d = await call({ action: 'set_status', status: k });
    if (d.ok) { location.reload(); return; }
    radios.forEach(b => b.disabled = false);
    mark(STATUS);
    show(sErr, d.error || '상태를 바꾸지 못했습니다.');
  };
  radios.forEach(b => b.addEventListener('click', () => {
    const k = b.dataset.status;
    show(sErr, '');
    if (k === STATUS) { mark(STATUS); warn.hidden = true; return; }
    if (k === 'closed') { mark('closed'); warn.hidden = false; return; }
    warn.hidden = true;
    apply(k);
  }));
  document.getElementById('cd-warn-cancel').addEventListener('click', () => { warn.hidden = true; mark(STATUS); });
  document.getElementById('cd-warn-ok').addEventListener('click', () => { warn.hidden = true; apply('closed'); });

  // 메모
  const memo = document.getElementById('cd-memo'), mSave = document.getElementById('cd-memo-save');
  const mErr = document.getElementById('cd-memo-err'), mSaved = document.getElementById('cd-memo-saved');
  let memoOrig = memo.value;
  memo.addEventListener('input', () => { mSave.disabled = memo.value === memoOrig; });
  mSave.addEventListener('click', async () => {
    mSave.disabled = true;
    const d = await call({ action: 'save_memo', memo: memo.value });
    if (d.ok) { memoOrig = memo.value; mSaved.textContent = '방금 저장됨'; show(mErr, ''); }
    else { show(mErr, d.error || '메모를 저장하지 못했습니다.'); mSave.disabled = false; }
  });

  const chat = document.getElementById('cd-chat');
  if (chat) chat.scrollTop = 0;
})();
</script>
<?php render_admin_footer(); ?>
