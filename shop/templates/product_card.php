<div class="product-card">
    <a href="./product.php?id=<?php echo $product['id']; ?>">
        <div class="product-image">
            <img src="../../app/assets/<?php echo htmlspecialchars($product['image_url'] ?? 'img/main.svg'); ?>" 
                 alt="<?php echo htmlspecialchars($product['product_name']); ?>">
        </div>
        <div class="product-info">
            <div class="vendor-name"><?php echo htmlspecialchars($product['company_name']); ?></div>
            <h3 class="product-title"><?php echo htmlspecialchars($product['product_name']); ?></h3>
            <div class="product-price"><?php echo $product['price_range']; ?></div>
            <?php if (!empty($product['description'])): ?>
                <p class="product-description"><?php echo Formatter::truncate($product['description'], 80); ?></p>
            <?php endif; ?>
        </div>
    </a>
    <button class="quick-add" data-product-id="<?php echo $product['id']; ?>">Quick Add</button>
</div>