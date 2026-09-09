import json
import re

with open('scratch/live_db_schema.json') as f:
    live = json.load(f)

with open('schema.sql') as f:
    sql = f.read()

# Extract tables in schema.sql
table_blocks = re.findall(r'CREATE TABLE (?:IF NOT EXISTS )?`?(\w+)`?\s*\((.*?)\)\s*ENGINE', sql, re.DOTALL | re.IGNORECASE)
sql_tables = {}
for tname, body in table_blocks:
    cols = {}
    for line in body.strip().split('\n'):
        line = line.strip()
        if not line or line.startswith('--') or line.startswith('PRIMARY') or line.startswith('KEY') or line.startswith('UNIQUE') or line.startswith('INDEX') or line.startswith('CONSTRAINT'):
            continue
        m = re.match(r'`?(\w+)`?\s+([^,\n]+)', line)
        if m:
            cname = m.group(1)
            ctype = m.group(2)
            cols[cname] = ctype
    sql_tables[tname] = cols

print("=== TABLES IN LIVE DB VS SCHEMA.SQL ===")
live_set = set(live.keys())
sql_set = set(sql_tables.keys())

print("Tables in Live DB but NOT in schema.sql:", live_set - sql_set)
print("Tables in schema.sql but NOT in Live DB:", sql_set - live_set)

print("\n=== COLUMN DIFFERENCES IN MATCHING TABLES ===")
for tname in live_set.intersection(sql_set):
    live_cols = set(live[tname].keys())
    sql_cols = set(sql_tables[tname].keys())
    
    missing_in_sql = live_cols - sql_cols
    missing_in_live = sql_cols - live_cols
    
    if missing_in_sql or missing_in_live:
        print(f"\nTable `{tname}`:")
        if missing_in_sql:
            print("  Columns in DB but missing in schema.sql:", missing_in_sql)
            for c in missing_in_sql:
                print(f"    -> {c}: {live[tname][c]}")
        if missing_in_live:
            print("  Columns in schema.sql but missing in DB:", missing_in_live)
