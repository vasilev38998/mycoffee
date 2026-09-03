INSERT INTO ingredients(name,unit,purchase_price,purchase_quantity,stock_quantity,min_stock_quantity)
SELECT 'Мороженное ванильное','g',0,0,0,0 WHERE NOT EXISTS (SELECT 1 FROM ingredients WHERE name='Мороженное ванильное');
INSERT INTO ingredients(name,unit,purchase_price,purchase_quantity,stock_quantity,min_stock_quantity)
SELECT 'Мороженное клубничное','g',0,0,0,0 WHERE NOT EXISTS (SELECT 1 FROM ingredients WHERE name='Мороженное клубничное');
INSERT INTO ingredients(name,unit,purchase_price,purchase_quantity,stock_quantity,min_stock_quantity)
SELECT 'Мороженное шоколадное','g',0,0,0,0 WHERE NOT EXISTS (SELECT 1 FROM ingredients WHERE name='Мороженное шоколадное');
INSERT INTO ingredients(name,unit,purchase_price,purchase_quantity,stock_quantity,min_stock_quantity)
SELECT 'Сок апельсин','ml',0,0,0,0 WHERE NOT EXISTS (SELECT 1 FROM ingredients WHERE name='Сок апельсин');
INSERT INTO ingredients(name,unit,purchase_price,purchase_quantity,stock_quantity,min_stock_quantity)
SELECT 'Сок вишня','ml',0,0,0,0 WHERE NOT EXISTS (SELECT 1 FROM ingredients WHERE name='Сок вишня');
INSERT INTO ingredients(name,unit,purchase_price,purchase_quantity,stock_quantity,min_stock_quantity)
SELECT 'Сок яблоко','ml',0,0,0,0 WHERE NOT EXISTS (SELECT 1 FROM ingredients WHERE name='Сок яблоко');
INSERT INTO ingredients(name,unit,purchase_price,purchase_quantity,stock_quantity,min_stock_quantity)
SELECT 'Газированная вода','ml',0,0,0,0 WHERE NOT EXISTS (SELECT 1 FROM ingredients WHERE name='Газированная вода');
INSERT INTO ingredients(name,unit,purchase_price,purchase_quantity,stock_quantity,min_stock_quantity)
SELECT 'Тоник лимон','ml',0,0,0,0 WHERE NOT EXISTS (SELECT 1 FROM ingredients WHERE name='Тоник лимон');
INSERT INTO ingredients(name,unit,purchase_price,purchase_quantity,stock_quantity,min_stock_quantity)
SELECT 'Тоник классический','ml',0,0,0,0 WHERE NOT EXISTS (SELECT 1 FROM ingredients WHERE name='Тоник классический');
INSERT INTO ingredients(name,unit,purchase_price,purchase_quantity,stock_quantity,min_stock_quantity)
SELECT 'Стакан 0.5 прозрачный','pcs',0,0,0,0 WHERE NOT EXISTS (SELECT 1 FROM ingredients WHERE name='Стакан 0.5 прозрачный');
INSERT INTO ingredients(name,unit,purchase_price,purchase_quantity,stock_quantity,min_stock_quantity)
SELECT 'Стакан 0.6 прозрачный','pcs',0,0,0,0 WHERE NOT EXISTS (SELECT 1 FROM ingredients WHERE name='Стакан 0.6 прозрачный');
INSERT INTO ingredients(name,unit,purchase_price,purchase_quantity,stock_quantity,min_stock_quantity)
SELECT 'Крышка 90мм','pcs',0,0,0,0 WHERE NOT EXISTS (SELECT 1 FROM ingredients WHERE name='Крышка 90мм');
INSERT INTO ingredients(name,unit,purchase_price,purchase_quantity,stock_quantity,min_stock_quantity)
SELECT 'Крышка 80мм','pcs',0,0,0,0 WHERE NOT EXISTS (SELECT 1 FROM ingredients WHERE name='Крышка 80мм');
INSERT INTO ingredients(name,unit,purchase_price,purchase_quantity,stock_quantity,min_stock_quantity)
SELECT 'Крышка купольная','pcs',0,0,0,0 WHERE NOT EXISTS (SELECT 1 FROM ingredients WHERE name='Крышка купольная');
INSERT INTO ingredients(name,unit,purchase_price,purchase_quantity,stock_quantity,min_stock_quantity)
SELECT 'Кокосовое молоко','ml',0,0,0,0 WHERE NOT EXISTS (SELECT 1 FROM ingredients WHERE name='Кокосовое молоко');
INSERT INTO ingredients(name,unit,purchase_price,purchase_quantity,stock_quantity,min_stock_quantity)
SELECT 'Бананы','g',0,0,0,0 WHERE NOT EXISTS (SELECT 1 FROM ingredients WHERE name='Бананы');
INSERT INTO ingredients(name,unit,purchase_price,purchase_quantity,stock_quantity,min_stock_quantity)
SELECT 'Яблоки','g',0,0,0,0 WHERE NOT EXISTS (SELECT 1 FROM ingredients WHERE name='Яблоки');
INSERT INTO ingredients(name,unit,purchase_price,purchase_quantity,stock_quantity,min_stock_quantity)
SELECT 'Сливки взбитые','g',0,0,0,0 WHERE NOT EXISTS (SELECT 1 FROM ingredients WHERE name='Сливки взбитые');
INSERT INTO ingredients(name,unit,purchase_price,purchase_quantity,stock_quantity,min_stock_quantity)
SELECT 'Вода газ 0.5','pcs',0,0,0,0 WHERE NOT EXISTS (SELECT 1 FROM ingredients WHERE name='Вода газ 0.5');
INSERT INTO ingredients(name,unit,purchase_price,purchase_quantity,stock_quantity,min_stock_quantity)
SELECT 'Вода без газа 0.5','pcs',0,0,0,0 WHERE NOT EXISTS (SELECT 1 FROM ingredients WHERE name='Вода без газа 0.5');
INSERT INTO ingredients(name,unit,purchase_price,purchase_quantity,stock_quantity,min_stock_quantity)
SELECT 'Замороженная клубника','g',0,0,0,0 WHERE NOT EXISTS (SELECT 1 FROM ingredients WHERE name='Замороженная клубника');
INSERT INTO ingredients(name,unit,purchase_price,purchase_quantity,stock_quantity,min_stock_quantity)
SELECT 'Концентрат манго-маракуйя','ml',0,0,0,0 WHERE NOT EXISTS (SELECT 1 FROM ingredients WHERE name='Концентрат манго-маракуйя');
INSERT INTO ingredients(name,unit,purchase_price,purchase_quantity,stock_quantity,min_stock_quantity)
SELECT 'Концентрат малина','ml',0,0,0,0 WHERE NOT EXISTS (SELECT 1 FROM ingredients WHERE name='Концентрат малина');
INSERT INTO ingredients(name,unit,purchase_price,purchase_quantity,stock_quantity,min_stock_quantity)
SELECT 'Концентрат лимон-базилик','ml',0,0,0,0 WHERE NOT EXISTS (SELECT 1 FROM ingredients WHERE name='Концентрат лимон-базилик');
INSERT INTO ingredients(name,unit,purchase_price,purchase_quantity,stock_quantity,min_stock_quantity)
SELECT 'Концентрат клюква','ml',0,0,0,0 WHERE NOT EXISTS (SELECT 1 FROM ingredients WHERE name='Концентрат клюква');
INSERT INTO ingredients(name,unit,purchase_price,purchase_quantity,stock_quantity,min_stock_quantity)
SELECT 'Концентрат облепиха','ml',0,0,0,0 WHERE NOT EXISTS (SELECT 1 FROM ingredients WHERE name='Концентрат облепиха');
INSERT INTO ingredients(name,unit,purchase_price,purchase_quantity,stock_quantity,min_stock_quantity)
SELECT 'Манжетка для стакана','pcs',0,0,0,0 WHERE NOT EXISTS (SELECT 1 FROM ingredients WHERE name='Манжетка для стакана');

INSERT INTO system_meta(meta_key,meta_value) VALUES('schema_version','15')
ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value);
