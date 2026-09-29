UPDATE app_settings
SET setting_value='Каждое вращение заканчивается подарком'
WHERE setting_key='customer_wheel_subtitle'
  AND setting_value='Заверши заказ — забери подарок';

INSERT INTO customer_wheel_prizes(title,subtitle,prize_type,value,cap_value,weight,daily_limit,validity_days,icon,accent,active,sort_order)
SELECT '30 бонусов','Небольшой приятный бонус уже на балансе','points',30,0,16,0,30,'bean','#D69A3A',1,15
WHERE NOT EXISTS (SELECT 1 FROM customer_wheel_prizes WHERE title='30 бонусов');

INSERT INTO customer_wheel_prizes(title,subtitle,prize_type,value,cap_value,weight,daily_limit,validity_days,icon,accent,active,sort_order)
SELECT '75 бонусов','Заметный бонус на следующий кофе','points',75,0,8,0,30,'sparkle','#B96C4A',1,35
WHERE NOT EXISTS (SELECT 1 FROM customer_wheel_prizes WHERE title='75 бонусов');

INSERT INTO customer_wheel_prizes(title,subtitle,prize_type,value,cap_value,weight,daily_limit,validity_days,icon,accent,active,sort_order)
SELECT '+2 к прогрессу','Сразу два шага к напитку в подарок','stamp',2,0,5,0,30,'stamp','#6D7461',1,45
WHERE NOT EXISTS (SELECT 1 FROM customer_wheel_prizes WHERE title='+2 к прогрессу');

INSERT INTO customer_wheel_prizes(title,subtitle,prize_type,value,cap_value,weight,daily_limit,validity_days,icon,accent,active,sort_order)
SELECT '−15% на напиток','Усиленная скидка на один подходящий напиток','discount_percent',15,120,4,5,14,'discount','#8A4A4B',1,55
WHERE NOT EXISTS (SELECT 1 FROM customer_wheel_prizes WHERE title='−15% на напиток');
