ALTER TABLE customer_product_settings
    ADD COLUMN display_name VARCHAR(255) DEFAULT NULL AFTER product_id;
