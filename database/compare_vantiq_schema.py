import re
from pathlib import Path
from collections import Counter

SOURCE = Path(r"C:\xampp\mysql\recovery_exports_20261121\vantiq_recovered_schema_20261121.sql")
TARGET = Path(r"C:\xampp\htdocs\Enterprise Intelligence\backend\spring\src\main\resources\db\migration\V1__complete_vantiq_schema.sql")
REPORT = Path(r"C:\xampp\htdocs\Enterprise Intelligence\vantiq_schema_comparison.txt")

def extract_tables(path):
    sql = path.read_text(encoding="utf-8-sig", errors="replace")
    pattern = re.compile(
        r'CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?'
        r'((?:"[^"]+"|`[^`]+`|\w+)(?:\s*\.\s*(?:"[^"]+"|`[^`]+`|\w+))?)\s*\(',
        re.I
    )
    tables = {}
    for match in pattern.finditer(sql):
        name = match.group(1).split(".")[-1].strip().strip('`"').lower()
        start = match.end()
        depth, quote, i = 1, None, start
        while i < len(sql) and depth:
            c = sql[i]
            if quote:
                if c == quote:
                    if i + 1 < len(sql) and sql[i+1] == quote:
                        i += 2
                        continue
                    quote = None
                elif c == "\\" and quote in ("'", '"'):
                    i += 1
            else:
                if c in ("'", '"', '`'):
                    quote = c
                elif c == '(':
                    depth += 1
                elif c == ')':
                    depth -= 1
            i += 1
        if depth == 0:
            tables[name] = split_items(sql[start:i-1])
    return tables

def split_items(body):
    items, start, depth, quote, i = [], 0, 0, None, 0
    while i < len(body):
        c = body[i]
        if quote:
            if c == quote:
                if i + 1 < len(body) and body[i+1] == quote:
                    i += 2
                    continue
                quote = None
            elif c == "\\" and quote in ("'", '"'):
                i += 1
        else:
            if c in ("'", '"', '`'):
                quote = c
            elif c == '(':
                depth += 1
            elif c == ')':
                depth -= 1
            elif c == ',' and depth == 0:
                items.append(body[start:i].strip())
                start = i + 1
        i += 1
    tail = body[start:].strip()
    if tail:
        items.append(tail)
    return items

def parse_table(items):
    columns, constraints = {}, []
    for item in items:
        if not item:
            continue
        first = item.split(None, 1)[0].strip('`"').upper()
        if first in {"PRIMARY", "UNIQUE", "CONSTRAINT", "FOREIGN", "CHECK",
                     "KEY", "INDEX", "EXCLUDE"}:
            constraints.append(" ".join(item.split()))
        else:
            parts = item.split(None, 1)
            if len(parts) == 2:
                col = parts[0].strip('`"').lower()
                columns[col] = " ".join(parts[1].split())
    return columns, constraints

def type_part(definition):
    # Capture the declared type before column modifiers.
    stop = re.search(
        r'\s+(?:NOT\s+NULL|NULL\b|DEFAULT\b|AUTO_INCREMENT\b|'
        r'GENERATED\b|PRIMARY\s+KEY\b|UNIQUE\b|REFERENCES\b|'
        r'CHECK\s*\(|COLLATE\b|COMMENT\b)',
        definition, re.I
    )
    return (definition[:stop.start()] if stop else definition).strip().lower()

def main():
    for p in (SOURCE, TARGET):
        if not p.is_file():
            raise FileNotFoundError(f"SQL file not found: {p}")

    src = extract_tables(SOURCE)
    tgt = extract_tables(TARGET)
    lines = []
    lines.append("VANTIQ MARIA DB vs POSTGRESQL SCHEMA COMPARISON")
    lines.append("=" * 65)
    lines.append(f"Source tables: {len(src)}")
    lines.append(f"Target tables: {len(tgt)}")
    lines.append(f"Source only: {', '.join(sorted(set(src)-set(tgt))) or 'None'}")
    lines.append(f"Target only: {', '.join(sorted(set(tgt)-set(src))) or 'None'}")
    lines.append("")

    common = sorted(set(src) & set(tgt))
    totals = Counter()

    for table in common:
        sc, sk = parse_table(src[table])
        tc, tk = parse_table(tgt[table])
        missing = sorted(set(sc)-set(tc))
        extra = sorted(set(tc)-set(sc))
        type_diff = []
        for col in sorted(set(sc) & set(tc)):
            st, tt = type_part(sc[col]), type_part(tc[col])
            if st != tt:
                type_diff.append((col, st, tt))

        if missing or extra or type_diff or len(sk) != len(tk):
            lines.append(f"TABLE: {table}")
            if missing:
                lines.append("  Missing in PostgreSQL: " + ", ".join(missing))
                totals["missing_columns"] += len(missing)
            if extra:
                lines.append("  Additional in PostgreSQL: " + ", ".join(extra))
                totals["additional_columns"] += len(extra)
            for col, st, tt in type_diff:
                lines.append(f"  TYPE DIFFERENCE {col}: MariaDB [{st}] | PostgreSQL [{tt}]")
                totals["type_differences"] += 1
            if len(sk) != len(tk):
                lines.append(f"  Constraint/index definition counts: MariaDB={len(sk)}, PostgreSQL={len(tk)}")
                totals["constraint_count_differences"] += 1
            lines.append("")

    lines.append("SUMMARY")
    lines.append("-" * 65)
    lines.append(f"Common tables analysed: {len(common)}")
    for key, value in totals.items():
        lines.append(f"{key}: {value}")
    lines.append("")
    lines.append("IMPORTANT: This is a structural screening report, not proof of data compatibility.")
    lines.append("Constraint counts are not semantic equivalence. Review foreign keys,")
    lines.append("checks, defaults, ENUM mappings, identity columns and tenant rules before import.")

    REPORT.write_text("\n".join(lines), encoding="utf-8")
    print("\n".join(lines))
    print(f"\nFull report saved to: {REPORT}")

if __name__ == "__main__":
    main()
