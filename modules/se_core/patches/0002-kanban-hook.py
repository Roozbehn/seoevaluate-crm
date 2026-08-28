"""
Adds an extension point to AbstractKanban.

Perfex's kanban classes expose no hooks: LeadsKanban::initiateQuery() builds
the query directly and the controller never calls tapQuery(). There is no way
to scope the kanban to a brand without touching core, so this patch adds one
general-purpose action instead of hard-coding tenancy logic into the class.

The query builder is the shared CodeIgniter singleton, so a listener can call
get_instance()->db->where(...) and it lands on the in-flight query.

Idempotent. Refuses to write unless it finds exactly the expected 2 call sites.
"""
import sys, shutil, datetime

REL = "/application/services/AbstractKanban.php"

OLD = """        $this->initiateQuery();

        if ($this->q) {"""

NEW = """        $this->initiateQuery();

        // SE fork: general extension point - see modules/se_core.
        hooks()->do_action('kanban_query_initiated', $this);

        if ($this->q) {"""

p = sys.argv[1] + REL
s = open(p, encoding="utf-8").read()

if NEW in s:
    print("already applied")
    sys.exit(0)

n = s.count(OLD)
if n != 2:
    print("REFUSED: expected 2 call sites, found %d - not stock 3.4.1" % n)
    sys.exit(1)

shutil.copy2(p, p + ".bak-" + datetime.datetime.now().strftime("%Y%m%d-%H%M%S"))
open(p, "w", encoding="utf-8").write(s.replace(OLD, NEW))
print("applied: kanban_query_initiated action added to 2 call sites")
