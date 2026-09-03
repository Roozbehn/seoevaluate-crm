<?php
/**
 * In-memory stand-in for CodeIgniter's query builder.
 *
 * Only the operations these modules actually use are implemented. Anything
 * unrecognised throws, so a test can never pass because the fake quietly did
 * nothing — a silent no-op is the one failure mode that would make this
 * harness worse than no harness at all.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

class SeFakeResult
{
    private $rows;

    public function __construct(array $rows)
    {
        $this->rows = array_values($rows);
    }

    public function row()
    {
        return isset($this->rows[0]) ? (object) $this->rows[0] : null;
    }

    public function row_array()
    {
        return $this->rows[0] ?? null;
    }

    public function result_array()
    {
        return $this->rows;
    }

    public function result()
    {
        return array_map(function ($r) { return (object) $r; }, $this->rows);
    }

    public function num_rows()
    {
        return count($this->rows);
    }
}

class SeFakeDb
{
    /** @var array<string,array<int,array>> table => rows */
    public $tables = [];
    public $autoinc = [];
    public $affected = 0;
    public $lastInsertId = 0;
    public $queryLog = [];
    /** Raw SQL patterns handled by handleRaw(); anything else throws. */
    public $unhandled = [];

    private $sel = null;
    private $wheres = [];
    private $whereIn = [];
    private $orderBy = null;
    private $limitN = null;
    private $groupBy = null;
    /** Open group_start() frames: each is a list of [cond, isOr]. */
    private $groups = [];

    public function seed($table, array $rows)
    {
        $this->tables[$table] = [];
        foreach ($rows as $r) {
            $this->tables[$table][] = $r;
            if (isset($r['id'])) {
                $this->autoinc[$table] = max($this->autoinc[$table] ?? 0, (int) $r['id']);
            }
        }
    }

    public function rows($table)
    {
        return $this->tables[$table] ?? [];
    }

    /** CI_DB::table_exists() — a table exists once it has been seeded. */
    public function table_exists($table)
    {
        return array_key_exists($table, $this->tables);
    }

    /* ---------------- query builder ---------------- */

    public function select($cols)          { $this->sel = $cols; return $this; }
    public function order_by($c, $d = '')  { $this->orderBy = [$c, $d]; return $this; }
    public function limit($n, $o = null)   { $this->limitN = (int) $n; return $this; }
    public function group_by($c)           { $this->groupBy = $c; return $this; }
    public function join($t, $c, $type='') { return $this; }

    public function where($k, $v = null, $escape = true)
    {
        if (is_array($k)) {
            foreach ($k as $kk => $vv) { $this->addWhere([$kk, $vv], false); }
        } else {
            $this->addWhere([$k, $v], false);
        }
        return $this;
    }

    /** or_where() is only meaningful inside a group_start()/group_end() frame. */
    public function or_where($k, $v = null, $escape = true)
    {
        $this->addWhere([$k, $v], true);
        return $this;
    }

    /** Parenthesised predicate group: (a AND b OR c). Evaluated left to right. */
    public function group_start()  { $this->groups[] = []; return $this; }
    public function group_end()
    {
        $frame = array_pop($this->groups);
        $this->addWhere(['__group__', $frame ?? []], false);
        return $this;
    }

    private function addWhere(array $cond, $isOr)
    {
        if ($this->groups) {
            $this->groups[count($this->groups) - 1][] = [$cond, $isOr];
        } else {
            $this->wheres[] = $cond;
        }
    }

    public function where_in($k, array $vals)
    {
        $this->whereIn[] = [$k, array_map('strval', $vals)];
        return $this;
    }

    /** CI_DB::where_not_in(): the complement of where_in, same column handling. */
    public function where_not_in($k, array $vals)
    {
        $this->whereIn[] = [$k, array_map('strval', $vals), true];
        return $this;
    }

    public function escape($v)
    {
        if ($v === null) { return 'NULL'; }
        if (is_int($v) || is_float($v)) { return (string) $v; }
        return "'" . str_replace("'", "''", (string) $v) . "'";
    }

    private function reset()
    {
        $this->sel = null; $this->wheres = []; $this->whereIn = []; $this->groups = [];
        $this->orderBy = null; $this->limitN = null; $this->groupBy = null;
    }

    /** CI_DB_query_builder::reset_query() — drops the in-flight builder state. */
    public function reset_query() { $this->reset(); return $this; }

    /**
     * Evaluate the accumulated predicates against one row.
     * Supports `col`, `col >=`, `col <`, `col !=` and a raw
     * "col IN (1,2)" string produced by se_brand_predicate().
     */
    private function matches(array $row)
    {
        foreach ($this->wheres as $w) {
            if (!$this->condMatches($row, $w)) { return false; }
        }

        foreach ($this->whereIn as $wi) {
            [$k, $vals] = $wi;
            $not = !empty($wi[2]);
            $col = trim($k, '` ');
            $in  = in_array((string) ($row[$col] ?? ''), $vals, true);
            if ($not ? $in : !$in) { return false; }
        }

        return true;
    }

    /** One where() condition (or a whole group) against a row. */
    private function condMatches(array $row, array $w)
    {
        [$k, $v] = $w;
        if ($k === '__group__') {
            $result = null;
            foreach ((array) $v as [$cond, $isOr]) {
                $c = $this->condMatches($row, $cond);
                if ($result === null) { $result = $c; }
                elseif ($isOr) { $result = $result || $c; }
                else { $result = $result && $c; }
            }
            return $result === null ? true : $result;
        }
        // Raw NULL tests and NOT IN, as emitted by the appointment/journey helpers.
        if ($v === null && is_string($k) && preg_match('/^\s*`?([A-Za-z0-9_.]+)`?\s+IS\s+(NOT\s+)?NULL\s*$/i', $k, $m)) {
            $col = strpos($m[1], '.') !== false ? substr($m[1], strrpos($m[1], '.') + 1) : $m[1];
            $isNull = !isset($row[$col]) || $row[$col] === null || $row[$col] === '';
            return empty($m[2]) ? $isNull : !$isNull;
        }
        if ($v === null && is_string($k) && preg_match('/^\s*`?([A-Za-z0-9_.]+)`?\s+NOT\s+IN\s*\(([^)]*)\)\s*$/i', $k, $m)) {
            $col = strpos($m[1], '.') !== false ? substr($m[1], strrpos($m[1], '.') + 1) : $m[1];
            $list = array_map(function ($x) { return trim(trim($x), "'\""); }, explode(',', $m[2]));
            return !in_array((string) ($row[$col] ?? ''), $list, true);
        }
        return $this->simpleMatches($row, $k, $v);
    }

    /** The original single-condition matcher. */
    private function simpleMatches(array $row, $k, $v)
    {
        foreach ([[$k, $v]] as [$k, $v]) {
            if ($v === null && is_string($k) && preg_match('/^\s*`?([A-Za-z0-9_]+)`?\s+IN\s*\(([^)]*)\)\s*$/i', $k, $m)) {
                $col  = $m[1];
                $list = array_map('trim', explode(',', $m[2]));
                if (!in_array((string) ($row[$col] ?? ''), $list, true)) { return false; }
                continue;
            }
            if ($v === null && is_string($k) && trim($k) === '1=0') { return false; }

            // OR-of-(col = 'x' AND col = 'y') pair predicate, as produced by
            // se_meta_form_pair_predicate(): ((a = 'v' AND b = 'w') OR (...)).
            if ($v === null && is_string($k) && preg_match('/^\(\s*\(.+\)\s*\)$/s', trim($k))
                && stripos($k, ' AND ') !== false) {
                if (!$this->orPairMatches($row, trim($k))) { return false; }
                continue;
            }

            $col = $k; $op = '=';
            if (preg_match('/^(.*?)\s*(>=|<=|!=|<>|>|<)$/', trim($k), $m)) {
                $col = trim($m[1]); $op = $m[2];
            }
            $col = trim($col, '` ');
            // strip a "table." qualifier
            if (strpos($col, '.') !== false) { $col = substr($col, strrpos($col, '.') + 1); }

            $actual = $row[$col] ?? null;

            switch ($op) {
                case '=':  if ((string) $actual !== (string) $v) { return false; } break;
                case '!=':
                case '<>': if ((string) $actual === (string) $v) { return false; } break;
                case '>':  if (!($actual > $v))  { return false; } break;
                case '<':  if (!($actual < $v))  { return false; } break;
                case '>=': if (!($actual >= $v)) { return false; } break;
                case '<=': if (!($actual <= $v)) { return false; } break;
            }
        }

        return true;
    }

    /**
     * Evaluate ((colA = 'v1' AND colB = 'v2') OR (colA = 'v3' AND ...)).
     * Only equality over quoted/plain scalars — exactly the shape
     * se_meta_form_pair_predicate() emits. Anything else throws, so the fake
     * can never silently mis-evaluate a predicate.
     */
    private function orPairMatches(array $row, $predicate)
    {
        $inner = trim(substr(trim($predicate), 1, -1));   // strip the outer ( )

        foreach (preg_split('/\)\s+OR\s+\(/i', trim($inner, '() ')) as $group) {
            $groupOk = true;

            foreach (preg_split('/\s+AND\s+/i', $group) as $cond) {
                if (!preg_match("/^\s*`?([A-Za-z0-9_.]+)`?\s*=\s*(?:'((?:[^']|'')*)'|([A-Za-z0-9_.\-]+))\s*$/", $cond, $m)) {
                    throw new RuntimeException('SeFakeDb: unhandled OR-pair fragment: ' . $cond);
                }
                $col = $m[1];
                if (strpos($col, '.') !== false) { $col = substr($col, strrpos($col, '.') + 1); }
                $val = isset($m[3]) && $m[3] !== '' ? $m[3] : str_replace("''", "'", $m[2] ?? '');
                if ((string) ($row[$col] ?? '') !== (string) $val) { $groupOk = false; break; }
            }

            if ($groupOk) { return true; }
        }

        return false;
    }

    private function select_rows($table)
    {
        $out = [];
        foreach ($this->rows($table) as $row) {
            if ($this->matches($row)) { $out[] = $row; }
        }

        if ($this->orderBy) {
            [$c, $d] = $this->orderBy;
            $c = trim(explode(' ', trim($c))[0], '` ');
            usort($out, function ($a, $b) use ($c, $d) {
                $x = $a[$c] ?? null; $y = $b[$c] ?? null;
                $cmp = is_numeric($x) && is_numeric($y) ? ($x <=> $y) : strcmp((string) $x, (string) $y);
                return strtoupper($d) === 'DESC' ? -$cmp : $cmp;
            });
        }

        if ($this->limitN !== null) { $out = array_slice($out, 0, $this->limitN); }

        return $out;
    }

    public function get($table)
    {
        $rows = $this->select_rows($table);

        // COUNT(*) aggregation used by se_outbox_health()
        if ($this->sel && stripos($this->sel, 'COUNT(*)') !== false && $this->groupBy) {
            $g = trim($this->groupBy, '` ');
            $buckets = [];
            foreach ($rows as $r) { $buckets[$r[$g] ?? ''] = ($buckets[$r[$g] ?? ''] ?? 0) + 1; }
            $agg = [];
            foreach ($buckets as $k => $c) { $agg[] = [$g => $k, 'c' => $c]; }
            $rows = $agg;
        }

        $this->reset();

        return new SeFakeResult($rows);
    }

    public function count_all_results($table, $reset = true)
    {
        $n = count($this->select_rows($table));
        if ($reset) { $this->reset(); }

        return $n;
    }

    public function insert($table, array $data)
    {
        $this->autoinc[$table] = ($this->autoinc[$table] ?? 0) + 1;
        if (!isset($data['id'])) { $data['id'] = $this->autoinc[$table]; }
        $this->tables[$table][] = $data;
        $this->lastInsertId = (int) $data['id'];
        $this->affected = 1;
        $this->reset();

        return true;
    }

    public function insert_id() { return $this->lastInsertId; }
    public function affected_rows() { return $this->affected; }

    public function update($table, array $data)
    {
        $n = 0;
        foreach ($this->tables[$table] ?? [] as $i => $row) {
            if ($this->matches($row)) {
                $this->tables[$table][$i] = array_merge($row, $data);
                $n++;
            }
        }
        $this->affected = $n;
        $this->reset();

        return true;
    }

    public function delete($table)
    {
        $n = 0; $kept = [];
        foreach ($this->tables[$table] ?? [] as $row) {
            if ($this->matches($row)) { $n++; } else { $kept[] = $row; }
        }
        $this->tables[$table] = $kept;
        $this->affected = $n;
        $this->reset();

        return true;
    }

    /* ---------------- raw SQL ---------------- */

    /**
     * Raw query. Deliberately does NOT reset the accumulated predicates,
     * because CodeIgniter 3's DB_driver::query() does not either — modelling it
     * otherwise would hide the class of ordering bug where a helper runs its
     * own query in the middle of somebody else's query build.
     */
    public function query($sql)
    {
        $this->queryLog[] = $sql;

        // Preserve any in-flight builder state around the raw call.
        $saved = [$this->sel, $this->wheres, $this->whereIn, $this->orderBy, $this->limitN, $this->groupBy];
        $this->reset();

        try {
            return $this->handleRaw(trim($sql));
        } finally {
            [$this->sel, $this->wheres, $this->whereIn, $this->orderBy, $this->limitN, $this->groupBy] = $saved;
        }
    }

    private function handleRaw($sql)
    {
        // DDL / locks: accepted as no-ops.
        if (preg_match('/^(ALTER|CREATE|DROP|INSERT INTO .* SELECT)/i', $sql)) {
            return new SeFakeResult([]);
        }
        // SELECT NOW() — se_db_now() asks the server for its clock. The fake
        // has no server, so it answers with PHP time; the REAL clock-offset
        // behaviour is asserted in the real-MariaDB tier, which is the only
        // place it can be.
        if (preg_match('/^SELECT\s+NOW\(\)\s+AS\s+n$/i', $sql)) {
            return new SeFakeResult([['n' => date('Y-m-d H:i:s')]]);
        }

        if (preg_match('/GET_LOCK\(/i', $sql))     { return new SeFakeResult([['l' => 1]]); }
        if (preg_match('/RELEASE_LOCK\(/i', $sql)) { return new SeFakeResult([['l' => 1]]); }

        // SELECT brand_id FROM <tbl>se_staff_brands WHERE staff_id = N
        if (preg_match('/^SELECT\s+brand_id\s+FROM\s+(\S+)\s+WHERE\s+staff_id\s*=\s*(\d+)/i', $sql, $m)) {
            $out = [];
            foreach ($this->rows($m[1]) as $r) {
                if ((int) $r['staff_id'] === (int) $m[2]) { $out[] = ['brand_id' => $r['brand_id']]; }
            }
            return new SeFakeResult($out);
        }

        // SELECT `col` AS brand_id FROM `tbl` WHERE `pk` = N LIMIT 1
        if (preg_match('/^SELECT\s+`([A-Za-z0-9_]+)`\s+AS\s+brand_id\s+FROM\s+`([A-Za-z0-9_]+)`\s+WHERE\s+`([A-Za-z0-9_]+)`\s*=\s*(\d+)/i', $sql, $m)) {
            [$all, $col, $table, $pk, $id] = $m;
            foreach ($this->rows($table) as $r) {
                if ((string) ($r[$pk] ?? null) === (string) $id) {
                    return new SeFakeResult([['brand_id' => $r[$col] ?? null]]);
                }
            }
            return new SeFakeResult([]);
        }

        // SELECT COUNT(*) AS c FROM `tbl` WHERE <simple ANDs>
        if (preg_match('/^SELECT\s+COUNT\(\*\)\s+AS\s+c\s+FROM\s+`?([A-Za-z0-9_]+)`?(?:\s+WHERE\s+(.*))?$/is', $sql, $m)) {
            $rows = $this->rows($m[1]);
            $n = 0;
            foreach ($rows as $r) {
                if (empty($m[2]) || $this->rawWhereMatches($r, $m[2])) { $n++; }
            }
            return new SeFakeResult([['c' => $n]]);
        }

        // SELECT <cols> FROM `tbl`  (no predicate) — used by the role migration
        if (preg_match('/^SELECT\s+(.+?)\s+FROM\s+`?([A-Za-z0-9_]+)`?\s*$/is', $sql, $m)) {
            return new SeFakeResult($this->rows($m[2]));
        }

        // SELECT <cols> FROM `tbl` WHERE <simple ANDs> [ORDER BY ...] [LIMIT n]
        if (preg_match('/^SELECT\s+(.+?)\s+FROM\s+`?([A-Za-z0-9_]+)`?\s+WHERE\s+(.+)$/is', $sql, $m)) {
            $where = $m[3];
            $limit = null;

            // Strip trailing clauses the predicate matcher must not see.
            if (preg_match('/^(.*?)\s+LIMIT\s+(\d+)\s*$/is', $where, $lm)) {
                $where = $lm[1];
                $limit = (int) $lm[2];
            }

            $where = preg_replace('/\s+ORDER\s+BY\s+.*$/is', '', $where);

            $out = [];
            foreach ($this->rows($m[2]) as $r) {
                if ($this->rawWhereMatches($r, $where)) { $out[] = $r; }
            }

            if ($limit !== null) { $out = array_slice($out, 0, $limit); }

            return new SeFakeResult($out);
        }

        // UPDATE `tbl` SET a=b[, ...] WHERE <ANDs> [ORDER BY ...] [LIMIT n]
        if (preg_match('/^UPDATE\s+`?([A-Za-z0-9_]+)`?\s+SET\s+(.*?)\s+WHERE\s+(.*?)(?:\s+ORDER BY\s+.*?)?(?:\s+LIMIT\s+(\d+))?$/is', $sql, $m)) {
            $table = $m[1];
            $sets  = $this->parseSet($m[2]);
            $limit = isset($m[4]) && $m[4] !== '' ? (int) $m[4] : PHP_INT_MAX;
            $n = 0;
            foreach ($this->tables[$table] ?? [] as $i => $r) {
                if ($n >= $limit) { break; }
                if ($this->rawWhereMatches($r, $m[3])) {
                    $merged = $r;
                    foreach ($sets as $k => $v) {
                        if (strpos($k, '__incr__') === 0) {
                            $col = substr($k, 8);
                            $merged[$col] = (int) ($r[$col] ?? 0) + (int) $v;
                        } else {
                            $merged[$k] = $v;
                        }
                    }
                    $this->tables[$table][$i] = $merged;
                    $n++;
                }
            }
            $this->affected = $n;
            return new SeFakeResult([]);
        }

        $this->unhandled[] = $sql;
        throw new RuntimeException('SeFakeDb: unhandled SQL: ' . substr($sql, 0, 160));
    }

    private function parseSet($setClause)
    {
        $out = [];
        foreach (preg_split('/,(?![^(]*\))/', $setClause) as $pair) {
            if (!preg_match('/^\s*`?([A-Za-z0-9_]+)`?\s*=\s*(.+?)\s*$/s', $pair, $m)) { continue; }
            $v = trim($m[2]);
            if (strcasecmp($v, 'NOW()') === 0)      { $v = date('Y-m-d H:i:s'); }
            elseif (strcasecmp($v, 'NULL') === 0)   { $v = null; }
            elseif (preg_match("/^'(.*)'$/s", $v, $q)) { $v = str_replace("''", "'", $q[1]); }
            elseif (preg_match('/^`?([A-Za-z0-9_]+)`?\s*\+\s*(\d+)$/', $v, $inc)) {
                $out['__incr__' . $m[1]] = (int) $inc[2];
                continue;
            }
            $out[$m[1]] = $v;
        }
        return $out;
    }

    private function rawWhereMatches(array $row, $where)
    {
        // Split on AND, but not inside parentheses.
        foreach (preg_split('/\s+AND\s+(?![^(]*\))/i', $where) as $cond) {
            $cond = trim($cond);
            if ($cond === '' ) { continue; }

            // "(col IS NULL OR col <= 'value')" — the drain due-time predicate.
            if (preg_match('/^\(\s*`?([A-Za-z0-9_]+)`?\s+IS\s+NULL\s+OR\s+`?([A-Za-z0-9_]+)`?\s*(<=|<|>=|>)\s*\'([^\']*)\'\s*\)$/i', $cond, $m)) {
                $actual = $row[$m[1]] ?? null;
                if ($actual === null || $actual === '') { continue; }   // IS NULL branch
                $v = $m[4];
                switch ($m[3]) {
                    case '<=': if (!($actual <= $v)) { return false; } break;
                    case '<':  if (!($actual < $v))  { return false; } break;
                    case '>=': if (!($actual >= $v)) { return false; } break;
                    case '>':  if (!($actual > $v))  { return false; } break;
                }
                continue;
            }
            if (preg_match('/^`?([A-Za-z0-9_]+)`?\s*(=|<=|>=|!=|<>|<|>)\s*(.+)$/s', $cond, $m)) {
                $col = $m[1]; $op = $m[2]; $v = trim($m[3]);
                if (preg_match("/^'(.*)'$/s", $v, $q)) { $v = str_replace("''", "'", $q[1]); }
                $actual = $row[$col] ?? null;
                switch ($op) {
                    case '=':  if ((string) $actual !== (string) $v) { return false; } break;
                    case '!=':
                    case '<>': if ((string) $actual === (string) $v) { return false; } break;
                    case '<':  if (!($actual < $v))  { return false; } break;
                    case '>':  if (!($actual > $v))  { return false; } break;
                    case '<=': if (!($actual <= $v)) { return false; } break;
                    case '>=': if (!($actual >= $v)) { return false; } break;
                }
                continue;
            }
            if (preg_match('/^`?([A-Za-z0-9_]+)`?\s+IN\s*\(([^)]*)\)$/i', $cond, $m)) {
                $list = array_map(function ($x) { return trim(trim($x), "'"); }, explode(',', $m[2]));
                if (!in_array((string) ($row[$m[1]] ?? ''), $list, true)) { return false; }
                continue;
            }
            throw new RuntimeException('SeFakeDb: unhandled WHERE fragment: ' . $cond);
        }
        return true;
    }
}
