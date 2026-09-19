#!/usr/bin/env bash
# CS32 evidence for B3 exit criterion 2: submit an XLSX recalculation run and
# download the resulting file. Prints PASS plus the output path, exits
# non-zero otherwise.
#
#   COMPUTE_URL=http://localhost:8080 COMPUTE_TOKEN=... \
#     ./xlsx-recalc.sh
#
# Needs curl + python3 on the caller. The input workbook is generated with
# stdlib zipfile (no openpyxl needed locally); the run itself uses the
# openpyxl inside the python image.
set -eu

URL="${COMPUTE_URL:-http://localhost:8080}"
TOKEN="${COMPUTE_TOKEN:?COMPUTE_TOKEN is required}"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

python3 - "$TMP" << 'EOF'
import sys, zipfile
root = sys.argv[1]
sheet = """<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="n"><v>100</v></c><c r="B1" t="n"><v>200</v></c></row></sheetData></worksheet>"""
workbook = """<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets></workbook>"""
rels = """<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>"""
wb_rels = """<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>"""
types = """<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>"""
with zipfile.ZipFile(f"{root}/input.xlsx", "w", zipfile.ZIP_DEFLATED) as z:
    z.writestr("[Content_Types].xml", types)
    z.writestr("_rels/.rels", wb_rels)
    z.writestr("xl/workbook.xml", workbook)
    z.writestr("xl/_rels/workbook.xml.rels", rels)
    z.writestr("xl/worksheets/sheet1.xml", sheet)
script = '''from openpyxl import load_workbook
wb = load_workbook("/work/input.xlsx")
ws = wb.active
for row in ws.iter_rows():
    for cell in row:
        if isinstance(cell.value, (int, float)):
            cell.value = round(cell.value * 1.05, 2)
wb.save("/out/recalculated.xlsx")
print("recalculated")
'''
open(f"{root}/main.py", "w").write(script)
print("fixture ready")
EOF

cat > "$TMP/request.json" << 'EOF'
{"protocol":1,"owner":"cs32-demo","workspace":{"kind":"run"},"image":"python","entry":{"program":"python","args":["main.py"]},"files":[{"name":"main.py","role":"input"},{"name":"input.xlsx","role":"input"}],"limits":{"timeoutSec":120,"memoryMb":512,"cpu":1.0,"pids":128,"outputMb":50},"egress":{"allow":[]}}
EOF

RUN_ID=$(curl -sf -X POST "$URL/v1/runs" -H "Authorization: Bearer $TOKEN" \
  -F "request.json=@$TMP/request.json;type=application/json" \
  -F "main.py=@$TMP/main.py;type=text/x-python" \
  -F "input.xlsx=@$TMP/input.xlsx;type=application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" \
  | python3 -c "import json,sys; print(json.load(sys.stdin)['runId'])")
echo "run: $RUN_ID"

for _ in $(seq 1 60); do
  STATUS=$(curl -sf "$URL/v1/runs/$RUN_ID" -H "Authorization: Bearer $TOKEN")
  STATE=$(echo "$STATUS" | python3 -c "import json,sys; print(json.load(sys.stdin)['status'])")
  [ "$STATE" = "succeeded" ] && break
  [ "$STATE" = "failed" ] || [ "$STATE" = "cancelled" ] && { echo "FAIL: run $STATE"; exit 1; }
  sleep 2
done
[ "$STATE" = "succeeded" ] || { echo "FAIL: timed out waiting for run"; exit 1; }

curl -sf "$URL/v1/runs/$RUN_ID/artefacts/recalculated.xlsx" -H "Authorization: Bearer $TOKEN" -o "$TMP/out.xlsx"
python3 - "$TMP/out.xlsx" << 'EOF'
import sys, zipfile
import xml.etree.ElementTree as ET
z = zipfile.ZipFile(sys.argv[1])
xml = z.read("xl/worksheets/sheet1.xml").decode()
ns = {"m": "http://schemas.openxmlformats.org/spreadsheetml/2006/main"}
vals = [float(v.text) for v in ET.fromstring(xml).iter("{http://schemas.openxmlformats.org/spreadsheetml/2006/main}v")]
assert vals == [105.0, 210.0], vals
print("PASS: recalculated.xlsx carries the +5% values", vals)
EOF
