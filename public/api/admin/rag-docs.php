<?php
declare(strict_types=1);
/**
 * 관리자 RAG 문서 API
 * POST { action:'save', id?, title, content, category_key, tags, is_active, sort_order }
 * POST { action:'delete', id }
 * POST { action:'toggle_active', id }
 */

require __DIR__ . '/../../../app/bootstrap.php';
require __DIR__ . '/../../../app/auth.php';
require_admin();

header('Content-Type: application/json; charset=utf-8');

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $body['action'] ?? '';

// ── 저장 (신규 + 수정) ───────────────────────────────────────────
if ($action === 'save') {
    $id         = (int) ($body['id'] ?? 0);
    $title      = trim(mb_substr($body['title']        ?? '', 0, 200));
    $content    = trim(mb_substr($body['content']      ?? '', 0, 10000));
    $catKey     = preg_replace('/[^\w]/', '', $body['category_key'] ?? '');
    $tags       = trim(mb_substr($body['tags']         ?? '', 0, 500));
    $isActive   = (int) ($body['is_active']  ?? 1);
    $sortOrder  = (int) ($body['sort_order'] ?? 0);

    if ($title === '' || $content === '') {
        echo json_encode(['ok' => false, 'error' => '제목과 본문은 필수입니다.']);
        exit;
    }

    if ($id > 0) {
        $stmt = db()->prepare(
            'UPDATE rag_documents
             SET title=:title, content=:content, category_key=:cat,
                 tags=:tags, is_active=:active, sort_order=:sort, updated_at=NOW()
             WHERE id=:id'
        );
        $stmt->execute([
            'title' => $title, 'content' => $content,
            'cat'   => $catKey ?: null, 'tags' => $tags ?: null,
            'active'=> $isActive, 'sort' => $sortOrder, 'id' => $id,
        ]);
        echo json_encode(['ok' => true, 'id' => $id]);
    } else {
        $stmt = db()->prepare(
            'INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order)
             VALUES (:title, :content, :cat, :tags, :active, :sort)'
        );
        $stmt->execute([
            'title' => $title, 'content' => $content,
            'cat'   => $catKey ?: null, 'tags' => $tags ?: null,
            'active'=> $isActive, 'sort' => $sortOrder,
        ]);
        echo json_encode(['ok' => true, 'id' => (int) db()->lastInsertId()]);
    }
    exit;
}

// ── 활성/비활성 토글 ─────────────────────────────────────────────
if ($action === 'toggle_active') {
    $id = (int) ($body['id'] ?? 0);
    if ($id <= 0) { echo json_encode(['ok' => false, 'error' => '잘못된 ID']); exit; }
    $stmt = db()->prepare('UPDATE rag_documents SET is_active = 1 - is_active WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $val = (int) db()->query("SELECT is_active FROM rag_documents WHERE id = {$id}")->fetchColumn();
    echo json_encode(['ok' => true, 'is_active' => $val]);
    exit;
}

// ── 삭제 ─────────────────────────────────────────────────────────
if ($action === 'delete') {
    $id = (int) ($body['id'] ?? 0);
    if ($id <= 0) { echo json_encode(['ok' => false, 'error' => '잘못된 ID']); exit; }
    db()->prepare('DELETE FROM rag_documents WHERE id = :id')->execute(['id' => $id]);
    echo json_encode(['ok' => true]);
    exit;
}

// ── 단건 조회 (편집 모달용) ──────────────────────────────────────
if ($action === 'get') {
    $id   = (int) ($body['id'] ?? 0);
    $stmt = db()->prepare('SELECT * FROM rag_documents WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $row  = $stmt->fetch();
    echo json_encode(['ok' => (bool)$row, 'doc' => $row ?: null]);
    exit;
}

echo json_encode(['ok' => false, 'error' => '알 수 없는 action']);
