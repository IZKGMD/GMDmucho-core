<?php

declare (strict_types=1);
namespace MuchoCore\Community;

final class PublicVisibility
{
    public static function where(string $table, array $columns, string $alias = ''): string
    {
        if ($alias !== '' && preg_match('/^[a-z][a-z0-9_]*$/D', $alias) !== 1) {
            throw new \InvalidArgumentException('Invalid public table alias.');
        }
        $rules = (function () use (&$table) {
            $__mcMatch1 = $table;
            if ($__mcMatch1 === 'accounts') {
                return ['is_active' => 1, 'is_banned' => 0];
            }
            if ($__mcMatch1 === 'levels') {
                return ['is_deleted' => 0, 'is_unlisted' => 0, 'unlisted2' => 0];
            }
            return [];
        })();
        $conditions = [];
        foreach ($rules as $name => $value) {
            if (in_array($name, $columns, true)) {
                $conditions[] = ($alias !== '' ? $alias . '.' : '') . "`{$name}`={$value}";
            }
        }
        return $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';
    }
}
