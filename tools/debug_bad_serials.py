from pathlib import Path
from openpyxl import load_workbook

base = Path(r"C:\laragon\www\maik\maik-cat-backend\excel")
for workbook_path in sorted(base.glob("*.xlsx")):
    print(f"=== {workbook_path.name} ===")
    try:
        wb = load_workbook(workbook_path, read_only=True, data_only=False)
    except Exception as exc:
        print(f"open failed: {exc}")
        continue
    found = 0
    for ws in wb.worksheets:
        for row in range(1, ws.max_row + 1):
            value = ws.cell(row=row, column=2).value
            if value is None:
                continue
            serial = str(value)
            if "?" not in serial:
                continue
            model = ws.cell(row=row, column=1).value
            print(repr((ws.title, row, model, serial)))
            found += 1
            if found >= 120:
                break
        if found >= 120:
            break
    print(f"found={found}")
    wb.close()
