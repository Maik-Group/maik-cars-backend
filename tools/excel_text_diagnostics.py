from pathlib import Path
from openpyxl import load_workbook
import json

path = Path(r"C:\laragon\www\maik\maik-cat-backend\storage\app\imports\maik_2_101857.xlsx")
out = Path(r"C:\laragon\www\maik\maik-cat-backend\tools\excel_text_diagnostics.json")
wb = load_workbook(path, read_only=True, data_only=False)

question = []
non_ascii = []
for ws in wb.worksheets:
    if ws.title.strip().lower() == "kitko":
        continue
    for row in range(4, ws.max_row + 1):
        model = ws.cell(row=row, column=1).value
        serial = ws.cell(row=row, column=2).value
        model_s = "" if model is None else str(model)
        serial_s = "" if serial is None else str(serial)
        rec = {
            "sheet": ws.title,
            "row": row,
            "model": model_s,
            "serial": serial_s,
            "model_codepoints": [f"U+{ord(ch):04X}" for ch in model_s],
            "serial_codepoints": [f"U+{ord(ch):04X}" for ch in serial_s],
        }
        if "?" in model_s or "?" in serial_s:
            question.append(rec)
        if any(ord(ch) > 127 for ch in model_s + serial_s):
            non_ascii.append(rec)

wb.close()
out.write_text(json.dumps({
    "question_count": len(question),
    "non_ascii_count": len(non_ascii),
    "question_rows": question[:100],
    "non_ascii_rows": non_ascii[:100],
}, ensure_ascii=False, indent=2), encoding="utf-8")
print(f"written={out}")
print(f"question_count={len(question)} non_ascii_count={len(non_ascii)}")
