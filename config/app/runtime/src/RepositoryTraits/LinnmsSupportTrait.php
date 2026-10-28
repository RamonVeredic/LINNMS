<?php

declare(strict_types=1);

trait LinnmsSupportTrait
{
    private function executeInventoryWrite(PDOStatement $statement, array $params = []): bool
    {
        $sql = trim($statement->queryString);
        if (strncasecmp($sql, 'INSERT INTO ', 12) !== 0) return $params === [] ? $statement->execute() : $statement->execute($params);
        $keys = [
            'pons' => ['user_id', 'olt_device_id', 'port_ref'],
            'pon_modules' => ['pon_id'],
            'onus' => ['user_id', 'olt_device_id', 'onu_index'],
            'olt_cards' => ['user_id', 'olt_device_id', 'card_key'],
            'olt_profiles' => ['user_id', 'olt_device_id', 'kind', 'name'],
            'onu_service_configs' => ['user_id', 'olt_device_id', 'onu_index'],
            'uplink_port' => ['user_id', 'olt_device_id', 'port_ref'],
        ];
        if (!preg_match('/^INSERT INTO (\w+)\s*\(([^)]+)\)\s*VALUES\s*\((.*?)\)(?:\s*ON DUPLICATE KEY UPDATE\s*(.*))?$/is', $sql, $match)
            || !isset($keys[$match[1]])) return $params === [] ? $statement->execute() : $statement->execute($params);
        $table = $match[1];
        $columns = array_map('trim', explode(',', $match[2]));
        $values = array_map('trim', explode(',', $match[3]));
        if (count($columns) !== count($values)) throw new RuntimeException('Format penyimpanan inventaris tidak valid.');
        $mapping = array_combine($columns, $values);
        $where = [];
        $identity = [];
        foreach ($keys[$table] as $index => $column) {
            $value = $mapping[$column] ?? '';
            if (!preg_match('/^:(\w+)$/', $value, $binding)) throw new RuntimeException('Identitas inventaris tidak valid.');
            $key = 'inventory_key_' . $index;
            $where[] = $column . '=:' . $key;
            $identity[$key] = $params[$binding[1]] ?? null;
        }
        $find = $this->pdo->prepare('SELECT id FROM ' . $table . ' WHERE ' . implode(' AND ', $where) . ' LIMIT 1');
        $find->execute($identity);
        $id = $find->fetchColumn();
        if ($id === false) return $params === [] ? $statement->execute() : $statement->execute($params);
        $updates = trim((string)($match[4] ?? ''));
        if ($updates === '') {
            $assignments = [];
            foreach ($mapping as $column => $value) {
                if (!in_array($column, $keys[$table], true)) $assignments[] = $column . '=' . $value;
            }
            $updates = implode(',', $assignments);
        } else {
            $updates = preg_replace_callback('/VALUES\((\w+)\)/i', static fn(array $field): string => $mapping[$field[1]] ?? throw new RuntimeException('Kolom inventaris tidak valid.'), $updates);
        }
        preg_match_all('/:(\w+)/', $updates, $bindings);
        $updateParams = array_intersect_key($params, array_fill_keys($bindings[1], true));
        $updateParams['inventory_row_id'] = (int)$id;
        $ok = $this->pdo->prepare('UPDATE ' . $table . ' SET ' . $updates . ' WHERE id=:inventory_row_id')->execute($updateParams);
        return $ok;
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function databaseName(): string
    {
        return $this->databaseName;
    }

    private function positiveInt(mixed $value): ?int
    {
        if ($value === null || $value === '' || !is_numeric($value)) return null;
        $v = (int) $value;
        return $v > 0 ? $v : null;
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value === null || $value === '' || !is_numeric($value) ? null : (int) $value;
    }

    private function nullableFloat(mixed $value): ?float
    {
        return $value === null || $value === '' || !is_numeric($value) ? null : (float) $value;
    }

    private function powerNumber(mixed $value): ?float
    {
        if ($value === null || $value === '') return null;
        if (is_numeric($value)) return (float) $value;
        return preg_match('/-?\d+(?:\.\d+)?/', (string) $value, $match) === 1
            ? (float) $match[0]
            : null;
    }

    private function nullText(mixed $value): ?string
    {
        if ($value === null || !is_scalar($value)) return null;
        $text = trim((string) $value);
        return $text === '' ? null : $text;
    }

    private function different(mixed $a, mixed $b): bool
    {
        if (($a === null || $a === '') && ($b === null || $b === '')) return false;
        if (is_numeric($a) && is_numeric($b)) return abs((float)$a - (float)$b) > 0.000001;
        return trim((string)$a) !== trim((string)$b);
    }

}
