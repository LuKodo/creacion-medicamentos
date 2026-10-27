#!/usr/bin/env python3
"""Flag unqualified column names that exist in more than one table of a JOIN.

MySQL rejects such queries at runtime with error 1052 ("Column 'id' in order clause is ambiguous",
SQLSTATE 23000). Run it before deploying:

    python3 -I tools/auditar_sql.py [project_root]

Exit code 0 = clean, 1 = ambiguous columns found. Only static SQL strings are analysed (a string that
contains SELECT ... JOIN); SQL assembled from fragments must still be reviewed by hand.
"""
import glob
import os
import re
import sys

KEYWORDS = set('select from where join left right inner outer on and or not in is null as order by group having '
               'limit offset case when then else end like between exists distinct asc desc union all'.split())


def load_columns(root):
    """Columns per table from lib/schema.php, plus the ones added later with ALTER TABLE ... ADD."""
    schema = open(os.path.join(root, 'lib/schema.php'), encoding='utf-8').read()
    tables = {}
    for m in re.finditer(r"'(\w+)'\s*=>\s*\"(.*?)\"", schema, re.S):
        cols = set()
        for line in m.group(2).split('\n'):
            w = re.match(r"\s*(\w+)", line)
            if w and w.group(1).upper() not in ('FOREIGN', 'PRIMARY', 'UNIQUE', 'INDEX', 'KEY', 'CONSTRAINT', 'CHECK'):
                cols.add(w.group(1).lower())
        if cols:
            tables[m.group(1)] = cols
    for f in glob.glob(os.path.join(root, '**/*.php'), recursive=True):
        text = open(f, encoding='utf-8', errors='ignore').read()
        for m in re.finditer(r"ALTER TABLE (\w+) ADD (?:COLUMN )?(\w+)", text, re.I):
            tables.setdefault(m.group(1), set()).add(m.group(2).lower())
    return tables


def strip_subqueries(sql):
    """Collapse innermost (SELECT ...) groups: their columns resolve in their own scope."""
    prev = None
    while prev != sql:
        prev = sql
        sql = re.sub(r"\((?:[^()]*?)\bSELECT\b[^()]*\)", "(SUB)", sql, flags=re.I)
    return sql


def statements(root):
    """Yield (file, line, sql) for every static SQL string that contains a JOIN."""
    for f in sorted(glob.glob(os.path.join(root, '**/*.php'), recursive=True)):
        if '/vendor/' in f:
            continue
        text = open(f, encoding='utf-8', errors='ignore').read()
        seen = set()
        for m in re.finditer(r"\bJOIN\b", text):
            start = text.rfind('SELECT', 0, m.start())
            if start < 1 or text[start - 1] not in '\'"':
                continue
            quote, end = text[start - 1], start
            while True:
                end = text.find(quote, end + 1)
                if end < 0 or text[end - 1] != '\\':
                    break
            if end < 0 or end < m.start() or start in seen:
                continue
            seen.add(start)
            yield f, text.count('\n', 0, start) + 1, text[start:end].replace("\\'", "'")


def ambiguous_columns(sql, tables):
    sql = strip_subqueries(sql)
    used = [t for t, _ in re.findall(
        r"\b(?:FROM|JOIN)\s+(\w+)(?:\s+(?:AS\s+)?(?!LEFT|RIGHT|INNER|JOIN|ON|WHERE|ORDER|GROUP|LIMIT|USING)(\w+))?", sql, re.I)
        if t in tables]
    if len(used) < 2:
        return []
    body = re.sub(r"'(?:[^'\\]|\\.)*'", "''", sql)                 # string literals
    body = re.sub(r"\$\w+(?:\[[^\]]*\])?|\{[^}]*\}", "", body)      # PHP interpolation
    body = re.sub(r"\b\w+\.\w+\b", "", body)                        # qualified references are fine
    body = re.sub(r"\bAS\s+\w+", "", body, flags=re.I)              # aliases
    names = {w.lower() for w in re.findall(r"\b[a-z_]\w*\b(?!\s*\()", body)} - KEYWORDS - {t.lower() for t in used}
    return sorted(n for n in names if sum(1 for t in used if n in tables[t]) >= 2)


def main():
    root = sys.argv[1] if len(sys.argv) > 1 else os.path.join(os.path.dirname(os.path.abspath(__file__)), '..')
    tables = load_columns(root)
    checked = found = 0
    for f, line, sql in statements(root):
        checked += 1
        amb = ambiguous_columns(sql, tables)
        if amb:
            found += 1
            print(f"{os.path.relpath(f, root)}:{line}  ambiguous: {', '.join(amb)}\n    {' '.join(sql.split())[:160]}")
    print(f"{checked} JOIN queries checked, {found} with unqualified ambiguous columns")
    return 1 if found else 0


if __name__ == '__main__':
    sys.exit(main())
