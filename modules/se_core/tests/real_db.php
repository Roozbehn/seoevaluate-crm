<?php
/**
 * CodeIgniter-query-builder-compatible adapter over a REAL MariaDB connection.
 *
 * WHY THIS EXISTS
 * ---------------
 * The fake-DB suite proves the module's LOGIC. It cannot prove what MariaDB
 * actually does: whether `IN ()` is really a syntax error, whether GET_LOCK
 * really returns 0 under contention, whether a UNIQUE index really rejects the
 * second inserter of a race, whether an UPDATE ... LIMIT really claims disjoint
 * rows across two connections. Those are database behaviours, and only a
 * database can answer them.
 *
 * This adapter implements the subset of $this->db that the SE models use, so
 * the REAL model classes run unmodified against a real server.
 *
 * SAFETY
 * ------
 * - Every suite runs inside a transaction that is ALWAYS rolled back.
 * - Fixtures use ids in a reserved high range (see SE_TEST_ID_BASE) and brand
 *   ids that do not exist in production data.
 * - Nothing is committed, and the runner asserts row counts are unchanged when
 *   it finishes.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

class SeRealResult
{
    private $rows;
    public function __construct(array $rows) { $this->rows = array_values($rows); }
    public function row() { return isset($this->rows[0]) ? (object) $this->rows[0] : null; }
    public function row_array() { return $this->rows[0] ?? null; }
    public function result_array() { return $this->rows; }
    public function result() { return array_map(function ($r) { return (object) $r; }, $this->rows); }
    public function num_rows() { return count($this->rows); }
}

class SeRealDb
{
    /** @var mysqli */
    public $conn;
    public $queries = [];
    public $lastError = '';

    private $sel = null;
    private $wheres = [];      // [sql fragment]
    private $orderBy = null;
    private $limitN = null;
    private $groupBy = null;
    private $joins = [];
    private $affected = 0;
    private $insertId = 0;

    public function __construct(mysqli $conn) { $this->conn = $conn; }

    /* ---------------- escaping ---------------- */

    public function escape($v)
    {
        if ($v === null) { return 'NULL'; }
        if (is_int($v) || is_float($v)) { return (string) $v; }
        if (is_bool($v)) { return $v ? '1' : '0'; }

        return "'" . $this->conn->real_escape_string((string) $v) . "'";
    }

    public function escape_str($v) { return $this->conn->real_escape_string((string) $v); }

    /* ---------------- builder ---------------- */

    public function select($cols) { $this->sel = $cols; return $this; }
    public function order_by($c, $d = '') { $this->orderBy = trim($c . ' ' . $d); return $this; }
    public function limit($n, $o = null) { $this->limitN = (int) $n; return $this; }
    public function group_by($c) { $this->groupBy = $c; return $this; }

    public function join($table, $cond, $type = '')
    {
        $this->joins[] = strtoupper($type) . ' JOIN ' . $table . ' ON ' . $cond;
        return $this;
    }

    public function where($k, $v = null, $escape = true)
    {
        if (is_array($k)) {
            foreach ($k as $kk => $vv) { $this->where($kk, $vv, $escape); }
            return $this;
        }

        // Raw predicate (CI convention: value null + escape false)
        if ($v === null && $escape === false) {
            $this->wheres[] = '(' . $k . ')';
            return $this;
        }

        if ($v === null && preg_match('/(IS NULL|IS NOT NULL|IN\s*\(|1=0|1=1)/i', (string) $k)) {
            $this->wheres[] = '(' . $k . ')';
            return $this;
        }

        $k = trim($k);
        $op = '=';

        if (preg_match('/^(.*?)\s*(>=|<=|!=|<>|>|<)$/', $k, $m)) {
            $k = trim($m[1]); $op = $m[2];
        }

        $this->wheres[] = $k . ' ' . $op . ' ' . $this->escape($v);

        return $this;
    }

    public function where_in($k, array $vals)
    {
        if (!$vals) { $this->wheres[] = '1=0'; return $this; }

        $esc = array_map([$this, 'escape'], $vals);
        $this->wheres[] = $k . ' IN (' . implode(',', $esc) . ')';

        return $this;
    }

    /* CI groups: ( ... OR ... ) */
    public function group_start()
    {
        $this->wheres[] = '__GROUP_START__';
        return $this;
    }

    public function or_group_start()
    {
        $this->wheres[] = '__OR_GROUP_START__';
        return $this;
    }

    public function group_end()
    {
        $this->wheres[] = '__GROUP_END__';
        return $this;
    }

    public function or_where($k, $v = null, $escape = true)
    {
        $before = count($this->wheres);
        $this->where($k, $v, $escape);

        // Mark the fragment just added as OR-joined.
        for ($i = $before; $i < count($this->wheres); $i++) {
            $this->wheres[$i] = '__OR__' . $this->wheres[$i];
        }

        return $this;
    }

    public function reset_query()
    {
        $this->reset();
        return $this;
    }

    public function table_exists($table)
    {
        $r = $this->query('SHOW TABLES LIKE ' . $this->escape($table));
        return $r->num_rows() > 0;
    }

    public function field_exists($field, $table)
    {
        $r = $this->query('SHOW COLUMNS FROM `' . $table . '` LIKE ' . $this->escape($field));
        return $r->num_rows() > 0;
    }

    public function list_fields($table)
    {
        $out = [];
        foreach ($this->query('SHOW COLUMNS FROM `' . $table . '`')->result_array() as $r) {
            $out[] = $r['Field'];
        }
        return $out;
    }

    /**
     * Assemble the WHERE clause, honouring CI's group and OR markers.
     * Fragments are AND-joined unless marked __OR__.
     */
    private function whereSql()
    {
        if (!$this->wheres) { return ''; }

        $sql  = '';
        $expectOperand = true;

        foreach ($this->wheres as $frag) {
            if ($frag === '__GROUP_START__' || $frag === '__OR_GROUP_START__') {
                if (!$expectOperand) { $sql .= ($frag === '__OR_GROUP_START__' ? ' OR ' : ' AND '); }
                $sql .= '(';
                $expectOperand = true;
                continue;
            }

            if ($frag === '__GROUP_END__') {
                $sql .= ')';
                $expectOperand = false;
                continue;
            }

            $isOr = strpos($frag, '__OR__') === 0;
            if ($isOr) { $frag = substr($frag, 6); }

            if (!$expectOperand) { $sql .= $isOr ? ' OR ' : ' AND '; }

            $sql .= $frag;
            $expectOperand = false;
        }

        return ' WHERE ' . $sql;
    }

    private function reset()
    {
        $this->sel = null; $this->wheres = []; $this->orderBy = null;
        $this->limitN = null; $this->groupBy = null; $this->joins = [];
    }

    /* ---------------- execution ---------------- */

    public function query($sql)
    {
        $this->queries[] = $sql;

        /* PHP 8.1 turns on MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT by default,
         * so mysqli THROWS rather than returning false. Catching that and
         * re-throwing as SeSqlError is what lets a test assert "MariaDB
         * rejected this" instead of the exception escaping the whole suite. */
        try {
            $res = $this->conn->query($sql);
        } catch (mysqli_sql_exception $e) {
            $this->lastError = $e->getMessage();
            throw new SeSqlError($e->getMessage() . ' :: ' . substr($sql, 0, 240));
        }

        if ($res === false) {
            $this->lastError = $this->conn->error;
            throw new SeSqlError($this->conn->error . ' :: ' . substr($sql, 0, 240));
        }

        $this->affected = $this->conn->affected_rows;
        $this->insertId = $this->conn->insert_id;

        if ($res === true) { return new SeRealResult([]); }

        $rows = [];
        while ($r = $res->fetch_assoc()) { $rows[] = $r; }
        $res->free();

        return new SeRealResult($rows);
    }

    public function get($table)
    {
        $sql = 'SELECT ' . ($this->sel ?: '*') . ' FROM ' . $table
             . ($this->joins ? ' ' . implode(' ', $this->joins) : '')
             . $this->whereSql()
             . ($this->groupBy ? ' GROUP BY ' . $this->groupBy : '')
             . ($this->orderBy ? ' ORDER BY ' . $this->orderBy : '')
             . ($this->limitN !== null ? ' LIMIT ' . (int) $this->limitN : '');
        $this->reset();

        return $this->query($sql);
    }

    public function count_all_results($table)
    {
        $sql = 'SELECT COUNT(*) AS c FROM ' . $table
             . ($this->joins ? ' ' . implode(' ', $this->joins) : '')
             . $this->whereSql();
        $this->reset();

        return (int) $this->query($sql)->row()->c;
    }

    public function insert($table, array $data)
    {
        $cols = []; $vals = [];

        foreach ($data as $k => $v) { $cols[] = '`' . $k . '`'; $vals[] = $this->escape($v); }

        $this->reset();
        $this->query('INSERT INTO ' . $table . ' (' . implode(',', $cols) . ') VALUES (' . implode(',', $vals) . ')');

        return true;
    }

    public function update($table, array $data)
    {
        $sets = [];

        foreach ($data as $k => $v) { $sets[] = '`' . $k . '` = ' . $this->escape($v); }

        $sql = 'UPDATE ' . $table . ' SET ' . implode(',', $sets) . $this->whereSql();
        $this->reset();
        $this->query($sql);

        return true;
    }

    public function delete($table)
    {
        $sql = 'DELETE FROM ' . $table . $this->whereSql();
        $this->reset();
        $this->query($sql);

        return true;
    }

    public function insert_id() { return (int) $this->insertId; }
    public function affected_rows() { return (int) $this->affected; }

    public function trans_begin() { $this->conn->begin_transaction(); }
    public function trans_rollback() { $this->conn->rollback(); }
}

/** Raised when MariaDB rejects a statement — tests assert on this. */
class SeSqlError extends RuntimeException {}
