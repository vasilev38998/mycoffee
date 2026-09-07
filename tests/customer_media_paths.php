<?php
declare(strict_types=1);
require dirname(__DIR__).'/inc/customer_media.php';

function media_ok(bool $condition,string $message): void{if(!$condition)throw new RuntimeException('ASSERT FAILED: '.$message);echo "OK: {$message}\n";}

media_ok(customer_media_filename('uploads/products/cap-123.webp')==='cap-123.webp','new relative product image path resolves');
media_ok(customer_media_filename('customer/uploads/products/cap-123.webp')==='cap-123.webp','legacy customer-prefixed product image path resolves');
media_ok(customer_media_filename('/customer/uploads/products/cap-123.webp')==='cap-123.webp','absolute-path product image resolves');
media_ok(customer_media_filename('https://kapouch.store/customer/uploads/products/cap-123.webp')==='cap-123.webp','legacy absolute main-domain product image resolves');
media_ok(customer_media_filename('https://app.kapouch.store/uploads/products/cap-123.webp')==='cap-123.webp','legacy app-domain product image resolves');
media_ok(customer_media_filename('https://kapouch.store/api/customer_product_image.php?f=cap-123.webp&v=42')==='cap-123.webp','stable API image URL resolves');
media_ok(customer_media_public_path('customer/uploads/products/cap-123.webp')==='uploads/products/cap-123.webp','legacy path normalizes to canonical stored form');
media_ok(customer_media_filename('uploads/products/../secret.php')===null,'path traversal is rejected');
media_ok(customer_media_filename('https://evil.example/image.webp')===null,'external images are not treated as local uploads');
media_ok(customer_media_filename('uploads/products/not-image.exe')===null,'non-image extension is rejected');

echo "CUSTOMER MEDIA PATHS PASSED\n";
