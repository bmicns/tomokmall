ALTER TABLE products
    ADD COLUMN IF NOT EXISTS external_source VARCHAR(40) NULL AFTER stock_qty,
    ADD COLUMN IF NOT EXISTS external_id VARCHAR(80) NULL AFTER external_source,
    ADD COLUMN IF NOT EXISTS source_url VARCHAR(255) NULL AFTER external_id,
    ADD COLUMN IF NOT EXISTS image_url VARCHAR(255) NULL AFTER source_url,
    ADD UNIQUE KEY IF NOT EXISTS uq_products_external (external_source, external_id);

