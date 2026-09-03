<?php
declare(strict_types=1);

function ensure_automatic_expense_tables(): void
{
    $migration = file_get_contents(__DIR__ . '/../database/migrations/003_automatic_expenses.sql');
    if ($migration !== false) db()->exec($migration);
}

function automatic_rule_label(string $type): string
{
    return match ($type) {
        'per_shift' => 'Фиксированно за смену',
        'monthly_fixed' => 'Фиксированно в месяц',
        'percent_revenue' => '% от всей выручки',
        'percent_card_revenue' => '% от безналичной выручки',
        default => $type,
    };
}

function automatic_expense_shifts(string $from, string $to): array
{
    $pdo = db();

    // Основной источник смен — документы Эвотор. Если их ещё нет, считаем один рабочий день одной сменой.
    try {
        $stmt = $pdo->prepare("SELECT DATE(close_date) work_date,
                COALESCE(NULLIF(session_id,''), CONCAT('session-', session_number)) shift_key
            FROM evotor_documents
            WHERE document_type IN ('SELL','PAYBACK')
              AND close_date >= ? AND close_date < DATE_ADD(?, INTERVAL 1 DAY)
              AND (session_id IS NOT NULL OR session_number IS NOT NULL)
            GROUP BY DATE(close_date), COALESCE(NULLIF(session_id,''), CONCAT('session-', session_number))
            ORDER BY work_date");
        $stmt->execute([$from . ' 00:00:00', $to]);
        $rows = $stmt->fetchAll();
        if ($rows) return $rows;
    } catch (Throwable $e) {
        // Интеграция Эвотор может быть ещё не установлена — используем fallback ниже.
    }

    $stmt = $pdo->prepare("SELECT DATE(sold_at) work_date, CONCAT('day-', DATE(sold_at)) shift_key
        FROM sales
        WHERE sold_at >= ? AND sold_at < DATE_ADD(?, INTERVAL 1 DAY)
        GROUP BY DATE(sold_at)
        ORDER BY work_date");
    $stmt->execute([$from . ' 00:00:00', $to]);
    return $stmt->fetchAll();
}

function refresh_automatic_expenses(string $from, string $to): int
{
    ensure_automatic_expense_tables();

    $today = date('Y-m-d');
    if ($from > $today) return 0;
    if ($to > $today) $to = $today;

    $pdo = db();
    $rules = $pdo->prepare("SELECT * FROM automatic_expense_rules
        WHERE enabled=1 AND starts_on <= ? AND (ends_on IS NULL OR ends_on >= ?)
        ORDER BY id");
    $rules->execute([$to, $from]);
    $rules = $rules->fetchAll();
    if (!$rules) return 0;

    $shifts = automatic_expense_shifts($from, $to);
    $shiftsByDate = [];
    foreach ($shifts as $shift) {
        $shiftsByDate[$shift['work_date']][] = (string)$shift['shift_key'];
    }

    $delete = $pdo->prepare('DELETE FROM automatic_expense_accruals WHERE rule_id=? AND accrual_date BETWEEN ? AND ?');
    $insert = $pdo->prepare('INSERT INTO automatic_expense_accruals(rule_id,accrual_date,shift_key,amount,basis_amount,basis_description) VALUES(?,?,?,?,?,?)');
    $sales = $pdo->prepare("SELECT
        COALESCE(SUM(total_amount),0) revenue,
        COALESCE(SUM(CASE WHEN payment_method='card' THEN total_amount ELSE 0 END),0) card_revenue
        FROM sales WHERE sold_at >= ? AND sold_at < DATE_ADD(?, INTERVAL 1 DAY)");

    $processed = 0;
    foreach ($rules as $rule) {
        $delete->execute([(int)$rule['id'], $from, $to]);

        $start = max($from, (string)$rule['starts_on']);
        $end = $to;
        if (!empty($rule['ends_on']) && $rule['ends_on'] < $end) $end = $rule['ends_on'];
        if ($start > $end) continue;

        $date = new DateTimeImmutable($start);
        $last = new DateTimeImmutable($end);
        while ($date <= $last) {
            $day = $date->format('Y-m-d');
            $type = (string)$rule['rule_type'];
            $value = (float)$rule['amount'];

            if ($type === 'per_shift') {
                foreach ($shiftsByDate[$day] ?? [] as $shiftKey) {
                    $insert->execute([(int)$rule['id'], $day, $shiftKey, round($value, 2), null, '1 смена']);
                    $processed++;
                }
            } elseif ($type === 'monthly_fixed') {
                $daysInMonth = (int)$date->format('t');
                $daily = $daysInMonth > 0 ? $value / $daysInMonth : 0;
                $insert->execute([(int)$rule['id'], $day, '', round($daily, 2), $value, 'Распределение ' . number_format($value, 2, '.', '') . ' / ' . $daysInMonth . ' дней']);
                $processed++;
            } elseif ($type === 'percent_revenue' || $type === 'percent_card_revenue') {
                $sales->execute([$day . ' 00:00:00', $day]);
                $basis = $sales->fetch();
                $basisAmount = $type === 'percent_card_revenue' ? (float)$basis['card_revenue'] : (float)$basis['revenue'];
                $amount = $basisAmount * $value / 100;
                $description = ($type === 'percent_card_revenue' ? 'Безналичная выручка' : 'Выручка') . ' × ' . $value . '%';
                $insert->execute([(int)$rule['id'], $day, '', round($amount, 2), round($basisAmount, 2), $description]);
                $processed++;
            }

            $date = $date->modify('+1 day');
        }
    }

    return $processed;
}

function automatic_expenses_total(string $from, string $to): float
{
    ensure_automatic_expense_tables();
    refresh_automatic_expenses($from, $to);
    $stmt = db()->prepare('SELECT COALESCE(SUM(amount),0) FROM automatic_expense_accruals WHERE accrual_date BETWEEN ? AND ?');
    $stmt->execute([$from, $to]);
    return (float)$stmt->fetchColumn();
}
