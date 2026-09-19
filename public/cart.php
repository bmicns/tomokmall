<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/layout.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $productId = max(0, (int) ($_POST['product_id'] ?? 0));
    if ($productId > 0) {
        $_SESSION['cart'][$productId] = ($_SESSION['cart'][$productId] ?? 0) + 1;
    }
    redirect('/cart.php');
}

if (isset($_GET['clear'])) {
    unset($_SESSION['cart']);
    redirect('/cart.php');
}

$items = cart_items();
$products = [];
$total = 0;

if ($items) {
    $ids = array_keys($items);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare("SELECT * FROM products WHERE id IN ({$placeholders})");
    $stmt->execute($ids);
    $products = $stmt->fetchAll();
}

render_header('장바구니');
?>
<section class="section narrow">
    <div class="section-title">
        <h1>장바구니</h1>
        <?php if ($items): ?><a href="/cart.php?clear=1">비우기</a><?php endif; ?>
    </div>
    <?php if (!$products): ?>
        <p class="empty">담긴 상품이 없습니다.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>상품</th>
                        <th>수량</th>
                        <th>단가</th>
                        <th>금액</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($products as $product): ?>
                        <?php
                        $qty = (int) ($items[$product['id']] ?? 0);
                        $line = $qty * (int) $product['price_b2c'];
                        $total += $line;
                        ?>
                        <tr>
                            <td><?= e($product['name']) ?></td>
                            <td><?= $qty ?></td>
                            <td><?= money((int) $product['price_b2c']) ?></td>
                            <td><?= money($line) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <th colspan="3">합계</th>
                        <th><?= money($total) ?></th>
                    </tr>
                </tfoot>
            </table>
        </div>
        <div class="actions">
            <a class="button secondary" href="/index.php">계속 쇼핑</a>
            <a class="button" href="/estimate.php">견적 요청</a>
        </div>
    <?php endif; ?>
</section>
<?php render_footer(); ?>

